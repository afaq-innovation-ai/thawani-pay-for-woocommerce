<?php
/**
 * Recurring payments with "Subscriptions for WooCommerce" by WP Swings.
 *
 * @package AfaqInnovation\ThawaniPay
 */

namespace AfaqInnovation\ThawaniPay\Subscriptions;

use AfaqInnovation\ThawaniPay\Api\ApiException;
use AfaqInnovation\ThawaniPay\Api\Client;
use AfaqInnovation\ThawaniPay\Gateway\CheckoutService;
use AfaqInnovation\ThawaniPay\Gateway\Gateway;
use AfaqInnovation\ThawaniPay\Gateway\OrderMeta;
use AfaqInnovation\ThawaniPay\Gateway\PaymentSync;
use AfaqInnovation\ThawaniPay\Support\Logger;
use AfaqInnovation\ThawaniPay\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * How it works
 *
 * 1. At checkout a subscription order always saves the customer's card at Thawani.
 * 2. When the order is paid, the card id is stored on the subscription.
 * 3. On each renewal the plugin charges that card with a Thawani payment intent:
 *    - `succeeded`       → the renewal order is paid and the subscription stays active;
 *    - `requires_action` → Thawani wants the cardholder's OTP. The subscription is put on hold and the
 *                          customer is emailed a link to the renewal order, where they confirm with OTP
 *                          (or use another card). Paying it re-activates the subscription;
 *    - declined          → the renewal order fails and the customer receives the same payment link.
 * 4. A renewal paid with a different card updates the card used for the next renewals.
 *
 * Thawani currently asks for an OTP on every saved-card charge, so step 3 usually takes the
 * `requires_action` path. If Thawani enables charges without OTP on your account, renewals become fully automatic
 * with no configuration change.
 */
final class WpsSubscriptions {

	const META_CARD_ID = '_thawani_card_id';
	const META_MODE    = '_thawani_mode';
	const META_MASKED  = '_thawani_masked_card';

	/**
	 * Hooks (only when the WP Swings plugin is installed and enabled).
	 */
	public static function init(): void {
		if ( ! self::is_active() ) {
			return;
		}

		add_filter( 'wps_sfw_supported_payment_gateway_for_woocommerce', array( __CLASS__, 'supported_gateway' ), 10, 2 );
		add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', array( __CLASS__, 'renewal_statuses' ), 10, 2 );
		add_filter( 'thawani_pay_force_save_card', array( __CLASS__, 'force_save_card' ), 10, 2 );
		add_filter( 'thawani_pay_checkout_description', array( __CLASS__, 'checkout_description' ) );
		add_filter( 'thawani_pay_show_save_option', array( __CLASS__, 'show_save_option' ) );
		add_action( 'thawani_pay_payment_completed', array( __CLASS__, 'remember_card' ), 10, 2 );
		add_action( 'wps_sfw_other_payment_gateway_renewal', array( __CLASS__, 'process_renewal' ), 10, 3 );
		add_action( 'wps_sfw_subscription_cancel', array( __CLASS__, 'on_cancel' ), 10, 2 );
		add_filter( 'thawani_pay_order_box_rows', array( __CLASS__, 'order_box_rows' ), 10, 2 );
	}

	/**
	 * Show the related subscription in the order screen box.
	 *
	 * @param array     $rows  Rows.
	 * @param \WC_Order $order Order.
	 */
	public static function order_box_rows( $rows, $order ): array {
		$rows = (array) $rows;
		if ( ! $order instanceof \WC_Order ) {
			return $rows;
		}

		$ids = self::subscriptions_for_order( $order );
		if ( $ids ) {
			$label          = self::is_renewal( $order ) ? __( 'Renewal of', 'thawani-pay-for-woocommerce' ) : __( 'Subscription', 'thawani-pay-for-woocommerce' );
			$rows[ $label ] = '#' . implode( ', #', $ids );
		}

		return $rows;
	}

	/**
	 * WP Swings "Subscriptions for WooCommerce" present and switched on.
	 */
	public static function is_active(): bool {
		return function_exists( 'wps_sfw_check_plugin_enable' ) && wps_sfw_check_plugin_enable() && function_exists( 'wps_sfw_get_meta_data' );
	}

	/**
	 * Offer Thawani for carts that contain subscription products.
	 *
	 * @param string[] $supported Gateway ids allowed for subscriptions.
	 * @param string   $gateway   Gateway being checked.
	 */
	public static function supported_gateway( $supported, $gateway ): array {
		$supported = (array) $supported;
		if ( Gateway::ID === $gateway ) {
			$supported[] = Gateway::ID;
		}

		return $supported;
	}

	/**
	 * Let WooCommerce complete payment on renewal orders in WP Swings' custom status.
	 *
	 * @param string[]       $statuses Statuses.
	 * @param \WC_Order|null $order    Order.
	 */
	public static function renewal_statuses( $statuses, $order = null ): array {
		$statuses = (array) $statuses;
		if ( $order instanceof \WC_Order && Gateway::ID === $order->get_payment_method() && self::is_renewal( $order ) ) {
			$statuses[] = 'wps_renewal';
		}

		return $statuses;
	}

