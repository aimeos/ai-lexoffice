<?php

/**
 * @license LGPLv3, https://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2021
 * @package MShop
 * @subpackage Service
 */


namespace Aimeos\MShop\Service\Provider\Delivery;

use \Aimeos\MW\Logger\Base as Log;


/**
 * Lexoffice delivery provider implementation
 *
 * @package MShop
 * @subpackage Service
 */
class Lexoffice
	extends \Aimeos\MShop\Service\Provider\Delivery\Base
	implements \Aimeos\MShop\Service\Provider\Delivery\Iface
{
	private $beconfig = [
		'lexoffice.apikey' => [
			'code' => 'lexoffice.apikey',
			'internalcode'=> 'lexoffice.apikey',
			'label'=> 'Lexoffice API key',
			'type'=> 'string',
			'internaltype'=> 'string',
			'default'=> '',
			'required'=> true,
		],
	];


	/**
	 * Checks the backend configuration attributes for validity
	 *
	 * @param array $attributes Attributes added by the shop owner in the administraton interface
	 * @return array An array with the attribute keys as key and an error message as values
	 */
	public function checkConfigBE( array $attributes ) : array
	{
		$errors = parent::checkConfigBE( $attributes );
		return array_merge( $errors, $this->checkConfig( $this->beconfig, $attributes ) );
	}


	/**
	 * Returns the configuration attribute definitions
	 *
	 * @return \Aimeos\MW\Common\Critera\Attribute\Iface[] List of attribute definitions
	 */
	public function getConfigBE() : array
	{
		$list = parent::getConfigBE();

		foreach( $this->beconfig as $key => $config ) {
			$list[$key] = new \Aimeos\MW\Criteria\Attribute\Standard( $config );
		}

		return $list;
	}


	/**
	 * Sends the order to the Lexoffice API
	 *
	 * @param \Aimeos\MShop\Order\Item\Iface $order Order item
	 * @return \Aimeos\MShop\Order\Item\Iface Modified order item
	 */
	public function process( \Aimeos\MShop\Order\Item\Iface $order ) : \Aimeos\MShop\Order\Item\Iface
	{
		$parts = \Aimeos\MShop\Order\Item\Base\Base::PARTS_ALL;
		$basket = $this->getOrderBase( $order->getBaseId(), $parts );

		$contactId = $this->contact( $basket );

		return $order->setDeliveryStatus( \Aimeos\MShop\Order\Item\Base::STAT_PROGRESS );
	}


	/**
	 * Returns the contact ID for the payment address or creates a new record
	 *
	 * @param \Aimeos\MShop\Order\Item\Base\Iface $basket Basket with addresses
	 * @return string|null Contact ID
	 */
	protected function contact( \Aimeos\MShop\Order\Item\Base\Iface $basket ) : ?string
	{
		if( ( $address = current( $basket->getAddress( 'payment' ) ) ) === false ) {
			return null;
		}

		$result = $this->send( 'v1/contacts?email=' . $address->getEmail() );
		$id = ( $item = current( $result ) ) !== false ? $item['id'] : null;
		$i18n = $this->getContext()->getI18n();

		$body = [
			'version' =>  0,
			'roles' => [
				'customer' => new \stdClass
			],
			'emailAddresses' => [
				'business' => [$address->getEmail()]
			],
			'note' => 'Aimeos'
		];

		$body = array_merge_recursive( $body, $this->contactPerson( $address ) );
		$body = array_merge_recursive( $body, $this->contactAddress( $address ), $basket->getAddress( 'delivery' ) );

		$result = $this->send( 'v1/contacts/' . $id, $body, 'POST' );

		return ( $item = current( $result ) ) !== false ? $item['id'] : null;
	}


	/**
	 * Returns the contact person data
	 *
	 * @param \Aimeos\MShop\Common\Item\Address\Iface $address Payment address item
	 * @return array Multi-dimensional associative list of key/value pairs for Lexoffice
	 */
	protected function contactPerson( \Aimeos\MShop\Common\Item\Address\Iface $address ) : array
	{
		if( !empty( $company = $address->getCompany() ) )
		{
			$body = [
				'company' => [
					'name' => $company,
					'vatRegistrationId' => $address->getVatId(),
				]
			];

			foreach( $basket->getAddress( 'payment' ) as $addr )
			{
				$body['company']['contactPersons'][] = [
					'firstName' => $addr->getFirstname(),
					'lastName' => $addr->getLastname(),
					'emailAddress' => $addr->getEmail(),
					'phoneNumber' => $addr->getTelephone(),
				];
			}

			return $body;
		}

		return [
			'person' => [
				'salutation' => $i18n->dt( 'mshop/code', $address->getSalutation() ),
				'firstName' => $address->getFirstname(),
				'lastName' => $address->getLastname(),
			]
		];
	}


	/**
	 * Returns the contact address data
	 *
	 * @param \Aimeos\MShop\Common\Item\Address\Iface $address Payment address item
	 * @param \Aimeos\MShop\Common\Item\Address\Iface[] $shipAddresses List of delivery address items
	 * @return array Multi-dimensional associative list of key/value pairs for Lexoffice
	 */
	protected function contactAddress( \Aimeos\MShop\Common\Item\Address\Iface $address, array $shipAddresses ) : array
	{
		$body = [
			'addresses' => [
				'billing' => [[
					'street' => $address->getAddress1(),
					'supplement' => $address->getAddress2(),
					'zip' => $address->getPostal(),
					'city' => $address->getCity(),
					'countryCode' => $address->getCountryId(),
				]
			]]
		];

		foreach( $shipAddresses as $addr )
		{
			$body['addresses']['shipping'][] = [
				'street' => $addr->getAddress1(),
				'supplement' => $addr->getAddress2(),
				'zip' => $addr->getPostal(),
				'city' => $addr->getCity(),
				'countryCode' => $addr->getCountryId(),
			];
		}

		return $body;
	}


	/**
	 * Sends a request to the Mangopay server
	 *
	 * @param string $path Relative path of the resource (without client ID)
	 * @param array $body Associative list of data to send
	 * @param string $method Request method (GET, PATCH, POST, PUT, DELETE)
	 * @return array Array of returned data and HTTP status code
	 */
	protected function send( string $path, array $body = [], string $method = 'GET' ) : array
	{
		if( ( $ch = curl_init() ) === false ) {
			throw new \RuntimeException( 'Initializing CURL connection failed' );
		}

		$url = 'https://api.lexoffice.io/' . $path;
		$body = !empty( $body ) ? json_encode( $body ) : '';

		$header = [
			'Authorization: Bearer ' . $this->getConfigValue( 'lexoffice.apikey' ),
			'Content-Length: ' . strlen( $body ),
			'Content-Type: application/json',
			'Accept: application/json',
			'Cache-control: no-cache'
		];

		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $header );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, $method );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 5 );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 5 );

		if( in_array( $method, ['PATCH', 'POST', 'PUT'] ) )
		{
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
			curl_setopt( $ch, CURLOPT_POST, true );
		}

		if( ( $response = curl_exec( $ch ) ) === false ) {
			throw new \RuntimeException( sprintf( 'Curl exec failed for "%1$s": %2$s', $url, curl_error( $ch ) ) );
		}

		if( ( $errno = curl_errno( $ch ) ) !== 0 ) {
			throw new \RuntimeException( sprintf( 'Curl error for "%1$s": "%2$s"', $url, curl_error( $ch ) ) );
		}

		if( ( $httpcode = curl_getinfo( $ch, CURLINFO_HTTP_CODE ) ) === false ) {
			throw new \RuntimeException( sprintf( 'Curl getinfo failed for "%1$s": %2$s', $url, curl_error( $ch ) ) );
		}

		curl_close( $ch );

		if( ( $result = json_decode( $response, true ) ) === null || !is_array( $result ) ) {
			throw new \RuntimeException( sprintf( 'Invalid repsonse for "%1$s": %2$s', $url, $response ) );
		}

		return [$result, $httpcode];
	}
}
