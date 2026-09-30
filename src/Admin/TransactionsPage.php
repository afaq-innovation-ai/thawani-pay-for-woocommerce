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
		?>
		<div class="wrap thawani-pay-transactions">
			<h1 class="wp-heading-inline">
				<img src="<?php echo esc_url( THAWANI_PAY_URL . 'assets/images/thawani-pay.svg' ); ?>" alt="" width="28" height="28" />
				<?php esc_html_e( 'Thawani Transactions', 'thawani-pay-for-woocommerce' ); ?>
			</h1>
			<a class="page-title-action" href="<?php echo esc_url( Admin::settings_url() ); ?>"><?php esc_html_e( 'Settings', 'thawani-pay-for-woocommerce' ); ?></a>
			<hr class="wp-header-end" />

			<nav class="nav-tab-wrapper">
				<a class="nav-tab <?php echo Settings::MODE_TEST === $mode ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( $link( 'test', $scope ) ); ?>"><?php esc_html_e( 'Sandbox', 'thawani-pay-for-woocommerce' ); ?></a>
				<a class="nav-tab <?php echo Settings::MODE_LIVE === $mode ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( $link( 'live', $scope ) ); ?>"><?php esc_html_e( 'Live', 'thawani-pay-for-woocommerce' ); ?></a>
			</nav>

			<ul class="subsubsub">
				<li><a class="<?php echo 'store' === $scope ? 'current' : ''; ?>" href="<?php echo esc_url( $link( $mode, 'store' ) ); ?>"><?php esc_html_e( 'This store', 'thawani-pay-for-woocommerce' ); ?></a> |</li>
				<li><a class="<?php echo 'all' === $scope ? 'current' : ''; ?>" href="<?php echo esc_url( $link( $mode, 'all' ) ); ?>"><?php esc_html_e( 'Entire Thawani account', 'thawani-pay-for-woocommerce' ); ?></a></li>
			</ul>
			<br class="clear" />

			<?php if ( 'store' === $scope ) : ?>
				<p class="description">
					<?php
					/* translators: %d: number of sessions scanned. */
					echo esc_html( sprintf( __( 'Sessions created by this store among the %d most recent in the Thawani account.', 'thawani-pay-for-woocommerce' ), self::SCAN_LIMIT ) );
					?>
				</p>
			<?php endif; ?>

			<?php if ( $error ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>

			<table class="widefat striped thawani-pay-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Created', 'thawani-pay-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Invoice', 'thawani-pay-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Order', 'thawani-pay-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'thawani-pay-for-woocommerce' ); ?></th>
						<th class="num"><?php esc_html_e( 'Amount', 'thawani-pay-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Status', 'thawani-pay-for-woocommerce' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $sessions ) ) : ?>
					<tr><td colspan="6" class="thawani-pay-empty"><?php esc_html_e( 'No checkout sessions yet.', 'thawani-pay-for-woocommerce' ); ?></td></tr>
				<?php endif; ?>
				<?php
				foreach ( $sessions as $session ) {
					if ( is_array( $session ) ) {
						self::row( $session );
					}
				}
				?>
				</tbody>
			</table>

			<?php if ( $newer || $older ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<?php if ( $newer ) : ?>
							<a class="button" href="<?php echo esc_url( $newer ); ?>">&larr; <?php esc_html_e( 'Newer', 'thawani-pay-for-woocommerce' ); ?></a>
						<?php endif; ?>
						<?php if ( $older ) : ?>
							<a class="button" href="<?php echo esc_url( $older ); ?>"><?php esc_html_e( 'Older', 'thawani-pay-for-woocommerce' ); ?> &rarr;</a>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>
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
		$created   = isset( $session['created_at'] ) ? strtotime( (string) $session['created_at'] . ( preg_match( '/Z|[+-]\d\d:?\d\d$/', (string) $session['created_at'] ) ? '' : 'Z' ) ) : 0;
		$format    = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<tr>
			<td><?php echo esc_html( $created ? wp_date( $format, $created ) : '—' ); ?></td>
			<td><code><?php echo esc_html( (string) ( $session['invoice'] ?? '' ) ); ?></code></td>
			<td>
				<?php if ( $order instanceof \WC_Order ) : ?>
					<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a>
				<?php else : ?>
					<span class="thawani-pay-muted"><?php echo esc_html( $reference ); ?></span>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( (string) ( $meta['Customer name'] ?? '—' ) ); ?></td>
			<td class="num"><?php echo esc_html( Money::format_baisa( (int) ( $session['total_amount'] ?? 0 ) ) ); ?></td>
			<td><span class="thawani-pay-pill thawani-pay-pill--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( self::status_label( $status ) ); ?></span></td>
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
