<?php
/**
 * Data access layer: trips (with the school's form data stored as JSON), activity and mail log.
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
			'status'          => 'open',
			'token'           => null,
			'access_code'     => '',
			'has_hotel'       => 1,
			'has_ferry'       => 0,
			'hotel_name'      => '',
			'ferry_company'   => '',
			'extra_emails'    => '',
			'notes_school'    => '',
			'notes_internal'  => '',
			'form_data'       => '',
			'rooming_data'    => '',
			'cabins_data'     => '',
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
		$row['id']        = (int) $row['id'];
		$row['has_hotel'] = (int) $row['has_hotel'];
		$row['has_ferry'] = (int) $row['has_ferry'];
		$meta             = json_decode( (string) $row['meta'], true );
		$row['meta']      = is_array( $meta ) ? $meta : array();
		$row['form']      = LGT_Data::sanitize_form( json_decode( (string) $row['form_data'], true ) );
		$row['rooming']   = LGT_Data::sanitize_rooming( json_decode( (string) $row['rooming_data'], true ), 'hotel', $row );
		$row['cabins']    = $row['has_ferry'] ? LGT_Data::sanitize_rooming( json_decode( (string) $row['cabins_data'], true ), 'cabin', $row ) : null;
		return $row;
	}

	private static function where( array $filters, array &$args ) {
		global $wpdb;
		$where = array( '1=1' );
		if ( ! empty( $filters['status'] ) ) {
			$where[] = 'status = %s';
			$args[]  = $filters['status'];
		} elseif ( empty( $filters['include_archived'] ) ) {
			$where[] = "status <> 'archived'";
		}
		if ( ! empty( $filters['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[] = '(title LIKE %s OR school_name LIKE %s OR destination LIKE %s)';
			array_push( $args, $like, $like, $like );
		}
		return implode( ' AND ', $where );
	}

	public static function list_trips( array $filters = array(), $limit = 50, $offset = 0 ) {
		global $wpdb;
		$args   = array();
		$sql    = 'SELECT * FROM ' . self::table( 'trips' ) . ' WHERE ' . self::where( $filters, $args ) . ' ORDER BY (departure_date IS NULL), departure_date DESC, id DESC LIMIT %d OFFSET %d';
		$args[] = (int) $limit;
		$args[] = (int) $offset;
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore
		return array_map( array( __CLASS__, 'hydrate_trip' ), $rows ? $rows : array() );
	}

	public static function count_trips( array $filters = array() ) {
		global $wpdb;
		$args = array();
		$sql  = 'SELECT COUNT(*) FROM ' . self::table( 'trips' ) . ' WHERE ' . self::where( $filters, $args );
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
		$data               = self::prepare_trip_data( array_merge( self::trip_defaults(), $data ) );
		$now                = current_time( 'mysql' );
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
		return self::update_trip( $id, array( 'meta' => array_merge( $trip['meta'], $patch ) ) );
	}

	/** Save the school's data (form rows + rooming sheets). */
	public static function save_data( $id, $form, $rooming, $cabins ) {
		return self::update_trip(
			$id,
			array(
				'form_data'       => wp_json_encode( $form, JSON_UNESCAPED_UNICODE ),
				'rooming_data'    => wp_json_encode( $rooming, JSON_UNESCAPED_UNICODE ),
				'cabins_data'     => null === $cabins ? '' : wp_json_encode( $cabins, JSON_UNESCAPED_UNICODE ),
				'data_updated_at' => current_time( 'mysql' ),
			)
		);
	}

	private static function prepare_trip_data( array $data ) {
		unset( $data['form'], $data['rooming'], $data['cabins'] );
		if ( isset( $data['meta'] ) && ! is_string( $data['meta'] ) ) {
			$data['meta'] = wp_json_encode( $data['meta'], JSON_UNESCAPED_UNICODE );
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
			$y  = (int) $m[1];
			$mo = (int) $m[2];
			$d  = (int) $m[3];
		} elseif ( preg_match( '/^(\d{1,2})[\/\.\-](\d{1,2})[\/\.\-](\d{2,4})$/', $v, $m ) ) {
			$d  = (int) $m[1];
			$mo = (int) $m[2];
			$y  = (int) $m[3];
			if ( $y < 100 ) {
				$y += ( $y > (int) date( 'y' ) + 1 ) ? 1900 : 2000;
			}
		} else {
			$ts = strtotime( $v );
			return $ts ? date( 'Y-m-d', $ts ) : null;
		}
		if ( ! checkdate( $mo, $d, $y ) ) {
			return null;
		}
		return sprintf( '%04d-%02d-%02d', $y, $mo, $d );
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
