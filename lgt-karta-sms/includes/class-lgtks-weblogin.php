<?php
/**
 * Source "web_login": log in to a web application (e.g. evardia.gr) with username/password,
 * keep the session cookies, fetch the page/report with the day's punches and parse it
 * (JSON, HTML table or CSV). Hidden form fields (CSRF, __VIEWSTATE…) are copied automatically.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LGTKS_WebLogin {

	const COOKIE_TRANSIENT = 'lgtks_weblogin_cookies';

	/** Diagnostics of the last login attempt (shown by the settings test). */
	public static $debug = array();

	const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36 LGT-Karta-SMS';

	private static function headers( array $extra = array() ) {
		return array_merge( array( 'User-Agent' => self::UA, 'Accept-Language' => 'el-GR,el;q=0.9,en;q=0.8' ), $extra );
	}

	/** Config from the generic web_login settings. */
	public static function cfg_from_settings() {
		return array(
			'login_url'        => trim( (string) LGTKS_Settings::get( 'wl_login_url' ) ),
			'username'         => (string) LGTKS_Settings::get( 'wl_username' ),
			'password'         => (string) LGTKS_Settings::get( 'wl_password' ),
			'user_field'       => trim( (string) LGTKS_Settings::get( 'wl_user_field', 'username' ) ),
			'pass_field'       => trim( (string) LGTKS_Settings::get( 'wl_pass_field', 'password' ) ),
			'extra_fields'     => (string) LGTKS_Settings::get( 'wl_extra_fields' ),
			'success_contains' => trim( (string) LGTKS_Settings::get( 'wl_success_contains' ) ),
			'data_url'         => trim( (string) LGTKS_Settings::get( 'wl_data_url' ) ),
			'cookie_key'       => self::COOKIE_TRANSIENT,
		);
	}

	/**
	 * Fetch and parse the day's records.
	 *
	 * @return array|WP_Error list of {employee, datetime, kind}
	 */
	public static function fetch( $day ) {
		$body = self::get_data( $day );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		return self::parse( $body );
	}

	/** Raw body of the data page (for the settings "test" button). */
	public static function get_data( $day, $retry = true, array $cfg = null ) {
		$cfg      = null === $cfg ? self::cfg_from_settings() : $cfg;
		$data_url = $cfg['data_url'];
		if ( '' === $data_url ) {
			return new WP_Error( 'lgtks', 'Λείπει το URL της σελίδας/αναφοράς με τα χτυπήματα.' );
		}
		$cookies = get_transient( $cfg['cookie_key'] );
		if ( ! is_array( $cookies ) || ! $cookies ) {
			$cookies = self::login( $cfg );
			if ( is_wp_error( $cookies ) ) {
				return $cookies;
			}
		}
		$ph   = self::placeholders( $day );
		$url  = strtr( $data_url, $ph );
		$resp = wp_remote_get(
			$url,
			array(
				'timeout'     => 30,
				'cookies'     => $cookies,
				'redirection' => 5,
				'user-agent'  => self::UA,
				'headers'     => self::headers( array( 'Accept' => 'application/json, text/html, text/csv, */*' ) ),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$body = (string) wp_remote_retrieve_body( $resp );
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $code === 401 || $code === 403 || self::looks_like_login_page( $body ) ) {
			delete_transient( $cfg['cookie_key'] );
			if ( $retry ) {
				return self::get_data( $day, false, $cfg );
			}
			return new WP_Error( 'lgtks', 'Η σύνδεση δεν έγινε δεκτή (η σελίδα δεδομένων επέστρεψε τη φόρμα login). Ελέγξτε κωδικούς και ονόματα πεδίων.' );
		}
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'lgtks', 'HTTP ' . $code . ' από τη σελίδα δεδομένων: ' . substr( wp_strip_all_tags( $body ), 0, 200 ) );
		}
		return $body;
	}

	/**
	 * Log in and return the cookie array (also cached).
	 *
	 * @return array|WP_Error
	 */
	public static function login( array $cfg = null ) {
		$cfg       = null === $cfg ? self::cfg_from_settings() : $cfg;
		$login_url = $cfg['login_url'];
		$user      = $cfg['username'];
		$pass      = $cfg['password'];
		$uf        = '' !== $cfg['user_field'] ? $cfg['user_field'] : 'username';
		$pf        = '' !== $cfg['pass_field'] ? $cfg['pass_field'] : 'password';
		if ( '' === $user || '' === $pass ) {
			return new WP_Error( 'lgtks', 'Συμπληρώστε όνομα χρήστη και κωδικό.' );
		}
		if ( '' === $login_url ) {
			// Discover the login page: request the data page anonymously and follow the redirect.
			$login_url = '' !== $cfg['data_url'] ? $cfg['data_url'] : '';
		}
		if ( '' === $login_url ) {
			return new WP_Error( 'lgtks', 'Λείπει το URL σύνδεσης.' );
		}
		self::$debug = array( 'requested_login_url' => $login_url );
		// 1. GET the login page: cookies + hidden fields.
		$resp = wp_remote_get( $login_url, array( 'timeout' => 30, 'redirection' => 5, 'user-agent' => self::UA, 'headers' => self::headers() ) );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$final = self::final_url( $resp );
		if ( $final ) {
			$login_url = $final;
		}
		$cookies = self::merge_cookies( array(), $resp );
		$html    = (string) wp_remote_retrieve_body( $resp );
		self::$debug['login_page_url']  = $login_url;
		self::$debug['login_page_http'] = (int) wp_remote_retrieve_response_code( $resp );
		self::$debug['cookies_after_get'] = array_keys( $cookies );
		if ( ! self::looks_like_login_page( $html ) ) {
			return new WP_Error( 'lgtks', 'Η σελίδα «' . $login_url . '» δεν περιέχει φόρμα σύνδεσης (πεδίο κωδικού). Δώστε το ακριβές URL login.' );
		}
		$form    = self::find_form( $html, $pf, $login_url );
		$fields  = $form ? $form['fields'] : array();
		$action  = $form ? $form['action'] : $login_url;
		if ( $form && '' !== $form['user_field'] && 'username' === $uf ) {
			$uf = $form['user_field']; // auto-detected name of the text input next to the password.
		}
		if ( $form && '' !== $form['pass_field'] && ( 'password' === $pf || ! array_key_exists( $pf, $form['all_names'] ) ) ) {
			$pf = $form['pass_field']; // auto-detected name of the password input.
		}
		self::$debug['form_found']  = (bool) $form;
		self::$debug['form_action'] = $action;
		self::$debug['user_field']  = $uf;
		self::$debug['pass_field']  = $pf;
		self::$debug['form_fields'] = $form ? array_keys( $form['all_names'] ) : array();
		$fields[ $uf ] = $user;
		$fields[ $pf ] = $pass;
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $cfg['extra_fields'] ) as $line ) {
			if ( strpos( $line, '=' ) !== false ) {
				list( $k, $v )       = explode( '=', $line, 2 );
				$fields[ trim( $k ) ] = trim( $v );
			}
		}
		// 2. POST credentials (do not follow redirects: we want the Set-Cookie of this response).
		// The read-only guard permits exactly this one POST, to this form action, and nothing else.
		LGTKS_Guard::$login_post_allowed = true;
		LGTKS_Guard::$login_post_action  = $action;
		$resp = wp_remote_post(
			$action,
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'cookies'     => $cookies,
				'body'        => $fields,
				'user-agent'  => self::UA,
				'headers'     => self::headers( array( 'Referer' => $login_url, 'Origin' => preg_replace( '#^(https?://[^/]+).*$#', '$1', $action ) ) ),
			)
		);
		LGTKS_Guard::$login_post_allowed = false;
		LGTKS_Guard::$login_post_action  = '';
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$cookies = self::merge_cookies( $cookies, $resp );
		$code    = (int) wp_remote_retrieve_response_code( $resp );
		$body    = (string) wp_remote_retrieve_body( $resp );
		self::$debug['post_http']     = $code;
		self::$debug['post_location'] = (string) wp_remote_retrieve_header( $resp, 'location' );
		self::$debug['cookies_after_post'] = array_keys( $cookies );
		self::$debug['post_body_text'] = mb_substr( trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( preg_replace( '#<(script|style)[^>]*>.*?</\1>#si', ' ', $body ) ) ) ), 0, 1200 );
		// 3. Follow one redirect manually (typical after a successful login) to collect more cookies.
		$loc = wp_remote_retrieve_header( $resp, 'location' );
		if ( $loc && in_array( $code, array( 301, 302, 303, 307 ), true ) ) {
			$next = self::absolute_url( $loc, $action );
			$r2   = wp_remote_get( $next, array( 'timeout' => 30, 'redirection' => 3, 'cookies' => $cookies, 'user-agent' => self::UA, 'headers' => self::headers() ) );
			if ( ! is_wp_error( $r2 ) ) {
				$cookies = self::merge_cookies( $cookies, $r2 );
				$body    = (string) wp_remote_retrieve_body( $r2 );
			}
		}
		$must = (string) $cfg['success_contains'];
		if ( '' !== $must && stripos( $body, $must ) === false ) {
			return new WP_Error( 'lgtks', 'Η σύνδεση φαίνεται να απέτυχε: δεν βρέθηκε το κείμενο «' . $must . '» μετά το login (HTTP ' . $code . ').' );
		}
		if ( '' === $must && self::looks_like_login_page( $body ) && $code < 300 ) {
			return new WP_Error( 'lgtks', 'Η σύνδεση απέτυχε: η σελίδα μετά το login εξακολουθεί να ζητά κωδικό (HTTP ' . $code . ').' );
		}
		if ( ! $cookies ) {
			return new WP_Error( 'lgtks', 'Δεν δόθηκε cookie συνεδρίας από τον server μετά το login.' );
		}
		set_transient( $cfg['cookie_key'], $cookies, 20 * MINUTE_IN_SECONDS );
		LGTKS_DB::log( 'info', 'Σύνδεση web (login) επιτυχής', array( 'cookies' => array_keys( $cookies ) ) );
		return $cookies;
	}

	/* ---------- parsing ---------- */

	public static function parse( $body ) {
		$trim = ltrim( $body );
		$fmt  = (string) LGTKS_Settings::get( 'wl_format', 'auto' );
		if ( 'auto' === $fmt ) {
			if ( '' !== $trim && ( '{' === $trim[0] || '[' === $trim[0] ) ) {
				$fmt = 'json';
			} elseif ( stripos( $body, '<table' ) !== false ) {
				$fmt = 'html';
			} else {
				$fmt = 'csv';
			}
		}
		if ( 'json' === $fmt ) {
			$data = json_decode( $body, true );
			if ( null === $data ) {
				return new WP_Error( 'lgtks', 'Η απάντηση δεν είναι JSON: ' . substr( wp_strip_all_tags( $body ), 0, 200 ) );
			}
			$list = LGTKS_Source::dig( $data, LGTKS_Settings::get( 'source_records_path' ) );
			if ( ! is_array( $list ) ) {
				return new WP_Error( 'lgtks', 'Δεν βρέθηκε λίστα στη διαδρομή «' . LGTKS_Settings::get( 'source_records_path' ) . '». Κλειδιά: ' . implode( ', ', array_slice( array_keys( (array) $data ), 0, 20 ) ) );
			}
			return self::map_rows( $list, true );
		}
		if ( 'html' === $fmt ) {
			$table = self::html_table( $body );
			if ( is_wp_error( $table ) ) {
				return $table;
			}
			return self::map_rows( $table, false );
		}
		return LGTKS_Source::parse_csv( $body );
	}

	/** Map rows (assoc arrays) to {employee, datetime, kind} using the configured field names. */
	private static function map_rows( array $rows, $json ) {
		$f_emp  = (string) LGTKS_Settings::get( 'source_field_employee' );
		$f_dt   = (string) LGTKS_Settings::get( 'source_field_datetime' );
		$f_kind = (string) LGTKS_Settings::get( 'source_field_kind' );
		$f_time = (string) LGTKS_Settings::get( 'wl_field_time' );
		if ( '' === $f_emp || '' === $f_dt ) {
			return new WP_Error( 'lgtks', 'Συμπληρώστε τα πεδία/στήλες «εργαζόμενος» και «ημερομηνία/ώρα».' . ( $rows && ! $json ? ' Στήλες που βρέθηκαν: ' . implode( ' | ', array_keys( $rows[0] ) ) : '' ) );
		}
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$emp  = self::cell( $row, $f_emp, $json );
			$dt   = self::cell( $row, $f_dt, $json );
			$time = '' !== $f_time ? self::cell( $row, $f_time, $json ) : '';
			if ( '' !== $time && '' !== $dt && ! preg_match( '/\d{1,2}:\d{2}/', $dt ) ) {
				$dt .= ' ' . $time; // separate date and time columns
			}
			$out[] = array(
				'employee' => $emp,
				'datetime' => $dt,
				'kind'     => '' !== $f_kind ? self::cell( $row, $f_kind, $json ) : '',
			);
		}
		return $out;
	}

	private static function cell( array $row, $name, $json ) {
		if ( $json ) {
			$v = LGTKS_Source::dig( $row, $name );
			return is_array( $v ) ? implode( ' ', array_filter( $v, 'is_scalar' ) ) : (string) ( null === $v ? '' : $v );
		}
		$name = trim( (string) $name );
		if ( ctype_digit( $name ) ) {
			$vals = array_values( $row );
			return isset( $vals[ (int) $name - 1 ] ) ? $vals[ (int) $name - 1 ] : '';
		}
		$n = LGTKS_Source::norm( $name );
		foreach ( $row as $k => $v ) {
			if ( LGTKS_Source::norm( $k ) === $n ) {
				return (string) $v;
			}
		}
		return '';
	}

	/**
	 * Parse the most relevant <table> into rows keyed by header text.
	 *
	 * @return array|WP_Error
	 */
	public static function html_table( $html ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return new WP_Error( 'lgtks', 'Λείπει η επέκταση PHP DOM (php-xml) από τον server.' );
		}
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();
		$want   = trim( (string) LGTKS_Settings::get( 'wl_table_hint' ) );
		$tables = $dom->getElementsByTagName( 'table' );
		$best   = null;
		$best_n = -1;
		foreach ( $tables as $t ) {
			$rows = $t->getElementsByTagName( 'tr' )->length;
			$txt  = $t->textContent;
			if ( '' !== $want ) {
				if ( mb_stripos( $txt, $want ) === false && strpos( (string) $t->getAttribute( 'id' ), $want ) === false && strpos( (string) $t->getAttribute( 'class' ), $want ) === false ) {
					continue;
				}
			}
			if ( $rows > $best_n ) {
				$best   = $t;
				$best_n = $rows;
			}
		}
		if ( ! $best ) {
			return new WP_Error( 'lgtks', 'Δεν βρέθηκε πίνακας (<table>) στη σελίδα' . ( $want ? ' με «' . $want . '»' : '' ) . '. Αν τα δεδομένα φορτώνουν με JavaScript, χρειάζεται το URL του εσωτερικού αιτήματος (βλ. οδηγίες).' );
		}
		$header = array();
		$out    = array();
		foreach ( $best->getElementsByTagName( 'tr' ) as $tr ) {
			$cells = array();
			$is_h  = false;
			foreach ( $tr->childNodes as $c ) {
				if ( XML_ELEMENT_NODE !== $c->nodeType ) {
					continue;
				}
				$tag = strtolower( $c->nodeName );
				if ( 'th' === $tag ) {
					$is_h = true;
				}
				if ( 'th' === $tag || 'td' === $tag ) {
					$cells[] = trim( preg_replace( '/\s+/u', ' ', $c->textContent ) );
				}
			}
			if ( ! $cells ) {
				continue;
			}
			if ( ! $header && ( $is_h || ! $out ) ) {
				$header = $cells;
				continue;
			}
			$row = array();
			foreach ( $cells as $i => $v ) {
				$key         = isset( $header[ $i ] ) && '' !== $header[ $i ] ? $header[ $i ] : 'col' . ( $i + 1 );
				$row[ $key ] = $v;
			}
			$out[] = $row;
		}
		return $out;
	}

	/* ---------- helpers ---------- */

	private static function placeholders( $day ) {
		$d = new DateTime( $day, LGTKS_Settings::tz() );
		return array(
			'{date}'      => $d->format( 'Y-m-d' ),
			'{date_dmy}'  => $d->format( 'd/m/Y' ),
			'{date_dmy2}' => $d->format( 'd-m-Y' ),
			'{date_ymd}'  => $d->format( 'Ymd' ),
			'{day}'       => $d->format( 'd' ),
			'{month}'     => $d->format( 'm' ),
			'{year}'      => $d->format( 'Y' ),
		);
	}

	/** Final URL after redirects, when the HTTP layer exposes it. */
	public static function final_url( $resp ) {
		try {
			if ( is_array( $resp ) && isset( $resp['http_response'] ) && is_object( $resp['http_response'] ) && method_exists( $resp['http_response'], 'get_response_object' ) ) {
				$o = $resp['http_response']->get_response_object();
				if ( is_object( $o ) && ! empty( $o->url ) ) {
					return (string) $o->url;
				}
			}
		} catch ( Exception $e ) {
			return '';
		}
		return '';
	}

	public static function looks_like_login_page( $html ) {
		return (bool) preg_match( '/<input[^>]+type=["\']?password/i', (string) $html );
	}

	/** Merge Set-Cookie of a response into a name => value map (what wp_remote_* accepts). */
	private static function merge_cookies( array $cookies, $resp ) {
		$new = wp_remote_retrieve_cookies( $resp );
		foreach ( (array) $new as $c ) {
			if ( $c instanceof WP_Http_Cookie ) {
				$cookies[ $c->name ] = $c->value;
			}
		}
		return $cookies;
	}

	public static function absolute_url( $url, $base ) {
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		$p = wp_parse_url( $base );
		$root = $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
		if ( strpos( $url, '/' ) === 0 ) {
			return $root . $url;
		}
		$path = isset( $p['path'] ) ? preg_replace( '#/[^/]*$#', '/', $p['path'] ) : '/';
		return $root . $path . $url;
	}

	/**
	 * Find the form containing the password field; return its action and all input values
	 * (hidden fields incl. CSRF/__VIEWSTATE), plus the detected username field name.
	 */
	public static function find_form( $html, $pass_field, $page_url ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return null;
		}
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();
		foreach ( $dom->getElementsByTagName( 'form' ) as $form ) {
			$inputs   = $form->getElementsByTagName( 'input' );
			$has_pass = false;
			foreach ( $inputs as $in ) {
				if ( strtolower( (string) $in->getAttribute( 'type' ) ) === 'password' || $in->getAttribute( 'name' ) === $pass_field ) {
					$has_pass = true;
					break;
				}
			}
			if ( ! $has_pass ) {
				continue;
			}
			$fields     = array();
			$user_field = '';
			$pass_name  = '';
			$all        = array();
			foreach ( $inputs as $in ) {
				$name = (string) $in->getAttribute( 'name' );
				$type = strtolower( (string) $in->getAttribute( 'type' ) );
				if ( '' === $name ) {
					continue;
				}
				$all[ $name ] = $type;
				if ( 'password' === $type ) {
					if ( '' === $pass_name ) {
						$pass_name = $name;
					}
					continue;
				}
				if ( in_array( $type, array( 'text', 'email', '' ), true ) && '' === $user_field ) {
					$user_field = $name;
					continue;
				}
				if ( in_array( $type, array( 'submit', 'button', 'image', 'reset' ), true ) ) {
					continue; // ASP.NET needs the submit button name sometimes; add it via "extra fields" if required.
				}
				if ( 'checkbox' === $type && ! $in->hasAttribute( 'checked' ) ) {
					continue;
				}
				$fields[ $name ] = (string) $in->getAttribute( 'value' );
			}
			$action = (string) $form->getAttribute( 'action' );
			$action = '' === $action ? $page_url : self::absolute_url( html_entity_decode( $action ), $page_url );
			return array( 'action' => $action, 'fields' => $fields, 'user_field' => $user_field, 'pass_field' => $pass_name, 'all_names' => $all );
		}
		return null;
	}
}
