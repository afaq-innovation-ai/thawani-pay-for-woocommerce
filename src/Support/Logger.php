<?php
/**
 * WooCommerce logger wrapper.
 *
 * @package AfaqInnovation\ThawaniPay
 */

namespace AfaqInnovation\ThawaniPay\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Writes to WooCommerce → Status → Logs under the `thawani-pay` source.
 * Errors are always recorded; debug/info only when "Debug log" is enabled.
 */
final class Logger {

	const SOURCE = 'thawani-pay';

	/**
	 * Debug-level message.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function debug( string $message, array $context = array() ): void {
		self::log( 'debug', $message, $context );
	}

	/**
	 * Info-level message.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function info( string $message, array $context = array() ): void {
		self::log( 'info', $message, $context );
	}

	/**
	 * Warning.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function warning( string $message, array $context = array() ): void {
		self::log( 'warning', $message, $context );
	}

	/**
	 * Error (always logged).
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function error( string $message, array $context = array() ): void {
		self::log( 'error', $message, $context );
	}

	/**
	 * Write a log entry.
	 *
	 * @param string $level   PSR-3 level.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	private static function log( string $level, string $message, array $context ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		if ( ! in_array( $level, array( 'error', 'warning' ), true ) && ! Settings::flag( 'debug' ) ) {
			return;
		}

		if ( $context ) {
			$message .= ' ' . wp_json_encode( self::redact( $context ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		wc_get_logger()->log( $level, $message, array( 'source' => self::SOURCE ) );
	}

	/**
	 * Mask secrets and personal data before they reach the log file.
	 *
	 * @param mixed $data Data.
	 * @return mixed
	 */
	private static function redact( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$sensitive = array( 'thawani-api-key', 'secret_key', 'publishable_key', 'key', 'Email address', 'Contact number' );

		foreach ( $data as $k => $v ) {
			if ( is_string( $k ) && in_array( $k, $sensitive, true ) && is_string( $v ) && '' !== $v ) {
				$data[ $k ] = substr( $v, 0, 3 ) . str_repeat( '•', 6 );
			} elseif ( is_array( $v ) ) {
				$data[ $k ] = self::redact( $v );
			}
		}

		return $data;
	}
}
