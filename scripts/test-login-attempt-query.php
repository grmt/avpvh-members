<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('AVPVH_LLDAP_DB', 'lldap');

class AVPVH_Test_WPDB {
    public string $prefix = 'pvh_';
    public array $queries = [];

    public function esc_like(string $value): string {
        return addcslashes($value, '_%\\');
    }

    public function prepare(string $query, ...$args): string {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        foreach ($args as $arg) {
            $replacement = is_int($arg) ? (string) $arg : "'" . addslashes((string) $arg) . "'";
            $query = (string) preg_replace('/%[sd]/', $replacement, $query, 1);
        }
        return $query;
    }

    public function get_var(string $query): int {
        $this->queries[] = $query;
        return 73;
    }

    public function get_results(string $query): array {
        $this->queries[] = $query;
        return [];
    }
}

$wpdb = new AVPVH_Test_WPDB();
require_once dirname(__DIR__) . '/includes/class-db.php';

$result = AVPVH_DB::query_login_attempts([
    'search' => 'needle',
    'method' => ['google', 'microsoft'],
    'result' => ['success', 'no_member'],
    'date_from' => '2026-08-01',
    'date_to' => '2026-08-31',
    'orderby' => 'attempted_at; DROP TABLE members',
    'order' => 'sideways',
    'per_page' => 25,
    'page' => 3,
]);

$sql = implode("\n", $wpdb->queries);
$checks = [
    'search filter' => str_contains($sql, "email LIKE '%needle%'"),
    'method filter' => str_contains($sql, "method IN ('google','microsoft')"),
    'result filter' => str_contains($sql, "result IN ('success','no_member')"),
    'date range' => str_contains($sql, "2026-08-01 00:00:00") && str_contains($sql, "2026-08-31 23:59:59"),
    'safe ordering' => str_contains($sql, 'ORDER BY attempted_at DESC') && !str_contains($sql, 'DROP TABLE'),
    'pagination' => str_contains($sql, 'LIMIT 25 OFFSET 50'),
    'total count' => $result['total'] === 73,
];

foreach ($checks as $label => $ok) {
    if (!$ok) {
        fwrite(STDERR, "[FAIL] {$label}\n");
        exit(1);
    }
    echo "[OK] {$label}\n";
}

echo "\nLogin attempt query tests: OK\n";
