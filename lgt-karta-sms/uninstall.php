<?php
/**
 * Uninstall: removes data only if "delete_on_uninstall" is enabled in settings.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings = get_option( 'lgt_ks_settings', array() );
wp_clear_scheduled_hook( 'lgtks_check_event' );
if ( empty( $settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;
foreach ( array( 'lgtks_employees', 'lgtks_punches', 'lgtks_notifications', 'lgtks_log' ) as $t ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$t}" ); // phpcs:ignore WordPress.DB
}
delete_option( 'lgt_ks_settings' );
delete_option( 'lgt_ks_db_version' );
delete_option( 'lgt_ks_last_sync' );
delete_option( 'lgt_ks_last_run' );
delete_transient( 'lgtks_routee_token' );
