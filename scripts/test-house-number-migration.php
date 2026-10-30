<?php
declare(strict_types=1);

/**
 * Ad-hoc checks for the 2.27 migration that moves house number additions
 * into their own column (AVPVH_DB::split_house_number_additions()).
 *
 * Run:
 *   php scripts/test-house-number-migration.php
 */

define('ABSPATH', __DIR__ . '/');

$wpdb = new class {
    public string $prefix = 'wp_';
    public array $rows = [];

    public function get_results(string $sql): array {
        return array_values(array_map(
            fn($r) => (object) ['id' => $r['id'], 'house_number' => $r['house_number']],
            array_filter($this->rows, fn($r) => $r['house_number'] !== '' && $r['house_number_addition'] === '')
        ));
    }

    public function update(string $table, array $data, array $where): int {
        foreach ($this->rows as &$row) {
            if ($row['id'] === $where['id']) {
                $row = array_merge($row, $data);
            }
        }
        return 1;
    }
};

require_once dirname(__DIR__) . '/includes/class-db.php';
require_once dirname(__DIR__) . '/includes/class-avpvh-address-normalizer.php';

function check(string $label, mixed $actual, mixed $expected): void {
    echo ($actual === $expected ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if ($actual !== $expected) {
        echo '  expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL;
        exit(1);
    }
}

$numbers = ['40-1 hoog', '40-I', '19a', '19 A', '7 bis', '12', '040', 'ong.', '', '19-hs'];
foreach ($numbers as $i => $number) {
    $wpdb->rows[] = ['id' => $i + 1, 'house_number' => $number, 'house_number_addition' => ''];
}
$wpdb->rows[] = ['id' => 99, 'house_number' => '5', 'house_number_addition' => 'B'];

$migrate = new ReflectionMethod(AVPVH_DB::class, 'split_house_number_additions');
$migrate->invoke(null);
$result = array_map(fn($r) => [$r['house_number'], $r['house_number_addition']], $wpdb->rows);

check('floor notation', $result[0], ['40', '1']);
check('roman floor', $result[1], ['40', '1']);
check('letter', $result[2], ['19', 'A']);
check('letter with space', $result[3], ['19', 'A']);
check('word', $result[4], ['7', 'BIS']);
check('plain number untouched', $result[5], ['12', '']);
check('leading zero dropped', $result[6], ['40', '']);
check('no number keeps its text', $result[7], ['ong.', '']);
check('empty stays empty', $result[8], ['', '']);
check('ground floor', $result[9], ['19', 'H']);
check('row with an addition already is left alone', $result[10], ['5', 'B']);

$migrate->invoke(null);
check('second run changes nothing', array_map(fn($r) => [$r['house_number'], $r['house_number_addition']], $wpdb->rows), $result);

echo "House number migration tests: OK\n";
