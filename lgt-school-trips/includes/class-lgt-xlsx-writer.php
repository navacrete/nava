<?php
/**
 * Dependency-free XLSX writer (multiple sheets, bold header, column widths, freeze pane).
 * Falls back to CSV when ZipArchive is unavailable.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LGT_XLSX_Writer {

	private $sheets = array();

	/**
	 * @param string $name    sheet name (max 31 chars)
	 * @param array  $header  column titles
	 * @param array  $rows    rows of scalar values
	 * @param array  $widths  optional column widths (chars)
	 * @param array  $title_rows optional rows rendered above the header (e.g. trip title)
	 */
	public function add_sheet( $name, array $header, array $rows, array $widths = array(), array $title_rows = array() ) {
		$name = preg_replace( '/[\[\]\*\?\/\\\\:]/', ' ', $name );
		$name = mb_substr( $name, 0, 31 );
		$this->sheets[] = compact( 'name', 'header', 'rows', 'widths', 'title_rows' );
	}

	public static function available() {
		return class_exists( 'ZipArchive' );
	}

	/**
	 * Write to a file. Returns the actual path (extension may become .csv on fallback).
	 */
	public function save( $path ) {
		if ( ! self::available() ) {
			return $this->save_csv( preg_replace( '/\.xlsx$/i', '', $path ) . '.csv' );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return $this->save_csv( preg_replace( '/\.xlsx$/i', '', $path ) . '.csv' );
		}
		$zip->addFromString( '[Content_Types].xml', $this->content_types() );
		$zip->addFromString( '_rels/.rels', $this->rels() );
		$zip->addFromString( 'docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>LGT School Trips</Application></Properties>' );
		$zip->addFromString( 'docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:creator>LGT School Trips</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate( 'Y-m-d\TH:i:s\Z' ) . '</dcterms:created></cp:coreProperties>' );
		$zip->addFromString( 'xl/workbook.xml', $this->workbook() );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', $this->workbook_rels() );
		$zip->addFromString( 'xl/styles.xml', $this->styles() );
		foreach ( $this->sheets as $i => $s ) {
			$zip->addFromString( 'xl/worksheets/sheet' . ( $i + 1 ) . '.xml', $this->sheet_xml( $s ) );
		}
		$zip->close();
		return $path;
	}

	private function save_csv( $path ) {
		$fh = fopen( $path, 'w' );
		fwrite( $fh, "\xEF\xBB\xBF" );
		foreach ( $this->sheets as $s ) {
			fputcsv( $fh, array( $s['name'] ), ';' );
			foreach ( $s['title_rows'] as $r ) {
				fputcsv( $fh, $r, ';' );
			}
			fputcsv( $fh, $s['header'], ';' );
			foreach ( $s['rows'] as $r ) {
				fputcsv( $fh, $r, ';' );
			}
			fputcsv( $fh, array(), ';' );
		}
		fclose( $fh );
		return $path;
	}

	private function content_types() {
		$x  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
		$x .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
		$x .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
		$x .= '<Default Extension="xml" ContentType="application/xml"/>';
		$x .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
		$x .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
		$x .= '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
		$x .= '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
		foreach ( $this->sheets as $i => $s ) {
			$x .= '<Override PartName="/xl/worksheets/sheet' . ( $i + 1 ) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
		}
		return $x . '</Types>';
	}

	private function rels() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>';
	}

	private function workbook() {
		$x  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
		$x .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
		foreach ( $this->sheets as $i => $s ) {
			$x .= '<sheet name="' . self::esc( $s['name'] ) . '" sheetId="' . ( $i + 1 ) . '" r:id="rId' . ( $i + 1 ) . '"/>';
		}
		return $x . '</sheets></workbook>';
	}

	private function workbook_rels() {
		$x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
		foreach ( $this->sheets as $i => $s ) {
			$x .= '<Relationship Id="rId' . ( $i + 1 ) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ( $i + 1 ) . '.xml"/>';
		}
		$x .= '<Relationship Id="rId' . ( count( $this->sheets ) + 1 ) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
		return $x . '</Relationships>';
	}

	private function styles() {
		// xf 0: default, 1: bold header with fill + border, 2: bold title, 3: bordered cell.
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<fonts count="3"><font><sz val="10"/><name val="Calibri"/></font><font><b/><sz val="10"/><name val="Calibri"/></font><font><b/><sz val="13"/><name val="Calibri"/></font></fonts>'
			. '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFDDEBF7"/><bgColor indexed="64"/></patternFill></fill></fills>'
			. '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFBFBFBF"/></left><right style="thin"><color rgb="FFBFBFBF"/></right><top style="thin"><color rgb="FFBFBFBF"/></top><bottom style="thin"><color rgb="FFBFBFBF"/></bottom><diagonal/></border></borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="4">'
			. '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
			. '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
			. '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
			. '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'
			. '</cellXfs>'
			. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
			. '</styleSheet>';
	}

	private function sheet_xml( array $s ) {
		$x  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
		$x .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
		$header_row = count( $s['title_rows'] ) + 1;
		$x .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . $header_row . '" topLeftCell="A' . ( $header_row + 1 ) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
		if ( $s['widths'] ) {
			$x .= '<cols>';
			foreach ( $s['widths'] as $i => $w ) {
				$x .= '<col min="' . ( $i + 1 ) . '" max="' . ( $i + 1 ) . '" width="' . (float) $w . '" customWidth="1"/>';
			}
			$x .= '</cols>';
		}
		$x .= '<sheetData>';
		$r  = 1;
		foreach ( $s['title_rows'] as $row ) {
			$x .= $this->row_xml( $r++, $row, 2 );
		}
		$x .= $this->row_xml( $r++, $s['header'], 1 );
		foreach ( $s['rows'] as $row ) {
			$x .= $this->row_xml( $r++, $row, 3 );
		}
		$x .= '</sheetData>';
		$x .= '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>';
		$x .= '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/>';
		return $x . '</worksheet>';
	}

	private function row_xml( $r, array $cells, $style ) {
		$x = '<row r="' . $r . '">';
		$c = 0;
		foreach ( $cells as $v ) {
			$ref = self::col_letter( $c++ ) . $r;
			if ( is_int( $v ) || is_float( $v ) ) {
				$x .= '<c r="' . $ref . '" s="' . $style . '"><v>' . $v . '</v></c>';
			} else {
				$v = (string) $v;
				if ( '' === $v ) {
					$x .= '<c r="' . $ref . '" s="' . $style . '"/>';
				} else {
					$x .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . self::esc( $v ) . '</t></is></c>';
				}
			}
		}
		return $x . '</row>';
	}

	public static function col_letter( $i ) {
		$s = '';
		$i = (int) $i;
		do {
			$s = chr( 65 + ( $i % 26 ) ) . $s;
			$i = intdiv( $i, 26 ) - 1;
		} while ( $i >= 0 );
		return $s;
	}

	private static function esc( $s ) {
		$s = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $s );
		return htmlspecialchars( $s, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}
}
