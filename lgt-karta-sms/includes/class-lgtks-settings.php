<?php
/**
 * Settings (single option array).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LGTKS_Settings {

	const OPTION = 'lgt_ks_settings';

	private static $cache = null;

	public static function defaults() {
		return array(
			'enabled'                  => 0,
			'company_name'             => 'Le Grand Travel',
			'grace_minutes'            => 15,
			'max_delay_minutes'        => 180,
			'second_reminder_minutes'  => 0,
			'message_template'         => 'Γεια σου {first_name}, η βάρδια σου ξεκίνησε {time} και δεν έχει καταγραφεί χτύπημα κάρτας. Παρακαλούμε χτύπα κάρτα τώρα. {company}',
			'manager_mobiles'          => '',
			'manager_message_template' => '{name} δεν έχει χτυπήσει κάρτα ({date}, βάρδια {time}, καθυστέρηση {minutes} λεπτά).',
			'manager_email'            => '',
			'holidays'                 => '',
			'country_prefix'           => '30',
			// SMS provider.
			'sms_provider'             => 'yuboto',
			'sms_sender'               => 'LeGrand',
			'yuboto_api_key'           => '',
			'routee_app_id'            => '',
			'routee_app_secret'        => '',
			'twilio_sid'               => '',
			'twilio_token'             => '',
			'twilio_from'              => '',
			'generic_url'              => '',
			'generic_method'           => 'POST',
			'generic_headers'          => "Content-Type: application/json",
			'generic_body'             => '{"to":"{to}","from":"{sender}","text":"{message}"}',
			'generic_success_contains' => '',
			// Clock-in source.
			'source_type'              => 'webhook',
			'source_url'               => '',
			'source_method'            => 'GET',
			'source_headers'           => '',
			'source_body'              => '',
			'source_records_path'      => '',
			'source_field_employee'    => '',
			'source_field_datetime'    => '',
			'source_field_kind'        => '',
			'source_kind_in_value'     => '',
			'source_date_format'       => '',
			'csv_delimiter'            => ';',
			'webhook_token'            => '',
			'cron_token'               => '',
			'delete_on_uninstall'      => 0,
		);
	}

	public static function ensure_defaults() {
		$current = get_option( self::OPTION, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		$merged = array_merge( self::defaults(), $current );
		if ( empty( $merged['webhook_token'] ) ) {
			$merged['webhook_token'] = wp_generate_password( 32, false, false );
		}
		if ( empty( $merged['cron_token'] ) ) {
			$merged['cron_token'] = wp_generate_password( 32, false, false );
		}
		update_option( self::OPTION, $merged );
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

	public static function update( array $values ) {
		$all = array_merge( self::all(), $values );
		update_option( self::OPTION, $all );
		self::$cache = null;
	}

	/** Dates (Y-m-d) from a free-text list: one per line or comma separated, "a..b" ranges allowed. */
	public static function parse_dates( $text ) {
		$out   = array();
		$parts = preg_split( '/[\s,;]+/u', (string) $text );
		foreach ( $parts as $p ) {
			$p = trim( $p );
			if ( '' === $p ) {
				continue;
			}
			if ( preg_match( '/^(\d{4}-\d{2}-\d{2})\.\.(\d{4}-\d{2}-\d{2})$/', $p, $m ) ) {
				$a = strtotime( $m[1] );
				$b = strtotime( $m[2] );
				if ( $a && $b && $b >= $a && ( $b - $a ) < 400 * DAY_IN_SECONDS ) {
					for ( $t = $a; $t <= $b; $t += DAY_IN_SECONDS ) {
						$out[ gmdate( 'Y-m-d', $t ) ] = true;
					}
				}
			} elseif ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $p ) ) {
				$out[ $p ] = true;
			} elseif ( preg_match( '/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $p, $m ) ) {
				$out[ sprintf( '%04d-%02d-%02d', $m[3], $m[2], $m[1] ) ] = true;
			}
		}
		return $out;
	}

	public static function is_holiday( $day ) {
		$h = self::parse_dates( self::get( 'holidays' ) );
		return isset( $h[ $day ] );
	}

	/** "Header: value" lines -> array. */
	public static function parse_headers( $text ) {
		$h = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			if ( strpos( $line, ':' ) === false ) {
				continue;
			}
			list( $k, $v ) = explode( ':', $line, 2 );
			$k = trim( $k );
			if ( '' !== $k ) {
				$h[ $k ] = trim( $v );
			}
		}
		return $h;
	}

	public static function manager_mobiles() {
		$list = preg_split( '/[\s,;]+/', (string) self::get( 'manager_mobiles' ) );
		return array_values( array_filter( array_map( 'trim', $list ) ) );
	}
}
