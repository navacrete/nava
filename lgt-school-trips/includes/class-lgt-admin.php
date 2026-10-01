<?php
/**
 * WP admin: trips list, new/edit trip, online view of the school's forms, settings, logs.
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
		$cap = current_user_can( LGT_ST_CAP ) ? LGT_ST_CAP : 'manage_options';
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
		wp_enqueue_style( 'lgt-admin', LGT_ST_URL . 'assets/css/admin.css', array(), LGT_ST_VERSION );
		wp_enqueue_script( 'lgt-admin', LGT_ST_URL . 'assets/js/admin.js', array(), LGT_ST_VERSION, true );
		if ( isset( $_GET['page'] ) && 'lgt-trip' === $_GET['page'] && ! empty( $_GET['id'] ) && ( $_GET['tab'] ?? 'manage' ) === 'manage' ) {
			$trip = LGT_DB::get_trip( (int) $_GET['id'] );
			if ( $trip ) {
				wp_enqueue_style( 'lgt-app', LGT_ST_URL . 'assets/css/app.css', array(), LGT_ST_VERSION );
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
			'saved'     => array( 'success', 'Οι αλλαγές αποθηκεύτηκαν.' ),
			'link_sent' => array( 'success', 'Η εκδρομή δημιουργήθηκε και ο σύνδεσμος στάλθηκε στο σχολείο. Όταν το σχολείο πατήσει «Υποβολή» θα λάβετε Excel και PDF με email.' ),
			'link_fail' => array( 'error', 'Η εκδρομή δημιουργήθηκε αλλά το email με τον σύνδεσμο δεν στάλθηκε. Αντιγράψτε τον σύνδεσμο από τη σελίδα της εκδρομής ή ελέγξτε τις ρυθμίσεις email.' ),
			'deleted'   => array( 'success', 'Η εκδρομή διαγράφηκε.' ),
			'settings'  => array( 'success', 'Οι ρυθμίσεις αποθηκεύτηκαν.' ),
			'cron'      => array( 'success', 'Οι υπενθυμίσεις/ενημερώσεις εκτελέστηκαν.' ),
			'test_ok'   => array( 'success', 'Το δοκιμαστικό email στάλθηκε.' ),
			'test_fail' => array( 'error', 'Η αποστολή δοκιμαστικού email απέτυχε. Ελέγξτε τις ρυθμίσεις email (SMTP) του WordPress.' ),
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
		$map = array( 'draft' => 'Πρόχειρο', 'open' => 'Ανοιχτή φόρμα', 'closed' => 'Κλειστή', 'archived' => 'Αρχείο' );
		return $map[ $status ] ?? $status;
	}

	/** Human-readable progress for the list screen. */
	public static function progress_label( array $t, $names ) {
		if ( 'draft' === $t['status'] ) {
			return array( 'draft', 'Πρόχειρο – δεν έχει σταλεί σύνδεσμος' );
		}
		if ( 'archived' === $t['status'] ) {
			return array( 'archived', 'Αρχείο' );
		}
		if ( 'closed' === $t['status'] ) {
			return array( 'closed', 'Κλειστή' . ( $t['submitted_at'] ? ' – υποβλήθηκε' : '' ) );
		}
		if ( $t['submitted_at'] ) {
			$newer = $t['data_updated_at'] && strtotime( $t['data_updated_at'] ) > strtotime( $t['submitted_at'] );
			return array( 'submitted', $newer ? 'Υποβλήθηκε – νέες αλλαγές από τότε' : 'Υποβλήθηκε ✓' );
		}
		if ( $names ) {
			return array( 'progress', 'Το σχολείο συμπληρώνει (' . (int) $names . ' ονόματα)' );
		}
		return array( 'waiting', 'Περιμένουμε το σχολείο' );
	}

	private static function dl_url( $trip_id, $format, $list = 'all' ) {
		return add_query_arg( array( 'action' => 'lgt_download', 'trip' => $trip_id, 'format' => $format, 'list' => $list, '_wpnonce' => wp_create_nonce( 'lgt_download_' . $trip_id ) ), admin_url( 'admin-post.php' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Trips list                                                         */
	/* ------------------------------------------------------------------ */

	public static function page_trips() {
		self::check_cap();
		$status  = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged   = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$per     = 25;
		$filters = array( 'status' => $status, 'search' => $search, 'include_archived' => 'archived' === $status );
		$trips   = LGT_DB::list_trips( $filters, $per, ( $paged - 1 ) * $per );
		$total   = LGT_DB::count_trips( $filters );
		$base    = admin_url( 'admin.php?page=lgt-trips' );
		?>
		<div class="wrap lgt-wrap">
			<h1 class="wp-heading-inline">Σχολικές Εκδρομές</h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=lgt-trip' ) ); ?>" class="page-title-action">+ Νέα εκδρομή</a>
			<hr class="wp-header-end">
			<p class="lgt-muted" style="margin:6px 0 12px">1. Δημιουργείτε την εκδρομή → 2. Το σχολείο λαμβάνει σύνδεσμο και συμπληρώνει τη φόρμα ονομάτων και τη rooming list → 3. Πατά «Υποβολή» και λαμβάνετε Excel/PDF με email. Εδώ βλέπετε πού βρίσκεται κάθε σχολείο.</p>
			<ul class="subsubsub">
				<?php
				$tabs = array( '' => 'Ενεργές', 'open' => 'Ανοιχτές', 'closed' => 'Κλειστές', 'archived' => 'Αρχείο' );
				$i    = 0;
				foreach ( $tabs as $k => $label ) :
					$i++;
					?>
					<li><a href="<?php echo esc_url( add_query_arg( 'status', $k, $base ) ); ?>" class="<?php echo $status === $k ? 'current' : ''; ?>"><?php echo esc_html( $label ); ?></a><?php echo $i < count( $tabs ) ? ' | ' : ''; ?></li>
				<?php endforeach; ?>
			</ul>
			<form method="get" class="search-form" style="float:right;margin-bottom:8px;">
				<input type="hidden" name="page" value="lgt-trips">
				<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Αναζήτηση σχολείου">
				<button class="button">Αναζήτηση</button>
			</form>
			<table class="wp-list-table widefat fixed striped lgt-trips-table">
				<thead><tr><th style="width:30%">Σχολείο / Εκδρομή</th><th>Ημερομηνίες</th><th>Ονόματα</th><th>Δωμάτια</th><th>Κατάσταση</th><th>Τελευταία αλλαγή</th><th style="width:230px">Ενέργειες</th></tr></thead>
				<tbody>
				<?php if ( ! $trips ) : ?>
					<tr><td colspan="7">Δεν βρέθηκαν εκδρομές. <a href="<?php echo esc_url( admin_url( 'admin.php?page=lgt-trip' ) ); ?>">Δημιουργήστε την πρώτη.</a></td></tr>
				<?php endif; ?>
				<?php foreach ( $trips as $t ) : ?>
					<?php
					$sum    = LGT_Data::summary( $t );
					$manage = admin_url( 'admin.php?page=lgt-trip&id=' . $t['id'] . '&tab=manage' );
					$days   = $t['departure_date'] ? (int) floor( ( strtotime( $t['departure_date'] ) - strtotime( current_time( 'Y-m-d' ) ) ) / DAY_IN_SECONDS ) : null;
					list( $pcls, $plabel ) = self::progress_label( $t, $sum['names'] );
					?>
					<tr>
						<td><strong><a href="<?php echo esc_url( $manage ); ?>"><?php echo esc_html( $t['school_name'] ); ?></a></strong><br><span class="lgt-muted"><?php echo esc_html( $t['title'] ); ?></span></td>
						<td><?php echo esc_html( LGT_Exporter::fmt_date( $t['departure_date'] ) ); ?><?php echo $t['return_date'] ? ' – ' . esc_html( LGT_Exporter::fmt_date( $t['return_date'] ) ) : ''; ?>
							<?php if ( null !== $days && $days >= 0 && 'archived' !== $t['status'] ) : ?><br><span class="lgt-badge <?php echo $days <= 7 ? 'lgt-badge-warn' : 'lgt-badge-soft'; ?>">σε <?php echo (int) $days; ?> ημ.</span><?php endif; ?>
						</td>
						<td><?php echo (int) $sum['names']; ?></td>
						<td><?php echo $t['has_hotel'] ? (int) $sum['rooming']['rooms'] : '—'; ?><?php echo $t['has_ferry'] && $sum['cabins'] ? ' <span class="lgt-muted">/ ' . (int) $sum['cabins']['rooms'] . ' καμπ.</span>' : ''; ?></td>
						<td><span class="lgt-status lgt-status-<?php echo esc_attr( $pcls ); ?>"><?php echo esc_html( $plabel ); ?></span></td>
						<td class="lgt-muted"><?php echo $t['data_updated_at'] ? esc_html( date_i18n( 'd/m/Y H:i', strtotime( $t['data_updated_at'] ) ) ) : '—'; ?><?php echo $t['submitted_at'] ? '<br><small>Υποβολή: ' . esc_html( date_i18n( 'd/m/Y H:i', strtotime( $t['submitted_at'] ) ) ) . '</small>' : ''; ?></td>
						<td>
							<a class="button button-small" href="<?php echo esc_url( $manage ); ?>">Άνοιγμα</a>
							<?php if ( $sum['names'] || $sum['rooming']['rooms'] ) : ?>
								<a class="button button-small" href="<?php echo esc_url( self::dl_url( $t['id'], 'xlsx' ) ); ?>">Excel</a>
								<a class="button button-small" href="<?php echo esc_url( self::dl_url( $t['id'], 'pdf' ) ); ?>">PDF</a>
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
				echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $pages ) ) . '</div></div>'; // phpcs:ignore
			}
			?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Trip page                                                          */
	/* ------------------------------------------------------------------ */

	public static function page_trip() {
		self::check_cap();
		$id   = (int) ( $_GET['id'] ?? 0 );
		$trip = $id ? LGT_DB::get_trip( $id ) : null;
		if ( $id && ! $trip ) {
			echo '<div class="wrap"><h1>Η εκδρομή δεν βρέθηκε.</h1></div>';
			return;
		}
		$tab  = $trip ? ( isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'manage' ) : 'details';
		$base = $trip ? admin_url( 'admin.php?page=lgt-trip&id=' . $trip['id'] ) : '';
		?>
		<div class="wrap lgt-wrap">
			<h1 class="wp-heading-inline"><?php echo $trip ? esc_html( $trip['school_name'] . ' – ' . $trip['title'] ) : 'Νέα εκδρομή'; ?></h1>
			<?php if ( $trip ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=lgt-trip' ) ); ?>" class="page-title-action">Νέα εκδρομή</a>
			<?php endif; ?>
			<hr class="wp-header-end">
			<?php if ( $trip ) : ?>
				<nav class="nav-tab-wrapper">
					<a href="<?php echo esc_url( $base . '&tab=manage' ); ?>" class="nav-tab <?php echo 'manage' === $tab ? 'nav-tab-active' : ''; ?>">Φόρμα & rooming list</a>
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

	private static function render_trip_form( $trip ) {
		$t      = $trip ? $trip : array_merge( LGT_DB::trip_defaults(), array( 'id' => 0 ) );
		$is_new = ! $trip;
		?>
		<?php if ( $is_new ) : ?>
			<div class="lgt-alert lgt-alert-info" style="max-width:900px;margin-top:12px"><strong>Πώς δουλεύει:</strong> συμπληρώνετε τα βασικά και πατάτε «Δημιουργία & αποστολή». Το σχολείο λαμβάνει με email τον σύνδεσμο της online φόρμας, συμπληρώνει ονόματα και rooming list, πατά «Υποβολή» και σας έρχονται Excel &amp; PDF.</div>
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
				<p><strong>Τι θα συμπληρώσει το σχολείο:</strong></p>
				<div class="lgt-grid3">
					<label class="lgt-check-card"><input type="checkbox" checked disabled> 📋 Φόρμα ονομάτων<br><small>πάντα (ονόματα + ημ. γέννησης)</small></label>
					<label class="lgt-check-card"><input type="checkbox" name="has_hotel" value="1" <?php checked( $t['has_hotel'] ); ?>> 🏨 Rooming list<br><small>δίκλινα / τρίκλινα / τετράκλινα</small></label>
					<label class="lgt-check-card"><input type="checkbox" name="has_ferry" value="1" <?php checked( $t['has_ferry'] ); ?>> ⛴ Καμπίνες πλοίου<br><small>ίδια λογική για τις καμπίνες</small></label>
				</div>
				<p><label>Ακτοπλοϊκή εταιρεία / δρομολόγιο (αν υπάρχει πλοίο)<br><input type="text" name="ferry_company" class="regular-text" value="<?php echo esc_attr( $t['ferry_company'] ); ?>" placeholder="π.χ. Blue Star – Πειραιάς → Ρόδος"></label></p>
				<?php if ( $is_new ) : ?>
					<p style="margin-top:14px"><label><input type="checkbox" name="send_link" value="1" checked> <strong>Αποστολή του συνδέσμου στο σχολείο με email αμέσως</strong></label></p>
				<?php endif; ?>
				<details style="margin-top:10px">
					<summary style="cursor:pointer;color:#0f766e;font-weight:600">Προαιρετικά (τίτλος, υπεύθυνος, μήνυμα, κωδικός πρόσβασης, κατάσταση)</summary>
					<div class="lgt-grid2" style="margin-top:10px">
						<p><label>Τίτλος εκδρομής<br><input type="text" name="title" class="regular-text" value="<?php echo esc_attr( $t['title'] ); ?>" placeholder="αν μείνει κενό συμπληρώνεται αυτόματα"></label></p>
						<p><label>Υπεύθυνος καθηγητής<br><input type="text" name="contact_name" class="regular-text" value="<?php echo esc_attr( $t['contact_name'] ); ?>"></label></p>
						<p><label>Τηλέφωνο σχολείου<br><input type="text" name="school_phone" class="regular-text" value="<?php echo esc_attr( $t['school_phone'] ); ?>"></label></p>
						<p><label>Κωδικός πρόσβασης συνδέσμου<br><input type="text" name="access_code" class="regular-text" value="<?php echo esc_attr( $t['access_code'] ); ?>" placeholder="κενό = χωρίς κωδικό"></label></p>
						<?php if ( ! $is_new ) : ?>
							<p><label>Κατάσταση<br><select name="status">
								<?php foreach ( array( 'open', 'closed', 'draft', 'archived' ) as $s ) : ?>
									<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $t['status'], $s ); ?>><?php echo esc_html( self::status_label( $s ) ); ?></option>
								<?php endforeach; ?>
							</select></label></p>
						<?php endif; ?>
					</div>
					<p><label>Επιπλέον emails για υπενθυμίσεις (συνοδοί, ξενοδοχείο…)<br><textarea name="extra_emails" rows="2" class="large-text"><?php echo esc_textarea( (string) $t['extra_emails'] ); ?></textarea></label></p>
					<p><label>Μήνυμα προς το σχολείο (φαίνεται στη φόρμα και στα emails)<br><textarea name="notes_school" rows="3" class="large-text"><?php echo esc_textarea( (string) $t['notes_school'] ); ?></textarea></label></p>
					<p><label>Εσωτερικές σημειώσεις (μόνο γραφείο)<br><textarea name="notes_internal" rows="2" class="large-text"><?php echo esc_textarea( (string) $t['notes_internal'] ); ?></textarea></label></p>
				</details>
			</div>
			<p class="submit">
				<button type="submit" class="button button-primary button-large"><?php echo $is_new ? '✔ Δημιουργία & αποστολή στο σχολείο' : 'Αποθήκευση'; ?></button>
				<?php if ( $trip ) : ?>
					<a class="button button-large" href="<?php echo esc_url( admin_url( 'admin.php?page=lgt-trip&id=' . $trip['id'] . '&tab=manage' ) ); ?>">Προβολή φόρμας →</a>
					<a class="button button-link-delete lgt-confirm" style="margin-left:20px" data-confirm="Οριστική διαγραφή της εκδρομής και όλων των στοιχείων;" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lgt_delete_trip&trip_id=' . $trip['id'] ), 'lgt_delete_trip_' . $trip['id'] ) ); ?>">Διαγραφή εκδρομής</a>
				<?php endif; ?>
			</p>
		</form>
		<?php
	}

	private static function render_history( array $trip ) {
		$activity = LGT_DB::get_activity( $trip['id'], 300 );
		$mail     = LGT_DB::get_mail_log( 100, $trip['id'] );
		$labels   = array( 'trip_update' => 'Αλλαγή στοιχείων', 'trip_create' => 'Δημιουργία', 'submit' => 'Υποβολή φόρμας', 'send_now' => 'Αποστολή αρχείων', 'send_link' => 'Αποστολή συνδέσμου', 'download' => 'Λήψη αρχείου', 'reminder' => 'Υπενθύμιση', 'link_close' => 'Κλείσιμο φόρμας', 'link_open' => 'Άνοιγμα φόρμας', 'link_regenerate' => 'Νέος σύνδεσμος', 'link_generate' => 'Δημιουργία συνδέσμου' );
		?>
		<div class="lgt-form-grid">
			<div class="lgt-card"><h2>Ενέργειες</h2>
				<table class="widefat striped"><thead><tr><th>Πότε</th><th>Ποιος</th><th>Ενέργεια</th><th>Λεπτομέρειες</th></tr></thead><tbody>
				<?php if ( ! $activity ) : ?><tr><td colspan="4">Καμία καταγραφή.</td></tr><?php endif; ?>
				<?php foreach ( (array) $activity as $a ) : ?>
					<tr><td><?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $a['created_at'] ) ) ); ?></td><td><?php echo esc_html( 'school' === $a['actor'] ? 'Σχολείο' : ( 'admin' === $a['actor'] ? 'Γραφείο' : 'Σύστημα' ) ); ?></td><td><?php echo esc_html( $labels[ $a['action'] ] ?? $a['action'] ); ?></td><td class="lgt-muted"><?php echo esc_html( mb_substr( (string) $a['details'], 0, 200 ) ); ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			</div>
			<div class="lgt-card"><h2>Emails</h2>
				<table class="widefat striped"><thead><tr><th>Πότε</th><th>Τύπος</th><th>Προς</th><th>Θέμα</th><th>OK</th></tr></thead><tbody>
				<?php if ( ! $mail ) : ?><tr><td colspan="5">Δεν έχουν σταλεί emails.</td></tr><?php endif; ?>
				<?php foreach ( (array) $mail as $m ) : ?>
					<tr><td><?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $m['created_at'] ) ) ); ?></td><td><?php echo esc_html( $m['kind'] ); ?></td><td class="lgt-muted"><?php echo esc_html( $m['recipients'] ); ?></td><td><?php echo esc_html( $m['subject'] ); ?><?php echo $m['attachments'] ? '<br><small class="lgt-muted">📎 ' . esc_html( $m['attachments'] ) . '</small>' : ''; ?></td><td><?php echo $m['success'] ? '✅' : '❌ <small>' . esc_html( $m['error'] ) . '</small>'; ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			</div>
		</div>
		<?php
	}

	public static function handle_save_trip() {
		self::check_cap();
		check_admin_referer( 'lgt_save_trip' );
		$id     = (int) ( $_POST['trip_id'] ?? 0 );
		$post   = wp_unslash( $_POST );
		$status = $post['status'] ?? 'open';
		$data   = array(
			'title'          => sanitize_text_field( $post['title'] ?? '' ),
			'school_name'    => sanitize_text_field( $post['school_name'] ?? '' ),
			'destination'    => sanitize_text_field( $post['destination'] ?? '' ),
			'departure_date' => LGT_DB::clean_date( $post['departure_date'] ?? '' ),
			'return_date'    => LGT_DB::clean_date( $post['return_date'] ?? '' ),
			'status'         => in_array( $status, array( 'draft', 'open', 'closed', 'archived' ), true ) ? $status : 'open',
			'contact_name'   => sanitize_text_field( $post['contact_name'] ?? '' ),
			'school_email'   => sanitize_email( $post['school_email'] ?? '' ),
			'school_phone'   => sanitize_text_field( $post['school_phone'] ?? '' ),
			'extra_emails'   => sanitize_textarea_field( $post['extra_emails'] ?? '' ),
			'access_code'    => sanitize_text_field( $post['access_code'] ?? '' ),
			'has_hotel'      => empty( $post['has_hotel'] ) ? 0 : 1,
			'has_ferry'      => empty( $post['has_ferry'] ) ? 0 : 1,
			'hotel_name'     => sanitize_text_field( $post['hotel_name'] ?? '' ),
			'ferry_company'  => sanitize_text_field( $post['ferry_company'] ?? '' ),
			'notes_school'   => sanitize_textarea_field( $post['notes_school'] ?? '' ),
			'notes_internal' => sanitize_textarea_field( $post['notes_internal'] ?? '' ),
		);
		if ( '' === $data['title'] ) {
			$data['title'] = trim( 'Εκδρομή ' . $data['destination'] . ( $data['departure_date'] ? ' ' . LGT_Exporter::fmt_date( $data['departure_date'] ) : '' ) );
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
		self::redirect( admin_url( 'admin.php?page=lgt-trip&id=' . $id . '&tab=' . ( (int) ( $_POST['trip_id'] ?? 0 ) ? 'details' : 'manage' ) ), $msg );
	}

	public static function handle_delete_trip() {
		self::check_cap();
		$id = (int) ( $_GET['trip_id'] ?? 0 );
		check_admin_referer( 'lgt_delete_trip_' . $id );
		LGT_DB::delete_trip( $id );
		self::redirect( admin_url( 'admin.php?page=lgt-trips' ), 'deleted' );
	}

	/** admin-post.php?action=lgt_download&trip=ID&format=xlsx|pdf&list=all|form|rooming|cabins */
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
		$s    = LGT_Settings::all();
		$next = wp_next_scheduled( LGT_Cron::HOOK );
		?>
		<div class="wrap lgt-wrap">
			<h1>Ρυθμίσεις – Σχολικές Εκδρομές</h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lgt-form">
				<?php wp_nonce_field( 'lgt_save_settings' ); ?>
				<input type="hidden" name="action" value="lgt_save_settings">
				<div class="lgt-form-grid">
					<div class="lgt-card">
						<h2>Πού έρχονται τα αρχεία</h2>
						<p><label>Emails γραφείου (λαμβάνουν Excel/PDF με κάθε υποβολή, υπενθυμίσεις)<br><textarea name="office_emails" rows="3" class="large-text" placeholder="ένα ανά γραμμή"><?php echo esc_textarea( $s['office_emails'] ); ?></textarea></label></p>
						<p><label><input type="checkbox" name="attach_xlsx" value="1" <?php checked( $s['attach_xlsx'] ); ?>> Επισύναψη Excel</label> &nbsp; <label><input type="checkbox" name="attach_pdf" value="1" <?php checked( $s['attach_pdf'] ); ?>> Επισύναψη PDF</label></p>
						<p><label><input type="checkbox" name="daily_digest" value="1" <?php checked( $s['daily_digest'] ); ?>> Ημερήσια αυτόματη αποστολή ενημερωμένων αρχείων όταν ένα σχολείο αλλάξει κάτι μετά την υποβολή</label></p>
						<p><label><input type="checkbox" name="close_on_submit" value="1" <?php checked( $s['close_on_submit'] ); ?>> Σύνδεσμος μίας χρήσης: η φόρμα κλείνει αυτόματα μόλις το σχολείο πατήσει «Υποβολή» (την ξανανοίγετε εσείς αν χρειαστεί)</label></p>
						<p><label>Όνομα αποστολέα<br><input type="text" name="from_name" class="regular-text" value="<?php echo esc_attr( $s['from_name'] ); ?>"></label></p>
						<p><label>Email αποστολέα<br><input type="email" name="from_email" class="regular-text" value="<?php echo esc_attr( $s['from_email'] ); ?>" placeholder="noreply@legrandtravel.gr"></label><br><span class="description">Προτείνεται SMTP plugin για αξιόπιστη παράδοση.</span></p>
						<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lgt_test_email' ), 'lgt_test_email' ) ); ?>">Αποστολή δοκιμαστικού email</a></p>
					</div>
					<div class="lgt-card">
						<h2>Υπενθυμίσεις</h2>
						<p><label>Ημέρες πριν την αναχώρηση<br><input type="text" name="reminder_days" class="regular-text" value="<?php echo esc_attr( $s['reminder_days'] ); ?>" placeholder="30,14,7,3,1"></label><br><span class="description">Χωρισμένες με κόμμα. Μία υπενθύμιση για κάθε όριο.</span></p>
						<p><label>Ώρα αποστολής<br><select name="reminder_hour"><?php for ( $h = 0; $h < 24; $h++ ) : ?><option value="<?php echo (int) $h; ?>" <?php selected( (int) $s['reminder_hour'], $h ); ?>><?php echo sprintf( '%02d:00', $h ); ?></option><?php endfor; ?></select></label></p>
						<p><label><input type="checkbox" name="reminder_to_school" value="1" <?php checked( $s['reminder_to_school'] ); ?>> Υπενθύμιση και στο σχολείο</label></p>
						<p><label><input type="checkbox" name="reminder_to_extra" value="1" <?php checked( $s['reminder_to_extra'] ); ?>> Υπενθύμιση και στα επιπλέον emails της εκδρομής</label></p>
						<p><label><input type="checkbox" name="reminder_attachments" value="1" <?php checked( $s['reminder_attachments'] ); ?>> Επισύναψη Excel/PDF στην υπενθύμιση του γραφείου</label></p>
						<p class="description">Επόμενη αυτόματη εκτέλεση: <?php echo $next ? esc_html( wp_date( 'd/m/Y H:i', $next ) ) : '—'; ?>. <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lgt_run_cron' ), 'lgt_run_cron' ) ); ?>">Εκτέλεση τώρα</a></p>
					</div>
					<div class="lgt-card">
						<h2>Εταιρεία & έγγραφα</h2>
						<p><label>Επωνυμία<br><input type="text" name="company_name" class="regular-text" value="<?php echo esc_attr( $s['company_name'] ); ?>"></label></p>
						<p><label>Τηλέφωνο<br><input type="text" name="company_phone" class="regular-text" value="<?php echo esc_attr( $s['company_phone'] ); ?>"></label></p>
						<p><label>Email επικοινωνίας (Reply-To)<br><input type="email" name="company_email" class="regular-text" value="<?php echo esc_attr( $s['company_email'] ); ?>"></label></p>
						<p><label>Λογότυπο για PDF (JPG)<br><input type="text" name="company_logo" id="lgt-logo" class="regular-text" value="<?php echo esc_attr( $s['company_logo'] ); ?>"> <button type="button" class="button" id="lgt-logo-pick">Επιλογή</button></label></p>
						<p><label>Slug συνδέσμου<br><code><?php echo esc_html( home_url( '/' ) ); ?></code><input type="text" name="portal_slug" class="regular-text" value="<?php echo esc_attr( $s['portal_slug'] ); ?>"><code>/xxxxxxxx/</code></label></p>
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
			'attach_xlsx'          => empty( $p['attach_xlsx'] ) ? 0 : 1,
			'attach_pdf'           => empty( $p['attach_pdf'] ) ? 0 : 1,
			'daily_digest'         => empty( $p['daily_digest'] ) ? 0 : 1,
			'close_on_submit'      => empty( $p['close_on_submit'] ) ? 0 : 1,
			'reminder_days'        => sanitize_text_field( $p['reminder_days'] ?? '' ),
			'reminder_hour'        => max( 0, min( 23, (int) ( $p['reminder_hour'] ?? 8 ) ) ),
			'reminder_to_school'   => empty( $p['reminder_to_school'] ) ? 0 : 1,
			'reminder_to_extra'    => empty( $p['reminder_to_extra'] ) ? 0 : 1,
			'reminder_attachments' => empty( $p['reminder_attachments'] ) ? 0 : 1,
			'company_name'         => sanitize_text_field( $p['company_name'] ?? '' ),
			'company_phone'        => sanitize_text_field( $p['company_phone'] ?? '' ),
			'company_email'        => sanitize_email( $p['company_email'] ?? '' ),
			'company_logo'         => esc_url_raw( $p['company_logo'] ?? '' ),
			'portal_slug'          => sanitize_title( $p['portal_slug'] ?? 'school-trip' ),
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
		$ok = LGT_Mailer::send( 0, 'test', LGT_Settings::office_emails(), '[Σχολικές Εκδρομές] Δοκιμαστικό email', '<p>Το email λειτουργεί σωστά.</p>' );
		self::redirect( admin_url( 'admin.php?page=lgt-settings' ), $ok ? 'test_ok' : 'test_fail' );
	}

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
					<tr><td><?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $m['created_at'] ) ) ); ?></td><td><?php echo esc_html( $m['kind'] ); ?></td><td><?php echo $m['trip_id'] ? '<a href="' . esc_url( admin_url( 'admin.php?page=lgt-trip&id=' . (int) $m['trip_id'] . '&tab=history' ) ) . '">#' . (int) $m['trip_id'] . '</a>' : '—'; ?></td><td class="lgt-muted"><?php echo esc_html( $m['recipients'] ); ?></td><td><?php echo esc_html( $m['subject'] ); ?><?php echo $m['attachments'] ? '<br><small class="lgt-muted">📎 ' . esc_html( $m['attachments'] ) . '</small>' : ''; ?></td><td><?php echo $m['success'] ? '✅' : '❌'; ?><?php echo $m['error'] ? '<br><small>' . esc_html( $m['error'] ) . '</small>' : ''; ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
