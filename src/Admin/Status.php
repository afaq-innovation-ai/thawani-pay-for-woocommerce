<?php
/**
 * Health indicators shown at the top of the settings page.
 *
 * @package AfaqInnovation\ThawaniPay
 */

namespace AfaqInnovation\ThawaniPay\Admin;

use AfaqInnovation\ThawaniPay\Api\ApiException;
use AfaqInnovation\ThawaniPay\Api\Client;
use AfaqInnovation\ThawaniPay\Subscriptions\WpsSubscriptions;
use AfaqInnovation\ThawaniPay\Support\Money;
use AfaqInnovation\ThawaniPay\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Computes the "is everything set up?" tiles. Results that need the API are cached for 10 minutes.
 */
final class Status {

	const LAST_WEBHOOK = 'thawani_pay_last_webhook';

	/**
	 * Remember the last webhook delivery (called by the webhook controller).
	 *
	 * @param string $event    Event type.
	 * @param bool   $verified Signature verified.
	 */
	public static function record_webhook( string $event, bool $verified ): void {
		update_option(
			self::LAST_WEBHOOK,
			array(
				'event'    => $event,
				'verified' => $verified,
				'at'       => time(),
			),
			false
		);
	}

	/**
	 * API connection for an environment: [ ok, ms, message, at ].
	 *
	 * @param string $mode  Environment.
	 * @param bool   $fresh Skip the cache.
	 * @return array{ok:bool, ms:int, message:string, at:int}
	 */
	public static function connection( string $mode, bool $fresh = false ): array {
		$key    = 'thawani_pay_conn_' . $mode;
		$cached = get_transient( $key );
		if ( ! $fresh && is_array( $cached ) ) {
			return $cached;
		}

		$result = array(
			'ok'      => false,
			'ms'      => 0,
			'message' => __( 'API keys are missing.', 'thawani-pay-for-woocommerce' ),
			'at'      => time(),
		);

		if ( Settings::has_keys( $mode ) ) {
			$started = microtime( true );
			try {
				Client::for_mode( $mode )->list_sessions( 1, 0 );
				$result['ok'] = true;
			} catch ( ApiException $e ) {
				$result['ok'] = $e->is_not_found();
				if ( ! $result['ok'] ) {
					$result['message'] = $e->is_auth_error() ? __( 'Key rejected by Thawani.', 'thawani-pay-for-woocommerce' ) : $e->getMessage();
				}
			}
			$result['ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
			if ( $result['ok'] ) {
				$result['message'] = '';
			}
		}

		set_transient( $key, $result, 10 * MINUTE_IN_SECONDS );

		return $result;
	}

	/**
	 * All tiles for the settings header.
	 *
	 * @return array<int, array{id:string, state:string, label:string, value:string, hint:string}>
	 */
	public static function tiles(): array {
		$mode = Settings::mode();
		$conn = self::connection( $mode );
		$hook = get_option( self::LAST_WEBHOOK );

		$tiles = array();

		$tiles[] = array(
			'id'    => 'connection',
			'state' => $conn['ok'] ? 'ok' : 'error',
			'label' => __( 'API connection', 'thawani-pay-for-woocommerce' ),
			'value' => $conn['ok'] ? __( 'Connected', 'thawani-pay-for-woocommerce' ) : __( 'Not connected', 'thawani-pay-for-woocommerce' ),
			'hint'  => $conn['ok']
				/* translators: 1: environment, 2: response time. */
				? sprintf( __( '%1$s · %2$d ms', 'thawani-pay-for-woocommerce' ), Settings::is_test( $mode ) ? __( 'Sandbox', 'thawani-pay-for-woocommerce' ) : __( 'Live', 'thawani-pay-for-woocommerce' ), $conn['ms'] )
				: $conn['message'],
		);

		if ( is_array( $hook ) && ! empty( $hook['at'] ) ) {
			$tiles[] = array(
				'id'    => 'webhooks',
				'state' => ! empty( $hook['verified'] ) ? 'ok' : 'warn',
				'label' => __( 'Webhooks', 'thawani-pay-for-woocommerce' ),
				/* translators: %s: human time difference, e.g. "5 mins". */
				'value' => sprintf( __( 'Last event %s ago', 'thawani-pay-for-woocommerce' ), human_time_diff( (int) $hook['at'] ) ),
				'hint'  => (string) $hook['event'] . ( empty( $hook['verified'] ) ? ' · ' . __( 'unsigned', 'thawani-pay-for-woocommerce' ) : '' ),
			);
		} else {
			$has_secret = '' !== Settings::webhook_secret( $mode );
			$tiles[]    = array(
				'id'    => 'webhooks',
				'state' => $has_secret ? 'idle' : 'warn',
				'label' => __( 'Webhooks', 'thawani-pay-for-woocommerce' ),
				'value' => $has_secret ? __( 'Waiting for first event', 'thawani-pay-for-woocommerce' ) : __( 'Not configured', 'thawani-pay-for-woocommerce' ),
				'hint'  => $has_secret ? __( 'Secret saved', 'thawani-pay-for-woocommerce' ) : __( 'Recommended for production', 'thawani-pay-for-woocommerce' ),
			);
		}

		$currency = get_woocommerce_currency();
		$tiles[]  = array(
			'id'    => 'currency',
			'state' => Money::CURRENCY === $currency ? 'ok' : 'error',
			'label' => __( 'Store currency', 'thawani-pay-for-woocommerce' ),
			'value' => $currency,
			'hint'  => Money::CURRENCY === $currency ? __( 'Omani Rial — supported', 'thawani-pay-for-woocommerce' ) : __( 'Thawani accepts OMR only', 'thawani-pay-for-woocommerce' ),
		);

		$subs    = WpsSubscriptions::is_active();
		$tiles[] = array(
			'id'    => 'recurring',
			'state' => Settings::saved_cards_enabled() ? 'ok' : 'idle',
			'label' => __( 'Saved cards', 'thawani-pay-for-woocommerce' ),
			'value' => Settings::saved_cards_enabled() ? __( 'Enabled', 'thawani-pay-for-woocommerce' ) : __( 'Disabled', 'thawani-pay-for-woocommerce' ),
			'hint'  => $subs ? __( 'Subscriptions active', 'thawani-pay-for-woocommerce' ) : __( 'Subscriptions plugin not detected', 'thawani-pay-for-woocommerce' ),
		);

		return $tiles;
	}
}
