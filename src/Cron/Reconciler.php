<?php
/**
 * Background reconciliation of unpaid orders.
 *
 * @package AfaqInnovation\ThawaniPay
 */

namespace AfaqInnovation\ThawaniPay\Cron;

use AfaqInnovation\ThawaniPay\Gateway\Gateway;
use AfaqInnovation\ThawaniPay\Gateway\OrderMeta;
use AfaqInnovation\ThawaniPay\Gateway\PaymentSync;
use AfaqInnovation\ThawaniPay\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Safety net for missed webhooks or customers who close the tab after paying.
 * Uses Action Scheduler (bundled with WooCommerce), visible under Tools → Scheduled Actions.
 */
final class Reconciler {

	const GROUP        = 'thawani-pay';
	const CHECK_ORDER  = 'thawani_pay_check_order';
	const RECONCILE    = 'thawani_pay_reconcile';
	const INTERVAL     = 15 * MINUTE_IN_SECONDS;
	const LOOKBACK_DAY = 3;

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( self::CHECK_ORDER, array( __CLASS__, 'check_order' ) );
		add_action( self::RECONCILE, array( __CLASS__, 'reconcile' ) );
		add_action( 'init', array( __CLASS__, 'ensure_recurring' ), 20 );
	}

	/**
	 * Register the recurring sweep once.
	 */
	public static function ensure_recurring(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || get_transient( 'thawani_pay_recurring_ok' ) ) {
			return;
		}

		if ( ! as_has_scheduled_action( self::RECONCILE, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + 5 * MINUTE_IN_SECONDS, self::INTERVAL, self::RECONCILE, array(), self::GROUP );
		}

		set_transient( 'thawani_pay_recurring_ok', 1, HOUR_IN_SECONDS );
	}

	/**
	 * Follow-up checks after a session is created.
	 *
	 * @param int $order_id Order id.
	 */
	public static function schedule_checks( int $order_id ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		foreach ( array( 5, 20, 65 ) as $minutes ) {
			as_schedule_single_action( time() + $minutes * MINUTE_IN_SECONDS, self::CHECK_ORDER, array( 'order_id' => $order_id ), self::GROUP );
		}
	}

	/**
	 * Refresh one order.
	 *
	 * @param int $order_id Order id.
	 */
	public static function check_order( $order_id ): void {
		$order = wc_get_order( absint( $order_id ) );

		if ( ! $order instanceof \WC_Order || Gateway::ID !== $order->get_payment_method() || $order->is_paid() ) {
			return;
		}

		if ( ! $order->has_status( array( 'pending', 'on-hold', 'failed' ) ) ) {
			return;
		}

		$session_id = (string) $order->get_meta( OrderMeta::SESSION_ID );
		if ( $session_id && $session_id === (string) $order->get_meta( OrderMeta::CANCELLED ) ) {
			return; // Customer cancelled this session; nothing to reconcile.
		}

		$result = PaymentSync::sync( $order );
		Logger::debug( sprintf( 'Reconciled order #%d: %s', $order->get_id(), $result ) );
	}

	/**
	 * Sweep recent unpaid Thawani orders.
	 */
	public static function reconcile(): void {
		$ids = wc_get_orders(
			array(
				'payment_method' => Gateway::ID,
				'status'         => array( 'pending' ),
				'date_created'   => '>' . ( time() - self::LOOKBACK_DAY * DAY_IN_SECONDS ),
				'limit'          => 50,
				'return'         => 'ids',
			)
		);

		foreach ( $ids as $id ) {
			self::check_order( $id );
		}
	}
}
