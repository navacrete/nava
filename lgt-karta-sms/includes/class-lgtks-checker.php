<?php
/**
 * The actual check: who has a shift, who has not clocked in, send SMS.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LGTKS_Checker {

	/**
	 * Per-employee status for a day (used by the dashboard and by run()).
	 *
	 * status: off | holiday | not_yet | due | late_window_passed | present
	 */
	public static function status_for_day( $day = null ) {
		$tz    = wp_timezone();
		$now   = new DateTime( 'now', $tz );
		$day   = $day ? $day : $now->format( 'Y-m-d' );
		$d     = new DateTime( $day, $tz );
		$dow   = (int) $d->format( 'N' );
		$hol   = LGTKS_Settings::is_holiday( $day );
		$grace = (int) LGTKS_Settings::get( 'grace_minutes', 15 );
		$maxd  = (int) LGTKS_Settings::get( 'max_delay_minutes', 180 );
		$punch = LGTKS_DB::punches_for_day( $day );
		$notif = LGTKS_DB::notifications_for_day( $day );
		$rows  = array();
		foreach ( LGTKS_DB::employees( true ) as $e ) {
			$start = isset( $e['schedule'][ $dow ] ) ? $e['schedule'][ $dow ] : '';
			$row   = array(
				'employee'  => $e,
				'start'     => $start,
				'status'    => 'off',
				'first_at'  => isset( $punch[ $e['id'] ] ) ? $punch[ $e['id'] ]['first_at'] : null,
				'sms'       => isset( $notif[ $e['id'] ] ) ? $notif[ $e['id'] ] : array(),
				'late_min'  => 0,
				'deadline'  => null,
			);
			if ( $hol ) {
				$row['status'] = 'holiday';
			} elseif ( '' === $start ) {
				$row['status'] = 'off';
			} else {
				$days_off = LGTKS_Settings::parse_dates( $e['days_off'] );
				if ( isset( $days_off[ $day ] ) ) {
					$row['status'] = 'off';
				} else {
					$g        = null === $e['grace_minutes'] ? $grace : (int) $e['grace_minutes'];
					$start_dt = new DateTime( $day . ' ' . $start, $tz );
					$deadline = clone $start_dt;
					$deadline->modify( '+' . $g . ' minutes' );
					$row['deadline'] = $deadline->format( 'H:i' );
					$row['late_min'] = (int) floor( ( $now->getTimestamp() - $start_dt->getTimestamp() ) / 60 );
					if ( $row['first_at'] ) {
						$row['status'] = 'present';
					} elseif ( $now < $deadline ) {
						$row['status'] = 'not_yet';
					} elseif ( $row['late_min'] > $maxd ) {
						$row['status'] = 'late_window_passed';
					} else {
						$row['status'] = 'due';
					}
				}
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * Run the check for today. Returns a summary array.
	 *
	 * @param bool $sync Pull punches from the source first (api_json/csv_url).
	 */
	public static function run( $sync = true ) {
		$lock = get_transient( 'lgtks_running' );
		if ( $lock ) {
			return array( 'skipped' => 'running' );
		}
		set_transient( 'lgtks_running', 1, 2 * MINUTE_IN_SECONDS );

		$day     = current_time( 'Y-m-d' );
		$summary = array( 'day' => $day, 'due' => 0, 'sent' => 0, 'failed' => 0, 'sync' => null );
		try {
			$rows   = self::status_for_day( $day );
			$anyDue = false;
			foreach ( $rows as $r ) {
				if ( 'due' === $r['status'] ) {
					$anyDue = true;
					break;
				}
			}
			// Only hit the external source when someone is actually due – saves API calls.
			if ( $anyDue && $sync ) {
				$res             = LGTKS_Source::sync( $day );
				$summary['sync'] = is_wp_error( $res ) ? $res->get_error_message() : $res;
				$rows            = self::status_for_day( $day );
			}
			$second = (int) LGTKS_Settings::get( 'second_reminder_minutes', 0 );
			$now_ts = current_time( 'timestamp' );
			foreach ( $rows as $r ) {
				if ( 'due' !== $r['status'] ) {
					continue;
				}
				if ( 'none' === $r['employee']['notify_channel'] ) {
					continue; // excluded from notifications
				}
				$summary['due']++;
				$sent_rounds = array();
				foreach ( $r['sms'] as $n ) {
					if ( 'sent' === $n['status'] && ! isset( $sent_rounds[ (int) $n['round'] ] ) ) {
						$sent_rounds[ (int) $n['round'] ] = strtotime( $n['created_at'] );
					}
				}
				$round = 0;
				if ( empty( $sent_rounds ) ) {
					$round = 1;
				} elseif ( $second > 0 && isset( $sent_rounds[1] ) && ! isset( $sent_rounds[2] ) && $now_ts >= $sent_rounds[1] + $second * 60 ) {
					$round = 2;
				}
				if ( ! $round ) {
					continue;
				}
				$ok = self::notify( $r, $round );
				if ( $ok ) {
					$summary['sent']++;
				} else {
					$summary['failed']++;
				}
			}
		} finally {
			delete_transient( 'lgtks_running' );
		}
		update_option( 'lgt_ks_last_run', array( 'at' => current_time( 'mysql' ), 'summary' => $summary ) );
		if ( $summary['due'] || $summary['sync'] ) {
			LGTKS_DB::log( 'info', 'Έλεγχος', $summary );
		}
		return $summary;
	}

	/**
	 * Notify the employee by SMS and/or email according to notify_channel (+ manager on round 1).
	 * Returns true if at least one employee notification was sent.
	 */
	public static function notify( array $row, $round = 1, $force = false ) {
		$e       = $row['employee'];
		$day     = current_time( 'Y-m-d' );
		$vars    = self::vars( $row );
		$msg     = strtr( (string) LGTKS_Settings::get( 'message_template' ), $vars );
		$channel = isset( $e['notify_channel'] ) ? $e['notify_channel'] : 'sms';
		if ( 'none' === $channel ) {
			// Manual "send now" on an excluded employee: use whatever contact exists.
			$channel = '' !== trim( $e['mobile'] ) ? 'sms' : 'email';
		}
		$ok_any = false;
		$res    = array( 'ok' => false, 'response' => 'δεν στάλθηκε SMS (κανάλι: ' . $channel . ')' );
		if ( in_array( $channel, array( 'sms', 'both' ), true ) ) {
			$res = LGTKS_SMS::send( $e['mobile'], $msg );
			LGTKS_DB::add_notification( $e['id'], $day, $round, 'sms', $e['mobile'], $msg, $res['ok'] ? 'sent' : 'failed', $res['response'] );
			if ( ! $res['ok'] ) {
				LGTKS_DB::log( 'error', 'Αποτυχία SMS σε ' . $e['name'] . ' (' . $e['mobile'] . ')', $res['response'] );
			}
			$ok_any = $ok_any || $res['ok'];
		}
		if ( in_array( $channel, array( 'email', 'both' ), true ) ) {
			$to = trim( (string) $e['email'] );
			if ( '' === $to || ! is_email( $to ) ) {
				LGTKS_DB::add_notification( $e['id'], $day, $round, 'email', $to, $msg, 'failed', 'Λείπει/άκυρο email εργαζομένου' );
			} else {
				$subject = strtr( (string) LGTKS_Settings::get( 'email_subject' ), $vars );
				$sent    = wp_mail( $to, $subject, $msg );
				LGTKS_DB::add_notification( $e['id'], $day, $round, 'email', $to, $msg, $sent ? 'sent' : 'failed', $sent ? 'wp_mail OK' : 'wp_mail απέτυχε (ελέγξτε SMTP)' );
				$ok_any = $ok_any || $sent;
			}
		}
		if ( 1 === (int) $round || $force ) {
			$mmsg = strtr( (string) LGTKS_Settings::get( 'manager_message_template' ), $vars );
			foreach ( LGTKS_Settings::manager_mobiles() as $m ) {
				$mr = LGTKS_SMS::send( $m, $mmsg );
				LGTKS_DB::add_notification( $e['id'], $day, $round, 'manager', $m, $mmsg, $mr['ok'] ? 'sent' : 'failed', $mr['response'] );
			}
			$email = trim( (string) LGTKS_Settings::get( 'manager_email' ) );
			if ( '' !== $email && is_email( $email ) ) {
				wp_mail( $email, '[Κάρτα εργασίας] ' . $e['name'] . ' – δεν χτύπησε κάρτα', $mmsg . "\n\nΕιδοποίηση εργαζομένου (" . $channel . '): ' . ( $ok_any ? 'εστάλη' : 'ΑΠΕΤΥΧΕ – ' . $res['response'] ) );
			}
		}
		return $ok_any;
	}

	public static function vars( array $row ) {
		$e     = $row['employee'];
		$parts = preg_split( '/\s+/', trim( $e['name'] ) );
		$tz    = wp_timezone();
		$now   = new DateTime( 'now', $tz );
		return array(
			'{name}'       => $e['name'],
			'{first_name}' => $parts ? $parts[0] : $e['name'],
			'{time}'       => $row['start'],
			'{date}'       => $now->format( 'd/m/Y' ),
			'{now}'        => $now->format( 'H:i' ),
			'{minutes}'    => max( 0, (int) $row['late_min'] ),
			'{company}'    => (string) LGTKS_Settings::get( 'company_name' ),
		);
	}
}
