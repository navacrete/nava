<?php
/**
 * REST API (namespace lgt/v1) used by both the school portal and the admin app.
 *
 * Auth:
 *  - Admin: logged-in user with the plugin capability + X-WP-Nonce.
 *  - School: X-LGT-Token header matching the trip token (trip must be "open" for writes;
 *            "closed" allows read-only) + access-code cookie when the trip has a code.
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
		$r( $base, 'PATCH', 'patch_trip' );
		$r( $base . '/participants', 'POST', 'create_participant' );
		$r( $base . '/participants/import', 'POST', 'import_participants' );
		$r( $base . '/participants/(?P<pid>\d+)', 'PUT', 'update_participant' );
		$r( $base . '/participants/(?P<pid>\d+)', 'DELETE', 'delete_participant' );
		$r( $base . '/rooms', 'POST', 'create_room' );
		$r( $base . '/rooms/assign', 'POST', 'assign' );
		$r( $base . '/rooms/auto', 'POST', 'auto_allocate' );
		$r( $base . '/rooms/copy', 'POST', 'copy_allocation' );
		$r( $base . '/rooms/clear', 'POST', 'clear_allocation' );
		$r( $base . '/rooms/(?P<rid>\d+)', 'PUT', 'update_room' );
		$r( $base . '/rooms/(?P<rid>\d+)', 'DELETE', 'delete_room' );
		$r( $base . '/submit', 'POST', 'submit' );
		$r( $base . '/transliterate', 'POST', 'transliterate', false );
		// Admin only (permission additionally checked inside).
		$r( $base . '/link', 'POST', 'link_action' );
		$r( $base . '/send', 'POST', 'send_now' );
		$r( $base . '/activity', 'GET', 'activity', false );
	}

	/* ------------------------------------------------------------------ */
	/* Auth                                                               */
	/* ------------------------------------------------------------------ */

	/** Context ('admin' | 'school') is stored in request attributes (not user-controllable). */
	private static function set_ctx( WP_REST_Request $req, $ctx ) {
		$attrs            = $req->get_attributes();
		$attrs['lgt_ctx'] = $ctx;
		$req->set_attributes( $attrs );
	}

	public static function permission( WP_REST_Request $req, $write ) {
		$trip = LGT_DB::get_trip( (int) $req['id'] );
		if ( ! $trip ) {
			return new WP_Error( 'lgt_not_found', 'Η εκδρομή δεν βρέθηκε.', array( 'status' => 404 ) );
		}
		// Admin.
		if ( is_user_logged_in() && LGT_Plugin::current_user_can_manage() ) {
			$nonce = $req->get_header( 'X-WP-Nonce' );
			if ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				self::set_ctx( $req, 'admin' );
				return true;
			}
		}
		// School via token.
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
			return new WP_Error( 'lgt_closed', 'Η καταχώρηση έχει κλείσει. Επικοινωνήστε με το γραφείο για αλλαγές.', array( 'status' => 423 ) );
		}
		self::set_ctx( $req, 'school' );
		return true;
	}

	private static function is_admin_ctx( WP_REST_Request $req ) {
		$attrs = $req->get_attributes();
		return isset( $attrs['lgt_ctx'] ) && 'admin' === $attrs['lgt_ctx'];
	}

	private static function actor( WP_REST_Request $req ) {
		return self::is_admin_ctx( $req ) ? 'admin' : 'school';
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

	/* ------------------------------------------------------------------ */
	/* Payload builders                                                   */
	/* ------------------------------------------------------------------ */

	public static function trip_payload( array $trip, $admin ) {
		$out = array(
			'id'             => $trip['id'],
			'title'          => $trip['title'],
			'school_name'    => $trip['school_name'],
			'school_email'   => $trip['school_email'],
			'school_phone'   => $trip['school_phone'],
			'contact_name'   => $trip['contact_name'],
			'destination'    => $trip['destination'],
			'departure_date' => $trip['departure_date'],
			'return_date'    => $trip['return_date'],
			'status'         => $trip['status'],
			'has_hotel'      => (bool) $trip['has_hotel'],
			'has_ferry'      => (bool) $trip['has_ferry'],
			'has_flight'     => (bool) $trip['has_flight'],
			'hotel_name'     => $trip['hotel_name'],
			'hotel_notes'    => $trip['hotel_notes'],
			'ferry_company'  => $trip['ferry_company'],
			'ferry_notes'    => $trip['ferry_notes'],
			'airline'        => $trip['airline'],
			'flight_notes'   => $trip['flight_notes'],
			'room_types'     => $trip['room_types'],
			'cabin_types'    => $trip['cabin_types'],
			'notes_school'   => $trip['notes_school'],
			'submitted_at'   => $trip['submitted_at'],
			'data_updated_at' => $trip['data_updated_at'],
			'required_fields' => LGT_Validator::required_fields( $trip ),
		);
		if ( $admin ) {
			$out['token']          = $trip['token'];
			$out['access_code']    = $trip['access_code'];
			$out['extra_emails']   = $trip['extra_emails'];
			$out['notes_internal'] = $trip['notes_internal'];
			$out['portal_url']     = $trip['token'] ? LGT_Portal::url( $trip ) : '';
			$out['meta']           = $trip['meta'];
		}
		return $out;
	}

	private static function full_state( array $trip, WP_REST_Request $req ) {
		$participants = LGT_DB::get_participants( $trip['id'] );
		$rooms        = LGT_DB::get_rooms( $trip['id'] );
		$assignments  = LGT_DB::get_assignments( $trip['id'] );
		$issues       = array();
		foreach ( $participants as $p ) {
			if ( 'active' !== $p['status'] ) {
				continue;
			}
			$c = LGT_Validator::check( $trip, $p );
			if ( $c['missing'] || $c['warnings'] ) {
				$issues[ $p['id'] ] = $c;
			}
		}
		return array(
			'trip'         => self::trip_payload( $trip, self::is_admin_ctx( $req ) ),
			'participants' => $participants,
			'rooms'        => $rooms,
			'assignments'  => $assignments,
			'issues'       => (object) $issues,
			'summary'      => LGT_Validator::trip_summary( $trip, $participants, $rooms, $assignments ),
		);
	}

	private static function state_response( $trip_id, WP_REST_Request $req, array $extra = array() ) {
		$trip = LGT_DB::get_trip( $trip_id );
		return rest_ensure_response( array_merge( self::full_state( $trip, $req ), $extra ) );
	}

	/* ------------------------------------------------------------------ */
	/* Endpoints                                                          */
	/* ------------------------------------------------------------------ */

	public static function bootstrap( WP_REST_Request $req ) {
		$trip  = LGT_DB::get_trip( (int) $req['id'] );
		$state = self::full_state( $trip, $req );
		$state['config'] = array(
			'mode'                => self::is_admin_ctx( $req ) ? 'admin' : 'school',
			'readonly'            => ! self::is_admin_ctx( $req ) && 'open' !== $trip['status'],
			'field_labels'        => LGT_Validator::field_labels(),
			'default_nationality' => LGT_Settings::get( 'default_nationality', 'GR' ),
			'company_name'        => LGT_Settings::get( 'company_name' ),
			'company_phone'       => LGT_Settings::get( 'company_phone' ),
			'company_email'       => LGT_Settings::get( 'company_email' ),
		);
		return rest_ensure_response( $state );
	}

	public static function patch_trip( WP_REST_Request $req ) {
		$trip  = LGT_DB::get_trip( (int) $req['id'] );
		$body  = $req->get_json_params();
		$admin = self::is_admin_ctx( $req );
		$allowed_school = array( 'contact_name', 'school_phone', 'school_email' );
		$allowed_admin  = array_merge( $allowed_school, array( 'title', 'school_name', 'destination', 'departure_date', 'return_date', 'hotel_name', 'hotel_notes', 'ferry_company', 'ferry_notes', 'airline', 'flight_notes', 'notes_school', 'notes_internal', 'extra_emails', 'has_hotel', 'has_ferry', 'has_flight', 'room_types', 'cabin_types', 'access_code' ) );
		$allowed = $admin ? $allowed_admin : $allowed_school;
		$upd     = array();
		foreach ( $allowed as $k ) {
			if ( ! array_key_exists( $k, (array) $body ) ) {
				continue;
			}
			$v = $body[ $k ];
			switch ( $k ) {
				case 'has_hotel':
				case 'has_ferry':
				case 'has_flight':
					$upd[ $k ] = $v ? 1 : 0;
					break;
				case 'room_types':
					$upd[ $k ] = LGT_Settings::sanitize_types( $v, LGT_Settings::default_room_types() );
					break;
				case 'cabin_types':
					$upd[ $k ] = LGT_Settings::sanitize_types( $v, LGT_Settings::default_cabin_types() );
					break;
				case 'departure_date':
				case 'return_date':
					$upd[ $k ] = LGT_DB::clean_date( $v );
					break;
				case 'school_email':
					$upd[ $k ] = sanitize_email( $v );
					break;
				case 'hotel_notes':
				case 'ferry_notes':
				case 'flight_notes':
				case 'notes_school':
				case 'notes_internal':
				case 'extra_emails':
					$upd[ $k ] = sanitize_textarea_field( (string) $v );
					break;
				default:
					$upd[ $k ] = sanitize_text_field( (string) $v );
			}
		}
		if ( $upd ) {
			LGT_DB::update_trip( $trip['id'], $upd );
			LGT_DB::log( $trip['id'], self::actor( $req ), 'trip_update', array_keys( $upd ) );
		}
		return self::state_response( $trip['id'], $req );
	}

	public static function create_participant( WP_REST_Request $req ) {
		$trip = LGT_DB::get_trip( (int) $req['id'] );
		$body = (array) $req->get_json_params();
		if ( '' === trim( (string) ( $body['last_name'] ?? '' ) ) || '' === trim( (string) ( $body['first_name'] ?? '' ) ) ) {
			return self::err( 'Συμπληρώστε επώνυμο και όνομα.' );
		}
		$id = LGT_DB::insert_participant( $trip['id'], $body );
		LGT_DB::log( $trip['id'], self::actor( $req ), 'participant_add', $body['last_name'] . ' ' . $body['first_name'] );
		return self::state_response( $trip['id'], $req, array( 'created_id' => $id ) );
	}

	public static function update_participant( WP_REST_Request $req ) {
		$trip = LGT_DB::get_trip( (int) $req['id'] );
		$p    = LGT_DB::get_participant( (int) $req['pid'] );
		if ( ! $p || $p['trip_id'] !== $trip['id'] ) {
			return self::err( 'Ο συμμετέχων δεν βρέθηκε.', 404 );
		}
		$body = (array) $req->get_json_params();
		if ( array_key_exists( 'last_name', $body ) && '' === trim( (string) $body['last_name'] ) ) {
			return self::err( 'Το επώνυμο είναι υποχρεωτικό.' );
		}
		LGT_DB::update_participant( $p['id'], $body );
		if ( isset( $body['status'] ) && 'cancelled' === $body['status'] ) {
			// Cancelled people leave their room/cabin.
			LGT_DB::assign( $trip['id'], 'hotel', 0, array( $p['id'] ) );
			LGT_DB::assign( $trip['id'], 'cabin', 0, array( $p['id'] ) );
		}
		LGT_DB::log( $trip['id'], self::actor( $req ), 'participant_update', $p['last_name'] . ' ' . $p['first_name'] . ' (' . implode( ',', array_keys( $body ) ) . ')' );
		return self::state_response( $trip['id'], $req );
	}

	public static function delete_participant( WP_REST_Request $req ) {
		$trip = LGT_DB::get_trip( (int) $req['id'] );
		$p    = LGT_DB::get_participant( (int) $req['pid'] );
		if ( ! $p || $p['trip_id'] !== $trip['id'] ) {
			return self::err( 'Ο συμμετέχων δεν βρέθηκε.', 404 );
		}
		LGT_DB::delete_participant( $p['id'] );
		LGT_DB::log( $trip['id'], self::actor( $req ), 'participant_delete', $p['last_name'] . ' ' . $p['first_name'] );
		return self::state_response( $trip['id'], $req );
	}

	/**
	 * Bulk import: rows = [ {last_name, first_name, gender, birth_date, class_name, ...}, ... ]
	 */
	public static function import_participants( WP_REST_Request $req ) {
		$trip = LGT_DB::get_trip( (int) $req['id'] );
		$body = (array) $req->get_json_params();
		$rows = isset( $body['rows'] ) && is_array( $body['rows'] ) ? $body['rows'] : array();
		if ( ! $rows ) {
			return self::err( 'Δεν βρέθηκαν γραμμές.' );
		}
		if ( count( $rows ) > 500 ) {
			return self::err( 'Μέγιστο 500 γραμμές ανά εισαγωγή.' );
		}
		// Duplicate key: surname + name + birth date (two namesakes with different birthdays are both kept).
		$dup_key  = function ( $ln, $fn, $bd ) {
			return mb_strtoupper( trim( $ln ) . '|' . trim( $fn ) ) . '|' . ( LGT_DB::clean_date( $bd ) ?: '' );
		};
		$existing = array();
		foreach ( LGT_DB::get_participants( $trip['id'] ) as $p ) {
			$existing[ $dup_key( $p['last_name'], $p['first_name'], $p['birth_date'] ) ] = true;
		}
		$added   = 0;
		$skipped = 0;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$ln = trim( (string) ( $row['last_name'] ?? '' ) );
			$fn = trim( (string) ( $row['first_name'] ?? '' ) );
			if ( '' === $ln || '' === $fn ) {
				$skipped++;
				continue;
			}
			$key = $dup_key( $ln, $fn, $row['birth_date'] ?? '' );
			if ( isset( $existing[ $key ] ) && empty( $body['allow_duplicates'] ) ) {
				$skipped++;
				continue;
			}
			$existing[ $key ] = true;
			LGT_DB::insert_participant( $trip['id'], $row );
			$added++;
		}
		LGT_DB::log( $trip['id'], self::actor( $req ), 'participant_import', sprintf( 'Προστέθηκαν %d, παραλείφθηκαν %d', $added, $skipped ) );
		return self::state_response( $trip['id'], $req, array( 'added' => $added, 'skipped' => $skipped ) );
	}

	public static function create_room( WP_REST_Request $req ) {
		$trip = LGT_DB::get_trip( (int) $req['id'] );
		$body = (array) $req->get_json_params();
		$kind = ( $body['kind'] ?? 'hotel' ) === 'cabin' ? 'cabin' : 'hotel';
		$types = 'cabin' === $kind ? $trip['cabin_types'] : $trip['room_types'];
		$code  = strtoupper( (string) ( $body['type_code'] ?? '' ) );
		$cap   = 0;
		foreach ( $types as $t ) {
			if ( $t['code'] === $code ) {
				$cap = (int) $t['capacity'];
			}
		}
		if ( ! $cap ) {
			$cap = max( 1, (int) ( $body['capacity'] ?? 2 ) );
		}
		$count = max( 1, min( 50, (int) ( $body['count'] ?? 1 ) ) );
		$last  = 0;
		for ( $i = 0; $i < $count; $i++ ) {
			$last = LGT_DB::insert_room( $trip['id'], $kind, array( 'type_code' => $code, 'capacity' => $cap, 'label' => $count > 1 ? '' : (string) ( $body['label'] ?? '' ) ) );
		}
		LGT_DB::log( $trip['id'], self::actor( $req ), 'room_add', $kind . ' ' . $code . ' x' . $count );
		return self::state_response( $trip['id'], $req, array( 'created_id' => $last ) );
	}

	public static function update_room( WP_REST_Request $req ) {
		$trip = LGT_DB::get_trip( (int) $req['id'] );
		$room = LGT_DB::get_room( (int) $req['rid'] );
		if ( ! $room || $room['trip_id'] !== $trip['id'] ) {
			return self::err( 'Το δωμάτιο δεν βρέθηκε.', 404 );
		}
		$body = (array) $req->get_json_params();
		if ( isset( $body['type_code'] ) && ! isset( $body['capacity'] ) ) {
			$types = 'cabin' === $room['kind'] ? $trip['cabin_types'] : $trip['room_types'];
			foreach ( $types as $t ) {
				if ( $t['code'] === strtoupper( $body['type_code'] ) ) {
					$body['capacity'] = $t['capacity'];
				}
			}
		}
		LGT_DB::update_room( $room['id'], $body );
		return self::state_response( $trip['id'], $req );
	}

	public static function delete_room( WP_REST_Request $req ) {
		$trip = LGT_DB::get_trip( (int) $req['id'] );
		$room = LGT_DB::get_room( (int) $req['rid'] );
		if ( ! $room || $room['trip_id'] !== $trip['id'] ) {
			return self::err( 'Το δωμάτιο δεν βρέθηκε.', 404 );
		}
		LGT_DB::delete_room( $room['id'] );
		LGT_DB::log( $trip['id'], self::actor( $req ), 'room_delete', $room['kind'] . ' ' . $room['label'] );
		return self::state_response( $trip['id'], $req );
	}

	/** { kind, room_id (0 = unassign), participant_ids: [] } */
	public static function assign( WP_REST_Request $req ) {
		$trip = LGT_DB::get_trip( (int) $req['id'] );
		$body = (array) $req->get_json_params();
		$kind = ( $body['kind'] ?? 'hotel' ) === 'cabin' ? 'cabin' : 'hotel';
		$ids  = array_map( 'intval', (array) ( $body['participant_ids'] ?? array() ) );
		$room_id = (int) ( $body['room_id'] ?? 0 );
		if ( $room_id ) {
			$room = LGT_DB::get_room( $room_id );
			if ( ! $room || $room['trip_id'] !== $trip['id'] || $room['kind'] !== $kind ) {
				return self::err( 'Το δωμάτιο δεν βρέθηκε.', 404 );
			}
		}
		// Ensure participants belong to this trip.
		$valid = array();
		foreach ( LGT_DB::get_participants( $trip['id'], true ) as $p ) {
			$valid[ $p['id'] ] = true;
		}
		$ids = array_values( array_filter( $ids, function ( $id ) use ( $valid ) { return isset( $valid[ $id ] ); } ) );
		if ( ! $ids ) {
			return self::err( 'Δεν επιλέχθηκαν άτομα.' );
		}
		LGT_DB::assign( $trip['id'], $kind, $room_id, $ids );
		return self::state_response( $trip['id'], $req );
	}

	/** { kind, by_class: bool, only_unassigned: bool } */
	public static function auto_allocate( WP_REST_Request $req ) {
		$trip  = LGT_DB::get_trip( (int) $req['id'] );
		$body  = (array) $req->get_json_params();
		$kind  = ( $body['kind'] ?? 'hotel' ) === 'cabin' ? 'cabin' : 'hotel';
		$types = 'cabin' === $kind ? $trip['cabin_types'] : $trip['room_types'];
		if ( ! empty( $body['type_codes'] ) && is_array( $body['type_codes'] ) ) {
			$sel   = array_map( 'strtoupper', $body['type_codes'] );
			$types = array_values( array_filter( $types, function ( $t ) use ( $sel ) { return in_array( $t['code'], $sel, true ); } ) ) ?: $types;
		}
		$participants = LGT_DB::get_participants( $trip['id'], true );
		if ( ! empty( $body['only_unassigned'] ) ) {
			$assigned = array();
			foreach ( LGT_DB::get_assignments( $trip['id'], $kind ) as $a ) {
				$assigned[ $a['participant_id'] ] = true;
			}
			$pool  = array_values( array_filter( $participants, function ( $p ) use ( $assigned ) { return ! isset( $assigned[ $p['id'] ] ); } ) );
			$rooms = LGT_Allocator::auto( $pool, $types, ! isset( $body['by_class'] ) || $body['by_class'] );
			$n     = count( LGT_DB::get_rooms( $trip['id'], $kind ) );
			foreach ( $rooms as $r ) {
				$n++;
				$rid = LGT_DB::insert_room( $trip['id'], $kind, array( 'type_code' => $r['type_code'], 'capacity' => $r['capacity'], 'label' => (string) $n, 'notes' => $r['notes'] ?? '' ) );
				LGT_DB::assign( $trip['id'], $kind, $rid, $r['members'] );
			}
		} else {
			$rooms = LGT_Allocator::auto( $participants, $types, ! isset( $body['by_class'] ) || $body['by_class'] );
			LGT_DB::replace_allocation( $trip['id'], $kind, $rooms );
		}
		LGT_DB::log( $trip['id'], self::actor( $req ), 'auto_allocate', $kind . ' – ' . count( $rooms ) . ' rooms' );
		return self::state_response( $trip['id'], $req, array( 'created_rooms' => count( $rooms ) ) );
	}

	/** { from: 'cabin'|'hotel', to: 'hotel'|'cabin' } – keep the same groups. */
	public static function copy_allocation( WP_REST_Request $req ) {
		$trip = LGT_DB::get_trip( (int) $req['id'] );
		$body = (array) $req->get_json_params();
		$from = ( $body['from'] ?? 'cabin' ) === 'hotel' ? 'hotel' : 'cabin';
		$to   = 'hotel' === $from ? 'cabin' : 'hotel';
		$types = 'cabin' === $to ? $trip['cabin_types'] : $trip['room_types'];
		$participants = array();
		foreach ( LGT_DB::get_participants( $trip['id'], true ) as $p ) {
			$participants[ $p['id'] ] = $p;
		}
		$groups = array();
		foreach ( LGT_DB::get_rooms( $trip['id'], $from ) as $r ) {
			$groups[ $r['id'] ] = array();
		}
		foreach ( LGT_DB::get_assignments( $trip['id'], $from ) as $a ) {
			$groups[ $a['room_id'] ][] = $a['participant_id'];
		}
		$groups = array_values( array_filter( $groups ) );
		if ( ! $groups ) {
			return self::err( 'Δεν υπάρχει κατανομή για αντιγραφή.' );
		}
		$rooms = LGT_Allocator::from_groups( $groups, $participants, $types, true );
		LGT_DB::replace_allocation( $trip['id'], $to, $rooms );
		LGT_DB::log( $trip['id'], self::actor( $req ), 'copy_allocation', $from . ' → ' . $to );
		return self::state_response( $trip['id'], $req, array( 'created_rooms' => count( $rooms ) ) );
	}

	public static function clear_allocation( WP_REST_Request $req ) {
		$trip = LGT_DB::get_trip( (int) $req['id'] );
		$body = (array) $req->get_json_params();
		$kind = ( $body['kind'] ?? 'hotel' ) === 'cabin' ? 'cabin' : 'hotel';
		LGT_DB::clear_rooms( $trip['id'], $kind );
		LGT_DB::log( $trip['id'], self::actor( $req ), 'clear_allocation', $kind );
		return self::state_response( $trip['id'], $req );
	}

	public static function submit( WP_REST_Request $req ) {
		$trip = LGT_DB::get_trip( (int) $req['id'] );
		$body = (array) $req->get_json_params();
		$msg  = sanitize_textarea_field( (string) ( $body['message'] ?? '' ) );
		if ( ! LGT_Settings::office_emails() ) {
			return self::err( 'Δεν έχουν οριστεί email γραφείου στις ρυθμίσεις.' );
		}
		$ok = LGT_Mailer::send_submission( $trip, $msg, self::is_admin_ctx( $req ) );
		LGT_DB::log( $trip['id'], self::actor( $req ), 'submit', $ok ? 'OK' : 'Αποτυχία αποστολής email' );
		if ( ! $ok ) {
			return self::err( 'Η αποστολή email απέτυχε. Δοκιμάστε ξανά ή επικοινωνήστε με το γραφείο.', 500 );
		}
		return self::state_response( $trip['id'], $req, array( 'sent' => true ) );
	}

	public static function transliterate( WP_REST_Request $req ) {
		$body = (array) $req->get_json_params();
		$out  = array();
		foreach ( (array) ( $body['texts'] ?? array() ) as $k => $t ) {
			$out[ $k ] = LGT_Transliterator::to_latin( (string) $t );
		}
		return rest_ensure_response( array( 'results' => $out ) );
	}

	/** Admin: { action: generate|regenerate|open|close } */
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
				return self::state_response( $trip['id'], $req, array( 'sent' => (bool) $ok ) );
			default:
				return self::err( 'Άγνωστη ενέργεια.' );
		}
		LGT_DB::update_trip( $trip['id'], $upd );
		LGT_DB::log( $trip['id'], 'admin', 'link_' . $action );
		return self::state_response( $trip['id'], $req );
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
		return self::state_response( $trip['id'], $req, array( 'sent' => true ) );
	}

	public static function activity( WP_REST_Request $req ) {
		$check = self::require_admin( $req );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$rows = LGT_DB::get_activity( (int) $req['id'], 200 );
		$mail = LGT_DB::get_mail_log( 50, (int) $req['id'] );
		return rest_ensure_response( array( 'activity' => $rows, 'mail' => $mail ) );
	}
}
