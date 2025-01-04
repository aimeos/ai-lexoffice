<?php

/**
 * @license LGPLv3, https://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2022-2025
 */


namespace Aimeos\MShop\Service\Provider\Delivery;


class LexofficeTest extends \PHPUnit\Framework\TestCase
{
	private $context;
	private $object;


	protected function setUp() : void
	{
		$this->context = \TestHelper::context();
		$serviceManager = \Aimeos\MShop::create( $this->context, 'service' );
		$serviceItem = $serviceManager->create()->setConfig( [
			'lexoffice.apikey' => 'xyz',
			'lexoffice.shipping-days' => 1,
			'lexoffice.payment-days' => 10,
		] );

		$this->object = new \Aimeos\MShop\Service\Provider\Delivery\Lexoffice( $this->context, $serviceItem );
	}


	protected function tearDown() : void
	{
		unset( $this->object );
	}


	public function testCheckConfigBE()
	{
		$attributes = [
			'lexoffice.apikey' => 'xyz',
			'lexoffice.shipping-days' => 1,
			'lexoffice.payment-days' => 10,
		];

		$result = $this->object->checkConfigBE( $attributes );

		$this->assertEquals( 3, count( $result ) );
		$this->assertEquals( null, $result['lexoffice.apikey'] );
		$this->assertEquals( null, $result['lexoffice.shipping-days'] );
		$this->assertEquals( null, $result['lexoffice.payment-days'] );
	}


	public function testGetConfigBE()
	{
		$result = $this->object->getConfigBE();

		$this->assertEquals( 3, count( $result ) );

		foreach( $result as $key => $item ) {
			$this->assertInstanceOf( 'Aimeos\Base\Criteria\Attribute\Iface', $item );
		}
	}
}
