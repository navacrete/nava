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
			'message_template'         => 'Γεια σου {first_name}, η βάρδια σου ξεκίνησε {time} και δεν έχει καταγραφεί χτύπημα κάρτας. Παρακαλούμε χτύπα κάρτα τώρα. {company}',
			'manager_mobiles'          => '',
			'manager_message_template' => '{name} δεν έχει χτυπήσει κάρτα ({date}, βάρδια {time}, καθυστέρηση {minutes} λεπτά).',
			'manager_email'            => '',
			'manager_channel'          => 'both',
			'manager_per_employee'     => 0,
			'clockin_email'            => 1,
			'anomaly_email'            => 1,
			'clockin_email_kinds'      => 'in',
			'clockin_email_mode'       => 'exceptions',
			'clockin_late_threshold'   => 15,
			'clockin_batch_minutes'    => 60,
			'clockin_email_to'         => '',
			'clockin_email_subject'    => 'Χτύπημα κάρτας: {name} {punch_time} ({kind})',
			'manager_digest_times'     => '11:00',
			'manager_digest_all_ok'    => 0,
			'manager_digest_sms'       => 'Κάρτα εργασίας {now}: {count} χωρίς χτύπημα: {list}',
			'manager_escalation_minutes' => 60,
			'manager_escalation_template' => 'ΠΡΟΣΟΧΗ: {name} δεν έχει χτυπήσει κάρτα {minutes} λεπτά μετά την έναρξη ({time}). Ειδοποιήθηκε με SMS χωρίς αποτέλεσμα.',
			'manager_summary_subject'  => 'Κάρτα εργασίας {date}: {due} χωρίς χτύπημα',
			'email_subject'            => 'Υπενθύμιση: δεν έχει καταγραφεί χτύπημα κάρτας',
			'holidays'                 => '',
			'holidays_auto'            => 1,
			'holiday_clean_monday'     => 1,
			'holiday_holy_spirit'      => 0,
			'holiday_good_friday'      => 0,
			'holiday_mode'             => 'skip',
			'country_prefix'           => '30',
			'timezone'                 => 'Europe/Athens',
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
			'source_type'              => 'evardia',
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
			// eVardia (evardia.gr) source.
			'ev_base_url'              => 'https://evardia.gr',
			'ev_login_url'             => '',
			'ev_username'              => '',
			'ev_password'              => '',
			'ev_ypokatasthma'          => '0',
			'ev_use_schedule'          => 1,
			'ev_auto_create'           => 1,
			// Generic web login source.
			'wl_login_url'             => '',
			'wl_username'              => '',
			'wl_password'              => '',
			'wl_user_field'            => 'username',
			'wl_pass_field'            => 'password',
			'wl_extra_fields'          => '',
			'wl_success_contains'      => '',
			'wl_data_url'              => '',
			'wl_format'                => 'auto',
			'wl_table_hint'            => '',
			'wl_field_time'            => '',
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

	/* ---------- time: the plugin always works in Greek time, independent of the WP setting ---------- */

	public static function tz() {
		static $tz = null;
		if ( null === $tz ) {
			$name = (string) self::get( 'timezone', 'Europe/Athens' );
			try {
				$tz = new DateTimeZone( '' !== $name ? $name : 'Europe/Athens' );
			} catch ( Exception $e ) {
				$tz = new DateTimeZone( 'Europe/Athens' );
			}
		}
		return $tz;
	}

	/** Current time formatted in the plugin timezone. */
	public static function now( $format = 'Y-m-d H:i:s' ) {
		$d = new DateTime( 'now', self::tz() );
		return $d->format( $format );
	}

	public static function now_ts() {
		return time();
	}

	/** Format a unix timestamp in the plugin timezone. */
	public static function fmt( $format, $timestamp ) {
		$d = new DateTime( '@' . (int) $timestamp );
		$d->setTimezone( self::tz() );
		return $d->format( $format );
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
		return '' !== LGTKS_Holidays::name( $day );
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

	public static function manager_emails() {
		$list = preg_split( '/[\s,;]+/', (string) self::get( 'manager_email' ) );
		return array_values( array_filter( array_map( 'trim', $list ), 'is_email' ) );
	}

	/** Recipients of the per-punch email: own list or the manager emails. */
	public static function clockin_emails() {
		$list = preg_split( '/[\s,;]+/', (string) self::get( 'clockin_email_to' ) );
		$list = array_values( array_filter( array_map( 'trim', $list ), 'is_email' ) );
		return $list ? $list : self::manager_emails();
	}

	public static function manager_channel() {
		$c = (string) self::get( 'manager_channel', 'both' );
		return in_array( $c, array( 'both', 'sms', 'email', 'none' ), true ) ? $c : 'both';
	}

	/** Digest times as 'HH:MM' list. */
	public static function digest_times() {
		$out = array();
		foreach ( preg_split( '/[\s,;]+/', (string) self::get( 'manager_digest_times' ) ) as $t ) {
			if ( preg_match( '/^(\d{1,2})[:.](\d{2})$/', trim( $t ), $m ) && (int) $m[1] < 24 && (int) $m[2] < 60 ) {
				$out[] = sprintf( '%02d:%02d', $m[1], $m[2] );
			}
		}
		sort( $out );
		return array_values( array_unique( $out ) );
	}

	public static function manager_mobiles() {
		$list = preg_split( '/[\s,;]+/', (string) self::get( 'manager_mobiles' ) );
		return array_values( array_filter( array_map( 'trim', $list ) ) );
	}
}
