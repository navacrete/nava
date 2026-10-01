<?php
/**
 * Excel / PDF files from the school's form and rooming list(s).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LGT_Exporter {

	const LISTS = array( 'all', 'form', 'rooming', 'cabins' );

	public static function storage_dir() {
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'lgt-school-trips';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	public static function cleanup() {
		foreach ( glob( self::storage_dir() . '/*.{xlsx,csv,pdf}', GLOB_BRACE ) as $f ) {
			if ( filemtime( $f ) < time() - DAY_IN_SECONDS ) {
				@unlink( $f );
			}
		}
	}

	public static function fmt_date( $d ) {
		return LGT_Data::fmt_date_dmy( $d );
	}

	public static function safe_filename( $s ) {
		$s = LGT_Transliterator::to_latin( $s );
		return trim( preg_replace( '/[^A-Z0-9]+/', '_', $s ), '_' );
	}

	private static function base_name( array $trip, $list ) {
		$map  = array( 'all' => 'LeGrand', 'form' => 'Forma_LeGrand', 'rooming' => 'LeGrand_rooming_list', 'cabins' => 'LeGrand_cabins' );
		$name = ( $map[ $list ] ?? 'LeGrand' ) . '_' . self::safe_filename( $trip['school_name'] ? $trip['school_name'] : $trip['title'] );
		if ( $trip['departure_date'] ) {
			$name .= '_' . date( 'Ymd', strtotime( $trip['departure_date'] ) );
		}
		return $name;
	}

	private static function unique( $base, $ext ) {
		return self::storage_dir() . '/' . $base . '_' . substr( md5( uniqid( '', true ) ), 0, 6 ) . '.' . $ext;
	}

	public static function lists_for( array $trip ) {
		$lists = array( 'form' );
		if ( $trip['has_hotel'] ) {
			$lists[] = 'rooming';
		}
		if ( $trip['has_ferry'] ) {
			$lists[] = 'cabins';
		}
		return $lists;
	}

	private static function title_rows( array $trip, $what ) {
		$rows   = array( array( LGT_Settings::get( 'company_name' ) . ' – ' . $what ), array( $trip['school_name'] . ( $trip['title'] ? ' – ' . $trip['title'] : '' ) ) );
		$line   = array();
		if ( $trip['destination'] ) {
			$line[] = $trip['destination'];
		}
		if ( $trip['departure_date'] ) {
			$line[] = self::fmt_date( $trip['departure_date'] ) . ( $trip['return_date'] ? ' – ' . self::fmt_date( $trip['return_date'] ) : '' );
		}
		if ( $line ) {
			$rows[] = array( implode( '  |  ', $line ) );
		}
		return $rows;
	}

	/* ------------------------------------------------------------------ */
	/* Datasets                                                           */
	/* ------------------------------------------------------------------ */

	/** Rows of the names form, numbered. */
	public static function form_rows( array $trip ) {
		$rows = array();
		$i    = 0;
		foreach ( LGT_Data::filled_rows( $trip['form'] ) as $r ) {
			$rows[] = array( ++$i, $r['lastName'], $r['firstName'], $r['birthDate'] );
		}
		return $rows;
	}

	/**
	 * Rooming rows exactly like the original export: Τύπος, Δωμάτιο, Όνομα 1..5 (rooms with names only).
	 */
	public static function rooming_rows( array $rooming, $kind = 'hotel' ) {
		$rows = array();
		$word = 'cabin' === $kind ? 'Καμπίνα' : 'Δωμάτιο';
		foreach ( $rooming['columns'] as $col ) {
			$n = 0;
			foreach ( $col['rooms'] as $room ) {
				if ( ! array_filter( $room ) ) {
					continue;
				}
				$n++;
				$row = array( $col['label'], $word . ' ' . $n );
				for ( $i = 0; $i < 5; $i++ ) {
					$row[] = $room[ $i ] ?? '';
				}
				$rows[] = $row;
			}
		}
		return $rows;
	}

	private static function summary_rows( array $rooming, $kind = 'hotel' ) {
		$s    = LGT_Data::rooming_summary( $rooming );
		$rows = array( array( 'ΣΥΝΟΛΑ' ), array( 'cabin' === $kind ? 'Σύνολο Καμπινών' : 'Σύνολο Δωματίων', $s['rooms'] ) );
		foreach ( $s['per_type'] as $label => $n ) {
			$rows[] = array( $label, $n );
		}
		return $rows;
	}

	/* ------------------------------------------------------------------ */
	/* Excel                                                              */
	/* ------------------------------------------------------------------ */

	public static function build_xlsx( $trip_id, $list = 'all' ) {
		$trip = is_array( $trip_id ) ? $trip_id : LGT_DB::get_trip( $trip_id );
		if ( ! $trip ) {
			return null;
		}
		$lists = 'all' === $list ? self::lists_for( $trip ) : array( $list );
		$x     = new LGT_XLSX_Writer();
		foreach ( $lists as $l ) {
			if ( 'form' === $l ) {
				$x->add_sheet( 'Φόρμα LeGrand', array( '#', 'Επώνυμο', 'Όνομα', 'Ημερομηνία Γέννησης' ), self::form_rows( $trip ), array( 5, 28, 28, 20 ), self::title_rows( $trip, 'ΦΟΡΜΑ ΟΝΟΜΑΤΩΝ' ) );
			} elseif ( 'rooming' === $l && $trip['rooming'] ) {
				$rows = array_merge( self::rooming_rows( $trip['rooming'], 'hotel' ), array( array() ), self::summary_rows( $trip['rooming'], 'hotel' ) );
				$tr   = self::title_rows( $trip, 'ROOMING LIST' . ( $trip['rooming']['meta']['hotelName'] ? ' – ' . $trip['rooming']['meta']['hotelName'] : '' ) );
				$x->add_sheet( 'Rooming List', array( 'Τύπος Δωματίου', 'Δωμάτιο', 'Όνομα 1', 'Όνομα 2', 'Όνομα 3', 'Όνομα 4', 'Όνομα 5' ), $rows, array( 16, 12, 28, 28, 28, 28, 28 ), $tr );
			} elseif ( 'cabins' === $l && $trip['cabins'] ) {
				$rows = array_merge( self::rooming_rows( $trip['cabins'], 'cabin' ), array( array() ), self::summary_rows( $trip['cabins'], 'cabin' ) );
				$tr   = self::title_rows( $trip, 'ΚΑΜΠΙΝΕΣ ΠΛΟΙΟΥ' . ( $trip['cabins']['meta']['hotelName'] ? ' – ' . $trip['cabins']['meta']['hotelName'] : '' ) );
				$x->add_sheet( 'Καμπίνες', array( 'Τύπος Καμπίνας', 'Καμπίνα', 'Όνομα 1', 'Όνομα 2', 'Όνομα 3', 'Όνομα 4', 'Όνομα 5' ), $rows, array( 16, 12, 28, 28, 28, 28, 28 ), $tr );
			}
		}
		return $x->save( self::unique( self::base_name( $trip, $list ), 'xlsx' ) );
	}

	/* ------------------------------------------------------------------ */
	/* PDF                                                                */
	/* ------------------------------------------------------------------ */

	private static function pdf_rooming_section( LGT_PDF_Writer $pdf, array $trip, array $rooming, $kind ) {
		$is_cabin = 'cabin' === $kind;
		$pdf->add_page();
		$pdf->write_line( $is_cabin ? 'CABIN LIST' : 'ROOMING LIST', 15, true );
		$m    = $rooming['meta'];
		$line = array();
		if ( $m['groupName'] ) {
			$line[] = $m['groupName'];
		}
		if ( $m['hotelName'] ) {
			$line[] = ( $is_cabin ? 'Ferry: ' : 'Hotel: ' ) . $m['hotelName'];
		}
		if ( $m['arrivalDate'] || $m['departureDate'] ) {
			$line[] = ( $is_cabin ? 'Date: ' : 'Arrival: ' ) . $m['arrivalDate'] . ( $m['departureDate'] ? ( $is_cabin ? ' - ' : '   Departure: ' ) . $m['departureDate'] : '' );
		}
		if ( $line ) {
			$pdf->write_line( implode( '   |   ', $line ), 9.5, false );
		}
		$s     = LGT_Data::rooming_summary( $rooming );
		$parts = array();
		foreach ( $s['per_type'] as $label => $n ) {
			if ( $n ) {
				$parts[] = $n . ' x ' . self::type_en( $label );
			}
		}
		$pdf->write_line( 'Total ' . ( $is_cabin ? 'cabins' : 'rooms' ) . ': ' . $s['rooms'] . ( $parts ? '   (' . implode( ', ', $parts ) . ')' : '' ), 10, true );
		$pdf->ln( 2 );
		foreach ( $rooming['columns'] as $col ) {
			$rows = array();
			$n    = 0;
			foreach ( $col['rooms'] as $room ) {
				if ( ! array_filter( $room ) ) {
					continue;
				}
				$n++;
				$rows[] = array_merge( array( ( $is_cabin ? 'Cabin ' : 'Room ' ) . $n ), $room );
			}
			if ( ! $rows ) {
				continue;
			}
			$type = (int) $col['type'];
			$pdf->write_line( strtoupper( self::type_en( $col['label'] ) ) . ' (' . $n . ')', 11, true );
			$header = array( $is_cabin ? 'Cabin' : 'Room' );
			$widths = array( 22 );
			$cw     = $pdf->content_width() - 22;
			for ( $i = 1; $i <= $type; $i++ ) {
				$header[] = 'Name ' . $i;
				$widths[] = $cw / $type;
			}
			$pdf->table( $header, $rows, $widths, array( 'size' => 9, 'zebra' => true ) );
		}
	}

	private static function type_en( $label ) {
		$map = array( 'Μονόκλινα' => 'Single', 'Δίκλινα' => 'Double', 'Τρίκλινα' => 'Triple', 'Τετράκλινα' => 'Quad', 'Μονόκλινες' => 'Single', 'Δίκλινες' => 'Double', 'Τρίκλινες' => 'Triple', 'Τετράκλινες' => 'Quad' );
		return $map[ $label ] ?? $label;
	}

	public static function build_pdf( $trip_id, $list = 'all' ) {
		$trip = is_array( $trip_id ) ? $trip_id : LGT_DB::get_trip( $trip_id );
		if ( ! $trip ) {
			return null;
		}
		$lists = 'all' === $list ? self::lists_for( $trip ) : array( $list );
		$pdf   = new LGT_PDF_Writer( 'P' );
		$pdf->set_header( LGT_Settings::get( 'company_name' ) . ( LGT_Settings::get( 'company_phone' ) ? '  |  ' . LGT_Settings::get( 'company_phone' ) : '' ) );
		$pdf->set_footer( LGT_Transliterator::to_latin( $trip['school_name'] ) . '  |  Generated ' . date( 'd/m/Y H:i' ) );
		$logo = self::logo_path();
		if ( $logo ) {
			$pdf->set_logo( $logo, 12 );
		}
		$sub = array( $trip['school_name'] );
		if ( $trip['destination'] ) {
			$sub[] = $trip['destination'];
		}
		if ( $trip['departure_date'] ) {
			$sub[] = self::fmt_date( $trip['departure_date'] ) . ( $trip['return_date'] ? ' - ' . self::fmt_date( $trip['return_date'] ) : '' );
		}
		foreach ( $lists as $l ) {
			if ( 'form' === $l ) {
				$rows = self::form_rows( $trip );
				$pdf->add_page();
				$pdf->write_line( 'NAMES LIST', 15, true );
				$pdf->write_line( implode( '   |   ', $sub ), 9.5, false );
				$pdf->write_line( count( $rows ) . ' names', 10, true );
				$pdf->ln( 2 );
				$pdf->table( array( '#', 'Surname', 'Name', 'Date of birth' ), $rows, array( 12, 70, 70, 34 ), array( 'size' => 9.5, 'zebra' => true, 'align' => array( 'C', 'L', 'L', 'C' ) ) );
			} elseif ( 'rooming' === $l && $trip['rooming'] ) {
				self::pdf_rooming_section( $pdf, $trip, $trip['rooming'], 'hotel' );
			} elseif ( 'cabins' === $l && $trip['cabins'] ) {
				self::pdf_rooming_section( $pdf, $trip, $trip['cabins'], 'cabin' );
			}
		}
		$path = self::unique( self::base_name( $trip, $list ), 'pdf' );
		file_put_contents( $path, $pdf->output() );
		return $path;
	}

	private static function logo_path() {
		$logo = LGT_Settings::get( 'company_logo' );
		if ( ! $logo ) {
			return null;
		}
		if ( file_exists( $logo ) ) {
			return $logo;
		}
		$upload = wp_upload_dir();
		if ( 0 === strpos( $logo, $upload['baseurl'] ) ) {
			$path = $upload['basedir'] . substr( $logo, strlen( $upload['baseurl'] ) );
			if ( file_exists( $path ) ) {
				return $path;
			}
		}
		return null;
	}

	public static function stream( $path, $download_name = null ) {
		if ( ! $path || ! file_exists( $path ) ) {
			wp_die( 'File not found.' );
		}
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$mime = array( 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'csv' => 'text/csv; charset=utf-8', 'pdf' => 'application/pdf' );
		nocache_headers();
		header( 'Content-Type: ' . ( $mime[ $ext ] ?? 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="' . ( $download_name ? $download_name : preg_replace( '/_[a-f0-9]{6}(\.\w+)$/', '$1', basename( $path ) ) ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path );
		@unlink( $path );
		exit;
	}
}
