<?php
/**
 * Plugin Name: LGT Κάρτα Εργασίας SMS
 * Plugin URI:  https://legrandtravel.gr
 * Description: Παίρνει αυτόματα τα χτυπήματα της ψηφιακής κάρτας εργασίας (eVardia: API, CSV ή webhook), βρίσκει ποιοι έχουν βάρδια αλλά δεν χτύπησαν κάρτα και τους στέλνει SMS (και στον υπεύθυνο).
 * Version:     1.7.1
 * Author:      Le Grand Travel
 * Author URI:  https://legrandtravel.gr
 * Text Domain: lgt-karta-sms
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License:     GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LGT_KS_VERSION', '1.7.1' );
define( 'LGT_KS_DB_VERSION', '1.5.0' );
define( 'LGT_KS_FILE', __FILE__ );
define( 'LGT_KS_DIR', plugin_dir_path( __FILE__ ) );
define( 'LGT_KS_URL', plugin_dir_url( __FILE__ ) );
define( 'LGT_KS_CAP', 'lgt_karta_sms' );

/**
 * Autoloader for LGTKS_* classes in /includes.
 * (Prefix is LGTKS_ on purpose so it never collides with the LGT_ autoloader of lgt-school-trips.)
 */
spl_autoload_register(
	function ( $class ) {
		if ( strpos( $class, 'LGTKS_' ) !== 0 ) {
			return;
		}
		$file = LGT_KS_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'LGTKS_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'LGTKS_Install', 'deactivate' ) );

add_action(
	'plugins_loaded',
	function () {
		LGTKS_Plugin::instance();
	}
);
