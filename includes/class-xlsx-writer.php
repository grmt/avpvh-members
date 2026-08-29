<?php
defined('ABSPATH') || exit;

/**
 * Minimal, dependency-free .xlsx writer for a single flat grid of cells
 * (value + bold + background color). Built by hand instead of pulling in
 * PhpSpreadsheet, since that's all the activity participation export needs
 * — no formulas, no multi-sheet workbooks, no rich number formats.
 *
 * Usage:
 *   $bytes = AVPVH_Xlsx_Writer::build($rows, ['A' => 24, 'B' => 12]);
 *   where $rows is a list of rows, each a list of cells:
 *   ['v' => 'Fictieve Testnaam', 'bold' => true, 'color' => 'FFEE99']
 */
class AVPVH_Xlsx_Writer {

    public static function build(array $rows, array $col_widths = [], string $sheet_name = 'Blad1'): string {
        [$styles_xml, $style_index] = self::build_styles($rows);
        $sheet_xml = self::build_sheet($rows, $col_widths, $style_index);

        $tmp = tempnam(sys_get_temp_dir(), 'avpvh_xlsx_');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);

        $zip->addEmptyDir('_rels');
        $zip->addEmptyDir('xl');
        $zip->addEmptyDir('xl/_rels');
        $zip->addEmptyDir('xl/worksheets');

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
            '<Default Extension="xml" ContentType="application/xml"/>' .
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
            '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
            '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
            '</Types>'
        );

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
            '</Relationships>'
        );

        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
            '</Relationships>'
        );

        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" ' .
            'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
            '<sheets><sheet name="' . htmlspecialchars($sheet_name, ENT_XML1 | ENT_QUOTES) . '" sheetId="1" r:id="rId1"/></sheets>' .
            '</workbook>'
        );

        $zip->addFromString('xl/styles.xml', $styles_xml);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet_xml);
        $zip->close();

        $bytes = file_get_contents($tmp);
        wp_delete_file($tmp);
        return $bytes;
    }

    private static function build_styles(array $rows): array {
        // Collect distinct (bold, color) combinations used by any cell.
        $combos = ['0|' => 0]; // default style always index 0
        $index_map = [];
        $i = 0;
        foreach ($rows as $row) {
            foreach ($row as $cell) {
                $bold = !empty($cell['bold']) ? 1 : 0;
                $color = $cell['color'] ?? '';
                $key = $bold . '|' . $color;
                if (!isset($combos[$key])) {
                    $combos[$key] = null;
                }
            }
        }

        $fonts = ['<font><sz val="10"/><name val="Calibri"/></font>', '<font><sz val="10"/><name val="Calibri"/><b/></font>'];
        $fills = ['<fill><patternFill patternType="none"/></fill>', '<fill><patternFill patternType="gray125"/></fill>'];
        $cellxfs = ['<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'];
        $fill_index_by_color = [];

        $xf_index = 1;
        foreach (array_keys($combos) as $key) {
            if ($key === '0|') {
                $index_map[$key] = 0;
                continue;
            }
            [$bold, $color] = explode('|', $key, 2);
            $font_id = $bold ? 1 : 0;
            if ($color !== '') {
                if (!isset($fill_index_by_color[$color])) {
                    $fills[] = '<fill><patternFill patternType="solid"><fgColor rgb="FF' . strtoupper($color) . '"/><bgColor indexed="64"/></patternFill></fill>';
                    $fill_index_by_color[$color] = count($fills) - 1;
                }
                $fill_id = $fill_index_by_color[$color];
            } else {
                $fill_id = 0;
            }
            $cellxfs[] = '<xf numFmtId="0" fontId="' . $font_id . '" fillId="' . $fill_id . '" borderId="0" xfId="0" applyFont="1" applyFill="1"/>';
            $index_map[$key] = $xf_index;
            $xf_index++;
        }

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<fonts count="' . count($fonts) . '">' . implode('', $fonts) . '</fonts>' .
            '<fills count="' . count($fills) . '">' . implode('', $fills) . '</fills>' .
            '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>' .
            '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
            '<cellXfs count="' . count($cellxfs) . '">' . implode('', $cellxfs) . '</cellXfs>' .
            '</styleSheet>';

        return [$xml, $index_map];
    }

    private static function col_letter(int $col): string {
        $letter = '';
        $col++;
        while ($col > 0) {
            $mod = ($col - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $col = intdiv($col - 1, 26);
        }
        return $letter;
    }

    private static function build_sheet(array $rows, array $col_widths, array $style_index): string {
        $cols_xml = '';
        if ($col_widths) {
            $cols_xml = '<cols>';
            $i = 1;
            foreach (array_values($col_widths) as $width) {
                $cols_xml .= '<col min="' . $i . '" max="' . $i . '" width="' . (float) $width . '" customWidth="1"/>';
                $i++;
            }
            $cols_xml .= '</cols>';
        }

        $rows_xml = '';
        $r = 1;
        foreach ($rows as $row) {
            $cells_xml = '';
            $c = 0;
            foreach ($row as $cell) {
                $ref = self::col_letter($c) . $r;
                $bold = !empty($cell['bold']) ? 1 : 0;
                $color = $cell['color'] ?? '';
                $s = $style_index[$bold . '|' . $color] ?? 0;
                $value = (string) ($cell['v'] ?? '');

                if ($value === '') {
                    $cells_xml .= '<c r="' . $ref . '" s="' . $s . '"/>';
                } elseif (is_numeric($value) && $value === (string) (float) $value) {
                    $cells_xml .= '<c r="' . $ref . '" s="' . $s . '"><v>' . htmlspecialchars($value, ENT_XML1) . '</v></c>';
                } else {
                    $cells_xml .= '<c r="' . $ref . '" s="' . $s . '" t="inlineStr"><is><t xml:space="preserve">' .
                        htmlspecialchars($value, ENT_XML1 | ENT_QUOTES) . '</t></is></c>';
                }
                $c++;
            }
            $rows_xml .= '<row r="' . $r . '">' . $cells_xml . '</row>';
            $r++;
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            $cols_xml .
            '<sheetData>' . $rows_xml . '</sheetData>' .
            '</worksheet>';
    }
}
