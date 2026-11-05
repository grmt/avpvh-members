<?php
declare(strict_types=1);

/**
 * Checks for AVPVH_DB::addresses_grant_household(): a shared address gives
 * household rights only when neither row was entered by a member and left
 * unconfirmed (member_entered), so copying someone else's address into
 * your own profile gives no access to them.
 *
 * Run:
 *   php scripts/test-household-rights.php
 */

define('ABSPATH', __DIR__ . '/');
require_once dirname(__DIR__) . '/includes/class-avpvh-address-normalizer.php';
require_once dirname(__DIR__) . '/includes/class-avpvh-address-matcher.php';
require_once dirname(__DIR__) . '/includes/class-db.php';

$failures = 0;
function check(string $label, bool $ok): void {
    global $failures;
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    $failures += $ok ? 0 : 1;
}

function address(string $street, string $number, string $postal, int $member_entered = 0): object {
    return (object) [
        'street' => $street, 'house_number' => $number, 'house_number_addition' => '',
        'postal_code' => $postal, 'city' => 'Teststad', 'country' => 'Nederland',
        'member_entered' => $member_entered,
    ];
}

$grant = [AVPVH_DB::class, 'addresses_grant_household'];
$home  = address('Teststraat', '1', '1234 AB');

check('same confirmed address gives household rights', $grant($home, address('Teststraat', '1', '1234 AB')));
check('spelling differences still match', $grant($home, address(' teststraat ', '1', '1234ab')));
check('a copied address the member entered gives no rights', !$grant($home, address('Teststraat', '1', '1234 AB', 1)));
check('also not when the target entered theirs', !$grant(address('Teststraat', '1', '1234 AB', 1), $home));
check('a confirmed copy (board) gives rights again', $grant($home, address('Teststraat', '1', '1234 AB', 0)));
check('different house number gives no rights', !$grant($home, address('Teststraat', '3', '1234 AB')));
check('no address on one side gives no rights', !$grant($home, null));
check('empty street gives no rights', !$grant($home, address('', '1', '1234 AB')));
check('rows from before the column (no member_entered) count as confirmed', $grant($home, (object) array_diff_key((array) address('Teststraat', '1', '1234 AB'), ['member_entered' => 1])));

echo $failures ? "\n$failures check(s) failed.\n" : "\nHousehold rights tests: OK\n";
exit($failures ? 1 : 0);
