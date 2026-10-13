<?php
/**
 * Integration tests against a DISPOSABLE MariaDB database; no real identities.
 * Set AVPVH_VISITOR_TEST_DB=visitor_fixtures and AVPVH_VISITOR_TEST_WPDB to a
 * WordPress class-wpdb.php copy. All tables in that database are fixture-owned.
 * Directory/Drive/WP account APIs are fakes; SQL is the real WordPress adapter.
 */
namespace Avpvh {
    class Options { public static $share_folder; }
}
namespace Avpvh\Frontend {
    class Share_Drive {
        public static $client;
        public static function drive() { return self::$client; }
    }
}
namespace {
if (getenv('AVPVH_VISITOR_TEST_DB') !== 'visitor_fixtures') {
    fwrite(STDERR, "Use a disposable visitor_fixtures database.\n"); exit(1);
}
define('ABSPATH', '/fixture/');
define('WP_DEBUG', false); define('WP_DEBUG_DISPLAY', false); define('WP_CONTENT_DIR', '/fixture/');
define('DB_CHARSET', 'utf8mb4'); define('DB_COLLATE', '');
$GLOBALS['admin'] = true; $GLOBALS['user_roles'] = []; $GLOBALS['filters'] = [];
function __($s, $domain = '') { return $s; }
function wp_json_encode($v) { return json_encode($v); }
function wp_salt($scheme) { return 'fictitious-fixture-salt-' . $scheme; }
function current_user_can($cap) { return $GLOBALS['admin']; }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function esc_url($value) { return esc_html($value); }
function esc_html__($value, $domain = '') { return esc_html($value); }
function esc_html_e($value, $domain = '') { echo esc_html($value); }
function esc_attr_e($value, $domain = '') { echo esc_attr($value); }
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function absint($value) { return abs((int) $value); }
function wp_unslash($value) { return $value; }
function sanitize_text_field($value) { return trim($value); }
function avpvh_format_name($member) { return $member->first_name . ' ' . $member->last_name; }
function wp_nonce_field($action) { echo '<input type="hidden" name="_wpnonce" value="' . hash('sha256', $action . '-fixture') . '">'; }
class Fixture_Controller_Result extends \RuntimeException {}
function wp_die($message, $title, $args) { throw new Fixture_Controller_Result($message, $args['response']); }
function wp_safe_redirect($url) { throw new Fixture_Controller_Result($url, 302); }
function check_admin_referer($action) {
    if (($_POST['_wpnonce'] ?? '') !== hash('sha256', $action . '-fixture')) { throw new Fixture_Controller_Result('nonce failed', 403); }
}
function add_action(...$args) {}
function user_can($user, $cap) { return $GLOBALS['privileged_users'][$user->ID] ?? false; }
function get_current_user_id() { return 999; }
function is_multisite() { return false; }
function is_wp_error($v) { return $v instanceof WP_Error; }
function wp_load_translations_early() {}
function wp_get_wp_version() { return '6.8'; }
function wp_debug_backtrace_summary() { return ''; }
function mbstring_binary_safe_encoding() {}
function reset_mbstring_encoding() {}
function add_filter($name, $fn, $priority = 10) { $GLOBALS['filters'][$name][] = $fn; }
function has_filter($name) { return !empty($GLOBALS['filters'][$name]); }
function apply_filters($name, $value) {
    foreach ($GLOBALS['filters'][$name] ?? [] as $fn) { $value = $fn($value); }
    return $value;
}
function clean_user_cache($id) {}
function delete_transient($key) {
    global $wpdb;
    $wpdb->delete($wpdb->options, ['option_name' => '_transient_' . $key]);
    $wpdb->delete($wpdb->options, ['option_name' => '_transient_timeout_' . $key]);
}
function get_transient($key) {
    global $wpdb;
    return $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", '_transient_' . $key)) ?: false;
}
function set_transient($key, $value, $ttl) {
    global $wpdb;
    $wpdb->replace($wpdb->options, ['option_name' => '_transient_' . $key, 'option_value' => $value]);
}
function get_userdata($id) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->users} WHERE ID = %d", $id));
    if ($row) { $row->roles = $GLOBALS['user_roles'][$id] ?? ['subscriber']; }
    return $row ?: false;
}
function wp_delete_user($id) {
    global $wpdb;
    $wpdb->delete($wpdb->usermeta, ['user_id' => $id]);
    return $wpdb->delete($wpdb->users, ['ID' => $id]) === 1;
}
class WP_Error {
    public function __construct(public string $code, private string $message) {}
    public function get_error_message() { return $this->message; }
}
class AVPVH_Directory {
    public static array $accounts = [];
    public static array $groups = [];
    public static int $calls = 0;
    public static bool $fail = false;
    public static $on_delete = null;
    public static function get_user($uid) { return self::$accounts[$uid] ?? null; }
    public static function get_user_groups($uid) { return self::$groups[$uid] ?? []; }
    public static function delete_user($uid) {
        global $wpdb;
        self::$calls++;
        if (self::$on_delete) { (self::$on_delete)(); }
        if (self::$fail) { return new WP_Error('ldap', 'fixture directory failure'); }
        unset(self::$accounts[$uid]);
        $wpdb->delete($wpdb->prefix . 'avm_directory_users', ['user_id' => $uid]);
        return true;
    }
    public static function forget_groups($uid) {}
}
class AVBK_Photo_Share { const ROOT_FOLDER_ID = 'fixture-upload-root'; }
class Fixture_Files {
    public array $folders = [];
    public array $removed = [];
    public string $fail_id = '';
    public function get($id, $options) {
        if (!isset($this->folders[$id])) { throw new \RuntimeException('missing', 404); }
        return new class($this->folders[$id]) {
            public function __construct(private string $parent) {}
            public function getMimeType() { return 'application/vnd.google-apps.folder'; }
            public function getParents() { return [$this->parent]; }
        };
    }
    public function delete($id, $options) {
        if ($id === $this->fail_id) { throw new \RuntimeException('fixture API failure', 403); }
        $this->removed[] = $id; unset($this->folders[$id]);
    }
}
require getenv('AVPVH_VISITOR_TEST_WPDB');
class Fixture_WPDB extends \wpdb {
    public string $fail_delete = '';
    public function query($query) {
        if ($this->fail_delete !== '' && str_starts_with($query, 'DELETE FROM `' . $this->fail_delete . '`')) {
            $this->last_error = 'fixture failure'; return false;
        }
        return parent::query($query);
    }
}
$wpdb = new Fixture_WPDB('root', getenv('AVPVH_VISITOR_TEST_PASSWORD'), 'visitor_fixtures', getenv('AVPVH_VISITOR_TEST_HOST'));
$wpdb->set_prefix('vdel_');
require getenv('AVPVH_VISITOR_TEST_CLASS') ?: dirname(__DIR__) . '/includes/class-visitor-delete.php';
require dirname(__DIR__) . '/includes/class-admin.php';
define('AVPVH_PLUGIN_DIR', dirname(__DIR__) . '/');
define('MINUTE_IN_SECONDS', 60);
$definitions = [
    'users' => 'ID INT PRIMARY KEY, user_login VARCHAR(100), user_email VARCHAR(255)',
    'usermeta' => 'umeta_id INT PRIMARY KEY, user_id INT, meta_key VARCHAR(100), meta_value TEXT, KEY(user_id)',
    'posts' => 'ID INT PRIMARY KEY, post_author INT, KEY(post_author)',
    'comments' => 'comment_ID INT PRIMARY KEY, user_id INT, KEY(user_id)',
    'options' => 'option_name VARCHAR(191) PRIMARY KEY, option_value LONGTEXT',
    'avm_members' => 'id INT PRIMARY KEY, lldap_user_id VARCHAR(255), wp_user_id INT NULL, status VARCHAR(20), first_name VARCHAR(100), last_name VARCHAR(100), KEY(wp_user_id)',
    'avm_directory_users' => 'user_id VARCHAR(255) PRIMARY KEY, email VARCHAR(255), KEY(email)',
    'avm_member_identities' => 'id INT PRIMARY KEY, member_id INT, email VARCHAR(255), KEY(member_id), KEY(email)',
    'avm_addresses' => 'id INT PRIMARY KEY, member_id INT, street VARCHAR(255), KEY(member_id)',
    'avm_activity_participation' => 'id INT PRIMARY KEY, member_id INT, KEY(member_id)',
    'avm_activity_participation_days' => 'id INT PRIMARY KEY, participation_id INT, KEY(participation_id)',
    'avm_relationships' => 'id INT PRIMARY KEY, member_id INT, related_member_id INT, KEY(member_id), KEY(related_member_id)',
    'avm_fees' => 'id INT PRIMARY KEY, member_id INT, amount_paid DECIMAL(8,2), paid_date DATE NULL, status VARCHAR(20), KEY(member_id)',
    'avm_login_attempts' => 'id INT PRIMARY KEY, email VARCHAR(255), KEY(email)',
    'avb_fee_items' => 'id INT PRIMARY KEY, member_id INT, amount_due DECIMAL(8,2), KEY(member_id)',
    'avb_transaction_allocations' => 'id INT PRIMARY KEY, member_id INT, fee_item_id INT, amount DECIMAL(8,2), KEY(member_id), KEY(fee_item_id)',
    'avb_transactions' => 'id INT PRIMARY KEY, suggested_member_ids VARCHAR(100), draft_data TEXT NULL',
    'avb_orders' => 'id INT PRIMARY KEY, member_id INT NULL, fee_item_id INT NULL, email VARCHAR(255), distribution_status VARCHAR(30), KEY(member_id), KEY(fee_item_id), KEY(email)',
    'avb_order_items' => 'id INT PRIMARY KEY, order_id INT, KEY(order_id)',
    'avb_payment_requests' => 'id INT PRIMARY KEY, member_id INT, fee_item_id INT, KEY(member_id), KEY(fee_item_id)',
    'avb_disputes' => 'id INT PRIMARY KEY, member_id INT, submitted_by_member_id INT, KEY(member_id), KEY(submitted_by_member_id)',
    'avb_dispute_events' => 'id INT PRIMARY KEY, dispute_id INT, KEY(dispute_id)',
    'avb_photo_shares' => 'id INT PRIMARY KEY, member_id INT NULL, wp_user_id INT NULL, email VARCHAR(255), drive_folder_id VARCHAR(255), KEY(member_id), KEY(wp_user_id), KEY(email), KEY(drive_folder_id)',
    'agallery_photo_shares' => 'id INT PRIMARY KEY, user_id INT, drive_folder_id VARCHAR(255), KEY(user_id), KEY(drive_folder_id)',
];
function sql($s) { global $wpdb; if ($wpdb->query($s) === false) { throw new \RuntimeException($wpdb->last_error); } }
function reset_fixture() {
    global $wpdb, $definitions;
    sql('SET FOREIGN_KEY_CHECKS=0');
    foreach ($wpdb->get_col('SHOW TABLES') as $table) { sql("DROP TABLE `$table`"); }
    sql('SET FOREIGN_KEY_CHECKS=1');
    foreach ($definitions as $table => $columns) { sql("CREATE TABLE vdel_$table ($columns) ENGINE=InnoDB"); }
    sql("INSERT INTO vdel_avm_members VALUES (10,'test.visitor',101,'visitor','Demo','Bezoeker'),(20,'fictional.member',202,'active','Voorbeeld','Lid')");
    sql("INSERT INTO vdel_users VALUES(101,'test.visitor','test-visitor@example.test'),(202,'fictional.member','member@example.test')");
    sql("INSERT INTO vdel_usermeta VALUES(1,101,'session_tokens','fictional-session'),(2,202,'session_tokens','other-session')");
    sql("INSERT INTO vdel_avm_directory_users VALUES('test.visitor','test-visitor@example.test'),('fictional.member','member@example.test')");
    sql("INSERT INTO vdel_avm_member_identities VALUES(1,10,'test-visitor@example.test'),(2,20,'member@example.test')");
    AVPVH_Directory::$accounts = ['test.visitor' => ['mail' => 'test-visitor@example.test'], 'fictional.member' => ['mail' => 'member@example.test']];
    AVPVH_Directory::$groups = []; AVPVH_Directory::$calls = 0; AVPVH_Directory::$fail = false; AVPVH_Directory::$on_delete = null;
    $GLOBALS['admin'] = true; $GLOBALS['user_roles'] = []; $wpdb->fail_delete = '';
    $GLOBALS['privileged_users'] = [];
    \Avpvh\Frontend\Share_Drive::$client = (object) ['files' => new Fixture_Files()];
    \Avpvh\Options::$share_folder = new class { public function get() { return 'fixture-selection-root'; } };
}
function check($ok, $message) { if (!$ok) { throw new \RuntimeException('FAIL: ' . $message); } echo 'PASS: ' . $message . "\n"; }
function plan() { $p = AVPVH_Visitor_Delete::preview(10); check(is_array($p), 'preview succeeds'); return $p; }
function blocked($setup, $message) {
    reset_fixture(); $setup(); $p = plan();
    check(count($p['blockers']) > 0, $message . ' preview');
    check(is_wp_error(AVPVH_Visitor_Delete::execute(10, $p['fingerprint'], '10', true)), $message . ' execution');
    check(AVPVH_Directory::$calls === 0 && get_userdata(101), 'blocked removal has no external or account mutation');
}
try {
    reset_fixture(); $p = plan();
    check($p['blockers'] === [], 'ordinary test visitor can be removed');
    check(is_wp_error(AVPVH_Visitor_Delete::execute(10, $p['fingerprint'], '10', false)), 'test-account confirmation required');
    check(is_wp_error(AVPVH_Visitor_Delete::execute(10, $p['fingerprint'], '20', true)), 'typed visitor number required');
    $GLOBALS['admin'] = false;
    check(is_wp_error(AVPVH_Visitor_Delete::preview(10)), 'preview requires admin');
    check(is_wp_error(AVPVH_Visitor_Delete::execute(10, $p['fingerprint'], '10', true)), 'execution requires admin');
    $GLOBALS['admin'] = true;
    blocked(fn() => sql("UPDATE vdel_avm_members SET status='active' WHERE id=10"), 'active members protected');
    blocked(fn() => sql("UPDATE vdel_avm_members SET status='inactive' WHERE id=10"), 'former members protected');
    blocked(fn() => sql('UPDATE vdel_avm_members SET wp_user_id=101 WHERE id=20'), 'shared WP account protected');
    blocked(function() { $GLOBALS['user_roles'][101] = ['administrator']; }, 'privileged WP account protected');
    blocked(function() { $GLOBALS['privileged_users'][101] = true; }, 'individual elevated WP capabilities protected');
    blocked(function() { AVPVH_Directory::$groups['test.visitor'] = ['boek']; }, 'directory group rights protected');
    blocked(fn() => sql('INSERT INTO vdel_posts VALUES(1,101)'), 'authored content protected');
    blocked(fn() => sql("INSERT INTO vdel_avm_fees VALUES(1,10,1.00,NULL,'pending')"), 'partial payment protected');
    blocked(fn() => sql('INSERT INTO vdel_avb_transaction_allocations VALUES(1,10,999,0.00)'), 'allocation protected even at zero');
    blocked(function() { sql('INSERT INTO vdel_avb_fee_items VALUES(30,10,35.00)'); sql('INSERT INTO vdel_avb_transaction_allocations VALUES(1,20,30,35.00)'); }, 'indirect allocation protected');
    blocked(fn() => sql("INSERT INTO vdel_avb_transactions VALUES(1,'10,20',NULL)"), 'bank suggestion protected');
    blocked(fn() => sql("INSERT INTO vdel_avb_transactions VALUES(1,'','[{\"member_id\":10}]')"), 'bank draft protected');
    blocked(function() { sql('INSERT INTO vdel_avb_fee_items VALUES(30,10,35.00)'); sql("INSERT INTO vdel_avb_transactions VALUES(1,'','[{\"fee_item_id\":30}]')"); }, 'bank draft fee reference protected');
    blocked(function() { sql('INSERT INTO vdel_avb_fee_items VALUES(30,10,35.00)'); sql("INSERT INTO vdel_avb_orders VALUES(1,20,30,'member@example.test','pending')"); }, 'another member order sharing a fee protected');
    blocked(function() { sql('INSERT INTO vdel_avb_fee_items VALUES(30,10,35.00)'); sql('INSERT INTO vdel_avb_payment_requests VALUES(1,20,30)'); }, 'another member payment request sharing a fee protected');
    blocked(function() { sql('INSERT INTO vdel_avb_fee_items VALUES(30,10,35.00)'); sql('CREATE TABLE vdel_extra_fee_data(id INT, fee_item_id INT) ENGINE=InnoDB'); sql('INSERT INTO vdel_extra_fee_data VALUES(1,30)'); }, 'unknown fee dependants protected');
    blocked(fn() => sql("INSERT INTO vdel_avb_orders VALUES(1,10,NULL,'test-visitor@example.test','distributed')"), 'fulfilled order protected');
    blocked(function() { sql('CREATE TABLE vdel_extra_data(id INT, member_id INT) ENGINE=InnoDB'); sql('INSERT INTO vdel_extra_data VALUES(1,10)'); }, 'unknown dependants protected');
    blocked(fn() => sql("INSERT INTO vdel_avm_member_identities VALUES(3,20,'test-visitor@example.test')"), 'shared email protected');
    blocked(fn() => sql("INSERT INTO vdel_avb_photo_shares VALUES(1,10,101,'test-visitor@example.test','fixture-upload-root')"), 'Drive root protected');
    blocked(fn() => sql("INSERT INTO vdel_usermeta VALUES(3,101,'photo_share_folder_id','manually-linked-folder')"), 'unproven Drive folder protected');
    blocked(function() {
        sql("INSERT INTO vdel_avb_photo_shares VALUES(1,10,101,'test-visitor@example.test','upload-child')");
        sql("INSERT INTO vdel_usermeta VALUES(3,202,'photo_share_folder_id','upload-child')");
    }, 'another user sharing a Drive folder protected');
    blocked(function() {
        sql("INSERT INTO vdel_avb_photo_shares VALUES(1,10,101,'test-visitor@example.test','upload-child')");
        sql("INSERT INTO vdel_agallery_photo_shares VALUES(1,202,'upload-child')");
    }, 'another integration sharing a Drive folder protected');

    reset_fixture(); $p = plan(); sql("UPDATE vdel_avm_members SET first_name='Gewijzigd' WHERE id=10");
    check(is_wp_error(AVPVH_Visitor_Delete::execute(10, $p['fingerprint'], '10', true)) && AVPVH_Directory::$calls === 0, 'stale preview cannot delete');
    reset_fixture(); $p = plan(); sql('INSERT INTO vdel_avb_transaction_allocations VALUES(1,10,999,1.00)');
    check(is_wp_error(AVPVH_Visitor_Delete::execute(10, $p['fingerprint'], '10', true)) && AVPVH_Directory::$calls === 0, 'payment after preview blocks removal');

    reset_fixture(); $p = plan(); AVPVH_Directory::$fail = true;
    check(is_wp_error(AVPVH_Visitor_Delete::execute(10, $p['fingerprint'], '10', true)) && get_userdata(101), 'LDAP failure keeps local data');
    reset_fixture(); $p = plan(); $wpdb->fail_delete = 'vdel_avm_member_identities';
    check(is_wp_error(AVPVH_Visitor_Delete::execute(10, $p['fingerprint'], '10', true)) && get_userdata(101), 'SQL failure rolls back local records after LDAP removal');
    $wpdb->fail_delete = ''; $p = plan();
    $result = AVPVH_Visitor_Delete::execute(10, $p['fingerprint'], '10', true);
    check($result === true, 'partial LDAP removal can be resumed');
    reset_fixture(); $p = plan(); $wpdb->fail_delete = 'vdel_avm_members';
    check(is_wp_error(AVPVH_Visitor_Delete::execute(10, $p['fingerprint'], '10', true)) && get_userdata(101), 'SQL rollback restores even a deleted WP account');
    $wpdb->fail_delete = '';

    reset_fixture(); $p = plan();
    $writer = new Fixture_WPDB('root', getenv('AVPVH_VISITOR_TEST_PASSWORD'), 'visitor_fixtures', getenv('AVPVH_VISITOR_TEST_HOST'));
    $writer->suppress_errors(true);
    $writer->query('SET SESSION innodb_lock_wait_timeout=1');
    AVPVH_Directory::$on_delete = function() use ($writer) {
        check($writer->query('INSERT INTO vdel_avb_transaction_allocations VALUES(1,10,999,1.00)') === false, 'concurrent payment insertion blocked by deletion locks');
    };
    check(AVPVH_Visitor_Delete::execute(10, $p['fingerprint'], '10', true) === true, 'locked visitor cleanup succeeds');

    reset_fixture();
    sql("INSERT INTO vdel_avb_photo_shares VALUES(1,10,101,'test-visitor@example.test','upload-child')");
    sql("INSERT INTO vdel_agallery_photo_shares VALUES(2,101,'selection-child')");
    $files = \Avpvh\Frontend\Share_Drive::$client->files;
    $files->folders = ['upload-child' => 'fixture-upload-root', 'selection-child' => 'fixture-selection-root'];
    $files->fail_id = 'upload-child'; $p = plan();
    check(is_wp_error(AVPVH_Visitor_Delete::execute(10, $p['fingerprint'], '10', true)) && get_userdata(101) && AVPVH_Directory::$calls === 0, 'Drive failure keeps local data and LDAP');
    $files->fail_id = ''; $p = plan();
    check(AVPVH_Visitor_Delete::execute(10, $p['fingerprint'], '10', true) === true, 'Drive cleanup resumes with already missing folders');
    check(count($files->removed) === 2, 'only personal child folders removed');

    reset_fixture();
    sql("INSERT INTO vdel_avm_addresses VALUES(1,10,'Fictieve straat'),(2,20,'Andere straat')");
    sql('INSERT INTO vdel_avm_activity_participation VALUES(3,10),(4,20)');
    sql('INSERT INTO vdel_avm_activity_participation_days VALUES(5,3),(6,4)');
    sql('INSERT INTO vdel_avm_relationships VALUES(7,20,10)');
    sql('INSERT INTO vdel_avb_fee_items VALUES(30,10,35.00),(40,20,35.00)');
    sql("INSERT INTO vdel_avb_orders VALUES(8,10,30,'test-visitor@example.test','pending'),(9,20,40,'member@example.test','pending'),(10,NULL,NULL,'test-visitor@example.test','pending')");
    sql('INSERT INTO vdel_avb_order_items VALUES(11,8),(12,9),(13,10)');
    sql('INSERT INTO vdel_avb_payment_requests VALUES(14,10,30),(15,20,40)');
    sql('ALTER TABLE vdel_avb_fee_items ADD FOREIGN KEY (member_id) REFERENCES vdel_avm_members(id)');
    sql('ALTER TABLE vdel_avb_orders ADD FOREIGN KEY (fee_item_id) REFERENCES vdel_avb_fee_items(id)');
    sql('ALTER TABLE vdel_avb_order_items ADD FOREIGN KEY (order_id) REFERENCES vdel_avb_orders(id)');
    sql('ALTER TABLE vdel_avb_payment_requests ADD FOREIGN KEY (fee_item_id) REFERENCES vdel_avb_fee_items(id)');
    sql('INSERT INTO vdel_avb_disputes VALUES(16,10,10),(17,20,20)');
    sql('INSERT INTO vdel_avb_dispute_events VALUES(18,16),(19,17)');
    sql("INSERT INTO vdel_avm_login_attempts VALUES(20,'test-visitor@example.test'),(21,'member@example.test')");
    $wpdb->insert($wpdb->options, ['option_name' => '_transient_avpvh_email_identity_fixture', 'option_value' => '{"member_id":10,"email":"test-visitor@example.test"}']);
    $wpdb->insert($wpdb->options, ['option_name' => '_transient_avpvh_oauth_state_other', 'option_value' => '{"member_id":20}']);
    $p = plan(); check($p['blockers'] === [], 'full fixture has no blockers');
    check(AVPVH_Visitor_Delete::execute(10, $p['fingerprint'], '10', true) === true, 'full visitor cleanup succeeds');
    foreach (['avm_members', 'users', 'usermeta', 'avm_directory_users', 'avm_member_identities', 'avm_addresses', 'avm_activity_participation', 'avm_activity_participation_days', 'avb_fee_items', 'avb_orders', 'avb_order_items', 'avb_payment_requests', 'avb_disputes', 'avb_dispute_events', 'avm_login_attempts', 'options'] as $table) {
        check((int) $wpdb->get_var("SELECT COUNT(*) FROM vdel_$table") === 1, "$table preserves only the unrelated member's data");
    }
    check((int) $wpdb->get_var('SELECT COUNT(*) FROM vdel_avm_relationships') === 0, 'inbound relationship removed');
    check(!isset(AVPVH_Directory::$accounts['test.visitor']) && isset(AVPVH_Directory::$accounts['fictional.member']), 'only the visitor directory account removed');

    reset_fixture(); $_GET['id'] = '10';
    $wpdb->update('vdel_avm_members', ['first_name' => '<script>fictional</script>'], ['id' => 10]);
    ob_start(); require dirname(__DIR__) . '/admin/delete-visitor.php'; $html = ob_get_clean();
    check(str_contains($html, '&lt;script&gt;fictional&lt;/script&gt;') && !str_contains($html, '<script>fictional'), 'preview escapes profile data');
    check(str_contains($html, 'pattern="10"') && str_contains($html, 'name="is_test"') && str_contains($html, 'name="fingerprint"'), 'preview has confirmation and snapshot fields');
    file_put_contents('/tmp/visitor-delete-confirmation.html', $html);
    sql('INSERT INTO vdel_avb_transaction_allocations VALUES(1,10,999,1.00)');
    ob_start(); require dirname(__DIR__) . '/admin/delete-visitor.php'; $html = ob_get_clean();
    check(str_contains($html, 'Verwijderen is geblokkeerd.') && !str_contains($html, 'name="confirmation"'), 'blocked preview does not offer a deletion form');

    reset_fixture(); $controller = new AVPVH_Admin();
    $_POST = ['member_id' => '10', 'is_test' => '1', 'confirmation' => '10', 'fingerprint' => plan()['fingerprint']];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    try { $controller->handle_delete_visitor(); } catch (Fixture_Controller_Result $response) { check($response->getCode() === 405, 'controller rejects GET'); }
    $_SERVER['REQUEST_METHOD'] = 'POST';
    try { $controller->handle_delete_visitor(); } catch (Fixture_Controller_Result $response) { check($response->getCode() === 403 && AVPVH_Directory::$calls === 0, 'controller requires nonce'); }
    $_POST['_wpnonce'] = hash('sha256', 'avpvh_delete_visitor_20-fixture');
    try { $controller->handle_delete_visitor(); } catch (Fixture_Controller_Result $response) { check($response->getCode() === 403 && AVPVH_Directory::$calls === 0, 'nonce is bound to target visitor'); }
    $GLOBALS['admin'] = false;
    try { $controller->handle_delete_visitor(); } catch (Fixture_Controller_Result $response) { check($response->getCode() === 403, 'controller requires admin'); }
    $GLOBALS['admin'] = true; $_POST['_wpnonce'] = hash('sha256', 'avpvh_delete_visitor_10-fixture');
    try { $controller->handle_delete_visitor(); } catch (Fixture_Controller_Result $response) { check($response->getCode() === 302 && str_contains($response->getMessage(), 'visitor_deleted=1') && !get_userdata(101), 'controller removes confirmed visitor and redirects to success'); }
    echo "All visitor deletion integration tests passed.\n";
} finally {
    sql('SET FOREIGN_KEY_CHECKS=0');
    foreach ($wpdb->get_col('SHOW TABLES') as $table) { sql("DROP TABLE `$table`"); }
}
}
