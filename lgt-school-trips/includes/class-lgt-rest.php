<?php
/**
 * REST API (namespace lgt/v1) used by the school page and the admin view.
 *
 * Auth:
 *  - Admin: logged-in user with the plugin capability + X-WP-Nonce.
 *  - School: X-LGT-Token header matching the trip token ("open" allows saving,
 *            "closed" is read-only) + access-code cookie when the trip has a code.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LGT_Rest {

	const NS = 'lgt/v1';

	public static function register_routes() {
		$base = '/trips/(?P<id>\d+)';
		$r    = function ( $route, $methods, $cb, $write = true ) {
			register_rest_route(
				self::NS,
				$route,
				array(
					'methods'             => $methods,
					'callback'            => array( __CLASS__, $cb ),
					'permission_callback' => function ( $req ) use ( $write ) {
						return LGT_Rest::permission( $req, $write );
					},
				)
			);
		};
		$r( $base . '/bootstrap', 'GET', 'bootstrap', false );
		$r( $base . '/data', 'PUT', 'save_data' );
		$r( $base . '/submit', 'POST', 'submit' );
		$r( $base . '/transliterate', 'POST', 'transliterate', false );
		$r( $base . '/link', 'POST', 'link_action' );
		$r( $base . '/send', 'POST', 'send_now' );
	}

	private static function set_ctx( WP_REST_Request $req, $ctx ) {
		$attrs            = $req->get_attributes();
		$attrs['lgt_ctx'] = $ctx;
		$req->set_attributes( $attrs );
	}

	private static function is_admin_ctx( WP_REST_Request $req ) {
		$attrs = $req->get_attributes();
		return isset( $attrs['lgt_ctx'] ) && 'admin' === $attrs['lgt_ctx'];
	}

	public static function permission( WP_REST_Request $req, $write ) {
		$trip = LGT_DB::get_trip( (int) $req['id'] );
		if ( ! $trip ) {
			return new WP_Error( 'lgt_not_found', 'Η εκδρομή δεν βρέθηκε.', array( 'status' => 404 ) );
		}
		if ( is_user_logged_in() && LGT_Plugin::current_user_can_manage() ) {
			$nonce = $req->get_header( 'X-WP-Nonce' );
			if ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				self::set_ctx( $req, 'admin' );
				return true;
			}
		}
		$token = $req->get_header( 'X-LGT-Token' );
		if ( ! $token || ! $trip['token'] || ! hash_equals( $trip['token'], (string) $token ) ) {
			return new WP_Error( 'lgt_forbidden', 'Μη έγκυρος σύνδεσμος.', array( 'status' => 403 ) );
		}
		if ( ! in_array( $trip['status'], array( 'open', 'closed' ), true ) ) {
			return new WP_Error( 'lgt_inactive', 'Ο σύνδεσμος δεν είναι ενεργός.', array( 'status' => 403 ) );
		}
		if ( $trip['access_code'] && ! LGT_Portal::access_cookie_valid( $trip ) ) {
			return new WP_Error( 'lgt_code', 'Απαιτείται κωδικός πρόσβασης.', array( 'status' => 403 ) );
		}
		if ( $write && 'open' !== $trip['status'] ) {
			return new WP_Error( 'lgt_closed', 'Η φόρμα έχει κλείσει. Επικοινωνήστε με το γραφείο για αλλαγές.', array( 'status' => 423 ) );
		}
		self::set_ctx( $req, 'school' );
		return true;
	}

	private static function require_admin( WP_REST_Request $req ) {
		if ( ! self::is_admin_ctx( $req ) ) {
			return new WP_Error( 'lgt_forbidden', 'Μόνο για διαχειριστές.', array( 'status' => 403 ) );
		}
		return true;
	}

	private static function err( $msg, $status = 400 ) {
		return new WP_Error( 'lgt_error', $msg, array( 'status' => $status ) );
	}

	public static function state( array $trip, WP_REST_Request $req ) {
		$admin = self::is_admin_ctx( $req );
		$out   = array(
			'trip'    => array(
				'id'              => $trip['id'],
				'title'           => $trip['title'],
				'school_name'     => $trip['school_name'],
				'school_email'    => $trip['school_email'],
				'destination'     => $trip['destination'],
				'departure_date'  => $trip['departure_date'],
				'return_date'     => $trip['return_date'],
				'status'          => $trip['status'],
				'has_hotel'       => (bool) $trip['has_hotel'],
				'has_ferry'       => (bool) $trip['has_ferry'],
				'hotel_name'      => $trip['hotel_name'],
				'ferry_company'   => $trip['ferry_company'],
				'notes_school'    => $trip['notes_school'],
				'submitted_at'    => $trip['submitted_at'],
				'data_updated_at' => $trip['data_updated_at'],
			),
			'form'    => $trip['form'],
			'rooming' => $trip['rooming'],
			'cabins'  => $trip['cabins'],
			'summary' => LGT_Data::summary( $trip ),
			'config'  => array(
				'mode'     => $admin ? 'admin' : 'school',
				'readonly' => ! $admin && 'open' !== $trip['status'],
				'company'  => array(
					'name'  => LGT_Settings::get( 'company_name' ),
					'phone' => LGT_Settings::get( 'company_phone' ),
					'email' => LGT_Settings::get( 'company_email' ),
				),
			),
		);
		if ( $admin ) {
			$out['trip']['token']      = $trip['token'];
			$out['trip']['portal_url'] = $trip['token'] ? LGT_Portal::url( $trip ) : '';
			$out['trip']['access_code'] = $trip['access_code'];
		}
		return $out;
	}

	public static function bootstrap( WP_REST_Request $req ) {
		return rest_ensure_response( self::state( LGT_DB::get_trip( (int) $req['id'] ), $req ) );
	}

	/** { form: [...], rooming: {...}, cabins: {...}|null } */
	public static function save_data( WP_REST_Request $req ) {
		$trip = LGT_DB::get_trip( (int) $req['id'] );
		$body = (array) $req->get_json_params();
		$form    = LGT_Data::sanitize_form( $body['form'] ?? array() );
		$rooming = LGT_Data::sanitize_rooming( $body['rooming'] ?? null, 'hotel', $trip );
		$cabins  = $trip['has_ferry'] ? LGT_Data::sanitize_rooming( $body['cabins'] ?? null, 'cabin', $trip ) : null;
		LGT_DB::save_data( $trip['id'], $form, $rooming, $cabins );
		$trip = LGT_DB::get_trip( $trip['id'] );
		return rest_ensure_response( array( 'ok' => true, 'summary' => LGT_Data::summary( $trip ), 'saved_at' => current_time( 'mysql' ) ) );
	}

	public static function submit( WP_REST_Request $req ) {
		$trip = LGT_DB::get_trip( (int) $req['id'] );
		$body = (array) $req->get_json_params();
		if ( isset( $body['form'] ) || isset( $body['rooming'] ) ) {
			// Save first so the files contain exactly what the school sees.
			$form    = LGT_Data::sanitize_form( $body['form'] ?? array() );
			$rooming = LGT_Data::sanitize_rooming( $body['rooming'] ?? null, 'hotel', $trip );
			$cabins  = $trip['has_ferry'] ? LGT_Data::sanitize_rooming( $body['cabins'] ?? null, 'cabin', $trip ) : null;
			LGT_DB::save_data( $trip['id'], $form, $rooming, $cabins );
			$trip = LGT_DB::get_trip( $trip['id'] );
		}
		if ( ! LGT_Data::filled_rows( $trip['form'] ) && ! LGT_Data::rooming_summary( $trip['rooming'] )['names'] ) {
			return self::err( 'Συμπληρώστε τουλάχιστον ένα όνομα πριν την υποβολή.' );
		}
		if ( ! LGT_Settings::office_emails() ) {
			return self::err( 'Δεν έχουν οριστεί email γραφείου στις ρυθμίσεις.' );
		}
		$msg = sanitize_textarea_field( (string) ( $body['message'] ?? '' ) );
		$by_admin = self::is_admin_ctx( $req );
		$ok  = LGT_Mailer::send_submission( $trip, $msg, $by_admin );
		LGT_DB::log( $trip['id'], $by_admin ? 'admin' : 'school', 'submit', $ok ? 'OK' : 'Αποτυχία αποστολής email' );
		if ( ! $ok ) {
			return self::err( 'Η αποστολή email απέτυχε. Δοκιμάστε ξανά ή επικοινωνήστε με το γραφείο.', 500 );
		}
		if ( ! $by_admin && LGT_Settings::get( 'close_on_submit' ) ) {
			LGT_DB::update_trip( $trip['id'], array( 'status' => 'closed' ) );
			LGT_DB::log( $trip['id'], 'system', 'link_close', 'Αυτόματο κλείσιμο μετά την υποβολή' );
		}
		return rest_ensure_response( self::state( LGT_DB::get_trip( $trip['id'] ), $req ) );
	}

	public static function transliterate( WP_REST_Request $req ) {
		$body = (array) $req->get_json_params();
		$out  = array();
		foreach ( (array) ( $body['texts'] ?? array() ) as $k => $t ) {
			$out[ $k ] = LGT_Transliterator::to_latin( (string) $t );
		}
		return rest_ensure_response( array( 'results' => $out ) );
	}

	/** Admin: { action: generate|regenerate|open|close|archive|send_link, message? } */
	public static function link_action( WP_REST_Request $req ) {
		$check = self::require_admin( $req );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$trip   = LGT_DB::get_trip( (int) $req['id'] );
		$body   = (array) $req->get_json_params();
		$action = $body['action'] ?? '';
		$upd    = array();
		switch ( $action ) {
			case 'generate':
			case 'regenerate':
				$upd['token']  = LGT_DB::generate_token();
				$upd['status'] = 'open';
				break;
			case 'open':
				if ( ! $trip['token'] ) {
					$upd['token'] = LGT_DB::generate_token();
				}
				$upd['status'] = 'open';
				break;
			case 'close':
				$upd['status'] = 'closed';
				break;
			case 'archive':
				$upd['status'] = 'archived';
				break;
			case 'send_link':
				$ok = LGT_Mailer::send_link( $trip, (string) ( $body['message'] ?? '' ) );
				LGT_DB::log( $trip['id'], 'admin', 'send_link', $ok ? $trip['school_email'] : 'Αποτυχία' );
				$st = self::state( LGT_DB::get_trip( $trip['id'] ), $req );
				$st['sent'] = (bool) $ok;
				return rest_ensure_response( $st );
			default:
				return self::err( 'Άγνωστη ενέργεια.' );
		}
		LGT_DB::update_trip( $trip['id'], $upd );
		LGT_DB::log( $trip['id'], 'admin', 'link_' . $action );
		return rest_ensure_response( self::state( LGT_DB::get_trip( $trip['id'] ), $req ) );
	}

	public static function send_now( WP_REST_Request $req ) {
		$check = self::require_admin( $req );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$trip = LGT_DB::get_trip( (int) $req['id'] );
		$body = (array) $req->get_json_params();
		$ok   = LGT_Mailer::send_submission( $trip, sanitize_textarea_field( (string) ( $body['message'] ?? '' ) ), true );
		LGT_DB::log( $trip['id'], 'admin', 'send_now', $ok ? 'OK' : 'Αποτυχία' );
		if ( ! $ok ) {
			return self::err( 'Η αποστολή email απέτυχε (ελέγξτε τις ρυθμίσεις email του WordPress).', 500 );
		}
		$st = self::state( LGT_DB::get_trip( $trip['id'] ), $req );
		$st['sent'] = true;
		return rest_ensure_response( $st );
	}
}
