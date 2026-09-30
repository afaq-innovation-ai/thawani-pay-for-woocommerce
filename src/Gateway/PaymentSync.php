<?php
/**
 * Applies the state reported by Thawani to a WooCommerce order.
 *
 * @package AfaqInnovation\ThawaniPay
 */

namespace AfaqInnovation\ThawaniPay\Gateway;

use AfaqInnovation\ThawaniPay\Api\ApiException;
use AfaqInnovation\ThawaniPay\Api\Client;
use AfaqInnovation\ThawaniPay\Support\Logger;
use AfaqInnovation\ThawaniPay\Support\Money;
use AfaqInnovation\ThawaniPay\Tokens\TokenManager;

defined( 'ABSPATH' ) || exit;

/**
 * Single, idempotent place where orders become paid.
 *
 * The customer redirect, the webhook, the scheduled reconciler and the admin
 * "Sync" button all end up here. The plugin never trusts a redirect or a webhook
 * body on its own: it always asks the Thawani API for the authoritative state.
 */
final class PaymentSync {

	const PAID      = 'paid';
	const UNPAID    = 'unpaid';
	const CANCELLED = 'cancelled';
	const MISMATCH  = 'mismatch';
	const UNKNOWN   = 'unknown';

	/**
	 * Refresh an order from Thawani.
	 *
	 * @param \WC_Order $order Order.
	 * @return string One of the class constants.
	 */
	public static function sync( \WC_Order $order ): string {
		if ( $order->get_meta( OrderMeta::INTENT_ID ) ) {
			return self::sync_intent( $order );
		}

		$session_id = (string) $order->get_meta( OrderMeta::SESSION_ID );
		if ( '' === $session_id ) {
			return self::UNKNOWN;
		}

		try {
			$session = Client::for_mode( OrderMeta::mode( $order ) )->get_session( $session_id );
		} catch ( ApiException $e ) {
			Logger::error( sprintf( 'Order #%d: unable to retrieve session.', $order->get_id() ), array( 'error' => $e->getMessage() ) );
			return self::UNKNOWN;
		}

		return self::apply_session( $order, $session );
	}

	/**
	 * Apply a checkout session to the order.
	 *
	 * @param \WC_Order $order   Order.
	 * @param array     $session Session returned by the API.
	 */
	public static function apply_session( \WC_Order $order, array $session ): string {
		$session_id = (string) ( $session['session_id'] ?? '' );

		if ( $session_id !== (string) $order->get_meta( OrderMeta::SESSION_ID ) ) {
			Logger::warning( sprintf( 'Order #%d: ignoring stale session %s.', $order->get_id(), $session_id ) );
			return self::MISMATCH;
		}

		$status = strtolower( (string) ( $session['payment_status'] ?? '' ) );

		if ( 'paid' === $status ) {
			$client  = Client::for_mode( OrderMeta::mode( $order ) );
			$payment = null;
			try {
				$payment = $client->find_successful_payment( array( 'checkout_invoice' => (string) ( $session['invoice'] ?? '' ) ) );
			} catch ( ApiException $e ) {
				Logger::warning( sprintf( 'Order #%d: paid session, payment lookup failed.', $order->get_id() ), array( 'error' => $e->getMessage() ) );
			}

			return self::complete( $order, (int) ( $session['total_amount'] ?? 0 ), $payment, (string) ( $session['invoice'] ?? '' ) );
		}

		if ( 'cancelled' === $status ) {
			// A customer who pressed "Cancel" keeps a pending order they can pay later; WooCommerce's
			// hold-stock timeout releases it if they never do. Only system cancellations mark it failed.
			if ( $session_id !== (string) $order->get_meta( OrderMeta::CANCELLED ) ) {
				self::fail( $order, __( 'The Thawani checkout session was cancelled or expired without payment.', 'thawani-pay-for-woocommerce' ) );
			}
			return self::CANCELLED;
		}

		$expires = isset( $session['expire_at'] ) ? strtotime( (string) $session['expire_at'] ) : 0;
		if ( $expires && $expires < time() ) {
			self::fail( $order, __( 'The Thawani checkout session expired without payment.', 'thawani-pay-for-woocommerce' ) );
			return self::CANCELLED;
		}

		return self::UNPAID;
	}

	/**
	 * Refresh a saved-card (payment intent) order.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function sync_intent( \WC_Order $order ): string {
		try {
			$intent = Client::for_mode( OrderMeta::mode( $order ) )->get_payment_intent( (string) $order->get_meta( OrderMeta::INTENT_ID ) );
		} catch ( ApiException $e ) {
			Logger::error( sprintf( 'Order #%d: unable to retrieve payment intent.', $order->get_id() ), array( 'error' => $e->getMessage() ) );
			return self::UNKNOWN;
		}

		return self::apply_intent( $order, $intent );
	}

	/**
	 * Apply a payment intent to the order.
	 *
	 * @param \WC_Order $order  Order.
	 * @param array     $intent Intent returned by the API.
	 */
	public static function apply_intent( \WC_Order $order, array $intent ): string {
		$intent_id = (string) ( $intent['id'] ?? '' );

		if ( $intent_id !== (string) $order->get_meta( OrderMeta::INTENT_ID ) ) {
			return self::MISMATCH;
		}

		$status = strtolower( (string) ( $intent['status'] ?? '' ) );

		if ( 'succeeded' === $status ) {
			$payment = null;
			try {
				$payment = Client::for_mode( OrderMeta::mode( $order ) )->find_successful_payment( array( 'payment_intent' => $intent_id ) );
			} catch ( ApiException $e ) {
				Logger::warning( sprintf( 'Order #%d: intent succeeded, payment lookup failed.', $order->get_id() ), array( 'error' => $e->getMessage() ) );
			}

			return self::complete( $order, (int) ( $intent['amount'] ?? 0 ), $payment, $intent_id );
		}

		if ( 'cancelled' === $status ) {
			self::fail( $order, __( 'The saved-card payment was cancelled.', 'thawani-pay-for-woocommerce' ) );
			return self::CANCELLED;
		}

		return self::UNPAID;
	}

