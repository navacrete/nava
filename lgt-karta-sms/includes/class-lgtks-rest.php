<?php
/**
 * REST endpoints:
 *  POST /wp-json/lgt-karta/v1/punch?token=…   receive clock-ins (webhook)
 *  GET  /wp-json/lgt-karta/v1/run?token=…     trigger the check (external cron)
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LGTKS_REST {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			'lgt-karta/v1',
			'/punch',
			array(
				'methods'             => array( 'POST', 'GET' ),
				'callback'            => array( __CLASS__, 'punch' ),
				'permission_callback' => array( __CLASS__, 'check_webhook_token' ),
			)
		);
		register_rest_route(
			'lgt-karta/v1',
			'/run',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( __CLASS__, 'run' ),
				'permission_callback' => array( __CLASS__, 'check_cron_token' ),
			)
		);
	}

	private static function token_from( WP_REST_Request $req ) {
		$t = $req->get_header( 'x-lgtks-token' );
		if ( ! $t ) {
			$t = $req->get_param( 'token' );
		}
		if ( ! $t ) {
			$auth = (string) $req->get_header( 'authorization' );
			if ( stripos( $auth, 'Bearer ' ) === 0 ) {
				$t = trim( substr( $auth, 7 ) );
			}
		}
		return (string) $t;
	}

	public static function check_webhook_token( WP_REST_Request $req ) {
		$expected = (string) LGTKS_Settings::get( 'webhook_token' );
		return '' !== $expected && hash_equals( $expected, self::token_from( $req ) );
	}

	public static function check_cron_token( WP_REST_Request $req ) {
		$expected = (string) LGTKS_Settings::get( 'cron_token' );
		return '' !== $expected && hash_equals( $expected, self::token_from( $req ) );
	}

	/**
	 * Accepts:
	 *  - JSON object {employee|name|code|afm|mobile, datetime|time|timestamp, kind|type}
	 *  - JSON array of such objects, or {records:[…]} / {data:[…]}
	 *  - form/query params employee=…&datetime=…
	 */
	public static function punch( WP_REST_Request $req ) {
		$json = $req->get_json_params();
		$body = is_array( $json ) && $json ? $json : $req->get_params();
		if ( isset( $body['records'] ) && is_array( $body['records'] ) ) {
			$body = $body['records'];
		} elseif ( isset( $body['data'] ) && is_array( $body['data'] ) ) {
			$body = $body['data'];
		}
		$items = ( isset( $body[0] ) && is_array( $body[0] ) ) ? $body : array( $body );
		$recs  = array();
		foreach ( $items as $it ) {
			if ( ! is_array( $it ) ) {
				continue;
			}
			$recs[] = array(
				'employee' => self::first( $it, array( 'employee', 'employee_id', 'employeeId', 'code', 'afm', 'ΑΦΜ', 'mobile', 'phone', 'name', 'fullname', 'user' ) ),
				'datetime' => self::first( $it, array( 'datetime', 'date_time', 'timestamp', 'time', 'date', 'punched_at', 'created_at' ) ),
				'kind'     => self::first( $it, array( 'kind', 'type', 'direction', 'event' ) ),
			);
		}
		$recs = array_filter(
			$recs,
			function ( $r ) {
				return '' !== $r['employee'];
			}
		);
		foreach ( $recs as &$r ) {
			if ( '' === $r['datetime'] ) {
				$r['datetime'] = LGTKS_Settings::now( 'Y-m-d H:i:s' );
			}
		}
		unset( $r );
		$res = LGTKS_Source::ingest( array_values( $recs ), 'webhook' );
		LGTKS_DB::log( 'info', 'Webhook χτυπήματος', $res );
		return new WP_REST_Response( array( 'ok' => true ) + $res, 200 );
	}

	private static function first( array $it, array $keys ) {
		foreach ( $keys as $k ) {
			if ( isset( $it[ $k ] ) && is_scalar( $it[ $k ] ) && '' !== trim( (string) $it[ $k ] ) ) {
				return trim( (string) $it[ $k ] );
			}
		}
		return '';
	}

	public static function run( WP_REST_Request $req ) {
		$summary = LGTKS_Checker::run( true, (bool) LGTKS_Settings::get( 'enabled' ) );
		return new WP_REST_Response( array( 'ok' => true, 'summary' => $summary ), 200 );
	}
}
