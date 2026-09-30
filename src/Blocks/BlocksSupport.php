<?php
/**
 * Cart & Checkout blocks integration.
 *
 * @package AfaqInnovation\ThawaniPay
 */

namespace AfaqInnovation\ThawaniPay\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use AfaqInnovation\ThawaniPay\Gateway\Gateway;
use AfaqInnovation\ThawaniPay\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Thawani Pay with the block-based checkout (default since WooCommerce 8.3).
 */
final class BlocksSupport extends AbstractPaymentMethodType {

	/**
	 * Payment method name (must match the gateway id).
	 *
	 * @var string
	 */
	protected $name = Gateway::ID;

	/**
	 * Gateway instance.
	 *
	 * @var Gateway|null
	 */
	private $gateway;

	/**
	 * Load settings.
	 */
	public function initialize() {
		$this->settings = Settings::all();
		$gateways       = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		$this->gateway  = isset( $gateways[ Gateway::ID ] ) ? $gateways[ Gateway::ID ] : null;
	}

	/**
	 * Whether the method is offered.
	 */
	public function is_active() {
		return $this->gateway ? $this->gateway->is_available() : false;
	}

	/**
	 * Script handles.
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'thawani-pay-blocks',
			THAWANI_PAY_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			THAWANI_PAY_VERSION,
			true
		);
		wp_set_script_translations( 'thawani-pay-blocks', 'thawani-pay-for-woocommerce', THAWANI_PAY_PATH . 'languages' );
		wp_enqueue_style( 'thawani-pay', THAWANI_PAY_URL . 'assets/css/checkout.css', array(), THAWANI_PAY_VERSION );

		return array( 'thawani-pay-blocks' );
	}

	/**
	 * Data exposed to the client as `thawani_data`.
	 */
	public function get_payment_method_data() {
		$tokenization = $this->gateway && $this->gateway->supports( 'tokenization' );
		$icons        = array();

		if ( 'yes' === ( $this->settings['show_icons'] ?? 'yes' ) ) {
			$icons = array(
				array(
					'id'  => 'visa',
					'src' => THAWANI_PAY_URL . 'assets/images/visa.svg',
					'alt' => 'Visa',
				),
				array(
					'id'  => 'mastercard',
					'src' => THAWANI_PAY_URL . 'assets/images/mastercard.svg',
					'alt' => 'Mastercard',
				),
			);
		}

		return array(
			'title'          => $this->gateway ? $this->gateway->get_title() : __( 'Thawani Pay', 'thawani-pay-for-woocommerce' ),
			/** This filter is documented in src/Gateway/Gateway.php */
			'description'    => (string) apply_filters( 'thawani_pay_checkout_description', $this->gateway ? $this->gateway->get_description() : '' ),
			'icons'          => $icons,
			'testMode'       => Settings::is_test(),
			'supports'       => $this->gateway ? array_values( array_filter( $this->gateway->supports, array( $this->gateway, 'supports' ) ) ) : array( 'products' ),
			'showSavedCards' => $tokenization && is_user_logged_in(),
			'cards'          => $tokenization ? (object) \AfaqInnovation\ThawaniPay\Tokens\TokenManager::cards_for_current_user() : new \stdClass(),
			/** This filter is documented in src/Gateway/Gateway.php */
			'showSaveOption' => $tokenization && is_user_logged_in() && (bool) apply_filters( 'thawani_pay_show_save_option', true ),
		);
	}
}
