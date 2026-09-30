<?php
/**
 * Uninstall handler: removes plugin data only when the option "delete_on_uninstall" is enabled.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings = get_option( 'lgt_st_settings', array() );
if ( empty( $settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;
$tables = array( 'lgt_trips', 'lgt_participants', 'lgt_rooms', 'lgt_assignments', 'lgt_activity', 'lgt_mail_log' );
foreach ( $tables as $t ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$t}" ); // phpcs:ignore WordPress.DB
}
delete_option( 'lgt_st_settings' );
delete_option( 'lgt_st_db_version' );
delete_option( 'lgt_st_last_cron' );
wp_clear_scheduled_hook( 'lgt_st_daily_event' );

$upload = wp_upload_dir();
$dir    = trailingslashit( $upload['basedir'] ) . 'lgt-school-trips';
if ( is_dir( $dir ) ) {
	foreach ( glob( $dir . '/*' ) as $f ) {
		if ( is_file( $f ) ) {
			@unlink( $f );
		}
	}
	@rmdir( $dir );
}
