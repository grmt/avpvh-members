<?php
declare(strict_types=1);

/**
 * The admin templates that show the contribution: members list (column
 * and year panel), member detail (Contributie tab) and the merge preview.
 * Every class except AVPVH_Fees(_Admin) is an auto-generated stub whose
 * methods return what $GLOBALS['returns'] says, or an empty Anything.
 * Members and amounts are made up.
 *
 * Run:
 *   php scripts/test-fees-templates.php
 */

define('ABSPATH', __DIR__ . '/');
ini_set('error_log', '/dev/null');

/** Stands in for any value a template reads but this test doesn't care about. */
final class Anything implements ArrayAccess, IteratorAggregate, Countable, Stringable {
    public function __get($name) { return ''; }
    public function __isset($name) { return false; }
    public function __call($name, $args) { return new self(); }
    public function offsetExists($o): bool { return false; }
    public function offsetGet($o): mixed { return ''; }
    public function offsetSet($o, $v): void {}
    public function offsetUnset($o): void {}
    public function getIterator(): Iterator { return new EmptyIterator(); }
    public function count(): int { return 0; }
    public function __toString(): string { return ''; }
}
final class Stub {
    public static function ret(string $class, string $method): mixed {
        $key = $class . '::' . $method;
        if (array_key_exists($key, $GLOBALS['returns'])) {
            $value = $GLOBALS['returns'][$key];
            return $value instanceof Closure ? $value() : $value;
        }
        return str_starts_with($method, 'get_') && str_ends_with($method, 's') ? [] : new Anything();
    }
}
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'AVPVH_')) {
        eval("final class $class { public static function __callStatic(\$m, \$a) { return Stub::ret('$class', \$m); } }");
    }
});

$GLOBALS['returns'] = [];
$GLOBALS['filters'] = [];
function __($s, $d = '') { return $s; }
function esc_html__($s, $d = '') { return $s; }
function esc_attr__($s, $d = '') { return $s; }
function esc_html_e($s, $d = '') { echo $s; }
function esc_attr_e($s, $d = '') { echo $s; }
function esc_html($s) { return htmlspecialchars((string) $s); }
function esc_attr($s) { return htmlspecialchars((string) $s); }
function esc_url($s) { return (string) $s; }
function esc_js($s) { return addslashes((string) $s); }
function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }
function home_url($p = '/') { return 'https://example.test' . $p; }
function add_query_arg($args, $url = '', $c = null) { return is_array($args) ? $url . '?' . http_build_query($args) : $url; }
function wp_nonce_field($a) {}
function wp_nonce_url($u, $a = '') { return $u; }
function submit_button(...$a) {}
function selected($a, $b) {}
function checked($a, $b = true) {}
function wp_date($f, $t) { return date($f, (int) $t); }
function current_time($f) { return $f === 'Y' ? '2026' : '2026-06-01'; }
function absint($v) { return abs((int) $v); }
function wp_unslash($v) { return $v; }
function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $v)); }
function sanitize_text_field($v) { return trim((string) $v); }
function current_user_can($c) { return true; }
function get_current_user_id() { return 1; }
function get_transient($k) { return false; }
function delete_transient($k) {}
function is_wp_error($v) { return false; }
function wp_die($m = '') { throw new RuntimeException((string) $m); }
function wp_list_pluck($list, $field) { return array_map(static fn($o) => is_object($o) ? $o->$field : $o[$field], (array) $list); }
function apply_filters($hook, $value, ...$args) {
    return isset($GLOBALS['filters'][$hook]) ? ($GLOBALS['filters'][$hook])($value, ...$args) : $value;
}
function add_action(...$a) {}
function wp_json_encode($v) { return json_encode($v); }
function avpvh_format_name($m, $style = '') { return trim(($m->first_name ?? '') . ' ' . ($m->last_name ?? '')); }
function avpvh_initials_mismatch(...$a) { return false; }

final class Template_WPDB {
    public string $prefix = 'wp_';
    public function get_results($sql) { return []; }
    public function get_var($sql) { return null; }
    public function get_row($sql) { return null; }
    public function prepare($sql, ...$a) { return $sql; }
}
$wpdb = new Template_WPDB();
$GLOBALS['wpdb'] = $wpdb;