	/**
	 * Mark the order paid (exactly once).
	 *
	 * @param \WC_Order  $order     Order.
	 * @param int        $amount    Amount Thawani reports as collected, in baisa.
	 * @param array|null $payment   Payment object, when available.
	 * @param string     $fallback  Transaction id to use when the payment object is unavailable.
	 */
	private static function complete( \WC_Order $order, int $amount, ?array $payment, string $fallback ): string {
		if ( $order->is_paid() && $order->get_transaction_id() ) {
			return self::PAID;
		}

		$order_id = $order->get_id();

		if ( ! self::lock( $order_id ) ) {
			return self::PAID; // Another request is completing this order right now.
		}

		try {
			$order = wc_get_order( $order_id ); // Re-read inside the lock.
			if ( ! $order instanceof \WC_Order || ( $order->is_paid() && $order->get_transaction_id() ) ) {
				return self::PAID;
			}

			$expected = (int) $order->get_meta( OrderMeta::AMOUNT );
			if ( $expected && $amount && $amount !== $expected ) {
				$order->update_status(
					'on-hold',
					sprintf(
						/* translators: 1: amount paid, 2: amount expected. */
						__( 'Thawani reports %1$s paid but the order expected %2$s. Please review before fulfilling.', 'thawani-pay-for-woocommerce' ),
						Money::format_baisa( $amount ),
						Money::format_baisa( $expected )
					)
				);
				return self::MISMATCH;
			}

			$payment_id = $payment ? (string) ( $payment['payment_id'] ?? '' ) : '';
			$card       = $payment ? (string) ( $payment['masked_card'] ?? '' ) : '';
			$card_type  = $payment ? (string) ( $payment['card_type'] ?? '' ) : '';

			if ( $payment_id ) {
				$order->update_meta_data( OrderMeta::PAYMENT_ID, $payment_id );
			}
			if ( $card ) {
				$order->update_meta_data( OrderMeta::CARD, $card );
				$order->update_meta_data( OrderMeta::CARD_TYPE, $card_type );
			}

			$order->add_order_note(
				$card
					? sprintf(
						/* translators: 1: amount, 2: masked card, 3: card type, 4: payment id. */
						__( 'Thawani payment of %1$s received with card %2$s (%3$s). Payment ID: %4$s', 'thawani-pay-for-woocommerce' ),
						Money::format_baisa( $amount ? $amount : $expected ),
						$card,
						$card_type,
						$payment_id
					)
					: sprintf(
						/* translators: %s: amount. */
						__( 'Thawani payment of %s received.', 'thawani-pay-for-woocommerce' ),
						Money::format_baisa( $amount ? $amount : $expected )
					)
			);

			$order->payment_complete( $payment_id ? $payment_id : $fallback );

			Logger::info( sprintf( 'Order #%d marked as paid.', $order->get_id() ), array( 'payment_id' => $payment_id ) );

			if ( 'yes' === $order->get_meta( OrderMeta::SAVE_CARD ) && $order->get_customer_id() ) {
				TokenManager::sync( $order->get_customer_id(), OrderMeta::mode( $order ) );

				if ( ! $order->get_meta( OrderMeta::CARD_ID ) && $card ) {
					$card_id = TokenManager::find_card_id( $order->get_customer_id(), OrderMeta::mode( $order ), $card );
					if ( $card_id ) {
						$order->update_meta_data( OrderMeta::CARD_ID, $card_id );
						$order->save();
					}
				}
			}

			/**
			 * Fires after an order has been paid through Thawani.
			 *
			 * @param \WC_Order  $order   The order.
			 * @param array|null $payment Thawani payment object (payment_id, masked_card, card_type, …).
			 */
			do_action( 'thawani_pay_payment_completed', $order, $payment );

			return self::PAID;
		} finally {
			self::unlock( $order_id );
		}
	}

	/**
	 * Move an unpaid order to failed (once).
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $note  Note.
	 */
	private static function fail( \WC_Order $order, string $note ): void {
		if ( $order->is_paid() || ! $order->has_status( array( 'pending', 'on-hold' ) ) ) {
			return;
		}

		$order->update_status( 'failed', $note );
	}

	/**
	 * Per-order mutex (add_option is atomic thanks to the unique option_name index).
	 *
	 * @param int $order_id Order id.
	 */
	private static function lock( int $order_id ): bool {
		$key = 'thawani_pay_lock_' . $order_id;

		if ( add_option( $key, time(), '', false ) ) {
			return true;
		}

		if ( (int) get_option( $key ) < time() - 60 ) {
			update_option( $key, time(), false ); // Stale lock left by a crashed request.
			return true;
		}

		return false;
	}

	/**
	 * Release the mutex.
	 *
	 * @param int $order_id Order id.
	 */
	private static function unlock( int $order_id ): void {
		delete_option( 'thawani_pay_lock_' . $order_id );
	}
}
