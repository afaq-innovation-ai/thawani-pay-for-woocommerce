<?php
/**
 * Starts Thawani payments: hosted checkout sessions and saved-card payment intents.
 *
 * @package AfaqInnovation\ThawaniPay
 */

namespace AfaqInnovation\ThawaniPay\Gateway;

use AfaqInnovation\ThawaniPay\Api\ApiException;
use AfaqInnovation\ThawaniPay\Api\Client;
use AfaqInnovation\ThawaniPay\Cron\Reconciler;
use AfaqInnovation\ThawaniPay\Support\LineItems;
use AfaqInnovation\ThawaniPay\Support\Logger;
use AfaqInnovation\ThawaniPay\Support\Money;
use AfaqInnovation\ThawaniPay\Support\Settings;
use AfaqInnovation\ThawaniPay\Tokens\TokenManager;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the API payloads from a WooCommerce order.
 */
final class CheckoutService {

	/**
	 * Create (or reuse) a checkout session and return the URL of the Thawani hosted payment page.
	 *
	 * @param \WC_Order $order     Order.
	 * @param bool      $save_card Customer asked to save the card.
	 * @return string Redirect URL.
	 * @throws ApiException When Thawani refuses the session.
	 */
	public static function start_session( \WC_Order $order, bool $save_card = false ): string {
		$mode   = Settings::mode();
		$client = Client::for_mode( $mode );
		$total  = Money::to_baisa( $order->get_total() );

		$reused = self::reusable_session( $order, $client, $total );
		if ( $reused ) {
			return $reused;
		}

		// A replaced session must not stay payable in another tab: Thawani would capture money for a stale session.
		self::cancel_open_session( $order );

		$payload = array(
			'client_reference_id' => OrderMeta::new_reference( $order ),
			'mode'                => 'payment',
			'products'            => LineItems::build(
				self::order_lines( $order ),
				$total,
				/* translators: %s: order number. */
				sprintf( __( 'Order #%s', 'thawani-pay-for-woocommerce' ), $order->get_order_number() ),
				(string) Settings::get( 'line_items', LineItems::MODE_ITEMIZED )
			),
			'success_url'         => ReturnHandler::url( $order, ReturnHandler::SUCCESS ),
			'cancel_url'          => ReturnHandler::url( $order, ReturnHandler::CANCEL ),
			'metadata'            => self::metadata( $order ),
			'expire_in_minutes'   => Settings::session_expiry(),
		);

		$customer_id = '';
		if ( $order->get_customer_id() && ( Settings::saved_cards_enabled() || $save_card ) ) {
			$customer_id = TokenManager::customer_id( $order->get_customer_id(), $mode );
			if ( $customer_id ) {
				$payload['customer_id']          = $customer_id;
				$payload['save_card_on_success'] = $save_card;
			}
		}

		/**
		 * Filter the checkout session payload before it is sent to Thawani.
		 *
		 * @param array     $payload Session payload.
		 * @param \WC_Order $order   Order.
		 */
		$payload = (array) apply_filters( 'thawani_pay_checkout_session_payload', $payload, $order );

		$session = $client->create_session( $payload );

		if ( empty( $session['session_id'] ) ) {
			throw new ApiException( 'Thawani did not return a session id.', 200 );
		}

		if ( isset( $session['total_amount'] ) && (int) $session['total_amount'] !== $total ) {
			Logger::error( sprintf( 'Order #%d: session total %d differs from order total %d.', $order->get_id(), (int) $session['total_amount'], $total ) );
		}

		$order->update_meta_data( OrderMeta::MODE, $mode );
		$order->update_meta_data( OrderMeta::SESSION_ID, $session['session_id'] );
		$order->update_meta_data( OrderMeta::INVOICE, (string) ( $session['invoice'] ?? '' ) );
		$order->update_meta_data( OrderMeta::REFERENCE, $payload['client_reference_id'] );
		$order->update_meta_data( OrderMeta::AMOUNT, $total );
		$order->update_meta_data( OrderMeta::EXPIRES_AT, isset( $session['expire_at'] ) ? (int) strtotime( (string) $session['expire_at'] ) : time() + Settings::session_expiry() * MINUTE_IN_SECONDS );
		$order->update_meta_data( OrderMeta::SAVE_CARD, $save_card ? 'yes' : 'no' );
		$order->update_meta_data( OrderMeta::CUSTOMER_ID, $customer_id );
		$order->delete_meta_data( OrderMeta::INTENT_ID );
		$order->add_order_note(
			sprintf(
				/* translators: 1: environment label, 2: invoice number, 3: amount. */
				__( 'Thawani checkout session created (%1$s). Invoice %2$s for %3$s. Customer redirected to the Thawani payment page.', 'thawani-pay-for-woocommerce' ),
				Settings::is_test( $mode ) ? __( 'sandbox', 'thawani-pay-for-woocommerce' ) : __( 'live', 'thawani-pay-for-woocommerce' ),
				(string) ( $session['invoice'] ?? '—' ),
				Money::format_baisa( $total )
			)
		);
		$order->save();

		Reconciler::schedule_checks( $order->get_id() );

		return $client->checkout_url( $session['session_id'] );
	}

