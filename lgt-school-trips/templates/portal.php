<?php
/**
 * Standalone school page (the LeGrand forms, online).
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
<title><?php echo esc_html( 'Φόρμα ' . $company . ' – ' . $trip['school_name'] ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( LGT_ST_URL . 'assets/css/app.css?v=' . LGT_ST_VERSION ); ?>">
</head>
<body class="lgt-portal-body">
<div class="lgt-brand">
	<div class="lgt-brand-logo"><?php echo esc_html( mb_substr( $company, 0, 1 ) ); ?></div>
	<div><b><?php echo esc_html( $company ); ?></b><small>Φόρμα ονομάτων &amp; rooming list</small></div>
	<div class="lgt-contact">
		<?php if ( LGT_Settings::get( 'company_phone' ) ) : ?><div>☎ <?php echo esc_html( LGT_Settings::get( 'company_phone' ) ); ?></div><?php endif; ?>
		<?php if ( LGT_Settings::get( 'company_email' ) ) : ?><div><a href="mailto:<?php echo esc_attr( LGT_Settings::get( 'company_email' ) ); ?>"><?php echo esc_html( LGT_Settings::get( 'company_email' ) ); ?></a></div><?php endif; ?>
	</div>
</div>
<div id="lgt-app" class="lgt-app"><div class="lgt-loading">Φόρτωση…</div></div>
<p class="lgt-footer">© <?php echo esc_html( date( 'Y' ) . ' ' . $company ); ?> · Τα στοιχεία χρησιμοποιούνται αποκλειστικά για την οργάνωση της εκδρομής.</p>
<script>window.LGT_APP = <?php echo wp_json_encode( $config ); ?>;</script>
<script src="<?php echo esc_url( LGT_ST_URL . 'assets/js/app.js?v=' . LGT_ST_VERSION ); ?>"></script>
</body>
</html>
