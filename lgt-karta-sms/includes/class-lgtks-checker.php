<?php
/**
 * The actual check: who has a shift, who has not clocked in, send SMS.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LGTKS_Checker {

	/** True while running without the right to send anything (switch off). */
	public static $dry = false;

	/**
	 * Per-employee status for a day (used by the dashboard and by run()).
	 *
	 * status: off | holiday | not_yet | due | late_window_passed | present
	 */
	public static function status_for_day( $day = null ) {
		$tz    = LGTKS_Settings::tz();
		$now   = new DateTime( 'now', $tz );
		$day   = $day ? $day : $now->format( 'Y-m-d' );
		$d     = new DateTime( $day, $tz );
		$dow   = (int) $d->format( 'N' );
		$hol   = LGTKS_Settings::is_holiday( $day );
		$hmode = (string) LGTKS_Settings::get( 'holiday_mode', 'skip' );
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
				'end'       => $shift ? $shift['end'] : '',
				'out_at'    => null,
				'out_late'  => 0,
			);
			if ( $shift && '' !== $shift['in'] ) {
				$row['first_at'] = $day . ' ' . $shift['in'] . ':00';
			} elseif ( $shift && $row['first_at'] && $shift['start'] > substr( $row['first_at'], 11, 5 ) ) {
				// A punch recorded before this (later) shift belongs to an earlier shift: look for one after its start.
				$row['first_at'] = LGTKS_DB::first_punch_after( $e['id'], $day, $day . ' ' . $shift['start'] . ':00' );
			}
			if ( $hol && ( 'evardia' !== $hmode || null === $ev || '' === $start ) ) {
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
			if ( 'present' === $row['status'] && $row['first_at'] ) {
				$out = ( $shift && '' !== $shift['out'] ) ? $day . ' ' . $shift['out'] . ':00' : LGTKS_DB::last_out_after( $e['id'], $day, $row['first_at'] );
				$row['out_at'] = $out ? $out : null;
				if ( '' !== $row['end'] ) {
					$end_dt          = new DateTime( $day . ' ' . $row['end'], $tz );
					$row['out_late'] = (int) floor( ( $now->getTimestamp() - $end_dt->getTimestamp() ) / 60 );
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
	public static function run( $sync = true, $notify = true ) {
		$lock = get_transient( 'lgtks_running' );
		if ( $lock ) {
			return array( 'skipped' => 'running' );
		}
		self::$dry = ! $notify;
		set_transient( 'lgtks_running', 1, 2 * MINUTE_IN_SECONDS );

		$day     = LGTKS_Settings::now( 'Y-m-d' );
		$summary = array( 'day' => $day, 'due' => 0, 'sent' => 0, 'failed' => 0, 'sync' => null, 'dry' => self::$dry );
		LGTKS_Health::record_run();
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
			$now_ts = LGTKS_Settings::now_ts();
			$escal  = (int) LGTKS_Settings::get( 'manager_escalation_minutes', 60 );
			// When the eVardia sync is failing, the status is stale: do not notify anyone on stale data.
			$health = LGTKS_Health::state();
			if ( 'evardia' === LGTKS_Settings::get( 'source_type' ) && ! empty( $health['sync_fail_since'] ) ) {
				$summary['suspended'] = 'sync-failing';
				$rows                 = array();
			}
			if ( $rows && LGTKS_Health::suspected_outage( $rows ) ) {
				$summary['suspended'] = 'suspected-outage';
				$rows                 = array(); // no employee/manager notifications this run
			}
			foreach ( $rows as $r ) {
				if ( 'due' !== $r['status'] ) {
					continue;
				}
				$target = $r['employee']['notify_target'];
				if ( 'none' === $target ) {
					continue; // excluded from notifications
				}
				if ( 'manager' !== $target && ! self::has_contact( $r['employee'] ) ) {
					$summary['no_contact'] = ( $summary['no_contact'] ?? 0 ) + 1;
					continue; // nothing to send to (e.g. auto-created from eVardia without a mobile yet)
				}
				$summary['due']++;
				$sent_rounds = array();
				foreach ( $r['sms'] as $n ) {
					$is_emp = in_array( $n['channel'], array( 'sms', 'email' ), true );
					$rnd    = (int) $n['round'];
					if ( 5 === $rnd ) {
						continue; // clock-out reminder is tracked separately
					}
					if ( 3 === $rnd && ! $is_emp ) {
						$sent_rounds[3] = true; // escalation already sent today (sent or failed: do not retry every 5')
					} elseif ( $is_emp && ( 'sent' === $n['status'] || 'failed' === $n['status'] ) && ! isset( $sent_rounds[ $rnd ] ) ) {
						// one attempt per day; a failed SMS is not retried automatically (visible on the dashboard)
						$sent_rounds[ $rnd ] = true;
					}
				}
				// Escalation to the manager: still not clocked in after N minutes, once per day.
				if ( LGTKS_Settings::managers_enabled() && $escal > 0 && in_array( $target, array( 'both', 'manager' ), true ) && (int) $r['late_min'] >= $escal && ! isset( $sent_rounds[3] ) ) {
					self::escalate( $r );
					$summary['escalated'] = ( $summary['escalated'] ?? 0 ) + 1;
				}
				if ( 'manager' === $target ) {
					continue; // the employee is not notified; managers get the digest/escalation
				}
				if ( ! empty( $sent_rounds ) ) {
					continue; // exactly one notification per employee per day
				}
				$ok = self::notify( $r, 1 );
				if ( $ok ) {
					$summary['sent']++;
				} else {
					$summary['failed']++;
				}
			}
			$summary['out'] = self::run_out_reminders( $rows );
			$summary['double'] = self::check_double_clockins( $day );
			$summary['batch']  = self::flush_punch_queue();
			$summary['digest'] = self::run_digest( $rows );
		} finally {
			delete_transient( 'lgtks_running' );
			self::$dry = false;
		}
		update_option( 'lgt_ks_last_run', array( 'at' => LGTKS_Settings::now( 'Y-m-d H:i:s' ), 'summary' => $summary ) );
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
		if ( self::$dry && ! $force ) {
			return false;
		}
		$e       = $row['employee'];
		$day     = LGTKS_Settings::now( 'Y-m-d' );
		$vars    = self::vars( $row );
		$msg     = strtr( (string) LGTKS_Settings::get( 'message_template' ), $vars );
		$channel = isset( $e['notify_channel'] ) ? $e['notify_channel'] : 'sms';
		$target  = isset( $e['notify_target'] ) ? $e['notify_target'] : 'both';
		if ( $force && 'none' === $target ) {
			$target = 'both'; // manual "send now" overrides the exclusion
		}
		$to_emp = in_array( $target, array( 'both', 'employee' ), true );
		$to_mgr = LGTKS_Settings::managers_enabled() && in_array( $target, array( 'both', 'manager' ), true );
		$ok_any = false;
		$res    = array( 'ok' => false, 'response' => $to_emp ? 'δεν στάλθηκε SMS (κανάλι: ' . $channel . ')' : 'ο εργαζόμενος δεν ειδοποιείται (μόνο υπεύθυνος)' );
		if ( $to_emp && in_array( $channel, array( 'sms', 'both' ), true ) ) {
			$res = LGTKS_SMS::send( $e['mobile'], $msg );
			LGTKS_DB::add_notification( $e['id'], $day, $round, 'sms', $e['mobile'], $msg, $res['ok'] ? 'sent' : 'failed', $res['response'] );
			if ( ! $res['ok'] ) {
				LGTKS_DB::log( 'error', 'Αποτυχία SMS σε ' . $e['name'] . ' (' . $e['mobile'] . ')', $res['response'] );
			}
			$ok_any = $ok_any || $res['ok'];
		}
		if ( $to_emp && in_array( $channel, array( 'email', 'both' ), true ) ) {
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
		$mgr_sent = false;
		if ( $to_mgr && ( $force || LGTKS_Settings::get( 'manager_per_employee' ) ) && ( 1 === (int) $round || $force || ! $to_emp ) ) {
			$mmsg = strtr( (string) LGTKS_Settings::get( 'manager_message_template' ), $vars );
			$mch  = LGTKS_Settings::manager_channel();
			if ( ! $to_emp && 'none' === $mch ) {
				// Manager-only employee but managers have no automatic channel: fall back to email so someone is told.
				$mch = 'email';
			}
			if ( in_array( $mch, array( 'both', 'sms' ), true ) ) {
				foreach ( LGTKS_Settings::manager_mobiles() as $m ) {
					$mr = LGTKS_SMS::send( $m, $mmsg );
					LGTKS_DB::add_notification( $e['id'], $day, $round, 'manager', $m, $mmsg, $mr['ok'] ? 'sent' : 'failed', $mr['response'] );
					$mgr_sent = $mgr_sent || $mr['ok'];
				}
			}
			if ( in_array( $mch, array( 'both', 'email' ), true ) ) {
				foreach ( LGTKS_Settings::manager_emails() as $email ) {
					$sent = wp_mail( $email, '[Κάρτα εργασίας] ' . $e['name'] . ' – δεν χτύπησε κάρτα', $mmsg . "\n\nΕιδοποίηση εργαζομένου (" . $channel . '): ' . ( $ok_any ? 'εστάλη' : 'ΑΠΕΤΥΧΕ – ' . $res['response'] ) );
					LGTKS_DB::add_notification( $e['id'], $day, $round, 'manager_email', $email, $mmsg, $sent ? 'sent' : 'failed', $sent ? 'wp_mail OK' : 'wp_mail απέτυχε' );
					$mgr_sent = $mgr_sent || $sent;
				}
			}
		}
		return $to_emp ? $ok_any : $mgr_sent;
	}

	/**
	 * Receipt to the employee themself: "your clock-in/out was recorded at HH:MM".
	 * Only for employees whose channel includes email and who are notified at all.
	 */
	public static function email_receipts( array $punches ) {
		if ( self::$dry || ! LGTKS_Settings::get( 'receipt_email', 1 ) ) {
			return 0;
		}
		$kinds   = (string) LGTKS_Settings::get( 'receipt_email_kinds', 'all' );
		$day     = LGTKS_Settings::now( 'Y-m-d' );
		$company = (string) LGTKS_Settings::get( 'company_name' );
		$sent    = 0;
		foreach ( $punches as $p ) {
			if ( 'in' === $kinds && 'in' !== $p['kind'] ) {
				continue;
			}
			$e = LGTKS_DB::employee( $p['employee_id'] );
			if ( ! $e || ! in_array( $e['notify_target'], array( 'both', 'employee' ), true ) ) {
				continue;
			}
			if ( ! in_array( $e['notify_channel'], array( 'email', 'both' ), true ) ) {
				continue;
			}
			$to = trim( (string) $e['email'] );
			if ( ! is_email( $to ) ) {
				continue;
			}
			$ptime = substr( $p['punched_at'], 11, 5 );
			$first = LGTKS_Settings::first_name( $e['name'] );
			$vars  = array( '{name}' => $e['name'], '{first_name}' => $first, '{punch_time}' => $ptime, '{date}' => LGTKS_Settings::fmt( 'd/m/Y', strtotime( $day ) ), '{company}' => $company );
			$is_in = 'in' === $p['kind'];
			$subj  = strtr( (string) LGTKS_Settings::get( $is_in ? 'receipt_subject_in' : 'receipt_subject_out' ), $vars );
			$body  = 'Γεια σου ' . $first . ",\n\n" . ( $is_in ? 'η προσέλευσή σου' : 'η αποχώρησή σου' ) . ' καταγράφηκε κανονικά στην ψηφιακή κάρτα εργασίας.' . "\n\nΗμερομηνία: " . $vars['{date}'] . "\nΏρα: " . $ptime . "\n\nΑν δεν χτύπησες εσύ κάρτα αυτή την ώρα, ενημέρωσε τον υπεύθυνό σου.\n\n" . $company;
			$ok    = wp_mail( $to, $subj, $body );
			LGTKS_DB::add_notification( $e['id'], $day, 1, 'receipt_email', $to, $subj, $ok ? 'sent' : 'failed', $ok ? 'wp_mail OK' : 'wp_mail απέτυχε' );
			$sent += $ok ? 1 : 0;
		}
		return $sent;
	}

	/** Batch mode: send the queued punches as one email once the oldest is older than N minutes. */
	public static function flush_punch_queue( $force = false ) {
		if ( self::$dry || ! LGTKS_Settings::managers_enabled() ) {
			return 0;
		}
		$q = get_option( 'lgt_ks_punch_queue', array() );
		if ( ! is_array( $q ) || ! $q ) {
			return 0;
		}
		$wait = max( 5, (int) LGTKS_Settings::get( 'clockin_batch_minutes', 60 ) );
		if ( ! $force && time() - (int) $q[0]['queued'] < $wait * 60 ) {
			return 0;
		}
		$to = LGTKS_Settings::clockin_emails();
		update_option( 'lgt_ks_punch_queue', array(), false );
		if ( ! $to ) {
			return 0;
		}
		$lines = array();
		foreach ( $q as $it ) {
			$lines[] = '• ' . $it['time'] . ' ' . $it['name'] . ' – ' . $it['kind'] . ( $it['start'] ? ' (βάρδια ' . $it['start'] . ( $it['delay'] ? ', ' . $it['delay'] : '' ) . ')' : ' (χωρίς βάρδια)' );
		}
		$subj = sprintf( 'Χτυπήματα κάρτας %s–%s: %d', $q[0]['at'], end( $q )['at'], count( $q ) );
		$body = 'Χτυπήματα κάρτας – ' . LGTKS_Settings::now( 'd/m/Y' ) . "\n\n" . implode( "\n", $lines ) . "\n\n" . (string) LGTKS_Settings::get( 'company_name' );
		$sent = 0;
		foreach ( $to as $addr ) {
			$ok = wp_mail( $addr, $subj, $body );
			LGTKS_DB::add_notification( 0, LGTKS_Settings::now( 'Y-m-d' ), 1, 'punch_email', $addr, $subj, $ok ? 'sent' : 'failed', $ok ? 'wp_mail OK' : 'wp_mail απέτυχε' );
			$sent += $ok ? 1 : 0;
		}
		return $sent;
	}

	/**
	 * Detect a second clock-in without a clock-out in between (or more clock-ins than scheduled shifts)
	 * and email the managers once per employee per day.
	 */
	public static function check_double_clockins( $day ) {
		if ( self::$dry || ! LGTKS_Settings::get( 'anomaly_email', 1 ) ) {
			return 0;
		}
		$mgr = LGTKS_Settings::managers_enabled();
		$to  = $mgr ? LGTKS_Settings::clockin_emails() : array();
		if ( $mgr && ! $to ) {
			return 0;
		}
		$ev   = ( 'evardia' === LGTKS_Settings::get( 'source_type' ) ) ? LGTKS_Evardia::today_rows( $day ) : array();
		$sent = 0;
		foreach ( LGTKS_DB::punch_list_for_day( $day ) as $emp_id => $list ) {
			$ins       = array();
			$double    = array();
			$open_in   = null;
			foreach ( $list as $p ) {
				if ( 'in' === $p['kind'] ) {
					$ins[] = substr( $p['punched_at'], 11, 5 );
					if ( null !== $open_in ) {
						$double[] = $open_in . ' → ' . substr( $p['punched_at'], 11, 5 );
					}
					$open_in = substr( $p['punched_at'], 11, 5 );
				} else {
					$open_in = null;
				}
			}
			$shifts = isset( $ev[ $emp_id ] ) ? count( array_filter( $ev[ $emp_id ]['shifts'], function ( $s ) { return '' !== $s['start']; } ) ) : 1;
			if ( ! $double && count( $ins ) <= max( 1, $shifts ) ) {
				continue;
			}
			$e = LGTKS_DB::employee( $emp_id );
			if ( ! $e ) {
				continue;
			}
			if ( $mgr && ! in_array( $e['notify_target'], array( 'both', 'manager' ), true ) ) {
				continue;
			}
			if ( ! $mgr ) {
				// Employee-only policy: warn the employee themself by email (if their channel includes email).
				if ( ! in_array( $e['notify_target'], array( 'both', 'employee' ), true ) || ! in_array( $e['notify_channel'], array( 'email', 'both' ), true ) || ! is_email( trim( (string) $e['email'] ) ) ) {
					continue;
				}
				$to = array( trim( (string) $e['email'] ) );
			}
			if ( LGTKS_DB::has_notification( $emp_id, $day, 'anomaly_email' ) ) {
				continue;
			}
			$why  = $double ? 'Δεύτερη προσέλευση χωρίς ενδιάμεση αποχώρηση: ' . implode( ', ', $double ) : sprintf( '%d προσελεύσεις ενώ οι βάρδιες της ημέρας είναι %d', count( $ins ), $shifts );
			if ( $mgr ) {
				$subj = 'Διπλή προσέλευση: ' . $e['name'] . ' (' . implode( ', ', $ins ) . ')';
				$body = "Πιθανό διπλό χτύπημα προσέλευσης\n\nΕργαζόμενος: " . $e['name'] . "\nΗμερομηνία: " . LGTKS_Settings::fmt( 'd/m/Y', strtotime( $day ) ) . "\nΠροσελεύσεις: " . implode( ', ', $ins ) . "\n" . $why . "\n\nΕλέγξτε την εγγραφή στο eVardia / ΕΡΓΑΝΗ και διορθώστε αν χρειάζεται.\n\n" . (string) LGTKS_Settings::get( 'company_name' );
			} else {
				$subj = 'Προσοχή: διπλή προσέλευση στην κάρτα σου (' . implode( ', ', $ins ) . ')';
				$body = 'Γεια σου ' . LGTKS_Settings::first_name( $e['name'] ) . ",\n\nσήμερα " . LGTKS_Settings::fmt( 'd/m/Y', strtotime( $day ) ) . ' καταγράφηκαν δύο χτυπήματα προσέλευσης στην κάρτα σου: ' . implode( ' και ', $ins ) . ".\n" . $why . ".\n\nΑν έγινε κατά λάθος, ενημέρωσε τον υπεύθυνό σου για να διορθωθεί.\n\n" . (string) LGTKS_Settings::get( 'company_name' );
			}
			foreach ( $to as $addr ) {
				$ok = wp_mail( $addr, $subj, $body );
				LGTKS_DB::add_notification( $emp_id, $day, 1, 'anomaly_email', $addr, $subj . ' – ' . $why, $ok ? 'sent' : 'failed', $ok ? 'wp_mail OK' : 'wp_mail απέτυχε' );
				$sent += $ok ? 1 : 0;
			}
			if ( ! $to ) {
				LGTKS_DB::add_notification( $emp_id, $day, 1, 'anomaly_email', '', $subj, 'failed', 'χωρίς παραλήπτες' );
			}
		}
		return $sent;
	}

	/**
	 * Email the managers about new punches. $punches = list of {employee_id, punched_at, kind, source}.
	 * One email per punch; above 5 punches in one batch a single combined email is sent instead.
	 */
	public static function email_new_punches( array $punches ) {
		if ( self::$dry || ! $punches ) {
			return 0;
		}
		self::email_receipts( $punches );
		if ( ! LGTKS_Settings::managers_enabled() || ! LGTKS_Settings::get( 'clockin_email' ) ) {
			return 0;
		}
		$kinds = (string) LGTKS_Settings::get( 'clockin_email_kinds', 'in' );
		$to    = LGTKS_Settings::clockin_emails();
		if ( ! $to ) {
			return 0;
		}
		$day  = LGTKS_Settings::now( 'Y-m-d' );
		$ev   = ( 'evardia' === LGTKS_Settings::get( 'source_type' ) ) ? LGTKS_Evardia::today_rows( $day ) : array();
		$tz   = LGTKS_Settings::tz();
		$now  = new DateTime( 'now', $tz );
		$items = array();
		foreach ( $punches as $p ) {
			if ( 'in' === $kinds && 'in' !== $p['kind'] ) {
				continue;
			}
			$e = LGTKS_DB::employee( $p['employee_id'] );
			if ( ! $e || ! in_array( $e['notify_target'], array( 'both', 'manager' ), true ) ) {
				continue; // excluded from manager notifications
			}
			$start = '';
			if ( isset( $ev[ $e['id'] ] ) ) {
				$sh    = LGTKS_Evardia::relevant_shift( $ev[ $e['id'] ], new DateTime( $p['punched_at'], $tz ) );
				$start = $sh ? $sh['start'] : '';
			} else {
				$dow   = (int) $now->format( 'N' );
				$start = isset( $e['schedule'][ $dow ] ) ? $e['schedule'][ $dow ] : '';
			}
			$ptime = substr( $p['punched_at'], 11, 5 );
			$delay = '';
			$late  = 0;
			if ( 'in' === $p['kind'] && '' !== $start ) {
				$diff  = (int) round( ( strtotime( $day . ' ' . $ptime ) - strtotime( $day . ' ' . $start ) ) / 60 );
				$late  = $diff;
				$delay = $diff > 0 ? '+' . $diff . '′ καθυστέρηση' : ( $diff < 0 ? abs( $diff ) . '′ νωρίτερα' : 'στην ώρα του' );
			}
			$items[] = array(
				'e'     => $e,
				'time'  => $ptime,
				'kind'  => 'in' === $p['kind'] ? 'Προσέλευση' : 'Αποχώρηση',
				'start' => $start,
				'delay' => $delay,
				'late'  => $late,
				'src'   => $p['source'],
			);
		}
		$mode = (string) LGTKS_Settings::get( 'clockin_email_mode', 'exceptions' );
		if ( 'exceptions' === $mode ) {
			$thr   = (int) LGTKS_Settings::get( 'clockin_late_threshold', 15 );
			$items = array_values(
				array_filter(
					$items,
					function ( $it ) use ( $thr ) {
						if ( 'Προσέλευση' !== $it['kind'] ) {
							return false; // clock-outs are never exceptions here
						}
						if ( '' === $it['start'] ) {
							$it['note'] = 'χωρίς βάρδια στο eVardia σήμερα';
							return true; // punched without a scheduled shift
						}
						return (int) $it['late'] > $thr;
					}
				)
			);
		}
		if ( ! $items ) {
			return 0;
		}
		if ( 'batch' === $mode ) {
			$q = get_option( 'lgt_ks_punch_queue', array() );
			if ( ! is_array( $q ) ) {
				$q = array();
			}
			foreach ( $items as $it ) {
				$q[] = array( 'at' => LGTKS_Settings::now( 'H:i' ), 'name' => $it['e']['name'], 'kind' => $it['kind'], 'time' => $it['time'], 'start' => $it['start'], 'delay' => $it['delay'], 'queued' => time() );
			}
			update_option( 'lgt_ks_punch_queue', $q, false );
			return 0; // flushed by flush_punch_queue() from run()
		}
		$company = (string) LGTKS_Settings::get( 'company_name' );
		$sent    = 0;
		if ( count( $items ) > 5 ) {
			$lines = array();
			foreach ( $items as $it ) {
				$lines[] = '• ' . $it['e']['name'] . ' – ' . $it['kind'] . ' ' . $it['time'] . ( $it['start'] ? ' (βάρδια ' . $it['start'] . ( $it['delay'] ? ', ' . $it['delay'] : '' ) . ')' : '' );
			}
			$subj = sprintf( 'Χτυπήματα κάρτας: %d νέα (%s)', count( $items ), LGTKS_Settings::now( 'H:i' ) );
			$body = 'Νέα χτυπήματα κάρτας – ' . LGTKS_Settings::now( 'd/m/Y H:i' ) . "\n\n" . implode( "\n", $lines ) . "\n\n" . $company;
			foreach ( $to as $addr ) {
				$ok = wp_mail( $addr, $subj, $body );
				LGTKS_DB::add_notification( 0, $day, 1, 'punch_email', $addr, $subj, $ok ? 'sent' : 'failed', $ok ? 'wp_mail OK' : 'wp_mail απέτυχε' );
				$sent += $ok ? 1 : 0;
			}
			return $sent;
		}
		foreach ( $items as $it ) {
			$vars = array(
				'{name}'       => $it['e']['name'],
				'{first_name}' => LGTKS_Settings::first_name( $it['e']['name'] ),
				'{punch_time}' => $it['time'],
				'{kind}'       => $it['kind'],
				'{time}'       => $it['start'],
				'{delay}'      => $it['delay'],
				'{date}'       => LGTKS_Settings::now( 'd/m/Y' ),
				'{company}'    => $company,
			);
			$subj = strtr( (string) LGTKS_Settings::get( 'clockin_email_subject' ), $vars );
			if ( 'exceptions' === $mode ) {
				$subj = ( '' === $it['start'] ? 'Χτύπημα χωρίς βάρδια: ' : 'Καθυστερημένη προσέλευση: ' ) . $it['e']['name'] . ' ' . $it['time'] . ( $it['delay'] ? ' (' . $it['delay'] . ')' : '' );
			}
			$body = $it['kind'] . ' κάρτας εργασίας' . "\n\n" . 'Εργαζόμενος: ' . $it['e']['name'] . "\n" . 'Ώρα χτυπήματος: ' . $it['time'] . ' (' . LGTKS_Settings::now( 'd/m/Y' ) . ")\n" . ( $it['start'] ? 'Ώρα βάρδιας: ' . $it['start'] . "\n" : '' ) . ( $it['delay'] ? 'Καθυστέρηση: ' . $it['delay'] . "\n" : '' ) . ( $it['e']['external_id'] ? 'ΑΦΜ/Κωδικός: ' . $it['e']['external_id'] . "\n" : '' ) . 'Πηγή: ' . ( 'evardia' === $it['src'] ? 'eVardia' : $it['src'] ) . "\n\n" . $company;
			foreach ( $to as $addr ) {
				$ok = wp_mail( $addr, $subj, $body );
				LGTKS_DB::add_notification( $it['e']['id'], $day, 1, 'punch_email', $addr, $subj, $ok ? 'sent' : 'failed', $ok ? 'wp_mail OK' : 'wp_mail απέτυχε' );
				$sent += $ok ? 1 : 0;
			}
		}
		return $sent;
	}

	/**
	 * Clock-out reminder: shift ended (per eVardia) + grace, employee clocked in but no clock-out yet.
	 * Exactly once per employee per day (round 5).
	 */
	public static function run_out_reminders( array $rows ) {
		if ( self::$dry || ! LGTKS_Settings::get( 'out_reminder', 1 ) ) {
			return 0;
		}
		$grace = (int) LGTKS_Settings::get( 'out_grace_minutes', 20 );
		$maxd  = (int) LGTKS_Settings::get( 'out_max_delay_minutes', 180 );
		$day   = LGTKS_Settings::now( 'Y-m-d' );
		$sent  = 0;
		foreach ( $rows as $r ) {
			$e = $r['employee'];
			if ( 'present' !== $r['status'] || '' === $r['end'] || $r['out_at'] ) {
				continue;
			}
			if ( $r['out_late'] < $grace || $r['out_late'] > $maxd ) {
				continue;
			}
			if ( ! in_array( $e['notify_target'], array( 'both', 'employee' ), true ) || ! self::has_contact( $e ) ) {
				continue;
			}
			$already = false;
			foreach ( $r['sms'] as $n ) {
				if ( 5 === (int) $n['round'] ) {
					$already = true;
					break;
				}
			}
			if ( $already ) {
				continue;
			}
			$vars        = self::vars( $r );
			$vars['{end}'] = $r['end'];
			$vars['{minutes}'] = max( 0, (int) $r['out_late'] );
			$msg = strtr( (string) LGTKS_Settings::get( 'out_message_template' ), $vars );
			if ( self::send_to_employee( $e, $msg, 5, 'Υπενθύμιση: χτύπημα αποχώρησης' ) ) {
				$sent++;
			}
		}
		return $sent;
	}

	/** Send a message to the employee through their channel; records notifications with the given round. */
	public static function send_to_employee( array $e, $msg, $round, $subject ) {
		$day     = LGTKS_Settings::now( 'Y-m-d' );
		$channel = isset( $e['notify_channel'] ) ? $e['notify_channel'] : 'sms';
		$ok_any  = false;
		if ( in_array( $channel, array( 'sms', 'both' ), true ) && '' !== trim( (string) $e['mobile'] ) ) {
			$res = LGTKS_SMS::send( $e['mobile'], $msg );
			LGTKS_DB::add_notification( $e['id'], $day, $round, 'sms', $e['mobile'], $msg, $res['ok'] ? 'sent' : 'failed', $res['response'] );
			$ok_any = $ok_any || $res['ok'];
		}
		if ( in_array( $channel, array( 'email', 'both' ), true ) && is_email( trim( (string) $e['email'] ) ) ) {
			$ok = wp_mail( trim( $e['email'] ), $subject, $msg );
			LGTKS_DB::add_notification( $e['id'], $day, $round, 'email', trim( $e['email'] ), $msg, $ok ? 'sent' : 'failed', $ok ? 'wp_mail OK' : 'wp_mail απέτυχε' );
			$ok_any = $ok_any || $ok;
		}
		return $ok_any;
	}

	/** Immediate manager alert for a long delay (round 3, once per day per employee). */
	public static function escalate( array $row ) {
		if ( self::$dry ) {
			return;
		}
		$e    = $row['employee'];
		$day  = LGTKS_Settings::now( 'Y-m-d' );
		$vars = self::vars( $row );
		$msg  = strtr( (string) LGTKS_Settings::get( 'manager_escalation_template' ), $vars );
		$mch  = LGTKS_Settings::manager_channel();
		if ( 'none' === $mch ) {
			$mch = 'email';
		}
		$any = false;
		if ( in_array( $mch, array( 'both', 'sms' ), true ) ) {
			foreach ( LGTKS_Settings::manager_mobiles() as $m ) {
				$r = LGTKS_SMS::send( $m, $msg );
				LGTKS_DB::add_notification( $e['id'], $day, 3, 'manager', $m, $msg, $r['ok'] ? 'sent' : 'failed', $r['response'] );
				$any = true;
			}
		}
		if ( in_array( $mch, array( 'both', 'email' ), true ) ) {
			foreach ( LGTKS_Settings::manager_emails() as $to ) {
				$ok = wp_mail( $to, '[Κάρτα εργασίας] ΚΛΙΜΑΚΩΣΗ: ' . $e['name'], $msg );
				LGTKS_DB::add_notification( $e['id'], $day, 3, 'manager_email', $to, $msg, $ok ? 'sent' : 'failed', $ok ? 'wp_mail OK' : 'wp_mail απέτυχε' );
				$any = true;
			}
		}
		if ( ! $any ) {
			// No manager contact configured: record so we do not retry every 5 minutes.
			LGTKS_DB::add_notification( $e['id'], $day, 3, 'manager', '', $msg, 'failed', 'Δεν έχουν οριστεί κινητά/email υπευθύνων' );
		}
	}

	/**
	 * Digest to managers at the configured times (e.g. 11:00): one SMS + one email listing who has not
	 * clocked in yet. Sent once per time per day, only if someone is pending (unless 'all ok' is on).
	 */
	public static function run_digest( array $rows ) {
		if ( self::$dry || ! LGTKS_Settings::managers_enabled() ) {
			return null;
		}
		$times = LGTKS_Settings::digest_times();
		if ( ! $times || 'none' === LGTKS_Settings::manager_channel() ) {
			return null;
		}
		$day   = LGTKS_Settings::now( 'Y-m-d' );
		$now   = LGTKS_Settings::now( 'H:i' );
		$state = get_option( 'lgt_ks_digest_sent', array() );
		if ( ! is_array( $state ) || ( $state['day'] ?? '' ) !== $day ) {
			$state = array( 'day' => $day, 'times' => array() );
		}
		$result = null;
		foreach ( $times as $t ) {
			if ( $now < $t || isset( $state['times'][ $t ] ) ) {
				continue;
			}
			// Do not send a stale digest hours later (e.g. cron was down); 2-hour window.
			$limit = date( 'H:i', strtotime( $day . ' ' . $t ) + 2 * HOUR_IN_SECONDS );
			if ( $now > $limit && $limit > $t ) {
				$state['times'][ $t ] = 'skipped-late';
				continue;
			}
			$pending = array();
			foreach ( $rows as $r ) {
				if ( ! in_array( $r['status'], array( 'due', 'late_window_passed' ), true ) ) {
					continue;
				}
				if ( ! in_array( $r['employee']['notify_target'], array( 'both', 'manager' ), true ) ) {
					continue;
				}
				$pending[] = $r;
			}
			$state['times'][ $t ] = LGTKS_Settings::now( 'H:i:s' );
			if ( ! $pending && ! LGTKS_Settings::get( 'manager_digest_all_ok' ) ) {
				$result = array( 'time' => $t, 'pending' => 0, 'sent' => 'nothing-pending' );
				continue;
			}
			$mch  = LGTKS_Settings::manager_channel();
			$list = array();
			foreach ( $pending as $r ) {
				$parts   = preg_split( '/\s+/', trim( $r['employee']['name'] ) );
				$short   = count( $parts ) > 1 ? $parts[0] . ' ' . mb_substr( $parts[1], 0, 1 ) . '.' : $r['employee']['name'];
				$list[]  = $short . ' (' . $r['start'] . ', +' . max( 0, (int) $r['late_min'] ) . "')";
			}
			$sms_sent = 0;
			if ( $pending && in_array( $mch, array( 'both', 'sms' ), true ) ) {
				$text = strtr( (string) LGTKS_Settings::get( 'manager_digest_sms' ), array( '{now}' => $now, '{date}' => LGTKS_Settings::now( 'd/m' ), '{count}' => count( $pending ), '{list}' => implode( ', ', $list ), '{company}' => (string) LGTKS_Settings::get( 'company_name' ) ) );
				if ( mb_strlen( $text ) > 400 ) {
					$text = mb_substr( $text, 0, 397 ) . '…';
				}
				foreach ( LGTKS_Settings::manager_mobiles() as $m ) {
					$r = LGTKS_SMS::send( $m, $text );
					LGTKS_DB::add_notification( 0, $day, 1, 'manager_digest', $m, $text, $r['ok'] ? 'sent' : 'failed', $r['response'] );
					$sms_sent += $r['ok'] ? 1 : 0;
				}
			}
			$mail = array( 'sent' => 0 );
			if ( in_array( $mch, array( 'both', 'email' ), true ) ) {
				$mail = self::email_managers_summary( 'Συγκεντρωτική ' . $t, $rows );
			}
			$result = array( 'time' => $t, 'pending' => count( $pending ), 'sms' => $sms_sent, 'email' => $mail['sent'] );
			LGTKS_DB::log( 'info', 'Συγκεντρωτική ειδοποίηση υπευθύνων ' . $t, $result );
		}
		update_option( 'lgt_ks_digest_sent', $state, false );
		return $result;
	}

	/**
	 * Send the managers a summary email of today's status (manual button or on demand).
	 *
	 * @return array {sent:int, failed:int, due:int, error:string}
	 */
	public static function email_managers_summary( $tag = '', array $rows = null ) {
		if ( ! LGTKS_Settings::managers_enabled() ) {
			return array( 'sent' => 0, 'failed' => 0, 'due' => 0, 'error' => 'Οι ειδοποιήσεις υπευθύνων είναι απενεργοποιημένες (πολιτική: μόνο ο εργαζόμενος).' );
		}
		$emails = LGTKS_Settings::manager_emails();
		if ( ! $emails ) {
			return array( 'sent' => 0, 'failed' => 0, 'due' => 0, 'error' => 'Δεν έχουν οριστεί email υπευθύνων (Ρυθμίσεις → Email υπευθύνων).' );
		}
		$rows   = null === $rows ? static::status_for_day() : $rows;
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
				if ( 'none' === $e['notify_target'] ) {
					$line .= ' (εξαιρείται από ειδοποιήσεις)';
				} elseif ( $r['sms'] ) {
					$line .= ' (ειδοποιήθηκε ' . substr( end( $r['sms'] )['created_at'], 11, 5 ) . ')';
				}
			}
			$groups[ $k ][] = $line;
		}
		$now  = LGTKS_Settings::now( 'd/m/Y H:i' );
		$vars = array( '{date}' => LGTKS_Settings::now( 'd/m/Y' ), '{now}' => LGTKS_Settings::now( 'H:i' ), '{due}' => count( $groups['due'] ), '{company}' => (string) LGTKS_Settings::get( 'company_name' ) );
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
			LGTKS_DB::add_notification( 0, LGTKS_Settings::now( 'Y-m-d' ), 1, 'manager_summary', $to, ( $tag ? '[' . $tag . '] ' : '' ) . $subj, $ok ? 'sent' : 'failed', $ok ? 'wp_mail OK' : 'wp_mail απέτυχε (ελέγξτε SMTP)' );
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
		$tz    = LGTKS_Settings::tz();
		$now   = new DateTime( 'now', $tz );
		return array(
			'{name}'       => $e['name'],
			'{first_name}' => LGTKS_Settings::first_name( $e['name'] ),
			'{time}'       => $row['start'],
			'{date}'       => $now->format( 'd/m/Y' ),
			'{now}'        => $now->format( 'H:i' ),
			'{minutes}'    => max( 0, (int) $row['late_min'] ),
			'{company}'    => (string) LGTKS_Settings::get( 'company_name' ),
		);
	}
}
