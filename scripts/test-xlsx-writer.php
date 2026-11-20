<?php
declare(strict_types=1);

/**
 * Ad-hoc check for AVPVH_Xlsx_Writer: a cell with control characters (say,
 * pasted into a notes field) still gives a sheet that is valid XML.
 *
 * Run:
 *   php scripts/test-xlsx-writer.php
 */

define('ABSPATH', __DIR__ . '/');

require_once dirname(__DIR__) . '/includes/class-xlsx-writer.php';

$build_sheet = new ReflectionMethod(AVPVH_Xlsx_Writer::class, 'build_sheet');
$args = [[[['v' => "Notitie\x01 met\x0B stuurtekens\tén tab"], ['v' => '=1+1'], ['v' => '+31 6 1234 5678'], ['v' => '+cmd|calc'], ['v' => '- vegetarisch']]]];
$sheet = (string) $build_sheet->invokeArgs(null, array_pad($args, $build_sheet->getNumberOfRequiredParameters(), []));

// No XML extension needed: the only characters XML 1.0 forbids are these.
$ok = preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $sheet) === 0;
echo ($ok ? '[OK] ' : '[FAIL] ') . 'sheet has no characters XML 1.0 forbids' . PHP_EOL;
$kept = str_contains($sheet, "Notitie met stuurtekens\tén tab");
echo ($kept ? '[OK] ' : '[FAIL] ') . 'control characters dropped, tab and text kept' . PHP_EOL;
$literal = !str_contains($sheet, '<f>') && substr_count($sheet, 't="inlineStr"') === 5
    && str_contains($sheet, '>+cmd|calc<') && str_contains($sheet, '>=1+1<')
    && str_contains($sheet, '>+31 6 1234 5678<') && str_contains($sheet, '>- vegetarisch<');
echo ($literal ? '[OK] ' : '[FAIL] ') . 'inline strings preserve literal text and contain no formulas' . PHP_EOL;
if (!$ok || !$kept || !$literal) {
    exit(1);
}

// Styles must remain valid XML even with an invalid user-supplied colour.
$style_rows = [[
    ['v' => '', 'bold' => true], ['v' => 42, 'color' => 'aabbcc'],
    ['v' => 0.5, 'color' => 'aabbcc'], ['v' => '0012', 'color' => '"/><bad/>'],
]];
$styles_method = new ReflectionMethod(AVPVH_Xlsx_Writer::class, 'build_styles');
[$styles, $style_index] = $styles_method->invoke(null, $style_rows);
$style_xml = simplexml_load_string($styles);
$style_ok = $style_xml !== false && (int) $style_xml->fills['count'] === 3
    && $style_index['0|AABBCC'] > 0 && !str_contains($styles, '<bad');
echo ($style_ok ? '[OK] ' : '[FAIL] ') . 'styles reuse valid colours and discard invalid colour markup' . PHP_EOL;
if (!$style_ok) { exit(1); }

if (!class_exists('ZipArchive')) {
    echo "SKIP archive round trip: ext-zip is unavailable\n";
    exit(0);
}

function wp_delete_file(string $path): void {
    $GLOBALS['xlsx_deleted'][] = $path;
    unlink($path);
}
function xlsx_check(string $label, bool $ok): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) { exit(1); }
}
$style_rows[0][] = ['v' => '=SUM(B1:C1)'];
$style_rows[0][] = ['v' => '<tekst & meer>'];
$style_rows[] = array_fill(0, 27, ['v' => 'kolom']);
$bytes = AVPVH_Xlsx_Writer::build($style_rows, ['A' => 18, 'B' => 9.5], 'Export & "voorbeeld"');
$path = tempnam(sys_get_temp_dir(), 'avpvh_xlsx_test_');
try {
    file_put_contents($path, $bytes);
    $zip = new ZipArchive();
    xlsx_check('build produces a readable ZIP package', $zip->open($path) === true);
    $required = ['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'];
    foreach ($required as $name) {
        xlsx_check($name . ' is present and valid XML', simplexml_load_string($zip->getFromName($name)) !== false);
    }
    $workbook = simplexml_load_string($zip->getFromName('xl/workbook.xml'));
    $sheet = simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));
    xlsx_check('sheet name survives XML escaping', (string) $workbook->sheets->sheet['name'] === 'Export & "voorbeeld"');
    xlsx_check('column widths survive the package round trip', (string) $sheet->cols->col[0]['width'] === '18' && (string) $sheet->cols->col[1]['width'] === '9.5');
    $cells = $sheet->sheetData->row[0]->c;
    xlsx_check('blank and numeric cells have the expected value types', !isset($cells[0]->v) && (string) $cells[1]->v === '42' && (string) $cells[2]->v === '0.5');
    xlsx_check('leading zeros and formula-looking text remain literal strings', (string) $cells[3]['t'] === 'inlineStr' && (string) $cells[3]->is->t === '0012' && (string) $cells[4]->is->t === '=SUM(B1:C1)' && !isset($cells[4]->f));
    xlsx_check('text markup is preserved as text', (string) $cells[5]->is->t === '<tekst & meer>');
    xlsx_check('column references extend beyond Z', (string) $sheet->sheetData->row[1]->c[26]['r'] === 'AA2');
    $zip->close();
    xlsx_check('builder removes its temporary archive', count($GLOBALS['xlsx_deleted']) === 1 && !file_exists($GLOBALS['xlsx_deleted'][0]));
    xlsx_check('an empty workbook is supported', str_starts_with(AVPVH_Xlsx_Writer::build([]), 'PK'));
} finally {
    if (is_file($path)) { unlink($path); }
}
echo "Xlsx writer tests: OK\n";