	/**
	 * Charge a saved card with a payment intent.
	 *
	 * @param \WC_Order         $order Order.
	 * @param \WC_Payment_Token $token Saved card.
	 * @return array{status:string, redirect:string}
	 * @throws ApiException When Thawani refuses the payment.
	 */
	public static function pay_with_token( \WC_Order $order, \WC_Payment_Token $token ): array {
		$mode   = (string) $token->get_meta( 'thawani_mode' );
		$masked = (string) $token->get_meta( 'masked_card' );

		return self::charge_card(
			$order,
			$token->get_token(),
			$mode ? $mode : Settings::mode(),
			$masked ? $masked : '•••• ' . $token->get_last4()
		);
	}

	/**
	 * Charge a Thawani card id with a payment intent (checkout with a saved card, or a subscription renewal).
	 *
	 * @param \WC_Order $order   Order.
	 * @param string    $card_id Thawani payment method id.
	 * @param string    $mode    Environment the card belongs to.
	 * @param string    $masked  Masked card number for notes and display.
	 * @return array{status:string, redirect:string, intent?:array}
	 * @throws ApiException When Thawani refuses the payment.
	 */
	public static function charge_card( \WC_Order $order, string $card_id, string $mode, string $masked = '' ): array {
		$client = Client::for_mode( $mode );
		$total  = Money::to_baisa( $order->get_total() );
		$ref    = OrderMeta::new_reference( $order );

		$payload = (array) apply_filters(
			'thawani_pay_payment_intent_payload',
			array(
				'payment_method_id'   => $card_id,
				'amount'              => $total,
				'client_reference_id' => $ref,
				'return_url'          => ReturnHandler::url( $order, ReturnHandler::INTENT ),
				'metadata'            => self::metadata( $order ),
			),
			$order
		);

		self::cancel_open_session( $order );

		$intent = $client->create_payment_intent( $payload );

		$order->update_meta_data( OrderMeta::MODE, $mode );
		$order->update_meta_data( OrderMeta::INTENT_ID, (string) ( $intent['id'] ?? '' ) );
		$order->update_meta_data( OrderMeta::REFERENCE, $ref );
		$order->update_meta_data( OrderMeta::AMOUNT, $total );
		$order->update_meta_data( OrderMeta::CARD_ID, $card_id );
		if ( $masked ) {
			$order->update_meta_data( OrderMeta::CARD, $masked );
		}
		$order->delete_meta_data( OrderMeta::SESSION_ID );
		$order->add_order_note(
			sprintf(
				/* translators: %s: masked card number. */
				__( 'Charging saved card %s through Thawani.', 'thawani-pay-for-woocommerce' ),
				$masked ? $masked : $card_id
			)
		);
		$order->save();

		$intent = $client->confirm_payment_intent( (string) $intent['id'] );
		$status = PaymentSync::apply_intent( $order, $intent );

		if ( PaymentSync::PAID === $status ) {
			return array(
				'status'   => 'paid',
				'redirect' => $order->get_checkout_order_received_url(),
			);
		}

		$next = isset( $intent['next_action']['url'] ) ? (string) $intent['next_action']['url'] : '';
		if ( $next ) {
			Reconciler::schedule_checks( $order->get_id() );
			return array(
				'status'   => 'action',
				'redirect' => $next,
				'intent'   => $intent,
			);
		}

		throw new ApiException( 'Payment intent could not be completed: ' . ( $intent['status'] ?? 'unknown' ), 402, ApiException::METHOD_REJECTED );
	}

