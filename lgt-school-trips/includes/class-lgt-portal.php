<?php
/**
 * Public school portal: /school-trip/{token}
 * Renders a standalone page (independent from the theme) hosting the JS app.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LGT_Portal {

	public static function slug() {
		$slug = sanitize_title( LGT_Settings::get( 'portal_slug', 'school-trip' ) );
		return $slug ? $slug : 'school-trip';
	}

	public static function register_rewrites() {
		add_rewrite_rule( '^' . preg_quote( self::slug(), '/' ) . '/([A-Za-z0-9]{8,64})/?$', 'index.php?lgt_trip_token=$matches[1]', 'top' );
	}

	public static function query_vars( $vars ) {
		$vars[] = 'lgt_trip_token';
		return $vars;
	}

	public static function url( array $trip ) {
		if ( ! $trip['token'] ) {
			return '';
		}
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( '/' . self::slug() . '/' . $trip['token'] . '/' );
		}
		return add_query_arg( 'lgt_trip_token', $trip['token'], home_url( '/' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Access code cookie                                                 */
	/* ------------------------------------------------------------------ */

	private static function cookie_name( array $trip ) {
		return 'lgt_ac_' . $trip['id'];
	}

	private static function cookie_value( array $trip ) {
		return hash_hmac( 'sha256', $trip['id'] . '|' . $trip['token'] . '|' . $trip['access_code'], wp_salt( 'auth' ) );
	}

	public static function access_cookie_valid( array $trip ) {
		if ( ! $trip['access_code'] ) {
			return true;
		}
		$name = self::cookie_name( $trip );
		return isset( $_COOKIE[ $name ] ) && hash_equals( self::cookie_value( $trip ), (string) $_COOKIE[ $name ] );
	}

	private static function set_access_cookie( array $trip ) {
		setcookie( self::cookie_name( $trip ), self::cookie_value( $trip ), time() + 30 * DAY_IN_SECONDS, '/', '', is_ssl(), true );
		$_COOKIE[ self::cookie_name( $trip ) ] = self::cookie_value( $trip );
	}

	/* ------------------------------------------------------------------ */
	/* Rendering                                                          */
	/* ------------------------------------------------------------------ */

	public static function maybe_render() {
		$token = get_query_var( 'lgt_trip_token' );
		if ( ! $token && isset( $_GET['lgt_trip_token'] ) ) {
			$token = sanitize_text_field( wp_unslash( $_GET['lgt_trip_token'] ) );
		}
		if ( ! $token ) {
			return;
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
		$trip = LGT_DB::get_trip_by_token( $token );
		if ( ! $trip || ! in_array( $trip['status'], array( 'open', 'closed' ), true ) ) {
			status_header( 404 );
			self::render_template( 'portal-inactive', array( 'trip' => $trip ) );
			exit;
		}
		// Access code.
		if ( $trip['access_code'] && ! self::access_cookie_valid( $trip ) ) {
			$error = '';
			if ( isset( $_POST['lgt_access_code'] ) ) {
				check_admin_referer( 'lgt_access_' . $trip['id'] );
				$code = trim( (string) wp_unslash( $_POST['lgt_access_code'] ) );
				if ( hash_equals( $trip['access_code'], $code ) ) {
					self::set_access_cookie( $trip );
					wp_safe_redirect( self::url( $trip ) );
					exit;
				}
				$error = 'Λάθος κωδικός πρόσβασης.';
			}
			self::render_template( 'portal-code', array( 'trip' => $trip, 'error' => $error ) );
			exit;
		}
		// Downloads.
		if ( isset( $_GET['lgt_dl'] ) ) {
			self::handle_download( $trip );
			exit;
		}
		self::render_template( 'portal', array( 'trip' => $trip ) );
		exit;
	}

	private static function handle_download( array $trip ) {
		$format = 'pdf' === ( $_GET['lgt_dl'] ?? '' ) ? 'pdf' : 'xlsx';
		$list   = isset( $_GET['list'] ) ? sanitize_key( $_GET['list'] ) : 'all';
		if ( ! in_array( $list, LGT_Exporter::LISTS, true ) ) {
			$list = 'all';
		}
		$path = 'pdf' === $format ? LGT_Exporter::build_pdf( $trip['id'], $list ) : LGT_Exporter::build_xlsx( $trip['id'], $list );
		LGT_DB::log( $trip['id'], 'school', 'download', $format . ' ' . $list );
		LGT_Exporter::stream( $path );
	}

	public static function render_template( $name, array $vars = array() ) {
		extract( $vars ); // phpcs:ignore
		$file = LGT_ST_DIR . 'templates/' . $name . '.php';
		if ( file_exists( $file ) ) {
			include $file;
		}
	}

	/**
	 * JS config for the app (shared with admin).
	 */
	public static function app_config( array $trip, $mode ) {
		$cfg = array(
			'mode'        => $mode,
			'tripId'      => $trip['id'],
			'restUrl'     => esc_url_raw( rest_url( LGT_Rest::NS . '/' ) ),
			'token'       => 'school' === $mode ? $trip['token'] : '',
			'nonce'       => 'admin' === $mode ? wp_create_nonce( 'wp_rest' ) : '',
			'downloadUrl' => 'admin' === $mode
				? add_query_arg( array( 'action' => 'lgt_download', 'trip' => $trip['id'], '_wpnonce' => wp_create_nonce( 'lgt_download_' . $trip['id'] ) ), admin_url( 'admin-post.php' ) )
				: add_query_arg( array( 'lgt_trip_token' => $trip['token'] ), home_url( '/' ) ),
			'adminUrl'    => 'admin' === $mode ? admin_url( 'admin.php?page=lgt-trip&id=' . $trip['id'] ) : '',
			'company'     => array(
				'name'  => LGT_Settings::get( 'company_name' ),
				'phone' => LGT_Settings::get( 'company_phone' ),
				'email' => LGT_Settings::get( 'company_email' ),
			),
		);
		return $cfg;
	}

	public static function shortcode( $atts ) {
		$token = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : '';
		if ( ! $token ) {
			return '<p>Χρησιμοποιήστε τον σύνδεσμο που σας έστειλε το γραφείο.</p>';
		}
		$trip = LGT_DB::get_trip_by_token( $token );
		if ( ! $trip || ! in_array( $trip['status'], array( 'open', 'closed' ), true ) ) {
			return '<p>Ο σύνδεσμος δεν είναι ενεργός.</p>';
		}
		if ( $trip['access_code'] && ! self::access_cookie_valid( $trip ) ) {
			return '<p><a href="' . esc_url( self::url( $trip ) ) . '">Συνεχίστε στην πλατφόρμα</a></p>';
		}
		wp_enqueue_style( 'lgt-app', LGT_ST_URL . 'assets/css/app.css', array(), LGT_ST_VERSION );
		wp_enqueue_script( 'lgt-app', LGT_ST_URL . 'assets/js/app.js', array(), LGT_ST_VERSION, true );
		wp_add_inline_script( 'lgt-app', 'window.LGT_APP = ' . wp_json_encode( self::app_config( $trip, 'school' ) ) . ';', 'before' );
		return '<div id="lgt-app" class="lgt-app"></div>';
	}
}
