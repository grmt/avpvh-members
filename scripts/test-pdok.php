<?php
declare(strict_types=1);

/**
 * Ad-hoc checks for AVPVH_PDOK::lookup(): picking the one official address
 * for a postcode + house number + addition, and caching. No network: PDOK
 * answers are canned.
 *
 * Run:
 *   php scripts/test-pdok.php
 */

define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);

$test_transients = [];
$test_requests = [];
$test_answers = [
    '1234AB|40' => [
        ['straatnaam' => 'Kerkstraat', 'huisnummer' => 40, 'huisnummertoevoeging' => '1', 'postcode' => '1234AB', 'woonplaatsnaam' => 'Utrecht'],
        ['straatnaam' => 'Kerkstraat', 'huisnummer' => 40, 'huisnummertoevoeging' => 'H', 'postcode' => '1234AB', 'woonplaatsnaam' => 'Utrecht'],
    ],
    '1234AB|12' => [
        ['straatnaam' => 'Kerkstraat', 'huisnummer' => 12, 'huisletter' => 'A', 'postcode' => '1234AB', 'woonplaatsnaam' => 'Utrecht'],
    ],
    '5678CD|9' => [
        ['straatnaam' => 'Dorpsstraat', 'huisnummer' => 9, 'postcode' => '5678CD', 'woonplaatsnaam' => 'Eindhoven'],
    ],
    '9999ZZ|1' => [],
];

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
function wp_remote_get(string $url, array $args = []): array {
    $GLOBALS['test_requests'][] = $url;
    if (str_contains($url, '?q=')) {
        // Free-text search: the same number in the right postcode, and in another town.
        return ['body' => json_encode(['response' => ['docs' => [
            ['straatnaam' => 'Johanna van Lindenweg', 'huisnummer' => 38, 'postcode' => '4321AW', 'woonplaatsnaam' => 'Breda'],
            ['straatnaam' => 'Johanna van Lindenweg', 'huisnummer' => 38, 'postcode' => '9999AA', 'woonplaatsnaam' => 'Elders'],
        ]]])];
    }
    preg_match('/postcode:(\w+)&fq=huisnummer:(\d+)/', $url, $m);
    return ['body' => json_encode(['response' => ['docs' => $GLOBALS['test_answers'][$m[1] . '|' . $m[2]] ?? []]])];
}
function wp_remote_retrieve_response_code(array $response): int {
    return 200;
}
function wp_remote_retrieve_body(array $response): string {
    return $response['body'];
}

require_once dirname(__DIR__) . '/includes/class-avpvh-address-normalizer.php';
require_once dirname(__DIR__) . '/includes/class-pdok.php';

function check(string $label, mixed $actual, mixed $expected): void {
    echo ($actual === $expected ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if ($actual !== $expected) {
        echo '  expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL;
        exit(1);
    }
}

$lookup = fn(string $number, string $addition, string $postcode) => AVPVH_PDOK::lookup(
    ['house_number' => $number, 'house_number_addition' => $addition, 'postal_code' => $postcode]
);

check('floor addition picks 40-1 out of 40-1 and 40-H', $lookup('40', '1', '1234 ab'),
    ['street' => 'Kerkstraat', 'house_number' => '40', 'house_number_addition' => '1', 'postal_code' => '1234 AB', 'city' => 'Utrecht']);
check('"1 hoog" still in the number field also finds 40-1', $lookup('40 1 hoog', '', '1234AB')['house_number_addition'] ?? null, '1');
check('ground floor', $lookup('40', 'H', '1234AB')['house_number_addition'] ?? null, 'H');
check('no addition while the BAG only has 40-1 and 40-H: no suggestion', $lookup('40', '', '1234AB'), null);
check('huisletter', $lookup('12', 'A', '1234AB')['house_number_addition'] ?? null, 'A');
check('plain address', $lookup('9', '', '5678 CD')['street'] ?? null, 'Dorpsstraat');
check('unknown address', $lookup('1', '', '9999ZZ'), null);
check('foreign postcode is not looked up', $lookup('1', '', 'B-1000'), null);
check('no number is not looked up', $lookup('ong.', '', '1234AB'), null);

$found = AVPVH_PDOK::lookup(['street' => 'J. van Lindenweg', 'house_number' => '38', 'house_number_addition' => '', 'postal_code' => '4321 AV', 'city' => 'Breda']);
check('mistyped postcode: found by street, number and town', $found,
    ['street' => 'Johanna van Lindenweg', 'house_number' => '38', 'house_number_addition' => '', 'postal_code' => '4321 AW', 'city' => 'Breda']);
check('mistyped postcode without a town: no suggestion',
    AVPVH_PDOK::lookup(['street' => 'J. van Lindenweg', 'house_number' => '38', 'postal_code' => '4321 AV', 'city' => '']), null);

$before = count($test_requests);
$lookup('40', '1', '1234AB');
$lookup('1', '', '9999ZZ');
check('cached answers (also "not found") are not fetched again', count($test_requests), $before);

echo "PDOK tests: OK\n";
