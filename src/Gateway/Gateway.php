<?php
/**
 * WooCommerce payment gateway.
 *
 * @package AfaqInnovation\ThawaniPay
 */

namespace AfaqInnovation\ThawaniPay\Gateway;

use AfaqInnovation\ThawaniPay\Api\ApiException;
use AfaqInnovation\ThawaniPay\Api\Client;
use AfaqInnovation\ThawaniPay\Support\LineItems;
use AfaqInnovation\ThawaniPay\Support\Logger;
use AfaqInnovation\ThawaniPay\Support\Money;
use AfaqInnovation\ThawaniPay\Support\Settings;
use AfaqInnovation\ThawaniPay\Webhooks\WebhookController;

defined( 'ABSPATH' ) || exit;

/**
 * Thawani Pay gateway.
 */
class Gateway extends \WC_Payment_Gateway {

	const ID = 'thawani';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->method_title       = __( 'Thawani Pay', 'thawani-pay-for-woocommerce' );
		$this->method_description = __( 'Accept Omani debit and credit cards through the secure Thawani hosted checkout. Supports saved cards, refunds from the order screen, signed webhooks and automatic reconciliation.', 'thawani-pay-for-woocommerce' );
		$this->has_fields         = true;
		$this->icon               = THAWANI_PAY_URL . 'assets/images/thawani-pay.svg';

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = (string) $this->get_option( 'title' );
		$this->description = (string) $this->get_option( 'description' );
		$this->enabled     = (string) $this->get_option( 'enabled', 'no' );

		$this->supports = array( 'products', 'refunds' );
		if ( 'yes' === $this->get_option( 'saved_cards', 'yes' ) ) {
			$this->supports[] = 'tokenization';
		}

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Settings form.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'intro'                => array(
				'type' => 'thawani_intro',
			),

