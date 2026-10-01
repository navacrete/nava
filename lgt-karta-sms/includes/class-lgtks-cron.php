<?php
/**
 * Every-5-minutes check (WP-Cron) + external trigger URL for a real cron.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LGTKS_Cron {

	const HOOK     = 'lgtks_check_event';
	const INTERVAL = 'lgtks_five_minutes';

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
	}

	public static function schedules( $s ) {
		if ( ! isset( $s[ self::INTERVAL ] ) ) {
			$s[ self::INTERVAL ] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => 'Κάθε 5 λεπτά (LGT Κάρτα Εργασίας)',
			);
		}
		return $s;
	}

	public static function schedule() {
		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}
		wp_schedule_event( time() + MINUTE_IN_SECONDS, self::INTERVAL, self::HOOK );
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Cron entry point. With the global switch off the check still runs (sync + dashboard + health)
	 * but sends no notifications at all ("dry run").
	 */
	public static function run() {
		LGTKS_Checker::run( true, (bool) LGTKS_Settings::get( 'enabled' ) );
	}
}
