<?php
/**
 * Health watchdogs. Everything here goes ONLY to the technical contact email.
 *  1. eVardia sync failing for more than N minutes (e.g. password changed).
 *  2. The check has not run for more than N minutes (WP-Cron dead); evaluated on normal page loads.
 *  3. Suspected outage: nobody has clocked in while several shifts are already overdue
 *     -> employee notifications are suspended until the first punch appears.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LGTKS_Health {

	const OPTION = 'lgt_ks_health';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'passive_cron_check' ) );
	}

	public static function state() {
		$s = get_option( self::OPTION, array() );
		return is_array( $s ) ? $s : array();
	}

	private static function save( array $s ) {
		update_option( self::OPTION, $s, false );
	}

	public static function tech_email() {
		$e = trim( (string) LGTKS_Settings::get( 'tech_email' ) );
		return is_email( $e ) ? $e : '';
	}

	private static function mail( $subject, $body, $key ) {
		$to = self::tech_email();
		if ( '' === $to ) {
			LGTKS_DB::log( 'warning', 'Υγεία συστήματος: ' . $subject . ' (δεν έχει οριστεί email τεχνικού)' );
			return false;
		}
		$site = wp_parse_url( home_url(), PHP_URL_HOST );
		$ok   = wp_mail( $to, '[Κάρτα εργασίας – ' . $site . '] ' . $subject, $body . "\n\nΏρα: " . LGTKS_Settings::now( 'd/m/Y H:i' ) . "\nΔιαχείριση: " . admin_url( 'admin.php?page=lgt-karta-sms' ) );
		LGTKS_DB::add_notification( 0, LGTKS_Settings::now( 'Y-m-d' ), 1, 'tech', $to, $subject, $ok ? 'sent' : 'failed', $key );
		LGTKS_DB::log( $ok ? 'warning' : 'error', 'Υγεία συστήματος → τεχνικό: ' . $subject );
		return $ok;
	}

	/* ---------- 1. eVardia sync ---------- */

	public static function record_sync_result( $ok, $message = '' ) {
		$s   = self::state();
		$now = time();
		if ( $ok ) {
			if ( ! empty( $s['sync_alerted'] ) ) {
				self::mail( 'Η σύνδεση με το eVardia αποκαταστάθηκε', 'Ο συγχρονισμός με το eVardia λειτουργεί ξανά κανονικά.', 'sync-recovered' );
			}
			unset( $s['sync_fail_since'], $s['sync_alerted'], $s['sync_last_error'] );
			$s['sync_last_ok'] = $now;
			self::save( $s );
			return;
		}
		if ( empty( $s['sync_fail_since'] ) ) {
			$s['sync_fail_since'] = $now;
		}
		$s['sync_last_error'] = $message;
		$limit                = max( 5, (int) LGTKS_Settings::get( 'health_sync_minutes', 30 ) ) * 60;
		if ( empty( $s['sync_alerted'] ) && $now - (int) $s['sync_fail_since'] >= $limit ) {
			$s['sync_alerted'] = $now;
			self::mail(
				'Ο συγχρονισμός με το eVardia αποτυγχάνει εδώ και ' . round( ( $now - (int) $s['sync_fail_since'] ) / 60 ) . ' λεπτά',
				"Τελευταίο σφάλμα:\n" . $message . "\n\nΠιθανές αιτίες: άλλαξε ο κωδικός eVardia, άλλαξε η σελίδα του eVardia, πρόβλημα δικτύου. Όσο διαρκεί, δεν στέλνονται ειδοποιήσεις σε εργαζόμενους γιατί δεν υπάρχουν φρέσκα δεδομένα.\n\nΕλέγξτε: Ρυθμίσεις → Δοκιμή πηγής.",
				'sync-failing'
			);
		}
		self::save( $s );
	}

	/* ---------- 2. cron not running ---------- */

	public static function record_run() {
		$s = self::state();
		if ( ! empty( $s['cron_alerted'] ) ) {
			self::mail( 'Ο αυτόματος έλεγχος τρέχει ξανά', 'Ο έλεγχος κάθε 5 λεπτά εκτελέστηκε ξανά μετά από διακοπή.', 'cron-recovered' );
		}
		unset( $s['cron_alerted'] );
		$s['last_run'] = time();
		self::save( $s );
	}

	/** Runs on ordinary page loads (throttled): if the check has not run for too long, alert once. */
	public static function passive_cron_check() {
		if ( ! LGTKS_Settings::get( 'enabled' ) || get_transient( 'lgtks_health_tick' ) ) {
			return;
		}
		set_transient( 'lgtks_health_tick', 1, 10 * MINUTE_IN_SECONDS );
		$hour = (int) LGTKS_Settings::now( 'G' );
		if ( $hour < 7 || $hour >= 22 ) {
			return; // nothing is expected to happen at night
		}
		$s     = self::state();
		$last  = (int) ( $s['last_run'] ?? 0 );
		$limit = max( 10, (int) LGTKS_Settings::get( 'health_cron_minutes', 60 ) ) * 60;
		if ( $last && time() - $last > $limit && empty( $s['cron_alerted'] ) ) {
			$s['cron_alerted'] = time();
			self::save( $s );
			self::mail(
				'Ο αυτόματος έλεγχος δεν έχει τρέξει εδώ και ' . round( ( time() - $last ) / 60 ) . ' λεπτά',
				"Τελευταία εκτέλεση: " . LGTKS_Settings::fmt( 'd/m/Y H:i', $last ) . "\n\nΤο WP-Cron πιθανόν δεν εκτελείται. Λύση: εξωτερικό cron κάθε 5 λεπτά στο URL που φαίνεται στις Ρυθμίσεις → Αξιοπιστία ελέγχου. Μέχρι τότε δεν στέλνεται καμία ειδοποίηση.",
				'cron-dead'
			);
		}
	}

	/* ---------- 3. suspected outage ---------- */

	/**
	 * Decide whether today looks like an outage: no punch at all while >= N shifts are overdue.
	 * Returns true when employee notifications must be suspended for this run.
	 */
	public static function suspected_outage( array $rows ) {
		$min = (int) LGTKS_Settings::get( 'outage_min_due', 3 );
		if ( $min <= 0 ) {
			return false;
		}
		$present = 0;
		$due     = 0;
		foreach ( $rows as $r ) {
			if ( 'present' === $r['status'] ) {
				$present++;
			} elseif ( in_array( $r['status'], array( 'due', 'late_window_passed' ), true ) ) {
				$due++;
			}
		}
		$day = LGTKS_Settings::now( 'Y-m-d' );
		$s   = self::state();
		if ( $present > 0 || $due < $min ) {
			if ( ( $s['outage_day'] ?? '' ) === $day && empty( $s['outage_cleared'] ) && $present > 0 ) {
				$s['outage_cleared'] = time();
				self::save( $s );
				self::mail( 'Η ύποπτη βλάβη έληξε: εμφανίστηκαν χτυπήματα', 'Μετά την προειδοποίηση για πιθανή βλάβη, καταγράφηκαν χτυπήματα. Οι ειδοποιήσεις προς εργαζόμενους συνεχίζουν κανονικά για όσους εξακολουθούν να εκκρεμούν.', 'outage-cleared' );
			}
			return false;
		}
		if ( ( $s['outage_day'] ?? '' ) !== $day ) {
			$s['outage_day'] = $day;
			unset( $s['outage_cleared'] );
			self::save( $s );
			self::mail(
				'Ύποπτη βλάβη: κανένα χτύπημα ενώ εκκρεμούν ' . $due . ' βάρδιες',
				"Μέχρι τις " . LGTKS_Settings::now( 'H:i' ) . " δεν έχει καταγραφεί κανένα χτύπημα κάρτας, ενώ " . $due . " εργαζόμενοι έχουν ήδη περάσει την ώρα έναρξης. Αυτό συνήθως σημαίνει πρόβλημα στο eVardia, στο ρολόι ή στην ανάγνωση, όχι ότι άργησαν όλοι.\n\nΟι ειδοποιήσεις SMS/email προς εργαζόμενους και υπευθύνους ΑΝΑΣΤΕΛΛΟΝΤΑΙ αυτόματα μέχρι να εμφανιστεί το πρώτο χτύπημα· τότε συνεχίζουν κανονικά για όσους εξακολουθούν να εκκρεμούν.\n\nΑν πρόκειται για πραγματική ομαδική καθυστέρηση, πατήστε «Έλεγχος τώρα» αφού σημειώσετε χειροκίνητα ένα χτύπημα, ή μηδενίστε το όριο στις Ρυθμίσεις → Τεχνικός.",
				'outage'
			);
		}
		return true;
	}

	/** Short status lines for the dashboard. */
	public static function summary_lines() {
		$s   = self::state();
		$out = array();
		$out[] = 'Email τεχνικού: ' . ( self::tech_email() ? self::tech_email() : '— (ορίστε το στις Ρυθμίσεις → Τεχνικός)' );
		if ( ! empty( $s['sync_fail_since'] ) ) {
			$out[] = 'eVardia: ΑΠΟΤΥΧΙΑ από ' . LGTKS_Settings::fmt( 'H:i', $s['sync_fail_since'] ) . ( ! empty( $s['sync_alerted'] ) ? ' (ειδοποιήθηκε ο τεχνικός)' : '' );
		} elseif ( ! empty( $s['sync_last_ok'] ) ) {
			$out[] = 'eVardia: OK, τελευταία επιτυχία ' . LGTKS_Settings::fmt( 'H:i', $s['sync_last_ok'] );
		}
		if ( ! empty( $s['last_run'] ) ) {
			$age   = round( ( time() - (int) $s['last_run'] ) / 60 );
			$out[] = 'Τελευταίος έλεγχος πριν ' . $age . '′' . ( $age > 15 ? ' – ΠΡΟΣΟΧΗ, το cron μάλλον δεν τρέχει' : '' );
		}
		if ( ( $s['outage_day'] ?? '' ) === LGTKS_Settings::now( 'Y-m-d' ) && empty( $s['outage_cleared'] ) ) {
			$out[] = 'ΥΠΟΠΤΗ ΒΛΑΒΗ ΣΗΜΕΡΑ: οι ειδοποιήσεις αναστέλλονται μέχρι το πρώτο χτύπημα';
		}
		return $out;
	}
}