			'section_general'      => array(
				'title' => __( 'General', 'thawani-pay-for-woocommerce' ),
				'type'  => 'title',
			),
			'enabled'              => array(
				'title'   => __( 'Enable / Disable', 'thawani-pay-for-woocommerce' ),
				'label'   => __( 'Enable Thawani Pay at checkout', 'thawani-pay-for-woocommerce' ),
				'type'    => 'checkbox',
				'default' => 'no',
			),
			'title'                => array(
				'title'       => __( 'Title', 'thawani-pay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Payment method name shown to customers at checkout.', 'thawani-pay-for-woocommerce' ),
				'default'     => __( 'Debit / Credit Card (Thawani)', 'thawani-pay-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'          => array(
				'title'       => __( 'Description', 'thawani-pay-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Thawani requires the checkout to state that card payments are accepted.', 'thawani-pay-for-woocommerce' ),
				'default'     => __( 'Pay securely with your debit or credit card. You will be redirected to Thawani to complete the payment.', 'thawani-pay-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'show_icons'           => array(
				'title'   => __( 'Card logos', 'thawani-pay-for-woocommerce' ),
				'label'   => __( 'Show Visa and Mastercard logos next to the title', 'thawani-pay-for-woocommerce' ),
				'type'    => 'checkbox',
				'default' => 'yes',
			),

			'section_api'          => array(
				'title'       => __( 'API credentials', 'thawani-pay-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Generate your keys in the Thawani merchant portal under Integration Keys. The sandbox keys below are the public test keys from the Thawani documentation.', 'thawani-pay-for-woocommerce' ),
			),
			'testmode'             => array(
				'title'       => __( 'Sandbox mode', 'thawani-pay-for-woocommerce' ),
				'label'       => __( 'Use the Thawani UAT sandbox (no real money is charged)', 'thawani-pay-for-woocommerce' ),
				'type'        => 'checkbox',
				'default'     => 'yes',
				'description' => __( 'Turn this off only after Thawani has approved your integration and issued production keys.', 'thawani-pay-for-woocommerce' ),
			),
			'test_secret_key'      => array(
				'title'   => __( 'Sandbox secret key', 'thawani-pay-for-woocommerce' ),
				'type'    => 'password',
				'default' => Settings::SANDBOX_SECRET_KEY,
				'class'   => 'thawani-key thawani-key--test',
			),
			'test_publishable_key' => array(
				'title'   => __( 'Sandbox publishable key', 'thawani-pay-for-woocommerce' ),
				'type'    => 'text',
				'default' => Settings::SANDBOX_PUBLISHABLE_KEY,
				'class'   => 'thawani-key thawani-key--test',
			),
			'live_secret_key'      => array(
				'title'   => __( 'Live secret key', 'thawani-pay-for-woocommerce' ),
				'type'    => 'password',
				'default' => '',
				'class'   => 'thawani-key thawani-key--live',
			),
			'live_publishable_key' => array(
				'title'   => __( 'Live publishable key', 'thawani-pay-for-woocommerce' ),
				'type'    => 'text',
				'default' => '',
				'class'   => 'thawani-key thawani-key--live',
			),
			'connection'           => array(
				'title' => __( 'Connection', 'thawani-pay-for-woocommerce' ),
				'type'  => 'thawani_connection',
			),

			'section_webhooks'     => array(
				'title'       => __( 'Webhooks', 'thawani-pay-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Webhooks let Thawani notify your store instantly, even if the customer closes the browser before returning. Paste this URL in the merchant portal and copy the webhook secret back here.', 'thawani-pay-for-woocommerce' ),
			),
			'webhook_url'          => array(
				'title' => __( 'Webhook URL', 'thawani-pay-for-woocommerce' ),
				'type'  => 'thawani_webhook_url',
			),
			'test_webhook_secret'  => array(
				'title'       => __( 'Sandbox webhook secret', 'thawani-pay-for-woocommerce' ),
				'type'        => 'password',
				'default'     => '',
				'description' => __( 'Used to verify the thawani-signature header.', 'thawani-pay-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'live_webhook_secret'  => array(
				'title'       => __( 'Live webhook secret', 'thawani-pay-for-woocommerce' ),
				'type'        => 'password',
				'default'     => '',
				'description' => __( 'Used to verify the thawani-signature header.', 'thawani-pay-for-woocommerce' ),
				'desc_tip'    => true,
			),

			'section_checkout'     => array(
				'title' => __( 'Checkout experience', 'thawani-pay-for-woocommerce' ),
				'type'  => 'title',
			),
			'line_items'           => array(
				'title'       => __( 'Order summary on Thawani', 'thawani-pay-for-woocommerce' ),
				'type'        => 'select',
				'default'     => LineItems::MODE_ITEMIZED,
				'options'     => array(
					LineItems::MODE_ITEMIZED => __( 'Itemised — show every product, shipping and fee', 'thawani-pay-for-woocommerce' ),
					LineItems::MODE_SINGLE   => __( 'Single line — "Order #123" with the total', 'thawani-pay-for-woocommerce' ),
				),
				'description' => __( 'Itemised mode falls back to a single line automatically whenever the lines cannot add up to the exact total (e.g. fractional baisa or negative fees).', 'thawani-pay-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'saved_cards'          => array(
				'title'       => __( 'Saved cards', 'thawani-pay-for-woocommerce' ),
				'label'       => __( 'Let logged-in customers save cards at Thawani and pay with them next time', 'thawani-pay-for-woocommerce' ),
				'type'        => 'checkbox',
				'default'     => 'yes',
				'description' => __( 'Card details are stored by Thawani, never on your server.', 'thawani-pay-for-woocommerce' ),
			),
			'session_expiry'       => array(
				'title'             => __( 'Payment link lifetime', 'thawani-pay-for-woocommerce' ),
				'type'              => 'number',
				'default'           => 60,
				'description'       => __( 'Minutes before an unpaid Thawani checkout session expires (30 – 10080).', 'thawani-pay-for-woocommerce' ),
				'custom_attributes' => array(
					'min'  => 30,
					'max'  => 10080,
					'step' => 1,
				),
				'desc_tip'          => true,
			),
			'reference_prefix'     => array(
				'title'       => __( 'Reference prefix', 'thawani-pay-for-woocommerce' ),
				'type'        => 'text',
				'default'     => 'wc',
				'description' => __( 'Prepended to every client_reference_id. Use a different prefix per store if several stores share one Thawani account.', 'thawani-pay-for-woocommerce' ),
				'desc_tip'    => true,
			),

			'section_advanced'     => array(
				'title' => __( 'Advanced', 'thawani-pay-for-woocommerce' ),
				'type'  => 'title',
			),
			'debug'                => array(
				'title'       => __( 'Debug log', 'thawani-pay-for-woocommerce' ),
				'label'       => __( 'Log API requests and responses', 'thawani-pay-for-woocommerce' ),
				'type'        => 'checkbox',
				'default'     => 'no',
				/* translators: %s: link to the logs screen. */
				'description' => sprintf( __( 'Errors are always logged. View logs under %s (source: thawani-pay). Keys, emails and phone numbers are masked.', 'thawani-pay-for-woocommerce' ), '<a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=logs&source=thawani-pay' ) ) . '">' . esc_html__( 'WooCommerce → Status → Logs', 'thawani-pay-for-woocommerce' ) . '</a>' ),
			),
			'delete_data'          => array(
				'title'   => __( 'Uninstall', 'thawani-pay-for-woocommerce' ),
				'label'   => __( 'Delete plugin settings and saved-card references when the plugin is deleted', 'thawani-pay-for-woocommerce' ),
				'type'    => 'checkbox',
				'default' => 'no',
			),
		);
	}

	/**
	 * Available only for OMR orders with keys configured.
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}

		/**
		 * Filter the currencies Thawani Pay may be offered for. Thawani settles in OMR only.
		 *
		 * @param string[] $currencies Currency codes.
		 */
		$currencies = (array) apply_filters( 'thawani_pay_supported_currencies', array( Money::CURRENCY ) );

		return in_array( get_woocommerce_currency(), $currencies, true ) && Settings::has_keys();
	}

