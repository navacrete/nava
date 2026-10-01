<?php
/**
 * Employees, punches, notifications, log.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LGTKS_DB {

	public static function t( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'lgtks_' . $name;
	}

	/* ---------- Employees ---------- */

	public static function employees( $only_active = false ) {
		global $wpdb;
		$where = $only_active ? 'WHERE active = 1' : '';
		$rows  = $wpdb->get_results( 'SELECT * FROM ' . self::t( 'employees' ) . " $where ORDER BY name ASC", ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( __CLASS__, 'hydrate_employee' ), $rows ? $rows : array() );
	}

	public static function employee( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'employees' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? self::hydrate_employee( $row ) : null;
	}

	private static function hydrate_employee( $row ) {
		$sched = json_decode( (string) $row['schedule'], true );
		if ( ! is_array( $sched ) ) {
			$sched = array();
		}
		$row['schedule']      = $sched;
		$row['grace_minutes'] = ( null === $row['grace_minutes'] || '' === $row['grace_minutes'] ) ? null : (int) $row['grace_minutes'];
		$row['active']        = (int) $row['active'];
		$row['email']         = isset( $row['email'] ) ? $row['email'] : '';
		$row['notify_channel'] = ! empty( $row['notify_channel'] ) && 'none' !== $row['notify_channel'] ? $row['notify_channel'] : 'sms';
		$row['notify_target']  = ! empty( $row['notify_target'] ) ? $row['notify_target'] : 'both';
		return $row;
	}

	/**
	 * @param array $data name, mobile, external_id, schedule (array 1..7 => 'HH:MM' or ''), grace_minutes (int|null), days_off, notes, active.
	 */
	public static function save_employee( array $data, $id = 0 ) {
		global $wpdb;
		$schedule = array();
		for ( $d = 1; $d <= 7; $d++ ) {
			$v = isset( $data['schedule'][ $d ] ) ? trim( (string) $data['schedule'][ $d ] ) : '';
			if ( preg_match( '/^(\d{1,2}):(\d{2})$/', $v, $m ) && (int) $m[1] < 24 && (int) $m[2] < 60 ) {
				$schedule[ $d ] = sprintf( '%02d:%02d', $m[1], $m[2] );
			} else {
				$schedule[ $d ] = '';
			}
		}
		$row = array(
			'name'          => sanitize_text_field( $data['name'] ?? '' ),
			'mobile'        => sanitize_text_field( $data['mobile'] ?? '' ),
			'email'         => sanitize_email( $data['email'] ?? '' ),
			'notify_channel' => in_array( $data['notify_channel'] ?? 'sms', array( 'sms', 'email', 'both' ), true ) ? $data['notify_channel'] : 'sms',
			'notify_target'  => in_array( $data['notify_target'] ?? 'both', array( 'both', 'employee', 'manager', 'none' ), true ) ? $data['notify_target'] : 'both',
			'external_id'   => sanitize_text_field( $data['external_id'] ?? '' ),
			'schedule'      => wp_json_encode( $schedule ),
			'grace_minutes' => ( isset( $data['grace_minutes'] ) && '' !== $data['grace_minutes'] && null !== $data['grace_minutes'] ) ? (int) $data['grace_minutes'] : null,
			'days_off'      => sanitize_textarea_field( $data['days_off'] ?? '' ),
			'notes'         => sanitize_textarea_field( $data['notes'] ?? '' ),
			'active'        => empty( $data['active'] ) ? 0 : 1,
			'updated_at'    => LGTKS_Settings::now( 'Y-m-d H:i:s' ),
		);
		$fmt = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s' );
		if ( null === $row['grace_minutes'] ) {
			unset( $row['grace_minutes'] );
			unset( $fmt[7] );
			$fmt = array_values( $fmt );
		}
		if ( $id ) {
			$wpdb->update( self::t( 'employees' ), $row, array( 'id' => (int) $id ), $fmt, array( '%d' ) ); // phpcs:ignore WordPress.DB
			if ( ! isset( $row['grace_minutes'] ) ) {
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'employees' ) . ' SET grace_minutes = NULL WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB
			}
			return (int) $id;
		}
		$row['created_at'] = LGTKS_Settings::now( 'Y-m-d H:i:s' );
		$fmt[]             = '%s';
		$wpdb->insert( self::t( 'employees' ), $row, $fmt ); // phpcs:ignore WordPress.DB
		return (int) $wpdb->insert_id;
	}

	public static function delete_employee( $id ) {
		global $wpdb;
		$wpdb->delete( self::t( 'employees' ), array( 'id' => (int) $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB
		$wpdb->delete( self::t( 'punches' ), array( 'employee_id' => (int) $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB
	}

	/* ---------- Punches ---------- */

	/** Insert a punch; returns true if new, false if duplicate. */
	public static function add_punch( $employee_id, $punched_at, $kind = 'in', $source = '', $raw_ref = '' ) {
		global $wpdb;
		$kind = ( 'out' === $kind ) ? 'out' : 'in';
		$ok   = $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . self::t( 'punches' ) . ' (employee_id, day, punched_at, kind, source, raw_ref, created_at) VALUES (%d, %s, %s, %s, %s, %s, %s)',
				(int) $employee_id,
				substr( $punched_at, 0, 10 ),
				$punched_at,
				$kind,
				substr( (string) $source, 0, 20 ),
				substr( (string) $raw_ref, 0, 190 ),
				LGTKS_Settings::now( 'Y-m-d H:i:s' )
			)
		);
		return (int) $ok === 1;
	}

	/** First clock-in of the day (any kind counts as "present"), or null. */
	public static function first_punch( $employee_id, $day ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(punched_at) FROM ' . self::t( 'punches' ) . ' WHERE employee_id = %d AND day = %s', $employee_id, $day ) ); // phpcs:ignore WordPress.DB
	}

	public static function first_punch_after( $employee_id, $day, $from ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(punched_at) FROM ' . self::t( 'punches' ) . ' WHERE employee_id = %d AND day = %s AND punched_at >= %s', $employee_id, $day, $from ) ); // phpcs:ignore WordPress.DB
	}

	public static function punches_for_day( $day ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT employee_id, MIN(punched_at) AS first_at, COUNT(*) AS cnt FROM ' . self::t( 'punches' ) . ' WHERE day = %s GROUP BY employee_id', $day ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out  = array();
		foreach ( $rows ? $rows : array() as $r ) {
			$out[ (int) $r['employee_id'] ] = $r;
		}
		return $out;
	}

	public static function delete_punches( $employee_id, $day ) {
		global $wpdb;
		$wpdb->delete( self::t( 'punches' ), array( 'employee_id' => (int) $employee_id, 'day' => $day ), array( '%d', '%s' ) ); // phpcs:ignore WordPress.DB
	}

	/* ---------- Notifications ---------- */

	public static function add_notification( $employee_id, $day, $round, $channel, $recipient, $message, $status, $response ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB
			self::t( 'notifications' ),
			array(
				'employee_id' => (int) $employee_id,
				'day'         => $day,
				'round'       => (int) $round,
				'channel'     => $channel,
				'recipient'   => substr( (string) $recipient, 0, 190 ),
				'message'     => $message,
				'status'      => $status,
				'response'    => is_string( $response ) ? substr( $response, 0, 5000 ) : wp_json_encode( $response ),
				'created_at'  => LGTKS_Settings::now( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/** Employee notifications (sms + email) for a day, grouped by employee id. */
	public static function notifications_for_day( $day ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'notifications' ) . " WHERE day = %s AND channel IN ('sms','email','manager','manager_email') ORDER BY created_at ASC", $day ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out  = array();
		foreach ( $rows ? $rows : array() as $r ) {
			$out[ (int) $r['employee_id'] ][] = $r;
		}
		return $out;
	}

	public static function recent_notifications( $limit = 200 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT n.*, e.name FROM ' . self::t( 'notifications' ) . ' n LEFT JOIN ' . self::t( 'employees' ) . ' e ON e.id = n.employee_id ORDER BY n.id DESC LIMIT %d', $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/* ---------- Log ---------- */

	public static function log( $level, $message, $context = null ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB
			self::t( 'log' ),
			array(
				'level'      => substr( $level, 0, 10 ),
				'message'    => $message,
				'context'    => null === $context ? null : ( is_string( $context ) ? substr( $context, 0, 10000 ) : substr( wp_json_encode( $context, JSON_UNESCAPED_UNICODE ), 0, 10000 ) ),
				'created_at' => LGTKS_Settings::now( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%s', '%s' )
		);
		// Keep table small.
		if ( mt_rand( 1, 50 ) === 1 ) {
			$wpdb->query( 'DELETE FROM ' . self::t( 'log' ) . ' WHERE created_at < ' . $wpdb->prepare( '%s', gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
		}
	}

	public static function recent_log( $limit = 200 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'log' ) . ' ORDER BY id DESC LIMIT %d', $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}
}
