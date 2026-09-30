<?php
/**
 * Builds the `products` array of a Thawani checkout session.
 *
 * @package AfaqInnovation\ThawaniPay
 */

namespace AfaqInnovation\ThawaniPay\Support;

/**
 * Pure helper (no WordPress dependency) so it can be unit tested in isolation.
 *
 * Thawani constraints (Create Session):
 *  - max 100 products per session
 *  - name: 1–40 characters
 *  - unit_amount: integer baisa, 1 – 5,000,000,000
 *  - quantity: 1 – 100
 * The sum of unit_amount × quantity becomes the amount charged, so it must equal the order total exactly.
 */
final class LineItems {

	const MAX_PRODUCTS   = 100;
	const MAX_NAME       = 40;
	const MAX_QUANTITY   = 100;
	const MAX_UNIT_BAISA = 5000000000;

	const MODE_ITEMIZED = 'itemized';
	const MODE_SINGLE   = 'single';

	/**
	 * Build the products list.
	 *
	 * @param array<int, array{name:string, quantity:int, total:int}> $lines       Order lines; `total` is the line total in baisa (after discounts, incl. tax).
	 * @param int                                                     $total_baisa Order grand total in baisa.
	 * @param string                                                  $summary     Name used for the single-line summary (e.g. "Order #1045").
	 * @param string                                                  $mode        `itemized` or `single`.
	 * @return array<int, array{name:string, quantity:int, unit_amount:int}>
	 */
	public static function build( array $lines, int $total_baisa, string $summary, string $mode = self::MODE_ITEMIZED ): array {
		$single = array(
			array(
				'name'        => self::name( $summary ),
				'quantity'    => 1,
				'unit_amount' => $total_baisa,
			),
		);

		if ( self::MODE_SINGLE === $mode || $total_baisa > self::MAX_UNIT_BAISA ) {
			return $single;
		}

		$products = array();
		$sum      = 0;

		foreach ( $lines as $line ) {
			$total    = (int) ( $line['total'] ?? 0 );
			$quantity = max( 1, (int) ( $line['quantity'] ?? 1 ) );
			$name     = (string) ( $line['name'] ?? '' );

			if ( 0 === $total ) {
				continue; // Free items cannot be sent (unit_amount minimum is 1 baisa).
			}

			if ( $total < 0 ) {
				return $single; // Negative fees / credits cannot be itemised.
			}

			if ( $quantity <= self::MAX_QUANTITY && 0 === $total % $quantity ) {
				$products[] = array(
					'name'        => self::name( $name ),
					'quantity'    => $quantity,
					'unit_amount' => intdiv( $total, $quantity ),
				);
			} else {
				// Price per unit is not a whole baisa (or quantity too large): send the line as one unit.
				$products[] = array(
					'name'        => self::name( $name, ' ×' . $quantity ),
					'quantity'    => 1,
					'unit_amount' => $total,
				);
			}

			$sum += $total;
		}

		if ( empty( $products ) || $sum !== $total_baisa || count( $products ) > self::MAX_PRODUCTS ) {
			return $single;
		}

		return $products;
	}

	/**
	 * Normalise a product name to Thawani's 1–40 character limit.
	 *
	 * @param string $name   Raw name (may contain HTML/entities).
	 * @param string $suffix Suffix that must stay visible, e.g. " ×3".
	 */
	public static function name( string $name, string $suffix = '' ): string {
		$name = strip_tags( $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Pure class, also runs without WordPress in unit tests.
		$name = html_entity_decode( $name, ENT_QUOTES, 'UTF-8' );
		$name = trim( (string) preg_replace( '/\s+/u', ' ', $name ) );

		if ( '' === $name ) {
			$name = 'Item';
		}

		$room = self::MAX_NAME - mb_strlen( $suffix, 'UTF-8' );

		if ( mb_strlen( $name, 'UTF-8' ) > $room ) {
			$name = rtrim( mb_substr( $name, 0, $room - 1, 'UTF-8' ) ) . '…';
		}

		return $name . $suffix;
	}
}
