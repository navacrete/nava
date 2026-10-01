<?php
/**
 * Greek public holidays for the private sector, computed per year (incl. Orthodox Easter).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LGTKS_Holidays {

	/** Orthodox Easter Sunday (Gregorian date) for 1900–2099. */
	public static function orthodox_easter( $year ) {
		$year = (int) $year;
		$a    = $year % 4;
		$b    = $year % 7;
		$c    = $year % 19;
		$d    = ( 19 * $c + 15 ) % 30;
		$e    = ( 2 * $a + 4 * $b - $d + 34 ) % 7;
		$month = (int) floor( ( $d + $e + 114 ) / 31 );
		$day   = ( ( $d + $e + 114 ) % 31 ) + 1;
		// Julian date -> Gregorian: +13 days for 1900-2099.
		$dt = new DateTime( sprintf( '%04d-%02d-%02d', $year, $month, $day ), new DateTimeZone( 'UTC' ) );
		$dt->modify( '+13 days' );
		return $dt;
	}

	/**
	 * Holidays of a year: [ 'Y-m-d' => name ].
	 * Statutory private-sector holidays (ν. 4468/2017 άρθρο 14, όπως ισχύει) plus optional customary ones.
	 */
	public static function for_year( $year, $clean_monday = true, $holy_spirit = false, $good_friday = false ) {
		$year = (int) $year;
		$h    = array(
			sprintf( '%04d-01-01', $year ) => 'Πρωτοχρονιά',
			sprintf( '%04d-01-06', $year ) => 'Θεοφάνεια',
			sprintf( '%04d-03-25', $year ) => '25η Μαρτίου',
			sprintf( '%04d-05-01', $year ) => 'Πρωτομαγιά',
			sprintf( '%04d-08-15', $year ) => 'Κοίμηση της Θεοτόκου',
			sprintf( '%04d-10-28', $year ) => '28η Οκτωβρίου',
			sprintf( '%04d-12-25', $year ) => 'Χριστούγεννα',
			sprintf( '%04d-12-26', $year ) => 'Σύναξη της Θεοτόκου',
		);
		$e = self::orthodox_easter( $year );
		$h[ $e->format( 'Y-m-d' ) ] = 'Κυριακή του Πάσχα';
		$m = clone $e;
		$m->modify( '+1 day' );
		$h[ $m->format( 'Y-m-d' ) ] = 'Δευτέρα του Πάσχα';
		if ( $clean_monday ) {
			$k = clone $e;
			$k->modify( '-48 days' );
			$h[ $k->format( 'Y-m-d' ) ] = 'Καθαρά Δευτέρα (κατ’ έθιμο)';
		}
		if ( $good_friday ) {
			$g = clone $e;
			$g->modify( '-2 days' );
			$h[ $g->format( 'Y-m-d' ) ] = 'Μεγάλη Παρασκευή (κατ’ έθιμο)';
		}
		if ( $holy_spirit ) {
			$p = clone $e;
			$p->modify( '+50 days' );
			$h[ $p->format( 'Y-m-d' ) ] = 'Αγίου Πνεύματος (κατ’ έθιμο)';
		}
		ksort( $h );
		return $h;
	}

	/** Auto holidays per settings for a given year. */
	public static function auto_for_year( $year ) {
		if ( ! LGTKS_Settings::get( 'holidays_auto', 1 ) ) {
			return array();
		}
		return self::for_year(
			$year,
			(bool) LGTKS_Settings::get( 'holiday_clean_monday', 1 ),
			(bool) LGTKS_Settings::get( 'holiday_holy_spirit', 0 ),
			(bool) LGTKS_Settings::get( 'holiday_good_friday', 0 )
		);
	}

	/** Name of the holiday on a day (auto or manual), or '' if not a holiday. */
	public static function name( $day ) {
		$auto = self::auto_for_year( substr( $day, 0, 4 ) );
		if ( isset( $auto[ $day ] ) ) {
			return $auto[ $day ];
		}
		$manual = LGTKS_Settings::parse_dates( LGTKS_Settings::get( 'holidays' ) );
		return isset( $manual[ $day ] ) ? 'Αργία (δική σας λίστα)' : '';
	}
}
