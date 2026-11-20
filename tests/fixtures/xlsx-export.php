<?php
declare(strict_types=1);

/** Read real export workbooks, including their values and cell formatting. */
define('ABSPATH', __DIR__ . '/');
define('AVPVH_PLUGIN_DIR', dirname(__DIR__, 2) . '/');

if (!class_exists('ZipArchive')) {
    echo "SKIP export round trip: ext-zip is unavailable\n";
    exit(0);
}
function __(string $text, string $domain): string { return $text; }
function date_i18n(string $format, int|false $timestamp = false): string {
    return gmdate($format, $timestamp === false ? strtotime('2026-06-15 12:00:00 UTC') : $timestamp);
}
function wp_date(string $format, int $timestamp): string { return gmdate($format, $timestamp); }
function wp_delete_file(string $path): void { unlink($path); }
date_default_timezone_set('UTC');

function export_check(string $label, bool $ok): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) { exit(1); }
}
/** @return array{sheet: SimpleXMLElement, styles: SimpleXMLElement, workbook: SimpleXMLElement} */
function read_export(string $bytes): array {
    $path = tempnam(sys_get_temp_dir(), 'avpvh_export_test_');
    try {
        file_put_contents($path, $bytes);
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) { throw new RuntimeException('Export is not a readable workbook.'); }
        try {
            $parts = [];
            foreach (['sheet' => 'xl/worksheets/sheet1.xml', 'styles' => 'xl/styles.xml', 'workbook' => 'xl/workbook.xml'] as $key => $entry) {
                $xml = simplexml_load_string($zip->getFromName($entry));
                if ($xml === false) { throw new RuntimeException('Invalid XML in ' . $entry); }
                $parts[$key] = $xml;
            }
            return $parts;
        } finally { $zip->close(); }
    } finally { unlink($path); }
}
function export_values(SimpleXMLElement $row): array {
    $values = [];
    foreach ($row->c as $cell) {
        $values[] = isset($cell->v) ? (string) $cell->v : (string) $cell->is->t;
    }
    return $values;
}
/** Resolve the cell's actual fill/font, rather than relying on a generated style number. */
function export_style(array $parts, SimpleXMLElement $cell): array {
    $style = $parts['styles']->cellXfs->xf[(int) $cell['s']];
    return [
        'color' => (string) $parts['styles']->fills->fill[(int) $style['fillId']]->patternFill->fgColor['rgb'],
        'bold' => isset($parts['styles']->fonts->font[(int) $style['fontId']]->b),
    ];
}