	/**
	 * Card logos after the title.
	 */
	public function get_icon() {
		if ( 'yes' !== $this->get_option( 'show_icons', 'yes' ) ) {
			return '';
		}

		$html = '<span class="thawani-pay-icons">';
		foreach ( array(
			'visa'       => 'Visa',
			'mastercard' => 'Mastercard',
		) as $file => $alt ) {
			$html .= '<img src="' . esc_url( THAWANI_PAY_URL . 'assets/images/' . $file . '.svg' ) . '" alt="' . esc_attr( $alt ) . '" width="38" height="24" />';
		}
		$html .= '</span>';

		return apply_filters( 'woocommerce_gateway_icon', $html, $this->id );
	}

	/**
	 * Description, sandbox notice and saved cards on the classic checkout.
	 */
	public function payment_fields() {
		if ( Settings::is_test() ) {
			echo '<p class="thawani-pay-test-notice">' . esc_html__( 'Sandbox mode — use test card 4242 4242 4242 4242, any future expiry, any CVV and OTP 1234.', 'thawani-pay-for-woocommerce' ) . '</p>';
		}

		/**
		 * Filter the description shown under the payment method (classic and block checkout).
		 *
		 * @param string $description Description.
		 */
		$description = (string) apply_filters( 'thawani_pay_checkout_description', $this->description );
		if ( $description ) {
			echo wp_kses_post( wpautop( wptexturize( $description ) ) );
		}

		if ( $this->supports( 'tokenization' ) && is_checkout() && is_user_logged_in() ) {
			$this->tokenization_script();
			$this->saved_payment_methods();

			/**
			 * Whether to show the "save my card" checkbox (hidden when the card is saved anyway, e.g. for subscriptions).
			 *
			 * @param bool $show Show the checkbox.
			 */
			if ( apply_filters( 'thawani_pay_show_save_option', true ) ) {
				$this->save_payment_method_checkbox();
			}
		}
	}

	/**
	 * Save-card checkbox label.
	 */
	public function save_payment_method_checkbox() {
		printf(
			'<p class="form-row woocommerce-SavedPaymentMethods-saveNew">
				<input id="wc-%1$s-new-payment-method" name="wc-%1$s-new-payment-method" type="checkbox" value="true" style="width:auto;" />
				<label for="wc-%1$s-new-payment-method" style="display:inline;">%2$s</label>
			</p>',
			esc_attr( $this->id ),
			esc_html__( 'Save my card securely with Thawani for faster checkout', 'thawani-pay-for-woocommerce' )
		);
	}

	/**
	 * Start the payment.
	 *
	 * @param int $order_id Order id.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return array( 'result' => 'failure' );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before calling process_payment().
		$token_id  = isset( $_POST[ 'wc-' . $this->id . '-payment-token' ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'wc-' . $this->id . '-payment-token' ] ) ) : '';
		$save_card = ! empty( $_POST[ 'wc-' . $this->id . '-new-payment-method' ] ) && 'false' !== $_POST[ 'wc-' . $this->id . '-new-payment-method' ];
		// phpcs:enable

		/**
		 * Force saving the card at Thawani for this order (e.g. it starts a subscription that renews later).
		 *
		 * @param bool      $save_card Customer's choice.
		 * @param \WC_Order $order     Order.
		 */
		$save_card = (bool) apply_filters( 'thawani_pay_force_save_card', $save_card, $order );

