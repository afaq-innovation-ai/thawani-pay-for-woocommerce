<?php
/**
 * Omani Rial ⇄ baisa conversion.
 *
 * @package AfaqInnovation\ThawaniPay
 */

namespace AfaqInnovation\ThawaniPay\Support;

/**
 * Thawani expects every amount as an integer number of baisa (1 OMR = 1000 baisa).
 */
final class Money {

	const CURRENCY = 'OMR';
	const FACTOR   = 1000;

	/**
	 * Convert a decimal OMR amount (string, float or int) to integer baisa.
	 *
	 * Rounds half away from zero on the third decimal place, which is how
	 * WooCommerce itself rounds OMR totals.
	 *
	 * @param string|float|int $amount Amount in OMR.
	 */
	public static function to_baisa( $amount ): int {
		if ( is_string( $amount ) ) {
			$amount = str_replace( array( ',', ' ' ), '', $amount );
		}

		return (int) round( round( (float) $amount, 3 ) * self::FACTOR );
	}

	/**
	 * Convert integer baisa to a decimal OMR amount.
	 *
	 * @param int|string $baisa Amount in baisa.
	 */
	public static function from_baisa( $baisa ): float {
		return round( ( (int) $baisa ) / self::FACTOR, 3 );
	}

	/**
	 * Human readable amount, e.g. "12.500 OMR".
	 *
	 * @param int|string $baisa Amount in baisa.
	 */
	public static function format_baisa( $baisa ): string {
		return number_format( self::from_baisa( $baisa ), 3, '.', ',' ) . ' ' . self::CURRENCY;
	}
}
