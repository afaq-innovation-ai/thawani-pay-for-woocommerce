<?php
/**
 * Order-received page enhancements.
 *
 * @package AfaqInnovation\ThawaniPay
 */

namespace AfaqInnovation\ThawaniPay\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the card used, and — when the bank is still processing — a live
 * "confirming your payment" panel that polls until Thawani settles the order.
 */
final class ThankYou {

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'woocommerce_before_thankyou', array( __CLASS__, 'pending_panel' ), 5 );
		add_action( 'woocommerce_thankyou_' . Gateway::ID, array( __CLASS__, 'payment_details' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'styles' ) );
	}

	/**
	 * Front-end styles for checkout and order pages.
	 */
	public static function styles(): void {
		if ( function_exists( 'is_checkout' ) && ( is_checkout() || is_account_page() ) ) {
			wp_enqueue_style( 'thawani-pay', THAWANI_PAY_URL . 'assets/css/checkout.css', array(), THAWANI_PAY_VERSION );
		}
	}

	/**
	 * Polling endpoint: GET /wp-json/thawani-pay/v1/order-status?order_id=…&key=…
	 */
	public static function routes(): void {
		register_rest_route(
			'thawani-pay/v1',
			'/order-status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => '__return_true', // Authorised by the order key.
				'args'                => array(
					'order_id' => array( 'sanitize_callback' => 'absint' ),
					'key'      => array( 'sanitize_callback' => 'sanitize_text_field' ),
				),
				'callback'            => static function ( \WP_REST_Request $request ) {
					$order = wc_get_order( (int) $request->get_param( 'order_id' ) );
					$key   = (string) $request->get_param( 'key' );

					if ( ! $order instanceof \WC_Order || '' === $key || ! hash_equals( $order->get_order_key(), $key ) ) {
						return new \WP_REST_Response( array( 'error' => 'not_found' ), 404 );
					}

					// Throttle API calls to one every 4 seconds per order.
					$throttle = 'thawani_pay_poll_' . $order->get_id();
					if ( ! $order->is_paid() && ! get_transient( $throttle ) ) {
						set_transient( $throttle, 1, 4 );
						PaymentSync::sync( $order );
						$order = wc_get_order( $order->get_id() );
					}

					return new \WP_REST_Response(
						array(
							'paid'   => $order->is_paid(),
							'status' => $order->get_status(),
						),
						200
					);
				},
			)
		);
	}

	/**
	 * "Confirming your payment" panel.
	 *
	 * @param int $order_id Order id.
	 */
	public static function pending_panel( $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order || Gateway::ID !== $order->get_payment_method() || $order->is_paid() || ! $order->has_status( 'pending' ) ) {
			return;
		}

		$endpoint = add_query_arg(
			array(
				'order_id' => $order->get_id(),
				'key'      => $order->get_order_key(),
			),
			rest_url( 'thawani-pay/v1/order-status' )
		);
		?>
		<div class="thawani-pay-pending" data-endpoint="<?php echo esc_url( $endpoint ); ?>">
			<span class="thawani-pay-spinner" aria-hidden="true"></span>
			<div>
				<strong><?php esc_html_e( 'Confirming your payment with Thawani…', 'thawani-pay-for-woocommerce' ); ?></strong>
				<p><?php esc_html_e( 'This usually takes a few seconds. You can keep this page open — it updates automatically.', 'thawani-pay-for-woocommerce' ); ?></p>
			</div>
		</div>
		<script>
		( function () {
			var box = document.querySelector( '.thawani-pay-pending' );
			if ( ! box || ! window.fetch ) { return; }
			var tries = 0;
			( function poll() {
				if ( ++tries > 40 ) { return; }
				fetch( box.getAttribute( 'data-endpoint' ), { credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( d ) { if ( d && ( d.paid || 'failed' === d.status || 'cancelled' === d.status ) ) { window.location.reload(); } else { setTimeout( poll, 3000 ); } } )
					.catch( function () { setTimeout( poll, 5000 ); } );
			} )();
		} )();
		</script>
		<?php
	}

	/**
	 * Card details under the order overview.
	 *
	 * @param int $order_id Order id.
	 */
	public static function payment_details( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || ! $order->is_paid() ) {
			return;
		}

		$card       = (string) $order->get_meta( OrderMeta::CARD );
		$payment_id = (string) $order->get_meta( OrderMeta::PAYMENT_ID );
		if ( '' === $card && '' === $payment_id ) {
			return;
		}
		?>
		<section class="thawani-pay-receipt">
			<h2><?php esc_html_e( 'Payment details', 'thawani-pay-for-woocommerce' ); ?></h2>
			<ul>
				<?php if ( $card ) : ?>
					<li><span><?php esc_html_e( 'Card', 'thawani-pay-for-woocommerce' ); ?></span><strong dir="ltr"><?php echo esc_html( $card ); ?></strong></li>
				<?php endif; ?>
				<?php if ( $payment_id ) : ?>
					<li><span><?php esc_html_e( 'Payment ID', 'thawani-pay-for-woocommerce' ); ?></span><strong dir="ltr"><?php echo esc_html( $payment_id ); ?></strong></li>
				<?php endif; ?>
				<li><span><?php esc_html_e( 'Processed by', 'thawani-pay-for-woocommerce' ); ?></span><strong>Thawani</strong></li>
			</ul>
		</section>
		<?php
	}
}
