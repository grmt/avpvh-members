<?php
/**
 * Tests AVPVH_DB::get_activity_delete_blockers() / delete_activity_if_unused()
 * with an in-memory fake $wpdb and filter stubs; no database, no real data.
 * Run: php scripts/test-activity-delete.php
 */

define('ABSPATH', __DIR__ . '/');
$GLOBALS['filters'] = [];
$GLOBALS['actions'] = [];

function __($s, $domain = '') { return $s; }
function _n($single, $plural, $n, $domain = '') { return $n === 1 ? $single : $plural; }
function add_filter($name, $fn) { $GLOBALS['filters'][$name][] = $fn; }
function has_filter($name) { return !empty($GLOBALS['filters'][$name]); }
function apply_filters($name, $value, ...$args) {
    foreach ($GLOBALS['filters'][$name] ?? [] as $fn) { $value = $fn($value, ...$args); }
    return $value;
}
function do_action($name, ...$args) { $GLOBALS['actions'][] = [$name, $args]; }

class Fake_Wpdb {
    public $prefix = 'wp_';
    public array $activities = [];
    public array $participation = [];
    public array $deleted = [];
    public function prepare($sql, ...$args) { return [$sql, $args]; }
    public function get_var($q) {
        [$sql, $args] = $q;
        if (str_contains($sql, 'avm_activity_participation')) {
            return count(array_filter($this->participation, fn($p) => $p === $args[0]));
        }
        return null;
    }
    public function get_row($q) {
        [, $args] = $q;
        return $this->activities[$args[0]] ?? null;
    }
    public function delete($table, $where) {
        $this->deleted[] = [$table, $where['id']];
        unset($this->activities[$where['id']]);
        return 1;
    }
}

$wpdb = new Fake_Wpdb();
require __DIR__ . '/../includes/class-db.php';

$failures = 0;
function check(string $name, bool $ok): void {
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$ok) { $failures++; }
}
function reset_fixture(): void {
    global $wpdb;
    $wpdb->activities = [
        1 => (object) ['id' => 1, 'name' => 'T-shirt', 'year' => 2026, 'type_name' => ''],
        2 => (object) ['id' => 2, 'name' => 'Kamp', 'year' => 2026, 'type_name' => 'Kamp'],
    ];
    $wpdb->participation = [2, 2, 2];
    $wpdb->deleted = [];
    $GLOBALS['actions'] = [];
}

// Without avpvh-bookkeeping loaded: only participation counts.
reset_fixture();
check('unused activity has no blockers', AVPVH_DB::get_activity_delete_blockers(1) === []);
check('participants block', AVPVH_DB::get_activity_delete_blockers(2) === ['3 deelnemers']);
check('blocked delete returns reasons', AVPVH_DB::delete_activity_if_unused(2) === ['3 deelnemers']);
check('blocked delete deletes nothing', $wpdb->deleted === [] && isset($wpdb->activities[2]));
check('unused delete succeeds', AVPVH_DB::delete_activity_if_unused(1) === []);
check('only the activity row is deleted', $wpdb->deleted === [['wp_avm_activities', 1]]);
check('avpvh_activity_deleted fired', ($GLOBALS['actions'][0][0] ?? '') === 'avpvh_activity_deleted' && $GLOBALS['actions'][0][1][0] === 1);
check('missing activity is reported, not deleted', AVPVH_DB::delete_activity_if_unused(99) === ['Activiteit niet gevonden.'] && count($wpdb->deleted) === 1);

// avpvh-bookkeeping loaded but without the filter: never delete.
define('AVBK_PLUGIN_DIR', '/fixture/');
reset_fixture();
$blockers = AVPVH_DB::get_activity_delete_blockers(1);
check('old bookkeeping blocks', count($blockers) === 1 && str_contains($blockers[0], 'avpvh-bookkeeping'));
check('old bookkeeping: nothing deleted', AVPVH_DB::delete_activity_if_unused(1) !== [] && $wpdb->deleted === []);

// avpvh-bookkeeping answering the filter.
add_filter('avpvh_activity_delete_blockers', function ($blockers, $activity_id) {
    if ($activity_id === 1) { $blockers[] = '2 tarieven'; }
    return $blockers;
});
reset_fixture();
check('filter receives activity id and adds reason', AVPVH_DB::get_activity_delete_blockers(1) === ['2 tarieven']);
check('filter reasons block the delete', AVPVH_DB::delete_activity_if_unused(1) === ['2 tarieven'] && $wpdb->deleted === []);
check('reasons combine', AVPVH_DB::get_activity_delete_blockers(2) === ['3 deelnemers']);
$wpdb->activities[3] = (object) ['id' => 3, 'name' => 'Leeg', 'year' => 2026, 'type_name' => ''];
check('bookkeeping says unused: deleted', AVPVH_DB::delete_activity_if_unused(3) === [] && $wpdb->deleted === [['wp_avm_activities', 3]]);

echo $failures ? "$failures FAILED\n" : "All passed\n";
exit($failures ? 1 : 0);
