<?php
/**
 * Email notifications: submissions, "send now", reminders, daily digest.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LGT_Mailer {

	/* ------------------------------------------------------------------ */
	/* Low level                                                          */
	/* ------------------------------------------------------------------ */

	public static function from_name() {
		return LGT_Settings::get( 'from_name' ) ? LGT_Settings::get( 'from_name' ) : get_bloginfo( 'name' );
	}

	public static function from_email() {
		$e = LGT_Settings::get( 'from_email' );
		if ( $e && is_email( $e ) ) {
			return $e;
		}
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = preg_replace( '/^www\./', '', (string) $host );
		return 'noreply@' . ( $host ? $host : 'localhost' );
	}

	/**
	 * Send HTML mail with attachments and log the result.
	 */
	public static function send( $trip_id, $kind, array $to, $subject, $html, array $attachments = array(), array $extra_headers = array() ) {
		$to = array_values( array_unique( array_filter( $to, 'is_email' ) ) );
		if ( ! $to ) {
			LGT_DB::mail_log( $trip_id, $kind, array(), $subject, $attachments, false, 'Δεν υπάρχουν παραλήπτες.' );
			return false;
		}
		$headers = array_merge(
			array(
				'Content-Type: text/html; charset=UTF-8',
				'From: ' . self::from_name() . ' <' . self::from_email() . '>',
			),
			$extra_headers
		);
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
		return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">'
			. '<div style="max-width:680px;margin:0 auto;padding:24px;">'
			. '<div style="background:#0f3b66;color:#fff;padding:16px 22px;border-radius:10px 10px 0 0;font-size:18px;font-weight:bold;">' . $company . ' – Σχολικές Εκδρομές</div>'
			. '<div style="background:#fff;padding:22px;border-radius:0 0 10px 10px;line-height:1.55;font-size:14px;">' . $inner . '</div>'
			. '<p style="color:#6b7280;font-size:11px;margin-top:14px;">Αυτό το μήνυμα στάλθηκε αυτόματα από την πλατφόρμα σχολικών εκδρομών της ' . $company . '.</p>'
			. '</div></body></html>';
	}

	/* ------------------------------------------------------------------ */
	/* Content helpers                                                    */
	/* ------------------------------------------------------------------ */

	public static function trip_block( array $trip, array $summary ) {
		$rows = array(
			'Σχολείο'       => $trip['school_name'],
			'Εκδρομή'       => $trip['title'],
			'Προορισμός'    => $trip['destination'],
			'Ημερομηνίες'   => LGT_Exporter::fmt_date( $trip['departure_date'] ) . ( $trip['return_date'] ? ' – ' . LGT_Exporter::fmt_date( $trip['return_date'] ) : '' ),
			'Ξενοδοχείο'    => $trip['has_hotel'] ? ( $trip['hotel_name'] ? $trip['hotel_name'] : '—' ) : null,
			'Ακτοπλοϊκό'    => $trip['has_ferry'] ? ( $trip['ferry_company'] ? $trip['ferry_company'] : 'Ναι' ) : null,
			'Αεροπορικό'    => $trip['has_flight'] ? ( $trip['airline'] ? $trip['airline'] : 'Ναι' ) : null,
			'Υπεύθυνος'     => trim( $trip['contact_name'] . ' ' . $trip['school_phone'] . ' ' . $trip['school_email'] ),
			'Συμμετέχοντες' => $summary['total'] . ' (μαθητές ' . $summary['students'] . ', συνοδοί ' . $summary['staff'] . ' | αγόρια ' . $summary['male'] . ', κορίτσια ' . $summary['female'] . ')',
		);
		if ( $trip['has_hotel'] ) {
			$rows['Δωμάτια'] = self::summary_str( $summary['hotel_summary'] ) . ( $summary['hotel_unassigned'] ? ' – <strong style="color:#b45309">χωρίς δωμάτιο: ' . $summary['hotel_unassigned'] . '</strong>' : '' );
		}
		if ( $trip['has_ferry'] ) {
			$rows['Καμπίνες'] = self::summary_str( $summary['cabin_summary'] ) . ( $summary['cabin_unassigned'] ? ' – <strong style="color:#b45309">χωρίς καμπίνα: ' . $summary['cabin_unassigned'] . '</strong>' : '' );
		}
		$rows['Ελλιπή στοιχεία'] = $summary['issue_count'] ? '<strong style="color:#b91c1c">' . $summary['issue_count'] . ' άτομα</strong>' : '<span style="color:#15803d">Κανένα</span>';

		$html = '<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:13px;">';
		foreach ( $rows as $k => $v ) {
			if ( null === $v ) {
				continue;
			}
			$html .= '<tr><td style="border-bottom:1px solid #e5e7eb;color:#6b7280;width:150px;">' . esc_html( $k ) . '</td><td style="border-bottom:1px solid #e5e7eb;">' . ( 'Δωμάτια' === $k || 'Καμπίνες' === $k || 'Ελλιπή στοιχεία' === $k ? wp_kses_post( $v ) : esc_html( $v ) ) . '</td></tr>';
		}
		return $html . '</table>';
	}

	private static function summary_str( array $s ) {
		$parts = array();
		foreach ( $s as $code => $n ) {
			$parts[] = $n . ' × ' . $code;
		}
		return $parts ? implode( ', ', $parts ) : '—';
	}

	public static function issues_block( array $trip, array $participants, array $summary, $limit = 30 ) {
		if ( ! $summary['issue_count'] ) {
			return '';
		}
		$labels = LGT_Validator::field_labels();
		$by_id  = array();
		foreach ( $participants as $p ) {
			$by_id[ $p['id'] ] = $p;
		}
		$html = '<h3 style="margin:18px 0 6px;font-size:14px;">Ελλιπή στοιχεία</h3><ul style="margin:0;padding-left:18px;font-size:13px;">';
		$n    = 0;
		foreach ( $summary['issues'] as $pid => $c ) {
			if ( ++$n > $limit ) {
				$html .= '<li>… και ' . ( $summary['issue_count'] - $limit ) . ' ακόμη</li>';
				break;
			}
			$p     = $by_id[ $pid ] ?? null;
			$name  = $p ? $p['last_name'] . ' ' . $p['first_name'] : '#' . $pid;
			$items = array();
			foreach ( $c['missing'] as $f ) {
				$items[] = $labels[ $f ] ?? $f;
			}
			$items = array_merge( $items, $c['warnings'] );
			$html .= '<li><strong>' . esc_html( $name ) . '</strong>: ' . esc_html( implode( ', ', $items ) ) . '</li>';
		}
		return $html . '</ul>';
	}

	public static function button( $url, $label ) {
		return '<p style="margin:18px 0;"><a href="' . esc_url( $url ) . '" style="background:#0f3b66;color:#fff;text-decoration:none;padding:10px 18px;border-radius:6px;display:inline-block;font-weight:bold;">' . esc_html( $label ) . '</a></p>';
	}

	public static function admin_url_for( $trip_id ) {
		return admin_url( 'admin.php?page=lgt-trip&id=' . (int) $trip_id . '&tab=manage' );
	}

	/**
	 * Build attachments according to settings; returns [paths].
	 */
	public static function build_attachments( array $trip, $force = false ) {
		$files = array();
		if ( $force || LGT_Settings::get( 'attach_xlsx' ) ) {
			$f = LGT_Exporter::build_xlsx( $trip['id'], 'all' );
			if ( $f ) {
				$files[] = $f;
			}
		}
		if ( $force || LGT_Settings::get( 'attach_pdf' ) ) {
			$f = LGT_Exporter::build_pdf( $trip['id'], 'all' );
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

	private static function load_summary( array $trip ) {
		$participants = LGT_DB::get_participants( $trip['id'] );
		$rooms        = LGT_DB::get_rooms( $trip['id'] );
		$assignments  = LGT_DB::get_assignments( $trip['id'] );
		return array( $participants, LGT_Validator::trip_summary( $trip, $participants, $rooms, $assignments ) );
	}

	/* ------------------------------------------------------------------ */
	/* Scenarios                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * School submitted the list (or admin pressed "send now").
	 */
	public static function send_submission( array $trip, $message = '', $by_admin = false ) {
		list( $participants, $summary ) = self::load_summary( $trip );
		$files   = self::build_attachments( $trip );
		$subject = sprintf( '[Σχολικές Εκδρομές] %s – %s – %s', $by_admin ? 'Λίστες' : 'Υποβολή λίστας', $trip['school_name'], $trip['title'] );
		$html    = '<h2 style="margin:0 0 12px;font-size:17px;">' . ( $by_admin ? 'Λίστες εκδρομής' : 'Νέα υποβολή από το σχολείο' ) . '</h2>';
		if ( $message ) {
			$html .= '<div style="background:#fef3c7;border-left:4px solid #f59e0b;padding:10px 12px;margin-bottom:14px;"><strong>Μήνυμα:</strong><br>' . nl2br( esc_html( $message ) ) . '</div>';
		}
		$html .= self::trip_block( $trip, $summary );
		$html .= self::issues_block( $trip, $participants, $summary );
		$html .= '<p style="margin-top:14px;">Επισυνάπτονται τα αρχεία Excel/PDF (rooming list' . ( $trip['has_ferry'] ? ', καμπίνες, manifest πλοίου' : '' ) . ( $trip['has_flight'] ? ', λίστα αεροπορικού' : '' ) . ').</p>';
		$html .= self::button( self::admin_url_for( $trip['id'] ), 'Άνοιγμα στη διαχείριση' );

		$ok = self::send( $trip['id'], $by_admin ? 'send_now' : 'submission', LGT_Settings::office_emails(), $subject, $html, $files );

		// Confirmation to the school (PDF only, no internal full list).
		if ( ! $by_admin && $trip['school_email'] && is_email( $trip['school_email'] ) ) {
			$pdf     = LGT_Exporter::build_pdf( $trip['id'], 'all' );
			$s_html  = '<h2 style="margin:0 0 12px;font-size:17px;">Λάβαμε τη λίστα σας – ευχαριστούμε!</h2>';
			$s_html .= '<p>Η λίστα συμμετεχόντων για την εκδρομή <strong>' . esc_html( $trip['title'] ) . '</strong> παραλήφθηκε από το γραφείο μας. Μπορείτε να συνεχίσετε να κάνετε αλλαγές από τον σύνδεσμό σας μέχρι να κλείσει η καταχώρηση.</p>';
			$s_html .= self::trip_block( $trip, $summary );
			$s_html .= self::issues_block( $trip, $participants, $summary );
			if ( $trip['token'] && 'open' === $trip['status'] ) {
				$s_html .= self::button( LGT_Portal::url( $trip ), 'Άνοιγμα πλατφόρμας' );
			}
			self::send( $trip['id'], 'submission_copy', array( $trip['school_email'] ), 'Επιβεβαίωση παραλαβής λίστας – ' . $trip['title'], $s_html, $pdf ? array( $pdf ) : array() );
			if ( $pdf ) {
				@unlink( $pdf );
			}
		}
		self::cleanup_files( $files );
		LGT_DB::update_trip( $trip['id'], array( 'submitted_at' => current_time( 'mysql' ) ) );
		LGT_DB::update_trip_meta( $trip['id'], array( 'last_export_at' => current_time( 'mysql' ) ) );
		return $ok;
	}

	/**
	 * Reminder N days before departure.
	 */
	public static function send_reminder( array $trip, $days ) {
		list( $participants, $summary ) = self::load_summary( $trip );
		$when = 0 === (int) $days ? 'σήμερα' : ( 1 === (int) $days ? 'αύριο' : 'σε ' . (int) $days . ' ημέρες' );
		$subject = sprintf( '[Υπενθύμιση] Εκδρομή %s – %s – αναχώρηση %s (%s)', $trip['school_name'], $trip['title'], $when, LGT_Exporter::fmt_date( $trip['departure_date'] ) );

		$intro  = '<h2 style="margin:0 0 12px;font-size:17px;">Υπενθύμιση εκδρομής – αναχώρηση ' . esc_html( $when ) . '</h2>';
		$intro .= '<p>Η εκδρομή <strong>' . esc_html( $trip['title'] ) . '</strong> του σχολείου <strong>' . esc_html( $trip['school_name'] ) . '</strong> αναχωρεί στις <strong>' . esc_html( LGT_Exporter::fmt_date( $trip['departure_date'] ) ) . '</strong>.</p>';
		$block  = self::trip_block( $trip, $summary );
		$issues = self::issues_block( $trip, $participants, $summary );
		if ( $trip['notes_school'] ) {
			$notes = '<div style="background:#eff6ff;border-left:4px solid #3b82f6;padding:10px 12px;margin:14px 0;">' . nl2br( esc_html( $trip['notes_school'] ) ) . '</div>';
		} else {
			$notes = '';
		}

		// Office: with attachments.
		$files = LGT_Settings::get( 'reminder_attachments' ) ? self::build_attachments( $trip ) : array();
		$html  = $intro . $block . $issues . self::button( self::admin_url_for( $trip['id'] ), 'Άνοιγμα στη διαχείριση' );
		$sent  = self::send( $trip['id'], 'reminder_office', LGT_Settings::office_emails(), $subject, $html, $files );
		self::cleanup_files( $files );

		// School + extra people: without internal attachments.
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
			if ( $summary['issue_count'] && 'open' === $trip['status'] ) {
				$html2 .= '<p style="color:#b91c1c;"><strong>Παρακαλούμε συμπληρώστε τα στοιχεία που λείπουν το συντομότερο.</strong></p>' . $issues;
			}
			if ( $trip['token'] && 'open' === $trip['status'] ) {
				$html2 .= self::button( LGT_Portal::url( $trip ), 'Άνοιγμα πλατφόρμας καταχώρησης' );
			}
			$html2 .= '<p>Για οποιαδήποτε απορία επικοινωνήστε με το γραφείο μας' . ( LGT_Settings::get( 'company_phone' ) ? ' στο ' . esc_html( LGT_Settings::get( 'company_phone' ) ) : '' ) . '.</p>';
			self::send( $trip['id'], 'reminder_school', array_values( $others ), $subject, $html2 );
		}
		return $sent;
	}

	/**
	 * Daily digest: trips whose data changed since the last export.
	 */
	public static function send_digest( array $trips ) {
		if ( ! $trips ) {
			return false;
		}
		$html  = '<h2 style="margin:0 0 12px;font-size:17px;">Ημερήσια ενημέρωση – αλλαγές σε λίστες σχολείων</h2>';
		$html .= '<p>Τα παρακάτω σχολεία έκαναν αλλαγές στις λίστες τους από την τελευταία αποστολή. Επισυνάπτονται τα ενημερωμένα αρχεία.</p>';
		$files = array();
		foreach ( $trips as $trip ) {
			list( $participants, $summary ) = self::load_summary( $trip );
			$html .= '<h3 style="margin:18px 0 6px;font-size:15px;border-top:1px solid #e5e7eb;padding-top:12px;">' . esc_html( $trip['school_name'] . ' – ' . $trip['title'] ) . '</h3>';
			$html .= self::trip_block( $trip, $summary );
			$html .= '<p><a href="' . esc_url( self::admin_url_for( $trip['id'] ) ) . '">Άνοιγμα στη διαχείριση</a></p>';
			$f = LGT_Exporter::build_xlsx( $trip['id'], 'all' );
			if ( $f ) {
				$files[] = $f;
			}
			$f = LGT_Exporter::build_pdf( $trip['id'], 'all' );
			if ( $f ) {
				$files[] = $f;
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

	/**
	 * Send the portal link to the school.
	 */
	public static function send_link( array $trip, $custom_message = '' ) {
		if ( ! $trip['token'] || ! $trip['school_email'] ) {
			return false;
		}
		$url   = LGT_Portal::url( $trip );
		$html  = '<h2 style="margin:0 0 12px;font-size:17px;">Πλατφόρμα καταχώρησης συμμετεχόντων</h2>';
		$html .= '<p>Αγαπητοί συνεργάτες, για την εκδρομή <strong>' . esc_html( $trip['title'] ) . '</strong>' . ( $trip['departure_date'] ? ' (' . esc_html( LGT_Exporter::fmt_date( $trip['departure_date'] ) ) . ')' : '' ) . ' μπορείτε να καταχωρήσετε τους μαθητές και τους συνοδούς, να ορίσετε τα δωμάτια' . ( $trip['has_ferry'] ? ' και τις καμπίνες του πλοίου' : '' ) . ' μέσω του παρακάτω συνδέσμου:</p>';
		$html .= self::button( $url, 'Άνοιγμα πλατφόρμας' );
		$html .= '<p style="font-size:12px;color:#6b7280;">Σύνδεσμος: <a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a></p>';
		if ( $trip['access_code'] ) {
			$html .= '<p>Κωδικός πρόσβασης: <strong style="font-size:16px;letter-spacing:1px;">' . esc_html( $trip['access_code'] ) . '</strong></p>';
		}
		if ( $custom_message ) {
			$html .= '<div style="background:#eff6ff;border-left:4px solid #3b82f6;padding:10px 12px;margin:14px 0;">' . nl2br( esc_html( $custom_message ) ) . '</div>';
		} elseif ( $trip['notes_school'] ) {
			$html .= '<div style="background:#eff6ff;border-left:4px solid #3b82f6;padding:10px 12px;margin:14px 0;">' . nl2br( esc_html( $trip['notes_school'] ) ) . '</div>';
		}
		$html .= '<p><strong>Τι χρειάζεται:</strong></p><ul style="padding-left:18px;">';
		$html .= '<li>Ονοματεπώνυμο κάθε μαθητή/συνοδού (τα λατινικά συμπληρώνονται αυτόματα, ελέγξτε τα με βάση την ταυτότητα/διαβατήριο).</li>';
		$html .= '<li>Φύλο' . ( $trip['has_ferry'] || $trip['has_flight'] ? ', ημερομηνία γέννησης, εθνικότητα' : '' ) . ( $trip['has_flight'] ? ', τύπος & αριθμός εγγράφου, ημερομηνία λήξης' : ( $trip['has_ferry'] && LGT_Settings::get( 'ferry_doc_required' ) ? ', αριθμός ταυτότητας' : '' ) ) . '.</li>';
		if ( $trip['has_hotel'] ) {
			$html .= '<li>Κατανομή σε δωμάτια ξενοδοχείου (υπάρχει αυτόματη κατανομή).</li>';
		}
		if ( $trip['has_ferry'] ) {
			$html .= '<li>Κατανομή σε καμπίνες πλοίου (μπορεί να αντιγραφεί από/προς τα δωμάτια).</li>';
		}
		$html .= '</ul><p>Όταν ολοκληρώσετε, πατήστε «Υποβολή στο γραφείο». Μπορείτε να κάνετε αλλαγές μέχρι να κλείσει η καταχώρηση.</p>';
		return self::send( $trip['id'], 'link', array( $trip['school_email'] ), 'Πλατφόρμα καταχώρησης – ' . $trip['title'] . ' – ' . LGT_Settings::get( 'company_name' ), $html );
	}
}
