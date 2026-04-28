<?php
/**
 * SimpleXlsxWriter
 * Pure-PHP, zero-dependency XLSX writer for the Umpire Scheduler plugin.
 * Supports: strings, numbers, bold headers, column widths, basic cell styles.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SimpleXlsxWriter {

    private $sheets     = [];
    private $sheetNames = [];
    private $strings    = [];
    private $stringMap  = [];
    private $styles     = [];
    private $styleMap   = [];

    // Built-in style indices
    const STYLE_NORMAL     = 0;
    const STYLE_BOLD       = 1;
    const STYLE_HEADER     = 2; // bold + dark bg + white text
    const STYLE_MONEY      = 3; // number format
    const STYLE_SECTION    = 4; // bold + light blue bg
    const STYLE_TOTAL      = 5; // bold + light grey bg

    public function __construct() {
        // Pre-register built-in styles — order must match STYLE_* constants
        $this->styles = [
            // 0 — normal
            [ 'font' => [], 'fill' => '', 'numFmt' => 0, 'align' => '' ],
            // 1 — bold
            [ 'font' => [ 'bold' => true ], 'fill' => '', 'numFmt' => 0, 'align' => '' ],
            // 2 — header (bold, navy bg, white fg)
            [ 'font' => [ 'bold' => true, 'color' => 'FFFFFFFF' ], 'fill' => 'FF091B33', 'numFmt' => 0, 'align' => 'center' ],
            // 3 — money (2 decimal places)
            [ 'font' => [], 'fill' => '', 'numFmt' => 164, 'align' => '' ],
            // 4 — section (bold, light blue bg)
            [ 'font' => [ 'bold' => true, 'color' => 'FF091B33' ], 'fill' => 'FFD6E4F0', 'numFmt' => 0, 'align' => '' ],
            // 5 — total (bold, light grey bg)
            [ 'font' => [ 'bold' => true ], 'fill' => 'FFF0F0F0', 'numFmt' => 0, 'align' => '' ],
        ];
    }

    // ── Public API ────────────────────────────────────────────

    public function addSheet( $name ) {
        $idx                  = count( $this->sheets );
        $this->sheetNames[]   = $name;
        $this->sheets[$idx]   = [ 'rows' => [], 'colWidths' => [] ];
        return $idx;
    }

    /**
     * Write a row to a sheet.
     * $cells = array of [ value, style_index, type ] or just scalar values
     * type: 's' = string (default), 'n' = number
     */
    public function writeRow( $sheetIdx, array $cells, $defaultStyle = self::STYLE_NORMAL ) {
        $row = [];
        foreach ( $cells as $cell ) {
            if ( is_array( $cell ) ) {
                $val   = $cell[0] ?? '';
                $style = $cell[1] ?? $defaultStyle;
                $type  = $cell[2] ?? ( is_numeric( $val ) ? 'n' : 's' );
            } else {
                $val   = $cell;
                $style = $defaultStyle;
                $type  = is_numeric( $val ) ? 'n' : 's';
            }
            $row[] = [ 'v' => $val, 's' => $style, 't' => $type ];
        }
        $this->sheets[$sheetIdx]['rows'][] = $row;
    }

    public function setColWidths( $sheetIdx, array $widths ) {
        $this->sheets[$sheetIdx]['colWidths'] = $widths;
    }

    public function writeBlankRow( $sheetIdx ) {
        $this->sheets[$sheetIdx]['rows'][] = [];
    }

    // ── Generate and stream ───────────────────────────────────

    public function download( $filename ) {
        $zip = $this->buildZip();
        header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . strlen( $zip ) );
        header( 'Cache-Control: max-age=0' );
        echo $zip;
        exit;
    }

    // ── Internal build ────────────────────────────────────────

    private function buildZip() {
        // Collect all shared strings
        $this->strings   = [];
        $this->stringMap = [];
        foreach ( $this->sheets as $sheet ) {
            foreach ( $sheet['rows'] as $row ) {
                foreach ( $row as $cell ) {
                    if ( $cell['t'] === 's' && $cell['v'] !== '' ) {
                        $this->internString( (string) $cell['v'] );
                    }
                }
            }
        }

        $files = [];
        $files['[Content_Types].xml']          = $this->buildContentTypes();
        $files['_rels/.rels']                  = $this->buildRels();
        $files['xl/workbook.xml']              = $this->buildWorkbook();
        $files['xl/_rels/workbook.xml.rels']   = $this->buildWorkbookRels();
        $files['xl/styles.xml']                = $this->buildStyles();
        $files['xl/sharedStrings.xml']         = $this->buildSharedStrings();

        foreach ( $this->sheets as $idx => $sheet ) {
            $files[ 'xl/worksheets/sheet' . ( $idx + 1 ) . '.xml' ] = $this->buildSheet( $sheet );
        }

        return $this->zipFiles( $files );
    }

    private function internString( $s ) {
        if ( ! isset( $this->stringMap[$s] ) ) {
            $this->stringMap[$s] = count( $this->strings );
            $this->strings[]     = $s;
        }
        return $this->stringMap[$s];
    }

    private function colLetter( $n ) {
        $letter = '';
        while ( $n >= 0 ) {
            $letter = chr( $n % 26 + 65 ) . $letter;
            $n      = intval( $n / 26 ) - 1;
        }
        return $letter;
    }

    private function buildSheet( $sheet ) {
        $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        // Column widths
        if ( ! empty( $sheet['colWidths'] ) ) {
            $xml .= '<cols>';
            foreach ( $sheet['colWidths'] as $i => $w ) {
                $col  = $i + 1;
                $xml .= '<col min="' . $col . '" max="' . $col . '" width="' . $w . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';
        foreach ( $sheet['rows'] as $rowIdx => $row ) {
            if ( empty( $row ) ) {
                $xml .= '<row r="' . ( $rowIdx + 1 ) . '"/>';
                continue;
            }
            $xml .= '<row r="' . ( $rowIdx + 1 ) . '">';
            foreach ( $row as $colIdx => $cell ) {
                $ref = $this->colLetter( $colIdx ) . ( $rowIdx + 1 );
                $s   = $cell['s'];
                if ( $cell['t'] === 'n' && $cell['v'] !== '' ) {
                    $xml .= '<c r="' . $ref . '" s="' . $s . '"><v>' . $cell['v'] . '</v></c>';
                } elseif ( $cell['v'] !== '' ) {
                    $si   = $this->internString( (string) $cell['v'] );
                    $xml .= '<c r="' . $ref . '" t="s" s="' . $s . '"><v>' . $si . '</v></c>';
                } else {
                    $xml .= '<c r="' . $ref . '" s="' . $s . '"/>';
                }
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData></worksheet>';
        return $xml;
    }

    private function buildSharedStrings() {
        $count = count( $this->strings );
        $xml   = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml  .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $count . '" uniqueCount="' . $count . '">';
        foreach ( $this->strings as $s ) {
            $xml .= '<si><t xml:space="preserve">' . htmlspecialchars( $s, ENT_XML1, 'UTF-8' ) . '</t></si>';
        }
        $xml .= '</sst>';
        return $xml;
    }

    private function buildStyles() {
        // Number formats
        $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $xml .= '<numFmts count="1"><numFmt numFmtId="164" formatCode="&quot;$&quot;#,##0.00"/></numFmts>';

        // Fonts
        $xml .= '<fonts count="5">';
        // 0 — normal
        $xml .= '<font><sz val="11"/><name val="Calibri"/></font>';
        // 1 — bold
        $xml .= '<font><b/><sz val="11"/><name val="Calibri"/></font>';
        // 2 — bold white (header)
        $xml .= '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>';
        // 3 — bold navy (section)
        $xml .= '<font><b/><sz val="11"/><color rgb="FF091B33"/><name val="Calibri"/></font>';
        // 4 — bold black (total)
        $xml .= '<font><b/><sz val="11"/><name val="Calibri"/></font>';
        $xml .= '</fonts>';

        // Fills (index 0 and 1 are required by spec)
        $xml .= '<fills count="5">';
        $xml .= '<fill><patternFill patternType="none"/></fill>';
        $xml .= '<fill><patternFill patternType="gray125"/></fill>';
        $xml .= '<fill><patternFill patternType="solid"><fgColor rgb="FF091B33"/></patternFill></fill>'; // 2 — navy
        $xml .= '<fill><patternFill patternType="solid"><fgColor rgb="FFD6E4F0"/></patternFill></fill>'; // 3 — light blue
        $xml .= '<fill><patternFill patternType="solid"><fgColor rgb="FFF0F0F0"/></patternFill></fill>'; // 4 — light grey
        $xml .= '</fills>';

        // Borders (just one empty border)
        $xml .= '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>';

        // Cell style xfs (required)
        $xml .= '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>';

        // Cell xfs — order matches STYLE_* constants
        $xml .= '<cellXfs>';
        // 0 — normal
        $xml .= '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>';
        // 1 — bold
        $xml .= '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/>';
        // 2 — header
        $xml .= '<xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0"><alignment horizontal="center"/></xf>';
        // 3 — money
        $xml .= '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0"/>';
        // 4 — section
        $xml .= '<xf numFmtId="0" fontId="3" fillId="3" borderId="0" xfId="0"/>';
        // 5 — total
        $xml .= '<xf numFmtId="0" fontId="4" fillId="4" borderId="0" xfId="0"/>';
        // 6 — total money
        $xml .= '<xf numFmtId="164" fontId="4" fillId="4" borderId="0" xfId="0"/>';
        $xml .= '</cellXfs>';

        $xml .= '</styleSheet>';
        return $xml;
    }

    private function buildWorkbook() {
        $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" ';
        $xml .= 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $xml .= '<sheets>';
        foreach ( $this->sheetNames as $idx => $name ) {
            $xml .= '<sheet name="' . htmlspecialchars( $name, ENT_XML1, 'UTF-8' ) . '" sheetId="' . ( $idx + 1 ) . '" r:id="rId' . ( $idx + 1 ) . '"/>';
        }
        $xml .= '</sheets></workbook>';
        return $xml;
    }

    private function buildWorkbookRels() {
        $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ( $this->sheetNames as $idx => $name ) {
            $xml .= '<Relationship Id="rId' . ( $idx + 1 ) . '" ';
            $xml .= 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" ';
            $xml .= 'Target="worksheets/sheet' . ( $idx + 1 ) . '.xml"/>';
        }
        $n    = count( $this->sheetNames );
        $xml .= '<Relationship Id="rId' . ( $n + 1 ) . '" ';
        $xml .= 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" ';
        $xml .= 'Target="sharedStrings.xml"/>';
        $xml .= '<Relationship Id="rId' . ( $n + 2 ) . '" ';
        $xml .= 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" ';
        $xml .= 'Target="styles.xml"/>';
        $xml .= '</Relationships>';
        return $xml;
    }

    private function buildRels() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function buildContentTypes() {
        $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
        $xml .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
        $xml .= '<Default Extension="xml" ContentType="application/xml"/>';
        $xml .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        $xml .= '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';
        $xml .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        foreach ( $this->sheetNames as $idx => $name ) {
            $xml .= '<Override PartName="/xl/worksheets/sheet' . ( $idx + 1 ) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $xml .= '</Types>';
        return $xml;
    }

    // ── Minimal ZIP builder ───────────────────────────────────

    private function zipFiles( array $files ) {
        $zip     = '';
        $entries = [];
        $offset  = 0;

        foreach ( $files as $name => $content ) {
            $compressed = gzdeflate( $content, 6 );
            $crc        = crc32( $content );
            $cSize      = strlen( $compressed );
            $uSize      = strlen( $content );
            $nameLen    = strlen( $name );

            $local  = "\x50\x4b\x03\x04";      // signature
            $local .= "\x14\x00";               // version needed
            $local .= "\x00\x00";               // flags
            $local .= "\x08\x00";               // compression: deflate
            $local .= "\x00\x00\x00\x00";       // mod time/date
            $local .= pack( 'V', $crc );
            $local .= pack( 'V', $cSize );
            $local .= pack( 'V', $uSize );
            $local .= pack( 'v', $nameLen );
            $local .= "\x00\x00";               // extra field length
            $local .= $name;
            $local .= $compressed;

            $entries[] = [
                'name'    => $name,
                'crc'     => $crc,
                'cSize'   => $cSize,
                'uSize'   => $uSize,
                'offset'  => $offset,
                'nameLen' => $nameLen,
            ];

            $offset += strlen( $local );
            $zip    .= $local;
        }

        $cdOffset = $offset;
        $cdSize   = 0;

        foreach ( $entries as $e ) {
            $cd  = "\x50\x4b\x01\x02";     // central dir signature
            $cd .= "\x14\x00";              // version made by
            $cd .= "\x14\x00";              // version needed
            $cd .= "\x00\x00";              // flags
            $cd .= "\x08\x00";              // compression
            $cd .= "\x00\x00\x00\x00";     // mod time/date
            $cd .= pack( 'V', $e['crc'] );
            $cd .= pack( 'V', $e['cSize'] );
            $cd .= pack( 'V', $e['uSize'] );
            $cd .= pack( 'v', $e['nameLen'] );
            $cd .= "\x00\x00";              // extra
            $cd .= "\x00\x00";              // comment
            $cd .= "\x00\x00";              // disk start
            $cd .= "\x00\x00";              // internal attr
            $cd .= "\x00\x00\x00\x00";     // external attr
            $cd .= pack( 'V', $e['offset'] );
            $cd .= $e['name'];
            $zip    .= $cd;
            $cdSize += strlen( $cd );
        }

        $count = count( $entries );
        $eocd  = "\x50\x4b\x05\x06";       // end of central dir
        $eocd .= "\x00\x00\x00\x00";       // disk numbers
        $eocd .= pack( 'v', $count );
        $eocd .= pack( 'v', $count );
        $eocd .= pack( 'V', $cdSize );
        $eocd .= pack( 'V', $cdOffset );
        $eocd .= "\x00\x00";               // comment length
        $zip  .= $eocd;

        return $zip;
    }
}