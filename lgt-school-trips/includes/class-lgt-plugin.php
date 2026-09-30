<?php
/**
 * Main plugin orchestrator.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LGT_Plugin {

	/** @var LGT_Plugin */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( 'LGT_Install', 'maybe_upgrade' ), 5 );
		add_action( 'init', array( 'LGT_Portal', 'register_rewrites' ) );
		add_filter( 'query_vars', array( 'LGT_Portal', 'query_vars' ) );
		add_action( 'template_redirect', array( 'LGT_Portal', 'maybe_render' ), 1 );
		add_shortcode( 'lgt_school_portal', array( 'LGT_Portal', 'shortcode' ) );

		add_action( 'rest_api_init', array( 'LGT_Rest', 'register_routes' ) );

		add_action( 'lgt_st_daily_event', array( 'LGT_Cron', 'run_daily' ) );

		if ( is_admin() ) {
			LGT_Admin::init();
		}

		add_action( 'admin_post_lgt_download', array( 'LGT_Admin', 'handle_download' ) );
	}

	/** Helper: can current user manage trips? */
	public static function current_user_can_manage() {
		return current_user_can( LGT_ST_CAP ) || current_user_can( 'manage_options' );
	}

	public static function now() {
		return current_time( 'mysql' );
	}
}
