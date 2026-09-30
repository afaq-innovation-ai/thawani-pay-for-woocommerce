<?php
/**
 * Thawani webhook signature verification.
 *
 * @package AfaqInnovation\ThawaniPay
 */

namespace AfaqInnovation\ThawaniPay\Webhooks;

/**
 * Thawani signs every webhook with HMAC-SHA256 over "{raw body}-{thawani-timestamp}"
 * using the webhook secret configured in the merchant portal. The hex digest is sent
 * in the `thawani-signature` header.
 */
final class Signature {

	/**
	 * Compute the expected signature.
	 *
	 * @param string $body      Raw request body, exactly as received.
	 * @param string $timestamp Value of the `thawani-timestamp` header.
	 * @param string $secret    Webhook secret.
	 */
	public static function compute( string $body, string $timestamp, string $secret ): string {
		return hash_hmac( 'sha256', $body . '-' . $timestamp, $secret );
	}

	/**
	 * Constant-time verification.
	 *
	 * @param string $body      Raw request body.
	 * @param string $timestamp `thawani-timestamp` header.
	 * @param string $signature `thawani-signature` header.
	 * @param string $secret    Webhook secret.
	 */
	public static function verify( string $body, string $timestamp, string $signature, string $secret ): bool {
		if ( '' === $secret || '' === $signature || '' === $timestamp ) {
			return false;
		}

		return hash_equals( self::compute( $body, $timestamp, $secret ), strtolower( trim( $signature ) ) );
	}

	/**
	 * Reject stale or future-dated deliveries (replay protection).
	 *
	 * @param string $timestamp Unix timestamp header.
	 * @param int    $tolerance Allowed clock drift in seconds.
	 * @param int    $now       Current time (injectable for tests).
	 */
	public static function is_fresh( string $timestamp, int $tolerance = 600, ?int $now = null ): bool {
		if ( ! ctype_digit( $timestamp ) ) {
			return false;
		}

		$now = null === $now ? time() : $now;

		return abs( $now - (int) $timestamp ) <= $tolerance;
	}
}