	/**
	 * Subscription orders must save the card so renewals can charge it.
	 *
	 * @param bool      $save  Customer's choice.
	 * @param \WC_Order $order Order.
	 */
	public static function force_save_card( $save, $order ): bool {
		return $save || ( $order instanceof \WC_Order && ( self::starts_subscription( $order ) || self::is_renewal( $order ) ) );
	}

	/**
	 * The card is always saved for subscriptions, so the opt-in checkbox would only confuse.
	 *
	 * @param bool $show Show the checkbox.
	 */
	public static function show_save_option( $show ): bool {
		return (bool) $show && ! self::cart_has_subscription();
	}

	/**
	 * Current cart contains a subscription product.
	 */
	private static function cart_has_subscription(): bool {
		return function_exists( 'wps_sfw_is_cart_has_subscription_product' ) && function_exists( 'WC' ) && WC()->cart && wps_sfw_is_cart_has_subscription_product();
	}

	/**
	 * Tell the customer their card will be kept for renewals.
	 *
	 * @param string $description Gateway description.
	 */
	public static function checkout_description( $description ): string {
		if ( self::cart_has_subscription() ) {
			$description .= "\n\n" . __( 'Your card will be saved securely with Thawani to pay future renewals of your subscription. You can cancel the subscription at any time from your account.', 'thawani-pay-for-woocommerce' );
		}

		return (string) $description;
	}

	/**
	 * After a parent or renewal order is paid, store the card on its subscription(s).
	 *
	 * @param \WC_Order  $order   Paid order.
	 * @param array|null $payment Thawani payment.
	 */
	public static function remember_card( $order, $payment = null ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$order   = wc_get_order( $order->get_id() );
		$card_id = (string) $order->get_meta( OrderMeta::CARD_ID );

		foreach ( self::subscriptions_for_order( $order ) as $subscription_id ) {
			if ( $card_id ) {
				wps_sfw_update_meta_data( $subscription_id, self::META_CARD_ID, $card_id );
				wps_sfw_update_meta_data( $subscription_id, self::META_MODE, OrderMeta::mode( $order ) );
				wps_sfw_update_meta_data( $subscription_id, self::META_MASKED, (string) $order->get_meta( OrderMeta::CARD ) );
			} elseif ( self::starts_subscription( $order ) ) {
				$order->add_order_note( __( 'Thawani could not identify the saved card for this subscription. Renewals will ask the customer to pay manually.', 'thawani-pay-for-woocommerce' ) );
			}
		}
	}

	/**
	 * Charge a renewal order.
	 *
	 * @param \WC_Order $order           Renewal order created by WP Swings.
	 * @param int       $subscription_id Subscription id.
	 * @param string    $payment_method  Gateway id stored on the subscription.
	 */
	public static function process_renewal( $order, $subscription_id, $payment_method ): void {
		if ( ! $order instanceof \WC_Order || Gateway::ID !== $payment_method || ! self::is_renewal( $order ) ) {
			return;
		}

		if ( $order->is_paid() ) {
			return;
		}

		if ( (float) $order->get_total() <= 0 ) {
			$order->payment_complete();
			return;
		}

		$card_id = (string) wps_sfw_get_meta_data( $subscription_id, self::META_CARD_ID, true );
		$mode    = (string) wps_sfw_get_meta_data( $subscription_id, self::META_MODE, true );
		$masked  = (string) wps_sfw_get_meta_data( $subscription_id, self::META_MASKED, true );

		if ( '' === $card_id ) {
			self::needs_customer( $order, $subscription_id, __( 'No saved Thawani card is linked to this subscription.', 'thawani-pay-for-woocommerce' ), 'pending' );
			return;
		}

		try {
			$result = CheckoutService::charge_card( $order, $card_id, $mode ? $mode : OrderMeta::mode( $order ), $masked );
		} catch ( ApiException $e ) {
			Logger::error( sprintf( 'Renewal order #%d declined.', $order->get_id() ), array( 'error' => $e->getMessage() ) );
			self::needs_customer(
				$order,
				$subscription_id,
				/* translators: %s: reason returned by Thawani. */
				sprintf( __( 'Thawani declined the automatic renewal charge: %s', 'thawani-pay-for-woocommerce' ), $e->getMessage() ),
				'failed'
			);
			return;
		}

		if ( 'paid' === $result['status'] ) {
			$order = wc_get_order( $order->get_id() );
			$order->add_order_note(
				sprintf(
					/* translators: 1: amount, 2: subscription id. */
					__( 'Renewal of %1$s for subscription #%2$s charged automatically through Thawani.', 'thawani-pay-for-woocommerce' ),
					Money::format_baisa( Money::to_baisa( $order->get_total() ) ),
					$subscription_id
				)
			);
			do_action( 'wps_sfw_recurring_payment_success', $order->get_id() );
			return;
		}

		// Thawani needs the cardholder (OTP). The OTP link is short-lived, so close it and send the durable order-pay link.
		if ( ! empty( $result['intent']['id'] ) ) {
			try {
				Client::for_mode( OrderMeta::mode( $order ) )->cancel_payment_intent( (string) $result['intent']['id'] );
			} catch ( ApiException $e ) {
				Logger::debug( 'Could not cancel renewal intent.', array( 'error' => $e->getMessage() ) );
			}
		}

		$order = wc_get_order( $order->get_id() );
		$order->delete_meta_data( OrderMeta::INTENT_ID );
		$order->save();

		self::needs_customer( $order, $subscription_id, __( 'Thawani requires the cardholder to confirm this renewal with an OTP.', 'thawani-pay-for-woocommerce' ), 'pending' );
	}

