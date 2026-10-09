<?php
/**
 * Plugin Name: LGT Η Κάρτα Μου
 * Plugin URI:  https://legrandtravel.gr
 * Description: Προσωπική εφαρμογή κινητού (PWA) για ΕΝΑΝ εργαζόμενο: χτύπημα προσέλευσης/αποχώρησης στην ψηφιακή κάρτα μέσω της «Επιλεκτικής αποστολής» του eVardia, με ένα κουμπί και PIN, χωρίς σκανάρισμα. Ανεξάρτητο από το plugin «Κάρτα Εργασίας SMS».
 * Version:     1.0.0
 * Author:      Le Grand Travel
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License:     GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LGT_KM_VERSION', '1.0.0' );
define( 'LGT_KM_FILE', __FILE__ );
define( 'LGT_KM_DIR', plugin_dir_path( __FILE__ ) );
define( 'LGT_KM_URL', plugin_dir_url( __FILE__ ) );
define( 'LGT_KM_OPTION', 'lgt_km_settings' );

require_once LGT_KM_DIR . 'includes/class-lgtkm-evardia.php';
require_once LGT_KM_DIR . 'includes/class-lgtkm-plugin.php';

register_activation_hook( __FILE__, array( 'LGTKM_Plugin', 'activate' ) );
add_action( 'plugins_loaded', array( 'LGTKM_Plugin', 'init' ) );
