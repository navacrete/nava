<?php
/**
 * Standalone school portal page.
 *
 * @var array $trip
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$company = LGT_Settings::get( 'company_name' );
$config  = LGT_Portal::app_config( $trip, 'school' );
?>
<!DOCTYPE html>
<html lang="el">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $trip['title'] . ' – ' . $trip['school_name'] . ' | ' . $company ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( LGT_ST_URL . 'assets/css/app.css?v=' . LGT_ST_VERSION ); ?>">
</head>
<body class="lgt-portal-body">
<div class="lgt-portal-shell">
	<header class="lgt-portal-header">
		<div class="lgt-portal-brand">
			<div class="lgt-portal-logo"><?php echo esc_html( mb_substr( $company, 0, 1 ) ); ?></div>
			<div>
				<div class="lgt-portal-company"><?php echo esc_html( $company ); ?></div>
				<div class="lgt-portal-sub">Πλατφόρμα σχολικών εκδρομών</div>
			</div>
		</div>
		<div class="lgt-portal-contact">
			<?php if ( LGT_Settings::get( 'company_phone' ) ) : ?>
				<span>☎ <?php echo esc_html( LGT_Settings::get( 'company_phone' ) ); ?></span>
			<?php endif; ?>
			<?php if ( LGT_Settings::get( 'company_email' ) ) : ?>
				<span>✉ <a href="mailto:<?php echo esc_attr( LGT_Settings::get( 'company_email' ) ); ?>"><?php echo esc_html( LGT_Settings::get( 'company_email' ) ); ?></a></span>
			<?php endif; ?>
		</div>
	</header>
	<main>
		<div id="lgt-app" class="lgt-app"><div class="lgt-loading">Φόρτωση…</div></div>
	</main>
	<footer class="lgt-portal-footer">© <?php echo esc_html( date( 'Y' ) . ' ' . $company ); ?> · Τα στοιχεία χρησιμοποιούνται αποκλειστικά για την οργάνωση της εκδρομής.</footer>
</div>
<script>window.LGT_APP = <?php echo wp_json_encode( $config ); ?>;</script>
<script src="<?php echo esc_url( LGT_ST_URL . 'assets/js/app.js?v=' . LGT_ST_VERSION ); ?>"></script>
</body>
</html>
