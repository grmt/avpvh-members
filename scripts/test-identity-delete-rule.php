<?php
declare(strict_types=1);

/**
 * Ad-hoc checks for AVPVH_DB::can_delete_identity(), the one rule behind
 * "Verwijder" on Ledendetail and on the member's own profile page.
 *
 * Run:
 *   php scripts/test-identity-delete-rule.php
 */

define('ABSPATH', __DIR__ . '/');
define('AVPVH_LLDAP_DB', 'lldap');
require_once dirname(__DIR__) . '/includes/class-db.php';

function check(string $label, mixed $actual, mixed $expected): void {
    echo ($actual === $expected ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if ($actual !== $expected) {
        echo '  expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL;
        exit(1);
    }
}

$identity = fn(int $id, bool $verified): object => (object) ['id' => $id, 'verified_at' => $verified ? '2026-01-01 00:00:00' : null];
$can = [AVPVH_DB::class, 'can_delete_identity'];

$one_verified = [$identity(1, true), $identity(2, false)];
check('unverified next to the only verified one can go', $can($one_verified, 2), true);
check('the only verified one stays', $can($one_verified, 1), false);
check('a verified one can go while another verified one remains', $can([$identity(1, true), $identity(2, true)], 1), true);
check('unverified when none is verified can go', $can([$identity(1, false)], 1), true);
check('unknown id is refused', $can($one_verified, 9), false);

echo "Identity delete rule tests: OK\n";