require __DIR__ . '/../includes/class-fees.php';
require __DIR__ . '/../includes/class-fees-admin.php';

function check(string $what, bool $ok): void { echo ($ok ? '[OK] ' : '[FAIL] ') . $what . PHP_EOL; }
function render(string $template): string {
    global $wpdb;
    ob_start();
    try {
        require __DIR__ . '/../admin/' . $template;
    } catch (Throwable $e) {
        echo 'THROWN: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    }
    return (string) ob_get_clean();
}
function member(int $id, string $first, string $status = 'active'): object {
    return (object) ['id' => $id, 'first_name' => $first, 'suffix' => '', 'last_name' => 'Jansen', 'passport_name' => '', 'email' => "m$id@example.test",
        'status' => $status, 'joined_year' => 2020, 'left_year' => null, 'birth_date' => null, 'birth_year' => null, 'archive_access' => 0];
}

$GLOBALS['returns'] = [
    'AVPVH_Roles::can_manage_members' => true,
    'AVPVH_Roles::can_merge_members'  => true,
    'AVPVH_DB::get_members'           => [member(1, 'Anna'), member(2, 'Bram')],
    'AVPVH_DB::get_member_identities' => [],
    'AVPVH_DB::get_flags_for_member'  => [],
    'AVPVH_DB::get_fees_for_year'     => [(object) ['id' => 5, 'member_id' => 1, 'year' => 2026, 'status' => 'paid', 'amount_due' => 25.0, 'amount_paid' => 25.0, 'paid_date' => '2026-02-01']],
    'AVPVH_DB::get_fees_for_member'   => [(object) ['id' => 5, 'member_id' => 1, 'year' => 2026, 'status' => 'paid', 'amount_due' => 25.0, 'amount_paid' => 25.0, 'paid_date' => '2026-02-01']],
    'AVPVH_DB::get_member'            => member(1, 'Anna'),
    'AVPVH_Member_Merge::get_member_options' => [member(1, 'Anna'), member(2, 'Bram')],
];

// --- members list -----------------------------------------------------------
$html = render('members-list.php');
check('list renders', !str_contains($html, 'THROWN') && str_contains($html, 'Contributie 2026'));
check('list: paid member shows Betaald', str_contains($html, '>Betaald</td>'));
check('list: member without record says so instead of a dash', str_contains($html, '>Niet aangemaakt</td>'));
check('list: year panel offered', str_contains($html, 'Contributie aanmaken voor een jaar'));

// --- member detail, Contributie tab ----------------------------------------
$_GET = ['id' => '1', 'tab' => 'fees'];
$html = render('member-detail.php');
check('detail renders the fee tab', !str_contains($html, 'THROWN') && str_contains($html, 'Contributieoverzicht') && str_contains($html, '€ 25,00'));
check('detail tab offers editing', str_contains($html, 'avpvh_save_fee'));

// --- merge preview ------------------------------------------------------------
$_GET = ['keep' => '1', 'remove' => '2'];
$GLOBALS['returns']['AVPVH_Member_Merge::preview'] = [
    'keep' => member(1, 'Anna'), 'remove' => member(2, 'Bram'), 'fields' => [], 'participation' => [], 'counts' => [], 'external' => [],
    'fees' => [['year' => 2026, 'status' => 'pending', 'conflict' => false, 'wins' => false]],
    'accounts' => ['keep' => ['uid' => 'u1', 'email' => '', 'groups' => [], 'exists' => true], 'remove' => ['uid' => 'u2', 'email' => '', 'groups' => [], 'exists' => true]],
    'fingerprint' => 'x', 'email' => ['default' => 'keep', 'keep' => 'm1@example.test', 'remove' => ''], 'address_plan' => [], 'addresses' => ['keep' => [], 'remove' => []],
    'identities' => [], 'flags' => [], 'relationships' => [], 'aliases' => [], 'blockers' => [], 'warnings' => [],
];
$html = render('merge-members.php');
check('merge preview labels the fee status', str_contains($html, 'Contributie 2026 (Openstaand)'));

echo PHP_EOL . 'Done.' . PHP_EOL;