	/**
	 * Put the subscription on hold and email the customer a link to pay the renewal.
	 *
	 * @param \WC_Order $order           Renewal order.
	 * @param int       $subscription_id Subscription id.
	 * @param string    $reason          Why the customer is needed.
	 * @param string    $status          Order status to set: pending or failed.
	 */
	private static function needs_customer( \WC_Order $order, $subscription_id, string $reason, string $status ): void {
		$note = rtrim( $reason, '. ' ) . '. ' . __( 'The subscription is on hold and the customer was emailed a link to pay this renewal. Paying it re-activates the subscription.', 'thawani-pay-for-woocommerce' );

		if ( 'failed' === $status ) {
			$order->update_status( 'failed', $note );
			do_action( 'wps_sfw_recurring_payment_failed', $order->get_id() );
		} else {
			$order->add_order_note( $note );
		}

		wps_sfw_update_meta_data( $subscription_id, 'wps_subscription_status', 'on-hold' );

		$emails = WC()->mailer() ? WC()->mailer()->get_emails() : array();
		if ( isset( $emails['WC_Email_Customer_Invoice'] ) ) {
			$emails['WC_Email_Customer_Invoice']->trigger( $order->get_id(), $order );
			$order->add_order_note( __( 'Renewal payment link sent to the customer.', 'thawani-pay-for-woocommerce' ) );
		}

		/**
		 * Fires when a Thawani renewal needs the customer to pay manually.
		 *
		 * @param \WC_Order $order           Renewal order.
		 * @param int       $subscription_id Subscription id.
		 * @param string    $reason          Reason.
		 */
		do_action( 'thawani_pay_renewal_requires_customer', $order, $subscription_id, $reason );
	}

	/**
	 * Cancelling a subscription only stops future renewals; nothing is scheduled at Thawani.
	 *
	 * @param int    $subscription_id Subscription id.
	 * @param string $status          Status label.
	 */
	public static function on_cancel( $subscription_id, $status ): void {
		if ( 'Cancel' !== $status || Gateway::ID !== (string) wps_sfw_get_meta_data( $subscription_id, '_payment_method', true ) ) {
			return;
		}

		if ( function_exists( 'wps_sfw_send_email_for_cancel_susbcription' ) ) {
			wps_sfw_send_email_for_cancel_susbcription( $subscription_id );
		}
		wps_sfw_update_meta_data( $subscription_id, 'wps_subscription_status', 'cancelled' );
	}

	/**
	 * Renewal order created by WP Swings.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function is_renewal( \WC_Order $order ): bool {
		return 'yes' === (string) wps_sfw_get_meta_data( $order->get_id(), 'wps_sfw_renewal_order', true );
	}

	/**
	 * Order that starts one or more subscriptions.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function starts_subscription( \WC_Order $order ): bool {
		return 'yes' === (string) wps_sfw_get_meta_data( $order->get_id(), 'wps_sfw_order_has_subscription', true );
	}

	/**
	 * Subscription ids attached to a parent or renewal order.
	 *
	 * @param \WC_Order $order Order.
	 * @return int[]
	 */
	private static function subscriptions_for_order( \WC_Order $order ): array {
		if ( self::is_renewal( $order ) ) {
			$id = (int) wps_sfw_get_meta_data( $order->get_id(), 'wps_sfw_subscription', true );
			return $id ? array( $id ) : array();
		}

		if ( ! self::starts_subscription( $order ) ) {
			return array();
		}

		$ids = wc_get_orders(
			array(
				'type'       => 'wps_subscriptions',
				'limit'      => -1,
				'return'     => 'ids',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => 'wps_parent_order',
						'value' => $order->get_id(),
					),
				),
			)
		);

		$ids = array_map( 'intval', (array) $ids );

		$direct = (int) wps_sfw_get_meta_data( $order->get_id(), 'wps_subscription_id', true );
		if ( $direct && ! in_array( $direct, $ids, true ) ) {
			$ids[] = $direct;
		}

		return $ids;
	}
}
