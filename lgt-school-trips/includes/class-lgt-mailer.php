<?php
/**
 * Email notifications: submissions, "send now", link mail, reminders, daily digest.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LGT_Mailer {

	public static function from_name() {
		return LGT_Settings::get( 'from_name' ) ? LGT_Settings::get( 'from_name' ) : get_bloginfo( 'name' );
	}

	public static function from_email() {
		$e = LGT_Settings::get( 'from_email' );
		if ( $e && is_email( $e ) ) {
			return $e;
		}
		$host = preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		return 'noreply@' . ( $host ? $host : 'localhost' );
	}

	/** Send HTML mail with attachments and log the result. */
	public static function send( $trip_id, $kind, array $to, $subject, $html, array $attachments = array() ) {
		$to = array_values( array_unique( array_filter( $to, 'is_email' ) ) );
		if ( ! $to ) {
			LGT_DB::mail_log( $trip_id, $kind, array(), $subject, $attachments, false, 'Δεν υπάρχουν παραλήπτες.' );
			return false;
		}
		$headers = array( 'Content-Type: text/html; charset=UTF-8', 'From: ' . self::from_name() . ' <' . self::from_email() . '>' );
		if ( LGT_Settings::get( 'company_email' ) && is_email( LGT_Settings::get( 'company_email' ) ) ) {
			$headers[] = 'Reply-To: ' . LGT_Settings::get( 'company_email' );
		}
		$error = '';
		$catch = function ( $wp_error ) use ( &$error ) {
			$error = $wp_error->get_error_message();
		};
		add_action( 'wp_mail_failed', $catch );
		$ok = wp_mail( $to, $subject, self::wrap( $html ), $headers, array_filter( $attachments, 'file_exists' ) );
		remove_action( 'wp_mail_failed', $catch );
		LGT_DB::mail_log( $trip_id, $kind, $to, $subject, $attachments, $ok, $error );
		return $ok;
	}

	private static function wrap( $inner ) {
		$company = esc_html( LGT_Settings::get( 'company_name' ) );
		return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f4efe8;font-family:Arial,Helvetica,sans-serif;color:#1f2933;">'
			. '<div style="max-width:680px;margin:0 auto;padding:24px;">'
			. '<div style="background:#0f766e;color:#fff;padding:16px 22px;border-radius:12px 12px 0 0;font-size:18px;font-weight:bold;">' . $company . ' – Σχολικές Εκδρομές</div>'
			. '<div style="background:#fffaf4;padding:22px;border-radius:0 0 12px 12px;line-height:1.55;font-size:14px;border:1px solid #d9cbb8;border-top:0;">' . $inner . '</div>'
			. '<p style="color:#52606d;font-size:11px;margin-top:14px;">Αυτό το μήνυμα στάλθηκε αυτόματα από την online φόρμα σχολικών εκδρομών της ' . $company . '.</p>'
			. '</div></body></html>';
	}

	/* ------------------------------------------------------------------ */
	/* Content helpers                                                    */
	/* ------------------------------------------------------------------ */

	public static function trip_block( array $trip, array $summary ) {
		$rows = array(
			'Σχολείο'     => $trip['school_name'],
			'Εκδρομή'     => $trip['title'],
			'Προορισμός'  => $trip['destination'],
			'Ημερομηνίες' => LGT_Exporter::fmt_date( $trip['departure_date'] ) . ( $trip['return_date'] ? ' – ' . LGT_Exporter::fmt_date( $trip['return_date'] ) : '' ),
			'Ξενοδοχείο'  => $trip['has_hotel'] ? ( $trip['rooming']['meta']['hotelName'] ?: ( $trip['hotel_name'] ?: '—' ) ) : null,
			'Πλοίο'       => $trip['has_ferry'] ? ( $trip['ferry_company'] ?: 'Ναι' ) : null,
			'Υπεύθυνος'   => trim( $trip['contact_name'] . ' ' . $trip['school_phone'] . ' ' . $trip['school_email'] ),
			'Ονόματα'     => $summary['names'] . ( $summary['no_dob'] ? ' <span style="color:#b45309">(' . $summary['no_dob'] . ' χωρίς ημ. γέννησης)</span>' : '' ) . ( $summary['bad_dates'] ? ' <strong style="color:#b91c1c">(' . $summary['bad_dates'] . ' με λάθος ημερομηνία)</strong>' : '' ),
		);
		if ( $trip['has_hotel'] ) {
			$rows['Δωμάτια'] = self::summary_str( $summary['rooming'] );
		}
		if ( $trip['has_ferry'] && $summary['cabins'] ) {
			$rows['Καμπίνες'] = self::summary_str( $summary['cabins'] );
		}
		$html = '<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:13px;">';
		foreach ( $rows as $k => $v ) {
			if ( null === $v ) {
				continue;
			}
			$html .= '<tr><td style="border-bottom:1px solid #e5e7eb;color:#6b7280;width:150px;">' . esc_html( $k ) . '</td><td style="border-bottom:1px solid #e5e7eb;">' . ( in_array( $k, array( 'Ονόματα', 'Δωμάτια', 'Καμπίνες' ), true ) ? wp_kses_post( $v ) : esc_html( $v ) ) . '</td></tr>';
		}
		return $html . '</table>';
	}

	private static function summary_str( array $s ) {
		$parts = array();
		foreach ( $s['per_type'] as $label => $n ) {
			if ( $n ) {
				$parts[] = $n . ' ' . mb_strtolower( $label );
			}
		}
		$txt = $s['rooms'] . ( $parts ? ' (' . implode( ', ', $parts ) . ')' : '' ) . ' – ' . $s['names'] . ' ονόματα';
		if ( $s['empty_beds'] ) {
			$txt .= ' <span style="color:#b45309">– ' . $s['empty_beds'] . ' κενές θέσεις</span>';
		}
		return $txt;
	}

	public static function issues_block( array $trip, array $summary ) {
		$items = array();
		if ( $summary['incomplete'] ) {
			$items[] = $summary['incomplete'] . ' γραμμές χωρίς επώνυμο ή όνομα';
		}
		if ( $summary['bad_dates'] ) {
			$items[] = $summary['bad_dates'] . ' ημερομηνίες γέννησης σε λάθος μορφή';
		}
		if ( $trip['has_hotel'] && ! empty( $summary['rooming']['empty_beds'] ) ) {
			$items[] = $summary['rooming']['empty_beds'] . ' κενές θέσεις σε δωμάτια';
		}
		if ( $trip['has_hotel'] && $summary['names'] && $summary['rooming']['names'] < $summary['names'] ) {
			$items[] = ( $summary['names'] - $summary['rooming']['names'] ) . ' ονόματα λιγότερα στη rooming list από τη φόρμα ονομάτων';
		}
		if ( ! $items ) {
			return '';
		}
		$html = '<h3 style="margin:18px 0 6px;font-size:14px;">Προσοχή</h3><ul style="margin:0;padding-left:18px;font-size:13px;">';
		foreach ( $items as $it ) {
			$html .= '<li>' . esc_html( $it ) . '</li>';
		}
		return $html . '</ul>';
	}

	public static function button( $url, $label ) {
		return '<p style="margin:18px 0;"><a href="' . esc_url( $url ) . '" style="background:#0f766e;color:#fff;text-decoration:none;padding:12px 20px;border-radius:999px;display:inline-block;font-weight:bold;">' . esc_html( $label ) . '</a></p>';
	}

	public static function admin_url_for( $trip_id ) {
		return admin_url( 'admin.php?page=lgt-trip&id=' . (int) $trip_id . '&tab=manage' );
	}

	public static function build_attachments( array $trip, $force = false ) {
		$files = array();
		if ( $force || LGT_Settings::get( 'attach_xlsx' ) ) {
			$f = LGT_Exporter::build_xlsx( $trip, 'all' );
			if ( $f ) {
				$files[] = $f;
			}
		}
		if ( $force || LGT_Settings::get( 'attach_pdf' ) ) {
			$f = LGT_Exporter::build_pdf( $trip, 'all' );
			if ( $f ) {
				$files[] = $f;
			}
		}
		return $files;
	}

	private static function cleanup_files( array $files ) {
		foreach ( $files as $f ) {
			@unlink( $f );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Scenarios                                                          */
	/* ------------------------------------------------------------------ */

	/** School pressed "Υποβολή" (or the office pressed "send now"). */
	public static function send_submission( array $trip, $message = '', $by_admin = false ) {
		$summary = LGT_Data::summary( $trip );
		$files   = self::build_attachments( $trip );
		$subject = sprintf( '[Σχολικές Εκδρομές] %s – %s – %s', $by_admin ? 'Φόρμα & rooming list' : 'Υποβολή φόρμας', $trip['school_name'], $trip['title'] );
		$html    = '<h2 style="margin:0 0 12px;font-size:17px;">' . ( $by_admin ? 'Φόρμα & rooming list εκδρομής' : 'Νέα υποβολή από το σχολείο' ) . '</h2>';
		if ( $message ) {
			$html .= '<div style="background:#fef3c7;border-left:4px solid #f59e0b;padding:10px 12px;margin-bottom:14px;"><strong>Μήνυμα από το σχολείο:</strong><br>' . nl2br( esc_html( $message ) ) . '</div>';
		}
		$html .= self::trip_block( $trip, $summary );
		$html .= self::issues_block( $trip, $summary );
		$html .= '<p style="margin-top:14px;">Επισυνάπτονται τα αρχεία Excel και PDF (φόρμα ονομάτων' . ( $trip['has_hotel'] ? ', rooming list' : '' ) . ( $trip['has_ferry'] ? ', καμπίνες' : '' ) . ').</p>';
		$html .= self::button( self::admin_url_for( $trip['id'] ), 'Προβολή online στη διαχείριση' );
		$ok = self::send( $trip['id'], $by_admin ? 'send_now' : 'submission', LGT_Settings::office_emails(), $subject, $html, $files );

		if ( ! $by_admin && $trip['school_email'] && is_email( $trip['school_email'] ) ) {
			$pdf     = LGT_Exporter::build_pdf( $trip, 'all' );
			$s_html  = '<h2 style="margin:0 0 12px;font-size:17px;">Λάβαμε τη φόρμα σας – ευχαριστούμε!</h2>';
			$s_html .= '<p>Η φόρμα για την εκδρομή <strong>' . esc_html( $trip['title'] ) . '</strong> παραλήφθηκε από το γραφείο μας. Επισυνάπτεται αντίγραφο σε PDF.' . ( LGT_Settings::get( 'close_on_submit' ) ? '' : ' Μπορείτε να κάνετε αλλαγές από τον σύνδεσμό σας μέχρι να κλείσει η φόρμα.' ) . '</p>';
			$s_html .= self::trip_block( $trip, $summary );
			$s_html .= self::issues_block( $trip, $summary );
			self::send( $trip['id'], 'submission_copy', array( $trip['school_email'] ), 'Επιβεβαίωση παραλαβής φόρμας – ' . $trip['title'], $s_html, $pdf ? array( $pdf ) : array() );
			if ( $pdf ) {
				@unlink( $pdf );
			}
		}
		self::cleanup_files( $files );
		LGT_DB::update_trip( $trip['id'], array( 'submitted_at' => current_time( 'mysql' ) ) );
		LGT_DB::update_trip_meta( $trip['id'], array( 'last_export_at' => current_time( 'mysql' ) ) );
		return $ok;
	}

	/** Reminder N days before departure. */
	public static function send_reminder( array $trip, $days ) {
		$summary = LGT_Data::summary( $trip );
		$when    = 0 === (int) $days ? 'σήμερα' : ( 1 === (int) $days ? 'αύριο' : 'σε ' . (int) $days . ' ημέρες' );
		$subject = sprintf( '[Υπενθύμιση] Εκδρομή %s – %s – αναχώρηση %s (%s)', $trip['school_name'], $trip['title'], $when, LGT_Exporter::fmt_date( $trip['departure_date'] ) );
		$intro   = '<h2 style="margin:0 0 12px;font-size:17px;">Υπενθύμιση εκδρομής – αναχώρηση ' . esc_html( $when ) . '</h2>';
		$intro  .= '<p>Η εκδρομή <strong>' . esc_html( $trip['title'] ) . '</strong> του σχολείου <strong>' . esc_html( $trip['school_name'] ) . '</strong> αναχωρεί στις <strong>' . esc_html( LGT_Exporter::fmt_date( $trip['departure_date'] ) ) . '</strong>.</p>';
		$block   = self::trip_block( $trip, $summary );
		$issues  = self::issues_block( $trip, $summary );
		$notes   = $trip['notes_school'] ? '<div style="background:#eff6ff;border-left:4px solid #3b82f6;padding:10px 12px;margin:14px 0;">' . nl2br( esc_html( $trip['notes_school'] ) ) . '</div>' : '';

		$files = LGT_Settings::get( 'reminder_attachments' ) ? self::build_attachments( $trip ) : array();
		$html  = $intro . $block . ( $trip['submitted_at'] ? '' : '<p style="color:#b91c1c;"><strong>Το σχολείο δεν έχει υποβάλει ακόμη τη φόρμα.</strong></p>' ) . $issues . self::button( self::admin_url_for( $trip['id'] ), 'Προβολή στη διαχείριση' );
		$sent  = self::send( $trip['id'], 'reminder_office', LGT_Settings::office_emails(), $subject, $html, $files );
		self::cleanup_files( $files );

		$others = array();
		if ( LGT_Settings::get( 'reminder_to_school' ) && $trip['school_email'] ) {
			$others[] = $trip['school_email'];
		}
		if ( LGT_Settings::get( 'reminder_to_extra' ) ) {
			$others = array_merge( $others, LGT_Settings::parse_emails( $trip['extra_emails'] ) );
		}
		$others = array_diff( array_unique( $others ), LGT_Settings::office_emails() );
		if ( $others ) {
			$html2 = $intro . $notes . $block;
			if ( 'open' === $trip['status'] && ! $trip['submitted_at'] ) {
				$html2 .= '<p style="color:#b91c1c;"><strong>Δεν έχουμε λάβει ακόμη τη φόρμα σας. Παρακαλούμε συμπληρώστε τα ονόματα και πατήστε «Υποβολή».</strong></p>';
			} elseif ( $issues ) {
				$html2 .= $issues;
			}
			if ( $trip['token'] && 'open' === $trip['status'] ) {
				$html2 .= self::button( LGT_Portal::url( $trip ), 'Άνοιγμα φόρμας' );
			}
			$html2 .= '<p>Για οποιαδήποτε απορία επικοινωνήστε με το γραφείο μας' . ( LGT_Settings::get( 'company_phone' ) ? ' στο ' . esc_html( LGT_Settings::get( 'company_phone' ) ) : '' ) . '.</p>';
			self::send( $trip['id'], 'reminder_school', array_values( $others ), $subject, $html2 );
		}
		return $sent;
	}

	/** Daily digest: trips whose data changed since the last export. */
	public static function send_digest( array $trips ) {
		if ( ! $trips ) {
			return false;
		}
		$html  = '<h2 style="margin:0 0 12px;font-size:17px;">Ημερήσια ενημέρωση – αλλαγές σε φόρμες σχολείων</h2>';
		$html .= '<p>Τα παρακάτω σχολεία έκαναν αλλαγές από την τελευταία αποστολή. Επισυνάπτονται τα ενημερωμένα αρχεία.</p>';
		$files = array();
		foreach ( $trips as $trip ) {
			$html .= '<h3 style="margin:18px 0 6px;font-size:15px;border-top:1px solid #e5e7eb;padding-top:12px;">' . esc_html( $trip['school_name'] . ' – ' . $trip['title'] ) . '</h3>';
			$html .= self::trip_block( $trip, LGT_Data::summary( $trip ) );
			$html .= '<p><a href="' . esc_url( self::admin_url_for( $trip['id'] ) ) . '">Προβολή στη διαχείριση</a></p>';
			foreach ( array( LGT_Exporter::build_xlsx( $trip, 'all' ), LGT_Exporter::build_pdf( $trip, 'all' ) ) as $f ) {
				if ( $f ) {
					$files[] = $f;
				}
			}
		}
		$ok = self::send( 0, 'digest', LGT_Settings::office_emails(), '[Σχολικές Εκδρομές] Ημερήσια ενημέρωση αλλαγών (' . count( $trips ) . ')', $html, $files );
		self::cleanup_files( $files );
		if ( $ok ) {
			foreach ( $trips as $trip ) {
				LGT_DB::update_trip_meta( $trip['id'], array( 'last_export_at' => current_time( 'mysql' ) ) );
			}
		}
		return $ok;
	}

	/** Send the form link to the school. */
	public static function send_link( array $trip, $custom_message = '' ) {
		if ( ! $trip['token'] || ! $trip['school_email'] ) {
			return false;
		}
		$url   = LGT_Portal::url( $trip );
		$html  = '<h2 style="margin:0 0 12px;font-size:17px;">Φόρμα ονομάτων & rooming list</h2>';
		$html .= '<p>Αγαπητοί συνεργάτες, για την εκδρομή <strong>' . esc_html( $trip['title'] ) . '</strong>' . ( $trip['departure_date'] ? ' (' . esc_html( LGT_Exporter::fmt_date( $trip['departure_date'] ) ) . ')' : '' ) . ' παρακαλούμε συμπληρώστε online τη φόρμα ονομάτων' . ( $trip['has_hotel'] ? ' και τη rooming list' : '' ) . ( $trip['has_ferry'] ? ' και τις καμπίνες του πλοίου' : '' ) . ' μέσω του παρακάτω συνδέσμου:</p>';
		$html .= self::button( $url, 'Άνοιγμα φόρμας' );
		$html .= '<p style="font-size:12px;color:#6b7280;">Σύνδεσμος: <a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a></p>';
		if ( $trip['access_code'] ) {
			$html .= '<p>Κωδικός πρόσβασης: <strong style="font-size:16px;letter-spacing:1px;">' . esc_html( $trip['access_code'] ) . '</strong></p>';
		}
		$note = $custom_message ? $custom_message : $trip['notes_school'];
		if ( $note ) {
			$html .= '<div style="background:#eff6ff;border-left:4px solid #3b82f6;padding:10px 12px;margin:14px 0;">' . nl2br( esc_html( $note ) ) . '</div>';
		}
		$html .= '<p><strong>Τι θα συμπληρώσετε:</strong></p><ul style="padding-left:18px;">';
		$html .= '<li><strong>Φόρμα ονομάτων:</strong> Επώνυμο, Όνομα (λατινικά, όπως στην ταυτότητα – αν γράψετε ελληνικά μετατρέπονται αυτόματα) και ημερομηνία γέννησης.</li>';
		if ( $trip['has_hotel'] ) {
			$html .= '<li><strong>Rooming list:</strong> ποιοι μένουν μαζί σε κάθε δωμάτιο (δίκλινα, τρίκλινα, τετράκλινα).</li>';
		}
		if ( $trip['has_ferry'] ) {
			$html .= '<li><strong>Καμπίνες πλοίου:</strong> ποιοι μένουν μαζί σε κάθε καμπίνα.</li>';
		}
		$html .= '</ul><p>Η φόρμα αποθηκεύεται αυτόματα όσο γράφετε. Όταν ολοκληρώσετε, πατήστε <strong>«Υποβολή»</strong> και θα λάβουμε τη λίστα σας.</p>';
		return self::send( $trip['id'], 'link', array( $trip['school_email'] ), 'Φόρμα συμμετεχόντων – ' . $trip['title'] . ' – ' . LGT_Settings::get( 'company_name' ), $html );
	}
}
