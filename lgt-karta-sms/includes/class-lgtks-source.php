<?php
/**
 * Clock-in source: pulls today's punches from eVardia (JSON API or CSV export URL)
 * or receives them by webhook. Matches each record to an employee and stores it.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LGTKS_Source {

	public static function types() {
		return array(
			'evardia'   => 'eVardia (evardia.gr) – σύνδεση με τους κωδικούς σας, ωράρια + χτυπήματα από τη σελίδα «Επιλεκτική αποστολή»',
			'web_login' => 'Άλλη web εφαρμογή με κωδικούς (γενικό login + ανάγνωση σελίδας/αναφοράς)',
			'webhook'  => 'Webhook / push (το eVardia, Zapier, Make ή άλλο σύστημα στέλνει τα χτυπήματα εδώ)',
			'api_json' => 'eVardia API (JSON) – το plugin τα κατεβάζει μόνο του',
			'csv_url'  => 'CSV/Excel export από URL – το plugin το κατεβάζει μόνο του',
			'manual'   => 'Χειροκίνητα (μόνο από τον πίνακα «Σήμερα»)',
		);
	}

	/**
	 * Pull today's punches from the configured source (api_json / csv_url).
	 *
	 * @return array|WP_Error {records:int, matched:int, new:int, unmatched:array}
	 */
	public static function sync( $day = null ) {
		$day  = $day ? $day : LGTKS_Settings::now( 'Y-m-d' );
		$type = LGTKS_Settings::get( 'source_type' );
		if ( ! self::is_pull( $type ) ) {
			return array( 'records' => 0, 'matched' => 0, 'new' => 0, 'unmatched' => array(), 'skipped' => true );
		}
		if ( 'evardia' === $type ) {
			$res = LGTKS_Evardia::sync();
			if ( is_wp_error( $res ) ) {
				LGTKS_DB::log( 'error', 'Συγχρονισμός eVardia απέτυχε: ' . $res->get_error_message() );
				update_option( 'lgt_ks_last_sync', array( 'at' => LGTKS_Settings::now( 'Y-m-d H:i:s' ), 'ok' => false, 'msg' => $res->get_error_message() ) );
				LGTKS_Health::record_sync_result( false, $res->get_error_message() );
			} else {
				LGTKS_Health::record_sync_result( true );
			}
			return $res;
		}
		$records = self::fetch( $type, $day );
		if ( is_wp_error( $records ) ) {
			LGTKS_DB::log( 'error', 'Συγχρονισμός πηγής απέτυχε: ' . $records->get_error_message() );
			update_option( 'lgt_ks_last_sync', array( 'at' => LGTKS_Settings::now( 'Y-m-d H:i:s' ), 'ok' => false, 'msg' => $records->get_error_message() ) );
			return $records;
		}
		$res = self::ingest( $records, $type );
		update_option(
			'lgt_ks_last_sync',
			array(
				'at'  => LGTKS_Settings::now( 'Y-m-d H:i:s' ),
				'ok'  => true,
				'msg' => sprintf( '%d εγγραφές, %d αντιστοιχίστηκαν, %d νέες', $res['records'], $res['matched'], $res['new'] ),
			)
		);
		LGTKS_DB::log( 'info', 'Συγχρονισμός πηγής', $res );
		return $res;
	}

	/**
	 * Store normalized records: each {employee: string, datetime: string, kind: 'in'|'out'|''}.
	 */
	public static function ingest( array $records, $source ) {
		$index = self::employee_index();
		$out   = array( 'records' => count( $records ), 'matched' => 0, 'new' => 0, 'unmatched' => array() );
		$in_v  = trim( (string) LGTKS_Settings::get( 'source_kind_in_value' ) );
		$newp  = array();
		foreach ( $records as $r ) {
			$who = isset( $r['employee'] ) ? trim( (string) $r['employee'] ) : '';
			$ts  = self::parse_datetime( $r['datetime'] ?? '' );
			if ( '' === $who || ! $ts ) {
				continue;
			}
			$kind = isset( $r['kind'] ) ? trim( (string) $r['kind'] ) : '';
			if ( '' !== $in_v && '' !== $kind && strcasecmp( $kind, $in_v ) !== 0 ) {
				$kind = 'out';
			} else {
				$kind = 'in';
			}
			$emp_id = self::match( $who, $index );
			if ( ! $emp_id ) {
				$out['unmatched'][ $who ] = true;
				continue;
			}
			$out['matched']++;
			if ( LGTKS_DB::add_punch( $emp_id, $ts, $kind, $source, $who ) ) {
				$out['new']++;
				if ( substr( $ts, 0, 10 ) === LGTKS_Settings::now( 'Y-m-d' ) ) {
					$newp[] = array( 'employee_id' => $emp_id, 'punched_at' => $ts, 'kind' => $kind, 'source' => $source );
				}
			}
		}
		$out['unmatched'] = array_keys( $out['unmatched'] );
		if ( $newp ) {
			$out['punch_emails'] = LGTKS_Checker::email_new_punches( $newp );
		}
		return $out;
	}

	/** Source types where the plugin pulls data itself. */
	public static function is_pull( $type ) {
		return in_array( $type, array( 'api_json', 'csv_url', 'web_login', 'evardia' ), true );
	}

	/** Fetch normalized records for a pull-type source. */
	public static function fetch( $type, $day ) {
		switch ( $type ) {
			case 'api_json':
				return self::fetch_json( $day );
			case 'csv_url':
				return self::fetch_csv( $day );
			case 'web_login':
				return LGTKS_WebLogin::fetch( $day );
		}
		return new WP_Error( 'lgtks', 'Άγνωστος τύπος πηγής.' );
	}

	/* ---------- fetchers ---------- */

	private static function placeholders( $day ) {
		$tz = LGTKS_Settings::tz();
		$d  = new DateTime( $day, $tz );
		return array(
			'{date}'      => $d->format( 'Y-m-d' ),
			'{date_dmy}'  => $d->format( 'd/m/Y' ),
			'{date_dmy2}' => $d->format( 'd-m-Y' ),
			'{date_ymd}'  => $d->format( 'Ymd' ),
			'{from}'      => $d->format( 'Y-m-d' ) . 'T00:00:00',
			'{to}'        => $d->format( 'Y-m-d' ) . 'T23:59:59',
			'{ts_from}'   => (string) $d->getTimestamp(),
			'{ts_to}'     => (string) ( $d->getTimestamp() + DAY_IN_SECONDS - 1 ),
		);
	}

	private static function request( $day ) {
		$url = trim( (string) LGTKS_Settings::get( 'source_url' ) );
		if ( '' === $url ) {
			return new WP_Error( 'lgtks', 'Λείπει το URL της πηγής (Ρυθμίσεις → Πηγή χτυπημάτων)' );
		}
		$ph      = self::placeholders( $day );
		$url     = strtr( $url, $ph );
		$method  = strtoupper( (string) LGTKS_Settings::get( 'source_method', 'GET' ) );
		$headers = LGTKS_Settings::parse_headers( LGTKS_Settings::get( 'source_headers' ) );
		$args    = array( 'timeout' => 30, 'method' => $method, 'headers' => $headers );
		if ( 'GET' !== $method ) {
			$args['body'] = strtr( (string) LGTKS_Settings::get( 'source_body' ), $ph );
		}
		$resp = wp_remote_request( $url, $args );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'lgtks', 'HTTP ' . $code . ': ' . substr( (string) wp_remote_retrieve_body( $resp ), 0, 300 ) );
		}
		return (string) wp_remote_retrieve_body( $resp );
	}

	public static function fetch_json( $day ) {
		$body = self::request( $day );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$data = json_decode( $body, true );
		if ( null === $data ) {
			return new WP_Error( 'lgtks', 'Η απάντηση δεν είναι έγκυρο JSON: ' . substr( $body, 0, 200 ) );
		}
		$list = self::dig( $data, LGTKS_Settings::get( 'source_records_path' ) );
		if ( ! is_array( $list ) ) {
			return new WP_Error( 'lgtks', 'Δεν βρέθηκε λίστα εγγραφών στη διαδρομή «' . LGTKS_Settings::get( 'source_records_path' ) . '». Κλειδιά που υπάρχουν: ' . implode( ', ', array_slice( array_keys( (array) $data ), 0, 20 ) ) );
		}
		$f_emp  = (string) LGTKS_Settings::get( 'source_field_employee' );
		$f_dt   = (string) LGTKS_Settings::get( 'source_field_datetime' );
		$f_kind = (string) LGTKS_Settings::get( 'source_field_kind' );
		if ( '' === $f_emp || '' === $f_dt ) {
			return new WP_Error( 'lgtks', 'Συμπληρώστε τα πεδία «εργαζόμενος» και «ημερομηνία/ώρα» στις ρυθμίσεις πηγής.' );
		}
		$out = array();
		foreach ( $list as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$out[] = array(
				'employee' => self::stringify( self::dig( $row, $f_emp ) ),
				'datetime' => self::stringify( self::dig( $row, $f_dt ) ),
				'kind'     => '' !== $f_kind ? self::stringify( self::dig( $row, $f_kind ) ) : '',
			);
		}
		return $out;
	}

	public static function fetch_csv( $day ) {
		$body = self::request( $day );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		return self::parse_csv( $body );
	}

	public static function parse_csv( $body ) {
		$body = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $body );
		if ( ! mb_check_encoding( $body, 'UTF-8' ) ) {
			$body = mb_convert_encoding( $body, 'UTF-8', 'Windows-1253' );
		}
		$delim = (string) LGTKS_Settings::get( 'csv_delimiter', ';' );
		if ( 'tab' === $delim || '\t' === $delim ) {
			$delim = "\t";
		}
		if ( '' === $delim ) {
			$delim = ';';
		}
		$lines = preg_split( '/\r\n|\r|\n/', trim( $body ) );
		if ( count( $lines ) < 1 ) {
			return array();
		}
		$header = array_map( 'trim', str_getcsv( array_shift( $lines ), $delim ) );
		$f_emp  = (string) LGTKS_Settings::get( 'source_field_employee' );
		$f_dt   = (string) LGTKS_Settings::get( 'source_field_datetime' );
		$f_kind = (string) LGTKS_Settings::get( 'source_field_kind' );
		$i_emp  = self::col_index( $header, $f_emp );
		$i_dt   = self::col_index( $header, $f_dt );
		$i_kind = '' !== $f_kind ? self::col_index( $header, $f_kind ) : null;
		if ( null === $i_emp || null === $i_dt ) {
			return new WP_Error( 'lgtks', 'Δεν βρέθηκαν οι στήλες «' . $f_emp . '» / «' . $f_dt . '». Στήλες αρχείου: ' . implode( ' | ', $header ) );
		}
		$out = array();
		foreach ( $lines as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$cells = str_getcsv( $line, $delim );
			$out[] = array(
				'employee' => isset( $cells[ $i_emp ] ) ? $cells[ $i_emp ] : '',
				'datetime' => isset( $cells[ $i_dt ] ) ? $cells[ $i_dt ] : '',
				'kind'     => ( null !== $i_kind && isset( $cells[ $i_kind ] ) ) ? $cells[ $i_kind ] : '',
			);
		}
		return $out;
	}

	/** Column by header name (case/accents-insensitive) or by 1-based number. */
	private static function col_index( array $header, $name ) {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return null;
		}
		if ( ctype_digit( $name ) ) {
			return (int) $name - 1;
		}
		$n = self::norm( $name );
		foreach ( $header as $i => $h ) {
			if ( self::norm( $h ) === $n ) {
				return $i;
			}
		}
		return null;
	}

	/* ---------- helpers ---------- */

	/** "a.b.0.c" path into nested arrays; '' returns the root. */
	public static function dig( $data, $path ) {
		$path = trim( (string) $path );
		if ( '' === $path ) {
			return $data;
		}
		foreach ( explode( '.', $path ) as $k ) {
			if ( is_array( $data ) && array_key_exists( $k, $data ) ) {
				$data = $data[ $k ];
			} else {
				return null;
			}
		}
		return $data;
	}

	private static function stringify( $v ) {
		if ( is_array( $v ) ) {
			return implode( ' ', array_map( 'strval', array_filter( $v, 'is_scalar' ) ) );
		}
		return null === $v ? '' : (string) $v;
	}

	/** Parse many date formats into site-local 'Y-m-d H:i:s'. */
	public static function parse_datetime( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return null;
		}
		$tz  = LGTKS_Settings::tz();
		$fmt = trim( (string) LGTKS_Settings::get( 'source_date_format' ) );
		$dt  = false;
		if ( '' !== $fmt ) {
			$dt = DateTime::createFromFormat( $fmt, $raw, $tz );
		}
		if ( ! $dt && ctype_digit( $raw ) && strlen( $raw ) >= 10 ) {
			$dt = new DateTime( '@' . substr( $raw, 0, 10 ) );
			$dt->setTimezone( $tz );
		}
		if ( ! $dt ) {
			// d/m/Y H:i[:s] (Greek style) first, then anything strtotime understands.
			if ( preg_match( '/^(\d{1,2})[\/\.-](\d{1,2})[\/\.-](\d{4})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?$/', $raw, $m ) ) {
				$dt = new DateTime( 'now', $tz );
				$dt->setDate( (int) $m[3], (int) $m[2], (int) $m[1] );
				$dt->setTime( isset( $m[4] ) ? (int) $m[4] : 0, isset( $m[5] ) ? (int) $m[5] : 0, isset( $m[6] ) ? (int) $m[6] : 0 );
			} else {
				try {
					$dt = new DateTime( $raw, $tz );
					$dt->setTimezone( $tz );
				} catch ( Exception $e ) {
					return null;
				}
			}
		}
		return $dt ? $dt->format( 'Y-m-d H:i:s' ) : null;
	}

	/** lowercase, strip accents/punctuation, collapse spaces. */
	public static function norm( $s ) {
		$s = mb_strtolower( (string) $s, 'UTF-8' );
		$s = remove_accents( $s );
		$s = strtr( $s, array( 'ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ώ' => 'ω', 'ϊ' => 'ι', 'ϋ' => 'υ', 'ΐ' => 'ι', 'ΰ' => 'υ', 'ς' => 'σ' ) );
		$s = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $s );
		return trim( preg_replace( '/\s+/', ' ', $s ) );
	}

	private static function name_key( $s ) {
		$parts = explode( ' ', self::norm( $s ) );
		sort( $parts );
		return implode( ' ', $parts );
	}

	public static function employee_index() {
		$idx = array( 'ext' => array(), 'mobile' => array(), 'name' => array() );
		foreach ( LGTKS_DB::employees() as $e ) {
			$id = (int) $e['id'];
			if ( '' !== trim( $e['external_id'] ) ) {
				$idx['ext'][ self::norm( $e['external_id'] ) ] = $id;
			}
			$m = LGTKS_SMS::normalize( $e['mobile'] );
			if ( '' !== $m ) {
				$idx['mobile'][ $m ] = $id;
			}
			$idx['name'][ self::name_key( $e['name'] ) ] = $id;
		}
		return $idx;
	}

	/** Match a free-text identifier (code, ΑΦΜ, phone or name) to an employee id. */
	public static function match( $who, array $idx = null ) {
		$idx = null === $idx ? self::employee_index() : $idx;
		$n   = self::norm( $who );
		if ( isset( $idx['ext'][ $n ] ) ) {
			return $idx['ext'][ $n ];
		}
		if ( preg_match( '/^[\d\s\+\-\(\)]{8,}$/', $who ) ) {
			$m = LGTKS_SMS::normalize( $who );
			if ( isset( $idx['mobile'][ $m ] ) ) {
				return $idx['mobile'][ $m ];
			}
		}
		$k = self::name_key( $who );
		if ( isset( $idx['name'][ $k ] ) ) {
			return $idx['name'][ $k ];
		}
		return 0;
	}
}
