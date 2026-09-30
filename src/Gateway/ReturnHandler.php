<?php
/**
 * Handles customers coming back from the Thawani payment page.
 *
 * @package AfaqInnovation\ThawaniPay
 */

namespace AfaqInnovation\ThawaniPay\Gateway;

use AfaqInnovation\ThawaniPay\Api\ApiException;
use AfaqInnovation\ThawaniPay\Api\Client;
use AfaqInnovation\ThawaniPay\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Endpoint: /?wc-api=thawani_return&thawani_action=success|cancel|intent&order_id=…&key=…
 *
 * The order key proves the visitor owns the order; the payment state itself is
 * always fetched from the Thawani API, never read from the URL.
 */
final class ReturnHandler {

	const ENDPOINT = 'thawani_return';
	const SUCCESS  = 'success';
	const CANCEL   = 'cancel';
	const INTENT   = 'intent';

	/**
	 * Register the endpoint.
	 */
	public static function init(): void {
		add_action( 'woocommerce_api_' . self::ENDPOINT, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Return URL for an order.
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $action success|cancel|intent.
	 */
	public static function url( \WC_Order $order, string $action ): string {
		return add_query_arg(
			array(
				'thawani_action' => $action,
				'order_id'       => $order->get_id(),
				'key'            => $order->get_order_key(),
			),
			WC()->api_request_url( self::ENDPOINT )
		);
	}

	/**
	 * Route the request.
	 */
	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Authenticated by the order key; state is re-fetched from Thawani.
		$action   = isset( $_GET['thawani_action'] ) ? sanitize_key( wp_unslash( $_GET['thawani_action'] ) ) : '';
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order || '' === $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			wc_add_notice( __( 'We could not find that order.', 'thawani-pay-for-woocommerce' ), 'error' );
			self::redirect( wc_get_cart_url() );
		}

		if ( self::CANCEL === $action ) {
			self::cancelled( $order );
		}

		$status = $order->is_paid() ? PaymentSync::PAID : PaymentSync::sync( $order );

		if ( PaymentSync::PAID === $status ) {
			if ( WC()->cart ) {
				WC()->cart->empty_cart();
			}
			self::redirect( $order->get_checkout_order_received_url() );
		}

		if ( PaymentSync::CANCELLED === $status ) {
			wc_add_notice( __( 'Your payment was not completed. Please try again.', 'thawani-pay-for-woocommerce' ), 'error' );
			self::redirect( self::retry_url( $order ) );
		}

		// Still unpaid (e.g. bank still processing): show the order page, which polls for the final state.
		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}
		self::redirect( $order->get_checkout_order_received_url() );
	}

	/**
	 * Customer pressed "Cancel" on the Thawani page.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function cancelled( \WC_Order $order ): void {
		if ( $order->is_paid() ) {
			self::redirect( $order->get_checkout_order_received_url() );
		}

		$session_id = (string) $order->get_meta( OrderMeta::SESSION_ID );
		if ( $session_id ) {
			$order->update_meta_data( OrderMeta::CANCELLED, $session_id );
			$order->save();
		}

		// A payment might have gone through a moment before the cancel click.
		if ( PaymentSync::PAID === PaymentSync::sync( $order ) ) {
			self::redirect( $order->get_checkout_order_received_url() );
		}

		if ( $session_id ) {
			try {
				Client::for_mode( OrderMeta::mode( $order ) )->cancel_session( $session_id );
			} catch ( ApiException $e ) {
				Logger::debug( 'Cancel session failed (already closed?)', array( 'error' => $e->getMessage() ) );
			}
			$order->update_meta_data( OrderMeta::EXPIRES_AT, 0 ); // Never reuse a cancelled session.
			$order->save();
		}

		$order->add_order_note( __( 'Customer cancelled the payment on the Thawani page.', 'thawani-pay-for-woocommerce' ) );

		wc_add_notice( __( 'Payment cancelled. Your cart is saved — you can try again whenever you are ready.', 'thawani-pay-for-woocommerce' ), 'notice' );
		self::redirect( self::retry_url( $order ) );
	}

	/**
	 * Where to send the customer to try again.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function retry_url( \WC_Order $order ): string {
		if ( WC()->cart && ! WC()->cart->is_empty() ) {
			return wc_get_checkout_url();
		}

		return $order->get_checkout_payment_url();
	}

	/**
	 * Redirect and stop.
	 *
	 * @param string $url URL.
	 */
	private static function redirect( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}
}
