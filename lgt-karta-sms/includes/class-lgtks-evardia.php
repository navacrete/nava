<?php
/**
 * Source "evardia": signs in to evardia.gr with the company's credentials and reads the page
 * «Επιλεκτική αποστολή» (/Ergazomenos/EpilektikhApostolh), which embeds a JSON array with,
 * per employee working today: ΑΦΜ, name, branch, up to 3 shifts (orarioApoN/orarioEosN) and
 * the recorded clock-in/out times (proselefshN / apoxorhshN, e.g. "09:03 ✓").
 *
 * Read-only: only GET requests to that page (plus the login POST).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LGTKS_Evardia {

	const COOKIE_TRANSIENT = 'lgtks_evardia_cookies';
	const PAGE_TRANSIENT   = 'lgtks_evardia_page';
	const DAY_OPTION       = 'lgt_ks_evardia_day';
	const ROSTER_OPTION    = 'lgt_ks_evardia_roster';
	const ROSTER_TRANSIENT = 'lgtks_evardia_roster_fresh';

	public static function cfg() {
		$base = rtrim( trim( (string) LGTKS_Settings::get( 'ev_base_url', 'https://evardia.gr' ) ), '/' );
		if ( '' === $base ) {
			$base = 'https://evardia.gr';
		}
		$ypok = trim( (string) LGTKS_Settings::get( 'ev_ypokatasthma', '0' ) );
		return array(
			'login_url'        => trim( (string) LGTKS_Settings::get( 'ev_login_url' ) ),
			'username'         => (string) LGTKS_Settings::get( 'ev_username' ),
			'password'         => (string) LGTKS_Settings::get( 'ev_password' ),
			'user_field'       => 'username',
			'pass_field'       => 'password',
			'extra_fields'     => '',
			'success_contains' => '',
			'data_url'         => $base . '/Ergazomenos/EpilektikhApostolh?fetchAll=False&ypokatasthma=' . rawurlencode( '' === $ypok ? '0' : $ypok ),
			'cookie_key'       => self::COOKIE_TRANSIENT,
		);
	}

	/** Raw HTML of the page (cached for 4 minutes). */
	public static function get_page( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::PAGE_TRANSIENT );
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}
		$html = LGTKS_WebLogin::get_data( LGTKS_Settings::now( 'Y-m-d' ), true, self::cfg() );
		if ( is_wp_error( $html ) ) {
			return $html;
		}
		set_transient( self::PAGE_TRANSIENT, $html, 4 * MINUTE_IN_SECONDS );
		return $html;
	}

	/**
	 * Extract the embedded JSON rows from the page HTML.
	 *
	 * @return array|WP_Error
	 */
	public static function parse_page( $html ) {
		// Locate the JavaScript string literal passed to JSON.parse('…') without regex backtracking limits.
		$js  = null;
		$pos = strpos( (string) $html, "JSON.parse('" );
		if ( false !== $pos ) {
			$i   = $pos + strlen( "JSON.parse('" );
			$len = strlen( $html );
			$buf = '';
			while ( $i < $len ) {
				$ch = $html[ $i ];
				if ( '\\' === $ch && $i + 1 < $len ) {
					$buf .= $ch . $html[ $i + 1 ];
					$i   += 2;
					continue;
				}
				if ( "'" === $ch ) {
					$js = $buf;
					break;
				}
				$buf .= $ch;
				$i++;
			}
		}
		if ( null === $js ) {
			if ( LGTKS_WebLogin::looks_like_login_page( $html ) ) {
				return new WP_Error( 'lgtks', 'Η σελίδα ζητά σύνδεση – οι κωδικοί eVardia δεν έγιναν δεκτοί.' );
			}
			return new WP_Error( 'lgtks', 'Δεν βρέθηκαν δεδομένα πίνακα (JSON.parse) στη σελίδα του eVardia. Ίσως άλλαξε η σελίδα.' );
		}
		// Decode the JavaScript single-quoted string literal.
		$js = preg_replace_callback(
			'/\\\\(u[0-9a-fA-F]{4}|x[0-9a-fA-F]{2}|.)/s',
			function ( $mm ) {
				$c = $mm[1];
				if ( 'u' === $c[0] && strlen( $c ) === 5 ) {
					return mb_convert_encoding( pack( 'n', hexdec( substr( $c, 1 ) ) ), 'UTF-8', 'UTF-16BE' );
				}
				if ( 'x' === $c[0] && strlen( $c ) === 3 ) {
					return chr( hexdec( substr( $c, 1 ) ) );
				}
				$map = array( 'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C", '0' => "\0" );
				return isset( $map[ $c ] ) ? $map[ $c ] : $c; // \' \" \\ \/ -> literal
			},
			$js
		);
		$rows = json_decode( $js, true );
		if ( ! is_array( $rows ) ) {
			return new WP_Error( 'lgtks', 'Τα δεδομένα του eVardia δεν αποκωδικοποιήθηκαν: ' . json_last_error_msg() );
		}
		return $rows;
	}

	/* ---------- employee roster (/Ergazomenos): only «Ενεργός = Ναι» are tracked ---------- */

	/** Raw HTML of the employee list page. */
	public static function get_roster_page() {
		$cfg             = self::cfg();
		$cfg['data_url'] = preg_replace( '#/Ergazomenos/EpilektikhApostolh.*$#', '/Ergazomenos', $cfg['data_url'] );
		return LGTKS_WebLogin::get_data( LGTKS_Settings::now( 'Y-m-d' ), true, $cfg );
	}

	/**
	 * Parse the roster page into {active: [afm => name], inactive: [afm => name], how: string}.
	 * Accepts the embedded JSON.parse('…') array or a plain HTML table with a column «Ενεργός».
	 *
	 * @return array|WP_Error
	 */
	public static function parse_roster( $html ) {
		$out = array( 'active' => array(), 'inactive' => array(), 'how' => '' );
		$rows = self::parse_page( $html );
		if ( ! is_wp_error( $rows ) && $rows && is_array( $rows[0] ) ) {
			// Find the key that holds «Ενεργός».
			$akey = '';
			foreach ( array_keys( $rows[0] ) as $k ) {
				if ( preg_match( '/^(energos|isEnergos|active|isActive|enabled|energ)/i', $k ) ) {
					$akey = $k;
					break;
				}
			}
			if ( '' === $akey ) {
				foreach ( array_keys( $rows[0] ) as $k ) {
					if ( stripos( $k, 'energ' ) !== false || stripos( $k, 'activ' ) !== false ) {
						$akey = $k;
						break;
					}
				}
			}
			if ( '' !== $akey ) {
				foreach ( $rows as $r ) {
					$afm  = preg_replace( '/\D+/', '', (string) ( $r['afm'] ?? '' ) );
					$name = trim( (string) ( $r['onomateponymo'] ?? trim( ( $r['eponymo'] ?? '' ) . ' ' . ( $r['onoma'] ?? '' ) ) ) );
					if ( '' === $afm && '' === $name ) {
						continue;
					}
					$out[ self::truthy( $r[ $akey ] ) ? 'active' : 'inactive' ][ '' !== $afm ? $afm : $name ] = $name;
				}
				$out['how'] = 'JSON, πεδίο «' . $akey . '»';
				return $out;
			}
			$out['how'] = 'JSON χωρίς αναγνωρίσιμο πεδίο «Ενεργός» (κλειδιά: ' . implode( ', ', array_keys( $rows[0] ) ) . ') – δοκιμή πίνακα HTML';
		}
		// HTML table fallback.
		$table = LGTKS_WebLogin::html_table( $html );
		if ( is_wp_error( $table ) || ! $table ) {
			return new WP_Error( 'lgtks', 'Η λίστα εργαζομένων δεν διαβάστηκε (ούτε JSON ούτε πίνακας). ' . $out['how'] );
		}
		$hdr  = array_keys( $table[0] );
		$acol = null;
		$fcol = null;
		foreach ( $hdr as $h ) {
			$n = LGTKS_Source::norm( $h );
			if ( null === $acol && ( 'ενεργοσ' === $n || strpos( $n, 'ενεργ' ) === 0 ) ) {
				$acol = $h;
			}
			if ( null === $fcol && ( 'αφμ' === $n || 'α φ μ' === $n || 'afm' === $n ) ) {
				$fcol = $h;
			}
		}
		if ( null === $acol ) {
			return new WP_Error( 'lgtks', 'Στον πίνακα της λίστας εργαζομένων δεν βρέθηκε στήλη «Ενεργός». Στήλες: ' . implode( ' | ', $hdr ) );
		}
		foreach ( $table as $row ) {
			$afm = null !== $fcol ? preg_replace( '/\D+/', '', (string) $row[ $fcol ] ) : '';
			if ( '' === $afm ) {
				foreach ( $row as $v ) {
					if ( preg_match( '/^\d{9}$/', trim( (string) $v ) ) ) {
						$afm = trim( (string) $v );
						break;
					}
				}
			}
			$name_parts = array();
			foreach ( $row as $h => $v ) {
				$n = LGTKS_Source::norm( $h );
				if ( in_array( $n, array( 'επωνυμο', 'ονομα', 'ονοματεπωνυμο' ), true ) ) {
					$name_parts[] = trim( (string) $v );
				}
			}
			$name = trim( implode( ' ', $name_parts ) );
			if ( '' === $afm && '' === $name ) {
				continue;
			}
			$out[ self::truthy( $row[ $acol ] ) ? 'active' : 'inactive' ][ '' !== $afm ? $afm : $name ] = $name;
		}
		$out['how'] = 'πίνακας HTML, στήλη «' . $acol . '»' . ( $fcol ? ', ΑΦΜ από «' . $fcol . '»' : '' );
		return $out;
	}

	/** «Ναι» / true / 1 / yes -> true; anything else (Όχι, false, 0, empty) -> false. */
	public static function truthy( $v ) {
		if ( is_bool( $v ) ) {
			return $v;
		}
		$s = LGTKS_Source::norm( (string) $v );
		return in_array( $s, array( 'ναι', 'yes', 'true', '1', 'ενεργοσ', 'ενεργη', 'ενεργο' ), true );
	}

	/**
	 * Fetch + store the roster (cached for 1 hour). Returns the roster array (possibly stale) or WP_Error.
	 */
	public static function sync_roster( $force = false ) {
		$stored = get_option( self::ROSTER_OPTION );
		if ( ! $force && get_transient( self::ROSTER_TRANSIENT ) && is_array( $stored ) ) {
			return $stored;
		}
		$html = self::get_roster_page();
		if ( is_wp_error( $html ) ) {
			return $html;
		}
		$r = self::parse_roster( $html );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$r['fetched_at'] = LGTKS_Settings::now( 'Y-m-d H:i:s' );
		update_option( self::ROSTER_OPTION, $r, false );
		set_transient( self::ROSTER_TRANSIENT, 1, HOUR_IN_SECONDS );
		return $r;
	}

	public static function roster() {
		$r = get_option( self::ROSTER_OPTION );
		return is_array( $r ) ? $r : null;
	}

	/** Is this ΑΦΜ (or name) known as NOT active in eVardia? Unknown => false (not excluded). */
	public static function is_inactive( $afm, $name, $roster ) {
		if ( ! is_array( $roster ) || empty( $roster['inactive'] ) ) {
			return false;
		}
		$afm = preg_replace( '/\D+/', '', (string) $afm );
		if ( '' !== $afm && isset( $roster['inactive'][ $afm ] ) ) {
			return ! isset( $roster['active'][ $afm ] );
		}
		if ( '' === $afm && '' !== $name ) {
			$nk = LGTKS_Source::norm( $name );
			foreach ( $roster['inactive'] as $k => $n ) {
				if ( LGTKS_Source::norm( $n ) === $nk ) {
					return true;
				}
			}
		}
		return false;
	}

	/** "09:03 ✓" / "09:03" -> "09:03", '' when empty. */
	public static function clean_time( $v ) {
		return preg_match( '/(\d{1,2}):(\d{2})/', (string) $v, $m ) ? sprintf( '%02d:%02d', $m[1], $m[2] ) : '';
	}

	/**
	 * Normalize eVardia rows: afm, name, branch, day, shifts[] = {start, end, in, out}.
	 */
	public static function normalize( array $rows ) {
		$out = array();
		foreach ( $rows as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$shifts = array();
			for ( $i = 1; $i <= 3; $i++ ) {
				$start = self::clean_time( $r[ 'orarioApo' . $i ] ?? '' );
				$in    = self::clean_time( $r[ 'proselefsh' . $i ] ?? '' );
				$outt  = self::clean_time( $r[ 'apoxorhsh' . $i ] ?? '' );
				if ( '' === $start && '' === $in && '' === $outt ) {
					continue;
				}
				$shifts[] = array(
					'start' => $start,
					'end'   => self::clean_time( $r[ 'orarioEos' . $i ] ?? '' ),
					'in'    => $in,
					'out'   => $outt,
				);
			}
			$out[] = array(
				'afm'    => trim( (string) ( $r['afm'] ?? '' ) ),
				'name'   => trim( (string) ( $r['onomateponymo'] ?? trim( ( $r['eponymo'] ?? '' ) . ' ' . ( $r['onoma'] ?? '' ) ) ) ),
				'branch' => (string) ( $r['ypokatasthmaPerigrafh'] ?? '' ),
				'day'    => (string) ( $r['hmeromhnia'] ?? '' ),
				'ev_id'  => (string) ( $r['ergazomenosId'] ?? '' ),
				'shifts' => $shifts,
			);
		}
		return $out;
	}

	/**
	 * Full sync: fetch page, create/match employees, store today's shifts, add punches.
	 *
	 * @return array|WP_Error summary
	 */
	public static function sync( $force = false ) {
		$html = self::get_page( $force );
		if ( is_wp_error( $html ) ) {
			return $html;
		}
		$rows = self::parse_page( $html );
		if ( is_wp_error( $rows ) ) {
			delete_transient( self::PAGE_TRANSIENT );
			return $rows;
		}
		$rows  = self::normalize( $rows );
		$today = LGTKS_Settings::now( 'Y-m-d' );
		$idx   = LGTKS_Source::employee_index();
		$auto  = (bool) LGTKS_Settings::get( 'ev_auto_create', 1 );
		$sum   = array( 'records' => count( $rows ), 'matched' => 0, 'created' => 0, 'new' => 0, 'inactive_skipped' => 0, 'deactivated' => 0, 'unmatched' => array() );
		$day   = array();
		// Roster: employees not marked «Ενεργός = Ναι» in eVardia are ignored completely.
		$roster = self::sync_roster();
		if ( is_wp_error( $roster ) ) {
			LGTKS_DB::log( 'warning', 'Η λίστα εργαζομένων του eVardia δεν διαβάστηκε – χρήση της τελευταίας γνωστής: ' . $roster->get_error_message() );
			$roster = self::roster();
		}
		foreach ( $rows as $r ) {
			if ( '' !== $r['day'] && $r['day'] !== $today ) {
				continue; // page shows today; ignore anything else defensively
			}
			if ( self::is_inactive( $r['afm'], $r['name'], $roster ) ) {
				$sum['inactive_skipped']++;
				$eid = '' !== $r['afm'] ? LGTKS_Source::match( $r['afm'], $idx ) : LGTKS_Source::match( $r['name'], $idx );
				if ( $eid ) {
					$e = LGTKS_DB::employee( $eid );
					if ( $e && (int) $e['active'] === 1 ) {
						LGTKS_DB::save_employee( array_merge( $e, array( 'active' => 0, 'notes' => trim( $e['notes'] . ' | Ανενεργός στο eVardia' ) ) ), $eid );
						$sum['deactivated']++;
					}
				}
				continue;
			}
			$emp_id = 0;
			if ( '' !== $r['afm'] ) {
				$emp_id = LGTKS_Source::match( $r['afm'], $idx );
			}
			if ( ! $emp_id && '' !== $r['name'] ) {
				$emp_id = LGTKS_Source::match( $r['name'], $idx );
				if ( $emp_id && '' !== $r['afm'] ) {
					$e = LGTKS_DB::employee( $emp_id );
					if ( $e && '' === trim( $e['external_id'] ) ) {
						LGTKS_DB::save_employee( array_merge( $e, array( 'external_id' => $r['afm'] ) ), $emp_id );
					}
				}
			}
			if ( ! $emp_id && $auto && '' !== $r['name'] ) {
				$emp_id = LGTKS_DB::save_employee(
					array(
						'name'           => $r['name'],
						'mobile'         => '',
						'email'          => '',
						'notify_channel' => 'sms',
						'notify_target'  => 'both',
						'external_id'    => $r['afm'],
						'schedule'       => array(),
						'notes'          => 'Από eVardia' . ( $r['branch'] ? ' – ' . $r['branch'] : '' ),
						'active'         => 1,
					)
				);
				$sum['created']++;
				$idx = LGTKS_Source::employee_index();
			}
			if ( ! $emp_id ) {
				$sum['unmatched'][] = $r['name'] . ( $r['afm'] ? ' (' . $r['afm'] . ')' : '' );
				continue;
			}
			$sum['matched']++;
			$day[ $emp_id ] = $r;
			foreach ( $r['shifts'] as $s ) {
				if ( '' !== $s['in'] && LGTKS_DB::add_punch( $emp_id, $today . ' ' . $s['in'] . ':00', 'in', 'evardia', $r['afm'] ) ) {
					$sum['new']++;
				}
				if ( '' !== $s['out'] && LGTKS_DB::add_punch( $emp_id, $today . ' ' . $s['out'] . ':00', 'out', 'evardia', $r['afm'] ) ) {
					$sum['new']++;
				}
			}
		}
		if ( is_array( $roster ) && ! empty( $roster['inactive'] ) ) {
			foreach ( LGTKS_DB::employees( true ) as $e ) {
				if ( '' !== trim( $e['external_id'] ) && self::is_inactive( $e['external_id'], $e['name'], $roster ) ) {
					LGTKS_DB::save_employee( array_merge( $e, array( 'active' => 0, 'notes' => trim( $e['notes'] . ' | Ανενεργός στο eVardia' ) ) ), $e['id'] );
					$sum['deactivated']++;
				}
			}
		}
		update_option( self::DAY_OPTION, array( 'day' => $today, 'fetched_at' => LGTKS_Settings::now( 'Y-m-d H:i:s' ), 'rows' => $day ), false );
		update_option(
			'lgt_ks_last_sync',
			array(
				'at'  => LGTKS_Settings::now( 'Y-m-d H:i:s' ),
				'ok'  => true,
				'msg' => sprintf( 'eVardia: %d εργαζόμενοι σήμερα, %d νέοι, %d νέα χτυπήματα, %d ανενεργοί αγνοήθηκαν', $sum['records'], $sum['created'], $sum['new'], $sum['inactive_skipped'] ),
			)
		);
		LGTKS_DB::log( 'info', 'Συγχρονισμός eVardia', $sum );
		return $sum;
	}

	/** Today's eVardia rows keyed by employee id (empty if not synced today). */
	public static function today_rows( $day ) {
		$o = get_option( self::DAY_OPTION );
		if ( is_array( $o ) && isset( $o['day'], $o['rows'] ) && $o['day'] === $day && is_array( $o['rows'] ) ) {
			return $o['rows'];
		}
		return array();
	}

	public static function last_fetch() {
		$o = get_option( self::DAY_OPTION );
		return is_array( $o ) && isset( $o['fetched_at'] ) ? $o['fetched_at'] : '';
	}

	/**
	 * Pick the shift that matters now: the last one whose start <= now (+grace), else the first.
	 * Returns {start, end, in, out} or null when the employee has no shift today.
	 */
	public static function relevant_shift( array $row, DateTime $now ) {
		$shifts = array();
		foreach ( $row['shifts'] as $s ) {
			if ( '' !== $s['start'] ) {
				$shifts[] = $s;
			}
		}
		if ( ! $shifts ) {
			return null;
		}
		usort(
			$shifts,
			function ( $a, $b ) {
				return strcmp( $a['start'], $b['start'] );
			}
		);
		$pick  = $shifts[0];
		$hhmm  = $now->format( 'H:i' );
		foreach ( $shifts as $s ) {
			if ( $s['start'] <= $hhmm ) {
				$pick = $s;
			}
		}
		return $pick;
	}
}
