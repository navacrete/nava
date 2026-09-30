<?php
/**
 * Inactive / unknown link.
 *
 * @var array|null $trip
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$company = LGT_Settings::get( 'company_name' );
?>
<!DOCTYPE html>
<html lang="el">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Μη ενεργός σύνδεσμος | <?php echo esc_html( $company ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( LGT_ST_URL . 'assets/css/app.css?v=' . LGT_ST_VERSION ); ?>">
</head>
<body class="lgt-portal-body">
<div class="lgt-portal-shell lgt-narrow">
	<div class="lgt-card lgt-center-card">
		<div class="lgt-portal-logo lgt-big"><?php echo esc_html( mb_substr( $company, 0, 1 ) ); ?></div>
		<h1>Ο σύνδεσμος δεν είναι ενεργός</h1>
		<p>Η καταχώρηση για αυτή την εκδρομή δεν είναι διαθέσιμη αυτή τη στιγμή. Παρακαλούμε επικοινωνήστε με το γραφείο μας.</p>
		<?php if ( LGT_Settings::get( 'company_phone' ) ) : ?>
			<p><strong>☎ <?php echo esc_html( LGT_Settings::get( 'company_phone' ) ); ?></strong></p>
		<?php endif; ?>
		<?php if ( LGT_Settings::get( 'company_email' ) ) : ?>
			<p><a href="mailto:<?php echo esc_attr( LGT_Settings::get( 'company_email' ) ); ?>"><?php echo esc_html( LGT_Settings::get( 'company_email' ) ); ?></a></p>
		<?php endif; ?>
	</div>
</div>
</body>
</html>
