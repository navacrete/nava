<?php
/**
 * Wiring.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LGTKS_Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		LGTKS_Install::maybe_upgrade();
		LGTKS_Guard::init();
		LGTKS_Cron::init();
		LGTKS_REST::init();
		if ( is_admin() ) {
			LGTKS_Admin::init();
		}
	}
}
