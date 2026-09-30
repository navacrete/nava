<?php
/**
 * Plugin Name: LGT School Trips – Σχολικές Εκδρομές
 * Plugin URI:  https://legrandtravel.gr
 * Description: Πλατφόρμα σχολικών εκδρομών: καταχώρηση μαθητών από τα σχολεία, rooming lists ξενοδοχείων (λατινικά), καμπίνες πλοίου, λίστες αεροπορικών, αυτόματη αποστολή Excel/PDF, υπενθυμίσεις.
 * Version:     1.0.0
 * Author:      Le Grand Travel
 * Author URI:  https://legrandtravel.gr
 * Text Domain: lgt-school-trips
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License:     GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LGT_ST_VERSION', '1.0.0' );
define( 'LGT_ST_DB_VERSION', '1.0.0' );
define( 'LGT_ST_FILE', __FILE__ );
define( 'LGT_ST_DIR', plugin_dir_path( __FILE__ ) );
define( 'LGT_ST_URL', plugin_dir_url( __FILE__ ) );
define( 'LGT_ST_CAP', 'lgt_manage_trips' );

/**
 * Simple PSR-0-ish autoloader for LGT_* classes living in /includes.
 */
spl_autoload_register(
	function ( $class ) {
		if ( strpos( $class, 'LGT_' ) !== 0 ) {
			return;
		}
		$file = LGT_ST_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'LGT_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'LGT_Install', 'deactivate' ) );

add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'lgt-school-trips', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		LGT_Plugin::instance();
	}
);