		try {
			if ( $token_id && 'new' !== $token_id && $this->supports( 'tokenization' ) ) {
				$token = \WC_Payment_Tokens::get( absint( $token_id ) );
				if ( ! $token || (int) $token->get_user_id() !== get_current_user_id() || self::ID !== $token->get_gateway_id() ) {
					wc_add_notice( __( 'That saved card is not available. Please choose another payment method.', 'thawani-pay-for-woocommerce' ), 'error' );
					return array( 'result' => 'failure' );
				}

				$result = CheckoutService::pay_with_token( $order, $token );
				if ( 'paid' === $result['status'] && WC()->cart ) {
					WC()->cart->empty_cart();
				}

				return array(
					'result'   => 'success',
					'redirect' => $result['redirect'],
				);
			}

			return array(
				'result'   => 'success',
				'redirect' => CheckoutService::start_session( $order, $save_card ),
			);
		} catch ( ApiException $e ) {
			Logger::error(
				sprintf( 'Order #%d: payment could not be started.', $order->get_id() ),
				array(
					'error' => $e->getMessage(),
					'code'  => $e->api_code(),
				)
			);
			$order->add_order_note(
				/* translators: %s: error message from Thawani. */
				sprintf( __( 'Thawani could not start the payment: %s', 'thawani-pay-for-woocommerce' ), $e->getMessage() )
			);
			wc_add_notice( $e->customer_message(), 'error' );

			return array( 'result' => 'failure' );
		}
	}

	/**
	 * Refund through the Thawani Refunds API (full or partial).
	 *
	 * @param int        $order_id Order id.
	 * @param float|null $amount   Amount.
	 * @param string     $reason   Reason.
	 * @return bool|\WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'thawani_refund', __( 'Order not found.', 'thawani-pay-for-woocommerce' ) );
		}

		$payment_id = (string) $order->get_meta( OrderMeta::PAYMENT_ID );
		if ( '' === $payment_id ) {
			PaymentSync::sync( $order );
			$order      = wc_get_order( $order_id );
			$payment_id = (string) $order->get_meta( OrderMeta::PAYMENT_ID );
		}

		if ( '' === $payment_id ) {
			return new \WP_Error( 'thawani_refund', __( 'This order has no Thawani payment ID, so it cannot be refunded automatically.', 'thawani-pay-for-woocommerce' ) );
		}

		$baisa = Money::to_baisa( null === $amount ? $order->get_remaining_refund_amount() : $amount );
		if ( $baisa < 1 ) {
			return new \WP_Error( 'thawani_refund', __( 'Refund amount must be greater than zero.', 'thawani-pay-for-woocommerce' ) );
		}

		$reason = trim( (string) $reason );

		try {
			$refund = Client::for_mode( OrderMeta::mode( $order ) )->create_refund(
				array(
					'payment_id' => $payment_id,
					/* translators: %s: order number. */
					'reason'     => '' !== $reason ? mb_substr( $reason, 0, 250 ) : sprintf( __( 'Refund for order #%s', 'thawani-pay-for-woocommerce' ), $order->get_order_number() ),
					'amount'     => $baisa,
					'metadata'   => array(
						'order_id'    => (string) $order->get_id(),
						'refunded_by' => (string) wp_get_current_user()->user_login,
						'store'       => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
					),
				)
			);
		} catch ( ApiException $e ) {
			$message = ApiException::ALREADY_REFUNDED === $e->api_code()
				? __( 'Thawani reports this payment is already fully refunded.', 'thawani-pay-for-woocommerce' )
				: $e->getMessage();

			/* translators: %s: error message. */
			$order->add_order_note( sprintf( __( 'Thawani refund failed: %s', 'thawani-pay-for-woocommerce' ), $message ) );

			return new \WP_Error( 'thawani_refund', $message );
		}

		$status = strtolower( (string) ( $refund['status'] ?? '' ) );
		if ( 'failed' === $status ) {
			/* translators: %s: refund id. */
			$message = sprintf( __( 'Thawani declined the refund (%s).', 'thawani-pay-for-woocommerce' ), (string) ( $refund['refund_id'] ?? '' ) );
			$order->add_order_note( $message );
			return new \WP_Error( 'thawani_refund', $message );
		}

		$log   = (array) $order->get_meta( OrderMeta::REFUNDS );
		$log[] = array(
			'refund_id' => (string) ( $refund['refund_id'] ?? '' ),
			'amount'    => $baisa,
			'status'    => $status,
			'date'      => gmdate( 'c' ),
		);
		$order->update_meta_data( OrderMeta::REFUNDS, $log );
		$order->add_order_note(
			sprintf(
				/* translators: 1: amount, 2: refund id, 3: status. */
				__( 'Refunded %1$s through Thawani. Refund ID: %2$s (%3$s).', 'thawani-pay-for-woocommerce' ),
				Money::format_baisa( $baisa ),
				(string) ( $refund['refund_id'] ?? '—' ),
				$status ? $status : 'submitted'
			)
		);
		$order->save();

		do_action( 'thawani_pay_refund_created', $order, $refund, $baisa );

		return true;
	}

	/**
	 * Validate settings on save.
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();

		$expiry = (int) $this->get_option( 'session_expiry', 60 );
		$this->update_option( 'session_expiry', (string) max( 30, min( 10080, $expiry ? $expiry : 60 ) ) );
		$this->update_option( 'reference_prefix', (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $this->get_option( 'reference_prefix', 'wc' ) ) );

		return $saved;
	}

	// Custom settings field renderers.

	/**
	 * Intro card at the top of the settings page.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field data.
	 */
	public function generate_thawani_intro_html( $key, $data ) {
		$mode_label = Settings::is_test() ? __( 'Sandbox', 'thawani-pay-for-woocommerce' ) : __( 'Live', 'thawani-pay-for-woocommerce' );

		ob_start();
		?>
		<tr valign="top"><td colspan="2" class="thawani-pay-intro-cell">
			<div class="thawani-pay-intro">
				<img class="thawani-pay-intro__logo" src="<?php echo esc_url( THAWANI_PAY_URL . 'assets/images/thawani-pay.svg' ); ?>" alt="" width="48" height="48" />
				<div>
					<h3><?php esc_html_e( 'Thawani Pay for WooCommerce', 'thawani-pay-for-woocommerce' ); ?>
						<span class="thawani-pay-badge thawani-pay-badge--<?php echo esc_attr( Settings::mode() ); ?>"><?php echo esc_html( $mode_label ); ?></span>
					</h3>
					<p><?php esc_html_e( 'Hosted card checkout for Oman — saved cards, refunds, webhooks and automatic reconciliation.', 'thawani-pay-for-woocommerce' ); ?></p>
					<p class="thawani-pay-intro__links">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=thawani-pay-transactions' ) ); ?>"><?php esc_html_e( 'View transactions', 'thawani-pay-for-woocommerce' ); ?></a>
						<span aria-hidden="true">·</span>
						<a href="https://thawani-technologies.stoplight.io/docs/thawani-ecommerce-api" target="_blank" rel="noopener"><?php esc_html_e( 'Thawani API docs', 'thawani-pay-for-woocommerce' ); ?></a>
						<span aria-hidden="true">·</span>
						<span class="thawani-pay-intro__by"><?php esc_html_e( 'Developed by Afaq Innovation and AI', 'thawani-pay-for-woocommerce' ); ?></span>
					</p>
				</div>
			</div>
		</td></tr>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * "Test connection" button.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field data.
	 */
	public function generate_thawani_connection_html( $key, $data ) {
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $data['title'] ); ?></th>
			<td class="forminp">
				<button type="button" class="button thawani-pay-test" data-mode="test"><?php esc_html_e( 'Test sandbox keys', 'thawani-pay-for-woocommerce' ); ?></button>
				<button type="button" class="button thawani-pay-test" data-mode="live"><?php esc_html_e( 'Test live keys', 'thawani-pay-for-woocommerce' ); ?></button>
				<span class="thawani-pay-test-result" role="status" aria-live="polite"></span>
				<p class="description"><?php esc_html_e( 'Checks the keys currently typed in the form against the Thawani API — no need to save first.', 'thawani-pay-for-woocommerce' ); ?></p>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Read-only webhook URL with copy button.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field data.
	 */
	public function generate_thawani_webhook_url_html( $key, $data ) {
		$url = WebhookController::url();

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $data['title'] ); ?></th>
			<td class="forminp">
				<div class="thawani-pay-copy">
					<input type="text" readonly value="<?php echo esc_attr( $url ); ?>" class="input-text regular-input code" id="thawani-pay-webhook-url" />
					<button type="button" class="button thawani-pay-copy__btn" data-target="thawani-pay-webhook-url"><?php esc_html_e( 'Copy', 'thawani-pay-for-woocommerce' ); ?></button>
				</div>
				<?php if ( 0 !== strpos( $url, 'https://' ) ) : ?>
					<p class="description thawani-pay-warn"><?php esc_html_e( 'Thawani requires HTTPS in production. This URL is not reachable from the internet while you develop locally — payments are still confirmed on return and by the background reconciler.', 'thawani-pay-for-woocommerce' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}
}
