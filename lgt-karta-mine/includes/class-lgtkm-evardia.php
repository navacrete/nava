<?php
/**
 * eVardia client for ONE employee: login, read own row, submit clock-in/out via «Επιλεκτική αποστολή».
 * Writes are limited to exactly two endpoints and to the configured ΑΦΜ.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LGTKM_Evardia {

	const COOKIES = 'lgtkm_cookies';
	const UA      = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36 LGT-Karta-Mine';

	/** Paths this class may POST to (besides the login form). Filled in once the scanner flow is known. */
	public static $write_paths = array();
	/** Set only while a write is in flight; the read-only guard of the other plugin honours it. */
	public static $writing = false;

	public static function opt( $k, $d = '' ) {
		$o = get_option( LGT_KM_OPTION, array() );
		return is_array( $o ) && array_key_exists( $k, $o ) ? $o[ $k ] : $d;
	}

	public static function base() {
		$b = rtrim( trim( (string) self::opt( 'base_url', 'https://evardia.gr' ) ), '/' );
		return '' !== $b ? $b : 'https://evardia.gr';
	}

	private static function headers( array $extra = array() ) {
		return array_merge( array( 'User-Agent' => self::UA, 'Accept-Language' => 'el-GR,el;q=0.9' ), $extra );
	}

	private static function merge_cookies( array $c, $resp ) {
		foreach ( (array) wp_remote_retrieve_cookies( $resp ) as $ck ) {
			if ( $ck instanceof WP_Http_Cookie ) {
				$c[ $ck->name ] = $ck->value;
			}
		}
		return $c;
	}

	private static function is_login_page( $html ) {
		return (bool) preg_match( '/<input[^>]+type=["\']?password/i', (string) $html );
	}

	/* ---------- login ---------- */

	public static function login() {
		$user = (string) self::opt( 'username' );
		$pass = (string) self::opt( 'password' );
		if ( '' === $user || '' === $pass ) {
			return new WP_Error( 'lgtkm', 'Λείπουν όνομα χρήστη/κωδικός eVardia.' );
		}
		$page = self::base() . '/Ergazomenos/EpilektikhApostolh';
		$resp = wp_remote_get( $page, array( 'timeout' => 30, 'redirection' => 5, 'user-agent' => self::UA, 'headers' => self::headers() ) );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$cookies = self::merge_cookies( array(), $resp );
		$html    = (string) wp_remote_retrieve_body( $resp );
		if ( ! self::is_login_page( $html ) ) {
			return new WP_Error( 'lgtkm', 'Δεν βρέθηκε φόρμα σύνδεσης.' );
		}
		$final = '';
		if ( isset( $resp['http_response'] ) && is_object( $resp['http_response'] ) && method_exists( $resp['http_response'], 'get_response_object' ) ) {
			$o     = $resp['http_response']->get_response_object();
			$final = is_object( $o ) && ! empty( $o->url ) ? (string) $o->url : '';
		}
		$login_url = $final ? $final : $page;
		// Parse the form.
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();
		$fields = array();
		$uf     = '';
		$pf     = '';
		$action = $login_url;
		foreach ( $dom->getElementsByTagName( 'form' ) as $form ) {
			$has = false;
			foreach ( $form->getElementsByTagName( 'input' ) as $in ) {
				if ( strtolower( (string) $in->getAttribute( 'type' ) ) === 'password' ) {
					$has = true;
				}
			}
			if ( ! $has ) {
				continue;
			}
			foreach ( $form->getElementsByTagName( 'input' ) as $in ) {
				$n = (string) $in->getAttribute( 'name' );
				$t = strtolower( (string) $in->getAttribute( 'type' ) );
				if ( '' === $n ) {
					continue;
				}
				if ( 'password' === $t ) {
					$pf = $pf ? $pf : $n;
				} elseif ( in_array( $t, array( 'text', 'email', '' ), true ) && '' === $uf ) {
					$uf = $n;
				} elseif ( ! in_array( $t, array( 'submit', 'button', 'image', 'reset' ), true ) && ( 'checkbox' !== $t || $in->hasAttribute( 'checked' ) ) ) {
					$fields[ $n ] = (string) $in->getAttribute( 'value' );
				}
			}
			$a = html_entity_decode( (string) $form->getAttribute( 'action' ) );
			if ( '' !== $a ) {
				$p      = wp_parse_url( $login_url );
				$root   = $p['scheme'] . '://' . $p['host'];
				$action = preg_match( '#^https?://#', $a ) ? $a : ( strpos( $a, '/' ) === 0 ? $root . $a : $root . preg_replace( '#/[^/]*$#', '/', $p['path'] ?? '/' ) . $a );
			}
			break;
		}
		$fields[ $uf ? $uf : 'username' ] = $user;
		$fields[ $pf ? $pf : 'password' ] = $pass;
		$resp = wp_remote_post(
			$action,
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'cookies'     => $cookies,
				'body'        => $fields,
				'user-agent'  => self::UA,
				'headers'     => self::headers( array( 'Referer' => $login_url ) ),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$cookies = self::merge_cookies( $cookies, $resp );
		$code    = (int) wp_remote_retrieve_response_code( $resp );
		$loc     = (string) wp_remote_retrieve_header( $resp, 'location' );
		if ( $loc && in_array( $code, array( 301, 302, 303, 307 ), true ) ) {
			$next = preg_match( '#^https?://#', $loc ) ? $loc : self::base() . $loc;
			$r2   = wp_remote_get( $next, array( 'timeout' => 30, 'redirection' => 3, 'cookies' => $cookies, 'user-agent' => self::UA, 'headers' => self::headers() ) );
			if ( ! is_wp_error( $r2 ) ) {
				$cookies = self::merge_cookies( $cookies, $r2 );
				if ( self::is_login_page( (string) wp_remote_retrieve_body( $r2 ) ) ) {
					return new WP_Error( 'lgtkm', 'Η σύνδεση στο eVardia απέτυχε (λάθος κωδικοί;).' );
				}
			}
		} elseif ( self::is_login_page( (string) wp_remote_retrieve_body( $resp ) ) ) {
			return new WP_Error( 'lgtkm', 'Η σύνδεση στο eVardia απέτυχε (λάθος κωδικοί;).' );
		}
		set_transient( self::COOKIES, $cookies, 20 * MINUTE_IN_SECONDS );
		return $cookies;
	}

	private static function cookies() {
		$c = get_transient( self::COOKIES );
		return is_array( $c ) && $c ? $c : self::login();
	}

	/* ---------- read my row ---------- */

	public static function page_url( $all = false ) {
		$yp = trim( (string) self::opt( 'ypokatasthma', '0' ) );
		return self::base() . '/Ergazomenos/EpilektikhApostolh?fetchAll=' . ( $all ? 'True' : 'False' ) . '&ypokatasthma=' . rawurlencode( '' === $yp ? '0' : $yp );
	}

	public static function rows( $all = false, $retry = true ) {
		$cookies = self::cookies();
		if ( is_wp_error( $cookies ) ) {
			return $cookies;
		}
		$resp = wp_remote_get( self::page_url( $all ), array( 'timeout' => 30, 'cookies' => $cookies, 'redirection' => 5, 'user-agent' => self::UA, 'headers' => self::headers() ) );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$html = (string) wp_remote_retrieve_body( $resp );
		if ( self::is_login_page( $html ) ) {
			delete_transient( self::COOKIES );
			return $retry ? self::rows( $all, false ) : new WP_Error( 'lgtkm', 'Η συνεδρία eVardia έληξε και η επανασύνδεση απέτυχε.' );
		}
		return self::parse_rows( $html );
	}

	public static function parse_rows( $html ) {
		$pos = strpos( (string) $html, "JSON.parse('" );
		if ( false === $pos ) {
			return new WP_Error( 'lgtkm', 'Δεν βρέθηκαν δεδομένα στη σελίδα του eVardia.' );
		}
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
				break;
			}
			$buf .= $ch;
			$i++;
		}
		$js = preg_replace_callback(
			'/\\\\(u[0-9a-fA-F]{4}|.)/s',
			function ( $m ) {
				$c = $m[1];
				if ( 'u' === $c[0] && strlen( $c ) === 5 ) {
					return mb_convert_encoding( pack( 'n', hexdec( substr( $c, 1 ) ) ), 'UTF-8', 'UTF-16BE' );
				}
				$map = array( 'n' => "\n", 'r' => "\r", 't' => "\t" );
				return isset( $map[ $c ] ) ? $map[ $c ] : $c;
			},
			$buf
		);
		$rows = json_decode( $js, true );
		return is_array( $rows ) ? $rows : new WP_Error( 'lgtkm', 'Τα δεδομένα του eVardia δεν αποκωδικοποιήθηκαν.' );
	}

	/** My row (by configured ΑΦΜ) from today's list; falls back to the full list. */
	public static function my_row() {
		$afm = preg_replace( '/\D+/', '', (string) self::opt( 'my_afm' ) );
		if ( '' === $afm ) {
			return new WP_Error( 'lgtkm', 'Δεν έχει οριστεί το ΑΦΜ σας στις ρυθμίσεις.' );
		}
		foreach ( array( false, true ) as $all ) {
			$rows = self::rows( $all );
			if ( is_wp_error( $rows ) ) {
				return $rows;
			}
			foreach ( $rows as $r ) {
				if ( preg_replace( '/\D+/', '', (string) ( $r['afm'] ?? '' ) ) === $afm ) {
					return $r;
				}
			}
		}
		return new WP_Error( 'lgtkm', 'Το ΑΦΜ ' . $afm . ' δεν βρέθηκε στη λίστα εργαζομένων του eVardia.' );
	}

	/** Compact status derived from a row. */
	public static function status( array $r ) {
		$t = function ( $v ) {
			return preg_match( '/(\d{1,2}):(\d{2})/', (string) $v, $m ) ? sprintf( '%02d:%02d', $m[1], $m[2] ) : '';
		};
		$shifts = array();
		for ( $i = 1; $i <= 3; $i++ ) {
			$s = array( 'start' => $t( $r[ 'orarioApo' . $i ] ?? '' ), 'end' => $t( $r[ 'orarioEos' . $i ] ?? '' ), 'in' => $t( $r[ 'proselefsh' . $i ] ?? '' ), 'out' => $t( $r[ 'apoxorhsh' . $i ] ?? '' ), 'in_ok' => strpos( (string) ( $r[ 'proselefsh' . $i ] ?? '' ), '✓' ) !== false, 'out_ok' => strpos( (string) ( $r[ 'apoxorhsh' . $i ] ?? '' ), '✓' ) !== false );
			if ( $s['start'] || $s['in'] || $s['out'] ) {
				$shifts[] = $s;
			}
		}
		// Last event decides what the next sensible action is.
		$last = '';
		for ( $i = 3; $i >= 1; $i-- ) {
			if ( $t( $r[ 'apoxorhsh' . $i ] ?? '' ) ) {
				$last = 'out';
				break;
			}
			if ( $t( $r[ 'proselefsh' . $i ] ?? '' ) ) {
				$last = 'in';
				break;
			}
		}
		return array(
			'name'      => (string) ( $r['onomateponymo'] ?? '' ),
			'day'       => (string) ( $r['hmeromhnia'] ?? '' ),
			'branch'    => (string) ( $r['ypokatasthmaPerigrafh'] ?? '' ),
			'shifts'    => $shifts,
			'last'      => $last,
			'next'      => 'in' === $last ? 'out' : 'in',
			'protocol'  => (string) ( $r['protokollo'] ?? '' ),
		);
	}

	/*
	 * Write (clock in / out): intentionally not implemented yet. It must mimic the eVardia camera
	 * scanner page (/Ergazomenos/ApostolhCamera), NOT «Επιλεκτική αποστολή». Awaiting the saved
	 * HTML of the scanner page to learn the exact request.
	 */

	/** jQuery-style form encoding of nested arrays (a[b][0][c]=v). */
	public static function form_encode( array $data, $prefix = '' ) {
		$parts = array();
		foreach ( $data as $k => $v ) {
			$key = '' === $prefix ? (string) $k : $prefix . '[' . $k . ']';
			if ( is_array( $v ) ) {
				$parts[] = self::form_encode( $v, $key );
			} else {
				$parts[] = rawurlencode( $key ) . '=' . rawurlencode( (string) $v );
			}
		}
		return implode( '&', array_filter( $parts, 'strlen' ) );
	}
}
