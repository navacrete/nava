<?php
/**
 * Data access layer for trips, participants, rooms, assignments and logs.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LGT_DB {

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'lgt_' . $name;
	}

	/* ------------------------------------------------------------------ */
	/* Trips                                                              */
	/* ------------------------------------------------------------------ */

	public static function trip_defaults() {
		return array(
			'title'           => '',
			'school_name'     => '',
			'school_email'    => '',
			'school_phone'    => '',
			'contact_name'    => '',
			'destination'     => '',
			'departure_date'  => null,
			'return_date'     => null,
			'status'          => 'draft',
			'token'           => null,
			'access_code'     => '',
			'has_hotel'       => 1,
			'has_ferry'       => 0,
			'has_flight'      => 0,
			'hotel_name'      => '',
			'hotel_notes'     => '',
			'ferry_company'   => '',
			'ferry_notes'     => '',
			'airline'         => '',
			'flight_notes'    => '',
			'room_types'      => LGT_Settings::get( 'default_room_types' ),
			'cabin_types'     => LGT_Settings::get( 'default_cabin_types' ),
			'extra_emails'    => '',
			'notes_school'    => '',
			'notes_internal'  => '',
			'meta'            => '{}',
			'created_by'      => 0,
			'submitted_at'    => null,
			'data_updated_at' => null,
		);
	}

	public static function get_trip( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'trips' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ? self::hydrate_trip( $row ) : null;
	}

	public static function get_trip_by_token( $token ) {
		global $wpdb;
		$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );
		if ( '' === $token ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'trips' ) . ' WHERE token = %s', $token ), ARRAY_A );
		return $row ? self::hydrate_trip( $row ) : null;
	}

	private static function hydrate_trip( array $row ) {
		$row['id']         = (int) $row['id'];
		$row['has_hotel']  = (int) $row['has_hotel'];
		$row['has_ferry']  = (int) $row['has_ferry'];
		$row['has_flight'] = (int) $row['has_flight'];
		$row['room_types'] = LGT_Settings::sanitize_types( $row['room_types'], LGT_Settings::default_room_types() );
		$row['cabin_types'] = LGT_Settings::sanitize_types( $row['cabin_types'], LGT_Settings::default_cabin_types() );
		$meta               = json_decode( (string) $row['meta'], true );
		$row['meta']        = is_array( $meta ) ? $meta : array();
		return $row;
	}

	/**
	 * @param array $filters status, search, upcoming.
	 */
	public static function list_trips( array $filters = array(), $limit = 50, $offset = 0 ) {
		global $wpdb;
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $filters['status'] ) ) {
			$where[] = 'status = %s';
			$args[]  = $filters['status'];
		} elseif ( empty( $filters['include_archived'] ) ) {
			$where[] = "status <> 'archived'";
		}
		if ( ! empty( $filters['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[] = '(title LIKE %s OR school_name LIKE %s OR destination LIKE %s)';
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = $like;
		}
		$sql = 'SELECT * FROM ' . self::table( 'trips' ) . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY (departure_date IS NULL), departure_date DESC, id DESC LIMIT %d OFFSET %d';
		$args[] = (int) $limit;
		$args[] = (int) $offset;
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore
		return array_map( array( __CLASS__, 'hydrate_trip' ), $rows ? $rows : array() );
	}

	public static function count_trips( array $filters = array() ) {
		global $wpdb;
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $filters['status'] ) ) {
			$where[] = 'status = %s';
			$args[]  = $filters['status'];
		} elseif ( empty( $filters['include_archived'] ) ) {
			$where[] = "status <> 'archived'";
		}
		if ( ! empty( $filters['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[] = '(title LIKE %s OR school_name LIKE %s OR destination LIKE %s)';
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = $like;
		}
		$sql = 'SELECT COUNT(*) FROM ' . self::table( 'trips' ) . ' WHERE ' . implode( ' AND ', $where );
		return (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_var( $sql ) ); // phpcs:ignore
	}

	/** Trips that are not archived and depart in the future (for reminders). */
	public static function upcoming_trips() {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT * FROM " . self::table( 'trips' ) . " WHERE status IN ('open','closed') AND departure_date IS NOT NULL AND departure_date >= CURDATE() ORDER BY departure_date ASC", ARRAY_A ); // phpcs:ignore
		return array_map( array( __CLASS__, 'hydrate_trip' ), $rows ? $rows : array() );
	}

	public static function insert_trip( array $data ) {
		global $wpdb;
		$data = self::prepare_trip_data( array_merge( self::trip_defaults(), $data ) );
		$now  = current_time( 'mysql' );
		$data['created_at'] = $now;
		$data['updated_at'] = $now;
		$data['created_by'] = get_current_user_id();
		$wpdb->insert( self::table( 'trips' ), $data );
		return (int) $wpdb->insert_id;
	}

	public static function update_trip( $id, array $data ) {
		global $wpdb;
		$data               = self::prepare_trip_data( $data );
		$data['updated_at'] = current_time( 'mysql' );
		return false !== $wpdb->update( self::table( 'trips' ), $data, array( 'id' => (int) $id ) );
	}

	public static function update_trip_meta( $id, array $patch ) {
		$trip = self::get_trip( $id );
		if ( ! $trip ) {
			return false;
		}
		$meta = array_merge( $trip['meta'], $patch );
		return self::update_trip( $id, array( 'meta' => $meta ) );
	}

	public static function touch_trip_data( $id ) {
		global $wpdb;
		$wpdb->update( self::table( 'trips' ), array( 'data_updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $id ) );
	}

	private static function prepare_trip_data( array $data ) {
		if ( isset( $data['room_types'] ) && ! is_string( $data['room_types'] ) ) {
			$data['room_types'] = wp_json_encode( $data['room_types'] );
		}
		if ( isset( $data['cabin_types'] ) && ! is_string( $data['cabin_types'] ) ) {
			$data['cabin_types'] = wp_json_encode( $data['cabin_types'] );
		}
		if ( isset( $data['meta'] ) && ! is_string( $data['meta'] ) ) {
			$data['meta'] = wp_json_encode( $data['meta'] );
		}
		foreach ( array( 'departure_date', 'return_date', 'submitted_at', 'data_updated_at' ) as $d ) {
			if ( array_key_exists( $d, $data ) && '' === $data[ $d ] ) {
				$data[ $d ] = null;
			}
		}
		return $data;
	}

	public static function delete_trip( $id ) {
		global $wpdb;
		$id = (int) $id;
		$wpdb->delete( self::table( 'assignments' ), array( 'trip_id' => $id ) );
		$wpdb->delete( self::table( 'rooms' ), array( 'trip_id' => $id ) );
		$wpdb->delete( self::table( 'participants' ), array( 'trip_id' => $id ) );
		$wpdb->delete( self::table( 'activity' ), array( 'trip_id' => $id ) );
		$wpdb->delete( self::table( 'mail_log' ), array( 'trip_id' => $id ) );
		return (bool) $wpdb->delete( self::table( 'trips' ), array( 'id' => $id ) );
	}

	public static function generate_token() {
		global $wpdb;
		do {
			$token  = wp_generate_password( 32, false, false );
			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table( 'trips' ) . ' WHERE token = %s', $token ) );
		} while ( $exists );
		return $token;
	}

	/* ------------------------------------------------------------------ */
	/* Participants                                                       */
	/* ------------------------------------------------------------------ */

	public static function participant_defaults() {
		return array(
			'ptype'          => 'student',
			'status'         => 'active',
			'last_name'      => '',
			'first_name'     => '',
			'last_name_lat'  => '',
			'first_name_lat' => '',
			'lat_manual'     => 0,
			'gender'         => '',
			'birth_date'     => null,
			'class_name'     => '',
			'nationality'    => LGT_Settings::get( 'default_nationality', 'GR' ),
			'doc_type'       => '',
			'doc_number'     => '',
			'doc_expiry'     => null,
			'phone'          => '',
			'notes'          => '',
			'sort_order'     => 0,
		);
	}

	public static function get_participants( $trip_id, $only_active = false ) {
		global $wpdb;
		$sql = 'SELECT * FROM ' . self::table( 'participants' ) . ' WHERE trip_id = %d' . ( $only_active ? " AND status = 'active'" : '' ) . ' ORDER BY sort_order ASC, id ASC';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $trip_id ), ARRAY_A ); // phpcs:ignore
		return array_map( array( __CLASS__, 'hydrate_participant' ), $rows ? $rows : array() );
	}

	public static function get_participant( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'participants' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ? self::hydrate_participant( $row ) : null;
	}

	private static function hydrate_participant( array $row ) {
		$row['id']         = (int) $row['id'];
		$row['trip_id']    = (int) $row['trip_id'];
		$row['lat_manual'] = (int) $row['lat_manual'];
		$row['sort_order'] = (int) $row['sort_order'];
		return $row;
	}

	public static function insert_participant( $trip_id, array $data ) {
		global $wpdb;
		$data = self::sanitize_participant( array_merge( self::participant_defaults(), $data ) );
		if ( ! $data['sort_order'] ) {
			$data['sort_order'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(sort_order),0)+1 FROM ' . self::table( 'participants' ) . ' WHERE trip_id = %d', $trip_id ) );
		}
		$data['trip_id']    = (int) $trip_id;
		$data['created_at'] = current_time( 'mysql' );
		$data['updated_at'] = current_time( 'mysql' );
		$wpdb->insert( self::table( 'participants' ), $data );
		self::touch_trip_data( $trip_id );
		return (int) $wpdb->insert_id;
	}

	public static function update_participant( $id, array $data ) {
		global $wpdb;
		$existing = self::get_participant( $id );
		if ( ! $existing ) {
			return false;
		}
		$data               = self::sanitize_participant( array_merge( $existing, $data ), $existing );
		$data['updated_at'] = current_time( 'mysql' );
		unset( $data['id'], $data['trip_id'], $data['created_at'] );
		$ok = $wpdb->update( self::table( 'participants' ), $data, array( 'id' => (int) $id ) );
		self::touch_trip_data( $existing['trip_id'] );
		return false !== $ok;
	}

	public static function delete_participant( $id ) {
		global $wpdb;
		$existing = self::get_participant( $id );
		if ( ! $existing ) {
			return false;
		}
		$wpdb->delete( self::table( 'assignments' ), array( 'participant_id' => (int) $id ) );
		$wpdb->delete( self::table( 'participants' ), array( 'id' => (int) $id ) );
		self::touch_trip_data( $existing['trip_id'] );
		return true;
	}

	/**
	 * Sanitise a participant record and auto-fill Latin names.
	 */
	public static function sanitize_participant( array $d, ?array $existing = null ) {
		$out = array();
		$out['ptype']  = in_array( $d['ptype'] ?? 'student', array( 'student', 'teacher', 'escort' ), true ) ? $d['ptype'] : 'student';
		$out['status'] = in_array( $d['status'] ?? 'active', array( 'active', 'cancelled' ), true ) ? $d['status'] : 'active';
		$out['last_name']  = self::clean_name( $d['last_name'] ?? '' );
		$out['first_name'] = self::clean_name( $d['first_name'] ?? '' );

		$manual = ! empty( $d['lat_manual'] );
		$lat_ln = LGT_Transliterator::latin_clean( $d['last_name_lat'] ?? '' );
		$lat_fn = LGT_Transliterator::latin_clean( $d['first_name_lat'] ?? '' );

		// If the Greek name changed and the Latin was not manually overridden, recompute.
		$greek_changed = $existing && ( $existing['last_name'] !== $out['last_name'] || $existing['first_name'] !== $out['first_name'] );
		if ( ! $manual && ( '' === $lat_ln || $greek_changed || ! $existing ) ) {
			$lat_ln = LGT_Transliterator::to_latin( $out['last_name'] );
		}
		if ( ! $manual && ( '' === $lat_fn || $greek_changed || ! $existing ) ) {
			$lat_fn = LGT_Transliterator::to_latin( $out['first_name'] );
		}
		if ( '' === $lat_ln ) {
			$lat_ln = LGT_Transliterator::to_latin( $out['last_name'] );
		}
		if ( '' === $lat_fn ) {
			$lat_fn = LGT_Transliterator::to_latin( $out['first_name'] );
		}
		$out['last_name_lat']  = $lat_ln;
		$out['first_name_lat'] = $lat_fn;
		$out['lat_manual']     = $manual ? 1 : 0;

		$g             = strtoupper( substr( trim( (string) ( $d['gender'] ?? '' ) ), 0, 1 ) );
		$out['gender'] = in_array( $g, array( 'M', 'F' ), true ) ? $g : ( in_array( $g, array( 'Α', 'A' ), true ) ? 'M' : ( in_array( $g, array( 'Θ', 'Κ', 'K' ), true ) ? 'F' : '' ) );
		$out['birth_date']  = self::clean_date( $d['birth_date'] ?? null );
		$out['class_name']  = sanitize_text_field( mb_substr( (string) ( $d['class_name'] ?? '' ), 0, 40 ) );
		$out['nationality'] = strtoupper( sanitize_text_field( mb_substr( (string) ( $d['nationality'] ?? '' ), 0, 60 ) ) );
		$dt                 = strtoupper( sanitize_text_field( (string) ( $d['doc_type'] ?? '' ) ) );
		$out['doc_type']    = in_array( $dt, array( 'ID', 'PASSPORT' ), true ) ? $dt : '';
		$out['doc_number']  = mb_substr( LGT_Transliterator::doc_number( sanitize_text_field( (string) ( $d['doc_number'] ?? '' ) ) ), 0, 60 );
		$out['doc_expiry']  = self::clean_date( $d['doc_expiry'] ?? null );
		$out['phone']       = sanitize_text_field( mb_substr( (string) ( $d['phone'] ?? '' ), 0, 60 ) );
		$out['notes']       = sanitize_textarea_field( (string) ( $d['notes'] ?? '' ) );
		$out['sort_order']  = (int) ( $d['sort_order'] ?? 0 );
		return $out;
	}

	public static function clean_name( $v ) {
		$v = sanitize_text_field( (string) $v );
		$v = preg_replace( '/\s+/u', ' ', trim( $v ) );
		return mb_substr( $v, 0, 120 );
	}

	/**
	 * Accepts Y-m-d, d/m/Y, d-m-Y, d.m.Y and returns Y-m-d or null.
	 */
	public static function clean_date( $v ) {
		if ( null === $v ) {
			return null;
		}
		$v = trim( (string) $v );
		if ( '' === $v ) {
			return null;
		}
		if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})/', $v, $m ) ) {
			$y = (int) $m[1];
			$mo = (int) $m[2];
			$d = (int) $m[3];
		} elseif ( preg_match( '/^(\d{1,2})[\/\.\-](\d{1,2})[\/\.\-](\d{2,4})$/', $v, $m ) ) {
			$d  = (int) $m[1];
			$mo = (int) $m[2];
			$y  = (int) $m[3];
			if ( $y < 100 ) {
				$y += ( $y > (int) date( 'y' ) + 1 ) ? 1900 : 2000;
			}
		} else {
			$ts = strtotime( $v );
			if ( ! $ts ) {
				return null;
			}
			return date( 'Y-m-d', $ts );
		}
		if ( ! checkdate( $mo, $d, $y ) ) {
			return null;
		}
		return sprintf( '%04d-%02d-%02d', $y, $mo, $d );
	}

	/* ------------------------------------------------------------------ */
	/* Rooms & assignments                                                */
	/* ------------------------------------------------------------------ */

	public static function get_rooms( $trip_id, $kind = null ) {
		global $wpdb;
		$sql  = 'SELECT * FROM ' . self::table( 'rooms' ) . ' WHERE trip_id = %d' . ( $kind ? $wpdb->prepare( ' AND kind = %s', $kind ) : '' ) . ' ORDER BY kind, sort_order ASC, id ASC';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $trip_id ), ARRAY_A ); // phpcs:ignore
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$r['id']         = (int) $r['id'];
			$r['trip_id']    = (int) $r['trip_id'];
			$r['capacity']   = (int) $r['capacity'];
			$r['sort_order'] = (int) $r['sort_order'];
			$out[]           = $r;
		}
		return $out;
	}

	public static function get_room( $id ) {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'rooms' ) . ' WHERE id = %d', $id ), ARRAY_A );
		if ( ! $r ) {
			return null;
		}
		$r['id']       = (int) $r['id'];
		$r['trip_id']  = (int) $r['trip_id'];
		$r['capacity'] = (int) $r['capacity'];
		return $r;
	}

	public static function get_assignments( $trip_id, $kind = null ) {
		global $wpdb;
		$sql  = 'SELECT * FROM ' . self::table( 'assignments' ) . ' WHERE trip_id = %d' . ( $kind ? $wpdb->prepare( ' AND kind = %s', $kind ) : '' ) . ' ORDER BY room_id, position, id';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $trip_id ), ARRAY_A ); // phpcs:ignore
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'id'             => (int) $r['id'],
				'kind'           => $r['kind'],
				'room_id'        => (int) $r['room_id'],
				'participant_id' => (int) $r['participant_id'],
				'position'       => (int) $r['position'],
			);
		}
		return $out;
	}

	public static function insert_room( $trip_id, $kind, array $data ) {
		global $wpdb;
		$kind = 'cabin' === $kind ? 'cabin' : 'hotel';
		$max  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(sort_order),0) FROM ' . self::table( 'rooms' ) . ' WHERE trip_id = %d AND kind = %s', $trip_id, $kind ) );
		$row  = array(
			'trip_id'    => (int) $trip_id,
			'kind'       => $kind,
			'label'      => sanitize_text_field( mb_substr( (string) ( $data['label'] ?? '' ), 0, 100 ) ),
			'type_code'  => strtoupper( sanitize_text_field( (string) ( $data['type_code'] ?? '' ) ) ),
			'capacity'   => max( 1, min( 12, (int) ( $data['capacity'] ?? 2 ) ) ),
			'notes'      => sanitize_text_field( mb_substr( (string) ( $data['notes'] ?? '' ), 0, 255 ) ),
			'sort_order' => isset( $data['sort_order'] ) ? (int) $data['sort_order'] : $max + 1,
			'created_at' => current_time( 'mysql' ),
		);
		if ( '' === $row['label'] ) {
			$row['label'] = (string) ( $max + 1 );
		}
		$wpdb->insert( self::table( 'rooms' ), $row );
		self::touch_trip_data( $trip_id );
		return (int) $wpdb->insert_id;
	}

	public static function update_room( $id, array $data ) {
		global $wpdb;
		$room = self::get_room( $id );
		if ( ! $room ) {
			return false;
		}
		$upd = array();
		if ( isset( $data['label'] ) ) {
			$upd['label'] = sanitize_text_field( mb_substr( (string) $data['label'], 0, 100 ) );
		}
		if ( isset( $data['type_code'] ) ) {
			$upd['type_code'] = strtoupper( sanitize_text_field( (string) $data['type_code'] ) );
		}
		if ( isset( $data['capacity'] ) ) {
			$upd['capacity'] = max( 1, min( 12, (int) $data['capacity'] ) );
		}
		if ( isset( $data['notes'] ) ) {
			$upd['notes'] = sanitize_text_field( mb_substr( (string) $data['notes'], 0, 255 ) );
		}
		if ( isset( $data['sort_order'] ) ) {
			$upd['sort_order'] = (int) $data['sort_order'];
		}
		if ( ! $upd ) {
			return true;
		}
		$wpdb->update( self::table( 'rooms' ), $upd, array( 'id' => (int) $id ) );
		self::touch_trip_data( $room['trip_id'] );
		return true;
	}

	public static function delete_room( $id ) {
		global $wpdb;
		$room = self::get_room( $id );
		if ( ! $room ) {
			return false;
		}
		$wpdb->delete( self::table( 'assignments' ), array( 'room_id' => (int) $id ) );
		$wpdb->delete( self::table( 'rooms' ), array( 'id' => (int) $id ) );
		self::touch_trip_data( $room['trip_id'] );
		return true;
	}

	public static function clear_rooms( $trip_id, $kind ) {
		global $wpdb;
		$wpdb->delete( self::table( 'assignments' ), array( 'trip_id' => (int) $trip_id, 'kind' => $kind ) );
		$wpdb->delete( self::table( 'rooms' ), array( 'trip_id' => (int) $trip_id, 'kind' => $kind ) );
		self::touch_trip_data( $trip_id );
	}

	/**
	 * Assign participants to a room (or unassign when $room_id is null/0).
	 */
	public static function assign( $trip_id, $kind, $room_id, array $participant_ids ) {
		global $wpdb;
		$kind = 'cabin' === $kind ? 'cabin' : 'hotel';
		$t    = self::table( 'assignments' );
		foreach ( $participant_ids as $pid ) {
			$pid = (int) $pid;
			$wpdb->delete( $t, array( 'kind' => $kind, 'participant_id' => $pid ) );
			if ( $room_id ) {
				$pos = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(position),0)+1 FROM $t WHERE room_id = %d", $room_id ) ); // phpcs:ignore
				$wpdb->insert(
					$t,
					array(
						'trip_id'        => (int) $trip_id,
						'kind'           => $kind,
						'room_id'        => (int) $room_id,
						'participant_id' => $pid,
						'position'       => $pos,
					)
				);
			}
		}
		self::touch_trip_data( $trip_id );
	}

	/**
	 * Replace the whole allocation of a kind with the result of the allocator.
	 *
	 * @param array $rooms [ ['type_code','capacity','members'=>[ids], 'label'?], ... ]
	 */
	public static function replace_allocation( $trip_id, $kind, array $rooms ) {
		self::clear_rooms( $trip_id, $kind );
		$n = 0;
		foreach ( $rooms as $r ) {
			$n++;
			$room_id = self::insert_room(
				$trip_id,
				$kind,
				array(
					'label'      => isset( $r['label'] ) && '' !== $r['label'] ? $r['label'] : (string) $n,
					'type_code'  => $r['type_code'],
					'capacity'   => $r['capacity'],
					'notes'      => $r['notes'] ?? '',
					'sort_order' => $n,
				)
			);
			if ( ! empty( $r['members'] ) ) {
				self::assign( $trip_id, $kind, $room_id, $r['members'] );
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Logs                                                               */
	/* ------------------------------------------------------------------ */

	public static function log( $trip_id, $actor, $action, $details = '' ) {
		global $wpdb;
		$wpdb->insert(
			self::table( 'activity' ),
			array(
				'trip_id'    => (int) $trip_id,
				'actor'      => $actor,
				'user_id'    => get_current_user_id(),
				'action'     => $action,
				'details'    => is_string( $details ) ? $details : wp_json_encode( $details, JSON_UNESCAPED_UNICODE ),
				'created_at' => current_time( 'mysql' ),
			)
		);
	}

	public static function get_activity( $trip_id, $limit = 100 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'activity' ) . ' WHERE trip_id = %d ORDER BY id DESC LIMIT %d', $trip_id, $limit ), ARRAY_A );
	}

	public static function mail_log( $trip_id, $kind, array $recipients, $subject, array $attachments, $success, $error = '' ) {
		global $wpdb;
		$wpdb->insert(
			self::table( 'mail_log' ),
			array(
				'trip_id'     => (int) $trip_id,
				'kind'        => $kind,
				'recipients'  => implode( ', ', $recipients ),
				'subject'     => $subject,
				'attachments' => implode( ', ', array_map( 'basename', $attachments ) ),
				'success'     => $success ? 1 : 0,
				'error'       => $error,
				'created_at'  => current_time( 'mysql' ),
			)
		);
	}

	public static function get_mail_log( $limit = 200, $trip_id = 0 ) {
		global $wpdb;
		if ( $trip_id ) {
			return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'mail_log' ) . ' WHERE trip_id = %d ORDER BY id DESC LIMIT %d', $trip_id, $limit ), ARRAY_A );
		}
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'mail_log' ) . ' ORDER BY id DESC LIMIT %d', $limit ), ARRAY_A );
	}
}
