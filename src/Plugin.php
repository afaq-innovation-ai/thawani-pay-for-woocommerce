<?php
/**
 * Plugin bootstrap.
 *
 * @package AfaqInnovation\ThawaniPay
 */

namespace AfaqInnovation\ThawaniPay;

use AfaqInnovation\ThawaniPay\Admin\Admin;
use AfaqInnovation\ThawaniPay\Blocks\BlocksSupport;
use AfaqInnovation\ThawaniPay\Cron\Reconciler;
use AfaqInnovation\ThawaniPay\Gateway\Gateway;
use AfaqInnovation\ThawaniPay\Gateway\ReturnHandler;
use AfaqInnovation\ThawaniPay\Gateway\ThankYou;
use AfaqInnovation\ThawaniPay\Subscriptions\WpsSubscriptions;
use AfaqInnovation\ThawaniPay\Tokens\TokenManager;
use AfaqInnovation\ThawaniPay\Webhooks\WebhookController;

defined( 'ABSPATH' ) || exit;

/**
 * Wires every component into WordPress.
 */
final class Plugin {

	/**
	 * Singleton.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Booted flag.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( $this, 'register_blocks' ) );
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'hide_on_add_payment_method' ) );

		ReturnHandler::init();
		WebhookController::init();
		Reconciler::init();
		TokenManager::init();
		ThankYou::init();
		WpsSubscriptions::init();

		if ( is_admin() ) {
			Admin::init();
		}
	}

	/**
	 * Add the gateway to WooCommerce.
	 *
	 * @param array $gateways Gateway classes.
	 */
	public function register_gateway( $gateways ): array {
		$gateways[] = Gateway::class;

		return (array) $gateways;
	}

	/**
	 * Thawani only saves cards during a real payment, so the gateway is not offered on
	 * "My account → Add payment method" (WooCommerce then explains cards are added at checkout).
	 *
	 * @param array $gateways Available gateways.
	 */
	public function hide_on_add_payment_method( $gateways ) {
		if ( function_exists( 'is_add_payment_method_page' ) && is_add_payment_method_page() ) {
			unset( $gateways[ Gateway::ID ] );
		}

		return $gateways;
	}

	/**
	 * Register the blocks integration.
	 *
	 * @param \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry Registry.
	 */
	public function register_blocks( $registry ): void {
		if ( class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
			$registry->register( new BlocksSupport() );
		}
	}
}
