<?php
declare(strict_types=1);

/**
 * Renders Ledendetail (admin/member-detail.php, contact tab) through the real
 * AVPVH_Admin and saves its groups form: the Status choice is there, leden
 * and ex-leden are greyed out, and saving the groups leaves those two alone.
 * The member is made up; everything outside the page is a stand-in.
 *
 * Run:
 *   php scripts/test-member-detail-groups.php
 */

define('ABSPATH', __DIR__ . '/');
define('AVPVH_PLUGIN_DIR', dirname(__DIR__) . '/');

// --- WordPress stubs -------------------------------------------------------
final class Test_Redirect extends Exception {}
$GLOBALS['log'] = [];
function __($s, $d = '') { return $s; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return esc_html($s); }
function esc_url($s) { return esc_html($s); }
function esc_js($s) { return addslashes((string) $s); }
function esc_html__($s, $d = '') { return esc_html($s); }
function esc_attr__($s, $d = '') { return esc_html($s); }
function esc_html_e($s, $d = '') { echo esc_html($s); }
function esc_attr_e($s, $d = '') { echo esc_html($s); }
function absint($v) { return abs((int) $v); }
function wp_unslash($v) { return $v; }
function sanitize_key($k) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $k)); }
function sanitize_text_field($s) { return trim((string) $s); }
function add_query_arg($args, $url = '') { return $url . '?' . http_build_query((array) $args); }
function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }
function home_url($p = '') { return 'https://example.test' . $p; }
function wp_nonce_url($url, $action) { return $url . '&_wpnonce=' . $action; }
function wp_nonce_field($action) { echo '<input type="hidden" name="_wpnonce" value="nonce-' . $action . '">'; }
function check_admin_referer($action) { $GLOBALS['log'][] = "nonce $action"; return 1; }
function current_user_can($cap) { return true; }
function checked($v, $current = true) { echo ((bool) $v === (bool) $current) ? ' checked' : ''; }
function selected($a, $b) { echo ((string) $a === (string) $b) ? ' selected' : ''; }
function disabled($v) { echo $v ? ' disabled' : ''; }
function submit_button($text) { echo '<button type="submit">' . esc_html($text) . '</button>'; }
function current_time($f) { return '2026-11-24'; }
function wp_date($f, $t = null) { return '2026'; }
function wp_list_pluck($list, $field) { return array_map(fn($o) => is_object($o) ? $o->$field : $o[$field], (array) $list); }
function is_wp_error($v) { return $v instanceof WP_Error; }
function wp_die($m) { throw new RuntimeException('wp_die: ' . $m); }
function wp_safe_redirect($url) { throw new Test_Redirect($url); }
function add_action(...$a) { /* Not needed in this test. */ }
function add_filter(...$a) { /* Not needed in this test. */ }
final class WP_Error {}

// --- Plugin stubs: anything not listed answers with an empty list. -----------
function avpvh_format_name($m) { return trim($m->first_name . ' ' . $m->last_name); }
function avpvh_initials_mismatch($m) { return ''; }
abstract class Test_Empty_Stub {
    public static function __callStatic($name, $args) { return []; }
}
final class AVPVH_DB extends Test_Empty_Stub {
    public static object $member;
    public static function get_member($id) { return (int) $id === 7 ? self::$member : null; }
    public static function get_login_stats_for_email($email) { return null; }
    public static function suggest_membership_years($id) { return null; }
}
final class AVPVH_Roles extends Test_Empty_Stub {
    const STATUS_GROUPS = ['active' => 'leden', 'inactive' => 'ex-leden', 'visitor' => null];
    public static function can_manage_members() { return true; }
    public static function can_merge_members() { return true; }
    public static function get_status_labels() { return ['active' => 'Lid', 'inactive' => 'Ex-lid', 'visitor' => 'Bezoeker']; }
    public static function get_status_label($s) { return self::get_status_labels()[$s] ?? $s; }
}
final class AVPVH_Directory extends Test_Empty_Stub {
    public static array $groups = ['leden', 'boek', 'rsp-leden'];
    public static function list_groups() { return ['leden', 'ex-leden', 'boek', 'bloggers', 'rsp-leden']; }
    public static function get_user_groups($uid) { return self::$groups; }
    public static function only_pvh_groups(array $g) { return array_values(array_filter($g, fn($x) => !str_starts_with($x, 'rsp-'))); }
    public static function add_to_group($uid, $g) { $GLOBALS['log'][] = "add $g"; return true; }
    public static function remove_from_group($uid, $g) { $GLOBALS['log'][] = "remove $g"; return true; }
    public static function forget_groups($uid) { $GLOBALS['log'][] = 'forget'; }
}
final class AVPVH_Member_Merge extends Test_Empty_Stub {
    public static function field_display($m, $f) { return ''; }
}
final class AVPVH_Address_Cleanup extends Test_Empty_Stub {}
final class AVPVH_Address_Normalizer extends Test_Empty_Stub {}
final class AVPVH_Fees_Admin extends Test_Empty_Stub {}
final class AVPVH_Member_Profile_Sections extends Test_Empty_Stub {}

