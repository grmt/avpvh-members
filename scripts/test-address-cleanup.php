<?php
declare(strict_types=1);

/**
 * Ad-hoc checks for Adressen opschonen (AVPVH_Address_Cleanup): which rows
 * of one member fold together, and which spelling is offered first.
 *
 * Run:
 *   php scripts/test-address-cleanup.php
 */

define('ABSPATH', __DIR__ . '/');

function __(string $text, string $domain = ''): string {
    return $text;
}

class WP_Error {
    public function __construct(public string $code = '', public string $message = '') {}
}
function is_wp_error(mixed $thing): bool {
    return $thing instanceof WP_Error;
}

require_once dirname(__DIR__) . '/includes/class-avpvh-address-normalizer.php';
require_once dirname(__DIR__) . '/includes/class-address-cleanup.php';

function check(string $label, mixed $actual, mixed $expected): void {
    echo ($actual === $expected ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if ($actual !== $expected) {
        echo '  expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL;
        exit(1);
    }
}

function row(int $id, int $member, ?string $from, ?string $until, string $street = 'Kerkstraat', string $number = '1', string $city = 'Utrecht'): object {
    return (object) ['id' => $id, 'member_id' => $member, 'street' => $street, 'house_number' => $number, 'house_number_addition' => '',
        'postal_code' => '1234 AB', 'city' => $city, 'valid_from' => $from, 'valid_until' => $until];
}

$clusters = [AVPVH_Address_Cleanup::class, 'clusters'];

check('overlapping periods fold', $clusters([row(1, 7, '2020-01-01', '2022-12-31'), row(2, 7, '2022-06-01', null)]), [[1, 2]]);
check('directly following periods fold', $clusters([row(1, 7, '2020-01-01', '2021-12-31'), row(2, 7, '2022-01-01', null)]), [[1, 2]]);
check('gap keeps rows apart (moved away and back)', $clusters([row(1, 7, '2010-01-01', '2012-12-31'), row(2, 7, '2020-01-01', null)]), []);
check('unknown start folds with anything', $clusters([row(1, 7, null, null), row(2, 7, '2020-01-01', null)]), [[1, 2]]);
check('different members never fold', $clusters([row(1, 7, '2020-01-01', null), row(2, 8, '2020-01-01', null)]), []);
check('open-ended run swallows later rows', $clusters([row(3, 7, '2010-01-01', null), row(1, 7, '2015-01-01', '2016-01-01'), row(2, 7, '2030-01-01', null)]), [[3, 1, 2]]);
check('two runs for one member', $clusters([
    row(1, 7, '2000-01-01', '2001-12-31'), row(2, 7, '2001-06-01', '2002-12-31'),
    row(3, 7, '2010-01-01', '2011-12-31'), row(4, 7, '2012-01-01', null),
]), [[1, 2], [3, 4]]);

$variants = (new ReflectionMethod(AVPVH_Address_Cleanup::class, 'variants'));
$v = $variants->invoke(null, [
    row(1, 7, null, null, 'Kerkstr', '40-I'),
    row(2, 8, null, null, 'Kerkstraat', '40 1 hoog'),
    row(3, 9, null, null, 'Kerkstraat', '40 1 hoog'),
]);
check('most common spelling first', $v[0]['fields']['street'] . ' ' . $v[0]['fields']['house_number'], 'Kerkstraat 40 1 hoog');
check('count kept', $v[0]['count'], 2);
$v = $variants->invoke(null, [row(1, 7, null, null, 'Kerkstr'), row(2, 8, null, null, 'Kerkstraat')]);
check('tie: written-out street first', $v[0]['fields']['street'], 'Kerkstraat');

$validate = new ReflectionMethod(AVPVH_Address_Cleanup::class, 'validated_fields');
$group = ['fingerprint' => 'fp', 'rows' => [(object) ['country' => 'Nederland']]];
$key = AVPVH_Address_Normalizer::match_key(['street' => 'Kerkstraat', 'house_number' => '40-1', 'postal_code' => '1234 AB']);
$ok = $validate->invoke(null, $group, $key, ['city' => 'Utrecht', 'street' => 'Kerkstr', 'house_number' => '40 1 hoog', 'postal_code' => '1234ab'], 'fp');
check('respelling accepted, cleaned and split, fields in fixed order', $ok,
    ['street' => 'Kerkstr', 'house_number' => '40', 'house_number_addition' => '1', 'postal_code' => '1234 AB', 'city' => 'Utrecht']);
check('other house number refused', $validate->invoke(null, $group, $key, ['street' => 'Kerkstraat', 'house_number' => '42', 'postal_code' => '1234 AB', 'city' => ''], 'fp')->code, 'other_address');
check('changed group refused', $validate->invoke(null, $group, $key, $ok, 'old')->code, 'stale');
check('vanished group refused', $validate->invoke(null, null, $key, $ok, 'fp')->code, 'gone');

function wp_json_encode(mixed $data): string {
    return (string) json_encode($data);
}
$wpdb = new class {
    public string $prefix = 'wp_';
    public array $open = [];
    public function get_results(string $sql): array {
        return $this->open;
    }
};
$wpdb->open = [
    // member 7: moved, both still open -> older one ends the day before
    row(1, 7, '2015-03-01', null, 'Kerkstraat', '1'),
    row(2, 7, '2021-06-15', null, 'Dorpsstraat', '9'),
    // member 8: same address twice -> left to the duplicate folding
    row(3, 8, '2020-01-01', null),
    row(4, 8, null, null),
    // member 9: no start dates -> no proposal
    row(5, 9, null, null, 'Kerkstraat', '1'),
    row(6, 9, null, null, 'Dorpsstraat', '9'),
    // member 10: newest address stored twice, plus an old one
    row(7, 10, '2010-01-01', null, 'Kerkstraat', '1'),
    row(8, 10, '2022-02-01', null, 'Dorpsstraat', '9'),
    row(9, 10, '2022-02-01', null, 'Dorpsstr', '9'),
];
$conflicts = AVPVH_Address_Cleanup::find_open_conflicts();
check('only members with different open addresses', array_keys($conflicts), [7, 9, 10]);
check('newest stays open', $conflicts[7]['keep'], 2);
check('older ends the day before the newest started', $conflicts[7]['close'], [1 => '2021-06-14']);
check('no start dates: no proposal', [$conflicts[9]['keep'], $conflicts[9]['close']], [null, []]);
check('duplicate of the newest is not closed', $conflicts[10]['close'], [7 => '2022-01-31']);

function place(string $street, string $number, string $postal, string $city): object {
    return (object) ['street' => $street, 'house_number' => $number, 'house_number_addition' => '', 'postal_code' => $postal, 'city' => $city];
}
$same = [AVPVH_Address_Cleanup::class, 'probably_same_address'];
check('abbreviated and misspelled street, postcode one off',
    $same(place('J. van Lindenweg', '14', '6523 ND', 'Utrecht'), place('Johan van Lindeweg', '14', '6521 ND', 'Utrecht')), true);
check('postcode one off, other city spelling',
    $same(place('Kerkstraat', '3', '1234 AB', 'Den Bosch'), place('Kerkstraat', '3', '1234 AC', "'s-Hertogenbosch")), true);
check('different house number is never the same',
    $same(place('Kerkstraat', '3', '1234 AB', 'Utrecht'), place('Kerkstraat', '5', '1234 AB', 'Utrecht')), false);
check('same number, other street, same city is a move',
    $same(place('Kerkstraat', '3', '1234 AB', 'Utrecht'), place('Molenweg', '3', '3581 XY', 'Utrecht')), false);
check('same street name in another town is a move',
    $same(place('Kerkstraat', '3', '1234 AB', 'Utrecht'), place('Kerkstraat', '3', '9876 ZZ', 'Groningen')), false);

$wpdb->open = [
    row(11, 20, '1999-06-01', null, 'J. van Lindenweg', '14'),
    row(12, 20, '2026-01-01', null, 'Johan van Lindeweg', '14'),
];
$wpdb->open[0]->postal_code = '6523 ND';
$wpdb->open[1]->postal_code = '6521 ND';
$conflicts = AVPVH_Address_Cleanup::find_open_conflicts();
check('typo pair is flagged, not closed', [$conflicts[20]['same_place'], $conflicts[20]['keep'], $conflicts[20]['close']], [true, null, []]);
check('both spellings offered', count($conflicts[20]['variants']), 2);

echo "Address cleanup tests: OK\n";
