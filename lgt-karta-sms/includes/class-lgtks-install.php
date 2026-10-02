<?php
/**
 * Activation / upgrade: tables, capability, cron, defaults.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LGTKS_Install {

	public static function activate() {
		self::create_tables();
		self::add_caps();
		LGTKS_Settings::ensure_defaults();
		LGTKS_Cron::schedule();
		update_option( 'lgt_ks_db_version', LGT_KS_DB_VERSION );
	}

	public static function deactivate() {
		LGTKS_Cron::unschedule();
	}

	public static function maybe_upgrade() {
		if ( get_option( 'lgt_ks_db_version' ) !== LGT_KS_DB_VERSION ) {
			self::create_tables();
			self::add_caps();
			LGTKS_Settings::ensure_defaults();
			self::migrate();
			update_option( 'lgt_ks_db_version', LGT_KS_DB_VERSION );
		}
		if ( ! wp_next_scheduled( LGTKS_Cron::HOOK ) ) {
			LGTKS_Cron::schedule();
		}
	}

	/** Data migrations between versions. */
	public static function migrate() {
		global $wpdb;
		$t = $wpdb->prefix . 'lgtks_employees';
		// 1.1: notify_channel 'none' meant "excluded"; now that lives in notify_target.
		$wpdb->query( "UPDATE {$t} SET notify_target = 'none', notify_channel = 'sms' WHERE notify_channel = 'none'" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "UPDATE {$t} SET notify_target = 'both' WHERE notify_target IS NULL OR notify_target = ''" ); // phpcs:ignore WordPress.DB
		// 1.16: new default wording (leave/day-off disclaimer) if the template was never customised.
		if ( (string) LGTKS_Settings::get( 'message_template' ) === 'Γεια σου {first_name}, η βάρδια σου ξεκίνησε {time} και δεν έχει καταγραφεί χτύπημα κάρτας. Παρακαλούμε χτύπα κάρτα τώρα. {company}' ) {
			LGTKS_Settings::update( array( 'message_template' => 'Αν έχεις άδεια ή ρεπό, αγνόησε αυτό το μήνυμα. Γεια σου {first_name}, η βάρδια σου ξεκίνησε {time} και δεν έχει καταγραφεί χτύπημα κάρτας. Παρακαλούμε χτύπα κάρτα τώρα. {company}' ) );
		}
		// 1.15: policy change – the system informs the employee only; manager notifications off.
		$s = get_option( LGTKS_Settings::OPTION, array() );
		if ( is_array( $s ) && ! array_key_exists( 'managers_enabled', $s ) ) {
			LGTKS_Settings::update( array( 'managers_enabled' => 0, 'clockin_email' => 0 ) );
		}
	}

	public static function add_caps() {
		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->add_cap( LGT_KS_CAP );
		}
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix;

		$sql   = array();
		$sql[] = "CREATE TABLE {$p}lgtks_employees (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL DEFAULT '',
			mobile VARCHAR(40) NOT NULL DEFAULT '',
			email VARCHAR(190) NOT NULL DEFAULT '',
			notify_channel VARCHAR(10) NOT NULL DEFAULT 'sms',
			notify_target VARCHAR(10) NOT NULL DEFAULT 'both',
			external_id VARCHAR(100) NOT NULL DEFAULT '',
			schedule TEXT NULL,
			grace_minutes INT NULL,
			days_off TEXT NULL,
			notes TEXT NULL,
			active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY external_id (external_id),
			KEY active (active)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}lgtks_punches (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			employee_id BIGINT UNSIGNED NOT NULL,
			day DATE NOT NULL,
			punched_at DATETIME NOT NULL,
			kind VARCHAR(10) NOT NULL DEFAULT 'in',
			source VARCHAR(20) NOT NULL DEFAULT '',
			raw_ref VARCHAR(190) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_punch (employee_id, punched_at, kind),
			KEY emp_day (employee_id, day)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}lgtks_notifications (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			employee_id BIGINT UNSIGNED NOT NULL,
			day DATE NOT NULL,
			round TINYINT NOT NULL DEFAULT 1,
			channel VARCHAR(20) NOT NULL DEFAULT 'sms',
			recipient VARCHAR(190) NOT NULL DEFAULT '',
			message TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'sent',
			response TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY emp_day (employee_id, day, channel)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}lgtks_log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			level VARCHAR(10) NOT NULL DEFAULT 'info',
			message TEXT NULL,
			context TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) $charset;";

		foreach ( $sql as $q ) {
			dbDelta( $q );
		}
	}
}
