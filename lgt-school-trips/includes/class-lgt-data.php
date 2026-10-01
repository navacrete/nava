<?php
/**
 * Shape and sanitisation of the school's data – mirrors the two original LeGrand forms:
 *
 *  form:    [ { lastName, firstName, birthDate (dd/mm/yyyy) }, ... ]
 *  rooming: { meta: { groupName, hotelName, arrivalDate, departureDate },
 *             columns: [ { type: 2, label: "Δίκλινα", rooms: [ ["NAME","NAME"], ... ] }, ... ] }
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LGT_Data {

	const MAX_ROWS  = 400;
	const MAX_ROOMS = 120;

	public static function column_definitions( $kind = 'hotel' ) {
		if ( 'cabin' === $kind ) {
			return array(
				1 => 'Μονόκλινες',
				2 => 'Δίκλινες',
				3 => 'Τρίκλινες',
				4 => 'Τετράκλινες',
			);
		}
		return array(
			1 => 'Μονόκλινα',
			2 => 'Δίκλινα',
			3 => 'Τρίκλινα',
			4 => 'Τετράκλινα',
		);
	}

	public static function fmt_date_dmy( $ymd ) {
		if ( ! $ymd ) {
			return '';
		}
		$ts = strtotime( $ymd );
		return $ts ? date( 'd/m/Y', $ts ) : '';
	}

	/** Default (empty) rooming sheet: 4 rooms in each of Δίκλινα/Τρίκλινα/Τετράκλινα. */
	public static function default_rooming( $kind, ?array $trip = null ) {
		$defs    = self::column_definitions( $kind );
		$columns = array();
		foreach ( array( 2, 3, 4 ) as $type ) {
			$columns[] = array(
				'type'  => $type,
				'label' => $defs[ $type ],
				'rooms' => array_fill( 0, 4, array_fill( 0, $type, '' ) ),
			);
		}
		return array(
			'meta'    => array(
				'groupName'     => $trip ? trim( $trip['school_name'] . ( $trip['destination'] ? ' – ' . $trip['destination'] : '' ) ) : '',
				'hotelName'     => $trip ? ( 'cabin' === $kind ? $trip['ferry_company'] : $trip['hotel_name'] ) : '',
				'arrivalDate'   => $trip ? self::fmt_date_dmy( $trip['departure_date'] ) : '',
				'departureDate' => $trip ? self::fmt_date_dmy( $trip['return_date'] ) : '',
			),
			'columns' => $columns,
		);
	}

	public static function clean_name( $v ) {
		$v = sanitize_text_field( (string) $v );
		$v = LGT_Transliterator::to_latin( $v );
		return mb_substr( $v, 0, 80 );
	}

	/** Normalise a typed date to dd/mm/yyyy ('' when empty, original digits when invalid). */
	public static function clean_dmy( $v ) {
		$v = trim( (string) $v );
		if ( '' === $v ) {
			return '';
		}
		$ymd = LGT_DB::clean_date( $v );
		if ( $ymd ) {
			return self::fmt_date_dmy( $ymd );
		}
		return mb_substr( preg_replace( '/[^0-9\/]/', '', $v ), 0, 10 );
	}

	public static function is_valid_dmy( $v ) {
		return (bool) ( $v && preg_match( '/^\d{2}\/\d{2}\/\d{4}$/', $v ) && LGT_DB::clean_date( $v ) );
	}

	public static function sanitize_form( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( array_slice( $raw, 0, self::MAX_ROWS ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$r = array(
				'lastName'  => self::clean_name( $row['lastName'] ?? '' ),
				'firstName' => self::clean_name( $row['firstName'] ?? '' ),
				'birthDate' => self::clean_dmy( $row['birthDate'] ?? '' ),
			);
			$out[] = $r;
		}
		return $out;
	}

	public static function sanitize_rooming( $raw, $kind = 'hotel', ?array $trip = null ) {
		$defs = self::column_definitions( $kind );
		if ( ! is_array( $raw ) || empty( $raw['columns'] ) || ! is_array( $raw['columns'] ) ) {
			return self::default_rooming( $kind, $trip );
		}
		$meta = is_array( $raw['meta'] ?? null ) ? $raw['meta'] : array();
		$out  = array(
			'meta'    => array(
				'groupName'     => sanitize_text_field( mb_substr( (string) ( $meta['groupName'] ?? '' ), 0, 120 ) ),
				'hotelName'     => sanitize_text_field( mb_substr( (string) ( $meta['hotelName'] ?? '' ), 0, 120 ) ),
				'arrivalDate'   => sanitize_text_field( mb_substr( (string) ( $meta['arrivalDate'] ?? '' ), 0, 20 ) ),
				'departureDate' => sanitize_text_field( mb_substr( (string) ( $meta['departureDate'] ?? '' ), 0, 20 ) ),
			),
			'columns' => array(),
		);
		$seen = array();
		foreach ( $raw['columns'] as $col ) {
			$type = (int) ( $col['type'] ?? 0 );
			if ( ! isset( $defs[ $type ] ) || isset( $seen[ $type ] ) ) {
				continue;
			}
			$seen[ $type ] = true;
			$rooms = array();
			foreach ( array_slice( is_array( $col['rooms'] ?? null ) ? $col['rooms'] : array(), 0, self::MAX_ROOMS ) as $room ) {
				$names = array();
				for ( $i = 0; $i < $type; $i++ ) {
					$names[] = self::clean_name( is_array( $room ) ? ( $room[ $i ] ?? '' ) : '' );
				}
				$rooms[] = $names;
			}
			if ( ! $rooms ) {
				$rooms[] = array_fill( 0, $type, '' );
			}
			$out['columns'][] = array( 'type' => $type, 'label' => $defs[ $type ], 'rooms' => $rooms );
		}
		if ( ! $out['columns'] ) {
			return self::default_rooming( $kind, $trip );
		}
		usort( $out['columns'], function ( $a, $b ) { return $a['type'] - $b['type']; } );
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Summaries                                                          */
	/* ------------------------------------------------------------------ */

	/** Form rows that have at least one filled field. */
	public static function filled_rows( array $form ) {
		return array_values( array_filter( $form, function ( $r ) { return $r['lastName'] || $r['firstName'] || $r['birthDate']; } ) );
	}

	/** Rooms with at least one name, per column. */
	public static function rooming_summary( $rooming ) {
		$out = array( 'rooms' => 0, 'names' => 0, 'per_type' => array(), 'empty_beds' => 0 );
		if ( ! $rooming ) {
			return $out;
		}
		foreach ( $rooming['columns'] as $col ) {
			$n = 0;
			foreach ( $col['rooms'] as $room ) {
				$filled = count( array_filter( $room ) );
				if ( $filled ) {
					$n++;
					$out['names']      += $filled;
					$out['empty_beds'] += count( $room ) - $filled;
				}
			}
			$out['per_type'][ $col['label'] ] = $n;
			$out['rooms']                    += $n;
		}
		return $out;
	}

	public static function summary( array $trip ) {
		$rows    = self::filled_rows( $trip['form'] );
		$missing = 0;
		$bad     = 0;
		foreach ( $rows as $r ) {
			if ( ! $r['lastName'] || ! $r['firstName'] ) {
				$missing++;
			}
			if ( $r['birthDate'] && ! self::is_valid_dmy( $r['birthDate'] ) ) {
				$bad++;
			}
		}
		return array(
			'names'        => count( $rows ),
			'incomplete'   => $missing,
			'bad_dates'    => $bad,
			'no_dob'       => count( array_filter( $rows, function ( $r ) { return '' === $r['birthDate']; } ) ),
			'rooming'      => self::rooming_summary( $trip['rooming'] ),
			'cabins'       => $trip['cabins'] ? self::rooming_summary( $trip['cabins'] ) : null,
		);
	}
}
