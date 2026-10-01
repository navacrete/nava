<?php
/**
 * Admin UI: Σήμερα / Εργαζόμενοι / Ρυθμίσεις / Ιστορικό.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LGTKS_Admin {

	const SLUG = 'lgt-karta-sms';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		foreach ( array( 'run', 'sync', 'save_settings', 'test_sms', 'test_source', 'save_employee', 'delete_employee', 'bulk_employees', 'manual_punch', 'undo_punch', 'send_now', 'regen_tokens' ) as $a ) {
			add_action( 'admin_post_lgtks_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	public static function menu() {
		add_menu_page( 'Κάρτα Εργασίας SMS', 'Κάρτα Εργασίας SMS', LGT_KS_CAP, self::SLUG, array( __CLASS__, 'page_today' ), 'dashicons-smartphone', 58 );
		add_submenu_page( self::SLUG, 'Σήμερα', 'Σήμερα', LGT_KS_CAP, self::SLUG, array( __CLASS__, 'page_today' ) );
		add_submenu_page( self::SLUG, 'Εργαζόμενοι', 'Εργαζόμενοι', LGT_KS_CAP, self::SLUG . '-employees', array( __CLASS__, 'page_employees' ) );
		add_submenu_page( self::SLUG, 'Ρυθμίσεις', 'Ρυθμίσεις', LGT_KS_CAP, self::SLUG . '-settings', array( __CLASS__, 'page_settings' ) );
		add_submenu_page( self::SLUG, 'Ιστορικό', 'Ιστορικό', LGT_KS_CAP, self::SLUG . '-log', array( __CLASS__, 'page_log' ) );
	}

	public static function assets( $hook ) {
		if ( strpos( $hook, self::SLUG ) === false ) {
			return;
		}
		wp_enqueue_style( 'lgtks-admin', LGT_KS_URL . 'assets/css/admin.css', array(), LGT_KS_VERSION );
		wp_add_inline_script( 'jquery-core', self::inline_js() );
	}

	private static function inline_js() {
		return "jQuery(function($){
			function sw(){ $('.lgtks-provider').removeClass('on'); $('.lgtks-provider[data-p=\"'+$('#sms_provider').val()+'\"]').addClass('on');
			               $('.lgtks-source').removeClass('on'); $('.lgtks-source[data-s=\"'+$('#source_type').val()+'\"]').addClass('on');
			               /* hidden sections must not submit (API and CSV share field names) */
			               $('.lgtks-provider,.lgtks-source').each(function(){ $(this).find('input,select,textarea').prop('disabled', !$(this).hasClass('on')); }); }
			$('#sms_provider,#source_type').on('change', sw); sw();
			$('.lgtks-copy-week').on('click', function(e){ e.preventDefault(); var v=$('input[name=\"schedule[1]\"]').val(); $('input[name^=\"schedule[\"]').slice(1,5).val(v); });
		});";
	}

	/* ---------- helpers ---------- */

	private static function url( $page = '', $args = array() ) {
		$slug = self::SLUG . ( $page ? '-' . $page : '' );
		return add_query_arg( array_merge( array( 'page' => $slug ), $args ), admin_url( 'admin.php' ) );
	}

	private static function guard( $action ) {
		if ( ! current_user_can( LGT_KS_CAP ) ) {
			wp_die( 'Δεν έχετε δικαίωμα.' );
		}
		check_admin_referer( 'lgtks_' . $action );
	}

	private static function back( $page, $msg, $type = 'success', $extra = array() ) {
		set_transient( 'lgtks_notice_' . get_current_user_id(), array( 'msg' => $msg, 'type' => $type ), 60 );
		wp_safe_redirect( self::url( $page, $extra ) );
		exit;
	}

	public static function notices() {
		if ( ! isset( $_GET['page'] ) || strpos( sanitize_text_field( wp_unslash( $_GET['page'] ) ), self::SLUG ) !== 0 ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$n = get_transient( 'lgtks_notice_' . get_current_user_id() );
		if ( $n ) {
			delete_transient( 'lgtks_notice_' . get_current_user_id() );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $n['type'] ), wp_kses_post( $n['msg'] ) );
		}
	}

	private static function form_open( $action, $extra_class = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="' . esc_attr( $extra_class ) . '">';
		echo '<input type="hidden" name="action" value="lgtks_' . esc_attr( $action ) . '">';
		wp_nonce_field( 'lgtks_' . $action );
	}

	private static function button_form( $action, $label, $class = 'button', $hidden = array(), $confirm = '' ) {
		self::form_open( $action, 'lgtks-inline' );
		foreach ( $hidden as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		$onclick = $confirm ? ' onclick="return confirm(\'' . esc_js( $confirm ) . '\')"' : '';
		echo '<button type="submit" class="' . esc_attr( $class ) . '"' . $onclick . '>' . esc_html( $label ) . '</button></form> ';
	}

	private static function status_label( $s ) {
		$map = array(
			'present'            => 'Χτύπησε',
			'due'                => 'ΔΕΝ ΧΤΥΠΗΣΕ',
			'not_yet'            => 'Αναμονή',
			'off'                => 'Ρεπό / εκτός βάρδιας',
			'holiday'            => 'Αργία',
			'late_window_passed' => 'Εκτός παραθύρου',
		);
		return isset( $map[ $s ] ) ? $map[ $s ] : $s;
	}

	/* ---------- Σήμερα ---------- */

	public static function page_today() {
		$rows      = LGTKS_Checker::status_for_day();
		$last_run  = get_option( 'lgt_ks_last_run' );
		$last_sync = get_option( 'lgt_ks_last_sync' );
		$enabled   = LGTKS_Settings::get( 'enabled' );
		$next      = wp_next_scheduled( LGTKS_Cron::HOOK );
		$counts    = array( 'present' => 0, 'due' => 0, 'not_yet' => 0 );
		foreach ( $rows as $r ) {
			if ( isset( $counts[ $r['status'] ] ) ) {
				$counts[ $r['status'] ]++;
			}
		}
		echo '<div class="wrap lgtks-wrap"><h1>Κάρτα Εργασίας – ' . esc_html( wp_date( 'l d/m/Y, H:i' ) ) . '</h1>';
		if ( ! $enabled ) {
			echo '<div class="notice notice-warning"><p><strong>Οι αυτόματες ειδοποιήσεις είναι απενεργοποιημένες.</strong> Ενεργοποιήστε τις στις <a href="' . esc_url( self::url( 'settings' ) ) . '">Ρυθμίσεις</a> όταν ολοκληρώσετε τη διαμόρφωση. Οι χειροκίνητες ενέργειες εδώ λειτουργούν κανονικά.</p></div>';
		}
		echo '<div class="lgtks-cards">';
		echo '<div class="lgtks-card"><h3>Χτύπησαν</h3><div class="big">' . (int) $counts['present'] . '</div></div>';
		echo '<div class="lgtks-card"><h3>Δεν χτύπησαν</h3><div class="big" style="color:#842029">' . (int) $counts['due'] . '</div></div>';
		echo '<div class="lgtks-card"><h3>Σε αναμονή</h3><div class="big">' . (int) $counts['not_yet'] . '</div></div>';
		echo '<div class="lgtks-card"><h3>Τελευταίος έλεγχος</h3><div>' . ( $last_run ? esc_html( $last_run['at'] ) : '—' ) . '</div>';
		echo '<div style="color:#646970">Επόμενος (WP-Cron): ' . ( $next ? esc_html( wp_date( 'H:i', $next ) ) : '—' ) . '</div></div>';
		echo '<div class="lgtks-card"><h3>Τελευταίος συγχρονισμός πηγής</h3><div>' . ( $last_sync ? esc_html( $last_sync['at'] . ' – ' . $last_sync['msg'] ) : '—' ) . '</div></div>';
		echo '</div>';

		echo '<p>';
		self::button_form( 'run', 'Έλεγχος τώρα (συγχρονισμός + SMS σε όσους εκκρεμούν)', 'button button-primary' );
		if ( in_array( LGTKS_Settings::get( 'source_type' ), array( 'api_json', 'csv_url' ), true ) ) {
			self::button_form( 'sync', 'Μόνο συγχρονισμός χτυπημάτων', 'button' );
		}
		echo '</p>';

		echo '<table class="widefat striped"><thead><tr><th>Εργαζόμενος</th><th>Κινητό</th><th>Βάρδια</th><th>Όριο</th><th>Κατάσταση</th><th>Χτύπημα</th><th>SMS</th><th>Ενέργειες</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="8">Δεν υπάρχουν ενεργοί εργαζόμενοι. <a href="' . esc_url( self::url( 'employees' ) ) . '">Προσθέστε εργαζόμενους</a>.</td></tr>';
		}
		foreach ( $rows as $r ) {
			$e = $r['employee'];
			echo '<tr>';
			echo '<td><strong>' . esc_html( $e['name'] ) . '</strong>' . ( $e['external_id'] ? '<br><small>' . esc_html( $e['external_id'] ) . '</small>' : '' ) . '</td>';
			echo '<td>' . esc_html( $e['mobile'] ) . '</td>';
			echo '<td>' . ( $r['start'] ? esc_html( $r['start'] ) : '—' ) . '</td>';
			echo '<td>' . ( $r['deadline'] ? esc_html( $r['deadline'] ) : '—' ) . '</td>';
			echo '<td><span class="lgtks-status ' . esc_attr( $r['status'] ) . '">' . esc_html( self::status_label( $r['status'] ) ) . '</span></td>';
			echo '<td>' . ( $r['first_at'] ? esc_html( substr( $r['first_at'], 11, 5 ) ) : '—' ) . '</td>';
			$sms = array();
			foreach ( $r['sms'] as $n ) {
				$sms[] = ( 'sent' === $n['status'] ? '✔' : '✖' ) . ' ' . substr( $n['created_at'], 11, 5 );
			}
			echo '<td>' . ( $sms ? esc_html( implode( ', ', $sms ) ) : '—' ) . '</td>';
			echo '<td>';
			if ( $r['first_at'] ) {
				self::button_form( 'undo_punch', 'Αναίρεση χτυπήματος', 'button button-small', array( 'employee_id' => $e['id'] ), 'Διαγραφή των σημερινών χτυπημάτων για ' . $e['name'] . ';' );
			} else {
				self::button_form( 'manual_punch', 'Χτύπησε (χειροκίνητα)', 'button button-small', array( 'employee_id' => $e['id'] ) );
				self::button_form( 'send_now', 'SMS τώρα', 'button button-small', array( 'employee_id' => $e['id'] ), 'Αποστολή SMS σε ' . $e['name'] . ' (' . $e['mobile'] . ');' );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		if ( $last_run && ! empty( $last_run['summary'] ) ) {
			echo '<p style="color:#646970">Τελευταίο αποτέλεσμα: ' . esc_html( wp_json_encode( $last_run['summary'], JSON_UNESCAPED_UNICODE ) ) . '</p>';
		}
		echo '</div>';
	}

	public static function handle_run() {
		self::guard( 'run' );
		$s = LGTKS_Checker::run( true );
		if ( isset( $s['skipped'] ) ) {
			self::back( '', 'Ο έλεγχος τρέχει ήδη, δοκιμάστε σε λίγο.', 'warning' );
		}
		$sync = '';
		if ( is_string( $s['sync'] ) ) {
			$sync = ' Σφάλμα πηγής: ' . $s['sync'];
		} elseif ( is_array( $s['sync'] ) && empty( $s['sync']['skipped'] ) ) {
			$sync = sprintf( ' Πηγή: %d εγγραφές, %d νέες.', $s['sync']['records'], $s['sync']['new'] );
			if ( ! empty( $s['sync']['unmatched'] ) ) {
				$sync .= ' Χωρίς αντιστοίχιση: ' . implode( ', ', array_slice( $s['sync']['unmatched'], 0, 10 ) );
			}
		}
		self::back( '', sprintf( 'Έλεγχος ολοκληρώθηκε: %d εκκρεμούσαν, %d SMS εστάλησαν, %d απέτυχαν.%s', $s['due'], $s['sent'], $s['failed'], $sync ), $s['failed'] ? 'warning' : 'success' );
	}

	public static function handle_sync() {
		self::guard( 'sync' );
		$r = LGTKS_Source::sync();
		if ( is_wp_error( $r ) ) {
			self::back( '', 'Σφάλμα συγχρονισμού: ' . $r->get_error_message(), 'error' );
		}
		$u = ! empty( $r['unmatched'] ) ? ' Χωρίς αντιστοίχιση (προσθέστε τον κωδικό στον εργαζόμενο): ' . implode( ', ', array_slice( $r['unmatched'], 0, 15 ) ) : '';
		self::back( '', sprintf( 'Συγχρονισμός: %d εγγραφές, %d αντιστοιχίστηκαν, %d νέες.%s', $r['records'], $r['matched'], $r['new'], $u ), $u ? 'warning' : 'success' );
	}

	public static function handle_manual_punch() {
		self::guard( 'manual_punch' );
		$id = isset( $_POST['employee_id'] ) ? (int) $_POST['employee_id'] : 0;
		LGTKS_DB::add_punch( $id, current_time( 'mysql' ), 'in', 'manual', 'admin:' . get_current_user_id() );
		self::back( '', 'Καταχωρήθηκε χειροκίνητο χτύπημα.' );
	}

	public static function handle_undo_punch() {
		self::guard( 'undo_punch' );
		$id = isset( $_POST['employee_id'] ) ? (int) $_POST['employee_id'] : 0;
		LGTKS_DB::delete_punches( $id, current_time( 'Y-m-d' ) );
		self::back( '', 'Τα σημερινά χτυπήματα διαγράφηκαν.' );
	}

	public static function handle_send_now() {
		self::guard( 'send_now' );
		$id   = isset( $_POST['employee_id'] ) ? (int) $_POST['employee_id'] : 0;
		$rows = LGTKS_Checker::status_for_day();
		foreach ( $rows as $r ) {
			if ( (int) $r['employee']['id'] === $id ) {
				if ( '' === $r['start'] ) {
					$r['start'] = wp_date( 'H:i' );
				}
				$ok = LGTKS_Checker::notify( $r, 1, true );
				self::back( '', $ok ? 'Το SMS εστάλη.' : 'Το SMS απέτυχε – δείτε το Ιστορικό.', $ok ? 'success' : 'error' );
			}
		}
		self::back( '', 'Ο εργαζόμενος δεν βρέθηκε.', 'error' );
	}

	/* ---------- Εργαζόμενοι ---------- */

	public static function page_employees() {
		$edit_id = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$edit    = $edit_id ? LGTKS_DB::employee( $edit_id ) : null;
		$days    = array( 1 => 'Δευ', 2 => 'Τρί', 3 => 'Τετ', 4 => 'Πέμ', 5 => 'Παρ', 6 => 'Σάβ', 7 => 'Κυρ' );
		echo '<div class="wrap lgtks-wrap"><h1>Εργαζόμενοι</h1>';

		echo '<div class="lgtks-section"><h2>' . ( $edit ? 'Επεξεργασία: ' . esc_html( $edit['name'] ) : 'Νέος εργαζόμενος' ) . '</h2>';
		self::form_open( 'save_employee' );
		echo '<input type="hidden" name="id" value="' . (int) $edit_id . '">';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>Ονοματεπώνυμο</th><td><input type="text" name="name" class="regular-text" required value="' . esc_attr( $edit['name'] ?? '' ) . '"></td></tr>';
		echo '<tr><th>Κινητό</th><td><input type="text" name="mobile" class="regular-text" required value="' . esc_attr( $edit['mobile'] ?? '' ) . '" placeholder="69xxxxxxxx"></td></tr>';
		echo '<tr><th>Κωδικός στο eVardia / ΑΦΜ</th><td><input type="text" name="external_id" class="regular-text" value="' . esc_attr( $edit['external_id'] ?? '' ) . '"><p class="description">Ό,τι στέλνει η πηγή για να αναγνωριστεί ο εργαζόμενος (κωδικός, ΑΦΜ, ΑΜΚΑ…). Αν μείνει κενό, γίνεται αντιστοίχιση με κινητό ή ονοματεπώνυμο.</p></td></tr>';
		echo '<tr><th>Ώρα έναρξης βάρδιας</th><td><table class="schedule"><tr>';
		foreach ( $days as $d => $l ) {
			echo '<th>' . esc_html( $l ) . '</th>';
		}
		echo '</tr><tr>';
		foreach ( $days as $d => $l ) {
			$v = isset( $edit['schedule'][ $d ] ) ? $edit['schedule'][ $d ] : ( ( ! $edit && $d <= 5 ) ? '09:00' : '' );
			echo '<td><input type="time" name="schedule[' . (int) $d . ']" value="' . esc_attr( $v ) . '"></td>';
		}
		echo '</tr></table><p class="description">Κενό = ρεπό εκείνη τη μέρα. <a href="#" class="lgtks-copy-week">Αντιγραφή Δευτέρας σε Τρί–Παρ</a></p></td></tr>';
		echo '<tr><th>Ανοχή (λεπτά)</th><td><input type="number" name="grace_minutes" min="0" max="600" value="' . esc_attr( null === ( $edit['grace_minutes'] ?? null ) ? '' : $edit['grace_minutes'] ) . '" style="width:90px"> <span class="description">Κενό = γενική ρύθμιση (' . (int) LGTKS_Settings::get( 'grace_minutes' ) . ')</span></td></tr>';
		echo '<tr><th>Άδειες / ρεπό (ημερομηνίες)</th><td><textarea name="days_off" rows="2" class="large-text" placeholder="2026-10-28, 2026-12-24..2027-01-02">' . esc_textarea( $edit['days_off'] ?? '' ) . '</textarea><p class="description">Μορφή ΕΕΕΕ-ΜΜ-ΗΗ ή ΗΗ/ΜΜ/ΕΕΕΕ, διάστημα με «..». Τις ημέρες αυτές δεν στέλνεται SMS.</p></td></tr>';
		echo '<tr><th>Σημειώσεις</th><td><input type="text" name="notes" class="large-text" value="' . esc_attr( $edit['notes'] ?? '' ) . '"></td></tr>';
		echo '<tr><th>Ενεργός</th><td><label><input type="checkbox" name="active" value="1" ' . checked( $edit ? $edit['active'] : 1, 1, false ) . '> Παρακολουθείται</label></td></tr>';
		echo '</tbody></table>';
		submit_button( $edit ? 'Αποθήκευση' : 'Προσθήκη', 'primary', 'submit', false );
		if ( $edit ) {
			echo ' <a class="button" href="' . esc_url( self::url( 'employees' ) ) . '">Άκυρο</a>';
		}
		echo '</form></div>';

		echo '<div class="lgtks-section"><h2>Μαζική προσθήκη</h2>';
		self::form_open( 'bulk_employees' );
		echo '<p class="description">Μία γραμμή ανά εργαζόμενο: <code>Ονοματεπώνυμο; Κινητό; Κωδικός eVardia; Ώρα Δευ–Παρ; Ώρα Σάβ; Ώρα Κυρ</code> (τα 3 τελευταία προαιρετικά, π.χ. <code>Μαρία Παπαδοπούλου; 6912345678; 1234; 09:00</code>). Επικόλληση και από Excel με Tab.</p>';
		echo '<textarea name="bulk" rows="5" class="large-text"></textarea><p>';
		submit_button( 'Προσθήκη όλων', 'secondary', 'submit', false );
		echo '</p></form></div>';

		$list = LGTKS_DB::employees();
		echo '<table class="widefat striped"><thead><tr><th>Όνομα</th><th>Κινητό</th><th>Κωδικός</th>';
		foreach ( $days as $l ) {
			echo '<th>' . esc_html( $l ) . '</th>';
		}
		echo '<th>Ενεργός</th><th></th></tr></thead><tbody>';
		foreach ( $list as $e ) {
			echo '<tr><td><strong>' . esc_html( $e['name'] ) . '</strong></td><td>' . esc_html( $e['mobile'] ) . '</td><td>' . esc_html( $e['external_id'] ) . '</td>';
			foreach ( $days as $d => $l ) {
				echo '<td>' . ( ! empty( $e['schedule'][ $d ] ) ? esc_html( $e['schedule'][ $d ] ) : '<span style="color:#aaa">—</span>' ) . '</td>';
			}
			echo '<td>' . ( $e['active'] ? 'Ναι' : 'Όχι' ) . '</td><td><a class="button button-small" href="' . esc_url( self::url( 'employees', array( 'edit' => $e['id'] ) ) ) . '">Επεξεργασία</a> ';
			self::button_form( 'delete_employee', 'Διαγραφή', 'button button-small button-link-delete', array( 'id' => $e['id'] ), 'Διαγραφή ' . $e['name'] . ';' );
			echo '</td></tr>';
		}
		if ( ! $list ) {
			echo '<tr><td colspan="12">Κανένας εργαζόμενος ακόμη.</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function handle_save_employee() {
		self::guard( 'save_employee' );
		$id   = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$data = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security
		if ( empty( $data['name'] ) || empty( $data['mobile'] ) ) {
			self::back( 'employees', 'Όνομα και κινητό είναι υποχρεωτικά.', 'error' );
		}
		LGTKS_DB::save_employee( $data, $id );
		self::back( 'employees', 'Ο εργαζόμενος αποθηκεύτηκε.' );
	}

	public static function handle_delete_employee() {
		self::guard( 'delete_employee' );
		LGTKS_DB::delete_employee( isset( $_POST['id'] ) ? (int) $_POST['id'] : 0 );
		self::back( 'employees', 'Διαγράφηκε.' );
	}

	public static function handle_bulk_employees() {
		self::guard( 'bulk_employees' );
		$text = isset( $_POST['bulk'] ) ? wp_unslash( $_POST['bulk'] ) : ''; // phpcs:ignore WordPress.Security
		$n    = 0;
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$c = array_map( 'trim', preg_split( '/[;\t]/', $line ) );
			if ( count( $c ) < 2 && strpos( $line, ',' ) !== false ) {
				$c = array_map( 'trim', explode( ',', $line ) );
			}
			if ( count( $c ) < 2 || '' === $c[0] || '' === $c[1] ) {
				continue;
			}
			$wd = isset( $c[3] ) && '' !== $c[3] ? $c[3] : '09:00';
			$sa = isset( $c[4] ) ? $c[4] : '';
			$su = isset( $c[5] ) ? $c[5] : '';
			LGTKS_DB::save_employee(
				array(
					'name'        => $c[0],
					'mobile'      => $c[1],
					'external_id' => isset( $c[2] ) ? $c[2] : '',
					'schedule'    => array( 1 => $wd, 2 => $wd, 3 => $wd, 4 => $wd, 5 => $wd, 6 => $sa, 7 => $su ),
					'active'      => 1,
				)
			);
			$n++;
		}
		self::back( 'employees', sprintf( 'Προστέθηκαν %d εργαζόμενοι.', $n ) );
	}

	/* ---------- Ρυθμίσεις ---------- */

	private static function text( $key, $label, $desc = '', $type = 'text', $attrs = '' ) {
		$v = LGTKS_Settings::get( $key );
		echo '<tr><th><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input type="' . esc_attr( $type ) . '" id="' . esc_attr( $key ) . '" name="s[' . esc_attr( $key ) . ']" class="regular-text" value="' . esc_attr( $v ) . '" ' . $attrs . '>' . ( $desc ? '<p class="description">' . wp_kses_post( $desc ) . '</p>' : '' ) . '</td></tr>';
	}

	private static function area( $key, $label, $desc = '', $rows = 3 ) {
		echo '<tr><th><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><textarea id="' . esc_attr( $key ) . '" name="s[' . esc_attr( $key ) . ']" rows="' . (int) $rows . '" class="large-text code">' . esc_textarea( LGTKS_Settings::get( $key ) ) . '</textarea>' . ( $desc ? '<p class="description">' . wp_kses_post( $desc ) . '</p>' : '' ) . '</td></tr>';
	}

	private static function check( $key, $label, $desc = '' ) {
		echo '<tr><th>' . esc_html( $label ) . '</th><td><label><input type="checkbox" name="s[' . esc_attr( $key ) . ']" value="1" ' . checked( LGTKS_Settings::get( $key ), 1, false ) . '> ' . wp_kses_post( $desc ) . '</label></td></tr>';
	}

	private static function select( $key, $label, array $opts, $desc = '' ) {
		echo '<tr><th><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><select id="' . esc_attr( $key ) . '" name="s[' . esc_attr( $key ) . ']">';
		foreach ( $opts as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '" ' . selected( LGTKS_Settings::get( $key ), $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select>' . ( $desc ? '<p class="description">' . wp_kses_post( $desc ) . '</p>' : '' ) . '</td></tr>';
	}

	public static function page_settings() {
		$webhook = rest_url( 'lgt-karta/v1/punch' ) . '?token=' . LGTKS_Settings::get( 'webhook_token' );
		$cronurl = rest_url( 'lgt-karta/v1/run' ) . '?token=' . LGTKS_Settings::get( 'cron_token' );
		$test    = get_transient( 'lgtks_test_' . get_current_user_id() );
		if ( $test ) {
			delete_transient( 'lgtks_test_' . get_current_user_id() );
		}
		echo '<div class="wrap lgtks-wrap"><h1>Ρυθμίσεις</h1>';
		if ( $test ) {
			echo '<div class="lgtks-section"><h2>' . esc_html( $test['title'] ) . '</h2><pre class="lgtks-resp">' . esc_html( $test['body'] ) . '</pre></div>';
		}
		self::form_open( 'save_settings' );

		echo '<div class="lgtks-section"><h2>Γενικά</h2><table class="form-table"><tbody>';
		self::check( 'enabled', 'Αυτόματες ειδοποιήσεις', 'Ενεργές (έλεγχος κάθε 5 λεπτά και αποστολή SMS σε όσους δεν χτύπησαν)' );
		self::text( 'company_name', 'Επωνυμία', 'Για το {company} στο μήνυμα.' );
		self::text( 'grace_minutes', 'Ανοχή (λεπτά)', 'Πόσα λεπτά μετά την έναρξη βάρδιας περιμένουμε πριν στείλουμε SMS.', 'number', 'min="0" max="600" style="width:90px"' );
		self::text( 'max_delay_minutes', 'Μέγιστη καθυστέρηση (λεπτά)', 'Μετά από τόσα λεπτά από την έναρξη δεν στέλνεται πλέον SMS (π.χ. ο εργαζόμενος απουσιάζει).', 'number', 'min="0" max="1440" style="width:90px"' );
		self::text( 'second_reminder_minutes', 'Δεύτερη υπενθύμιση μετά από (λεπτά)', '0 = χωρίς δεύτερο SMS.', 'number', 'min="0" max="600" style="width:90px"' );
		self::area( 'message_template', 'Μήνυμα SMS στον εργαζόμενο', 'Μεταβλητές: {first_name} {name} {time} {date} {now} {minutes} {company}. Ελληνικοί χαρακτήρες = Unicode SMS (70 χαρακτήρες/τμήμα).', 3 );
		self::area( 'manager_mobiles', 'Κινητά υπευθύνων', 'Ένα ανά γραμμή ή με κόμμα. Ειδοποιούνται με SMS για κάθε εργαζόμενο που δεν χτύπησε (στην πρώτη ειδοποίηση). Κενό = δεν στέλνεται.', 2 );
		self::area( 'manager_message_template', 'Μήνυμα στον υπεύθυνο', 'Ίδιες μεταβλητές.', 2 );
		self::text( 'manager_email', 'Email υπευθύνου', 'Προαιρετικό: email για κάθε περίπτωση που δεν χτύπησε κάρτα.', 'email' );
		self::area( 'holidays', 'Αργίες (όλη η εταιρεία)', 'Ημερομηνίες ΕΕΕΕ-ΜΜ-ΗΗ, π.χ. <code>2026-10-28, 2026-12-25, 2026-12-26, 2027-01-01</code>. Διάστημα: <code>2026-08-10..2026-08-16</code>.', 2 );
		self::text( 'country_prefix', 'Κωδικός χώρας', 'Προστίθεται σε κινητά 10 ψηφίων (69…). Ελλάδα = 30.', 'text', 'style="width:90px"' );
		echo '</tbody></table></div>';

		echo '<div class="lgtks-section"><h2>Αποστολή SMS</h2><table class="form-table"><tbody>';
		self::select( 'sms_provider', 'Πάροχος', LGTKS_SMS::providers() );
		self::text( 'sms_sender', 'Αποστολέας (Sender ID)', 'Έως 11 λατινικοί χαρακτήρες, π.χ. LeGrand. Πρέπει να είναι εγκεκριμένος στον πάροχο.' );
		echo '</tbody></table>';
		echo '<div class="lgtks-provider" data-p="yuboto"><table class="form-table"><tbody>';
		self::text( 'yuboto_api_key', 'Yuboto API key', 'Από το yuboto.com → Settings → API. Χρησιμοποιείται το Omni API (<code>services.yuboto.com/omni/v1/Send</code>). Αν ο λογαριασμός σας έχει διαφορετικό API, επιλέξτε «Γενικό HTTP API».', 'password' );
		echo '</tbody></table></div>';
		echo '<div class="lgtks-provider" data-p="routee"><table class="form-table"><tbody>';
		self::text( 'routee_app_id', 'Routee application id' );
		self::text( 'routee_app_secret', 'Routee application secret', '', 'password' );
		echo '</tbody></table></div>';
		echo '<div class="lgtks-provider" data-p="twilio"><table class="form-table"><tbody>';
		self::text( 'twilio_sid', 'Twilio Account SID' );
		self::text( 'twilio_token', 'Twilio Auth Token', '', 'password' );
		self::text( 'twilio_from', 'Twilio From', 'Αριθμός Twilio (+1…) ή εγκεκριμένο alphanumeric sender.' );
		echo '</tbody></table></div>';
		echo '<div class="lgtks-provider" data-p="generic"><table class="form-table"><tbody>';
		self::text( 'generic_url', 'URL', 'Από την τεκμηρίωση του παρόχου σας (Yuboto, Routee, EasySMS, SMS.to, Apifon, Vonage…). Μεταβλητές σε URL/headers/body: <code>{to}</code> (306912345678) <code>{to_plus}</code> (+30…) <code>{to_url}</code> <code>{message}</code> <code>{message_url}</code> <code>{sender}</code> <code>{sender_url}</code>.', 'url' );
		self::select( 'generic_method', 'Μέθοδος', array( 'POST' => 'POST', 'GET' => 'GET', 'PUT' => 'PUT' ) );
		self::area( 'generic_headers', 'Headers', 'Μία γραμμή ανά header, π.χ. <code>Authorization: Bearer XXXX</code> και <code>Content-Type: application/json</code>.', 3 );
		self::area( 'generic_body', 'Body', 'Για POST/PUT. Αν το Content-Type είναι JSON, οι μεταβλητές γίνονται αυτόματα JSON-escaped. Για form-encoded: <code>to={to}&amp;text={message_url}&amp;from={sender}</code>.', 3 );
		self::text( 'generic_success_contains', 'Επιτυχία αν η απάντηση περιέχει', 'Προαιρετικό, π.χ. <code>"status":"ok"</code>. Αλλιώς αρκεί HTTP 2xx.' );
		echo '</tbody></table></div>';
		echo '</div>';

		echo '<div class="lgtks-section"><h2>Πηγή χτυπημάτων (eVardia)</h2><table class="form-table"><tbody>';
		self::select( 'source_type', 'Τρόπος λήψης', LGTKS_Source::types() );
		echo '</tbody></table>';
		echo '<div class="lgtks-source" data-s="webhook"><p>Δώστε αυτό το URL στο eVardia (αν υποστηρίζει webhooks/ειδοποιήσεις HTTP) ή σε Zapier/Make/n8n που διαβάζει το eVardia και κάνει POST εδώ για κάθε χτύπημα:</p>';
		echo '<p><code class="lgtks-url">' . esc_html( $webhook ) . '</code></p>';
		echo '<p class="description">Δέχεται JSON <code>{"employee":"ΚΩΔΙΚΟΣ ή ΑΦΜ ή κινητό ή ονοματεπώνυμο","datetime":"2026-10-01 09:02","kind":"in"}</code>, λίστα τέτοιων, ή απλά <code>?employee=…&amp;datetime=…</code>. Το token μπορεί να πάει και σε header <code>X-LGTKS-Token</code>. Αν λείπει η ώρα, θεωρείται «τώρα».</p></div>';
		echo '<div class="lgtks-source" data-s="api_json"><table class="form-table"><tbody>';
		self::text( 'source_url', 'URL API', 'Μεταβλητές: <code>{date}</code> (2026-10-01) <code>{date_dmy}</code> (01/10/2026) <code>{from}</code>/<code>{to}</code> (ISO αρχή/τέλος ημέρας) <code>{ts_from}</code>/<code>{ts_to}</code>.', 'url' );
		self::select( 'source_method', 'Μέθοδος', array( 'GET' => 'GET', 'POST' => 'POST' ) );
		self::area( 'source_headers', 'Headers', 'π.χ. <code>Authorization: Bearer XXXX</code> ή <code>X-Api-Key: XXXX</code>.', 2 );
		self::area( 'source_body', 'Body (για POST)', 'π.χ. <code>{"date":"{date}"}</code>', 2 );
		self::text( 'source_records_path', 'Διαδρομή λίστας στο JSON', 'Κενό αν η απάντηση είναι απευθείας λίστα. Αλλιώς π.χ. <code>data</code> ή <code>result.records</code>.' );
		self::text( 'source_field_employee', 'Πεδίο εργαζόμενου', 'π.χ. <code>employeeCode</code>, <code>afm</code>, <code>employee.name</code>. Αντιστοιχίζεται με τον «Κωδικό eVardia», το κινητό ή το ονοματεπώνυμο.' );
		self::text( 'source_field_datetime', 'Πεδίο ημερομηνίας/ώρας', 'π.χ. <code>timestamp</code> ή <code>datetime</code>.' );
		self::text( 'source_field_kind', 'Πεδίο τύπου (προαιρετικό)', 'Αν η πηγή επιστρέφει και αποχωρήσεις, το πεδίο που λέει είσοδος/έξοδος, π.χ. <code>type</code>.' );
		self::text( 'source_kind_in_value', 'Τιμή για «είσοδο»', 'π.χ. <code>IN</code>, <code>1</code>, <code>Έναρξη</code>. Κενό = όλα μετρούν ως παρουσία.' );
		self::text( 'source_date_format', 'Μορφή ημερομηνίας (προαιρετικό)', 'Μορφή PHP αν δεν αναγνωρίζεται αυτόματα, π.χ. <code>d/m/Y H:i:s</code>. Καταλαβαίνει αυτόματα ISO, Unix timestamp, ΗΗ/ΜΜ/ΕΕΕΕ ΩΩ:ΛΛ.' );
		echo '</tbody></table></div>';
		echo '<div class="lgtks-source" data-s="csv_url"><table class="form-table"><tbody>';
		self::text( 'source_url', 'URL αρχείου CSV', 'Σύνδεσμος export που δίνει το eVardia (ή αρχείο που ανεβάζει κάποιος σε σταθερό URL). Ίδιες μεταβλητές ημερομηνίας με το API.', 'url' );
		self::area( 'source_headers', 'Headers (προαιρετικό)', '', 2 );
		self::text( 'csv_delimiter', 'Διαχωριστικό', '<code>;</code> <code>,</code> ή <code>tab</code>.', 'text', 'style="width:60px"' );
		self::text( 'source_field_employee', 'Στήλη εργαζόμενου', 'Όνομα στήλης στην πρώτη γραμμή ή αριθμός στήλης (1 = πρώτη).' );
		self::text( 'source_field_datetime', 'Στήλη ημερομηνίας/ώρας', 'Όνομα ή αριθμός στήλης.' );
		self::text( 'source_field_kind', 'Στήλη τύπου (προαιρετικό)' );
		self::text( 'source_kind_in_value', 'Τιμή για «είσοδο»' );
		self::text( 'source_date_format', 'Μορφή ημερομηνίας (προαιρετικό)', 'π.χ. <code>d/m/Y H:i</code>.' );
		echo '</tbody></table></div>';
		echo '<div class="lgtks-source" data-s="manual"><p class="description">Τα χτυπήματα σημειώνονται μόνο με το χέρι από τον πίνακα «Σήμερα». Το SMS στέλνεται σε όσους δεν έχουν σημειωθεί μέχρι το όριο.</p></div>';
		echo '</div>';

		echo '<div class="lgtks-section"><h2>Αξιοπιστία ελέγχου</h2>';
		echo '<p>Ο έλεγχος τρέχει κάθε 5 λεπτά με το WP-Cron, που όμως εκτελείται μόνο όταν έχει επισκέψεις το site. Για να είναι σίγουρο, βάλτε στο hosting (cPanel → Cron Jobs) ή στο cron-job.org να καλεί κάθε 5 λεπτά αυτό το URL:</p>';
		echo '<p><code class="lgtks-url">' . esc_html( $cronurl ) . '</code></p>';
		echo '<p class="description">π.χ. <code>*/5 * * * * curl -s "' . esc_html( $cronurl ) . '" &gt;/dev/null</code></p>';
		self::check( 'delete_on_uninstall', 'Απεγκατάσταση', 'Διαγραφή όλων των δεδομένων (εργαζόμενοι, ιστορικό) κατά την απεγκατάσταση του plugin' );
		echo '</div>';

		submit_button( 'Αποθήκευση ρυθμίσεων' );
		echo '</form>';

		echo '<div class="lgtks-cards">';
		echo '<div class="lgtks-card"><h3>Δοκιμαστικό SMS</h3>';
		self::form_open( 'test_sms' );
		echo '<input type="text" name="mobile" placeholder="69xxxxxxxx" required> <button class="button">Αποστολή</button><p class="description">Αποθηκεύστε πρώτα τις ρυθμίσεις.</p></form></div>';
		echo '<div class="lgtks-card"><h3>Δοκιμή πηγής</h3>';
		self::form_open( 'test_source' );
		echo '<button class="button">Λήψη σημερινών χτυπημάτων</button><p class="description">Δείχνει τι επιστρέφει το API/CSV και ποιοι αντιστοιχίστηκαν, χωρίς να στείλει SMS.</p></form></div>';
		echo '<div class="lgtks-card"><h3>Tokens</h3>';
		self::button_form( 'regen_tokens', 'Νέα tokens webhook/cron', 'button', array(), 'Τα παλιά URL θα πάψουν να ισχύουν. Συνέχεια;' );
		echo '</div></div>';
		echo '</div>';
	}

	public static function handle_save_settings() {
		self::guard( 'save_settings' );
		$in  = isset( $_POST['s'] ) && is_array( $_POST['s'] ) ? wp_unslash( $_POST['s'] ) : array(); // phpcs:ignore WordPress.Security
		$out = array();
		$def = LGTKS_Settings::defaults();
		foreach ( $def as $k => $d ) {
			if ( in_array( $k, array( 'webhook_token', 'cron_token' ), true ) ) {
				continue;
			}
			if ( is_int( $d ) && in_array( $k, array( 'enabled', 'delete_on_uninstall' ), true ) ) {
				$out[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
			} elseif ( is_int( $d ) ) {
				$out[ $k ] = isset( $in[ $k ] ) ? max( 0, (int) $in[ $k ] ) : $d;
			} elseif ( isset( $in[ $k ] ) ) {
				$v = (string) $in[ $k ];
				// Keep headers/bodies/templates raw (they may contain JSON, quotes, {vars}); only strip tags.
				$out[ $k ] = in_array( $k, array( 'generic_headers', 'generic_body', 'source_headers', 'source_body', 'message_template', 'manager_message_template', 'holidays', 'manager_mobiles' ), true ) ? wp_strip_all_tags( $v ) : sanitize_text_field( $v );
			}
		}
		if ( ! empty( $out['sms_sender'] ) ) {
			$out['sms_sender'] = substr( $out['sms_sender'], 0, 16 );
		}
		LGTKS_Settings::update( $out );
		delete_transient( 'lgtks_routee_token' );
		self::back( 'settings', 'Οι ρυθμίσεις αποθηκεύτηκαν.' );
	}

	public static function handle_test_sms() {
		self::guard( 'test_sms' );
		$mobile = isset( $_POST['mobile'] ) ? sanitize_text_field( wp_unslash( $_POST['mobile'] ) ) : '';
		$res    = LGTKS_SMS::send( $mobile, 'Δοκιμαστικό SMS από ' . LGTKS_Settings::get( 'company_name' ) . ' (Κάρτα Εργασίας). ' . wp_date( 'd/m H:i' ) );
		set_transient( 'lgtks_test_' . get_current_user_id(), array( 'title' => 'Απάντηση παρόχου SMS (' . LGTKS_SMS::normalize( $mobile ) . ')', 'body' => $res['response'] ), 120 );
		LGTKS_DB::log( $res['ok'] ? 'info' : 'error', 'Δοκιμαστικό SMS σε ' . $mobile, $res['response'] );
		self::back( 'settings', $res['ok'] ? 'Το δοκιμαστικό SMS εστάλη.' : 'Το δοκιμαστικό SMS απέτυχε – δείτε την απάντηση παρακάτω.', $res['ok'] ? 'success' : 'error' );
	}

	public static function handle_test_source() {
		self::guard( 'test_source' );
		$type = LGTKS_Settings::get( 'source_type' );
		if ( ! in_array( $type, array( 'api_json', 'csv_url' ), true ) ) {
			self::back( 'settings', 'Η δοκιμή αφορά μόνο API/CSV. Για webhook, στείλτε ένα δοκιμαστικό POST στο URL.', 'warning' );
		}
		$day  = current_time( 'Y-m-d' );
		$recs = ( 'api_json' === $type ) ? LGTKS_Source::fetch_json( $day ) : LGTKS_Source::fetch_csv( $day );
		if ( is_wp_error( $recs ) ) {
			set_transient( 'lgtks_test_' . get_current_user_id(), array( 'title' => 'Σφάλμα πηγής', 'body' => $recs->get_error_message() ), 120 );
			self::back( 'settings', 'Η λήψη απέτυχε.', 'error' );
		}
		$idx   = LGTKS_Source::employee_index();
		$lines = array( count( $recs ) . ' εγγραφές. Πρώτες 30:' );
		foreach ( array_slice( $recs, 0, 30 ) as $r ) {
			$m       = LGTKS_Source::match( $r['employee'], $idx );
			$lines[] = sprintf( '%-30s %-22s %-8s -> %s', $r['employee'], $r['datetime'], $r['kind'], $m ? 'εργαζόμενος #' . $m : 'ΧΩΡΙΣ ΑΝΤΙΣΤΟΙΧΙΣΗ' );
		}
		set_transient( 'lgtks_test_' . get_current_user_id(), array( 'title' => 'Δοκιμή πηγής', 'body' => implode( "\n", $lines ) ), 120 );
		self::back( 'settings', 'Η λήψη πέτυχε (δεν αποθηκεύτηκε τίποτα).' );
	}

	public static function handle_regen_tokens() {
		self::guard( 'regen_tokens' );
		LGTKS_Settings::update( array( 'webhook_token' => wp_generate_password( 32, false, false ), 'cron_token' => wp_generate_password( 32, false, false ) ) );
		self::back( 'settings', 'Δημιουργήθηκαν νέα tokens.' );
	}

	/* ---------- Ιστορικό ---------- */

	public static function page_log() {
		echo '<div class="wrap lgtks-wrap"><h1>Ιστορικό</h1>';
		echo '<h2>Ειδοποιήσεις</h2><table class="widefat striped"><thead><tr><th>Πότε</th><th>Εργαζόμενος</th><th>Κανάλι</th><th>Παραλήπτης</th><th>Κατάσταση</th><th>Μήνυμα</th><th>Απάντηση παρόχου</th></tr></thead><tbody>';
		foreach ( LGTKS_DB::recent_notifications( 200 ) as $n ) {
			echo '<tr><td>' . esc_html( $n['created_at'] ) . '</td><td>' . esc_html( $n['name'] ?: ( '#' . $n['employee_id'] ) ) . '</td><td>' . esc_html( 'manager' === $n['channel'] ? 'Υπεύθυνος' : 'SMS' ) . ( (int) $n['round'] > 1 ? ' (2η)' : '' ) . '</td><td>' . esc_html( $n['recipient'] ) . '</td><td>' . ( 'sent' === $n['status'] ? '<span class="lgtks-status present">Εστάλη</span>' : '<span class="lgtks-status due">Απέτυχε</span>' ) . '</td><td>' . esc_html( $n['message'] ) . '</td><td><small>' . esc_html( mb_substr( (string) $n['response'], 0, 200 ) ) . '</small></td></tr>';
		}
		echo '</tbody></table>';
		echo '<h2>Καταγραφή συστήματος</h2><table class="widefat striped"><thead><tr><th>Πότε</th><th>Επίπεδο</th><th>Μήνυμα</th><th>Λεπτομέρειες</th></tr></thead><tbody>';
		foreach ( LGTKS_DB::recent_log( 200 ) as $l ) {
			echo '<tr><td>' . esc_html( $l['created_at'] ) . '</td><td>' . esc_html( $l['level'] ) . '</td><td>' . esc_html( $l['message'] ) . '</td><td><small>' . esc_html( mb_substr( (string) $l['context'], 0, 300 ) ) . '</small></td></tr>';
		}
		echo '</tbody></table></div>';
	}
}
