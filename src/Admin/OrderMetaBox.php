<?php
/**
 * "Thawani Pay" box on the order edit screen.
 *
 * @package AfaqInnovation\ThawaniPay
 */

namespace AfaqInnovation\ThawaniPay\Admin;

use AfaqInnovation\ThawaniPay\Gateway\Gateway;
use AfaqInnovation\ThawaniPay\Gateway\OrderMeta;
use AfaqInnovation\ThawaniPay\Gateway\PaymentSync;
use AfaqInnovation\ThawaniPay\Support\Money;
use AfaqInnovation\ThawaniPay\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Shows session, invoice, payment and card details, plus a one-click "Sync with Thawani".
 * Works with both HPOS and legacy post storage.
 */
final class OrderMetaBox {

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ), 10, 2 );
		add_action( 'admin_post_thawani_pay_sync', array( __CLASS__, 'sync' ) );
		add_action( 'admin_notices', array( __CLASS__, 'result_notice' ) );
	}

	/**
	 * Register the box.
	 *
	 * @param string             $post_type Screen / post type.
	 * @param \WP_Post|\WC_Order $post_or_order    Post or order.
	 */
	public static function register( $post_type, $post_or_order ): void {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : ( $post_or_order instanceof \WP_Post ? wc_get_order( $post_or_order->ID ) : null );

		if ( ! $order instanceof \WC_Order || Gateway::ID !== $order->get_payment_method() ) {
			return;
		}

		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';

		add_meta_box( 'thawani-pay-order', __( 'Thawani Pay', 'thawani-pay-for-woocommerce' ), array( __CLASS__, 'render' ), $screen, 'side', 'high' );
	}

	/**
	 * Render the box.
	 *
	 * @param \WP_Post|\WC_Order $post_or_order Post or order.
	 */
	public static function render( $post_or_order ): void {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$mode    = OrderMeta::mode( $order );
		$amount  = (int) $order->get_meta( OrderMeta::AMOUNT );
		$amount  = $amount ? $amount : Money::to_baisa( $order->get_total() );
		$card    = (string) $order->get_meta( OrderMeta::CARD );
		$refunds = array_filter( (array) $order->get_meta( OrderMeta::REFUNDS ) );

		$refunded = 0;
		foreach ( $refunds as $refund ) {
			$refunded += (int) ( $refund['amount'] ?? 0 );
		}

		if ( $order->is_paid() ) {
			$state = $refunded >= $amount && $refunded > 0 ? 'refunded' : ( $refunded > 0 ? 'partial' : 'paid' );
		} else {
			$state = $order->has_status( array( 'failed', 'cancelled' ) ) ? 'cancelled' : 'unpaid';
		}

		$labels = array(
			'paid'      => __( 'Paid', 'thawani-pay-for-woocommerce' ),
			'partial'   => __( 'Partially refunded', 'thawani-pay-for-woocommerce' ),
			'refunded'  => __( 'Refunded', 'thawani-pay-for-woocommerce' ),
			'unpaid'    => __( 'Awaiting payment', 'thawani-pay-for-woocommerce' ),
			'cancelled' => __( 'Not paid', 'thawani-pay-for-woocommerce' ),
		);

		$rows = array(
			__( 'Payment ID', 'thawani-pay-for-woocommerce' ) => (string) $order->get_meta( OrderMeta::PAYMENT_ID ),
			__( 'Invoice', 'thawani-pay-for-woocommerce' ) => (string) $order->get_meta( OrderMeta::INVOICE ),
		);

		/**
		 * Filter the rows of the Thawani Pay box on the order screen.
		 *
		 * @param array<string, string> $rows  Label => value.
		 * @param \WC_Order             $order Order.
		 */
		$rows = (array) apply_filters( 'thawani_pay_order_box_rows', $rows, $order );

		$technical = array(
			__( 'Environment', 'thawani-pay-for-woocommerce' ) => Settings::is_test( $mode ) ? __( 'Sandbox', 'thawani-pay-for-woocommerce' ) : __( 'Live', 'thawani-pay-for-woocommerce' ),
			__( 'Session', 'thawani-pay-for-woocommerce' ) => (string) $order->get_meta( OrderMeta::SESSION_ID ),
			__( 'Payment intent', 'thawani-pay-for-woocommerce' ) => (string) $order->get_meta( OrderMeta::INTENT_ID ),
			__( 'Reference', 'thawani-pay-for-woocommerce' ) => (string) $order->get_meta( OrderMeta::REFERENCE ),
			__( 'Customer', 'thawani-pay-for-woocommerce' ) => (string) $order->get_meta( OrderMeta::CUSTOMER_ID ),
			__( 'Saved card', 'thawani-pay-for-woocommerce' ) => (string) $order->get_meta( OrderMeta::CARD_ID ),
		);

		$sync_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'thawani_pay_sync',
					'order_id' => $order->get_id(),
				),
				admin_url( 'admin-post.php' )
			),
			'thawani_pay_sync_' . $order->get_id()
		);
		?>
		<div class="tp-box">
			<div class="tp-box__top">
				<div class="tp-box__amount"><?php echo esc_html( number_format( Money::from_baisa( $amount ), 3 ) ); ?> <small>OMR</small></div>
				<span class="tp-pill tp-pill--<?php echo esc_attr( $state ); ?>"><?php echo esc_html( $labels[ $state ] ); ?></span>
			</div>
			<?php if ( Settings::is_test( $mode ) ) : ?>
				<span class="tp-badge tp-badge--test"><?php esc_html_e( 'Sandbox', 'thawani-pay-for-woocommerce' ); ?></span>
			<?php endif; ?>

			<?php if ( $card ) : ?>
				<div class="tp-box__card">
					<img src="<?php echo esc_url( THAWANI_PAY_URL . 'assets/images/' . self::brand( $card ) . '.svg' ); ?>" alt="" width="34" height="22" />
					<span dir="ltr"><?php echo esc_html( $card ); ?></span>
					<?php if ( $order->get_meta( OrderMeta::CARD_TYPE ) ) : ?>
						<small><?php echo esc_html( (string) $order->get_meta( OrderMeta::CARD_TYPE ) ); ?></small>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<dl class="tp-box__meta">
				<?php foreach ( $rows as $label => $value ) : ?>
					<?php
					if ( '' === (string) $value ) {
						continue;
					}
					?>
					<dt><?php echo esc_html( $label ); ?></dt>
					<dd><?php echo esc_html( (string) $value ); ?></dd>
				<?php endforeach; ?>
				<?php if ( $refunded ) : ?>
					<dt><?php esc_html_e( 'Refunded', 'thawani-pay-for-woocommerce' ); ?></dt>
					<dd class="tp-neg">−<?php echo esc_html( Money::format_baisa( $refunded ) ); ?></dd>
				<?php endif; ?>
			</dl>

			<?php if ( $refunds ) : ?>
				<ul class="tp-box__refunds">
					<?php foreach ( $refunds as $refund ) : ?>
						<li><span><?php echo esc_html( (string) ( $refund['refund_id'] ?? '' ) ); ?></span><strong><?php echo esc_html( Money::format_baisa( (int) ( $refund['amount'] ?? 0 ) ) ); ?></strong></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<details class="tp-box__tech">
				<summary><?php esc_html_e( 'Technical details', 'thawani-pay-for-woocommerce' ); ?></summary>
				<dl>
					<?php foreach ( $technical as $label => $value ) : ?>
						<?php
						if ( '' === $value ) {
							continue;
						}
						?>
						<dt><?php echo esc_html( $label ); ?></dt>
						<dd><code><?php echo esc_html( $value ); ?></code></dd>
					<?php endforeach; ?>
				</dl>
			</details>

			<a class="tp-btn tp-btn--block" href="<?php echo esc_url( $sync_url ); ?>">
				<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M21 12a9 9 0 0 1-15.5 6.2M3 12a9 9 0 0 1 15.5-6.2M21 4v5h-5M3 20v-5h5"/></svg>
				<?php esc_html_e( 'Sync with Thawani', 'thawani-pay-for-woocommerce' ); ?>
			</a>
		</div>
		<?php
	}

	/**
	 * Card brand image for a masked number.
	 *
	 * @param string $masked Masked card number.
	 */
	public static function brand( string $masked ): string {
		$first = substr( (string) preg_replace( '/\D/', '', $masked ), 0, 1 );

		if ( '4' === $first ) {
			return 'visa';
		}

		return in_array( $first, array( '2', '5' ), true ) ? 'mastercard' : 'card';
	}

	/**
	 * "Sync with Thawani" action.
	 */
	public static function sync(): void {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;

		check_admin_referer( 'thawani_pay_sync_' . $order_id );

		if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'thawani-pay-for-woocommerce' ) );
		}

		$order  = wc_get_order( $order_id );
		$result = $order instanceof \WC_Order ? PaymentSync::sync( $order ) : PaymentSync::UNKNOWN;

		$back = $order instanceof \WC_Order ? $order->get_edit_order_url() : admin_url( 'admin.php?page=wc-orders' );
		wp_safe_redirect( add_query_arg( 'thawani_synced', rawurlencode( $result ), $back ) );
		exit;
	}

	/**
	 * Notice after a manual sync.
	 */
	public static function result_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$result = isset( $_GET['thawani_synced'] ) ? sanitize_key( wp_unslash( $_GET['thawani_synced'] ) ) : '';
		if ( '' === $result ) {
			return;
		}

		$messages = array(
			PaymentSync::PAID      => array( 'success', __( 'Thawani confirms this order is paid.', 'thawani-pay-for-woocommerce' ) ),
			PaymentSync::UNPAID    => array( 'info', __( 'Thawani reports the payment is still pending.', 'thawani-pay-for-woocommerce' ) ),
			PaymentSync::CANCELLED => array( 'warning', __( 'Thawani reports the payment was cancelled or expired.', 'thawani-pay-for-woocommerce' ) ),
			PaymentSync::MISMATCH  => array( 'warning', __( 'Thawani returned data that does not match this order. See the order notes and log.', 'thawani-pay-for-woocommerce' ) ),
		);
		$message  = $messages[ $result ] ?? array( 'error', __( 'Could not reach Thawani. Check the log under WooCommerce → Status → Logs.', 'thawani-pay-for-woocommerce' ) );

		printf( '<div class="notice notice-%1$s is-dismissible"><p><strong>Thawani Pay:</strong> %2$s</p></div>', esc_attr( $message[0] ), esc_html( $message[1] ) );
	}
}
