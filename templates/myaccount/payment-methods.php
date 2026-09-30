<?php
/**
 * My account → Payment methods, with Thawani cards shown as bank cards.
 *
 * Replaces woocommerce/templates/myaccount/payment-methods.php (unless the theme overrides it).
 * Cards of other gateways keep the standard WooCommerce table.
 *
 * @package AfaqInnovation\ThawaniPay
 */

use AfaqInnovation\ThawaniPay\Gateway\Gateway;
use AfaqInnovation\ThawaniPay\Tokens\TokenManager;

defined( 'ABSPATH' ) || exit;

$thawani_saved   = wc_get_customer_saved_methods_list( get_current_user_id() );
$thawani_cards   = array();
$thawani_others  = array();
$thawani_actions = array();

foreach ( $thawani_saved as $thawani_type => $thawani_methods ) {
	foreach ( $thawani_methods as $thawani_method ) {
		if ( Gateway::ID === ( $thawani_method['method']['gateway'] ?? '' ) && ! empty( $thawani_method['method']['token_id'] ) ) {
			$thawani_actions[ (int) $thawani_method['method']['token_id'] ] = $thawani_method['actions'];
		} else {
			$thawani_others[ $thawani_type ][] = $thawani_method;
		}
	}
}

foreach ( WC_Payment_Tokens::get_customer_tokens( get_current_user_id(), Gateway::ID ) as $thawani_token ) {
	if ( isset( $thawani_actions[ $thawani_token->get_id() ] ) ) {
		$thawani_cards[] = $thawani_token;
	}
}

$thawani_has_methods = (bool) $thawani_saved;

do_action( 'woocommerce_before_account_payment_methods', $thawani_has_methods );
?>

