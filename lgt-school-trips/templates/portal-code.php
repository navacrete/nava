<?php
/**
 * Access-code prompt.
 *
 * @var array  $trip
 * @var string $error
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
<title>Κωδικός πρόσβασης | <?php echo esc_html( $company ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( LGT_ST_URL . 'assets/css/app.css?v=' . LGT_ST_VERSION ); ?>">
</head>
<body class="lgt-portal-body">
<div class="lgt-portal-shell lgt-narrow">
	<div class="lgt-card lgt-center-card">
		<div class="lgt-portal-logo lgt-big"><?php echo esc_html( mb_substr( $company, 0, 1 ) ); ?></div>
		<h1><?php echo esc_html( $trip['title'] ); ?></h1>
		<p class="lgt-muted"><?php echo esc_html( $trip['school_name'] ); ?></p>
		<p>Εισάγετε τον κωδικό πρόσβασης που σας έστειλε το γραφείο.</p>
		<?php if ( $error ) : ?>
			<div class="lgt-alert lgt-alert-error"><?php echo esc_html( $error ); ?></div>
		<?php endif; ?>
		<form method="post">
			<?php wp_nonce_field( 'lgt_access_' . $trip['id'] ); ?>
			<input class="lgt-input lgt-input-lg" type="text" name="lgt_access_code" autocomplete="off" autofocus placeholder="Κωδικός">
			<button class="lgt-btn lgt-btn-primary lgt-btn-block" type="submit">Είσοδος</button>
		</form>
	</div>
</div>
</body>
</html>
