<?php
/**
 * Builds datasets (rooming list, cabin list, ferry & flight manifests, full list)
 * and renders them to XLSX / PDF files.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LGT_Exporter {

	const LISTS = array( 'all', 'rooming', 'cabins', 'ferry', 'flight', 'full' );

	public static function storage_dir() {
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'lgt-school-trips';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	/** Delete generated files older than a day. */
	public static function cleanup() {
		$dir = self::storage_dir();
		foreach ( glob( $dir . '/*.{xlsx,csv,pdf}', GLOB_BRACE ) as $f ) {
			if ( filemtime( $f ) < time() - DAY_IN_SECONDS ) {
				@unlink( $f );
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                            */
	/* ------------------------------------------------------------------ */

	public static function fmt_date( $d ) {
		if ( ! $d ) {
			return '';
		}
		$ts = strtotime( $d );
		return $ts ? date( 'd/m/Y', $ts ) : '';
	}

	public static function safe_filename( $s ) {
		$s = LGT_Transliterator::to_latin( $s );
		$s = preg_replace( '/[^A-Z0-9]+/', '_', $s );
		return trim( $s, '_' );
	}

	public static function cat_label( $ptype ) {
		switch ( $ptype ) {
			case 'teacher':
				return 'TEACHER';
			case 'escort':
				return 'ESCORT';
			default:
				return 'STUDENT';
		}
	}

	public static function cat_label_el( $ptype ) {
		switch ( $ptype ) {
			case 'teacher':
				return 'Καθηγητής/τρια';
			case 'escort':
				return 'Συνοδός';
			default:
				return 'Μαθητής/τρια';
		}
	}

	private static function type_label( array $types, $code, $capacity ) {
		foreach ( $types as $t ) {
			if ( $t['code'] === $code ) {
				return $t['code'];
			}
		}
		return $code ? $code : ( $capacity . '-BED' );
	}

	/**
	 * Load everything needed for exports.
	 */
	public static function load( $trip_id ) {
		$trip = is_array( $trip_id ) ? $trip_id : LGT_DB::get_trip( $trip_id );
		if ( ! $trip ) {
			return null;
		}
		$participants = LGT_DB::get_participants( $trip['id'], true );
		$by_id        = array();
		foreach ( $participants as $p ) {
			$by_id[ $p['id'] ] = $p;
		}
		$rooms       = LGT_DB::get_rooms( $trip['id'] );
		$assignments = LGT_DB::get_assignments( $trip['id'] );
		$members     = array(); // room_id => [participant]
		$room_of     = array( 'hotel' => array(), 'cabin' => array() );
		foreach ( $assignments as $a ) {
			if ( isset( $by_id[ $a['participant_id'] ] ) ) {
				$members[ $a['room_id'] ][] = $by_id[ $a['participant_id'] ];
				$room_of[ $a['kind'] ][ $a['participant_id'] ] = $a['room_id'];
			}
		}
		// Display label = type code + running number within the type (DBL-1, DBL-2, TRPL-1…),
		// matching the column layout the school sees. Rooms are ordered by type capacity.
		$cap_of = array();
		foreach ( array_merge( $trip['room_types'], $trip['cabin_types'] ) as $t ) {
			$cap_of[ $t['code'] ] = (int) $t['capacity'];
		}
		usort( $rooms, function ( $a, $b ) use ( $cap_of ) {
			if ( $a['kind'] !== $b['kind'] ) {
				return strcmp( $a['kind'], $b['kind'] );
			}
			$ca = $cap_of[ $a['type_code'] ] ?? $a['capacity'];
			$cb = $cap_of[ $b['type_code'] ] ?? $b['capacity'];
			if ( $ca !== $cb ) {
				return $ca - $cb;
			}
			if ( $a['type_code'] !== $b['type_code'] ) {
				return strcmp( $a['type_code'], $b['type_code'] );
			}
			return $a['sort_order'] - $b['sort_order'] ?: $a['id'] - $b['id'];
		} );
		$counters    = array();
		$rooms_by_id = array();
		foreach ( $rooms as $i => $r ) {
			$k              = $r['kind'] . '|' . $r['type_code'];
			$counters[ $k ] = ( $counters[ $k ] ?? 0 ) + 1;
			$r['label']     = ( $r['type_code'] ? $r['type_code'] : 'X' . $r['capacity'] ) . '-' . $counters[ $k ];
			$rooms[ $i ]    = $r;
			$rooms_by_id[ $r['id'] ] = $r;
		}
		return compact( 'trip', 'participants', 'by_id', 'rooms', 'rooms_by_id', 'members', 'room_of' );
	}

	private static function sort_participants( array $list ) {
		usort( $list, function ( $a, $b ) {
			if ( $a['ptype'] !== $b['ptype'] ) {
				return 'student' === $a['ptype'] ? 1 : -1; // staff first
			}
			return strcmp( $a['last_name_lat'] . ' ' . $a['first_name_lat'], $b['last_name_lat'] . ' ' . $b['first_name_lat'] );
		} );
		return $list;
	}

	/* ------------------------------------------------------------------ */
	/* Datasets                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Rooming list (hotel) or cabin list (ferry). One row per guest, grouped by room.
	 */
	public static function dataset_rooms( array $d, $kind ) {
		$trip   = $d['trip'];
		$types  = 'cabin' === $kind ? $trip['cabin_types'] : $trip['room_types'];
		$rows   = array();
		$n      = 0;
		$summary = array();
		$assigned_ids = array();
		foreach ( $d['rooms'] as $r ) {
			if ( $r['kind'] !== $kind ) {
				continue;
			}
			$n++;
			$label = $r['label'];
			$type  = self::type_label( $types, $r['type_code'], $r['capacity'] );
			$summary[ $type ] = ( $summary[ $type ] ?? 0 ) + 1;
			$mem = $d['members'][ $r['id'] ] ?? array();
			if ( ! $mem ) {
				$rows[] = array( $label, $type, '(EMPTY)', '', '', '', '', '' );
				continue;
			}
			foreach ( $mem as $p ) {
				$rows[] = array(
					$label,
					$type,
					$p['last_name_lat'],
					$p['first_name_lat'],
					$p['gender'],
					self::fmt_date( $p['birth_date'] ),
					self::cat_label( $p['ptype'] ),
					$r['notes'],
				);
				$assigned_ids[ $p['id'] ] = true;
			}
		}
		$unassigned = array();
		foreach ( self::sort_participants( $d['participants'] ) as $p ) {
			if ( ! isset( $assigned_ids[ $p['id'] ] ) ) {
				$unassigned[] = $p;
				$rows[]       = array( 'cabin' === $kind ? 'DECK' : '-', 'cabin' === $kind ? 'NO CABIN' : 'NOT ASSIGNED', $p['last_name_lat'], $p['first_name_lat'], $p['gender'], self::fmt_date( $p['birth_date'] ), self::cat_label( $p['ptype'] ), '' );
			}
		}
		// Order summary by capacity (SGL, DBL, TRPL, QUAD…), then code.
		$cap_of = array();
		foreach ( $types as $t ) {
			$cap_of[ $t['code'] ] = (int) $t['capacity'];
		}
		uksort( $summary, function ( $a, $b ) use ( $cap_of ) {
			$ca = $cap_of[ $a ] ?? 99;
			$cb = $cap_of[ $b ] ?? 99;
			return $ca === $cb ? strcmp( $a, $b ) : $ca - $cb;
		} );
		return array(
			'header'     => array( 'cabin' === $kind ? 'Cabin' : 'Room', 'Type', 'Surname', 'Name', 'Sex', 'Date of birth', 'Category', 'Notes' ),
			'widths_xl'  => array( 8, 10, 26, 26, 6, 14, 12, 24 ),
			'widths_pdf' => array( 14, 16, 46, 46, 10, 22, 20, 30 ),
			'rows'       => $rows,
			'summary'    => $summary,
			'unassigned' => count( $unassigned ),
			'rooms'      => $n,
		);
	}

	/**
	 * Ferry passenger manifest (EU 98/41 style): names, gender, DOB, nationality, document, cabin.
	 */
	public static function dataset_ferry( array $d ) {
		$rows = array();
		$i    = 0;
		foreach ( self::sort_participants( $d['participants'] ) as $p ) {
			$i++;
			$room_id = $d['room_of']['cabin'][ $p['id'] ] ?? 0;
			$cabin   = $room_id && isset( $d['rooms_by_id'][ $room_id ] ) ? $d['rooms_by_id'][ $room_id ]['label'] : 'DECK';
			$rows[]  = array(
				$i,
				$p['last_name_lat'],
				$p['first_name_lat'],
				$p['gender'],
				self::fmt_date( $p['birth_date'] ),
				$p['nationality'],
				$p['doc_type'],
				$p['doc_number'],
				$cabin,
				self::cat_label( $p['ptype'] ),
			);
		}
		return array(
			'header'     => array( '#', 'Surname', 'Name', 'Sex', 'Date of birth', 'Nationality', 'Doc type', 'Doc number', 'Cabin', 'Category' ),
			'widths_xl'  => array( 5, 26, 26, 6, 14, 12, 10, 16, 16, 12 ),
			'widths_pdf' => array( 9, 44, 44, 10, 22, 20, 18, 30, 30, 20 ),
			'rows'       => $rows,
		);
	}

	/**
	 * Airline manifest: title, names as in passport, DOB, PAX type, nationality, document, expiry.
	 */
	public static function dataset_flight( array $d ) {
		$trip = $d['trip'];
		$rows = array();
		$i    = 0;
		foreach ( self::sort_participants( $d['participants'] ) as $p ) {
			$i++;
			$rows[] = array(
				$i,
				LGT_Validator::title( $p['gender'], $p['birth_date'], $trip['departure_date'] ),
				$p['last_name_lat'],
				$p['first_name_lat'],
				$p['gender'],
				self::fmt_date( $p['birth_date'] ),
				LGT_Validator::pax_type( $p['birth_date'], $trip['departure_date'] ),
				$p['nationality'],
				$p['doc_type'],
				$p['doc_number'],
				self::fmt_date( $p['doc_expiry'] ),
				self::cat_label( $p['ptype'] ),
			);
		}
		return array(
			'header'     => array( '#', 'Title', 'Surname', 'Name', 'Sex', 'Date of birth', 'PAX', 'Nationality', 'Doc type', 'Doc number', 'Doc expiry', 'Category' ),
			'widths_xl'  => array( 5, 7, 26, 26, 6, 14, 6, 12, 10, 16, 12, 12 ),
			'widths_pdf' => array( 8, 12, 40, 40, 9, 20, 10, 18, 16, 28, 20, 18 ),
			'rows'       => $rows,
		);
	}

	/**
	 * Full internal list: Greek + Latin, all fields, room & cabin.
	 */
	public static function dataset_full( array $d ) {
		$rows = array();
		$i    = 0;
		foreach ( $d['participants'] as $p ) {
			$i++;
			$hr     = $d['room_of']['hotel'][ $p['id'] ] ?? 0;
			$cr     = $d['room_of']['cabin'][ $p['id'] ] ?? 0;
			$rows[] = array(
				$i,
				self::cat_label_el( $p['ptype'] ),
				$p['class_name'],
				$p['last_name'],
				$p['first_name'],
				$p['last_name_lat'],
				$p['first_name_lat'],
				$p['gender'],
				self::fmt_date( $p['birth_date'] ),
				$p['nationality'],
				$p['doc_type'],
				$p['doc_number'],
				self::fmt_date( $p['doc_expiry'] ),
				$hr && isset( $d['rooms_by_id'][ $hr ] ) ? $d['rooms_by_id'][ $hr ]['label'] : '',
				$cr && isset( $d['rooms_by_id'][ $cr ] ) ? $d['rooms_by_id'][ $cr ]['label'] : '',
				$p['phone'],
				$p['notes'],
			);
		}
		return array(
			'header'    => array( '#', 'Κατηγορία', 'Τμήμα', 'Επώνυμο', 'Όνομα', 'Surname', 'Name', 'Φύλο', 'Ημ. γέννησης', 'Εθνικότητα', 'Έγγραφο', 'Αρ. εγγράφου', 'Λήξη', 'Δωμάτιο', 'Καμπίνα', 'Τηλέφωνο', 'Σημειώσεις' ),
			'widths_xl' => array( 5, 14, 8, 22, 22, 22, 22, 6, 13, 11, 10, 16, 12, 9, 9, 14, 30 ),
			'rows'      => $rows,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Files                                                              */
	/* ------------------------------------------------------------------ */

	private static function base_name( array $trip, $list ) {
		$map  = array( 'all' => 'Lists', 'rooming' => 'Rooming_List', 'cabins' => 'Cabin_List', 'ferry' => 'Ferry_Manifest', 'flight' => 'Flight_Manifest', 'full' => 'Full_List' );
		$name = ( $map[ $list ] ?? 'List' ) . '_' . self::safe_filename( $trip['school_name'] ? $trip['school_name'] : $trip['title'] );
		if ( $trip['departure_date'] ) {
			$name .= '_' . date( 'Ymd', strtotime( $trip['departure_date'] ) );
		}
		return $name;
	}

	private static function title_rows( array $trip, $what ) {
		$rows   = array();
		$rows[] = array( LGT_Settings::get( 'company_name' ) . ' – ' . $what );
		$rows[] = array( $trip['school_name'] . ( $trip['title'] ? ' – ' . $trip['title'] : '' ) );
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

	/**
	 * Which lists apply to this trip.
	 */
	public static function lists_for( array $trip ) {
		$lists = array();
		if ( $trip['has_hotel'] ) {
			$lists[] = 'rooming';
		}
		if ( $trip['has_ferry'] ) {
			$lists[] = 'cabins';
			$lists[] = 'ferry';
		}
		if ( $trip['has_flight'] ) {
			$lists[] = 'flight';
		}
		return $lists;
	}

	/**
	 * Build an XLSX (or CSV fallback) file. Returns path.
	 */
	public static function build_xlsx( $trip_id, $list = 'all' ) {
		$d = self::load( $trip_id );
		if ( ! $d ) {
			return null;
		}
		$trip  = $d['trip'];
		$lists = 'all' === $list ? array_merge( self::lists_for( $trip ), array( 'full' ) ) : array( $list );
		$x     = new LGT_XLSX_Writer();
		foreach ( $lists as $l ) {
			switch ( $l ) {
				case 'rooming':
					$ds = self::dataset_rooms( $d, 'hotel' );
					$tr = self::title_rows( $trip, 'ROOMING LIST' . ( $trip['hotel_name'] ? ' – ' . $trip['hotel_name'] : '' ) );
					$tr[] = array( 'Rooms: ' . self::summary_text( $ds['summary'] ) . ( $ds['unassigned'] ? '  |  Not assigned: ' . $ds['unassigned'] : '' ) );
					$x->add_sheet( 'Rooming list', $ds['header'], $ds['rows'], $ds['widths_xl'], $tr );
					break;
				case 'cabins':
					$ds = self::dataset_rooms( $d, 'cabin' );
					$tr = self::title_rows( $trip, 'CABIN LIST' . ( $trip['ferry_company'] ? ' – ' . $trip['ferry_company'] : '' ) );
					$tr[] = array( 'Cabins: ' . self::summary_text( $ds['summary'] ) . ( $ds['unassigned'] ? '  |  Deck: ' . $ds['unassigned'] : '' ) );
					$x->add_sheet( 'Cabins', $ds['header'], $ds['rows'], $ds['widths_xl'], $tr );
					break;
				case 'ferry':
					$ds = self::dataset_ferry( $d );
					$x->add_sheet( 'Ferry manifest', $ds['header'], $ds['rows'], $ds['widths_xl'], self::title_rows( $trip, 'PASSENGER MANIFEST' . ( $trip['ferry_company'] ? ' – ' . $trip['ferry_company'] : '' ) ) );
					break;
				case 'flight':
					$ds = self::dataset_flight( $d );
					$x->add_sheet( 'Flight manifest', $ds['header'], $ds['rows'], $ds['widths_xl'], self::title_rows( $trip, 'FLIGHT PASSENGER LIST' . ( $trip['airline'] ? ' – ' . $trip['airline'] : '' ) ) );
					break;
				case 'full':
					$ds = self::dataset_full( $d );
					$x->add_sheet( 'Πλήρης λίστα', $ds['header'], $ds['rows'], $ds['widths_xl'], self::title_rows( $trip, 'ΠΛΗΡΗΣ ΛΙΣΤΑ ΣΥΜΜΕΤΕΧΟΝΤΩΝ' ) );
					break;
			}
		}
		$path = self::storage_dir() . '/' . self::base_name( $trip, $list ) . '_' . substr( md5( uniqid( '', true ) ), 0, 6 ) . '.xlsx';
		return $x->save( $path );
	}

	private static function summary_text( array $summary ) {
		$parts = array();
		foreach ( $summary as $code => $n ) {
			$parts[] = $n . ' x ' . $code;
		}
		return $parts ? implode( ', ', $parts ) : '-';
	}

	/**
	 * Build a PDF. Returns path.
	 */
	public static function build_pdf( $trip_id, $list = 'all' ) {
		$d = self::load( $trip_id );
		if ( ! $d ) {
			return null;
		}
		$trip  = $d['trip'];
		$lists = 'all' === $list ? self::lists_for( $trip ) : array( $list );
		if ( 'full' === $list ) {
			$lists = self::lists_for( $trip ); // the Greek full list is Excel-only
		}
		$orient = LGT_Settings::get( 'pdf_orientation', 'P' );
		$pdf    = new LGT_PDF_Writer( in_array( 'flight', $lists, true ) || in_array( 'ferry', $lists, true ) ? 'L' : $orient );
		$pdf->set_header( LGT_Settings::get( 'company_name' ) . ( LGT_Settings::get( 'company_phone' ) ? '  |  ' . LGT_Settings::get( 'company_phone' ) : '' ) );
		$pdf->set_footer( LGT_Transliterator::to_latin( $trip['school_name'] ) . '  |  Generated ' . date( 'd/m/Y H:i' ) );
		$logo = self::logo_path();
		if ( $logo ) {
			$pdf->set_logo( $logo, 12 );
		}
		$sub = array();
		if ( $trip['destination'] ) {
			$sub[] = $trip['destination'];
		}
		if ( $trip['departure_date'] ) {
			$sub[] = self::fmt_date( $trip['departure_date'] ) . ( $trip['return_date'] ? ' - ' . self::fmt_date( $trip['return_date'] ) : '' );
		}
		$sub[] = count( $d['participants'] ) . ' pax';
		$subtitle = implode( '   |   ', $sub );

		$first = true;
		foreach ( $lists as $l ) {
			$pdf->add_page();
			$first = false;
			switch ( $l ) {
				case 'rooming':
					$ds = self::dataset_rooms( $d, 'hotel' );
					$pdf->write_line( 'ROOMING LIST' . ( $trip['hotel_name'] ? ' - ' . $trip['hotel_name'] : '' ), 14, true );
					$pdf->write_line( $trip['school_name'] . ( $trip['title'] ? ' - ' . $trip['title'] : '' ), 10, true );
					$pdf->write_line( $subtitle, 9, false );
					$pdf->write_line( 'Rooms: ' . self::summary_text( $ds['summary'] ) . ( $ds['unassigned'] ? '   |   NOT ASSIGNED: ' . $ds['unassigned'] : '' ), 9, true );
					$pdf->ln( 2 );
					$pdf->table( $ds['header'], $ds['rows'], $ds['widths_pdf'], array( 'group_col' => 0, 'size' => 8.5, 'align' => array( 'C', 'C', 'L', 'L', 'C', 'C', 'L', 'L' ) ) );
					break;
				case 'cabins':
					$ds = self::dataset_rooms( $d, 'cabin' );
					$pdf->write_line( 'CABIN LIST' . ( $trip['ferry_company'] ? ' - ' . $trip['ferry_company'] : '' ), 14, true );
					$pdf->write_line( $trip['school_name'] . ( $trip['title'] ? ' - ' . $trip['title'] : '' ), 10, true );
					$pdf->write_line( $subtitle, 9, false );
					$pdf->write_line( 'Cabins: ' . self::summary_text( $ds['summary'] ) . ( $ds['unassigned'] ? '   |   DECK: ' . $ds['unassigned'] : '' ), 9, true );
					$pdf->ln( 2 );
					$pdf->table( $ds['header'], $ds['rows'], $ds['widths_pdf'], array( 'group_col' => 0, 'size' => 8.5, 'align' => array( 'C', 'C', 'L', 'L', 'C', 'C', 'L', 'L' ) ) );
					break;
				case 'ferry':
					$ds = self::dataset_ferry( $d );
					$pdf->write_line( 'PASSENGER MANIFEST' . ( $trip['ferry_company'] ? ' - ' . $trip['ferry_company'] : '' ), 14, true );
					$pdf->write_line( $trip['school_name'] . ( $trip['title'] ? ' - ' . $trip['title'] : '' ), 10, true );
					$pdf->write_line( $subtitle, 9, false );
					$pdf->ln( 2 );
					$pdf->table( $ds['header'], $ds['rows'], $ds['widths_pdf'], array( 'size' => 8, 'zebra' => true, 'align' => array( 'C', 'L', 'L', 'C', 'C', 'C', 'C', 'L', 'L', 'L' ) ) );
					break;
				case 'flight':
					$ds = self::dataset_flight( $d );
					$pdf->write_line( 'FLIGHT PASSENGER LIST' . ( $trip['airline'] ? ' - ' . $trip['airline'] : '' ), 14, true );
					$pdf->write_line( $trip['school_name'] . ( $trip['title'] ? ' - ' . $trip['title'] : '' ), 10, true );
					$pdf->write_line( $subtitle, 9, false );
					$pdf->ln( 2 );
					$pdf->table( $ds['header'], $ds['rows'], $ds['widths_pdf'], array( 'size' => 8, 'zebra' => true, 'align' => array( 'C', 'C', 'L', 'L', 'C', 'C', 'C', 'C', 'C', 'L', 'C', 'L' ) ) );
					break;
			}
		}
		if ( $first ) {
			$pdf->add_page();
			$pdf->write_line( 'No lists configured for this trip.', 11, true );
		}
		$path = self::storage_dir() . '/' . self::base_name( $trip, $list ) . '_' . substr( md5( uniqid( '', true ) ), 0, 6 ) . '.pdf';
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
		// URL → path within uploads.
		$upload = wp_upload_dir();
		if ( 0 === strpos( $logo, $upload['baseurl'] ) ) {
			$path = $upload['basedir'] . substr( $logo, strlen( $upload['baseurl'] ) );
			if ( file_exists( $path ) ) {
				return $path;
			}
		}
		$rel = ABSPATH . ltrim( wp_make_link_relative( $logo ), '/' );
		return file_exists( $rel ) ? $rel : null;
	}

	/**
	 * Stream a file to the browser and exit.
	 */
	public static function stream( $path, $download_name = null ) {
		if ( ! $path || ! file_exists( $path ) ) {
			wp_die( 'File not found.' );
		}
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$mime = array(
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'csv'  => 'text/csv; charset=utf-8',
			'pdf'  => 'application/pdf',
		);
		nocache_headers();
		header( 'Content-Type: ' . ( $mime[ $ext ] ?? 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="' . ( $download_name ? $download_name : preg_replace( '/_[a-f0-9]{6}(\.\w+)$/', '$1', basename( $path ) ) ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path );
		@unlink( $path );
		exit;
	}
}