	/**
	 * Reuse the order's open session when nothing changed, so repeated clicks do not create duplicate invoices.
	 *
	 * @param \WC_Order $order  Order.
	 * @param Client    $client Client.
	 * @param int       $total  Current total in baisa.
	 */
	private static function reusable_session( \WC_Order $order, Client $client, int $total ): ?string {
		$session_id = (string) $order->get_meta( OrderMeta::SESSION_ID );

		if (
			'' === $session_id
			|| OrderMeta::mode( $order ) !== $client->mode()
			|| (int) $order->get_meta( OrderMeta::AMOUNT ) !== $total
			|| (int) $order->get_meta( OrderMeta::EXPIRES_AT ) < time() + 10 * MINUTE_IN_SECONDS
		) {
			return null;
		}

		try {
			$session = $client->get_session( $session_id );
		} catch ( ApiException $e ) {
			return null;
		}

		if ( 'unpaid' === strtolower( (string) ( $session['payment_status'] ?? '' ) ) ) {
			return $client->checkout_url( $session_id );
		}

		if ( PaymentSync::PAID === PaymentSync::apply_session( $order, $session ) ) {
			return $order->get_checkout_order_received_url();
		}

		return null;
	}

	/**
	 * Cancel the order's previous, still-unpaid checkout session at Thawani (best effort).
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function cancel_open_session( \WC_Order $order ): void {
		$session_id = (string) $order->get_meta( OrderMeta::SESSION_ID );
		if ( '' === $session_id || $session_id === (string) $order->get_meta( OrderMeta::CANCELLED ) ) {
			return;
		}

		try {
			$client  = Client::for_mode( OrderMeta::mode( $order ) );
			$session = $client->get_session( $session_id );

			if ( 'paid' === strtolower( (string) ( $session['payment_status'] ?? '' ) ) ) {
				PaymentSync::apply_session( $order, $session );
				return;
			}

			if ( 'unpaid' === strtolower( (string) ( $session['payment_status'] ?? '' ) ) ) {
				$client->cancel_session( $session_id );
				Logger::info( sprintf( 'Order #%d: cancelled superseded session.', $order->get_id() ) );
			}
		} catch ( ApiException $e ) {
			Logger::debug( 'Could not cancel superseded session.', array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Lines of the order in baisa (after discounts, including tax).
	 *
	 * @param \WC_Order $order Order.
	 * @return array<int, array{name:string, quantity:int, total:int}>
	 */
	public static function order_lines( \WC_Order $order ): array {
		$lines = array();

		foreach ( $order->get_items( array( 'line_item', 'shipping', 'fee' ) ) as $item ) {
			$name = $item->get_name();
			if ( $item instanceof \WC_Order_Item_Shipping ) {
				/* translators: %s: shipping method name. */
				$name = sprintf( __( 'Shipping: %s', 'thawani-pay-for-woocommerce' ), $name );
			}

			$lines[] = array(
				'name'     => $name,
				'quantity' => $item instanceof \WC_Order_Item_Product ? (int) $item->get_quantity() : 1,
				'total'    => Money::to_baisa( (float) $item->get_total() + (float) $item->get_total_tax() ),
			);
		}

		return $lines;
	}

	/**
	 * Session metadata. Thawani requires customer name, contact number and email address in production.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string, string>
	 */
	public static function metadata( \WC_Order $order ): array {
		$meta = array(
			'Customer name'  => trim( $order->get_formatted_billing_full_name() ),
			'Contact number' => (string) $order->get_billing_phone(),
			'Email address'  => (string) $order->get_billing_email(),
			'order_id'       => (string) $order->get_id(),
			'order_number'   => (string) $order->get_order_number(),
			'store'          => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'platform'       => 'WooCommerce',
		);

		/**
		 * Filter the metadata attached to Thawani sessions and payment intents.
		 *
		 * @param array     $meta  Key/value pairs (strings).
		 * @param \WC_Order $order Order.
		 */
		$meta = (array) apply_filters( 'thawani_pay_metadata', $meta, $order );

		$clean = array();
		foreach ( $meta as $key => $value ) {
			$value = is_scalar( $value ) ? trim( (string) $value ) : '';
			if ( '' !== $value ) {
				$clean[ (string) $key ] = mb_substr( $value, 0, 250, 'UTF-8' );
			}
		}

		return $clean;
	}
}