require_once AVPVH_PLUGIN_DIR . 'includes/class-member-status.php';
require_once AVPVH_PLUGIN_DIR . 'includes/class-admin.php';

AVPVH_DB::$member = (object) [
    'id' => '7', 'first_name' => 'Alfa', 'suffix' => '', 'last_name' => 'Voorbeeld', 'status' => 'active',
    'lldap_user_id' => 'alfa.voorbeeld', 'email' => 'alfa@example.test', 'passport_name' => '', 'initials' => '',
    'phone' => '', 'mobile' => '', 'birth_date' => null, 'birth_year' => null, 'death_date' => null, 'is_student' => 0,
    'joined_year' => null, 'left_year' => null, 'archive_access' => 0,
];

function check(string $label, bool $ok): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) { exit(1); }
}

// --- Ledendetail ------------------------------------------------------------
$_GET = ['page' => 'avpvh-member-detail', 'id' => '7', 'status_saved' => '1'];
$admin = new AVPVH_Admin();
ob_start();
$admin->render_member_detail();
$html = (string) ob_get_clean();
preg_match_all('~<label[^>]*>\s*<input type="checkbox" name="groups\[\]" value="([a-z-]+)"([^>]*)>~', $html, $boxes, PREG_SET_ORDER);
$box = [];
foreach ($boxes as [, $group, $attrs]) {
    $box[$group] = trim($attrs);
}
check('status row: the choice, current status selected', str_contains($html, 'name="action" value="avpvh_set_status"') && str_contains($html, 'value="active"  selected>Lid'));
check('status notice shown after saving', str_contains($html, 'Status opgeslagen.'));
check('groups: hint that leden and ex-leden follow the status', str_contains($html, 'De groepen leden en ex-leden volgen de status hierboven.'));
check('groups: only PvH groups, in order', array_keys($box) === ['leden', 'ex-leden', 'boek', 'bloggers']);
check('groups: leden ticked and greyed out, ex-leden greyed out', $box['leden'] === 'checked disabled' && $box['ex-leden'] === 'disabled');
check('groups: boek and bloggers can be changed', $box['boek'] === 'checked' && $box['bloggers'] === '');

// --- Saving the groups form ----------------------------------------------------
$_POST = ['member_id' => '7', 'groups' => ['ex-leden', 'bloggers']];
$GLOBALS['log'] = [];
try {
    $admin->handle_save_groups();
    $redirect = '';
} catch (Test_Redirect $r) {
    $redirect = $r->getMessage();
}
check('save: bloggers added, boek removed, leden and ex-leden untouched',
    $GLOBALS['log'] === ['nonce avpvh_save_groups', 'add bloggers', 'remove boek', 'forget']);
check('save: back to Ledendetail with a notice', str_contains($redirect, 'page=avpvh-member-detail') && str_contains($redirect, 'id=7') && str_contains($redirect, 'groups_saved=1'));

echo "Member detail groups tests: OK\n";
