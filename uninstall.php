<?php
/**
 * Uninstall routine.
 *
 * Settings and saved-card references are removed only when the merchant opted in
 * ("Delete plugin settings … when the plugin is deleted"). Order meta is always kept
 * because it is part of the store's financial records.
 *
 * @package AfaqInnovation\ThawaniPay
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$thawani_pay_settings = get_option( 'woocommerce_thawani_settings', array() );

if ( ! is_array( $thawani_pay_settings ) || 'yes' !== ( $thawani_pay_settings['delete_data'] ?? 'no' ) ) {
	return;
}

delete_option( 'woocommerce_thawani_settings' );
delete_transient( 'thawani_pay_recurring_ok' );

delete_metadata( 'user', 0, '_thawani_customer_id_test', '', true );
delete_metadata( 'user', 0, '_thawani_customer_id_live', '', true );

global $wpdb;

// Remove local references to cards stored at Thawani (the cards themselves stay with Thawani).
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}woocommerce_payment_tokenmeta WHERE payment_token_id IN ( SELECT token_id FROM {$wpdb->prefix}woocommerce_payment_tokens WHERE gateway_id = %s )", 'thawani' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}woocommerce_payment_tokens WHERE gateway_id = %s", 'thawani' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'thawani-pay' );
}
