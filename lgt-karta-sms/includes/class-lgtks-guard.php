<?php
/**
 * Read-only guard for the external web application (evardia.gr).
 *
 * Every HTTP request WordPress makes is inspected via 'pre_http_request'. For the guarded hosts
 * only these are allowed:
 *   - GET  to the data page, the site root, the login page (configured or any path containing "login"),
 *   - POST to the login form, and only while LGTKS_WebLogin is performing the login step.
 * Anything else (any other POST/PUT/DELETE, any other path) is blocked before it leaves the server
 * and written to the log. This holds for every code path of the plugin, including bugs.
 * Requests are also recorded in an audit trail visible in the settings page.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LGTKS_Guard {

	const AUDIT_OPTION = 'lgt_ks_http_audit';

	/** Set by LGTKS_WebLogin only around the login POST. */
	public static $login_post_allowed = false;
	public static $login_post_action  = '';

	public static function init() {
		add_filter( 'pre_http_request', array( __CLASS__, 'inspect' ), 1, 3 );
		add_action( 'http_api_debug', array( __CLASS__, 'record_response' ), 10, 5 );
	}

	/** Hosts under guard: the eVardia base URL and the generic web-login URLs. */
	public static function hosts() {
		$hosts = array();
		foreach ( array( LGTKS_Settings::get( 'ev_base_url' ), LGTKS_Settings::get( 'ev_login_url' ), LGTKS_Settings::get( 'wl_login_url' ), LGTKS_Settings::get( 'wl_data_url' ) ) as $u ) {
			$h = strtolower( (string) wp_parse_url( (string) $u, PHP_URL_HOST ) );
			if ( '' !== $h ) {
				$hosts[ $h ] = true;
			}
		}
		if ( ! $hosts ) {
			$hosts['evardia.gr'] = true;
		}
		return array_keys( $hosts );
	}

	public static function is_guarded_host( $url ) {
		$h = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
		if ( '' === $h ) {
			return false;
		}
		foreach ( self::hosts() as $g ) {
			if ( $h === $g || substr( $h, -strlen( '.' . $g ) ) === '.' . $g ) {
				return true;
			}
		}
		return false;
	}

	/** Paths a GET may touch. */
	private static function allowed_get_paths() {
		$paths = array( '/', '/ergazomenos/epilektikhapostolh' );
		foreach ( array( LGTKS_Settings::get( 'ev_login_url' ), LGTKS_Settings::get( 'wl_login_url' ), LGTKS_Settings::get( 'wl_data_url' ) ) as $u ) {
			$p = strtolower( rtrim( (string) wp_parse_url( (string) $u, PHP_URL_PATH ), '/' ) );
			if ( '' !== $p ) {
				$paths[] = $p;
			}
		}
		return $paths;
	}

	/**
	 * Decide for one outgoing request. Returning a WP_Error cancels the request.
	 */
	public static function inspect( $pre, $args, $url ) {
		if ( ! self::is_guarded_host( $url ) ) {
			return $pre;
		}
		$method = strtoupper( isset( $args['method'] ) ? (string) $args['method'] : 'GET' );
		$path   = strtolower( rtrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ) );
		$path   = '' === $path ? '/' : $path;
		$ok     = false;
		$why    = '';

		if ( 'GET' === $method || 'HEAD' === $method ) {
			if ( in_array( $path, self::allowed_get_paths(), true ) || false !== strpos( $path, 'login' ) || false !== strpos( $path, 'signin' ) || false !== strpos( $path, 'account' ) ) {
				$ok = true;
			} else {
				$why = 'GET σε μη επιτρεπόμενη σελίδα';
			}
		} elseif ( 'POST' === $method ) {
			if ( self::$login_post_allowed && self::$login_post_action && self::same_endpoint( $url, self::$login_post_action ) ) {
				$ok = true;
			} else {
				$why = 'POST εκτός φόρμας σύνδεσης';
			}
		} else {
			$why = 'μέθοδος ' . $method . ' δεν επιτρέπεται';
		}

		self::audit( $method, $url, $ok ? 'allowed' : 'BLOCKED', $why );
		if ( $ok ) {
			return $pre;
		}
		LGTKS_DB::log( 'error', 'ΦΡΟΥΡΟΣ ΜΟΝΟ-ΑΝΑΓΝΩΣΗΣ: αποκλείστηκε αίτημα προς ' . wp_parse_url( $url, PHP_URL_HOST ) . ' (' . $why . ')', array( 'method' => $method, 'url' => $url ) );
		return new WP_Error( 'lgtks_readonly', 'Αποκλείστηκε από τον κανόνα «μόνο ανάγνωση»: ' . $method . ' ' . $path . ' (' . $why . ').' );
	}

	private static function same_endpoint( $a, $b ) {
		$pa = wp_parse_url( $a );
		$pb = wp_parse_url( $b );
		return strtolower( $pa['host'] ?? '' ) === strtolower( $pb['host'] ?? '' ) && rtrim( $pa['path'] ?? '/', '/' ) === rtrim( $pb['path'] ?? '/', '/' );
	}

	/* ---------- audit trail ---------- */

	private static function audit( $method, $url, $result, $why = '' ) {
		$trail = get_option( self::AUDIT_OPTION, array() );
		if ( ! is_array( $trail ) ) {
			$trail = array();
		}
		$p       = wp_parse_url( $url );
		$trail[] = array(
			'at'     => current_time( 'mysql' ),
			'method' => $method,
			'path'   => ( $p['path'] ?? '/' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' ),
			'host'   => $p['host'] ?? '',
			'result' => $result . ( $why ? ' – ' . $why : '' ),
			'status' => '',
		);
		if ( count( $trail ) > 60 ) {
			$trail = array_slice( $trail, -60 );
		}
		update_option( self::AUDIT_OPTION, $trail, false );
	}

	/** Attach the HTTP status to the last audit line for this URL. */
	public static function record_response( $response, $context, $class, $args, $url ) {
		if ( ! self::is_guarded_host( $url ) || is_wp_error( $response ) ) {
			return;
		}
		$trail = get_option( self::AUDIT_OPTION, array() );
		if ( ! is_array( $trail ) || ! $trail ) {
			return;
		}
		for ( $i = count( $trail ) - 1; $i >= 0; $i-- ) {
			if ( '' === $trail[ $i ]['status'] ) {
				$trail[ $i ]['status'] = (string) wp_remote_retrieve_response_code( $response );
				break;
			}
		}
		update_option( self::AUDIT_OPTION, $trail, false );
	}

	public static function trail() {
		$t = get_option( self::AUDIT_OPTION, array() );
		return is_array( $t ) ? array_reverse( $t ) : array();
	}
}
