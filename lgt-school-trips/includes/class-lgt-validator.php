<?php
/**
 * Completeness rules per transport type (hotel / ferry / flight).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LGT_Validator {

	public static function field_labels() {
		return array(
			'last_name'      => 'Επώνυμο',
			'first_name'     => 'Όνομα',
			'last_name_lat'  => 'Επώνυμο (λατινικά)',
			'first_name_lat' => 'Όνομα (λατινικά)',
			'gender'         => 'Φύλο',
			'birth_date'     => 'Ημ. γέννησης',
			'nationality'    => 'Εθνικότητα',
			'doc_type'       => 'Τύπος εγγράφου',
			'doc_number'     => 'Αρ. εγγράφου',
			'doc_expiry'     => 'Λήξη εγγράφου',
			'class_name'     => 'Τμήμα',
		);
	}

	/** Required field keys for a trip, keyed by transport. */
	public static function required_fields( array $trip ) {
		$req = array( 'last_name', 'first_name', 'last_name_lat', 'first_name_lat', 'gender' );
		if ( $trip['has_ferry'] ) {
			$req = array_merge( $req, array( 'birth_date', 'nationality' ) );
			if ( LGT_Settings::get( 'ferry_doc_required' ) ) {
				$req[] = 'doc_number';
			}
		}
		if ( $trip['has_flight'] ) {
			$req = array_merge( $req, array( 'birth_date', 'nationality', 'doc_type', 'doc_number', 'doc_expiry' ) );
		}
		return array_values( array_unique( $req ) );
	}

	/**
	 * @return array{missing:string[], warnings:string[]}
	 */
	public static function check( array $trip, array $p ) {
		$missing  = array();
		$warnings = array();
		foreach ( self::required_fields( $trip ) as $f ) {
			$v = $p[ $f ] ?? '';
			if ( null === $v || '' === trim( (string) $v ) ) {
				$missing[] = $f;
			}
		}
		if ( ! empty( $p['birth_date'] ) ) {
			if ( strtotime( $p['birth_date'] ) > time() ) {
				$warnings[] = 'Η ημερομηνία γέννησης είναι στο μέλλον.';
			}
		}
		if ( ! empty( $p['doc_expiry'] ) && ! empty( $trip['return_date'] ) ) {
			if ( strtotime( $p['doc_expiry'] ) < strtotime( $trip['return_date'] ) ) {
				$warnings[] = 'Το έγγραφο λήγει πριν την επιστροφή.';
			} elseif ( $trip['has_flight'] && strtotime( $p['doc_expiry'] ) < strtotime( $trip['return_date'] . ' +3 months' ) && 'PASSPORT' === $p['doc_type'] ) {
				$warnings[] = 'Διαβατήριο με ισχύ < 3 μήνες μετά την επιστροφή.';
			}
		}
		foreach ( array( 'last_name_lat', 'first_name_lat' ) as $f ) {
			if ( ! empty( $p[ $f ] ) && preg_match( '/[^A-Z0-9 \-\']/', $p[ $f ] ) ) {
				$warnings[] = 'Λατινικό όνομα με μη έγκυρους χαρακτήρες.';
				break;
			}
		}
		return array( 'missing' => $missing, 'warnings' => $warnings );
	}

	/**
	 * Age at a given date (Y-m-d) → int|null.
	 */
	public static function age_at( $birth_date, $at_date ) {
		if ( ! $birth_date ) {
			return null;
		}
		try {
			$b = new DateTime( $birth_date );
			$a = new DateTime( $at_date ? $at_date : 'today' );
			return (int) $b->diff( $a )->y;
		} catch ( Exception $e ) {
			return null;
		}
	}

	/** Airline passenger type: ADT / CHD / INF. */
	public static function pax_type( $birth_date, $at_date ) {
		$age = self::age_at( $birth_date, $at_date );
		if ( null === $age ) {
			return '';
		}
		if ( $age < 2 ) {
			return 'INF';
		}
		if ( $age < 12 ) {
			return 'CHD';
		}
		return 'ADT';
	}

	/** Airline title: MR / MS / MSTR / MISS. */
	public static function title( $gender, $birth_date, $at_date ) {
		$age = self::age_at( $birth_date, $at_date );
		if ( 'M' === $gender ) {
			return ( null !== $age && $age < 12 ) ? 'MSTR' : 'MR';
		}
		if ( 'F' === $gender ) {
			return ( null !== $age && $age < 12 ) ? 'MISS' : 'MS';
		}
		return '';
	}

	/**
	 * Trip-level summary: counts, missing per participant, unassigned etc.
	 */
	public static function trip_summary( array $trip, array $participants, array $rooms, array $assignments ) {
		$active   = array_filter( $participants, function ( $p ) { return 'active' === $p['status']; } );
		$students = 0;
		$staff    = 0;
		$male     = 0;
		$female   = 0;
		$issues   = array();
		foreach ( $active as $p ) {
			if ( 'student' === $p['ptype'] ) {
				$students++;
			} else {
				$staff++;
			}
			if ( 'M' === $p['gender'] ) {
				$male++;
			} elseif ( 'F' === $p['gender'] ) {
				$female++;
			}
			$c = self::check( $trip, $p );
			if ( $c['missing'] || $c['warnings'] ) {
				$issues[ $p['id'] ] = $c;
			}
		}
		$assigned = array( 'hotel' => array(), 'cabin' => array() );
		foreach ( $assignments as $a ) {
			$assigned[ $a['kind'] ][ $a['participant_id'] ] = $a['room_id'];
		}
		$out = array(
			'total'         => count( $active ),
			'students'      => $students,
			'staff'         => $staff,
			'male'          => $male,
			'female'        => $female,
			'cancelled'     => count( $participants ) - count( $active ),
			'issues'        => $issues,
			'issue_count'   => count( $issues ),
			'hotel_unassigned' => 0,
			'cabin_unassigned' => 0,
			'hotel_rooms'   => 0,
			'cabin_rooms'   => 0,
			'hotel_summary' => array(),
			'cabin_summary' => array(),
			'overfilled'    => array(),
		);
		foreach ( $active as $p ) {
			if ( $trip['has_hotel'] && ! isset( $assigned['hotel'][ $p['id'] ] ) ) {
				$out['hotel_unassigned']++;
			}
			if ( $trip['has_ferry'] && ! isset( $assigned['cabin'][ $p['id'] ] ) ) {
				$out['cabin_unassigned']++;
			}
		}
		$occupancy = array();
		foreach ( $assignments as $a ) {
			$occupancy[ $a['room_id'] ] = ( $occupancy[ $a['room_id'] ] ?? 0 ) + 1;
		}
		foreach ( $rooms as $r ) {
			$k = $r['kind'];
			$out[ $k . '_rooms' ]++;
			$code = $r['type_code'] ? $r['type_code'] : 'X' . $r['capacity'];
			$out[ $k . '_summary' ][ $code ] = ( $out[ $k . '_summary' ][ $code ] ?? 0 ) + 1;
			if ( ( $occupancy[ $r['id'] ] ?? 0 ) > $r['capacity'] ) {
				$out['overfilled'][] = $r['id'];
			}
		}
		return $out;
	}
}
