<?php
/**
 * Order meta keys and order lookup.
 *
 * @package AfaqInnovation\ThawaniPay
 */

namespace AfaqInnovation\ThawaniPay\Gateway;

use AfaqInnovation\ThawaniPay\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the plugin stores on an order, in one place (HPOS-safe: always via WC_Order meta API).
 */
final class OrderMeta {

	const MODE        = '_thawani_mode';
	const SESSION_ID  = '_thawani_session_id';
	const INVOICE     = '_thawani_invoice';
	const REFERENCE   = '_thawani_reference';
	const AMOUNT      = '_thawani_amount';
	const EXPIRES_AT  = '_thawani_session_expires';
	const INTENT_ID   = '_thawani_intent_id';
	const PAYMENT_ID  = '_thawani_payment_id';
	const CARD        = '_thawani_masked_card';
	const CARD_TYPE   = '_thawani_card_type';
	const CUSTOMER_ID = '_thawani_customer_id';
	const SAVE_CARD   = '_thawani_save_card';
	const REFUNDS     = '_thawani_refunds';
	const CANCELLED   = '_thawani_customer_cancelled';
	const CARD_ID     = '_thawani_card_id';

	/**
	 * Environment the order was paid (or attempted) in. Falls back to the active mode.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function mode( \WC_Order $order ): string {
		$mode = (string) $order->get_meta( self::MODE );

		return in_array( $mode, array( Settings::MODE_TEST, Settings::MODE_LIVE ), true ) ? $mode : Settings::mode();
	}

	/**
	 * Generate a fresh, unique client_reference_id: "{prefix}-{order id}-{random}".
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function new_reference( \WC_Order $order ): string {
		$prefix = Settings::reference_prefix();

		return ( '' !== $prefix ? $prefix . '-' : '' ) . $order->get_id() . '-' . strtolower( wp_generate_password( 6, false, false ) );
	}

	/**
	 * Extract the order id from a client_reference_id created by {@see new_reference()}.
	 *
	 * @param string $reference Client reference.
	 */
	public static function order_id_from_reference( string $reference ): int {
		$prefix = Settings::reference_prefix();
		if ( '' !== $prefix && 0 === strpos( $reference, $prefix . '-' ) ) {
			$reference = substr( $reference, strlen( $prefix ) + 1 );
		}

		return preg_match( '/^(\d+)-[a-z0-9]+$/', $reference, $m ) ? (int) $m[1] : 0;
	}

	/**
	 * Find the order that owns a client reference.
	 *
	 * @param string $reference Client reference.
	 */
	public static function find_by_reference( string $reference ): ?\WC_Order {
		$order = wc_get_order( self::order_id_from_reference( $reference ) );
		if ( $order instanceof \WC_Order && $reference === (string) $order->get_meta( self::REFERENCE ) ) {
			return $order;
		}

		return self::find_by_meta( self::REFERENCE, $reference );
	}

	/**
	 * Find an order by any Thawani meta value.
	 *
	 * @param string $key   Meta key.
	 * @param string $value Meta value.
	 */
	public static function find_by_meta( string $key, string $value ): ?\WC_Order {
		if ( '' === $value ) {
			return null;
		}

		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'type'       => 'shop_order',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => $key,
						'value' => $value,
					),
				),
			)
		);

		return ! empty( $orders ) && $orders[0] instanceof \WC_Order ? $orders[0] : null;
	}
}
