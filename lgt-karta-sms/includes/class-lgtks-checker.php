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
		$tz    = LGTKS_Settings::tz();
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

		$day     = LGTKS_Settings::now( 'Y-m-d' );
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
			$now_ts = LGTKS_Settings::now_ts();
			$escal  = (int) LGTKS_Settings::get( 'manager_escalation_minutes', 60 );
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
					if ( 3 === $rnd && ! $is_emp ) {
						$sent_rounds[3] = true; // escalation already sent today (sent or failed: do not retry every 5')
					} elseif ( $is_emp && ( 'sent' === $n['status'] || 'failed' === $n['status'] ) && ! isset( $sent_rounds[ $rnd ] ) ) {
						// one attempt per day; a failed SMS is not retried automatically (visible on the dashboard)
						$sent_rounds[ $rnd ] = true;
					}
				}
				// Escalation to the manager: still not clocked in after N minutes, once per day.
				if ( $escal > 0 && in_array( $target, array( 'both', 'manager' ), true ) && (int) $r['late_min'] >= $escal && ! isset( $sent_rounds[3] ) ) {
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
			$summary['digest'] = self::run_digest( $rows );
		} finally {
			delete_transient( 'lgtks_running' );
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
		$to_mgr = in_array( $target, array( 'both', 'manager' ), true );
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

	/** Immediate manager alert for a long delay (round 3, once per day per employee). */
	public static function escalate( array $row ) {
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
		$parts = preg_split( '/\s+/', trim( $e['name'] ) );
		$tz    = LGTKS_Settings::tz();
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
