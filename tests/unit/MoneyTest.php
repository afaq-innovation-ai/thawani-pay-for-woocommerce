<?php
namespace AfaqInnovation\ThawaniPay\Tests;

use AfaqInnovation\ThawaniPay\Support\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase {

	/**
	 * @dataProvider amounts
	 */
	public function test_to_baisa( $omr, int $baisa ): void {
		$this->assertSame( $baisa, Money::to_baisa( $omr ) );
	}

	public function amounts(): array {
		return array(
			'integer'                     => array( 12, 12000 ),
			'three decimals string'       => array( '12.345', 12345 ),
			'float that is not exact'     => array( 0.1 + 0.2, 300 ),
			'binary rounding trap'        => array( 12.344999999, 12345 ),
			'thousands separator'         => array( '1,250.500', 1250500 ),
			'fourth decimal rounds'       => array( '9.9995', 10000 ),
			'zero'                        => array( '0', 0 ),
			'smallest unit'               => array( '0.001', 1 ),
		);
	}

	public function test_from_baisa_and_format(): void {
		$this->assertSame( 18.75, Money::from_baisa( 18750 ) );
		$this->assertSame( '18.750 OMR', Money::format_baisa( 18750 ) );
		$this->assertSame( '1,250.500 OMR', Money::format_baisa( 1250500 ) );
	}
}
