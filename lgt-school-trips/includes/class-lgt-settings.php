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
			'close_on_submit'      => 0,
			'delete_on_uninstall'  => 0,
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

}
