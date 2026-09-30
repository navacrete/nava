<?php
/**
 * Minimal dependency-free PDF writer (Helvetica core fonts, WinAnsi).
 * Designed for tabular Latin-alphabet lists: rooming lists and manifests.
 * Supports: pages (A4 P/L), text, table with header repeat & page breaks, JPEG logo, footer.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LGT_PDF_Writer {

	private $pages       = array();
	private $current     = '';
	private $orientation = 'P';
	private $w           = 210;
	private $h           = 297;
	private $margin      = 12;
	private $x           = 12;
	private $y           = 12;
	private $font_size   = 9;
	private $font_bold   = false;
	private $line_h      = 5;
	private $images      = array(); // name => [data, w, h]
	private $logo        = null;    // [name, w_mm, h_mm]
	private $header_text = '';
	private $footer_text = '';
	private $page_count  = 0;
	private $auto_break  = true;
	private $fill_rgb    = array( 0.87, 0.92, 0.97 );

	private static $widths = array(
		32 => 278, 33 => 278, 34 => 355, 35 => 556, 36 => 556, 37 => 889, 38 => 667, 39 => 191, 40 => 333, 41 => 333, 42 => 389, 43 => 584,
		44 => 278, 45 => 333, 46 => 278, 47 => 278, 48 => 556, 49 => 556, 50 => 556, 51 => 556, 52 => 556, 53 => 556, 54 => 556, 55 => 556,
		56 => 556, 57 => 556, 58 => 278, 59 => 278, 60 => 584, 61 => 584, 62 => 584, 63 => 556, 64 => 1015, 65 => 667, 66 => 667, 67 => 722,
		68 => 722, 69 => 667, 70 => 611, 71 => 778, 72 => 722, 73 => 278, 74 => 500, 75 => 667, 76 => 556, 77 => 833, 78 => 722, 79 => 778,
		80 => 667, 81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944, 88 => 667, 89 => 667, 90 => 611, 91 => 278,
		92 => 278, 93 => 278, 94 => 469, 95 => 556, 96 => 333, 97 => 556, 98 => 556, 99 => 500, 100 => 556, 101 => 556, 102 => 278, 103 => 556,
		104 => 556, 105 => 222, 106 => 222, 107 => 500, 108 => 222, 109 => 833, 110 => 556, 111 => 556, 112 => 556, 113 => 556, 114 => 333,
		115 => 500, 116 => 278, 117 => 556, 118 => 500, 119 => 722, 120 => 500, 121 => 500, 122 => 500, 123 => 334, 124 => 260, 125 => 334, 126 => 584,
	);

	public function __construct( $orientation = 'P' ) {
		$this->orientation = 'L' === strtoupper( $orientation ) ? 'L' : 'P';
		if ( 'L' === $this->orientation ) {
			$this->w = 297;
			$this->h = 210;
		}
	}

	public function set_header( $text ) {
		$this->header_text = $text;
	}

	public function set_footer( $text ) {
		$this->footer_text = $text;
	}

	/** JPEG only. */
	public function set_logo( $path, $height_mm = 14 ) {
		if ( ! $path || ! file_exists( $path ) ) {
			return;
		}
		$info = @getimagesize( $path );
		if ( ! $info || IMAGETYPE_JPEG !== $info[2] ) {
			return;
		}
		$data = file_get_contents( $path );
		$name = 'Im1';
		$this->images[ $name ] = array( 'data' => $data, 'w' => $info[0], 'h' => $info[1], 'n' => 0 );
		$this->logo = array( 'name' => $name, 'h' => $height_mm, 'w' => $height_mm * $info[0] / max( 1, $info[1] ) );
	}

	public function add_page() {
		if ( '' !== $this->current ) {
			$this->pages[] = $this->current;
		}
		$this->current = '';
		$this->page_count++;
		$this->y = $this->margin;
		$this->x = $this->margin;
		$this->draw_page_header();
	}

	private function draw_page_header() {
		$top = $this->margin;
		if ( $this->logo ) {
			$this->image_at( $this->logo['name'], $this->margin, $top, $this->logo['w'], $this->logo['h'] );
			$this->y = $top + $this->logo['h'] + 3;
		}
		if ( '' !== $this->header_text ) {
			$this->set_font( 8, false );
			$this->text_at( $this->w - $this->margin, $top + 3, $this->header_text, 'R' );
			if ( ! $this->logo ) {
				$this->y = $top + 6;
			}
		}
		$this->y = max( $this->y, $this->margin + 2 );
	}

	private function draw_page_footer( $page_no, $total ) {
		$this->set_font( 7, false );
		$txt = ( '' !== $this->footer_text ? $this->footer_text . '   |   ' : '' ) . 'Page ' . $page_no . ' / ' . $total;
		return $this->text_cmd( $this->w - $this->margin, $this->h - 7, $txt, 'R' );
	}

	public function set_font( $size, $bold = false ) {
		$this->font_size = (float) $size;
		$this->font_bold = (bool) $bold;
		$this->line_h    = $size * 0.55;
	}

	public function ln( $h = null ) {
		$this->y += null === $h ? $this->line_h : $h;
		$this->x  = $this->margin;
	}

	public function get_y() {
		return $this->y;
	}

	public function content_width() {
		return $this->w - 2 * $this->margin;
	}

	/** Width of text in mm at current font. */
	public function text_width( $text ) {
		$text = $this->to_ansi( $text );
		$sum  = 0;
		$len  = strlen( $text );
		for ( $i = 0; $i < $len; $i++ ) {
			$o    = ord( $text[ $i ] );
			$sum += self::$widths[ $o ] ?? 556;
		}
		$factor = $this->font_bold ? 1.05 : 1.0;
		return $sum * $this->font_size * $factor / 1000 * 0.352778;
	}

	/** Simple text line at current position, moves y. */
	public function write_line( $text, $size = null, $bold = null, $align = 'L' ) {
		if ( null !== $size ) {
			$this->set_font( $size, null === $bold ? $this->font_bold : $bold );
		} elseif ( null !== $bold ) {
			$this->set_font( $this->font_size, $bold );
		}
		$this->check_break( $this->line_h + 1 );
		$x = 'R' === $align ? $this->w - $this->margin : ( 'C' === $align ? $this->w / 2 : $this->margin );
		$this->text_at( $x, $this->y + $this->line_h * 0.8, $text, $align );
		$this->y += $this->line_h + 1;
	}

	/** Wrapped paragraph. */
	public function write_paragraph( $text, $size = 9, $bold = false ) {
		$this->set_font( $size, $bold );
		$maxw  = $this->content_width();
		$words = preg_split( '/\s+/', trim( (string) $text ) );
		$line  = '';
		foreach ( $words as $w ) {
			$try = '' === $line ? $w : $line . ' ' . $w;
			if ( $this->text_width( $try ) > $maxw && '' !== $line ) {
				$this->write_line( $line );
				$line = $w;
			} else {
				$line = $try;
			}
		}
		if ( '' !== $line ) {
			$this->write_line( $line );
		}
	}

	private function check_break( $needed ) {
		if ( $this->auto_break && $this->y + $needed > $this->h - $this->margin - 6 ) {
			$this->add_page();
			return true;
		}
		return false;
	}

	/**
	 * Draw a table.
	 *
	 * @param array $header column titles
	 * @param array $rows   rows
	 * @param array $widths column widths in mm (sum ≤ content width; scaled otherwise)
	 * @param array $opts   ['size'=>8,'align'=>['L','C',...],'group_col'=>index → draw separators when value changes, 'zebra'=>bool]
	 */
	public function table( array $header, array $rows, array $widths, array $opts = array() ) {
		$size   = $opts['size'] ?? 8;
		$aligns = $opts['align'] ?? array();
		$sum    = array_sum( $widths );
		$cw     = $this->content_width();
		if ( $sum > $cw && $sum > 0 ) {
			foreach ( $widths as $i => $w ) {
				$widths[ $i ] = $w * $cw / $sum;
			}
		}
		$row_h = $size * 0.55 + 2.2;
		$draw_header = function () use ( $header, $widths, $size, $row_h ) {
			$this->set_font( $size, true );
			$x = $this->margin;
			$this->fill_rgb = array( 0.87, 0.92, 0.97 );
			foreach ( $header as $i => $h ) {
				$w = $widths[ $i ] ?? 20;
				$this->rect( $x, $this->y, $w, $row_h, true );
				$this->cell_text( $x, $this->y, $w, $row_h, $h, 'L' );
				$x += $w;
			}
			$this->y += $row_h;
		};
		$this->check_break( $row_h * 3 );
		$draw_header();
		$this->set_font( $size, false );
		$group_col = $opts['group_col'] ?? null;
		$prev      = null;
		$zebra     = ! empty( $opts['zebra'] );
		$n         = 0;
		foreach ( $rows as $row ) {
			$row = array_values( $row );
			// Row height: allow 2 lines when text overflows.
			$lines = 1;
			foreach ( $row as $i => $v ) {
				$w = ( $widths[ $i ] ?? 20 ) - 2;
				if ( $this->text_width( (string) $v ) > $w ) {
					$lines = 2;
				}
			}
			$rh = $row_h * ( 1 === $lines ? 1 : 1.8 );
			if ( $this->check_break( $rh ) ) {
				$draw_header();
				$this->set_font( $size, false );
			}
			$is_group_start = null !== $group_col && null !== $prev && ( $row[ $group_col ] ?? '' ) !== $prev;
			if ( $zebra && $n % 2 ) {
				$this->fill_rgb = array( 0.96, 0.96, 0.96 );
				$this->rect( $this->margin, $this->y, array_sum( $widths ), $rh, true );
			}
			$x = $this->margin;
			foreach ( $row as $i => $v ) {
				$w = $widths[ $i ] ?? 20;
				$this->rect( $x, $this->y, $w, $rh, false );
				$this->cell_text( $x, $this->y, $w, $row_h, (string) $v, $aligns[ $i ] ?? 'L', $lines );
				$x += $w;
			}
			if ( $is_group_start ) {
				$this->line( $this->margin, $this->y, $this->margin + array_sum( $widths ), $this->y, 0.9 );
			}
			if ( null !== $group_col ) {
				$prev = $row[ $group_col ] ?? '';
			}
			$this->y += $rh;
			$n++;
		}
		$this->y += 2;
	}

	private function cell_text( $x, $y, $w, $h, $text, $align = 'L', $lines = 1 ) {
		$text = (string) $text;
		$maxw = $w - 2;
		if ( 1 === $lines ) {
			$text = $this->truncate( $text, $maxw );
			$tx   = 'R' === $align ? $x + $w - 1 : ( 'C' === $align ? $x + $w / 2 : $x + 1 );
			$this->text_at( $tx, $y + $h * 0.72, $text, $align );
			return;
		}
		// Two lines: split by words.
		$words = preg_split( '/\s+/', $text );
		$l1    = '';
		$rest  = array();
		foreach ( $words as $i => $wd ) {
			$try = '' === $l1 ? $wd : $l1 . ' ' . $wd;
			if ( $this->text_width( $try ) <= $maxw ) {
				$l1 = $try;
			} else {
				$rest = array_slice( $words, $i );
				break;
			}
		}
		if ( '' === $l1 ) {
			$l1   = $this->truncate( $words[0], $maxw );
			$rest = array_slice( $words, 1 );
		}
		$l2 = $this->truncate( implode( ' ', $rest ), $maxw );
		$tx = 'R' === $align ? $x + $w - 1 : ( 'C' === $align ? $x + $w / 2 : $x + 1 );
		$this->text_at( $tx, $y + $h * 0.72, $l1, $align );
		$this->text_at( $tx, $y + $h * 0.72 + $this->line_h * 1.15, $l2, $align );
	}

	private function truncate( $text, $maxw ) {
		if ( $this->text_width( $text ) <= $maxw ) {
			return $text;
		}
		while ( strlen( $text ) > 1 && $this->text_width( $text . '..' ) > $maxw ) {
			$text = substr( $text, 0, -1 );
		}
		return $text . '..';
	}

	private function rect( $x, $y, $w, $h, $fill ) {
		$k = 2.834646;
		if ( $fill ) {
			$this->current .= sprintf(
				"q %.3f %.3f %.3f rg %.3f %.3f %.3f %.3f re B Q\n",
				$this->fill_rgb[0], $this->fill_rgb[1], $this->fill_rgb[2],
				$x * $k, ( $this->h - $y - $h ) * $k, $w * $k, $h * $k
			);
		} else {
			$this->current .= sprintf( "%.3f %.3f %.3f %.3f re S\n", $x * $k, ( $this->h - $y - $h ) * $k, $w * $k, $h * $k );
		}
	}

	private function line( $x1, $y1, $x2, $y2, $lw = 0.6 ) {
		$k = 2.834646;
		$this->current .= sprintf( "q %.3f w %.3f %.3f m %.3f %.3f l S Q\n", $lw, $x1 * $k, ( $this->h - $y1 ) * $k, $x2 * $k, ( $this->h - $y2 ) * $k );
	}

	private function text_at( $x, $y, $text, $align = 'L' ) {
		$this->current .= $this->text_cmd( $x, $y, $text, $align );
	}

	private function text_cmd( $x, $y, $text, $align = 'L' ) {
		$text = (string) $text;
		if ( 'R' === $align ) {
			$x -= $this->text_width( $text );
		} elseif ( 'C' === $align ) {
			$x -= $this->text_width( $text ) / 2;
		}
		$font = $this->font_bold ? '/F2' : '/F1';
		$esc  = str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $this->to_ansi( $text ) );
		return sprintf( "BT %s %.2f Tf %.3f %.3f Td (%s) Tj ET\n", $font, $this->font_size, $x * 2.834646, ( $this->h - $y ) * 2.834646, $esc );
	}

	private function image_at( $name, $x, $y, $w, $h ) {
		$this->current .= sprintf( "q %.3f 0 0 %.3f %.3f %.3f cm /%s Do Q\n", $w * 2.834646, $h * 2.834646, $x * 2.834646, ( $this->h - $y - $h ) * 2.834646, $name );
	}

	private function to_ansi( $text ) {
		$text = (string) $text;
		if ( preg_match( '//u', $text ) && preg_match( '/[^\x00-\x7F]/', $text ) ) {
			// Transliterate Greek runs only (keep digits, slashes, punctuation intact).
			if ( LGT_Transliterator::is_greek( $text ) ) {
				$text = preg_replace_callback(
					'/[\p{Greek}\x{0300}-\x{036F}]+(?:[\s\'’-]+[\p{Greek}]+)*/u',
					function ( $m ) {
						return LGT_Transliterator::to_latin( $m[0] );
					},
					$text
				);
			}
			$conv = @iconv( 'UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text );
			if ( false !== $conv ) {
				return $conv;
			}
			return preg_replace( '/[^\x20-\x7E]/', '?', $text );
		}
		return $text;
	}

	/**
	 * Build the PDF binary.
	 */
	public function output() {
		if ( '' !== $this->current || ! $this->pages ) {
			$this->pages[] = $this->current;
			$this->current = '';
		}
		$total   = count( $this->pages );
		$objects = array();
		$add     = function ( $body ) use ( &$objects ) {
			$objects[] = $body;
			return count( $objects );
		};
		$font1    = $add( '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>' );
		$font2    = $add( '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>' );
		$img_objs = array();
		foreach ( $this->images as $name => $im ) {
			$img_objs[ $name ] = $add( '<< /Type /XObject /Subtype /Image /Width ' . $im['w'] . ' /Height ' . $im['h'] . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen( $im['data'] ) . " >>\nstream\n" . $im['data'] . "\nendstream" );
		}
		$xobj_dict = '';
		foreach ( $img_objs as $name => $oid ) {
			$xobj_dict .= '/' . $name . ' ' . $oid . ' 0 R ';
		}
		$resources = '<< /Font << /F1 ' . $font1 . ' 0 R /F2 ' . $font2 . ' 0 R >> ' . ( $xobj_dict ? '/XObject << ' . $xobj_dict . '>> ' : '' ) . '/ProcSet [/PDF /Text /ImageC] >>';
		$pw        = $this->w * 2.834646;
		$ph        = $this->h * 2.834646;
		$pages_obj = $add( 'PAGES' );
		$page_ids  = array();
		foreach ( $this->pages as $i => $content ) {
			$stream     = "0.2 w 0 G 0 g\n" . $content . $this->footer_for( $i + 1, $total );
			$cid        = $add( '<< /Length ' . strlen( $stream ) . " >>\nstream\n" . $stream . "\nendstream" );
			$page_ids[] = $add( '<< /Type /Page /Parent ' . $pages_obj . ' 0 R /MediaBox [0 0 ' . sprintf( '%.2f %.2f', $pw, $ph ) . '] /Resources ' . $resources . ' /Contents ' . $cid . ' 0 R >>' );
		}
		$kids = '';
		foreach ( $page_ids as $pid ) {
			$kids .= $pid . ' 0 R ';
		}
		$objects[ $pages_obj - 1 ] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . $total . ' >>';
		$catalog = $add( '<< /Type /Catalog /Pages ' . $pages_obj . ' 0 R >>' );
		$info    = $add( '<< /Producer (LGT School Trips) /CreationDate (D:' . gmdate( 'YmdHis' ) . 'Z) >>' );

		$pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();
		foreach ( $objects as $i => $body ) {
			$offsets[] = strlen( $pdf );
			$pdf      .= ( $i + 1 ) . " 0 obj\n" . $body . "\nendobj\n";
		}
		$xref = strlen( $pdf );
		$pdf .= "xref\n0 " . ( count( $objects ) + 1 ) . "\n0000000000 65535 f \n";
		foreach ( $offsets as $o ) {
			$pdf .= sprintf( '%010d 00000 n ', $o ) . "\n";
		}
		$pdf .= 'trailer << /Size ' . ( count( $objects ) + 1 ) . ' /Root ' . $catalog . ' 0 R /Info ' . $info . " 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
		return $pdf;
	}

	private function footer_for( $page_no, $total ) {
		$saved_size = $this->font_size;
		$saved_bold = $this->font_bold;
		$cmd        = $this->draw_page_footer( $page_no, $total );
		$this->set_font( $saved_size, $saved_bold );
		return $cmd;
	}
}
