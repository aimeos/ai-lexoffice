<?php

/**
 * @license LGPLv3, https://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2021-2022
 * @package MShop
 * @subpackage Service
 */


namespace Aimeos\MShop\Service\Provider\Delivery;


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
		'lexoffice.shipping-days' => [
			'code' => 'lexoffice.shipping-days',
			'internalcode'=> 'lexoffice.shipping-days',
			'label'=> 'Max. days until order is shipped',
			'type'=> 'integer',
			'internaltype'=> 'integer',
			'default'=> 3,
			'required'=> false,
		],
		'lexoffice.payment-days' => [
			'code' => 'lexoffice.payment-days',
			'internalcode'=> 'lexoffice.payment-days',
			'label'=> 'Days until payment is overdue',
			'type'=> 'integer',
			'internaltype'=> 'integer',
			'default'=> 3,
			'required'=> false,
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
		$invoiceId = $this->order( $basket, $order, $contactId );

		$service = map( $basket->getService( 'delivery' ) )
			->col( null, 'order.base.service.code' )
			->get( $this->getServiceItem()->getCode() );

		if( $service )
		{
			$this->setAttributes( $service, ['lexoffice-invoiceid' => $invoiceId], 'hidden' );
			$this->saveOrderBase( $basket );
		}

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

		$id = null; $version = 0;
		list( $result, $status ) = $this->send( 'v1/contacts?email=' . $address->getEmail() );

		if( $status == 200 && ( $item = current( $result['content'] ?? [] ) ) !== false )
		{
			$version = $item['version'] ?? 0;
			$id = $item['id'] ?? null;
		}

		$body = [
			'version' => $version,
			'roles' => [
				'customer' => new \stdClass
			],
			'emailAddresses' => [
				'business' => [$address->getEmail()]
			],
			'note' => 'Aimeos'
		];

		$body = array_merge_recursive( $body, $this->contactPerson( $address ) );
		$body = array_merge_recursive( $body, $this->contactAddress( $address, $basket->getAddress( 'delivery' ) ) );

		list( $result, $status ) = $this->send( 'v1/contacts/' . $id, $body, $id ? 'PUT' : 'POST' );

		return in_array( $status, [200, 201] ) && isset( $result['id'] ) ? $result['id'] : null;
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
				],
				'contactPersons' => [[
					'firstName' => $address->getFirstname(),
					'lastName' => $address->getLastname(),
					'emailAddress' => $address->getEmail(),
					'phoneNumber' => $address->getTelephone(),
				]]
			];

			return $body;
		}

		return [
			'person' => [
				'salutation' => $this->context()->translate( 'mshop/code', $address->getSalutation() ),
				'firstName' => $address->getFirstname(),
				'lastName' => $address->getLastname(),
			]
		];
	}


	/**
	 * Sends the order details to Lexoffice and returns the Lexoffice ID
	 *
	 * @param \Aimeos\MShop\Order\Item\Base\Iface $basket Basket with addresses, products and services
	 * @param \Aimeos\MShop\Order\Item\Iface $order Order item
	 * @param string|null Lexoffice contact ID or NULL if none is available
	 * @return string|null Lexoffice order/invoice ID or NULL in case of an error
	 */
	protected function order( \Aimeos\MShop\Order\Item\Base\Iface $basket,
		\Aimeos\MShop\Order\Item\Iface $order, string $contactId = null ) : ?string
	{
		$intro = $this->context()->translate( 'lexoffice', 'Invoice for your order %1$s');
		$price = $basket->getPrice();

		$body = [
			'voucherDate' => str_replace( ' ', 'T', $basket->getTimeCreated() ) . '.000+01:00',
			'language' => $basket->locale()->getLanguageId(),
			'totalPrice' => [
				'currency' => $price->getCurrencyId()
			],
			'taxConditions' => [
				'taxType' => $price->getTaxflag() ? 'gross' : 'net'
			],
			'introduction' => sprintf( $intro, $order->getId() )
		];

		if( !$contactId && ( $address = current( $basket->getAddress( 'payment' ) ) ) !== false ) {
			$body = array_merge_recursive( $body, $this->orderAddress( $address ) );
		} else {
			$body['address'] = ['contactId' => $contactId];
		}

		$body['lineItems'] = $this->orderItems( $basket->getProducts() );
		$body = array_merge_recursive( $body, $this->orderPayment( $basket->getService( 'payment' ) ) );
		$body = array_merge_recursive( $body, $this->orderShipping( $basket->getService( 'delivery' ), $price ) );

		list( $result, $status ) = $this->send( 'v1/invoices?finalize=true', $body, 'POST' );

		if( $status != 201 || !isset( $result['id'] ) ) {
			throw new \RuntimeException( "Lexoffice: Unable to create invoice\n" . print_r( $result, true ) );
		}

		return $result['id'];
	}


	/**
	 * Returns the order address data for Lexoffice
	 *
	 * @param \Aimeos\MShop\Common\Item\Address\Iface $address Payment address item
	 * @return array Multi-dimensional associative list of key/value pairs for Lexoffice
	 */
	protected function orderAddress( \Aimeos\MShop\Common\Item\Address\Iface $address ) : array
	{
		return [
			'address' => [
				'name' => $address->getCompany() ?: $address->getFirstname() . ' ' . $address->getLastname(),
				'street' => $address->getAddress1(),
				'supplement' => $address->getAddress2(),
				'zip' => $address->getPostal(),
				'city' => $address->getCity(),
				'countryCode' => $address->getCountryId()
			]
		];
	}


	/**
	 * Returns the order product data for Lexoffice
	 *
	 * @param iterable $products List of ordered products
	 * @return array Multi-dimensional associative list of key/value pairs for Lexoffice
	 */
	protected function orderItems( iterable $products ) : array
	{
		$list = [];

		foreach( $products as $oProduct )
		{
			$price = $oProduct->getPrice();
			$item = [
				'type' => 'custom',
				'name' => $oProduct->getName(),
				'description' => $oProduct->getDescription(),
				'quantity' => $oProduct->getQuantity(),
				'unitName' => 'x',
				'unitPrice' => [
					'currency' => $price->getCurrencyId(),
					'taxRatePercentage' => $price->getTaxrate(),
				]
			];

			if( $price->getTaxflag() ) {
				$item['unitPrice']['grossAmount'] = $price->getValue();
			} else {
				$item['unitPrice']['netAmount'] = $price->getValue();
			}

			$list[] = $item;
		}

		return $list;
	}


	/**
	 * Returns the order payment related data for Lexoffice
	 *
	 * @param iterable $list List of order payment service items
	 * @return array Multi-dimensional associative list of key/value pairs for Lexoffice
	 */
	protected function orderPayment( iterable $list ) : array
	{
		if( ( $service = current( $list ) ) === false ) {
			return [];
		}

		return [
			'paymentConditions' => [
				'paymentTermLabel' => $service->getName(),
				'paymentTermDuration' => $this->getConfigValue( 'lexoffice.payment-days' )
			]
		];
	}


	/**
	 * Returns the order delivery related data for Lexoffice
	 *
	 * @param iterable $list List of order delivery service items
	 * @return array Multi-dimensional associative list of key/value pairs for Lexoffice
	 */
	protected function orderShipping( iterable $list, \Aimeos\MShop\Price\Item\Iface $price ) : array
	{
		if( ( $service = current( $list ) ) === false ) {
			return [];
		}

		$servicePrice = $service->getPrice();
		$item = [
			'type' => 'custom',
			'name' => $service->getName(),
			'quantity' => 1,
			'unitName' => 'x',
			'unitPrice' => [
				'currency' => $servicePrice->getCurrencyId(),
				'taxRatePercentage' => $servicePrice->getTaxrate(),
			]
		];

		if( $price->getTaxflag() ) {
			$item['unitPrice']['grossAmount'] = $price->getCosts();
		} else {
			$item['unitPrice']['netAmount'] = $price->getCosts();
		}

		$days = $this->getConfigValue( 'lexoffice.shipping-days' );

		return [
			'lineItems' => [$item],
			'shippingConditions' => [
				'shippingType' => 'delivery',
				'shippingDate' => date( 'Y-m-d\TH:i:s.000P', time() + 3600 * 24 * $days )
			]
		];
	}


	/**
	 * Sends a request to the Lexoffice server
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
