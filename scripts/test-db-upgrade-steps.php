<?php
declare(strict_types=1);

/**
 * Runs AVPVH_DB::maybe_upgrade() against a recording $wpdb from several
 * starting versions, with the probed columns and tables present and absent,
 * and checks the order of the steps and their statements.
 *
 * AVPVH_DB_UPGRADE_TEST_DUMP=<file> writes every recorded run, to compare two
 * versions of the code (AVPVH_DB_UPGRADE_TEST_CLASS=<copy of class-db.php>).
 *
 * Run:
 *   php scripts/test-db-upgrade-steps.php
 */

$abspath = sys_get_temp_dir() . '/avpvh-db-upgrade-test-' . getmypid() . '/';
@mkdir($abspath . 'wp-admin/includes', 0777, true);
file_put_contents($abspath . 'wp-admin/includes/upgrade.php', '<?php');
define('ABSPATH', $abspath);
define('AVPVH_PLUGIN_DIR', dirname(__DIR__) . '/');

$GLOBALS['log'] = [];
$GLOBALS['options'] = [];
function note(string $what): void { $GLOBALS['log'][] = preg_replace('/\s+/', ' ', trim($what)); }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value) { note("update_option $key=$value"); $GLOBALS['options'][$key] = $value; return true; }
function delete_option($key) { note("delete_option $key"); return true; }
function dbDelta($sql) { note("dbDelta $sql"); return []; }

class Recording_WPDB {
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    public function __construct(public bool $exists) {}
    public function get_charset_collate() { return 'CHARSET'; }
    public function prepare($sql, ...$args) { return vsprintf(str_replace(['%s', '%d'], ["'%s'", '%d'], $sql), is_array($args[0] ?? null) ? $args[0] : $args); }
    public function query($sql) { note("query $sql"); return 1; }
    public function get_var($sql) { note("get_var $sql"); return $this->exists ? '1' : null; }
    public function get_col($sql) { note("get_col $sql"); return []; }
    public function get_results($sql) {
        note("get_results $sql");
        return str_contains($sql, 'passport_name') ? [(object) ['id' => 4, 'passport_name' => 'Roepnaam (Twee  Namen) [x]']] : [];
    }
    public function update($table, $data, $where, ...$f) { note("update $table " . json_encode($data) . ' ' . json_encode($where)); return 1; }
    public function insert($table, $data, ...$f) { note("insert $table " . json_encode($data)); $this->insert_id++; return 1; }
}

class AVPVH_Address_Normalizer {
    public static function clean(array $a) { return $a; }
    public static function split_house_number(string $n) { return [$n, '']; }
}

require_once getenv('AVPVH_DB_UPGRADE_TEST_CLASS') ?: dirname(__DIR__) . '/includes/class-db.php';

function upgrade_from(string $version, bool $exists): array {
    $GLOBALS['log'] = [];
    $GLOBALS['options'] = ['avpvh_db_version' => $version];
    $GLOBALS['wpdb'] = new Recording_WPDB($exists);
    AVPVH_DB::maybe_upgrade();
    if (getenv('AVPVH_DB_UPGRADE_TEST_DUMP')) {
        file_put_contents(getenv('AVPVH_DB_UPGRADE_TEST_DUMP'), "== $version " . ($exists ? 'exists' : 'missing') . "\n" . implode("\n", $GLOBALS['log']) . "\n", FILE_APPEND);
    }
    return $GLOBALS['log'];
}
function check(string $label, bool $ok): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) { exit(1); }
}
$versions = static fn(array $log): array => array_values(array_map(
    fn($l) => substr($l, strlen('update_option avpvh_db_version=')),
    array_filter($log, fn($l) => str_starts_with($l, 'update_option avpvh_db_version='))
));
$has = static fn(array $log, string $needle): bool => (bool) array_filter($log, fn($l) => str_contains($l, $needle));
$before = static function (array $log, string $a, string $b): bool {
    $i = array_key_first(array_filter($log, fn($l) => str_contains($l, $a)));
    $j = array_key_first(array_filter($log, fn($l) => str_contains($l, $b)));
    return $i !== null && $j !== null && $i < $j;
};

$all = ['1.2', '1.3', '1.4', '1.5', '1.6', '1.7', '1.8', '1.9', '2.0', '2.1', '2.2', '2.3', '2.4', '2.5', '2.6', '2.7', '2.8', '2.9',
    '2.10', '2.11', '2.12', '2.13', '2.14', '2.15', '2.16', '2.17', '2.18', '2.19', '2.21', '2.22', '2.23', '2.24', '2.25', '2.26',
    '2.27', '2.28', '2.29', '2.30', '2.31', '2.32', '2.33', '2.34'];

$log = upgrade_from('0', false);
check('fresh install: straight to the schema version', $versions($log) === [AVPVH_DB::SCHEMA_VERSION] && !$has($log, 'ALTER TABLE'));

$log = upgrade_from('1.0', true);
check('from 1.0: every step, in order, recording each version', $versions($log) === $all);
check('last step reaches the schema version', end($all) === AVPVH_DB::SCHEMA_VERSION);
check('1.8: consent backfill before the new default', $before($log, "SET directory_consent = 'granted'", "MODIFY directory_consent"));
check('2.7: existing baptism_name folded into passport_name, then dropped', $before($log, 'SET passport_name = baptism_name', 'DROP COLUMN baptism_name'));
check('2.8: brackets stripped from passport names', $has($log, 'update wp_avm_members {"passport_name":"Roepnaam Twee Namen x"} {"id":4}'));
check('2.13: kenmerk exists, so location is not renamed', !$has($log, 'CHANGE location kenmerk'));
check('2.14: camp tables renamed when present', $has($log, 'RENAME TABLE wp_avm_camps TO wp_avm_activities') && $has($log, 'share_activity_history'));
check('2.15: verified_at exists, so no backfill', !$has($log, 'SET verified_at = created_at'));
check('2.32: LLDAP settings removed', $has($log, 'delete_option avpvh_lldap_password'));

$log = upgrade_from('1.0', false);
check('missing columns: added', $has($log, 'ADD COLUMN is_student') && $has($log, 'ADD COLUMN initials') && $has($log, 'ADD COLUMN birth_year')
    && $has($log, 'ADD COLUMN sets_inactive') && $has($log, 'ADD COLUMN gallery_taggable') && $has($log, 'CHANGE location kenmerk'));
check('missing verified_at: added and backfilled', $before($log, 'ADD COLUMN verified_at', 'SET verified_at = created_at'));
check('missing camp tables / baptism_name: left alone', !$has($log, 'RENAME TABLE wp_avm_camps') && !$has($log, 'DROP COLUMN baptism_name'));

$log = upgrade_from('2.13', true);
check('from 2.13: only the later steps', $versions($log) === array_slice($all, array_search('2.14', $all, true)) && !$has($log, 'ADD COLUMN suffix'));

$log = upgrade_from('2.20', true);
check('from 2.20: continues at 2.21', $versions($log)[0] === '2.21');

$log = upgrade_from(AVPVH_DB::SCHEMA_VERSION, true);
check('up to date: nothing runs', $log === []);

@unlink($abspath . 'wp-admin/includes/upgrade.php');
echo "DB upgrade step tests: OK\n";
