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

$hn = [AVPVH_Address_Normalizer::class, 'normalize_house_number'];
foreach (['40 1', '40-1', '40 1 hoog', '40-1-hoog', '40 1e', '40-I', '40 I hg', '40 01', '040-1'] as $variant) {
    check("house number '$variant' = 40-1", $hn($variant), '40-1');
}
check('40-1 is not 401', $hn('40-1') === $hn('401'), false);
check('letter addition', $hn('12 A'), '12-a');
check('letter addition without space', $hn('12a'), '12-a');
check('bis', $hn('7 bis'), '7-bis');
check('huis and hs fold to h', [$hn('19 huis'), $hn('19-hs'), $hn('19 H')], ['19-h', '19-h', '19-h']);
check('2 hoog differs from 1 hoog', $hn('40 2 hoog') === $hn('40 1 hoog'), false);
check('plain number', $hn(' 40 '), '40');
check('no number', $hn('ong.'), 'ong');

check('Dutch postcode: street abbreviation ignored',
    $key(addr('2e Molendwarsstr', '7a', '1234 AB', "A'dam")), $key(addr('2e Molendwarsstraat', '7 A', '1234AB', 'Amsterdam')));
check('Dutch postcode: floor notations match',
    $key(addr('Kerkstraat', '40-1', '1234 AB')), $key(addr('Kerkstraat', '40 1 hoog', '1234AB')));
check('Dutch postcode: 40-1 is not 401',
    $key(addr('Kerkstraat', '40-1', '1234 AB')) === $key(addr('Kerkstraat', '401', '1234 AB')), false);
check('foreign postcode still compares street',
    $key(addr('Rue Haute', '1', 'B-1000')) === $key(addr('Rue Basse', '1', 'B-1000')), false);

$split = [AVPVH_Address_Normalizer::class, 'split_house_number'];
check('split 40-1 hoog', $split('40-1 hoog'), ['40', '1']);
check('split 19a', $split('19a'), ['19', 'A']);
check('split 40 - I', $split('40 - I'), ['40', '1']);
check('split 7 bis', $split('7 bis'), ['7', 'BIS']);
check('split 19-hs', $split('19-hs'), ['19', 'H']);
check('split plain', $split(' 040 '), ['40', '']);
check('split without number keeps text', $split('ong.'), ['ong.', '']);
check('split 12/14', $split('12/14'), ['12', '14']);
$fmt = [AVPVH_Address_Normalizer::class, 'format_house_number'];
check('format letter glued', $fmt('19', 'A'), '19A');
check('format floor with dash', $fmt('40', '1'), '40-1');
check('format word with dash', $fmt('7', 'BIS'), '7-BIS');
check('format no addition', $fmt('40', ''), '40');
check('street line', AVPVH_Address_Normalizer::format_street_line(['street' => 'Kerkstraat', 'house_number' => '40', 'house_number_addition' => '1']), 'Kerkstraat 40-1');
check('separate field matches combined',
    $key(['street' => 'Kerkstraat', 'house_number' => '40', 'house_number_addition' => '1', 'postal_code' => '1234 AB']),
    $key(addr('Kerkstraat', '40-1 hoog', '1234AB')));
$cleaned = AVPVH_Address_Normalizer::clean(['house_number' => '40-1 hoog']);
check('clean splits a combined number', [$cleaned['house_number'], $cleaned['house_number_addition']], ['40', '1']);
$cleaned = AVPVH_Address_Normalizer::clean(['house_number' => '19', 'house_number_addition' => 'a']);
check('clean normalizes the separate addition', [$cleaned['house_number'], $cleaned['house_number_addition']], ['19', 'A']);
$cleaned = AVPVH_Address_Normalizer::clean(['house_number' => '', 'house_number_addition' => '']);
check('clean leaves empty empty', [$cleaned['house_number'], $cleaned['house_number_addition']], ['', '']);

$country = [AVPVH_Address_Normalizer::class, 'canonical_country'];
check('country: Belgie', $country('belgie'), 'België');
check('country: Luxembourg', $country('Luxembourg'), 'Luxemburg');
check('country: USA', $country('USA'), 'Verenigde Staten');
check('country: Italy', $country(' Italy '), 'Italië');
check('country: unknown kept', $country(' Kroatië '), 'Kroatië');
$be = fn(array $a) => AVPVH_Address_Normalizer::clean($a + ['country' => 'Belgium']);
check('Belgian postcode prefix dropped', $be(['postal_code' => 'B-2000'])['postal_code'], '2000');
check('Belgian postcode without dash', $be(['postal_code' => 'B2000'])['postal_code'], '2000');
check('Luxembourg postcode prefix dropped', AVPVH_Address_Normalizer::clean(['postal_code' => 'L-1352', 'country' => 'Luxemburg'])['postal_code'], '1352');
check('Polish postcode keeps its dash', AVPVH_Address_Normalizer::clean(['postal_code' => '00-950', 'country' => 'Polen'])['postal_code'], '00-950');
check('US postcode untouched', AVPVH_Address_Normalizer::clean(['postal_code' => 'FL 33101', 'country' => 'USA'])['postal_code'], 'FL 33101');
$c = $be(['street' => 'Kerkstraat 12 bus 3', 'house_number' => '']);
check('Belgian number out of the street, with bus', [$c['street'], $c['house_number'], $c['house_number_addition']], ['Kerkstraat', '12', 'BUS3']);
$c = AVPVH_Address_Normalizer::clean(['street' => '12, rue Haute', 'house_number' => '', 'country' => 'Luxembourg']);
check('Luxembourg number in front of the street', [$c['street'], $c['house_number']], ['rue Haute', '12']);
$c = AVPVH_Address_Normalizer::clean(['street' => 'Plein 1940', 'house_number' => '', 'country' => 'Nederland']);
check('Dutch street ending in a year is left alone', [$c['street'], $c['house_number']], ['Plein 1940', '']);
$c = AVPVH_Address_Normalizer::clean(['street' => 'Kast. Molenstr 17d', 'house_number' => '', 'country' => 'Nederland']);
check('Dutch house number out of the street', [$c['street'], $c['house_number'], $c['house_number_addition']], ['Kast. Molenstr', '17', 'D']);
$c = $be(['street' => 'Kerkstraat 5', 'house_number' => '7']);
check('number already set: street left alone', [$c['street'], $c['house_number']], ['Kerkstraat 5', '7']);
check('bte is a bus', AVPVH_Address_Normalizer::normalize_addition('bte 4'), 'BUS4');
$c = $be(['street' => 'Lindenlei 19 (2e v.)', 'house_number' => '']);
check('Belgian number with a floor note', [$c['street'], $c['house_number'], $c['house_number_addition']], ['Lindenlei', '19', '2']);
check('bus displayed Belgian style', $fmt('12', 'BUS3'), '12 bus 3');

$clean = AVPVH_Address_Normalizer::clean(addr(' Kerk  straat ', '1', '1234ab') + ['country' => 'Nederland', 'valid_from' => '2026-01-01']);
check('clean tidies whitespace', $clean['street'], 'Kerk straat');
check('clean formats Dutch postcode', $clean['postal_code'], '1234 AB');
check('clean keeps other keys', $clean['valid_from'], '2026-01-01');
check('clean leaves foreign postcode', AVPVH_Address_Normalizer::clean(['postal_code' => '1234ab', 'country' => 'België'])['postal_code'], '1234ab');
