<?php
/**
 * WP admin: menus, trip list, trip form, embedded management app, settings, logs.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LGT_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_lgt_save_trip', array( __CLASS__, 'handle_save_trip' ) );
		add_action( 'admin_post_lgt_delete_trip', array( __CLASS__, 'handle_delete_trip' ) );
		add_action( 'admin_post_lgt_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_lgt_run_cron', array( __CLASS__, 'handle_run_cron' ) );
		add_action( 'admin_post_lgt_test_email', array( __CLASS__, 'handle_test_email' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	public static function menu() {
		$cap = LGT_ST_CAP;
		if ( ! current_user_can( $cap ) && current_user_can( 'manage_options' ) ) {
			$cap = 'manage_options';
		}
		add_menu_page( 'Σχολικές Εκδρομές', 'Σχολικές Εκδρομές', $cap, 'lgt-trips', array( __CLASS__, 'page_trips' ), 'dashicons-palmtree', 26 );
		add_submenu_page( 'lgt-trips', 'Όλες οι εκδρομές', 'Όλες οι εκδρομές', $cap, 'lgt-trips', array( __CLASS__, 'page_trips' ) );
		add_submenu_page( 'lgt-trips', 'Νέα εκδρομή', 'Νέα εκδρομή', $cap, 'lgt-trip', array( __CLASS__, 'page_trip' ) );
		add_submenu_page( 'lgt-trips', 'Ρυθμίσεις', 'Ρυθμίσεις', $cap, 'lgt-settings', array( __CLASS__, 'page_settings' ) );
		add_submenu_page( 'lgt-trips', 'Αρχείο email', 'Αρχείο email', $cap, 'lgt-mail-log', array( __CLASS__, 'page_mail_log' ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( $hook, 'lgt-' ) ) {
			return;
		}
		wp_enqueue_style( 'lgt-app', LGT_ST_URL . 'assets/css/app.css', array(), LGT_ST_VERSION );
		wp_enqueue_style( 'lgt-admin', LGT_ST_URL . 'assets/css/admin.css', array( 'lgt-app' ), LGT_ST_VERSION );
		wp_enqueue_script( 'lgt-admin', LGT_ST_URL . 'assets/js/admin.js', array(), LGT_ST_VERSION, true );
		if ( isset( $_GET['page'] ) && 'lgt-trip' === $_GET['page'] && ! empty( $_GET['id'] ) && ( $_GET['tab'] ?? 'manage' ) === 'manage' ) {
			$trip = LGT_DB::get_trip( (int) $_GET['id'] );
			if ( $trip ) {
				wp_enqueue_script( 'lgt-app', LGT_ST_URL . 'assets/js/app.js', array(), LGT_ST_VERSION, true );
				wp_add_inline_script( 'lgt-app', 'window.LGT_APP = ' . wp_json_encode( LGT_Portal::app_config( $trip, 'admin' ) ) . ';', 'before' );
			}
		}
		if ( isset( $_GET['page'] ) && 'lgt-settings' === $_GET['page'] ) {
			wp_enqueue_media();
		}
	}

	private static function check_cap() {
		if ( ! LGT_Plugin::current_user_can_manage() ) {
			wp_die( 'Δεν έχετε δικαίωμα πρόσβασης.' );
		}
	}

	public static function notices() {
		if ( empty( $_GET['page'] ) || 0 !== strpos( (string) $_GET['page'], 'lgt-' ) || empty( $_GET['lgt_msg'] ) ) {
			return;
		}
		$msgs = array(
			'saved'      => array( 'success', 'Οι αλλαγές αποθηκεύτηκαν.' ),
			'link_sent'  => array( 'success', 'Η εκδρομή δημιουργήθηκε και ο σύνδεσμος στάλθηκε στο σχολείο. Τώρα περιμένετε το σχολείο να περάσει τα ονόματα. Θα λάβετε email με Excel/PDF όταν πατήσουν «Υποβολή».' ),
			'link_fail'  => array( 'error', 'Η εκδρομή δημιουργήθηκε αλλά το email με τον σύνδεσμο δεν στάλθηκε. Αντιγράψτε τον σύνδεσμο από την καρτέλα «Σύνοψη» ή ελέγξτε τις ρυθμίσεις email.' ),
			'deleted'    => array( 'success', 'Η εκδρομή διαγράφηκε.' ),
			'settings'   => array( 'success', 'Οι ρυθμίσεις αποθηκεύτηκαν.' ),
			'cron'       => array( 'success', 'Οι υπενθυμίσεις/ενημερώσεις εκτελέστηκαν.' ),
			'test_ok'    => array( 'success', 'Το δοκιμαστικό email στάλθηκε.' ),
			'test_fail'  => array( 'error', 'Η αποστολή δοκιμαστικού email απέτυχε. Ελέγξτε τις ρυθμίσεις email (SMTP) του WordPress.' ),
			'error'      => array( 'error', 'Παρουσιάστηκε σφάλμα.' ),
		);
		$k = sanitize_key( $_GET['lgt_msg'] );
		if ( isset( $msgs[ $k ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $msgs[ $k ][0] ), esc_html( $msgs[ $k ][1] ) );
		}
	}

	private static function redirect( $url, $msg ) {
		wp_safe_redirect( add_query_arg( 'lgt_msg', $msg, $url ) );
		exit;
	}

	public static function status_label( $status ) {
		$map = array(
			'draft'    => 'Πρόχειρο',
			'open'     => 'Ανοιχτή καταχώρηση',
			'closed'   => 'Κλειστή',
			'archived' => 'Αρχείο',
		);
		return $map[ $status ] ?? $status;
	}

	/** Human-readable progress for the list screen. */
	public static function progress_label( array $t, $count ) {
		if ( 'draft' === $t['status'] ) {
			return array( 'draft', 'Πρόχειρο – δεν έχει σταλεί σύνδεσμος' );
		}
		if ( 'archived' === $t['status'] ) {
			return array( 'archived', 'Αρχείο' );
		}
		if ( 'closed' === $t['status'] ) {
			return array( 'closed', 'Κλειστή – οριστική λίστα' );
		}
		if ( $t['submitted_at'] ) {
			$newer = $t['data_updated_at'] && strtotime( $t['data_updated_at'] ) > strtotime( $t['submitted_at'] );
			return array( 'submitted', $newer ? 'Υποβλήθηκε – νέες αλλαγές από τότε' : 'Υποβλήθηκε από το σχολείο ✓' );
		}
		if ( $count ) {
			return array( 'progress', 'Το σχολείο καταχωρεί (' . (int) $count . ' άτομα)' );
		}
		return array( 'waiting', 'Περιμένουμε το σχολείο' );
	}

	/* ------------------------------------------------------------------ */
	/* Trips list                                                         */
	/* ------------------------------------------------------------------ */

	public static function page_trips() {
		self::check_cap();
		global $wpdb;
		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$per    = 25;
		$filters = array( 'status' => $status, 'search' => $search, 'include_archived' => 'archived' === $status );
		$trips  = LGT_DB::list_trips( $filters, $per, ( $paged - 1 ) * $per );
		$total  = LGT_DB::count_trips( $filters );
		// Counts per trip in one query.
		$counts = array();
		if ( $trips ) {
			$ids  = implode( ',', array_map( 'intval', wp_list_pluck( $trips, 'id' ) ) );
			$rows = $wpdb->get_results( "SELECT trip_id, COUNT(*) AS n FROM " . LGT_DB::table( 'participants' ) . " WHERE status='active' AND trip_id IN ($ids) GROUP BY trip_id", ARRAY_A ); // phpcs:ignore
			foreach ( (array) $rows as $r ) {
				$counts[ (int) $r['trip_id'] ] = (int) $r['n'];
			}
		}
		$base = admin_url( 'admin.php?page=lgt-trips' );
		?>
		<div class="wrap lgt-wrap">
			<h1 class="wp-heading-inline">Σχολικές Εκδρομές</h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=lgt-trip' ) ); ?>" class="page-title-action">+ Νέα εκδρομή</a>
			<hr class="wp-header-end">
			<p class="lgt-muted" style="margin:6px 0 12px">1. Δημιουργείτε την εκδρομή → 2. Το σχολείο λαμβάνει σύνδεσμο και περνά τα ονόματα → 3. Λαμβάνετε Excel/PDF με email. Εδώ βλέπετε πού βρίσκεται κάθε σχολείο.</p>
			<ul class="subsubsub">
				<?php
				$tabs = array( '' => 'Ενεργές', 'open' => 'Ανοιχτές', 'closed' => 'Κλειστές', 'draft' => 'Πρόχειρα', 'archived' => 'Αρχείο' );
				$i = 0;
				foreach ( $tabs as $k => $label ) :
					$i++;
					?>
					<li><a href="<?php echo esc_url( add_query_arg( 'status', $k, $base ) ); ?>" class="<?php echo $status === $k ? 'current' : ''; ?>"><?php echo esc_html( $label ); ?></a><?php echo $i < count( $tabs ) ? ' | ' : ''; ?></li>
				<?php endforeach; ?>
			</ul>
			<form method="get" class="search-form" style="float:right;margin-bottom:8px;">
				<input type="hidden" name="page" value="lgt-trips">
				<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Αναζήτηση σχολείου / τίτλου">
				<button class="button">Αναζήτηση</button>
			</form>
			<table class="wp-list-table widefat fixed striped lgt-trips-table">
				<thead>
					<tr>
						<th style="width:30%">Εκδρομή / Σχολείο</th>
						<th>Ημερομηνίες</th>
						<th>Μεταφορά</th>
						<th>Άτομα</th>
						<th>Κατάσταση</th>
						<th>Τελευταία αλλαγή</th>
						<th style="width:220px">Ενέργειες</th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $trips ) : ?>
					<tr><td colspan="7">Δεν βρέθηκαν εκδρομές. <a href="<?php echo esc_url( admin_url( 'admin.php?page=lgt-trip' ) ); ?>">Δημιουργήστε την πρώτη.</a></td></tr>
				<?php endif; ?>
				<?php foreach ( $trips as $t ) : ?>
					<?php
					$edit   = admin_url( 'admin.php?page=lgt-trip&id=' . $t['id'] );
					$manage = $edit . '&tab=manage';
					$days   = $t['departure_date'] ? (int) floor( ( strtotime( $t['departure_date'] ) - strtotime( current_time( 'Y-m-d' ) ) ) / DAY_IN_SECONDS ) : null;
					?>
					<tr>
						<td>
							<strong><a href="<?php echo esc_url( $manage ); ?>"><?php echo esc_html( $t['title'] ? $t['title'] : '(χωρίς τίτλο)' ); ?></a></strong><br>
							<span class="lgt-muted"><?php echo esc_html( $t['school_name'] ); ?><?php echo $t['destination'] ? ' · ' . esc_html( $t['destination'] ) : ''; ?></span>
						</td>
						<td>
							<?php echo esc_html( LGT_Exporter::fmt_date( $t['departure_date'] ) ); ?><?php echo $t['return_date'] ? ' – ' . esc_html( LGT_Exporter::fmt_date( $t['return_date'] ) ) : ''; ?>
							<?php if ( null !== $days && $days >= 0 && 'archived' !== $t['status'] ) : ?>
								<br><span class="lgt-badge <?php echo $days <= 7 ? 'lgt-badge-warn' : 'lgt-badge-soft'; ?>">σε <?php echo (int) $days; ?> ημ.</span>
							<?php endif; ?>
						</td>
						<td>
							<?php echo $t['has_hotel'] ? '<span class="lgt-chip" title="Ξενοδοχείο">🏨</span>' : ''; ?>
							<?php echo $t['has_ferry'] ? '<span class="lgt-chip" title="Πλοίο">⛴</span>' : ''; ?>
							<?php echo $t['has_flight'] ? '<span class="lgt-chip" title="Αεροπλάνο">✈</span>' : ''; ?>
						</td>
						<td><?php echo (int) ( $counts[ $t['id'] ] ?? 0 ); ?></td>
						<?php list( $pcls, $plabel ) = self::progress_label( $t, $counts[ $t['id'] ] ?? 0 ); ?>
						<td><span class="lgt-status lgt-status-<?php echo esc_attr( $pcls ); ?>"><?php echo esc_html( $plabel ); ?></span></td>
						<td class="lgt-muted"><?php echo $t['data_updated_at'] ? esc_html( date_i18n( 'd/m/Y H:i', strtotime( $t['data_updated_at'] ) ) ) : '—'; ?><?php echo $t['submitted_at'] ? '<br><small>Υποβολή: ' . esc_html( date_i18n( 'd/m/Y H:i', strtotime( $t['submitted_at'] ) ) ) . '</small>' : ''; ?></td>
						<td>
							<a class="button button-small" href="<?php echo esc_url( $manage ); ?>">Άνοιγμα</a>
							<?php if ( ! empty( $counts[ $t['id'] ] ) ) : ?>
								<a class="button button-small" title="Λήψη Excel" href="<?php echo esc_url( add_query_arg( array( 'action' => 'lgt_download', 'trip' => $t['id'], 'format' => 'xlsx', 'list' => 'all', '_wpnonce' => wp_create_nonce( 'lgt_download_' . $t['id'] ) ), admin_url( 'admin-post.php' ) ) ); ?>">Excel</a>
								<a class="button button-small" title="Λήψη PDF" href="<?php echo esc_url( add_query_arg( array( 'action' => 'lgt_download', 'trip' => $t['id'], 'format' => 'pdf', 'list' => 'all', '_wpnonce' => wp_create_nonce( 'lgt_download_' . $t['id'] ) ), admin_url( 'admin-post.php' ) ) ); ?>">PDF</a>
							<?php endif; ?>
							<?php if ( $t['token'] && 'open' === $t['status'] ) : ?>
								<button type="button" class="button button-small lgt-copy" data-copy="<?php echo esc_attr( LGT_Portal::url( $t ) ); ?>" title="Αντιγραφή συνδέσμου">🔗</button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			$pages = (int) ceil( $total / $per );
			if ( $pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links( array( // phpcs:ignore
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $paged,
					'total'   => $pages,
				) ) . '</div></div>';
			}
			?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Trip form / manage                                                 */
	/* ------------------------------------------------------------------ */

	public static function page_trip() {
		self::check_cap();
		$id   = (int) ( $_GET['id'] ?? 0 );
		$trip = $id ? LGT_DB::get_trip( $id ) : null;
		if ( $id && ! $trip ) {
			echo '<div class="wrap"><h1>Η εκδρομή δεν βρέθηκε.</h1></div>';
			return;
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : ( $trip ? 'manage' : 'details' );
		if ( ! $trip ) {
			$tab = 'details';
		}
		$base = $trip ? admin_url( 'admin.php?page=lgt-trip&id=' . $trip['id'] ) : '';
		?>
		<div class="wrap lgt-wrap">
			<h1 class="wp-heading-inline"><?php echo $trip ? esc_html( $trip['title'] ) : 'Νέα εκδρομή'; ?></h1>
			<?php if ( $trip ) : ?>
				<span class="lgt-status lgt-status-<?php echo esc_attr( $trip['status'] ); ?>" style="margin-left:8px;vertical-align:middle"><?php echo esc_html( self::status_label( $trip['status'] ) ); ?></span>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=lgt-trip' ) ); ?>" class="page-title-action">Νέα εκδρομή</a>
			<?php endif; ?>
			<hr class="wp-header-end">
			<?php if ( $trip ) : ?>
				<nav class="nav-tab-wrapper">
					<a href="<?php echo esc_url( $base . '&tab=manage' ); ?>" class="nav-tab <?php echo 'manage' === $tab ? 'nav-tab-active' : ''; ?>">Λίστες & κατανομή</a>
					<a href="<?php echo esc_url( $base . '&tab=details' ); ?>" class="nav-tab <?php echo 'details' === $tab ? 'nav-tab-active' : ''; ?>">Στοιχεία εκδρομής</a>
					<a href="<?php echo esc_url( $base . '&tab=history' ); ?>" class="nav-tab <?php echo 'history' === $tab ? 'nav-tab-active' : ''; ?>">Ιστορικό</a>
				</nav>
			<?php endif; ?>
			<?php
			if ( 'manage' === $tab && $trip ) {
				echo '<div id="lgt-app" class="lgt-app lgt-app-admin"><div class="lgt-loading">Φόρτωση…</div></div>';
			} elseif ( 'history' === $tab && $trip ) {
				self::render_history( $trip );
			} else {
				self::render_trip_form( $trip );
			}
			?>
		</div>
		<?php
	}

	private static function render_types_editor( $name, array $types ) {
		?>
		<div class="lgt-types-editor" data-name="<?php echo esc_attr( $name ); ?>">
			<table class="widefat lgt-types-table">
				<thead><tr><th>Κωδικός</th><th>Περιγραφή</th><th>Άτομα</th><th>Διαθέσιμα (προαιρ.)</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $types as $i => $t ) : ?>
					<tr>
						<td><input type="text" name="<?php echo esc_attr( $name ); ?>[<?php echo (int) $i; ?>][code]" value="<?php echo esc_attr( $t['code'] ); ?>" class="small-text" style="width:70px"></td>
						<td><input type="text" name="<?php echo esc_attr( $name ); ?>[<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( $t['label'] ); ?>"></td>
						<td><input type="number" min="1" max="12" name="<?php echo esc_attr( $name ); ?>[<?php echo (int) $i; ?>][capacity]" value="<?php echo (int) $t['capacity']; ?>" class="small-text"></td>
						<td><input type="number" min="0" name="<?php echo esc_attr( $name ); ?>[<?php echo (int) $i; ?>][quota]" value="<?php echo isset( $t['quota'] ) && null !== $t['quota'] ? (int) $t['quota'] : ''; ?>" class="small-text" placeholder="—"></td>
						<td><button type="button" class="button-link lgt-row-remove" title="Αφαίρεση">✕</button></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<button type="button" class="button lgt-row-add">+ Τύπος</button>
		</div>
		<?php
	}

	private static function render_trip_form( $trip ) {
		$t = $trip ? $trip : array_merge( LGT_DB::trip_defaults(), array( 'id' => 0, 'meta' => array() ) );
		if ( $trip ) {
			$t['room_types']  = LGT_Settings::sanitize_types( $trip['room_types'], LGT_Settings::default_room_types() );
			$t['cabin_types'] = LGT_Settings::sanitize_types( $trip['cabin_types'], LGT_Settings::default_cabin_types() );
		} else {
			$t['room_types']  = LGT_Settings::sanitize_types( LGT_Settings::get( 'default_room_types' ), LGT_Settings::default_room_types() );
			$t['cabin_types'] = LGT_Settings::sanitize_types( LGT_Settings::get( 'default_cabin_types' ), LGT_Settings::default_cabin_types() );
		}
		$is_new = ! $trip;
		?>
		<?php if ( $is_new ) : ?>
			<div class="lgt-alert lgt-alert-info" style="max-width:900px;margin-top:12px">
				<strong>Πώς δουλεύει:</strong> συμπληρώνετε τα βασικά, πατάτε «Δημιουργία & αποστολή» και το σχολείο λαμβάνει με email τον σύνδεσμο.
				Το σχολείο περνά τα ονόματα, τα δωμάτια και τις καμπίνες. Εσείς λαμβάνετε αυτόματα Excel &amp; PDF όταν πατήσουν «Υποβολή».
			</div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lgt-form lgt-form-simple">
			<?php wp_nonce_field( 'lgt_save_trip' ); ?>
			<input type="hidden" name="action" value="lgt_save_trip">
			<input type="hidden" name="trip_id" value="<?php echo (int) $t['id']; ?>">
			<div class="lgt-card" style="max-width:900px">
				<h2>Βασικά στοιχεία</h2>
				<div class="lgt-grid2">
					<p><label>Σχολείο *<br><input type="text" name="school_name" required class="regular-text" value="<?php echo esc_attr( $t['school_name'] ); ?>" placeholder="π.χ. 3ο ΓΕΛ Αθηνών"></label></p>
					<p><label>Email σχολείου / υπευθύνου *<br><input type="email" name="school_email" required class="regular-text" value="<?php echo esc_attr( $t['school_email'] ); ?>" placeholder="εδώ στέλνεται ο σύνδεσμος"></label></p>
					<p><label>Προορισμός *<br><input type="text" name="destination" required class="regular-text" value="<?php echo esc_attr( $t['destination'] ); ?>" placeholder="π.χ. Ρόδος"></label></p>
					<p><label>Ξενοδοχείο<br><input type="text" name="hotel_name" class="regular-text" value="<?php echo esc_attr( $t['hotel_name'] ); ?>"></label></p>
					<p><label>Αναχώρηση *<br><input type="date" name="departure_date" required value="<?php echo esc_attr( (string) $t['departure_date'] ); ?>"></label></p>
					<p><label>Επιστροφή<br><input type="date" name="return_date" value="<?php echo esc_attr( (string) $t['return_date'] ); ?>"></label></p>
				</div>
				<p><strong>Τι περιλαμβάνει η εκδρομή:</strong></p>
				<div class="lgt-grid3">
					<label class="lgt-check-card"><input type="checkbox" name="has_hotel" value="1" <?php checked( $t['has_hotel'] ); ?>> 🏨 Ξενοδοχείο<br><small>rooming list</small></label>
					<label class="lgt-check-card"><input type="checkbox" name="has_ferry" value="1" <?php checked( $t['has_ferry'] ); ?>> ⛴ Πλοίο<br><small>καμπίνες + λίστα επιβατών</small></label>
					<label class="lgt-check-card"><input type="checkbox" name="has_flight" value="1" <?php checked( $t['has_flight'] ); ?>> ✈ Αεροπλάνο<br><small>λίστα με ημ. γέννησης &amp; έγγραφα</small></label>
				</div>
				<div class="lgt-grid2">
					<p><label>Ακτοπλοϊκή εταιρεία / δρομολόγιο<br><input type="text" name="ferry_company" class="regular-text" value="<?php echo esc_attr( $t['ferry_company'] ); ?>" placeholder="π.χ. Blue Star – Πειραιάς → Ρόδος"></label></p>
					<p><label>Αεροπορική εταιρεία / πτήσεις<br><input type="text" name="airline" class="regular-text" value="<?php echo esc_attr( $t['airline'] ); ?>" placeholder="π.χ. Aegean A3 302"></label></p>
				</div>
				<?php if ( $is_new ) : ?>
					<p style="margin-top:14px"><label><input type="checkbox" name="send_link" value="1" checked> <strong>Αποστολή του συνδέσμου στο σχολείο με email αμέσως</strong></label></p>
				<?php endif; ?>
				<details style="margin-top:10px">
					<summary style="cursor:pointer;color:#0f3b66;font-weight:600">Προαιρετικά (τίτλος, υπεύθυνος, σημειώσεις, τύποι δωματίων, κωδικός πρόσβασης)</summary>
					<div class="lgt-grid2" style="margin-top:10px">
						<p><label>Τίτλος εκδρομής<br><input type="text" name="title" class="regular-text" value="<?php echo esc_attr( $t['title'] ); ?>" placeholder="αν μείνει κενό συμπληρώνεται αυτόματα"></label></p>
						<p><label>Υπεύθυνος καθηγητής<br><input type="text" name="contact_name" class="regular-text" value="<?php echo esc_attr( $t['contact_name'] ); ?>"></label></p>
						<p><label>Τηλέφωνο σχολείου<br><input type="text" name="school_phone" class="regular-text" value="<?php echo esc_attr( $t['school_phone'] ); ?>"></label></p>
						<p><label>Κωδικός πρόσβασης συνδέσμου<br><input type="text" name="access_code" class="regular-text" value="<?php echo esc_attr( $t['access_code'] ); ?>" placeholder="κενό = χωρίς κωδικό"></label></p>
						<?php if ( ! $is_new ) : ?>
						<p><label>Κατάσταση<br>
							<select name="status">
								<?php foreach ( array( 'draft', 'open', 'closed', 'archived' ) as $s ) : ?>
									<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $t['status'], $s ); ?>><?php echo esc_html( self::status_label( $s ) ); ?></option>
								<?php endforeach; ?>
							</select></label></p>
						<?php endif; ?>
					</div>
					<p><label>Επιπλέον emails για υπενθυμίσεις (συνοδοί, ξενοδοχείο…)<br><textarea name="extra_emails" rows="2" class="large-text"><?php echo esc_textarea( (string) $t['extra_emails'] ); ?></textarea></label></p>
					<p><label>Μήνυμα προς το σχολείο (φαίνεται στην πλατφόρμα και στα emails)<br><textarea name="notes_school" rows="3" class="large-text"><?php echo esc_textarea( (string) $t['notes_school'] ); ?></textarea></label></p>
					<p><label>Σημειώσεις ξενοδοχείου προς το σχολείο<br><textarea name="hotel_notes" rows="2" class="large-text"><?php echo esc_textarea( (string) $t['hotel_notes'] ); ?></textarea></label></p>
					<p><label>Σημειώσεις πλοίου προς το σχολείο<br><textarea name="ferry_notes" rows="2" class="large-text"><?php echo esc_textarea( (string) $t['ferry_notes'] ); ?></textarea></label></p>
					<p><label>Σημειώσεις πτήσης προς το σχολείο<br><textarea name="flight_notes" rows="2" class="large-text"><?php echo esc_textarea( (string) $t['flight_notes'] ); ?></textarea></label></p>
					<p><label>Εσωτερικές σημειώσεις (μόνο γραφείο)<br><textarea name="notes_internal" rows="2" class="large-text"><?php echo esc_textarea( (string) $t['notes_internal'] ); ?></textarea></label></p>
					<h3>Τύποι δωματίων που διαθέτει το ξενοδοχείο</h3>
					<?php self::render_types_editor( 'room_types', $t['room_types'] ); ?>
					<h3>Τύποι καμπινών</h3>
					<?php self::render_types_editor( 'cabin_types', $t['cabin_types'] ); ?>
				</details>
			</div>
			<p class="submit">
				<button type="submit" class="button button-primary button-large"><?php echo $is_new ? '✔ Δημιουργία & αποστολή στο σχολείο' : 'Αποθήκευση'; ?></button>
				<?php if ( $trip ) : ?>
					<a class="button button-large" href="<?php echo esc_url( admin_url( 'admin.php?page=lgt-trip&id=' . $trip['id'] . '&tab=manage' ) ); ?>">Μετάβαση στις λίστες →</a>
					<a class="button button-link-delete lgt-confirm" style="margin-left:20px" data-confirm="Οριστική διαγραφή της εκδρομής και όλων των στοιχείων;" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lgt_delete_trip&trip_id=' . $trip['id'] ), 'lgt_delete_trip_' . $trip['id'] ) ); ?>">Διαγραφή εκδρομής</a>
				<?php endif; ?>
			</p>
		</form>
		<?php
	}

	private static function render_history( array $trip ) {
		$activity = LGT_DB::get_activity( $trip['id'], 300 );
		$mail     = LGT_DB::get_mail_log( 100, $trip['id'] );
		$labels   = array(
			'trip_update'        => 'Αλλαγή στοιχείων',
			'participant_add'    => 'Προσθήκη ατόμου',
			'participant_update' => 'Αλλαγή ατόμου',
			'participant_delete' => 'Διαγραφή ατόμου',
			'participant_import' => 'Μαζική εισαγωγή',
			'room_add'           => 'Προσθήκη δωματίου/καμπίνας',
			'room_delete'        => 'Διαγραφή δωματίου/καμπίνας',
			'auto_allocate'      => 'Αυτόματη κατανομή',
			'copy_allocation'    => 'Αντιγραφή κατανομής',
			'clear_allocation'   => 'Καθαρισμός κατανομής',
			'submit'             => 'Υποβολή στο γραφείο',
			'send_now'           => 'Αποστολή λιστών',
			'send_link'          => 'Αποστολή συνδέσμου',
			'download'           => 'Λήψη αρχείου',
			'reminder'           => 'Υπενθύμιση',
		);
		?>
		<div class="lgt-form-grid">
			<div class="lgt-card">
				<h2>Ενέργειες</h2>
				<table class="widefat striped">
					<thead><tr><th>Πότε</th><th>Ποιος</th><th>Ενέργεια</th><th>Λεπτομέρειες</th></tr></thead>
					<tbody>
					<?php if ( ! $activity ) : ?><tr><td colspan="4">Καμία καταγραφή.</td></tr><?php endif; ?>
					<?php foreach ( (array) $activity as $a ) : ?>
						<tr>
							<td><?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $a['created_at'] ) ) ); ?></td>
							<td><?php echo esc_html( 'school' === $a['actor'] ? 'Σχολείο' : ( 'admin' === $a['actor'] ? 'Γραφείο' : 'Σύστημα' ) ); ?></td>
							<td><?php echo esc_html( $labels[ $a['action'] ] ?? $a['action'] ); ?></td>
							<td class="lgt-muted"><?php echo esc_html( mb_substr( (string) $a['details'], 0, 200 ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<div class="lgt-card">
				<h2>Emails</h2>
				<table class="widefat striped">
					<thead><tr><th>Πότε</th><th>Τύπος</th><th>Προς</th><th>Θέμα</th><th>OK</th></tr></thead>
					<tbody>
					<?php if ( ! $mail ) : ?><tr><td colspan="5">Δεν έχουν σταλεί emails.</td></tr><?php endif; ?>
					<?php foreach ( (array) $mail as $m ) : ?>
						<tr>
							<td><?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $m['created_at'] ) ) ); ?></td>
							<td><?php echo esc_html( $m['kind'] ); ?></td>
							<td class="lgt-muted"><?php echo esc_html( $m['recipients'] ); ?></td>
							<td><?php echo esc_html( $m['subject'] ); ?><?php echo $m['attachments'] ? '<br><small class="lgt-muted">📎 ' . esc_html( $m['attachments'] ) . '</small>' : ''; ?></td>
							<td><?php echo $m['success'] ? '✅' : '❌ <small>' . esc_html( $m['error'] ) . '</small>'; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	public static function handle_save_trip() {
		self::check_cap();
		check_admin_referer( 'lgt_save_trip' );
		$id   = (int) ( $_POST['trip_id'] ?? 0 );
		$post = wp_unslash( $_POST );
		$data = array(
			'title'          => sanitize_text_field( $post['title'] ?? '' ),
			'school_name'    => sanitize_text_field( $post['school_name'] ?? '' ),
			'destination'    => sanitize_text_field( $post['destination'] ?? '' ),
			'departure_date' => LGT_DB::clean_date( $post['departure_date'] ?? '' ),
			'return_date'    => LGT_DB::clean_date( $post['return_date'] ?? '' ),
			'status'         => in_array( $post['status'] ?? 'open', array( 'draft', 'open', 'closed', 'archived' ), true ) ? ( $post['status'] ?? 'open' ) : 'open',
			'contact_name'   => sanitize_text_field( $post['contact_name'] ?? '' ),
			'school_email'   => sanitize_email( $post['school_email'] ?? '' ),
			'school_phone'   => sanitize_text_field( $post['school_phone'] ?? '' ),
			'extra_emails'   => sanitize_textarea_field( $post['extra_emails'] ?? '' ),
			'access_code'    => sanitize_text_field( $post['access_code'] ?? '' ),
			'has_hotel'      => empty( $post['has_hotel'] ) ? 0 : 1,
			'has_ferry'      => empty( $post['has_ferry'] ) ? 0 : 1,
			'has_flight'     => empty( $post['has_flight'] ) ? 0 : 1,
			'hotel_name'     => sanitize_text_field( $post['hotel_name'] ?? '' ),
			'hotel_notes'    => sanitize_textarea_field( $post['hotel_notes'] ?? '' ),
			'ferry_company'  => sanitize_text_field( $post['ferry_company'] ?? '' ),
			'ferry_notes'    => sanitize_textarea_field( $post['ferry_notes'] ?? '' ),
			'airline'        => sanitize_text_field( $post['airline'] ?? '' ),
			'flight_notes'   => sanitize_textarea_field( $post['flight_notes'] ?? '' ),
			'notes_school'   => sanitize_textarea_field( $post['notes_school'] ?? '' ),
			'notes_internal' => sanitize_textarea_field( $post['notes_internal'] ?? '' ),
			'room_types'     => LGT_Settings::sanitize_types( $post['room_types'] ?? array(), LGT_Settings::default_room_types() ),
			'cabin_types'    => LGT_Settings::sanitize_types( $post['cabin_types'] ?? array(), LGT_Settings::default_cabin_types() ),
		);
		if ( '' === $data['title'] ) {
			$data['title'] = trim( 'Εκδρομή ' . $data['destination'] . ( $data['departure_date'] ? ' ' . LGT_Exporter::fmt_date( $data['departure_date'] ) : '' ) );
		}
		if ( ! $data['has_hotel'] && ! $data['has_ferry'] && ! $data['has_flight'] ) {
			$data['has_hotel'] = 1;
		}
		if ( 'open' === $data['status'] ) {
			$existing = $id ? LGT_DB::get_trip( $id ) : null;
			if ( ! $existing || ! $existing['token'] ) {
				$data['token'] = LGT_DB::generate_token();
			}
		}
		$msg = 'saved';
		if ( $id ) {
			LGT_DB::update_trip( $id, $data );
			LGT_DB::log( $id, 'admin', 'trip_update', 'Φόρμα στοιχείων' );
		} else {
			$id = LGT_DB::insert_trip( $data );
			LGT_DB::log( $id, 'admin', 'trip_create' );
			if ( ! empty( $post['send_link'] ) && $data['school_email'] ) {
				$trip = LGT_DB::get_trip( $id );
				$ok   = LGT_Mailer::send_link( $trip );
				LGT_DB::log( $id, 'admin', 'send_link', $ok ? $trip['school_email'] : 'Αποτυχία' );
				$msg = $ok ? 'link_sent' : 'link_fail';
			}
		}
		self::redirect( admin_url( 'admin.php?page=lgt-trip&id=' . $id . '&tab=' . ( isset( $_POST['trip_id'] ) && (int) $_POST['trip_id'] ? 'details' : 'manage' ) ), $msg );
	}

	public static function handle_delete_trip() {
		self::check_cap();
		$id = (int) ( $_GET['trip_id'] ?? 0 );
		check_admin_referer( 'lgt_delete_trip_' . $id );
		LGT_DB::delete_trip( $id );
		self::redirect( admin_url( 'admin.php?page=lgt-trips' ), 'deleted' );
	}

	/**
	 * admin-post.php?action=lgt_download&trip=ID&format=xlsx|pdf&list=all|rooming|cabins|ferry|flight|full
	 */
	public static function handle_download() {
		self::check_cap();
		$id = (int) ( $_GET['trip'] ?? 0 );
		check_admin_referer( 'lgt_download_' . $id );
		$format = ( $_GET['format'] ?? 'xlsx' ) === 'pdf' ? 'pdf' : 'xlsx';
		$list   = sanitize_key( $_GET['list'] ?? 'all' );
		if ( ! in_array( $list, LGT_Exporter::LISTS, true ) ) {
			$list = 'all';
		}
		$path = 'pdf' === $format ? LGT_Exporter::build_pdf( $id, $list ) : LGT_Exporter::build_xlsx( $id, $list );
		if ( ! $path ) {
			wp_die( 'Η εκδρομή δεν βρέθηκε.' );
		}
		LGT_DB::update_trip_meta( $id, array( 'last_export_at' => current_time( 'mysql' ) ) );
		LGT_Exporter::stream( $path );
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                           */
	/* ------------------------------------------------------------------ */

	public static function page_settings() {
		self::check_cap();
		$s = LGT_Settings::all();
		$room_types  = LGT_Settings::sanitize_types( $s['default_room_types'], LGT_Settings::default_room_types() );
		$cabin_types = LGT_Settings::sanitize_types( $s['default_cabin_types'], LGT_Settings::default_cabin_types() );
		$next        = wp_next_scheduled( LGT_Cron::HOOK );
		?>
		<div class="wrap lgt-wrap">
			<h1>Ρυθμίσεις – Σχολικές Εκδρομές</h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lgt-form">
				<?php wp_nonce_field( 'lgt_save_settings' ); ?>
				<input type="hidden" name="action" value="lgt_save_settings">
				<div class="lgt-form-grid">
					<div class="lgt-card">
						<h2>Παραλήπτες & αποστολή</h2>
						<p><label>Emails γραφείου (λαμβάνουν Excel/PDF, υποβολές, υπενθυμίσεις)<br><textarea name="office_emails" rows="3" class="large-text" placeholder="ένα ανά γραμμή"><?php echo esc_textarea( $s['office_emails'] ); ?></textarea></label></p>
						<p><label>Όνομα αποστολέα<br><input type="text" name="from_name" class="regular-text" value="<?php echo esc_attr( $s['from_name'] ); ?>"></label></p>
						<p><label>Email αποστολέα<br><input type="email" name="from_email" class="regular-text" value="<?php echo esc_attr( $s['from_email'] ); ?>" placeholder="noreply@legrandtravel.gr"></label><br><span class="description">Προτείνεται SMTP plugin για αξιόπιστη παράδοση.</span></p>
						<p><label><input type="checkbox" name="notify_on_submit" value="1" <?php checked( $s['notify_on_submit'] ); ?>> Ειδοποίηση γραφείου όταν το σχολείο πατά «Υποβολή»</label></p>
						<p><label><input type="checkbox" name="attach_xlsx" value="1" <?php checked( $s['attach_xlsx'] ); ?>> Επισύναψη Excel</label> &nbsp; <label><input type="checkbox" name="attach_pdf" value="1" <?php checked( $s['attach_pdf'] ); ?>> Επισύναψη PDF</label></p>
						<p><label><input type="checkbox" name="daily_digest" value="1" <?php checked( $s['daily_digest'] ); ?>> Ημερήσια αυτόματη αποστολή ενημερωμένων αρχείων όταν ένα σχολείο κάνει αλλαγές</label></p>
						<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lgt_test_email' ), 'lgt_test_email' ) ); ?>">Αποστολή δοκιμαστικού email</a></p>
					</div>
					<div class="lgt-card">
						<h2>Υπενθυμίσεις</h2>
						<p><label>Ημέρες πριν την αναχώρηση<br><input type="text" name="reminder_days" class="regular-text" value="<?php echo esc_attr( $s['reminder_days'] ); ?>" placeholder="30,14,7,3,1"></label><br><span class="description">Χωρισμένες με κόμμα. Στέλνεται μία υπενθύμιση για κάθε όριο.</span></p>
						<p><label>Ώρα αποστολής<br><select name="reminder_hour"><?php for ( $h = 0; $h < 24; $h++ ) : ?><option value="<?php echo (int) $h; ?>" <?php selected( (int) $s['reminder_hour'], $h ); ?>><?php echo sprintf( '%02d:00', $h ); ?></option><?php endfor; ?></select></label></p>
						<p><label><input type="checkbox" name="reminder_to_school" value="1" <?php checked( $s['reminder_to_school'] ); ?>> Υπενθύμιση και στο σχολείο</label></p>
						<p><label><input type="checkbox" name="reminder_to_extra" value="1" <?php checked( $s['reminder_to_extra'] ); ?>> Υπενθύμιση και στα επιπλέον emails της εκδρομής (συνοδοί, ξενοδοχείο…)</label></p>
						<p><label><input type="checkbox" name="reminder_attachments" value="1" <?php checked( $s['reminder_attachments'] ); ?>> Επισύναψη Excel/PDF στην υπενθύμιση του γραφείου</label></p>
						<p class="description">Επόμενη αυτόματη εκτέλεση: <?php echo $next ? esc_html( wp_date( 'd/m/Y H:i', $next ) ) : '—'; ?>. <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lgt_run_cron' ), 'lgt_run_cron' ) ); ?>">Εκτέλεση τώρα</a></p>
					</div>
					<div class="lgt-card">
						<h2>Εταιρεία & έγγραφα</h2>
						<p><label>Επωνυμία<br><input type="text" name="company_name" class="regular-text" value="<?php echo esc_attr( $s['company_name'] ); ?>"></label></p>
						<p><label>Τηλέφωνο<br><input type="text" name="company_phone" class="regular-text" value="<?php echo esc_attr( $s['company_phone'] ); ?>"></label></p>
						<p><label>Email επικοινωνίας (Reply-To)<br><input type="email" name="company_email" class="regular-text" value="<?php echo esc_attr( $s['company_email'] ); ?>"></label></p>
						<p><label>Λογότυπο για PDF (JPG)<br><input type="text" name="company_logo" id="lgt-logo" class="regular-text" value="<?php echo esc_attr( $s['company_logo'] ); ?>"> <button type="button" class="button" id="lgt-logo-pick">Επιλογή</button></label></p>
						<p><label>Προσανατολισμός PDF rooming list<br><select name="pdf_orientation"><option value="P" <?php selected( $s['pdf_orientation'], 'P' ); ?>>Κατακόρυφος</option><option value="L" <?php selected( $s['pdf_orientation'], 'L' ); ?>>Οριζόντιος</option></select></label></p>
						<p><label>Προεπιλεγμένη εθνικότητα<br><input type="text" name="default_nationality" class="small-text" value="<?php echo esc_attr( $s['default_nationality'] ); ?>"></label></p>
						<p><label><input type="checkbox" name="ferry_doc_required" value="1" <?php checked( $s['ferry_doc_required'] ); ?>> Στα ακτοπλοϊκά απαιτείται αριθμός ταυτότητας/διαβατηρίου</label></p>
					</div>
					<div class="lgt-card">
						<h2>Πλατφόρμα σχολείων</h2>
						<p><label>Slug συνδέσμου<br><code><?php echo esc_html( home_url( '/' ) ); ?></code><input type="text" name="portal_slug" class="regular-text" value="<?php echo esc_attr( $s['portal_slug'] ); ?>"><code>/xxxxxxxx/</code></label></p>
						<h3>Προεπιλεγμένοι τύποι δωματίων (νέες εκδρομές)</h3>
						<?php self::render_types_editor( 'default_room_types', $room_types ); ?>
						<h3>Προεπιλεγμένοι τύποι καμπινών</h3>
						<?php self::render_types_editor( 'default_cabin_types', $cabin_types ); ?>
						<hr>
						<p><label><input type="checkbox" name="delete_on_uninstall" value="1" <?php checked( $s['delete_on_uninstall'] ); ?>> Διαγραφή όλων των δεδομένων κατά την απεγκατάσταση</label></p>
					</div>
				</div>
				<p class="submit"><button type="submit" class="button button-primary button-large">Αποθήκευση ρυθμίσεων</button></p>
			</form>
		</div>
		<?php
	}

	public static function handle_save_settings() {
		self::check_cap();
		check_admin_referer( 'lgt_save_settings' );
		$p    = wp_unslash( $_POST );
		$data = array(
			'office_emails'        => implode( "\n", LGT_Settings::parse_emails( $p['office_emails'] ?? '' ) ),
			'from_name'            => sanitize_text_field( $p['from_name'] ?? '' ),
			'from_email'           => sanitize_email( $p['from_email'] ?? '' ),
			'notify_on_submit'     => empty( $p['notify_on_submit'] ) ? 0 : 1,
			'attach_xlsx'          => empty( $p['attach_xlsx'] ) ? 0 : 1,
			'attach_pdf'           => empty( $p['attach_pdf'] ) ? 0 : 1,
			'daily_digest'         => empty( $p['daily_digest'] ) ? 0 : 1,
			'reminder_days'        => sanitize_text_field( $p['reminder_days'] ?? '' ),
			'reminder_hour'        => max( 0, min( 23, (int) ( $p['reminder_hour'] ?? 8 ) ) ),
			'reminder_to_school'   => empty( $p['reminder_to_school'] ) ? 0 : 1,
			'reminder_to_extra'    => empty( $p['reminder_to_extra'] ) ? 0 : 1,
			'reminder_attachments' => empty( $p['reminder_attachments'] ) ? 0 : 1,
			'company_name'         => sanitize_text_field( $p['company_name'] ?? '' ),
			'company_phone'        => sanitize_text_field( $p['company_phone'] ?? '' ),
			'company_email'        => sanitize_email( $p['company_email'] ?? '' ),
			'company_logo'         => esc_url_raw( $p['company_logo'] ?? '' ),
			'pdf_orientation'      => ( $p['pdf_orientation'] ?? 'P' ) === 'L' ? 'L' : 'P',
			'default_nationality'  => strtoupper( sanitize_text_field( $p['default_nationality'] ?? 'GR' ) ),
			'ferry_doc_required'   => empty( $p['ferry_doc_required'] ) ? 0 : 1,
			'portal_slug'          => sanitize_title( $p['portal_slug'] ?? 'school-trip' ),
			'default_room_types'   => wp_json_encode( LGT_Settings::sanitize_types( $p['default_room_types'] ?? array(), LGT_Settings::default_room_types() ) ),
			'default_cabin_types'  => wp_json_encode( LGT_Settings::sanitize_types( $p['default_cabin_types'] ?? array(), LGT_Settings::default_cabin_types() ) ),
			'delete_on_uninstall'  => empty( $p['delete_on_uninstall'] ) ? 0 : 1,
		);
		$old_slug = LGT_Settings::get( 'portal_slug' );
		LGT_Settings::update( $data );
		if ( $old_slug !== $data['portal_slug'] ) {
			LGT_Portal::register_rewrites();
			flush_rewrite_rules();
		}
		self::redirect( admin_url( 'admin.php?page=lgt-settings' ), 'settings' );
	}

	public static function handle_run_cron() {
		self::check_cap();
		check_admin_referer( 'lgt_run_cron' );
		delete_option( 'lgt_st_last_cron' );
		LGT_Cron::run_daily();
		self::redirect( admin_url( 'admin.php?page=lgt-settings' ), 'cron' );
	}

	public static function handle_test_email() {
		self::check_cap();
		check_admin_referer( 'lgt_test_email' );
		$ok = LGT_Mailer::send( 0, 'test', LGT_Settings::office_emails(), '[Σχολικές Εκδρομές] Δοκιμαστικό email', '<p>Το email λειτουργεί σωστά. Αυτό είναι ένα δοκιμαστικό μήνυμα από το plugin Σχολικών Εκδρομών.</p>' );
		self::redirect( admin_url( 'admin.php?page=lgt-settings' ), $ok ? 'test_ok' : 'test_fail' );
	}

	/* ------------------------------------------------------------------ */
	/* Mail log                                                           */
	/* ------------------------------------------------------------------ */

	public static function page_mail_log() {
		self::check_cap();
		$rows = LGT_DB::get_mail_log( 300 );
		?>
		<div class="wrap lgt-wrap">
			<h1>Αρχείο email</h1>
			<table class="wp-list-table widefat fixed striped">
				<thead><tr><th style="width:130px">Πότε</th><th style="width:110px">Τύπος</th><th>Εκδρομή</th><th>Προς</th><th>Θέμα</th><th style="width:60px">OK</th></tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?><tr><td colspan="6">Δεν έχουν σταλεί emails ακόμη.</td></tr><?php endif; ?>
				<?php foreach ( (array) $rows as $m ) : ?>
					<tr>
						<td><?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $m['created_at'] ) ) ); ?></td>
						<td><?php echo esc_html( $m['kind'] ); ?></td>
						<td><?php echo $m['trip_id'] ? '<a href="' . esc_url( admin_url( 'admin.php?page=lgt-trip&id=' . (int) $m['trip_id'] . '&tab=history' ) ) . '">#' . (int) $m['trip_id'] . '</a>' : '—'; ?></td>
						<td class="lgt-muted"><?php echo esc_html( $m['recipients'] ); ?></td>
						<td><?php echo esc_html( $m['subject'] ); ?><?php echo $m['attachments'] ? '<br><small class="lgt-muted">📎 ' . esc_html( $m['attachments'] ) . '</small>' : ''; ?></td>
						<td><?php echo $m['success'] ? '✅' : '❌'; ?><?php echo $m['error'] ? '<br><small>' . esc_html( $m['error'] ) . '</small>' : ''; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
