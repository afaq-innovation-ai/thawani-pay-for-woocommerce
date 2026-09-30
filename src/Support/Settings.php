<?php
/**
 * Typed access to the gateway settings.
 *
 * @package AfaqInnovation\ThawaniPay
 */

namespace AfaqInnovation\ThawaniPay\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Reads `woocommerce_thawani_settings` so services outside the gateway class
 * (webhooks, cron, admin screens) share one source of truth.
 */
final class Settings {

	const OPTION = 'woocommerce_thawani_settings';

	const MODE_TEST = 'test';
	const MODE_LIVE = 'live';

	/**
	 * Public UAT (sandbox) keys published in the Thawani developer documentation.
	 * They only work against uatcheckout.thawani.om and move no real money.
	 */
	const SANDBOX_SECRET_KEY      = 'rRQ26GcsZzoEhbrP2HZvLYDbn9C9et';
	const SANDBOX_PUBLISHABLE_KEY = 'HGvTMLDssJghr9tlN9gr4DVYt0qyBy';

	/**
	 * All saved settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$settings = get_option( self::OPTION, array() );

		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = '' ) {
		$settings = self::all();

		return array_key_exists( $key, $settings ) && '' !== $settings[ $key ] ? $settings[ $key ] : $fallback;
	}

	/**
	 * Yes/no setting as boolean.
	 *
	 * @param string $key     Setting key.
	 * @param bool   $fallback Fallback.
	 */
	public static function flag( string $key, bool $fallback = false ): bool {
		return 'yes' === self::get( $key, $fallback ? 'yes' : 'no' );
	}

	/**
	 * Active environment.
	 */
	public static function mode(): string {
		return self::flag( 'testmode', true ) ? self::MODE_TEST : self::MODE_LIVE;
	}

	/**
	 * Whether the given (or active) environment is the sandbox.
	 *
	 * @param string|null $mode Mode.
	 */
	public static function is_test( ?string $mode = null ): bool {
		return self::MODE_TEST === ( $mode ?? self::mode() );
	}

	/**
	 * Secret API key for an environment.
	 *
	 * @param string|null $mode Mode.
	 */
	public static function secret_key( ?string $mode = null ): string {
		return self::is_test( $mode )
			? trim( (string) self::get( 'test_secret_key', self::SANDBOX_SECRET_KEY ) )
			: trim( (string) self::get( 'live_secret_key' ) );
	}

	/**
	 * Publishable key for an environment.
	 *
	 * @param string|null $mode Mode.
	 */
	public static function publishable_key( ?string $mode = null ): string {
		return self::is_test( $mode )
			? trim( (string) self::get( 'test_publishable_key', self::SANDBOX_PUBLISHABLE_KEY ) )
			: trim( (string) self::get( 'live_publishable_key' ) );
	}

	/**
	 * Webhook secret for an environment.
	 *
	 * @param string|null $mode Mode.
	 */
	public static function webhook_secret( ?string $mode = null ): string {
		return trim( (string) self::get( self::is_test( $mode ) ? 'test_webhook_secret' : 'live_webhook_secret' ) );
	}

	/**
	 * Both API keys present for an environment.
	 *
	 * @param string|null $mode Mode.
	 */
	public static function has_keys( ?string $mode = null ): bool {
		return '' !== self::secret_key( $mode ) && '' !== self::publishable_key( $mode );
	}

	/**
	 * Session lifetime in minutes, clamped to Thawani's 30–10080 range.
	 */
	public static function session_expiry(): int {
		return max( 30, min( 10080, (int) self::get( 'session_expiry', 60 ) ) );
	}

	/**
	 * Prefix added to every client_reference_id (lets several stores share one merchant account).
	 */
	public static function reference_prefix(): string {
		return (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) self::get( 'reference_prefix', 'wc' ) );
	}

	/**
	 * Whether customers may save cards.
	 */
	public static function saved_cards_enabled(): bool {
		return self::flag( 'saved_cards', true );
	}
}
