<?php
/**
 * Greek → Latin transliteration following ELOT 743 (the standard used on Greek
 * passports / IDs), so rooming lists and manifests match travel documents.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LGT_Transliterator {

	private static $map = array(
		'α' => 'a', 'β' => 'v', 'γ' => 'g', 'δ' => 'd', 'ε' => 'e', 'ζ' => 'z', 'η' => 'i',
		'θ' => 'th', 'ι' => 'i', 'κ' => 'k', 'λ' => 'l', 'μ' => 'm', 'ν' => 'n', 'ξ' => 'x',
		'ο' => 'o', 'π' => 'p', 'ρ' => 'r', 'σ' => 's', 'ς' => 's', 'τ' => 't', 'υ' => 'y',
		'φ' => 'f', 'χ' => 'ch', 'ψ' => 'ps', 'ω' => 'o',
	);

	private static $accents = array(
		'ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ώ' => 'ω',
		'ϊ' => 'ι', 'ϋ' => 'υ', 'ΐ' => 'ι', 'ΰ' => 'υ', 'ς' => 'σ',
		// Polytonic / rare variants.
		'ᾶ' => 'α', 'ῆ' => 'η', 'ῖ' => 'ι', 'ῦ' => 'υ', 'ῶ' => 'ω', 'ἀ' => 'α', 'ἁ' => 'α',
		'ἐ' => 'ε', 'ἑ' => 'ε', 'ἠ' => 'η', 'ἡ' => 'η', 'ἰ' => 'ι', 'ἱ' => 'ι', 'ὀ' => 'ο',
		'ὁ' => 'ο', 'ὐ' => 'υ', 'ὑ' => 'υ', 'ὠ' => 'ω', 'ὡ' => 'ω',
	);

	private static $diaeresis = array( 'ϊ', 'ϋ', 'ΐ', 'ΰ' );

	private static $vowels = array( 'α', 'ε', 'η', 'ι', 'ο', 'υ', 'ω' );

	private static $voiced = array( 'β', 'γ', 'δ', 'ζ', 'λ', 'μ', 'ν', 'ρ' );

	/**
	 * Transliterate to UPPERCASE Latin.
	 */
	public static function to_latin( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return '';
		}
		if ( ! preg_match( '/\p{Greek}/u', $text ) ) {
			return self::latin_clean( $text );
		}
		$parts = preg_split( '/(\s+|-|\'|’)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		$out   = '';
		foreach ( $parts as $part ) {
			if ( preg_match( '/^(\s+|-|\'|’)$/u', $part ) ) {
				$out .= preg_match( '/^\s+$/u', $part ) ? ' ' : ( '-' === $part ? '-' : "'" );
				continue;
			}
			$out .= self::word( $part );
		}
		$out = preg_replace( '/\s+/', ' ', $out );
		return self::latin_clean( $out );
	}

	private static function base( $c ) {
		if ( isset( self::$accents[ $c ] ) ) {
			return self::$accents[ $c ];
		}
		return $c;
	}

	private static function word( $w ) {
		$lw    = mb_strtolower( $w, 'UTF-8' );
		$chars = preg_split( '//u', $lw, -1, PREG_SPLIT_NO_EMPTY );
		$n     = count( $chars );
		$out   = '';
		for ( $i = 0; $i < $n; $i++ ) {
			$c    = $chars[ $i ];
			$b    = self::base( $c );
			$nc   = $i + 1 < $n ? $chars[ $i + 1 ] : '';
			$next = '' !== $nc ? self::base( $nc ) : '';
			$ndia = in_array( $nc, self::$diaeresis, true );

			// αυ / ευ / ηυ → av/ev/iv before vowels & voiced consonants, af/ef/if otherwise.
			if ( in_array( $b, array( 'α', 'ε', 'η' ), true ) && 'υ' === $next && ! $ndia ) {
				$after  = $i + 2 < $n ? self::base( $chars[ $i + 2 ] ) : '';
				$voiced = '' !== $after && ( in_array( $after, self::$vowels, true ) || in_array( $after, self::$voiced, true ) );
				$out   .= self::$map[ $b ] . ( $voiced ? 'v' : 'f' );
				$i++;
				continue;
			}
			if ( 'ο' === $b && 'υ' === $next && ! $ndia ) {
				$out .= 'ou';
				$i++;
				continue;
			}
			if ( 'γ' === $b ) {
				if ( 'γ' === $next ) { $out .= 'ng'; $i++; continue; }
				if ( 'κ' === $next ) { $out .= 'gk'; $i++; continue; }
				if ( 'ξ' === $next ) { $out .= 'nx'; $i++; continue; }
				if ( 'χ' === $next ) { $out .= 'nch'; $i++; continue; }
			}
			if ( 'μ' === $b && 'π' === $next ) {
				$out .= ( 0 === $i ) ? 'b' : 'mp';
				$i++;
				continue;
			}
			if ( 'ν' === $b && 'τ' === $next ) {
				$out .= 'nt';
				$i++;
				continue;
			}
			if ( isset( self::$map[ $b ] ) ) {
				$out .= self::$map[ $b ];
			} else {
				$out .= $c;
			}
		}
		return $out;
	}

	/**
	 * Uppercase, strip Latin diacritics, keep only A-Z, digits, space, hyphen, apostrophe.
	 */
	public static function latin_clean( $text ) {
		$text = (string) $text;
		if ( class_exists( 'Normalizer' ) ) {
			$norm = Normalizer::normalize( $text, Normalizer::FORM_D );
			if ( false !== $norm ) {
				$text = preg_replace( '/\p{Mn}+/u', '', $norm );
			}
		} elseif ( function_exists( 'iconv' ) ) {
			$conv = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $text );
			if ( false !== $conv ) {
				$text = $conv;
			}
		}
		$text = strtr( $text, array( 'ß' => 'ss', 'Æ' => 'AE', 'æ' => 'ae', 'Ø' => 'O', 'ø' => 'o', 'Œ' => 'OE', 'œ' => 'oe', 'Đ' => 'D', 'đ' => 'd', 'Ł' => 'L', 'ł' => 'l' ) );
		$text = mb_strtoupper( $text, 'UTF-8' );
		$text = preg_replace( '/[^A-Z0-9 \-\']/', '', $text );
		$text = preg_replace( '/\s+/', ' ', $text );
		return trim( $text );
	}

	/**
	 * Document numbers: Greek ID letters are Latin look-alikes (ΑΚ 123456 → AK123456).
	 * Uses homoglyph mapping (not ELOT), strips spaces, uppercases.
	 */
	public static function doc_number( $text ) {
		$text = mb_strtoupper( trim( (string) $text ), 'UTF-8' );
		$text = strtr( $text, array( 'Α' => 'A', 'Β' => 'B', 'Ε' => 'E', 'Ζ' => 'Z', 'Η' => 'H', 'Ι' => 'I', 'Κ' => 'K', 'Μ' => 'M', 'Ν' => 'N', 'Ο' => 'O', 'Ρ' => 'P', 'Τ' => 'T', 'Υ' => 'Y', 'Χ' => 'X', 'Ά' => 'A', 'Έ' => 'E', 'Ή' => 'H', 'Ί' => 'I', 'Ό' => 'O', 'Ύ' => 'Y' ) );
		return preg_replace( '/[\s\.\-\/]+/', '', $text );
	}

	/** Does the text contain Greek letters? */
	public static function is_greek( $text ) {
		return (bool) preg_match( '/\p{Greek}/u', (string) $text );
	}
}
