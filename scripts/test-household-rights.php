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

// Execute the directional predicates the production query actually sends.
// Fixtures follow the stored meaning: related_member is [label] of member.
function current_time(string $format): string { return $format === 'Y' ? '2026' : '2026-06-15'; }
class AVPVH_Directory_Cache {
    public static function table(): string { return 'wp_avm_directory_users'; }
}
$wpdb = new class {
    public string $prefix = 'wp_';
    public array $relationships = [];
    public array $members = [];
    public function prepare(string $sql, mixed ...$params): string {
        $index = 0;
        return preg_replace_callback('/%[ds]/', static function (array $match) use ($params, &$index): string {
            $value = $params[$index++];
            return $match[0] === '%d' ? (string) (int) $value : "'" . addslashes((string) $value) . "'";
        }, $sql);
    }
    public function get_var(string $sql): ?int {
        preg_match_all("/r\.member_id = (\d+) AND r\.related_member_id = (\d+) AND l\.code = '([^']+)'/", $sql, $predicates, PREG_SET_ORDER);
        foreach ($this->relationships as [$member, $related, $label]) {
            foreach ($predicates as $predicate) {
                if ((int) $predicate[1] === $member && (int) $predicate[2] === $related && $predicate[3] === $label) {
                    return 1;
                }
            }
        }
        return null;
    }
    public function get_row(string $sql): ?object {
        return preg_match('/WHERE m\.id = (\d+)/', $sql, $match) ? ($this->members[(int) $match[1]] ?? null) : null;
    }
    public function get_results(string $sql): array { return array_values($this->members); }
};
$wpdb->members = [
    100 => (object) ['id' => 100, 'status' => 'active', 'birth_year' => 1980],
    200 => (object) ['id' => 200, 'status' => 'active', 'birth_year' => 2015],
];
foreach ([[200, 100, 'ouder'], [100, 200, 'kind']] as $edge) {
    $wpdb->relationships = [$edge];
    check("{$edge[2]} edge grants authority from parent to child", AVPVH_DB::is_parent_of(100, 200));
    check("{$edge[2]} edge grants no authority from child to parent", !AVPVH_DB::is_parent_of(200, 100));
    check("{$edge[2]} edge lets a parent manage a minor living elsewhere", array_column(AVPVH_DB::get_manageable_members(100), 'id') === [100, 200]);
    check("{$edge[2]} edge does not let a child manage the parent", array_column(AVPVH_DB::get_manageable_members(200), 'id') === [200]);
}
$wpdb->members[200]->birth_year = 2000;
check('a parent cannot manage an adult child living elsewhere', array_column(AVPVH_DB::get_manageable_members(100), 'id') === [100]);

echo $failures ? "\n$failures check(s) failed.\n" : "\nHousehold rights tests: OK\n";
exit($failures ? 1 : 0);
