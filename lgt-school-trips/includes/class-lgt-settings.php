<?php
/**
 * Plugin settings (single option array) + helpers.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LGT_Settings {

	const OPTION = 'lgt_st_settings';

	private static $cache = null;

	public static function defaults() {
		return array(
			'company_name'         => 'Le Grand Travel',
			'company_phone'        => '',
			'company_email'        => get_option( 'admin_email' ),
			'company_address'      => '',
			'company_logo'         => '',
			'office_emails'        => get_option( 'admin_email' ),
			'from_name'            => 'Le Grand Travel – Σχολικές Εκδρομές',
			'from_email'           => '',
			'portal_slug'          => 'school-trip',
			'reminder_days'        => '30,14,7,3,1',
			'reminder_hour'        => 8,
			'reminder_to_school'   => 1,
			'reminder_to_extra'    => 1,
			'reminder_attachments' => 1,
			'notify_on_submit'     => 1,
			'attach_xlsx'          => 1,
			'attach_pdf'           => 1,
			'daily_digest'         => 1,
			'ferry_doc_required'   => 1,
			'default_nationality'  => 'GR',
			'pdf_orientation'      => 'P',
			'delete_on_uninstall'  => 0,
			'default_room_types'   => wp_json_encode( self::default_room_types() ),
			'default_cabin_types'  => wp_json_encode( self::default_cabin_types() ),
		);
	}

	public static function default_room_types() {
		return array(
			array( 'code' => 'SGL', 'label' => 'Μονόκλινο', 'capacity' => 1 ),
			array( 'code' => 'DBL', 'label' => 'Δίκλινο', 'capacity' => 2 ),
			array( 'code' => 'TRPL', 'label' => 'Τρίκλινο', 'capacity' => 3 ),
			array( 'code' => 'QUAD', 'label' => 'Τετράκλινο', 'capacity' => 4 ),
		);
	}

	public static function default_cabin_types() {
		return array(
			array( 'code' => 'AB2', 'label' => 'Δίκλινη εσωτερική (AB2)', 'capacity' => 2 ),
			array( 'code' => 'AB3', 'label' => 'Τρίκλινη εσωτερική (AB3)', 'capacity' => 3 ),
			array( 'code' => 'AB4', 'label' => 'Τετράκλινη εσωτερική (AB4)', 'capacity' => 4 ),
			array( 'code' => 'A4', 'label' => 'Τετράκλινη εξωτερική (A4)', 'capacity' => 4 ),
		);
	}

	public static function ensure_defaults() {
		$current = get_option( self::OPTION, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		update_option( self::OPTION, array_merge( self::defaults(), $current ) );
		self::$cache = null;
	}

	public static function all() {
		if ( null === self::$cache ) {
			$opt         = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $opt ) ? $opt : array() );
		}
		return self::$cache;
	}

	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	public static function update( array $data ) {
		$all = array_merge( self::all(), $data );
		update_option( self::OPTION, $all );
		self::$cache = null;
		LGT_Cron::schedule( true );
	}

	/** Parse a comma/newline separated email list. */
	public static function parse_emails( $text ) {
		$parts = preg_split( '/[\s,;]+/', (string) $text );
		$out   = array();
		foreach ( $parts as $p ) {
			$p = trim( $p );
			if ( $p && is_email( $p ) ) {
				$out[ strtolower( $p ) ] = $p;
			}
		}
		return array_values( $out );
	}

	public static function office_emails() {
		return self::parse_emails( self::get( 'office_emails' ) );
	}

	public static function reminder_days() {
		$days = array();
		foreach ( preg_split( '/[\s,;]+/', (string) self::get( 'reminder_days' ) ) as $d ) {
			$d = (int) trim( $d );
			if ( $d >= 0 ) {
				$days[ $d ] = $d;
			}
		}
		rsort( $days );
		return array_values( $days );
	}

	/** Decode JSON room/cabin type lists, sanitising shape. */
	public static function sanitize_types( $raw, $fallback ) {
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) ) {
			return $fallback;
		}
		$out = array();
		foreach ( $raw as $t ) {
			if ( ! is_array( $t ) ) {
				continue;
			}
			$code = strtoupper( sanitize_text_field( $t['code'] ?? '' ) );
			$cap  = (int) ( $t['capacity'] ?? 0 );
			if ( '' === $code || $cap < 1 || $cap > 12 ) {
				continue;
			}
			$out[] = array(
				'code'     => $code,
				'label'    => sanitize_text_field( $t['label'] ?? $code ),
				'capacity' => $cap,
				'quota'    => isset( $t['quota'] ) && '' !== $t['quota'] ? max( 0, (int) $t['quota'] ) : null,
			);
		}
		return $out ? $out : $fallback;
	}
}
