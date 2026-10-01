<?php
/**
 * SMS sending: Yuboto, Routee, Twilio, or any HTTP API (generic).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LGTKS_SMS {

	public static function providers() {
		return array(
			'generic' => 'Οποιοδήποτε HTTP API (γενικό)',
			'yuboto'  => 'Yuboto (Omni API)',
			'routee'  => 'Routee (AMD Telecom)',
			'twilio'  => 'Twilio',
		);
	}

	/**
	 * Normalize to E.164 digits (no "+"), e.g. 6912345678 -> 306912345678.
	 */
	public static function normalize( $raw, $prefix = null ) {
		$prefix = null === $prefix ? (string) LGTKS_Settings::get( 'country_prefix', '30' ) : (string) $prefix;
		$d      = preg_replace( '/\D+/', '', (string) $raw );
		if ( '' === $d ) {
			return '';
		}
		if ( strpos( $d, '00' ) === 0 ) {
			$d = substr( $d, 2 );
		}
		if ( strlen( $d ) === 10 && $prefix && strpos( $d, $prefix ) !== 0 ) {
			$d = $prefix . $d;
		} elseif ( strlen( $d ) < 10 && $prefix ) {
			$d = $prefix . ltrim( $d, '0' );
		}
		return $d;
	}

	/**
	 * @return array {ok: bool, response: string}
	 */
	public static function send( $to, $message ) {
		$to = self::normalize( $to );
		if ( '' === $to ) {
			return array( 'ok' => false, 'response' => 'Κενό/άκυρο κινητό' );
		}
		$message  = trim( (string) $message );
		$provider = LGTKS_Settings::get( 'sms_provider', 'generic' );
		switch ( $provider ) {
			case 'yuboto':
				return self::send_yuboto( $to, $message );
			case 'routee':
				return self::send_routee( $to, $message );
			case 'twilio':
				return self::send_twilio( $to, $message );
			default:
				return self::send_generic( $to, $message );
		}
	}

	private static function result( $resp, $ok_extra = true ) {
		if ( is_wp_error( $resp ) ) {
			return array( 'ok' => false, 'response' => $resp->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = (string) wp_remote_retrieve_body( $resp );
		return array(
			'ok'       => $code >= 200 && $code < 300 && $ok_extra,
			'response' => 'HTTP ' . $code . ' ' . substr( $body, 0, 2000 ),
		);
	}

	/** True if the text fits the GSM 03.38 7-bit alphabet (otherwise it must go as Unicode). */
	public static function is_gsm7( $text ) {
		return (bool) preg_match( '/^[A-Za-z0-9 @£$¥èéùìòÇØøÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ!"#¤%&\'()*+,\-.\/:;<=>?¡ÄÖÑÜ§¿äöñüà\^{}\\\[~\]|€\r\n]*$/u', $text );
	}

	/**
	 * Yuboto OMNI API v1.3: POST https://services.yuboto.com/omni/v1/Send
	 * Authorization: Basic base64(apiKey); body {phonenumbers:[…], sms:{sender,text,validity,typesms,longsms}}
	 * Response {ErrorCode:0, ErrorMessage:null, Message:[{id,channel,phonenumber,status}]}
	 */
	private static function send_yuboto( $to, $message ) {
		$key = trim( (string) LGTKS_Settings::get( 'yuboto_api_key' ) );
		if ( '' === $key ) {
			return array( 'ok' => false, 'response' => 'Λείπει το Yuboto API key' );
		}
		$unicode = ! self::is_gsm7( $message );
		$body    = array(
			'phonenumbers' => array( $to ),
			'dlr'          => false,
			'sms'          => array(
				'sender'   => (string) LGTKS_Settings::get( 'sms_sender', 'LeGrand' ),
				'text'     => $message,
				'validity' => 180,
				'typesms'  => $unicode ? 'unicode' : 'sms',
				'longsms'  => mb_strlen( $message, 'UTF-8' ) > ( $unicode ? 70 : 160 ),
				'priority' => 0,
			),
		);
		$resp = wp_remote_post(
			'https://services.yuboto.com/omni/v1/Send',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $key ),
					'Content-Type'  => 'application/json; charset=utf-8',
					'Accept'        => 'application/json',
				),
				'body'    => wp_json_encode( $body, JSON_UNESCAPED_UNICODE ),
			)
		);
		$res = self::result( $resp );
		if ( is_wp_error( $resp ) ) {
			return $res;
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $json ) || ! array_key_exists( 'ErrorCode', $json ) ) {
			$res['ok'] = false;
			return $res;
		}
		if ( (int) $json['ErrorCode'] !== 0 ) {
			$res['ok']       = false;
			$res['response'] = 'Yuboto σφάλμα ' . (int) $json['ErrorCode'] . ': ' . ( $json['ErrorMessage'] ?? '' );
			return $res;
		}
		$statuses = array();
		foreach ( (array) ( $json['Message'] ?? array() ) as $m ) {
			$statuses[] = ( $m['phonenumber'] ?? '' ) . '=' . ( $m['status'] ?? '' ) . ( ! empty( $m['id'] ) ? ' (id ' . $m['id'] . ')' : '' );
			if ( in_array( strtolower( (string) ( $m['status'] ?? '' ) ), array( 'error', 'rejected', 'failed', 'not delivered' ), true ) ) {
				$res['ok'] = false;
			}
		}
		$res['response'] = 'Yuboto OK: ' . implode( ', ', $statuses ) . ( $unicode ? ' [unicode]' : '' );
		return $res;
	}

	/** Yuboto account balance (for the settings page). Returns string or WP_Error. */
	public static function yuboto_balance() {
		$key = trim( (string) LGTKS_Settings::get( 'yuboto_api_key' ) );
		if ( '' === $key ) {
			return new WP_Error( 'lgtks', 'Λείπει το Yuboto API key' );
		}
		$resp = wp_remote_post(
			'https://services.yuboto.com/omni/v1/Balance',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $key ),
					'Content-Type'  => 'application/json; charset=utf-8',
					'Accept'        => 'application/json',
				),
				'body'    => '{}',
			)
		);
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $json ) ) {
			return new WP_Error( 'lgtks', 'HTTP ' . wp_remote_retrieve_response_code( $resp ) . ': ' . substr( (string) wp_remote_retrieve_body( $resp ), 0, 200 ) );
		}
		if ( ! empty( $json['ErrorCode'] ) ) {
			return new WP_Error( 'lgtks', 'Yuboto σφάλμα ' . $json['ErrorCode'] . ': ' . ( $json['ErrorMessage'] ?? '' ) );
		}
		unset( $json['ErrorCode'], $json['ErrorMessage'] );
		return wp_json_encode( $json, JSON_UNESCAPED_UNICODE );
	}

	private static function routee_token() {
		$cached = get_transient( 'lgtks_routee_token' );
		if ( $cached ) {
			return $cached;
		}
		$id     = trim( (string) LGTKS_Settings::get( 'routee_app_id' ) );
		$secret = trim( (string) LGTKS_Settings::get( 'routee_app_secret' ) );
		if ( '' === $id || '' === $secret ) {
			return new WP_Error( 'lgtks', 'Λείπουν Routee application id / secret' );
		}
		$resp = wp_remote_post(
			'https://auth.routee.net/oauth/token',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $id . ':' . $secret ),
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => 'grant_type=client_credentials',
			)
		);
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$json = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( empty( $json['access_token'] ) ) {
			return new WP_Error( 'lgtks', 'Routee token: ' . substr( wp_remote_retrieve_body( $resp ), 0, 500 ) );
		}
		$ttl = isset( $json['expires_in'] ) ? max( 60, (int) $json['expires_in'] - 60 ) : 3000;
		set_transient( 'lgtks_routee_token', $json['access_token'], $ttl );
		return $json['access_token'];
	}

	private static function send_routee( $to, $message ) {
		$token = self::routee_token();
		if ( is_wp_error( $token ) ) {
			return array( 'ok' => false, 'response' => $token->get_error_message() );
		}
		$resp = wp_remote_post(
			'https://connect.routee.net/sms',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'body' => $message,
						'to'   => '+' . $to,
						'from' => (string) LGTKS_Settings::get( 'sms_sender', 'LeGrand' ),
					)
				),
			)
		);
		if ( ! is_wp_error( $resp ) && 401 === (int) wp_remote_retrieve_response_code( $resp ) ) {
			delete_transient( 'lgtks_routee_token' );
		}
		return self::result( $resp );
	}

	private static function send_twilio( $to, $message ) {
		$sid   = trim( (string) LGTKS_Settings::get( 'twilio_sid' ) );
		$tok   = trim( (string) LGTKS_Settings::get( 'twilio_token' ) );
		$from  = trim( (string) LGTKS_Settings::get( 'twilio_from' ) );
		if ( '' === $sid || '' === $tok || '' === $from ) {
			return array( 'ok' => false, 'response' => 'Λείπουν Twilio SID / token / from' );
		}
		$resp = wp_remote_post(
			'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode( $sid ) . '/Messages.json',
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Basic ' . base64_encode( $sid . ':' . $tok ) ),
				'body'    => array(
					'To'   => '+' . $to,
					'From' => $from,
					'Body' => $message,
				),
			)
		);
		return self::result( $resp );
	}

	private static function send_generic( $to, $message ) {
		$url = trim( (string) LGTKS_Settings::get( 'generic_url' ) );
		if ( '' === $url ) {
			return array( 'ok' => false, 'response' => 'Λείπει το URL του SMS API (Ρυθμίσεις → SMS)' );
		}
		$sender = (string) LGTKS_Settings::get( 'sms_sender', 'LeGrand' );
		$vars   = array(
			'{to}'          => $to,
			'{to_plus}'     => '+' . $to,
			'{to_url}'      => rawurlencode( $to ),
			'{message}'     => $message,
			'{message_url}' => rawurlencode( $message ),
			'{sender}'      => $sender,
			'{sender_url}'  => rawurlencode( $sender ),
		);
		$json_vars = $vars;
		// Inside a JSON body the message must be JSON-escaped (without the surrounding quotes).
		foreach ( array( '{message}', '{sender}', '{to}', '{to_plus}' ) as $k ) {
			$json_vars[ $k ] = substr( wp_json_encode( $vars[ $k ], JSON_UNESCAPED_UNICODE ), 1, -1 );
		}
		$method  = strtoupper( (string) LGTKS_Settings::get( 'generic_method', 'POST' ) );
		$headers = LGTKS_Settings::parse_headers( LGTKS_Settings::get( 'generic_headers' ) );
		foreach ( $headers as $k => $v ) {
			$headers[ $k ] = strtr( $v, $vars );
		}
		$body_tpl = (string) LGTKS_Settings::get( 'generic_body' );
		$is_json  = false;
		foreach ( $headers as $k => $v ) {
			if ( strtolower( $k ) === 'content-type' && stripos( $v, 'json' ) !== false ) {
				$is_json = true;
			}
		}
		$body = strtr( $body_tpl, $is_json ? $json_vars : $vars );
		$url  = strtr( $url, $vars );
		$args = array( 'timeout' => 20, 'headers' => $headers, 'method' => $method );
		if ( 'GET' !== $method ) {
			$args['body'] = $body;
		}
		$resp = wp_remote_request( $url, $args );
		$ok   = true;
		$must = trim( (string) LGTKS_Settings::get( 'generic_success_contains' ) );
		if ( '' !== $must && ! is_wp_error( $resp ) ) {
			$ok = stripos( (string) wp_remote_retrieve_body( $resp ), $must ) !== false;
		}
		return self::result( $resp, $ok );
	}
}
