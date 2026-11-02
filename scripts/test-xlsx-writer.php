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
$args = [[[['v' => "Notitie\x01 met\x0B stuurtekens\tén tab"], ['v' => '=1+1']]]];
$sheet = (string) $build_sheet->invokeArgs(null, array_pad($args, $build_sheet->getNumberOfRequiredParameters(), []));

// No XML extension needed: the only characters XML 1.0 forbids are these.
$ok = preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $sheet) === 0;
echo ($ok ? '[OK] ' : '[FAIL] ') . 'sheet has no characters XML 1.0 forbids' . PHP_EOL;
$kept = str_contains($sheet, "Notitie met stuurtekens\tén tab");
echo ($kept ? '[OK] ' : '[FAIL] ') . 'control characters dropped, tab and text kept' . PHP_EOL;
if (!$ok || !$kept) {
    exit(1);
}
echo "Xlsx writer tests: OK\n";
