<?php
declare(strict_types=1);

/**
 * Ad-hoc checks for AVPVH_Address_Lookup: which register is asked per
 * country, and how its answer becomes a suggestion. No network: answers
 * are canned in the shape the services return.
 *
 * Run:
 *   php scripts/test-address-lookup.php
 */

define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);

$test_requests = [];
$test_transients = [];

function get_transient(string $key): mixed {
    return $GLOBALS['test_transients'][$key] ?? false;
}
function set_transient(string $key, mixed $value, int $ttl): bool {
    $GLOBALS['test_transients'][$key] = $value;
    return true;
}
function is_wp_error(mixed $thing): bool {
    return false;
}
function flemish_match(string $street, string $number, string $postcode, string $municipality, string $status = 'inGebruik', float $score = 99.0, ?string $bus = null): array {
    return array_filter([
        'straatnaam' => ['straatnaam' => ['geografischeNaam' => ['spelling' => $street]]],
        'huisnummer' => $number,
        'busnummer' => $bus,
        'postinfo' => ['objectId' => $postcode],
        'gemeente' => ['gemeentenaam' => ['geografischeNaam' => ['spelling' => $municipality]]],
        'adresStatus' => $status,
        'score' => $score,
    ], fn($v) => $v !== null);
}
function wp_remote_get(string $url, array $args = []): array {
    $GLOBALS['test_requests'][] = $url;
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    if (str_contains($url, 'adresmatch')) {
        $answers = [
            'Grote Mrkt|1' => [flemish_match('Grote Markt', '1', '2000', 'Antwerpen', 'gehistoreerd'), flemish_match('Grote Markt', '1', '2000', 'Antwerpen')],
            'Kerkstraat|7' => [flemish_match('Kerkstraat', '7', '2600', 'Antwerpen')],
            'Dorpstraat|5' => [flemish_match('Dorpsstraat', '5', '9000', 'Gent'), flemish_match('Dorpsstraat', '5', '9000', 'Gent', 'inGebruik', 95.0, '1')],
        ];
        return ['body' => json_encode(['adresMatches' => $answers[$q['straatnaam'] . '|' . $q['huisnummer']] ?? []])];
    }
    if (str_contains($url, 'geoportail')) {
        $results = str_contains($q['queryString'], 'Congregation') ? [
            ['accuracy' => 8, 'AddressDetails' => ['postnumber' => '2', 'street' => 'Rue de la Congrégation', 'zip' => '1352', 'locality' => 'Luxembourg']],
            ['accuracy' => 5, 'AddressDetails' => ['postnumber' => '', 'street' => 'Rue de la Congrégation', 'zip' => '1352', 'locality' => 'Luxembourg']],
        ] : [];
        return ['body' => json_encode(['success' => true, 'results' => $results])];
    }
    return ['body' => json_encode(['response' => ['docs' => []]])];
}
function wp_remote_retrieve_response_code(array $response): int {
    return 200;
}
function wp_remote_retrieve_body(array $response): string {
    return $response['body'];
}

require_once dirname(__DIR__) . '/includes/class-avpvh-address-normalizer.php';
require_once dirname(__DIR__) . '/includes/class-pdok.php';
require_once dirname(__DIR__) . '/includes/class-address-lookup.php';

function check(string $label, mixed $actual, mixed $expected): void {
    echo ($actual === $expected ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if ($actual !== $expected) {
        echo '  expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL;
        exit(1);
    }
}

$lookup = fn(string $street, string $number, string $postcode, string $city, string $country) => AVPVH_Address_Lookup::lookup(
    ['street' => $street, 'house_number' => $number, 'house_number_addition' => '', 'postal_code' => $postcode, 'city' => $city, 'country' => $country]
);

check('Flemish postcodes', array_map([AVPVH_Address_Lookup::class, 'is_flemish_postcode'], ['1000', '1500', '3999', '4000', '7999', '8000', '9999', 'B-2000']),
    [false, true, true, false, false, true, true, false]);

$r = $lookup('Grote Mrkt', '1', 'B-2000', 'Antwerpen', 'Belgium');
check('Flanders: typo corrected, only the address in use, prefix dropped', $r,
    ['fields' => ['street' => 'Grote Markt', 'house_number' => '1', 'house_number_addition' => '', 'postal_code' => '2000', 'city' => 'Antwerpen'], 'source' => 'Adressenregister Vlaanderen']);
check('Flanders: the stored town stays (Berchem, not municipality Antwerpen)', $lookup('Kerkstraat', '7', '2600', 'Berchem', 'België')['fields']['city'] ?? null, 'Berchem');
check('Flanders: no box stored -> street corrected, no box made up', $lookup('Dorpstraat', '5', '9000', 'Gent', 'België')['fields'] ?? null,
    ['street' => 'Dorpsstraat', 'house_number' => '5', 'house_number_addition' => '', 'postal_code' => '9000', 'city' => 'Gent']);
$before = count($test_requests);
check('Brussels is not asked', $lookup('Grote Markt', '1', '1000', 'Brussel', 'België'), null);
check('Wallonia is not asked', $lookup('Place Saint-Lambert', '1', '4000', 'Liège', 'België'), null);
check('no request for Brussels or Wallonia', count($test_requests), $before);

$r = $lookup('rue de la Congregation', '2', 'L-1352', 'Luxemburg', 'Luxembourg');
check('Luxembourg: official spelling, building-level match only', $r,
    ['fields' => ['street' => 'Rue de la Congrégation', 'house_number' => '2', 'house_number_addition' => '', 'postal_code' => '1352', 'city' => 'Luxembourg'], 'source' => 'geoportail.lu']);
check('Luxembourg: number in front of the street is understood', $lookup('2, rue de la Congregation', '', '1352', 'Luxembourg', 'Luxemburg')['fields']['house_number'] ?? null, '2');
check('Luxembourg: unknown address', $lookup('Nergensstraat', '1', '9999', 'Luxembourg', 'Luxemburg'), null);

$before = count($test_requests);
check('other countries are not asked', $lookup('Hauptstraße', '1', '10115', 'Berlin', 'Duitsland'), null);
check('no request for Germany', count($test_requests), $before);

$before = count($test_requests);
$lookup('Grote Mrkt', '1', '2000', 'Antwerpen', 'België');
check('cached answers are not fetched again', count($test_requests), $before);

echo "Address lookup tests: OK\n";
