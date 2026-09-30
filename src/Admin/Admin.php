<?php
/**
 * Admin wiring: assets, connection test, notices and plugin links.
 *
 * @package AfaqInnovation\ThawaniPay
 */

namespace AfaqInnovation\ThawaniPay\Admin;

use AfaqInnovation\ThawaniPay\Api\ApiException;
use AfaqInnovation\ThawaniPay\Api\Client;
use AfaqInnovation\ThawaniPay\Gateway\Gateway;
use AfaqInnovation\ThawaniPay\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Admin-only behaviour.
 */
final class Admin {

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_thawani_pay_test_connection', array( __CLASS__, 'test_connection' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'plugin_action_links_' . THAWANI_PAY_BASENAME, array( __CLASS__, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );

		OrderMetaBox::init();
		TransactionsPage::init();
	}

	/**
	 * Settings URL.
	 */
	public static function settings_url(): string {
		return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . Gateway::ID );
	}

	/**
	 * Styles everywhere in wp-admin that shows Thawani UI; scripts on the settings page.
	 *
	 * @param string $hook Current screen hook.
	 */
	public static function assets( $hook ): void {
		wp_enqueue_style( 'thawani-pay-admin', THAWANI_PAY_URL . 'assets/css/admin.css', array(), THAWANI_PAY_VERSION );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
		if ( 'woocommerce_page_wc-settings' !== $hook || Gateway::ID !== $section ) {
			return;
		}

		wp_enqueue_script( 'thawani-pay-admin', THAWANI_PAY_URL . 'assets/js/admin.js', array(), THAWANI_PAY_VERSION, true );
		wp_localize_script(
			'thawani-pay-admin',
			'thawaniPayAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'thawani_pay_test_connection' ),
				'i18n'    => array(
					'testing' => __( 'Checking…', 'thawani-pay-for-woocommerce' ),
					'copied'  => __( 'Copied', 'thawani-pay-for-woocommerce' ),
					'failed'  => __( 'Request failed', 'thawani-pay-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * AJAX: validate keys against the API without saving.
	 */
	public static function test_connection(): void {
		check_ajax_referer( 'thawani_pay_test_connection', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'thawani-pay-for-woocommerce' ) ), 403 );
		}

		$mode        = isset( $_POST['mode'] ) && 'live' === $_POST['mode'] ? Settings::MODE_LIVE : Settings::MODE_TEST;
		$secret      = isset( $_POST['secret'] ) ? sanitize_text_field( wp_unslash( $_POST['secret'] ) ) : '';
		$publishable = isset( $_POST['publishable'] ) ? sanitize_text_field( wp_unslash( $_POST['publishable'] ) ) : '';

		if ( '' === $secret || '' === $publishable ) {
			wp_send_json_error( array( 'message' => __( 'Enter both the secret and the publishable key.', 'thawani-pay-for-woocommerce' ) ) );
		}

		$started = microtime( true );

		try {
			( new Client( $secret, $publishable, $mode ) )->list_sessions( 1, 0 );
		} catch ( ApiException $e ) {
			if ( ! $e->is_not_found() ) { // An empty account answers "not found", which still proves the key works.
				wp_send_json_error( array( 'message' => $e->is_auth_error() ? __( 'Key rejected by Thawani.', 'thawani-pay-for-woocommerce' ) : $e->getMessage() ) );
			}
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: environment, 2: response time in ms. */
					__( 'Connected to Thawani %1$s (%2$d ms).', 'thawani-pay-for-woocommerce' ),
					Settings::is_test( $mode ) ? __( 'sandbox', 'thawani-pay-for-woocommerce' ) : __( 'live', 'thawani-pay-for-woocommerce' ),
					(int) round( ( microtime( true ) - $started ) * 1000 )
				),
			)
		);
	}

	/**
	 * Configuration warnings.
	 */
	public static function notices(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! Settings::flag( 'enabled' ) ) {
			return;
		}

		$link = '<a href="' . esc_url( self::settings_url() ) . '">' . esc_html__( 'Thawani settings', 'thawani-pay-for-woocommerce' ) . '</a>';

		if ( 'OMR' !== get_woocommerce_currency() && ! has_filter( 'thawani_pay_supported_currencies' ) ) {
			/* translators: %s: currency code. */
			self::notice( 'warning', sprintf( esc_html__( 'Thawani Pay only accepts Omani Rial, but your store currency is %s. The payment method is hidden at checkout.', 'thawani-pay-for-woocommerce' ), '<code>' . esc_html( get_woocommerce_currency() ) . '</code>' ) );
		}

		if ( ! Settings::has_keys() ) {
			/* translators: %s: settings link. */
			self::notice( 'error', sprintf( esc_html__( 'Thawani Pay is enabled but the API keys for the active environment are missing. Open %s.', 'thawani-pay-for-woocommerce' ), $link ) );
		}

		if ( ! Settings::is_test() && ! is_ssl() ) {
			self::notice( 'error', esc_html__( 'Thawani Pay is in live mode but your store is not served over HTTPS. Thawani requires an SSL certificate in production.', 'thawani-pay-for-woocommerce' ) );
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( Settings::is_test() && $screen && in_array( $screen->id, array( 'woocommerce_page_wc-settings', 'woocommerce_page_wc-orders', 'edit-shop_order', 'dashboard' ), true ) ) {
			/* translators: %s: settings link. */
			self::notice( 'info', sprintf( esc_html__( 'Thawani Pay is in sandbox mode — orders are not charged. Switch to live in %s when you are ready.', 'thawani-pay-for-woocommerce' ), $link ) );
		}
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Links.
	 */
	public static function action_links( $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::settings_url() ) . '">' . esc_html__( 'Settings', 'thawani-pay-for-woocommerce' ) . '</a>' );

		return $links;
	}

	/**
	 * Extra links on the Plugins screen.
	 *
	 * @param array  $links Links.
	 * @param string $file  Plugin file.
	 */
	public static function row_meta( $links, $file ): array {
		if ( THAWANI_PAY_BASENAME === $file ) {
			$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=thawani-pay-transactions' ) ) . '">' . esc_html__( 'Transactions', 'thawani-pay-for-woocommerce' ) . '</a>';
			$links[] = '<a href="https://github.com/afaq-innovation-ai/thawani-pay-for-woocommerce#readme" target="_blank" rel="noopener">' . esc_html__( 'Documentation', 'thawani-pay-for-woocommerce' ) . '</a>';
		}

		return (array) $links;
	}

	/**
	 * Print a notice (message must already be escaped).
	 *
	 * @param string $type    notice type.
	 * @param string $message Escaped HTML.
	 */
	private static function notice( string $type, string $message ): void {
		printf( '<div class="notice notice-%1$s thawani-pay-notice"><p><strong>Thawani Pay:</strong> %2$s</p></div>', esc_attr( $type ), wp_kses_post( $message ) );
	}
}
