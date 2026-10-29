<?php
declare(strict_types=1);

/**
 * Ad-hoc checks for which member pairs a non-admin granted Leden
 * samenvoegen may merge (AVPVH_Member_Merge::involves_elevated_access()).
 *
 * Run:
 *   php scripts/test-member-merge-access.php
 */

define('ABSPATH', __DIR__ . '/');

function __(string $text, string $domain = ''): string {
    return $text;
}

$test_wp_users = []; // wp user id => [roles, caps]

function get_userdata(int $id): object|false {
    return isset($GLOBALS['test_wp_users'][$id]) ? (object) ['ID' => $id, 'roles' => $GLOBALS['test_wp_users'][$id][0]] : false;
}
function user_can(object $user, string $cap): bool {
    return in_array($cap, $GLOBALS['test_wp_users'][$user->ID][1], true);
}

require_once dirname(__DIR__) . '/includes/class-roles.php';
require_once dirname(__DIR__) . '/includes/class-member-merge.php';

function account(array $groups, bool $exists = true, bool $groups_known = true): array {
    return ['groups' => $groups, 'exists' => $exists, 'groups_known' => $groups_known];
}

function check_elevated(string $label, array $keep_account, array $remove_account, bool $expected, ?int $keep_wp_user = null): void {
    $method = new ReflectionMethod(AVPVH_Member_Merge::class, 'involves_elevated_access');
    $actual = $method->invoke(
        null,
        (object) ['wp_user_id' => $keep_wp_user],
        (object) ['wp_user_id' => null],
        ['keep' => $keep_account, 'remove' => $remove_account]
    );
    echo ($actual === $expected ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if ($actual !== $expected) {
        exit(1);
    }
}

$test_wp_users = [
    1 => [['subscriber'], []],
    2 => [['administrator'], ['manage_options', 'edit_users']],
    3 => [['editor'], []],
];

check_elevated('two ordinary members', account(['leden']), account(['ex-leden']), false);
check_elevated('duplicate without an account', account(['leden']), account([], false, false), false);
check_elevated('ordinary member who has logged in', account(['leden']), account([]), false, 1);
check_elevated('board role on the kept member', account(['leden', 'penningmeester']), account([]), true);
check_elevated('bestuur on the duplicate', account(['leden']), account(['bestuur']), true);
check_elevated('it-beheer', account(['it-beheer']), account([]), true);
check_elevated('bloggers', account(['bloggers']), account([]), true);
check_elevated('boek group', account(['boek']), account([]), true);
check_elevated('groups of an existing account unreadable', account([], true, false), account([]), true);
check_elevated('WP administrator', account(['leden']), account([]), true, 2);
check_elevated('WP editor role', account(['leden']), account([]), true, 3);

echo "Member merge access tests: OK\n";
