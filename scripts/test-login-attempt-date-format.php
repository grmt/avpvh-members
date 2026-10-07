<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

class WP_List_Table {}

function sanitize_text_field(string $value): string {
    return trim($value);
}

function wp_unslash($value) {
    return $value;
}

require_once dirname(__DIR__) . '/admin/class-login-attempts-list-table.php';

$table = (new ReflectionClass(AVPVH_Login_Attempts_List_Table::class))->newInstanceWithoutConstructor();
$sanitize = new ReflectionMethod($table, 'sanitize_date');
$format = new ReflectionMethod($table, 'format_date_input');

$checks = [
    'Nederlandse maandnaam wordt gelezen' => $sanitize->invoke($table, '02-sep-2026') === '2026-09-02',
    'Numerieke notatie blijft geldig' => $sanitize->invoke($table, '02-09-2026') === '2026-09-02',
    'ISO-notatie blijft geldig' => $sanitize->invoke($table, '2026-09-02') === '2026-09-02',
    'Ongeldige datum wordt geweigerd' => $sanitize->invoke($table, '31-feb-2026') === '',
    'Datum wordt Nederlands weergegeven' => $format->invoke($table, '2026-10-07') === '07-okt-2026',
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "[FAIL] {$label}\n");
        exit(1);
    }
    echo "[OK] {$label}\n";
}

echo "\nDatumformaat-tests geslaagd.\n";
