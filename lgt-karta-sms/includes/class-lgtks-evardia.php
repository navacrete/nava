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
		$html = LGTKS_WebLogin::get_data( current_time( 'Y-m-d' ), true, self::cfg() );
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
		$today = current_time( 'Y-m-d' );
		$idx   = LGTKS_Source::employee_index();
		$auto  = (bool) LGTKS_Settings::get( 'ev_auto_create', 1 );
		$sum   = array( 'records' => count( $rows ), 'matched' => 0, 'created' => 0, 'new' => 0, 'unmatched' => array() );
		$day   = array();
		foreach ( $rows as $r ) {
			if ( '' !== $r['day'] && $r['day'] !== $today ) {
				continue; // page shows today; ignore anything else defensively
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
		update_option( self::DAY_OPTION, array( 'day' => $today, 'fetched_at' => current_time( 'mysql' ), 'rows' => $day ), false );
		update_option(
			'lgt_ks_last_sync',
			array(
				'at'  => current_time( 'mysql' ),
				'ok'  => true,
				'msg' => sprintf( 'eVardia: %d εργαζόμενοι σήμερα, %d νέοι, %d νέα χτυπήματα', $sum['records'], $sum['created'], $sum['new'] ),
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
