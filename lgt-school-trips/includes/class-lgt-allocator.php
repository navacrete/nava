<?php
/**
 * Smart room / cabin allocation.
 *
 *  - partition(): splits N people into rooms of allowed sizes (min rooms, prefer larger).
 *  - auto():      builds an allocation from scratch (by gender, class and participant type).
 *  - from_groups(): re-packs existing groups (e.g. ship cabins) into another kind of
 *                   accommodation (e.g. hotel rooms), keeping people together as far as
 *                   the target capacities allow.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LGT_Allocator {

	/**
	 * Partition $n into a list of sizes drawn from $sizes.
	 *
	 * @return array{sizes:int[], underfilled:int} sizes to create; `underfilled` is the
	 *         number of empty beds in the last (partial) room, 0 when exact.
	 */
	public static function partition( $n, array $sizes ) {
		$n     = (int) $n;
		$sizes = array_values( array_unique( array_filter( array_map( 'intval', $sizes ), function ( $s ) { return $s > 0; } ) ) );
		rsort( $sizes );
		if ( $n <= 0 || ! $sizes ) {
			return array( 'sizes' => array(), 'underfilled' => 0 );
		}
		$INF    = PHP_INT_MAX;
		$best   = array_fill( 0, $n + 1, $INF );
		$choice = array_fill( 0, $n + 1, 0 );
		$best[0] = 0;
		for ( $k = 1; $k <= $n; $k++ ) {
			foreach ( $sizes as $s ) {
				if ( $s <= $k && $best[ $k - $s ] !== $INF && $best[ $k - $s ] + 1 < $best[ $k ] ) {
					$best[ $k ]   = $best[ $k - $s ] + 1;
					$choice[ $k ] = $s;
				}
			}
		}
		$reconstruct = function ( $k ) use ( $choice ) {
			$out = array();
			while ( $k > 0 ) {
				$out[] = $choice[ $k ];
				$k    -= $choice[ $k ];
			}
			rsort( $out );
			return $out;
		};
		if ( $best[ $n ] !== $INF ) {
			return array( 'sizes' => $reconstruct( $n ), 'underfilled' => 0 );
		}
		// Not exactly partitionable: take the largest m < n that is, plus one partial room.
		for ( $m = $n - 1; $m >= 0; $m-- ) {
			if ( $best[ $m ] !== $INF ) {
				$rest = $n - $m;
				// Smallest size that can hold the remainder.
				$cap = null;
				foreach ( array_reverse( $sizes ) as $s ) {
					if ( $s >= $rest ) {
						$cap = $s;
						break;
					}
				}
				if ( null === $cap ) {
					$cap = $sizes[0];
				}
				$list   = $reconstruct( $m );
				$list[] = $cap;
				return array( 'sizes' => $list, 'underfilled' => $cap - $rest );
			}
		}
		return array( 'sizes' => array( $sizes[0] ), 'underfilled' => $sizes[0] - $n );
	}

	/** Map capacity → type code using the trip's type list (first match). */
	public static function type_for_capacity( array $types, $cap ) {
		foreach ( $types as $t ) {
			if ( (int) $t['capacity'] === (int) $cap ) {
				return $t['code'];
			}
		}
		return 'X' . (int) $cap;
	}

	public static function capacities( array $types ) {
		$caps = array();
		foreach ( $types as $t ) {
			$caps[] = (int) $t['capacity'];
		}
		return array_values( array_unique( $caps ) );
	}

	private static function pool_key( array $p, $by_class ) {
		$grp = ( 'student' === $p['ptype'] ) ? 'S' : 'T';
		$g   = $p['gender'] ? $p['gender'] : 'U';
		$c   = $by_class ? trim( (string) $p['class_name'] ) : '';
		return $grp . '|' . $g . '|' . $c;
	}

	/**
	 * Build rooms for a list of pools, merging leftovers per gender.
	 *
	 * @param array $pools     key => [participant ids]
	 * @param array $types     allowed types (code/label/capacity)
	 * @param array $staff_types allowed types for staff (teachers/escorts)
	 * @return array rooms
	 */
	private static function pack_pools( array $pools, array $types, array $staff_types ) {
		$rooms     = array();
		$leftovers = array(); // merge key (grp|gender) => ids

		// Order pools: staff last, then by class, then gender – so rooms come out grouped per class.
		uksort( $pools, function ( $a, $b ) {
			$pa = explode( '|', $a );
			$pb = explode( '|', $b );
			if ( $pa[0] !== $pb[0] ) {
				return 'S' === $pa[0] ? -1 : 1;
			}
			$c = strnatcasecmp( $pa[2] ?? '', $pb[2] ?? '' );
			if ( 0 !== $c ) {
				return $c;
			}
			return strcmp( $pa[1], $pb[1] );
		} );

		$student_types = array_values( array_filter( $types, function ( $t ) { return (int) $t['capacity'] >= 2; } ) );
		if ( ! $student_types ) {
			$student_types = $types;
		}

		foreach ( $pools as $key => $ids ) {
			list( $grp, $gender ) = explode( '|', $key );
			$use   = ( 'T' === $grp ) ? $staff_types : $student_types;
			$caps  = self::capacities( $use );
			$part  = self::partition( count( $ids ), $caps );
			$sizes = $part['sizes'];
			if ( $part['underfilled'] > 0 ) {
				// Drop the partial room: its people go to the leftover pool of their gender.
				array_pop( $sizes );
				$n_keep = array_sum( $sizes );
				$rest   = array_splice( $ids, $n_keep );
				$mk     = $grp . '|' . $gender;
				$leftovers[ $mk ] = array_merge( $leftovers[ $mk ] ?? array(), $rest );
			}
			foreach ( $sizes as $s ) {
				$members = array_splice( $ids, 0, $s );
				$rooms[] = array(
					'type_code' => self::type_for_capacity( $use, $s ),
					'capacity'  => $s,
					'members'   => $members,
					'_pool'     => $grp . '|' . $gender,
				);
			}
		}

		// Second pass: leftovers merged across classes (same gender / group). If they still
		// do not fit exactly, borrow one or two already-built rooms of the same gender and
		// re-pack them together (e.g. 1 leftover girl + a double → a triple).
		foreach ( $leftovers as $mk => $ids ) {
			list( $grp ) = explode( '|', $mk );
			$use  = ( 'T' === $grp ) ? $staff_types : $student_types;
			$caps = self::capacities( $use );
			$part = self::partition( count( $ids ), $caps );
			if ( $part['underfilled'] > 0 ) {
				$candidates = array();
				foreach ( $rooms as $idx => $r ) {
					if ( $r['_pool'] === $mk ) {
						$candidates[] = $idx;
					}
				}
				// Smallest rooms first: less disruption.
				usort( $candidates, function ( $a, $b ) use ( $rooms ) {
					return count( $rooms[ $a ]['members'] ) - count( $rooms[ $b ]['members'] );
				} );
				$borrowed = array();
				foreach ( $candidates as $idx ) {
					$borrowed[] = $idx;
					$n = count( $ids );
					foreach ( $borrowed as $bi ) {
						$n += count( $rooms[ $bi ]['members'] );
					}
					$try = self::partition( $n, $caps );
					if ( 0 === $try['underfilled'] ) {
						foreach ( $borrowed as $bi ) {
							$ids = array_merge( $ids, $rooms[ $bi ]['members'] );
							unset( $rooms[ $bi ] );
						}
						$part = $try;
						break;
					}
					if ( count( $borrowed ) >= 2 ) {
						break;
					}
				}
				// Still not exact (e.g. a single student): allow every type, singles included.
				if ( $part['underfilled'] > 0 && 'T' !== $grp && count( self::capacities( $types ) ) > count( $caps ) ) {
					$use  = $types;
					$part = self::partition( count( $ids ), self::capacities( $use ) );
				}
			}
			foreach ( $part['sizes'] as $s ) {
				$members = array_splice( $ids, 0, $s );
				$rooms[] = array(
					'type_code' => self::type_for_capacity( $use, $s ),
					'capacity'  => $s,
					'members'   => $members,
					'notes'     => count( $members ) < $s ? 'underfilled' : '',
					'_pool'     => $mk,
				);
			}
		}
		$out = array();
		foreach ( $rooms as $r ) {
			unset( $r['_pool'] );
			$out[] = $r;
		}
		return $out;
	}

	/**
	 * Allocate from scratch.
	 *
	 * @param array $participants active participants
	 * @param array $types        allowed room/cabin types
	 * @param bool  $by_class     keep classes together where possible
	 */
	public static function auto( array $participants, array $types, $by_class = true ) {
		$staff_types = array_values( array_filter( $types, function ( $t ) { return (int) $t['capacity'] <= 2; } ) );
		if ( ! $staff_types ) {
			$staff_types = $types;
		}
		// Sort: students by class then surname, staff by surname.
		usort( $participants, function ( $a, $b ) {
			$c = strcmp( $a['class_name'], $b['class_name'] );
			if ( 0 !== $c ) {
				return $c;
			}
			return strcmp( $a['last_name_lat'] . $a['first_name_lat'], $b['last_name_lat'] . $b['first_name_lat'] );
		} );
		$pools = array();
		foreach ( $participants as $p ) {
			$pools[ self::pool_key( $p, $by_class ) ][] = (int) $p['id'];
		}
		ksort( $pools );
		return self::pack_pools( $pools, $types, $staff_types );
	}

	/**
	 * Re-pack existing groups (arrays of participant ids, e.g. ship cabins) into
	 * accommodation of another kind while keeping the groups together.
	 *
	 * @param array $groups        list of [ids]
	 * @param array $participants  id => participant
	 * @param array $types         allowed target types
	 * @param bool  $include_unassigned add participants not in any group as singles
	 */
	public static function from_groups( array $groups, array $participants, array $types, $include_unassigned = true ) {
		$caps        = self::capacities( $types );
		$max         = $caps ? max( $caps ) : 4;
		$staff_types = array_values( array_filter( $types, function ( $t ) { return (int) $t['capacity'] <= 2; } ) );
		if ( ! $staff_types ) {
			$staff_types = $types;
		}
		$rooms     = array();
		$leftovers = array(); // pool key => ids
		$seen      = array();

		foreach ( $groups as $ids ) {
			$ids = array_values( array_filter( array_map( 'intval', $ids ), function ( $id ) use ( $participants, &$seen ) {
				if ( ! isset( $participants[ $id ] ) || isset( $seen[ $id ] ) ) {
					return false;
				}
				$seen[ $id ] = true;
				return true;
			} ) );
			if ( ! $ids ) {
				continue;
			}
			$is_staff = 'student' !== $participants[ $ids[0] ]['ptype'];
			$use      = $is_staff ? $staff_types : $types;
			$ucaps    = self::capacities( $use );
			$n        = count( $ids );
			if ( in_array( $n, $ucaps, true ) ) {
				$rooms[] = array( 'type_code' => self::type_for_capacity( $use, $n ), 'capacity' => $n, 'members' => $ids );
				continue;
			}
			$part = self::partition( $n, $ucaps );
			$sizes = $part['sizes'];
			if ( $part['underfilled'] > 0 ) {
				array_pop( $sizes );
			}
			foreach ( $sizes as $s ) {
				$members = array_splice( $ids, 0, $s );
				$rooms[] = array( 'type_code' => self::type_for_capacity( $use, $s ), 'capacity' => $s, 'members' => $members );
			}
			// Remainder (people whose group could not be fitted exactly) → leftovers by gender.
			foreach ( $ids as $id ) {
				$leftovers[ self::pool_key( $participants[ $id ], false ) ][] = $id;
			}
		}

		if ( $include_unassigned ) {
			foreach ( $participants as $id => $p ) {
				if ( ! isset( $seen[ $id ] ) ) {
					$leftovers[ self::pool_key( $p, false ) ][] = (int) $id;
				}
			}
		}
		if ( $leftovers ) {
			ksort( $leftovers );
			$rooms = array_merge( $rooms, self::pack_pools( $leftovers, $types, $staff_types ) );
		}
		return $rooms;
	}

	/**
	 * Summary of a set of rooms: count per type code.
	 */
	public static function summary( array $rooms ) {
		$out = array();
		foreach ( $rooms as $r ) {
			$k = $r['type_code'] ? $r['type_code'] : ( 'X' . $r['capacity'] );
			$out[ $k ] = ( $out[ $k ] ?? 0 ) + 1;
		}
		ksort( $out );
		return $out;
	}
}
