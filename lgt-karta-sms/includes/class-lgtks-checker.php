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
		$ev    = ( 'evardia' === LGTKS_Settings::get( 'source_type' ) && LGTKS_Settings::get( 'ev_use_schedule' ) ) ? LGTKS_Evardia::today_rows( $day ) : null;
		$rows  = array();
		foreach ( LGTKS_DB::employees( true ) as $e ) {
			$start = isset( $e['schedule'][ $dow ] ) ? $e['schedule'][ $dow ] : '';
			$shift = null;
			if ( null !== $ev ) {
				// Schedule comes from eVardia: no row today = no shift today.
				$start = '';
				if ( isset( $ev[ $e['id'] ] ) ) {
					$shift = LGTKS_Evardia::relevant_shift( $ev[ $e['id'] ], $now );
					$start = $shift ? $shift['start'] : '';
				}
			}
			$row   = array(
				'employee'  => $e,
				'start'     => $start,
				'status'    => 'off',
				'first_at'  => isset( $punch[ $e['id'] ] ) ? $punch[ $e['id'] ]['first_at'] : null,
				'sms'       => isset( $notif[ $e['id'] ] ) ? $notif[ $e['id'] ] : array(),
				'late_min'  => 0,
				'deadline'  => null,
				'shift'     => $shift,
				'ev'        => null !== $ev,
			);
			if ( $shift && '' !== $shift['in'] ) {
				$row['first_at'] = $day . ' ' . $shift['in'] . ':00';
			} elseif ( $shift && $row['first_at'] && $shift['start'] > substr( $row['first_at'], 11, 5 ) ) {
				// A punch recorded before this (later) shift belongs to an earlier shift: look for one after its start.
				$row['first_at'] = LGTKS_DB::first_punch_after( $e['id'], $day, $day . ' ' . $shift['start'] . ':00' );
			}
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
			// eVardia also provides the schedule, so it is synced on every run (page cached 4 min).
			if ( $sync && 'evardia' === LGTKS_Settings::get( 'source_type' ) ) {
				$res             = LGTKS_Source::sync( $day );
				$summary['sync'] = is_wp_error( $res ) ? $res->get_error_message() : $res;
				$rows            = self::status_for_day( $day );
				$sync            = false;
				$anyDue          = false;
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
				if ( ! self::has_contact( $r['employee'] ) ) {
					$summary['no_contact'] = ( $summary['no_contact'] ?? 0 ) + 1;
					continue; // nothing to send to (e.g. auto-created from eVardia without a mobile yet)
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
			$mch  = LGTKS_Settings::manager_channel();
			if ( in_array( $mch, array( 'both', 'sms' ), true ) ) {
				foreach ( LGTKS_Settings::manager_mobiles() as $m ) {
					$mr = LGTKS_SMS::send( $m, $mmsg );
					LGTKS_DB::add_notification( $e['id'], $day, $round, 'manager', $m, $mmsg, $mr['ok'] ? 'sent' : 'failed', $mr['response'] );
				}
			}
			if ( in_array( $mch, array( 'both', 'email' ), true ) ) {
				foreach ( LGTKS_Settings::manager_emails() as $email ) {
					$sent = wp_mail( $email, '[Κάρτα εργασίας] ' . $e['name'] . ' – δεν χτύπησε κάρτα', $mmsg . "\n\nΕιδοποίηση εργαζομένου (" . $channel . '): ' . ( $ok_any ? 'εστάλη' : 'ΑΠΕΤΥΧΕ – ' . $res['response'] ) );
					LGTKS_DB::add_notification( $e['id'], $day, $round, 'manager_email', $email, $mmsg, $sent ? 'sent' : 'failed', $sent ? 'wp_mail OK' : 'wp_mail απέτυχε' );
				}
			}
		}
		return $ok_any;
	}

	/**
	 * Send the managers a summary email of today's status (manual button or on demand).
	 *
	 * @return array {sent:int, failed:int, due:int, error:string}
	 */
	public static function email_managers_summary() {
		$emails = LGTKS_Settings::manager_emails();
		if ( ! $emails ) {
			return array( 'sent' => 0, 'failed' => 0, 'due' => 0, 'error' => 'Δεν έχουν οριστεί email υπευθύνων (Ρυθμίσεις → Email υπευθύνων).' );
		}
		$rows   = self::status_for_day();
		$groups = array( 'due' => array(), 'not_yet' => array(), 'present' => array(), 'late_window_passed' => array(), 'off' => array() );
		foreach ( $rows as $r ) {
			$e = $r['employee'];
			$k = isset( $groups[ $r['status'] ] ) ? $r['status'] : 'off';
			$line = $e['name'];
			if ( $r['start'] ) {
				$line .= ' – βάρδια ' . $r['start'];
			}
			if ( 'present' === $k && $r['first_at'] ) {
				$line .= ' – χτύπησε ' . substr( $r['first_at'], 11, 5 );
			}
			if ( 'due' === $k ) {
				$line .= ' – καθυστέρηση ' . max( 0, (int) $r['late_min'] ) . '′';
				if ( 'none' === $e['notify_channel'] ) {
					$line .= ' (εξαιρείται από ειδοποιήσεις)';
				} elseif ( $r['sms'] ) {
					$line .= ' (ειδοποιήθηκε ' . substr( end( $r['sms'] )['created_at'], 11, 5 ) . ')';
				}
			}
			$groups[ $k ][] = $line;
		}
		$now  = wp_date( 'd/m/Y H:i' );
		$vars = array( '{date}' => wp_date( 'd/m/Y' ), '{now}' => wp_date( 'H:i' ), '{due}' => count( $groups['due'] ), '{company}' => (string) LGTKS_Settings::get( 'company_name' ) );
		$subj = strtr( (string) LGTKS_Settings::get( 'manager_summary_subject' ), $vars );
		$body = 'Κατάσταση κάρτας εργασίας – ' . $now . "\n\n";
		$body .= 'ΔΕΝ ΕΧΟΥΝ ΧΤΥΠΗΣΕΙ (' . count( $groups['due'] ) . "):\n" . ( $groups['due'] ? '  • ' . implode( "\n  • ", $groups['due'] ) : '  —' ) . "\n\n";
		$body .= 'Εκτός παραθύρου / πιθανή απουσία (' . count( $groups['late_window_passed'] ) . "):\n" . ( $groups['late_window_passed'] ? '  • ' . implode( "\n  • ", $groups['late_window_passed'] ) : '  —' ) . "\n\n";
		$body .= 'Σε αναμονή, δεν ήρθε ακόμη η ώρα (' . count( $groups['not_yet'] ) . "):\n" . ( $groups['not_yet'] ? '  • ' . implode( "\n  • ", $groups['not_yet'] ) : '  —' ) . "\n\n";
		$body .= 'Χτύπησαν (' . count( $groups['present'] ) . "):\n" . ( $groups['present'] ? '  • ' . implode( "\n  • ", $groups['present'] ) : '  —' ) . "\n\n";
		$body .= 'Ρεπό / εκτός βάρδιας: ' . count( $groups['off'] ) . "\n\n" . (string) LGTKS_Settings::get( 'company_name' );
		$res = array( 'sent' => 0, 'failed' => 0, 'due' => count( $groups['due'] ), 'error' => '' );
		foreach ( $emails as $to ) {
			$ok = wp_mail( $to, $subj, $body );
			LGTKS_DB::add_notification( 0, current_time( 'Y-m-d' ), 1, 'manager_summary', $to, $subj, $ok ? 'sent' : 'failed', $ok ? 'wp_mail OK' : 'wp_mail απέτυχε (ελέγξτε SMTP)' );
			$res[ $ok ? 'sent' : 'failed' ]++;
		}
		return $res;
	}

	/** Does the employee have the contact detail their channel needs? */
	public static function has_contact( array $e ) {
		$ch = isset( $e['notify_channel'] ) ? $e['notify_channel'] : 'sms';
		$m  = '' !== trim( (string) $e['mobile'] );
		$em = is_email( trim( (string) ( $e['email'] ?? '' ) ) );
		if ( 'sms' === $ch ) {
			return $m;
		}
		if ( 'email' === $ch ) {
			return (bool) $em;
		}
		if ( 'both' === $ch ) {
			return $m || $em;
		}
		return false;
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