<?php if ( $thawani_cards ) : ?>
	<section class="tp-wallet" aria-labelledby="tp-wallet-title">
		<header class="tp-wallet__head">
			<h3 id="tp-wallet-title"><?php esc_html_e( 'Your saved cards', 'thawani-pay-for-woocommerce' ); ?></h3>
			<p><?php esc_html_e( 'Stored securely by Thawani. Give each card a name so you can recognise it at checkout.', 'thawani-pay-for-woocommerce' ); ?></p>
		</header>

		<div class="tp-wallet__grid">
			<?php foreach ( $thawani_cards as $thawani_token ) : ?>
				<?php
				$thawani_brand   = $thawani_token instanceof WC_Payment_Token_CC ? strtolower( (string) $thawani_token->get_card_type() ) : 'card';
				$thawani_brand   = in_array( $thawani_brand, array( 'visa', 'mastercard' ), true ) ? $thawani_brand : 'card';
				$thawani_name    = TokenManager::nickname( $thawani_token );
				$thawani_label   = TokenManager::brand_label( $thawani_token );
				$thawani_last4   = $thawani_token instanceof WC_Payment_Token_CC ? $thawani_token->get_last4() : '';
				$thawani_month   = $thawani_token instanceof WC_Payment_Token_CC ? $thawani_token->get_expiry_month() : '';
				$thawani_year    = $thawani_token instanceof WC_Payment_Token_CC ? $thawani_token->get_expiry_year() : '';
				$thawani_expired = $thawani_year && $thawani_month && strtotime( sprintf( '%04d-%02d-01 +1 month', (int) $thawani_year, (int) $thawani_month ) ) < time();
				$thawani_kind    = (string) $thawani_token->get_meta( 'funding' );
				$thawani_id      = $thawani_token->get_id();
				$thawani_acts    = $thawani_actions[ $thawani_id ];
				?>
				<div class="tp-wallet__item">
					<article class="tp-cc tp-cc--<?php echo esc_attr( $thawani_brand ); ?><?php echo $thawani_token->is_default() ? ' is-default' : ''; ?><?php echo $thawani_expired ? ' is-expired' : ''; ?>">
						<div class="tp-cc__top">
							<span class="tp-cc__name"><?php echo esc_html( '' !== $thawani_name ? $thawani_name : $thawani_label ); ?></span>
							<span class="tp-cc__brand" aria-label="<?php echo esc_attr( $thawani_label ); ?>">
								<?php if ( 'visa' === $thawani_brand ) : ?>
									<svg viewBox="0 0 64 22" width="58" height="20" direction="ltr" aria-hidden="true"><text x="0" y="19" text-anchor="start" direction="ltr" font-family="Arial Black,Arial,sans-serif" font-size="21" font-style="italic" font-weight="900" fill="#fff" letter-spacing=".5">VISA</text></svg>
								<?php elseif ( 'mastercard' === $thawani_brand ) : ?>
									<svg viewBox="0 0 46 28" width="46" height="28" aria-hidden="true"><circle cx="16" cy="14" r="12" fill="#eb001b"/><circle cx="30" cy="14" r="12" fill="#f79e1b"/><path d="M23 4.3a12 12 0 0 1 0 19.4 12 12 0 0 1 0-19.4z" fill="#ff5f00"/></svg>
								<?php else : ?>
									<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="#fff" stroke-width="1.8" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
								<?php endif; ?>
							</span>
						</div>
						<div class="tp-cc__chip" aria-hidden="true"><span></span></div>
						<div class="tp-cc__number" dir="ltr">
							<span>••••</span><span>••••</span><span>••••</span><span><?php echo esc_html( $thawani_last4 ); ?></span>
						</div>
						<div class="tp-cc__bottom">
							<div>
								<small><?php esc_html_e( 'Type', 'thawani-pay-for-woocommerce' ); ?></small>
								<b><?php echo esc_html( $thawani_kind ? ucfirst( strtolower( $thawani_kind ) ) : '—' ); ?></b>
							</div>
							<div>
								<small><?php esc_html_e( 'Expires', 'thawani-pay-for-woocommerce' ); ?></small>
								<b dir="ltr"><?php echo esc_html( $thawani_month . '/' . substr( (string) $thawani_year, -2 ) ); ?></b>
							</div>
							<?php if ( $thawani_expired ) : ?>
								<span class="tp-cc__badge tp-cc__badge--expired"><?php esc_html_e( 'Expired', 'thawani-pay-for-woocommerce' ); ?></span>
							<?php elseif ( $thawani_token->is_default() ) : ?>
								<span class="tp-cc__badge"><?php esc_html_e( 'Default', 'thawani-pay-for-woocommerce' ); ?></span>
							<?php endif; ?>
						</div>
					</article>

					<div class="tp-cc-actions">
						<button type="button" class="tp-cc-link" data-tp-rename="<?php echo esc_attr( (string) $thawani_id ); ?>">
							<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
							<?php esc_html_e( 'Rename', 'thawani-pay-for-woocommerce' ); ?>
						</button>
						<?php if ( ! empty( $thawani_acts['default'] ) ) : ?>
							<a class="tp-cc-link" href="<?php echo esc_url( $thawani_acts['default']['url'] ); ?>">
								<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/></svg>
								<?php esc_html_e( 'Make default', 'thawani-pay-for-woocommerce' ); ?>
							</a>
						<?php endif; ?>
						<?php if ( ! empty( $thawani_acts['delete'] ) ) : ?>
							<a class="tp-cc-link tp-cc-link--danger" href="<?php echo esc_url( $thawani_acts['delete']['url'] ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Remove this card? It will also be deleted at Thawani.', 'thawani-pay-for-woocommerce' ) ); ?>');">
								<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/></svg>
								<?php esc_html_e( 'Remove', 'thawani-pay-for-woocommerce' ); ?>
							</a>
						<?php endif; ?>
					</div>

					<form class="tp-rename" method="post" id="tp-rename-<?php echo esc_attr( (string) $thawani_id ); ?>" hidden>
						<label for="tp-rename-input-<?php echo esc_attr( (string) $thawani_id ); ?>"><?php esc_html_e( 'Card name', 'thawani-pay-for-woocommerce' ); ?></label>
						<div class="tp-rename__row">
							<input type="text" id="tp-rename-input-<?php echo esc_attr( (string) $thawani_id ); ?>" name="nickname" maxlength="30" value="<?php echo esc_attr( $thawani_name ); ?>" placeholder="<?php esc_attr_e( 'e.g. Salary card', 'thawani-pay-for-woocommerce' ); ?>" />
							<button type="submit" class="button tp-rename__save"><?php esc_html_e( 'Save', 'thawani-pay-for-woocommerce' ); ?></button>
							<button type="button" class="button tp-rename__cancel" data-tp-cancel="<?php echo esc_attr( (string) $thawani_id ); ?>"><?php esc_html_e( 'Cancel', 'thawani-pay-for-woocommerce' ); ?></button>
						</div>
						<input type="hidden" name="thawani_pay_rename_card" value="1" />
						<input type="hidden" name="token_id" value="<?php echo esc_attr( (string) $thawani_id ); ?>" />
						<?php wp_nonce_field( 'thawani_pay_rename_card_' . $thawani_id ); ?>
					</form>
				</div>
			<?php endforeach; ?>
		</div>
	</section>
	<script>
	( function () {
		document.querySelectorAll( '[data-tp-rename]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var form = document.getElementById( 'tp-rename-' + btn.getAttribute( 'data-tp-rename' ) );
				form.hidden = ! form.hidden;
				if ( ! form.hidden ) { form.querySelector( 'input[name="nickname"]' ).focus(); }
			} );
		} );
		document.querySelectorAll( '[data-tp-cancel]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				document.getElementById( 'tp-rename-' + btn.getAttribute( 'data-tp-cancel' ) ).hidden = true;
			} );
		} );
	} )();
	</script>
