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

		$mode = OrderMeta::mode( $order );
		$rows = array(
			__( 'Payment ID', 'thawani-pay-for-woocommerce' ) => $order->get_meta( OrderMeta::PAYMENT_ID ),
			__( 'Card', 'thawani-pay-for-woocommerce' )    => trim( $order->get_meta( OrderMeta::CARD ) . ' ' . ( $order->get_meta( OrderMeta::CARD_TYPE ) ? '(' . $order->get_meta( OrderMeta::CARD_TYPE ) . ')' : '' ) ),
			__( 'Invoice', 'thawani-pay-for-woocommerce' ) => $order->get_meta( OrderMeta::INVOICE ),
			__( 'Amount', 'thawani-pay-for-woocommerce' )  => $order->get_meta( OrderMeta::AMOUNT ) ? Money::format_baisa( (int) $order->get_meta( OrderMeta::AMOUNT ) ) : '',
			__( 'Session', 'thawani-pay-for-woocommerce' ) => $order->get_meta( OrderMeta::SESSION_ID ),
			__( 'Payment intent', 'thawani-pay-for-woocommerce' ) => $order->get_meta( OrderMeta::INTENT_ID ),
			__( 'Reference', 'thawani-pay-for-woocommerce' ) => $order->get_meta( OrderMeta::REFERENCE ),
			__( 'Customer', 'thawani-pay-for-woocommerce' ) => $order->get_meta( OrderMeta::CUSTOMER_ID ),
		);

		/**
		 * Filter the rows of the Thawani Pay box on the order screen.
		 *
		 * @param array<string, string> $rows  Label => value.
		 * @param \WC_Order             $order Order.
		 */
		$rows = (array) apply_filters( 'thawani_pay_order_box_rows', $rows, $order );

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
		<div class="thawani-pay-box">
			<p class="thawani-pay-box__status">
				<span class="thawani-pay-badge thawani-pay-badge--<?php echo esc_attr( $mode ); ?>"><?php echo esc_html( Settings::is_test( $mode ) ? __( 'Sandbox', 'thawani-pay-for-woocommerce' ) : __( 'Live', 'thawani-pay-for-woocommerce' ) ); ?></span>
				<span class="thawani-pay-pill thawani-pay-pill--<?php echo $order->is_paid() ? 'paid' : 'unpaid'; ?>"><?php echo esc_html( $order->is_paid() ? __( 'Paid', 'thawani-pay-for-woocommerce' ) : __( 'Not paid', 'thawani-pay-for-woocommerce' ) ); ?></span>
			</p>
			<dl>
				<?php foreach ( $rows as $label => $value ) : ?>
					<?php
					if ( '' === (string) $value ) {
						continue;
					}
					?>
					<dt><?php echo esc_html( $label ); ?></dt>
					<dd><code><?php echo esc_html( (string) $value ); ?></code></dd>
				<?php endforeach; ?>
			</dl>
			<?php $refunds = (array) $order->get_meta( OrderMeta::REFUNDS ); ?>
			<?php if ( array_filter( $refunds ) ) : ?>
				<p class="thawani-pay-box__sub"><?php esc_html_e( 'Refunds', 'thawani-pay-for-woocommerce' ); ?></p>
				<ul class="thawani-pay-box__refunds">
					<?php foreach ( $refunds as $refund ) : ?>
						<li><code><?php echo esc_html( (string) ( $refund['refund_id'] ?? '' ) ); ?></code> — <?php echo esc_html( Money::format_baisa( (int) ( $refund['amount'] ?? 0 ) ) ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<p><a class="button button-secondary" href="<?php echo esc_url( $sync_url ); ?>"><?php esc_html_e( 'Sync with Thawani', 'thawani-pay-for-woocommerce' ); ?></a></p>
		</div>
		<?php
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
