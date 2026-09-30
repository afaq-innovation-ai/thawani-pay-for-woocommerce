<?php
/**
 * WooCommerce → Thawani Pay (transactions).
 *
 * @package AfaqInnovation\ThawaniPay
 */

namespace AfaqInnovation\ThawaniPay\Admin;

use AfaqInnovation\ThawaniPay\Api\ApiException;
use AfaqInnovation\ThawaniPay\Api\Client;
use AfaqInnovation\ThawaniPay\Gateway\OrderMeta;
use AfaqInnovation\ThawaniPay\Support\Money;
use AfaqInnovation\ThawaniPay\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Live view of checkout sessions straight from the Thawani API, linked to WooCommerce orders.
 */
final class TransactionsPage {

	const SLUG       = 'thawani-pay-transactions';
	const PER_PAGE   = 20;
	const SCAN_LIMIT = 200;

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
	}

	/**
	 * Submenu under WooCommerce.
	 */
	public static function menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Thawani Transactions', 'thawani-pay-for-woocommerce' ),
			__( 'Thawani Pay', 'thawani-pay-for-woocommerce' ),
			'manage_woocommerce',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Page URL.
	 *
	 * @param array $args Query args.
	 */
	private static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	/**
	 * Page.
	 */
	public static function render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only view.
		$mode  = isset( $_GET['mode'] ) && 'live' === $_GET['mode'] ? Settings::MODE_LIVE : ( isset( $_GET['mode'] ) ? Settings::MODE_TEST : Settings::mode() );
		$page  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$scope = isset( $_GET['scope'] ) && 'all' === $_GET['scope'] ? 'all' : 'store';
		// phpcs:enable

		$sessions = array();
		$error    = '';

		if ( ! Settings::has_keys( $mode ) ) {
			$error = __( 'API keys for this environment are not configured.', 'thawani-pay-for-woocommerce' );
		} else {
			try {
				$client   = Client::for_mode( $mode );
				$sessions = 'all' === $scope
					? $client->list_sessions( self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE )
					: self::store_sessions( $client );
			} catch ( ApiException $e ) {
				$error = $e->is_not_found() ? '' : $e->getMessage();
			}
		}

		$link  = static function ( string $link_mode, string $link_scope, int $paged = 1 ): string {
			$args = array(
				'mode'  => $link_mode,
				'scope' => $link_scope,
			);
			if ( $paged > 1 ) {
				$args['paged'] = $paged;
			}
			return self::url( $args );
		};
		$newer = 'all' === $scope && $page > 1 ? $link( $mode, $scope, $page - 1 ) : '';
		$older = 'all' === $scope && count( $sessions ) >= self::PER_PAGE ? $link( $mode, $scope, $page + 1 ) : '';

		$kpi = array(
			'volume'    => 0,
			'paid'      => 0,
			'unpaid'    => 0,
			'cancelled' => 0,
		);
		foreach ( $sessions as $session ) {
			$status = is_array( $session ) ? strtolower( (string) ( $session['payment_status'] ?? '' ) ) : '';
			if ( isset( $kpi[ $status ] ) ) {
				++$kpi[ $status ];
			}
			if ( 'paid' === $status ) {
				$kpi['volume'] += (int) ( $session['total_amount'] ?? 0 );
			}
		}
		?>
		<div class="wrap tp-wrap">
			<h1 class="screen-reader-text"><?php esc_html_e( 'Thawani Transactions', 'thawani-pay-for-woocommerce' ); ?></h1>
			<header class="tp-hero tp-hero--compact">
				<div class="tp-hero__brand">
					<img src="<?php echo esc_url( THAWANI_PAY_URL . 'assets/images/thawani-pay.svg' ); ?>" alt="" width="44" height="44" />
					<div>
						<h2 class="tp-hero__title"><?php esc_html_e( 'Transactions', 'thawani-pay-for-woocommerce' ); ?></h2>
						<p class="tp-hero__sub"><?php esc_html_e( 'Live checkout sessions from the Thawani API, linked to your orders.', 'thawani-pay-for-woocommerce' ); ?></p>
					</div>
				</div>
				<div class="tp-hero__actions">
					<div class="tp-segment" role="tablist">
						<a role="tab" class="<?php echo Settings::MODE_TEST === $mode ? 'is-active' : ''; ?>" href="<?php echo esc_url( $link( 'test', $scope ) ); ?>"><?php esc_html_e( 'Sandbox', 'thawani-pay-for-woocommerce' ); ?></a>
						<a role="tab" class="<?php echo Settings::MODE_LIVE === $mode ? 'is-active' : ''; ?>" href="<?php echo esc_url( $link( 'live', $scope ) ); ?>"><?php esc_html_e( 'Live', 'thawani-pay-for-woocommerce' ); ?></a>
					</div>
					<a class="tp-btn tp-btn--ghost" href="<?php echo esc_url( Admin::settings_url() ); ?>"><?php esc_html_e( 'Settings', 'thawani-pay-for-woocommerce' ); ?></a>
				</div>
			</header>

			<div class="tp-kpis">
				<div class="tp-kpi tp-kpi--brand">
					<span class="tp-kpi__label"><?php esc_html_e( 'Collected', 'thawani-pay-for-woocommerce' ); ?></span>
					<strong class="tp-kpi__value"><?php echo esc_html( number_format( Money::from_baisa( $kpi['volume'] ), 3 ) ); ?> <small>OMR</small></strong>
					<span class="tp-kpi__hint"><?php esc_html_e( 'Paid sessions in this view', 'thawani-pay-for-woocommerce' ); ?></span>
				</div>
				<div class="tp-kpi">
					<span class="tp-kpi__label"><?php esc_html_e( 'Paid', 'thawani-pay-for-woocommerce' ); ?></span>
					<strong class="tp-kpi__value"><?php echo esc_html( number_format_i18n( $kpi['paid'] ) ); ?></strong>
					<span class="tp-kpi__hint tp-dot tp-dot--paid"><?php esc_html_e( 'Completed payments', 'thawani-pay-for-woocommerce' ); ?></span>
				</div>
				<div class="tp-kpi">
					<span class="tp-kpi__label"><?php esc_html_e( 'Awaiting payment', 'thawani-pay-for-woocommerce' ); ?></span>
					<strong class="tp-kpi__value"><?php echo esc_html( number_format_i18n( $kpi['unpaid'] ) ); ?></strong>
					<span class="tp-kpi__hint tp-dot tp-dot--unpaid"><?php esc_html_e( 'Open payment links', 'thawani-pay-for-woocommerce' ); ?></span>
				</div>
				<div class="tp-kpi">
					<span class="tp-kpi__label"><?php esc_html_e( 'Cancelled', 'thawani-pay-for-woocommerce' ); ?></span>
					<strong class="tp-kpi__value"><?php echo esc_html( number_format_i18n( $kpi['cancelled'] ) ); ?></strong>
					<span class="tp-kpi__hint tp-dot tp-dot--cancelled"><?php esc_html_e( 'Cancelled or expired', 'thawani-pay-for-woocommerce' ); ?></span>
				</div>
			</div>

			<section class="tp-card tp-card--table">
				<div class="tp-toolbar">
					<div class="tp-chips" role="group" aria-label="<?php esc_attr_e( 'Filter by status', 'thawani-pay-for-woocommerce' ); ?>">
						<button type="button" class="tp-chip is-active" data-filter=""><?php esc_html_e( 'All', 'thawani-pay-for-woocommerce' ); ?> <span><?php echo esc_html( (string) count( $sessions ) ); ?></span></button>
						<button type="button" class="tp-chip" data-filter="paid"><?php esc_html_e( 'Paid', 'thawani-pay-for-woocommerce' ); ?> <span><?php echo esc_html( (string) $kpi['paid'] ); ?></span></button>
						<button type="button" class="tp-chip" data-filter="unpaid"><?php esc_html_e( 'Unpaid', 'thawani-pay-for-woocommerce' ); ?> <span><?php echo esc_html( (string) $kpi['unpaid'] ); ?></span></button>
						<button type="button" class="tp-chip" data-filter="cancelled"><?php esc_html_e( 'Cancelled', 'thawani-pay-for-woocommerce' ); ?> <span><?php echo esc_html( (string) $kpi['cancelled'] ); ?></span></button>
					</div>
					<div class="tp-toolbar__right">
						<input type="search" class="tp-search" placeholder="<?php esc_attr_e( 'Search order, customer or invoice…', 'thawani-pay-for-woocommerce' ); ?>" aria-label="<?php esc_attr_e( 'Search transactions', 'thawani-pay-for-woocommerce' ); ?>" />
						<div class="tp-segment tp-segment--sm">
							<a class="<?php echo 'store' === $scope ? 'is-active' : ''; ?>" href="<?php echo esc_url( $link( $mode, 'store' ) ); ?>"><?php esc_html_e( 'This store', 'thawani-pay-for-woocommerce' ); ?></a>
							<a class="<?php echo 'all' === $scope ? 'is-active' : ''; ?>" href="<?php echo esc_url( $link( $mode, 'all' ) ); ?>"><?php esc_html_e( 'Entire Thawani account', 'thawani-pay-for-woocommerce' ); ?></a>
						</div>
					</div>
				</div>

				<?php if ( $error ) : ?>
					<div class="tp-alert tp-alert--error"><?php echo esc_html( $error ); ?></div>
				<?php endif; ?>

				<table class="tp-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Customer', 'thawani-pay-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Order', 'thawani-pay-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Invoice', 'thawani-pay-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Created', 'thawani-pay-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Status', 'thawani-pay-for-woocommerce' ); ?></th>
							<th class="tp-num"><?php esc_html_e( 'Amount', 'thawani-pay-for-woocommerce' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php
					foreach ( $sessions as $session ) {
						if ( is_array( $session ) ) {
							self::row( $session );
						}
					}
					?>
					</tbody>
				</table>

				<div class="tp-empty" <?php echo $sessions ? 'hidden' : ''; ?>>
					<svg viewBox="0 0 24 24" width="36" height="36" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18"/></svg>
					<p><?php esc_html_e( 'No checkout sessions yet.', 'thawani-pay-for-woocommerce' ); ?></p>
				</div>

				<footer class="tp-card__foot">
					<span>
						<?php
						if ( 'store' === $scope ) {
							/* translators: %d: number of sessions scanned. */
							echo esc_html( sprintf( __( 'Sessions created by this store among the %d most recent in the Thawani account.', 'thawani-pay-for-woocommerce' ), self::SCAN_LIMIT ) );
						}
						?>
					</span>
					<?php if ( $newer || $older ) : ?>
						<span class="tp-pager">
							<?php if ( $newer ) : ?>
								<a class="tp-btn tp-btn--ghost" href="<?php echo esc_url( $newer ); ?>">&larr; <?php esc_html_e( 'Newer', 'thawani-pay-for-woocommerce' ); ?></a>
							<?php endif; ?>
							<?php if ( $older ) : ?>
								<a class="tp-btn tp-btn--ghost" href="<?php echo esc_url( $older ); ?>"><?php esc_html_e( 'Older', 'thawani-pay-for-woocommerce' ); ?> &rarr;</a>
							<?php endif; ?>
						</span>
					<?php endif; ?>
				</footer>
			</section>
		</div>
		<?php
	}

	/**
	 * One table row.
	 *
	 * @param array $session Session.
	 */
	private static function row( array $session ): void {
		$reference = (string) ( $session['client_reference_id'] ?? '' );
		$order_id  = OrderMeta::order_id_from_reference( $reference );
		$order     = $order_id ? wc_get_order( $order_id ) : null;
		$status    = strtolower( (string) ( $session['payment_status'] ?? '' ) );
		$meta      = isset( $session['metadata'] ) && is_array( $session['metadata'] ) ? $session['metadata'] : array();
		$raw_date  = (string) ( $session['created_at'] ?? '' );
		$created   = $raw_date ? strtotime( $raw_date . ( preg_match( '/Z|[+-]\d\d:?\d\d$/', $raw_date ) ? '' : 'Z' ) ) : 0;
		$name      = trim( (string) ( $meta['Customer name'] ?? '' ) );
		$email     = (string) ( $meta['Email address'] ?? '' );
		$initials  = '';
		foreach ( array_slice( preg_split( '/\s+/u', $name ? $name : '?' ), 0, 2 ) as $part ) {
			$initials .= mb_strtoupper( mb_substr( $part, 0, 1 ) );
		}
		$search = strtolower( implode( ' ', array( $name, $email, $reference, (string) ( $session['invoice'] ?? '' ), $order ? '#' . $order->get_order_number() : '' ) ) );
		?>
		<tr data-status="<?php echo esc_attr( $status ); ?>" data-search="<?php echo esc_attr( $search ); ?>">
			<td>
				<div class="tp-who">
					<span class="tp-avatar" aria-hidden="true"><?php echo esc_html( $initials ); ?></span>
					<span><strong><?php echo esc_html( $name ? $name : __( 'Guest', 'thawani-pay-for-woocommerce' ) ); ?></strong>
					<?php
					if ( $email ) :
						?>
						<small><?php echo esc_html( $email ); ?></small><?php endif; ?></span>
				</div>
			</td>
			<td>
				<?php if ( $order instanceof \WC_Order ) : ?>
					<a class="tp-link" href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a>
				<?php else : ?>
					<span class="tp-muted"><?php echo esc_html( $reference ); ?></span>
				<?php endif; ?>
			</td>
			<td><code class="tp-code"><?php echo esc_html( (string) ( $session['invoice'] ?? '' ) ); ?></code></td>
			<td>
				<?php if ( $created ) : ?>
					<span title="<?php echo esc_attr( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $created ) ); ?>">
						<?php
						/* translators: %s: human time difference. */
						echo esc_html( sprintf( __( '%s ago', 'thawani-pay-for-woocommerce' ), human_time_diff( $created ) ) );
						?>
					</span>
				<?php endif; ?>
			</td>
			<td><span class="tp-pill tp-pill--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( self::status_label( $status ) ); ?></span></td>
			<td class="tp-num"><strong><?php echo esc_html( number_format( Money::from_baisa( (int) ( $session['total_amount'] ?? 0 ) ), 3 ) ); ?></strong> <small>OMR</small></td>
		</tr>
		<?php
	}

	/**
	 * Recent sessions that belong to this store (identified by the `store` metadata the plugin sends).
	 *
	 * @param Client $client Client.
	 * @return array[]
	 * @throws ApiException On API errors.
	 */
	private static function store_sessions( Client $client ): array {
		$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$found = array();

		for ( $skip = 0; $skip < self::SCAN_LIMIT; $skip += 50 ) {
			$batch = $client->list_sessions( 50, $skip );

			foreach ( $batch as $session ) {
				if ( is_array( $session ) && isset( $session['metadata']['store'] ) && $host === (string) $session['metadata']['store'] ) {
					$found[] = $session;
				}
			}

			if ( count( $batch ) < 50 || count( $found ) >= 50 ) {
				break;
			}
		}

		return $found;
	}

	/**
	 * Translated status.
	 *
	 * @param string $status API status.
	 */
	private static function status_label( string $status ): string {
		$labels = array(
			'paid'      => __( 'Paid', 'thawani-pay-for-woocommerce' ),
			'unpaid'    => __( 'Unpaid', 'thawani-pay-for-woocommerce' ),
			'cancelled' => __( 'Cancelled', 'thawani-pay-for-woocommerce' ),
		);

		return $labels[ $status ] ?? ucfirst( $status );
	}
}