<?php endif; ?>

<?php if ( $thawani_others ) : ?>

	<table class="woocommerce-MyAccount-paymentMethods shop_table shop_table_responsive account-payment-methods-table">
		<thead>
			<tr>
				<?php foreach ( wc_get_account_payment_methods_columns() as $thawani_column_id => $thawani_column_name ) : ?>
					<th class="woocommerce-PaymentMethod woocommerce-PaymentMethod--<?php echo esc_attr( $thawani_column_id ); ?> payment-method-<?php echo esc_attr( $thawani_column_id ); ?>"><span class="nobr"><?php echo esc_html( $thawani_column_name ); ?></span></th>
				<?php endforeach; ?>
			</tr>
		</thead>
		<?php foreach ( $thawani_others as $thawani_methods ) : ?>
			<?php foreach ( $thawani_methods as $thawani_method ) : ?>
				<tr class="payment-method<?php echo ! empty( $thawani_method['is_default'] ) ? ' default-payment-method' : ''; ?>">
					<?php foreach ( wc_get_account_payment_methods_columns() as $thawani_column_id => $thawani_column_name ) : ?>
						<td class="woocommerce-PaymentMethod woocommerce-PaymentMethod--<?php echo esc_attr( $thawani_column_id ); ?> payment-method-<?php echo esc_attr( $thawani_column_id ); ?>" data-title="<?php echo esc_attr( $thawani_column_name ); ?>">
							<?php
							if ( has_action( 'woocommerce_account_payment_methods_column_' . $thawani_column_id ) ) {
								do_action( 'woocommerce_account_payment_methods_column_' . $thawani_column_id, $thawani_method );
							} elseif ( 'method' === $thawani_column_id ) {
								if ( ! empty( $thawani_method['method']['last4'] ) ) {
									/* translators: 1: credit card type 2: last 4 digits */
									printf( esc_html__( '%1$s ending in %2$s', 'woocommerce' ), esc_html( wc_get_credit_card_type_label( $thawani_method['method']['brand'] ) ), esc_html( $thawani_method['method']['last4'] ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Reuses WooCommerce's translation.
								} else {
									echo esc_html( wc_get_credit_card_type_label( $thawani_method['method']['brand'] ) );
								}
							} elseif ( 'expires' === $thawani_column_id ) {
								echo esc_html( $thawani_method['expires'] );
							} elseif ( 'actions' === $thawani_column_id ) {
								foreach ( $thawani_method['actions'] as $thawani_key => $thawani_action ) {
									echo '<a href="' . esc_url( $thawani_action['url'] ) . '" class="button ' . sanitize_html_class( $thawani_key ) . '">' . esc_html( $thawani_action['name'] ) . '</a>&nbsp;';
								}
							}
							?>
						</td>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
		<?php endforeach; ?>
	</table>

<?php elseif ( ! $thawani_cards ) : ?>

	<?php wc_print_notice( esc_html__( 'No saved methods found.', 'woocommerce' ), 'notice' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Reuses WooCommerce's translation. ?>

<?php endif; ?>

<?php do_action( 'woocommerce_after_account_payment_methods', $thawani_has_methods ); ?>

<?php
$thawani_can_add = false;
foreach ( WC()->payment_gateways->get_available_payment_gateways() as $thawani_gateway ) {
	if ( $thawani_gateway->supports( 'add_payment_method' ) ) {
		$thawani_can_add = true;
		break;
	}
}
?>
<?php if ( $thawani_can_add ) : ?>
	<a class="button" href="<?php echo esc_url( wc_get_endpoint_url( 'add-payment-method' ) ); ?>"><?php esc_html_e( 'Add payment method', 'woocommerce' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Reuses WooCommerce's translation. ?></a>
<?php endif; ?>
