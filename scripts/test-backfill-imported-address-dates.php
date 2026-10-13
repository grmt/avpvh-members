<?php
/** SQL integration tests: disposable address_fixture DB and real WordPress wpdb. */
if (getenv('AVPVH_ADDRESS_TEST_DB') !== 'address_fixture') {
    fwrite(STDERR, "Use a disposable address_fixture database.\n");
    exit(1);
}
define('ABSPATH', '/fixture/');
define('WP_DEBUG', false);
define('WP_DEBUG_DISPLAY', false);
define('WP_CONTENT_DIR', '/fixture/');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
function __($value, $domain = '') { return $value; }
function wp_load_translations_early() {}
function wp_debug_backtrace_summary() { return ''; }
function wp_get_wp_version() { return '6.8'; }
function mbstring_binary_safe_encoding() {}
function reset_mbstring_encoding() {}
$GLOBALS['address_fixture_filters'] = [];
function add_filter($name, $callback, $priority = 10) { $GLOBALS['address_fixture_filters'][$name][] = $callback; }
function has_filter($name) { return !empty($GLOBALS['address_fixture_filters'][$name]); }
function apply_filters($name, $value) {
    foreach ($GLOBALS['address_fixture_filters'][$name] ?? [] as $callback) {
        $value = $callback($value);
    }
    return $value;
}
function is_multisite() { return false; }
function absint($value) { return abs((int) $value); }
require getenv('AVPVH_ADDRESS_TEST_WPDB');
class AVPVH_Address_Fixture_DB extends wpdb
{
    public bool $fail_update = false;
    public function query($sql)
    {
        if ($this->fail_update && str_contains($sql, 'UPDATE pdates_avm_addresses') && str_contains($sql, 'id = 2')) {
            $this->last_error = 'fixture failure';
            return false;
        }
        return parent::query($sql);
    }
}
$wpdb = new AVPVH_Address_Fixture_DB('root', 'address-fixture-only', 'address_fixture', getenv('AVPVH_ADDRESS_TEST_HOST'));
$wpdb->set_prefix('pdates_');
require __DIR__ . '/backfill-imported-address-dates.php';
function avpvh_address_test_query($sql): void
{
    global $wpdb;
    if ($wpdb->query($sql) === false) {
        throw new RuntimeException('Fixture SQL failed: ' . $wpdb->last_error);
    }
}
function avpvh_address_test_check($condition, $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
    echo "PASS $label\n";
}
function avpvh_address_test_reset(): void
{
    avpvh_address_test_query('DELETE FROM pdates_avm_addresses');
    avpvh_address_test_query('DELETE FROM pdates_avm_members');
    avpvh_address_test_query("INSERT INTO pdates_avm_members VALUES(1,'active'),(2,'inactive'),(3,'visitor')");
    avpvh_address_test_query("INSERT INTO pdates_avm_addresses VALUES
        (1,1,'Legacy fictive',NULL,NULL), (2,2,'Zero fictive','0000-00-00',NULL),
        (3,1,'Current fictive','2026-01-01',NULL), (4,3,'Visitor fictive',NULL,NULL),
        (5,1,'Historic fictive','2024-01-01','2024-12-31'),
        (6,1,'Expired undated fictive',NULL,'2025-12-31')");
}
$snapshot_path = tempnam(sys_get_temp_dir(), 'address-fixture-');
avpvh_address_test_query('CREATE TABLE pdates_avm_members (id INT PRIMARY KEY, status VARCHAR(20)) ENGINE=InnoDB');
avpvh_address_test_query('CREATE TABLE pdates_avm_addresses (id INT PRIMARY KEY, member_id INT, street VARCHAR(255), valid_from DATE NULL, valid_until DATE NULL) ENGINE=InnoDB');
try {
    avpvh_address_test_reset();
    $before = $wpdb->get_results('SELECT * FROM pdates_avm_addresses ORDER BY id', ARRAY_A);
    $plan = AVPVH_Imported_Address_Dates::plan();
    avpvh_address_test_check(count($plan['rows']) === 3, 'only undated member addresses selected, including zero dates');
    avpvh_address_test_check($wpdb->get_results('SELECT * FROM pdates_avm_addresses ORDER BY id', ARRAY_A) === $before, 'dry-run does not write');
    file_put_contents($snapshot_path, json_encode($plan));
    $result = AVPVH_Imported_Address_Dates::apply($snapshot_path);
    avpvh_address_test_check($result['updated'] === 3, 'eligible addresses receive baseline');
    $after = $wpdb->get_results('SELECT * FROM pdates_avm_addresses ORDER BY id', ARRAY_A);
    foreach ($before as $index => $row) {
        if (in_array((int) $row['id'], [1, 2, 6], true)) {
            $row['valid_from'] = '2025-01-01';
        }
        avpvh_address_test_check($after[$index] === $row, 'address content, end dates, visitors and explicit dates preserved: fixture ' . $row['id']);
    }
    avpvh_address_test_check((int) $wpdb->get_var("SELECT id FROM pdates_avm_addresses WHERE member_id=1 AND (valid_until IS NULL OR valid_until >= CURDATE()) ORDER BY valid_from DESC,id DESC LIMIT 1") === 3, 'later address remains current');
    file_put_contents($snapshot_path, json_encode(AVPVH_Imported_Address_Dates::plan()));
    avpvh_address_test_check(AVPVH_Imported_Address_Dates::apply($snapshot_path)['updated'] === 0, 'repeat run is idempotent');

    avpvh_address_test_reset();
    file_put_contents($snapshot_path, json_encode(AVPVH_Imported_Address_Dates::plan()));
    avpvh_address_test_query("UPDATE pdates_avm_addresses SET valid_from='2026-02-01' WHERE id=3");
    try {
        AVPVH_Imported_Address_Dates::apply($snapshot_path);
        throw new LogicException('Stale snapshot accepted');
    } catch (RuntimeException $error) {
        avpvh_address_test_check(str_contains($error->getMessage(), 'changed since'), 'changed data blocks stale snapshot');
    }
    avpvh_address_test_check($wpdb->get_var('SELECT valid_from FROM pdates_avm_addresses WHERE id=1') === null, 'stale snapshot makes no changes');

    avpvh_address_test_reset();
    file_put_contents($snapshot_path, json_encode(AVPVH_Imported_Address_Dates::plan()));
    $wpdb->fail_update = true;
    try {
        AVPVH_Imported_Address_Dates::apply($snapshot_path);
        throw new LogicException('Injected failure accepted');
    } catch (RuntimeException $error) {
        avpvh_address_test_check(str_contains($error->getMessage(), 'query failed'), 'SQL failure stops backfill');
    }
    $wpdb->fail_update = false;
    avpvh_address_test_check($wpdb->get_var('SELECT valid_from FROM pdates_avm_addresses WHERE id=1') === null, 'SQL failure rolls back earlier updates');

    avpvh_address_test_query("UPDATE pdates_avm_addresses SET valid_until='2024-12-31' WHERE id=1");
    try {
        AVPVH_Imported_Address_Dates::plan();
        throw new LogicException('Invalid date range accepted');
    } catch (RuntimeException $error) {
        avpvh_address_test_check(str_contains($error->getMessage(), 'ends before'), 'incompatible historical end date requires review');
    }
    echo "All address backfill integration tests passed.\n";
} finally {
    avpvh_address_test_query('DROP TABLE pdates_avm_addresses');
    avpvh_address_test_query('DROP TABLE pdates_avm_members');
    unlink($snapshot_path);
}
