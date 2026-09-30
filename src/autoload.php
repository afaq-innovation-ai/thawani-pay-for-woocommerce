<?php
/**
 * PSR-4 autoloader for the AfaqInnovation\ThawaniPay namespace.
 *
 * @package AfaqInnovation\ThawaniPay
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'AfaqInnovation\\ThawaniPay\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
