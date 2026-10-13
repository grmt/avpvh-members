<?php
declare(strict_types=1);

/**
 * Ad-hoc checks for AVPVH_Address_Normalizer (shared "same address" rule).
 *
 * Run:
 *   php scripts/test-address-normalizer.php
 */

define('ABSPATH', __DIR__ . '/');

require_once dirname(__DIR__) . '/includes/class-avpvh-address-normalizer.php';

function check(string $label, mixed $actual, mixed $expected): void {
    echo ($actual === $expected ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if ($actual !== $expected) {
        echo '  expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL;
        exit(1);
    }
}

function addr(string $street, string $number, string $postal, string $city = ''): array {
    return ['street' => $street, 'house_number' => $number, 'postal_code' => $postal, 'city' => $city];
}

$key = [AVPVH_Address_Normalizer::class, 'match_key'];

check('postcode spacing and case', AVPVH_Address_Normalizer::normalize_postal_code('1234ab'), '1234 AB');
check('postcode with extra spaces', AVPVH_Address_Normalizer::normalize_postal_code(' 1234  Ab '), '1234 AB');
check('foreign postcode untouched', AVPVH_Address_Normalizer::normalize_postal_code(' B-2000 '), 'B-2000');

check('same address, different spacing/case',
    $key(addr('Kerkstraat', '12a', '1234AB')), $key(addr(' kerkstraat ', '12 A', '1234 ab')));
check('punctuation ignored',
    $key(addr('Burg. de Withstraat', '3', '1234 AB')), $key(addr('Burg de Withstraat', '3', '1234AB')));
check('city ignored',
    $key(addr('Kerkstraat', '1', '5211 AA', 'Den Bosch')), $key(addr('Kerkstraat', '1', '5211 AA', "'s-Hertogenbosch")));
check('different number differs',
    $key(addr('Kerkstraat', '12', '1234 AB')) === $key(addr('Kerkstraat', '14', '1234 AB')), false);
check('no street gives empty key', $key(addr('', '12', '1234 AB')), '');
check('object input', $key((object) addr('Kerkstraat', '1', '1234 AB')), $key(addr('Kerkstraat', '1', '1234AB')));

$clean = AVPVH_Address_Normalizer::clean(addr(' Kerk  straat ', '1', '1234ab') + ['country' => 'Nederland', 'valid_from' => '2026-01-01']);
check('clean tidies whitespace', $clean['street'], 'Kerk straat');
check('clean formats Dutch postcode', $clean['postal_code'], '1234 AB');
check('clean keeps other keys', $clean['valid_from'], '2026-01-01');
check('clean leaves foreign postcode', AVPVH_Address_Normalizer::clean(['postal_code' => '1234ab', 'country' => 'België'])['postal_code'], '1234ab');
