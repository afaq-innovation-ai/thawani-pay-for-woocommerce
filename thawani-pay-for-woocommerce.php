<?php
/**
 * Plugin Name:          Thawani Pay for WooCommerce
 * Plugin URI:           https://github.com/afaq-innovation-ai/thawani-pay-for-woocommerce
 * Description:          Accept debit and credit card payments in Omani Rial through Thawani Checkout — hosted checkout, saved cards, subscriptions, one-click refunds, signed webhooks and automatic payment reconciliation.
 * Version:              1.2.0
 * Requires at least:    6.2
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               Afaq Innovation and AI
 * Author URI:           https://github.com/afaq-innovation-ai
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          thawani-pay-for-woocommerce
 * Domain Path:          /languages
 * WC requires at least: 8.0
 * WC tested up to:      11.1
 *
 * @package AfaqInnovation\ThawaniPay
 */

defined( 'ABSPATH' ) || exit;

define( 'THAWANI_PAY_VERSION', '1.2.0' );
define( 'THAWANI_PAY_FILE', __FILE__ );
define( 'THAWANI_PAY_PATH', plugin_dir_path( __FILE__ ) );
define( 'THAWANI_PAY_URL', plugin_dir_url( __FILE__ ) );
define( 'THAWANI_PAY_BASENAME', plugin_basename( __FILE__ ) );

require_once THAWANI_PAY_PATH . 'src/autoload.php';

/*
 * Declare compatibility with WooCommerce High-Performance Order Storage and the Cart & Checkout blocks.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', THAWANI_PAY_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', THAWANI_PAY_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain( 'thawani-pay-for-woocommerce', false, dirname( THAWANI_PAY_BASENAME ) . '/languages' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Thawani Pay for WooCommerce requires WooCommerce to be installed and active.', 'thawani-pay-for-woocommerce' ) . '</p></div>';
				}
			);
			return;
		}

		\AfaqInnovation\ThawaniPay\Plugin::instance()->boot();
	},
	11
);

register_deactivation_hook(
	__FILE__,
	static function () {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), 'thawani-pay' );
		}
	}
);
