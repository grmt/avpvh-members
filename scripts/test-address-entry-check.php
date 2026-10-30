<?php
declare(strict_types=1);

/**
 * Ad-hoc checks for AVPVH_Address_Entry_Check: which addresses have a
 * register to ask, and the per-user limit on lookups.
 *
 * Run:
 *   php scripts/test-address-entry-check.php
 */

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('DAY_IN_SECONDS', 86400);

$test_transients = [];
function get_transient(string $key): mixed {
    return $GLOBALS['test_transients'][$key] ?? false;
}
function set_transient(string $key, mixed $value, int $ttl): bool {
    $GLOBALS['test_transients'][$key] = $value;
    return true;
}

require_once dirname(__DIR__) . '/includes/class-avpvh-address-normalizer.php';
require_once dirname(__DIR__) . '/includes/class-pdok.php';
require_once dirname(__DIR__) . '/includes/class-address-lookup.php';
require_once dirname(__DIR__) . '/includes/class-address-entry-check.php';

function check(string $label, mixed $actual, mixed $expected): void {
    echo ($actual === $expected ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if ($actual !== $expected) {
        echo '  expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL;
        exit(1);
    }
}

$checkable = new ReflectionMethod(AVPVH_Address_Entry_Check::class, 'is_checkable');
$is = fn(string $country, string $postcode) => $checkable->invoke(null, ['country' => $country, 'postal_code' => $postcode]);
check('Netherlands is checked', $is('Nederland', '1234 AB'), true);
check('no country counts as the Netherlands', $is('', '1234 AB'), true);
check('Luxembourg is checked', $is('Luxemburg', '1352'), true);
check('Flanders is checked', $is('België', '2000'), true);
check('Brussels is not', $is('België', '1000'), false);
check('Wallonia is not', $is('België', '4000'), false);
check('Germany is not', $is('Duitsland', '10115'), false);

$limit = new ReflectionMethod(AVPVH_Address_Entry_Check::class, 'within_limit');
$allowed = 0;
for ($i = 0; $i < AVPVH_Address_Entry_Check::LIMIT + 5; $i++) {
    $allowed += (int) $limit->invoke(null, 'u7', AVPVH_Address_Entry_Check::LIMIT);
}
check('at most LIMIT lookups per user', $allowed, AVPVH_Address_Entry_Check::LIMIT);
check('another user has their own limit', $limit->invoke(null, 'u8', AVPVH_Address_Entry_Check::LIMIT), true);
$allowed = 0;
for ($i = 0; $i < 40; $i++) {
    $allowed += (int) $limit->invoke(null, 'ipabc', AVPVH_Address_Entry_Check::ANONYMOUS_LIMIT);
}
check('anonymous: the lower limit per IP address', $allowed, AVPVH_Address_Entry_Check::ANONYMOUS_LIMIT);

echo "Address entry check tests: OK\n";
