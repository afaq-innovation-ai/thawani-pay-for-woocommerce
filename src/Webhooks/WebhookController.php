<?php
/**
 * Thawani webhook receiver.
 *
 * @package AfaqInnovation\ThawaniPay
 */

namespace AfaqInnovation\ThawaniPay\Webhooks;

use AfaqInnovation\ThawaniPay\Gateway\Gateway;
use AfaqInnovation\ThawaniPay\Gateway\OrderMeta;
use AfaqInnovation\ThawaniPay\Gateway\PaymentSync;
use AfaqInnovation\ThawaniPay\Support\Logger;
use AfaqInnovation\ThawaniPay\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-json/thawani-pay/v1/webhook
 *
 * Events: checkout.created, checkout.completed, payment.pending, payment.succeeded, payment.failed.
 * The signature is verified with the sandbox or live webhook secret, then the
 * affected order is refreshed from the API (the webhook body is treated as a hint only).
 */
final class WebhookController {

	const NAMESPACE_V1 = 'thawani-pay/v1';
	const ROUTE        = '/webhook';

	/**
	 * Register the route.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Public URL to paste in the Thawani merchant portal.
	 */
	public static function url(): string {
		return rest_url( self::NAMESPACE_V1 . self::ROUTE );
	}

	/**
	 * REST route.
	 */
	public static function register(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true', // Authenticated by HMAC signature below.
			)
		);
	}

	/**
	 * Handle a delivery.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$body      = (string) $request->get_body();
		$timestamp = (string) $request->get_header( 'thawani-timestamp' );
		$signature = (string) $request->get_header( 'thawani-signature' );
		$payload   = json_decode( $body, true );

		if ( ! is_array( $payload ) || empty( $payload['event_type'] ) ) {
			return new \WP_REST_Response( array( 'error' => 'invalid_payload' ), 400 );
		}

		$verified_mode = self::verified_mode( $body, $timestamp, $signature );

		if ( false === $verified_mode ) {
			Logger::warning( 'Webhook rejected: invalid signature.', array( 'event' => $payload['event_type'] ) );
			return new \WP_REST_Response( array( 'error' => 'invalid_signature' ), 401 );
		}

		if ( $verified_mode && ! Signature::is_fresh( $timestamp ) ) {
			Logger::warning( 'Webhook rejected: timestamp outside tolerance.', array( 'timestamp' => $timestamp ) );
			return new \WP_REST_Response( array( 'error' => 'stale_timestamp' ), 401 );
		}

		$event = (string) $payload['event_type'];
		$data  = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();

		Logger::info( "Webhook {$event} received.", array( 'verified' => (bool) $verified_mode ) );

		/**
		 * Fires for every accepted Thawani webhook.
		 *
		 * @param string $event Event type, e.g. checkout.completed.
		 * @param array  $data  Event data.
		 */
		do_action( 'thawani_pay_webhook_received', $event, $data );

		$order = self::find_order( $data );
		if ( ! $order ) {
			return new \WP_REST_Response(
				array(
					'received' => true,
					'order'    => null,
				),
				200
			);
		}

		switch ( $event ) {
			case 'checkout.completed':
			case 'payment.succeeded':
				$result = PaymentSync::sync( $order );
				break;

			case 'payment.failed':
				$order->add_order_note(
					sprintf(
						/* translators: 1: masked card, 2: reason. */
						__( 'A Thawani payment attempt failed (card %1$s). %2$s The customer can retry on the same payment page.', 'thawani-pay-for-woocommerce' ),
						(string) ( $data['masked_card'] ?? '—' ),
						! empty( $data['reason'] ) ? (string) $data['reason'] . '.' : ''
					)
				);
				$result = 'noted';
				break;

			default:
				$result = 'ignored';
		}

		return new \WP_REST_Response(
			array(
				'received' => true,
				'order'    => $order->get_id(),
				'result'   => $result,
			),
			200
		);
	}

	/**
	 * Which secret validated the signature.
	 *
	 * @param string $body      Raw body.
	 * @param string $timestamp Timestamp header.
	 * @param string $signature Signature header.
	 * @return string|false|null Mode on success; null when no secret is configured (accepted, but only as a hint); false when invalid.
	 */
	private static function verified_mode( string $body, string $timestamp, string $signature ) {
		$configured = false;

		foreach ( array( Settings::MODE_LIVE, Settings::MODE_TEST ) as $mode ) {
			$secret = Settings::webhook_secret( $mode );
			if ( '' === $secret ) {
				continue;
			}
			$configured = true;
			if ( Signature::verify( $body, $timestamp, $signature, $secret ) ) {
				return $mode;
			}
		}

		return $configured ? false : null;
	}

	/**
	 * Resolve the order an event belongs to.
	 *
	 * @param array $data Event data.
	 */
	private static function find_order( array $data ): ?\WC_Order {
		$order = null;

		if ( ! empty( $data['client_reference_id'] ) ) {
			$order = OrderMeta::find_by_reference( (string) $data['client_reference_id'] );
		}
		if ( ! $order && ! empty( $data['session_id'] ) ) {
			$order = OrderMeta::find_by_meta( OrderMeta::SESSION_ID, (string) $data['session_id'] );
		}
		if ( ! $order && ! empty( $data['checkout_invoice'] ) ) {
			$order = OrderMeta::find_by_meta( OrderMeta::INVOICE, (string) $data['checkout_invoice'] );
		}
		if ( ! $order && ! empty( $data['invoice'] ) ) {
			$order = OrderMeta::find_by_meta( OrderMeta::INVOICE, (string) $data['invoice'] );
		}
		if ( ! $order && ! empty( $data['payment_intent'] ) ) {
			$order = OrderMeta::find_by_meta( OrderMeta::INTENT_ID, (string) $data['payment_intent'] );
		}

		return $order && Gateway::ID === $order->get_payment_method() ? $order : null;
	}
}
