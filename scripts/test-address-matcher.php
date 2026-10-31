<?php
declare(strict_types=1);

/**
 * Ad-hoc checks for AVPVH_Address_Matcher: which address rows count as the
 * same address (cleanup, merge, profile form, ledenlijst import).
 *
 * Run:
 *   php scripts/test-address-matcher.php
 */

define('ABSPATH', __DIR__ . '/');

require_once dirname(__DIR__) . '/includes/class-avpvh-address-normalizer.php';
require_once dirname(__DIR__) . '/includes/class-avpvh-address-matcher.php';

function check(string $label, mixed $actual, mixed $expected): void {
    echo ($actual === $expected ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if ($actual !== $expected) {
        echo '  expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL;
        exit(1);
    }
}

function place(string $street, string $number, string $postal, string $city): object {
    return (object) ['street' => $street, 'house_number' => $number, 'house_number_addition' => '', 'postal_code' => $postal, 'city' => $city];
}
$same = [AVPVH_Address_Matcher::class, 'probably_same'];
check('abbreviated and misspelled street, postcode one off',
    $same(place('J. van Lindenweg', '3', '1234 AC', 'Utrecht'), place('Johan van Lindeweg', '3', '1234 AB', 'Utrecht')), true);
check('postcode one off, other city spelling',
    $same(place('Kerkstraat', '3', '1234 AB', 'Den Bosch'), place('Kerkstraat', '3', '1234 AC', "'s-Hertogenbosch")), true);
check('different house number is never the same',
    $same(place('Kerkstraat', '3', '1234 AB', 'Utrecht'), place('Kerkstraat', '5', '1234 AB', 'Utrecht')), false);
check('same number, other street, same city is a move',
    $same(place('Kerkstraat', '3', '1234 AB', 'Utrecht'), place('Molenweg', '3', '3581 XY', 'Utrecht')), false);
check('same street name in another town is a move',
    $same(place('Kerkstraat', '3', '1234 AB', 'Utrecht'), place('Kerkstraat', '3', '9876 ZZ', 'Groningen')), false);

check('no number on either side: same postcode, street respelled',
    $same(place('Kerkstr', '', '1234 AB', 'Utrecht'), place('Kerkstraat', '', '1234AB', 'Utrecht')), true);
check('no number on either side: another street is not the same',
    $same(place('Kerkstraat', '', '1234 AB', 'Utrecht'), place('Molenweg', '', '1234AB', 'Utrecht')), false);
check('same Dutch postcode and number, city missing',
    $same(place('Kerkstr', '3', '1234 AB', ''), place('Kerkstraat', '3', '1234AB', 'Utrecht')), true);

check('abroad: abbreviated first word in the street',
    $same(place('B. Lindenlaan', '12', '2000', 'Antwerpen-Centrum'), place('Burgemeester Lindelaan', '12', '2000', 'Antwerpen')), true);
check('abroad: abbreviated words on both sides',
    $same(place('Prof. v.d. Bergstraat', '3', '2000', 'Antwerpen'), place('Professor van den Bergstraat', '3', '2000', 'Antwerpen')), true);
check('abroad: another street at the same postcode and number is not the same',
    $same(place('M. Kerkstraat', '8', '9000', 'Gent'), place('Molenweg', '8', '9000', 'Gent')), false);

check('postcode one off is not enough with another street (often the next street)',
    $same(place('Kerkstraat', '3', '1234 AB', 'Utrecht'), place('Molenweg', '3', '1234 AC', 'Utrecht')), false);

$is = [AVPVH_Address_Matcher::class, 'same'];
check('same(): equal key, other spelling', $is(['street' => 'Kerkstr', 'house_number' => '40-I', 'postal_code' => '1234ab'], place('Kerkstraat', '40 1 hoog', '1234 AB', 'Utrecht')), true);
check('same(): arrays and objects mixed, typo abroad',
    $is(['street' => 'Kapeldrecf', 'house_number' => '8', 'postal_code' => '9000', 'city' => 'Gent'], place('Kapeldreef', '8', '9000', 'Gent')), true);
check('same(): no street on either side compares the text', [$is(place('', '', '', 'Gent'), place('', '', '', 'gent')), $is(place('', '', '', 'Gent'), place('', '', '', 'Brugge'))], [true, false]);
check('same(): a move is not the same', $is(place('Kerkstraat', '3', '1234 AB', 'Utrecht'), place('Molenweg', '9', '3581 XY', 'Utrecht')), false);

function addr(int $id, string $street, string $number, string $postal): object {
    return (object) ['id' => $id, 'street' => $street, 'house_number' => $number, 'house_number_addition' => '', 'postal_code' => $postal, 'city' => 'Gent'];
}
$groups = AVPVH_Address_Matcher::group([
    addr(4, 'Kapeldrecf', '8', '9000'), addr(2, 'Kapeldreef', '8', '9000'), addr(3, 'Molenweg', '8', '9000'),
    addr(1, 'Kerkstr', '5', '1234 AB'), addr(5, 'Kerkstraat', '5', '1234AB'), addr(6, '', '', ''),
]);
check('group(): respelled street abroad joins, other street apart, no street left out',
    array_map(fn($g) => array_map(fn($r) => $r->id, $g), array_values($groups)), [[2, 4], [3], [1, 5]]);

echo "Address matcher tests: OK\n";
