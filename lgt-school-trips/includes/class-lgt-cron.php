<?php
/**
 * Daily scheduled job: reminders, digest, cleanup.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LGT_Cron {

	const HOOK = 'lgt_st_daily_event';

	/** Schedule the daily event at the configured hour (site timezone). */
	public static function schedule( $reschedule = false ) {
		if ( $reschedule ) {
			wp_clear_scheduled_hook( self::HOOK );
		} elseif ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}
		$hour = (int) LGT_Settings::get( 'reminder_hour', 8 );
		$tz   = wp_timezone();
		$next = new DateTime( 'today', $tz );
		$next->setTime( $hour, 5 );
		if ( $next->getTimestamp() <= time() ) {
			$next->modify( '+1 day' );
		}
		wp_schedule_event( $next->getTimestamp(), 'daily', self::HOOK );
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Run reminders and digest. Safe to call repeatedly (idempotent per day).
	 */
	public static function run_daily() {
		$today = current_time( 'Y-m-d' );
		$last  = get_option( 'lgt_st_last_cron', '' );
		if ( $last === $today ) {
			return;
		}
		update_option( 'lgt_st_last_cron', $today );

		self::run_reminders( $today );
		if ( LGT_Settings::get( 'daily_digest' ) ) {
			self::run_digest();
		}
		LGT_Exporter::cleanup();
	}

	public static function run_reminders( $today = null ) {
		$today = $today ? $today : current_time( 'Y-m-d' );
		$days  = LGT_Settings::reminder_days();
		if ( ! $days ) {
			return;
		}
		$today_ts = strtotime( $today );
		foreach ( LGT_DB::upcoming_trips() as $trip ) {
			$dep = strtotime( $trip['departure_date'] );
			if ( ! $dep ) {
				continue;
			}
			$diff = (int) round( ( $dep - $today_ts ) / DAY_IN_SECONDS );
			$sent = isset( $trip['meta']['reminders_sent'] ) && is_array( $trip['meta']['reminders_sent'] ) ? $trip['meta']['reminders_sent'] : array();
			// Send for the largest configured threshold that is >= diff and not yet sent
			// (catches missed days when cron did not run).
			$to_send = null;
			foreach ( $days as $d ) {
				if ( $diff <= $d && ! in_array( $d, $sent, true ) ) {
					$to_send = $d;
				}
			}
			if ( null === $to_send ) {
				continue;
			}
			// Mark all thresholds >= diff as sent, so a missed 30-day reminder is not sent at day 14 twice.
			foreach ( $days as $d ) {
				if ( $d >= $diff && ! in_array( $d, $sent, true ) ) {
					$sent[] = $d;
				}
			}
			LGT_DB::update_trip_meta( $trip['id'], array( 'reminders_sent' => array_values( $sent ) ) );
			LGT_Mailer::send_reminder( $trip, $diff );
			LGT_DB::log( $trip['id'], 'system', 'reminder', sprintf( 'Υπενθύμιση %d ημέρες πριν', $diff ) );
		}
	}

	public static function run_digest() {
		$changed = array();
		foreach ( LGT_DB::upcoming_trips() as $trip ) {
			if ( ! $trip['data_updated_at'] ) {
				continue;
			}
			$last = $trip['meta']['last_export_at'] ?? '';
			if ( $last ) {
				// Already sent once: include when the data changed after the last export.
				if ( strtotime( $trip['data_updated_at'] ) > strtotime( $last ) ) {
					$changed[] = $trip;
				}
			} elseif ( strtotime( $trip['data_updated_at'] ) < time() - 20 * HOUR_IN_SECONDS ) {
				// Never exported: wait until the school has been quiet for ~a day, then send the first snapshot.
				$changed[] = $trip;
			}
		}
		if ( $changed ) {
			LGT_Mailer::send_digest( $changed );
		}
	}
}
