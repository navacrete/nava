<?php
/**
 * Activation / upgrade: database tables, capabilities, cron, defaults.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LGT_Install {

	public static function activate() {
		self::create_tables();
		self::add_caps();
		LGT_Settings::ensure_defaults();
		LGT_Cron::schedule();
		LGT_Portal::register_rewrites();
		flush_rewrite_rules();
		self::secure_upload_dir();
		update_option( 'lgt_st_db_version', LGT_ST_DB_VERSION );
	}

	public static function deactivate() {
		LGT_Cron::unschedule();
		flush_rewrite_rules();
	}

	/** Run on every load: upgrade schema if version changed. */
	public static function maybe_upgrade() {
		if ( get_option( 'lgt_st_db_version' ) !== LGT_ST_DB_VERSION ) {
			self::create_tables();
			self::add_caps();
			LGT_Settings::ensure_defaults();
			update_option( 'lgt_st_db_version', LGT_ST_DB_VERSION );
		}
		if ( ! wp_next_scheduled( 'lgt_st_daily_event' ) ) {
			LGT_Cron::schedule();
		}
	}

	public static function add_caps() {
		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->add_cap( LGT_ST_CAP );
		}
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix;

		$sql = array();

		$sql[] = "CREATE TABLE {$p}lgt_trips (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(200) NOT NULL DEFAULT '',
			school_name VARCHAR(200) NOT NULL DEFAULT '',
			school_email VARCHAR(200) NOT NULL DEFAULT '',
			school_phone VARCHAR(60) NOT NULL DEFAULT '',
			contact_name VARCHAR(200) NOT NULL DEFAULT '',
			destination VARCHAR(200) NOT NULL DEFAULT '',
			departure_date DATE NULL,
			return_date DATE NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			token VARCHAR(64) NULL,
			access_code VARCHAR(60) NOT NULL DEFAULT '',
			has_hotel TINYINT(1) NOT NULL DEFAULT 1,
			has_ferry TINYINT(1) NOT NULL DEFAULT 0,
			has_flight TINYINT(1) NOT NULL DEFAULT 0,
			hotel_name VARCHAR(200) NOT NULL DEFAULT '',
			hotel_notes TEXT NULL,
			ferry_company VARCHAR(200) NOT NULL DEFAULT '',
			ferry_notes TEXT NULL,
			airline VARCHAR(200) NOT NULL DEFAULT '',
			flight_notes TEXT NULL,
			room_types LONGTEXT NULL,
			cabin_types LONGTEXT NULL,
			extra_emails TEXT NULL,
			notes_school TEXT NULL,
			notes_internal TEXT NULL,
			meta LONGTEXT NULL,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			submitted_at DATETIME NULL,
			data_updated_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY status (status),
			KEY departure_date (departure_date)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}lgt_participants (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			trip_id BIGINT UNSIGNED NOT NULL,
			ptype VARCHAR(20) NOT NULL DEFAULT 'student',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			last_name VARCHAR(120) NOT NULL DEFAULT '',
			first_name VARCHAR(120) NOT NULL DEFAULT '',
			last_name_lat VARCHAR(120) NOT NULL DEFAULT '',
			first_name_lat VARCHAR(120) NOT NULL DEFAULT '',
			lat_manual TINYINT(1) NOT NULL DEFAULT 0,
			gender CHAR(1) NOT NULL DEFAULT '',
			birth_date DATE NULL,
			class_name VARCHAR(40) NOT NULL DEFAULT '',
			nationality VARCHAR(60) NOT NULL DEFAULT '',
			doc_type VARCHAR(20) NOT NULL DEFAULT '',
			doc_number VARCHAR(60) NOT NULL DEFAULT '',
			doc_expiry DATE NULL,
			phone VARCHAR(60) NOT NULL DEFAULT '',
			notes TEXT NULL,
			sort_order INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY trip_id (trip_id),
			KEY trip_status (trip_id, status)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}lgt_rooms (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			trip_id BIGINT UNSIGNED NOT NULL,
			kind VARCHAR(10) NOT NULL DEFAULT 'hotel',
			label VARCHAR(100) NOT NULL DEFAULT '',
			type_code VARCHAR(30) NOT NULL DEFAULT '',
			capacity TINYINT UNSIGNED NOT NULL DEFAULT 2,
			notes VARCHAR(255) NOT NULL DEFAULT '',
			sort_order INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY trip_kind (trip_id, kind)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}lgt_assignments (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			trip_id BIGINT UNSIGNED NOT NULL,
			kind VARCHAR(10) NOT NULL DEFAULT 'hotel',
			room_id BIGINT UNSIGNED NOT NULL,
			participant_id BIGINT UNSIGNED NOT NULL,
			position TINYINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY kind_participant (kind, participant_id),
			KEY room_id (room_id),
			KEY trip_kind (trip_id, kind)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}lgt_activity (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			trip_id BIGINT UNSIGNED NOT NULL,
			actor VARCHAR(20) NOT NULL DEFAULT 'system',
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			action VARCHAR(60) NOT NULL DEFAULT '',
			details TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY trip_id (trip_id, created_at)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}lgt_mail_log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			trip_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			kind VARCHAR(30) NOT NULL DEFAULT '',
			recipients TEXT NULL,
			subject VARCHAR(255) NOT NULL DEFAULT '',
			attachments TEXT NULL,
			success TINYINT(1) NOT NULL DEFAULT 0,
			error TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY trip_id (trip_id, created_at)
		) $charset;";

		foreach ( $sql as $q ) {
			dbDelta( $q );
		}
	}

	/** Attachment temp dir is protected from direct web access. */
	public static function secure_upload_dir() {
		$dir = LGT_Exporter::storage_dir();
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			@file_put_contents( $dir . '/.htaccess', "Order deny,allow\nDeny from all\n" );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
	}
}
