<?php
/**
 * Wiring (work in progress): defaults + token-protected REST status endpoint. The clock-in action
 * will be added once the eVardia scanner (camera) request format is known.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LGTKM_Plugin {

	public static function activate() {
		$o = get_option( LGT_KM_OPTION, array() );
		if ( ! is_array( $o ) ) {
			$o = array();
		}
		$o = array_merge(
			array(
				'base_url'     => 'https://evardia.gr',
				'username'     => '',
				'password'     => '',
				'my_afm'       => '',
				'ypokatasthma' => '0',
				'pin'          => '',
				'token'        => wp_generate_password( 32, false, false ),
				'dry_run'      => 1,
			),
			$o
		);
		update_option( LGT_KM_OPTION, $o );
	}

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			'lgt-karta-mine/v1',
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'status' ),
				'permission_callback' => array( __CLASS__, 'check_token' ),
			)
		);
	}

	public static function check_token( WP_REST_Request $req ) {
		$t = (string) LGTKM_Evardia::opt( 'token' );
		return '' !== $t && hash_equals( $t, (string) $req->get_param( 'token' ) );
	}

	public static function status( WP_REST_Request $req ) {
		$row = LGTKM_Evardia::my_row();
		if ( is_wp_error( $row ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => $row->get_error_message() ), 200 );
		}
		return new WP_REST_Response( array( 'ok' => true, 'status' => LGTKM_Evardia::status( $row ) ), 200 );
	}
}
