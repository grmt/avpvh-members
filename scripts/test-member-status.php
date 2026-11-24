<?php
declare(strict_types=1);

/**
 * Checks AVPVH_Member_Status: the Status choice on Ledendetail, its handler,
 * and that the groups leden/ex-leden follow the status instead of being
 * ticked by hand. Members are made up.
 *
 * Run:
 *   php scripts/test-member-status.php
 */

define('ABSPATH', __DIR__ . '/');

// --- WordPress stubs -------------------------------------------------------
final class Test_Redirect extends Exception {}
final class Test_Die extends Exception {}
$GLOBALS['can_manage'] = true;
$GLOBALS['is_admin'] = false;
$GLOBALS['log'] = [];
function __($s, $d = '') { return $s; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return esc_html($s); }
function esc_url($s) { return esc_html($s); }
function esc_html__($s, $d = '') { return esc_html($s); }
function esc_attr__($s, $d = '') { return esc_html($s); }
function esc_html_e($s, $d = '') { echo esc_html($s); }
function absint($v) { return abs((int) $v); }
function wp_unslash($v) { return $v; }
function sanitize_key($k) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $k)); }
function sanitize_text_field($s) { return trim((string) $s); }
function check_admin_referer($action) { $GLOBALS['log'][] = "nonce $action"; return 1; }
function current_user_can($cap) { return $GLOBALS['is_admin']; }
function wp_die($message, $title = '', $args = []) { throw new Test_Die(($args['response'] ?? 500) . ' ' . $message); }
function add_query_arg(array $args, string $url) { return $url . '?' . http_build_query($args); }
function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }
function wp_safe_redirect($url) {
    if (!empty($GLOBALS['really_exit'])) {
        $GLOBALS['redirected_to'] = $url;
        return true;
    }
    throw new Test_Redirect($url);
}
function wp_nonce_field($action) { echo '<input type="hidden" name="_wpnonce" value="nonce-' . $action . '">'; }
function selected($a, $b) { echo ((string) $a === (string) $b) ? ' selected' : ''; }
function checked($v) { echo $v ? ' checked' : ''; }
function disabled($v) { echo $v ? ' disabled' : ''; }
function is_wp_error($v) { return $v instanceof WP_Error; }
function add_action($hook, $cb) { $GLOBALS['log'][] = "hook $hook"; }
final class WP_Error {}

// --- Plugin stubs -----------------------------------------------------------
final class AVPVH_Roles {
    const STATUS_GROUPS = ['active' => 'leden', 'inactive' => 'ex-leden', 'visitor' => null];
    public static array $elevated = [];
    public static bool $sync_fails = false;
    public static bool $strips = false;
    public static function can_manage_members() { return $GLOBALS['can_manage']; }
    public static function get_status_labels() { return ['active' => 'Lid', 'inactive' => 'Ex-lid', 'visitor' => 'Bezoeker']; }
    public static function get_status_label($s) { return self::get_status_labels()[$s] ?? $s; }
    public static function member_has_elevated_access($id) { return in_array($id, self::$elevated, true); }
    public static function strip_bestuur_roles($id) { $GLOBALS['log'][] = "strip $id"; return self::$strips; }
    public static function sync_status_group($id) { $GLOBALS['log'][] = "sync $id"; return self::$sync_fails ? new WP_Error() : true; }
}
final class AVPVH_DB {
    public static array $members = [];
    public static array $inactivating = [];
    public static function get_member($id) { return self::$members[$id] ?? null; }
    public static function member_has_inactivating_flag($id) { return in_array($id, self::$inactivating, true); }
    public static function update_member_with_audit($id, $data, $format) {
        $GLOBALS['log'][] = "update $id " . json_encode($data);
        self::$members[$id]->status = $data['status'];
    }
}
final class AVPVH_Directory {
    public static function only_pvh_groups(array $groups) { return array_values(array_filter($groups, fn($g) => !str_starts_with($g, 'lldap_') && !str_starts_with($g, 'rsp-'))); }
}

require_once dirname(__DIR__) . '/includes/class-member-status.php';

function check(string $label, bool $ok): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) { exit(1); }
}
function reset_state(): void {
    $GLOBALS['can_manage'] = true;
    $GLOBALS['is_admin'] = false;
    $GLOBALS['log'] = [];
    AVPVH_Roles::$elevated = [];
    AVPVH_Roles::$sync_fails = false;
    AVPVH_Roles::$strips = false;
    AVPVH_DB::$inactivating = [];
    AVPVH_DB::$members = [
        1 => (object) ['id' => '1', 'status' => 'active'],
        2 => (object) ['id' => '2', 'status' => 'inactive'],
    ];
}
/** Posts the status form; returns "redirect <query>" or "die <code> <message>". */
function post_status(int $member_id, string $status): string {
    $_POST = ['member_id' => (string) $member_id, 'status' => $status];
    try {
        AVPVH_Member_Status::handle_set_status();
    } catch (Test_Redirect $r) {
        return 'redirect ' . (string) parse_url($r->getMessage(), PHP_URL_QUERY);
    } catch (Test_Die $d) {
        return 'die ' . $d->getMessage();
    }
    return 'no response';
}
function render(callable $fn): string {
    ob_start();
    $fn();
    return (string) ob_get_clean();
}

// --- Registration and groups ------------------------------------------------
reset_state();
AVPVH_Member_Status::init();
check('handler registered on admin-post', $GLOBALS['log'] === ['hook admin_post_avpvh_set_status']);
check('status groups: leden and ex-leden', AVPVH_Member_Status::status_groups() === ['leden', 'ex-leden']);

$all = ['leden', 'ex-leden', 'boek', 'bloggers', 'lldap_admin', 'rsp-leden'];
[$add, $remove] = AVPVH_Member_Status::group_changes($all, ['leden', 'boek', 'rsp-leden'], ['bloggers', 'ex-leden', 'lldap_admin', ' BOEK ']);
check('groups form: hand-chosen groups change', $add === ['bloggers'] && $remove === []);
[$add, $remove] = AVPVH_Member_Status::group_changes($all, ['leden', 'boek'], []);
check('groups form: unticking everything keeps leden', $add === [] && $remove === ['boek']);
[$add, $remove] = AVPVH_Member_Status::group_changes($all, ['ex-leden'], ['leden']);
check('groups form: a posted leden is ignored, ex-leden kept', $add === [] && $remove === []);

// --- Handler ----------------------------------------------------------------
reset_state();
$r = post_status(1, 'inactive');
check('active -> ex-lid: saved, audited, groups synced', $r === 'redirect page=avpvh-member-detail&id=1&status_saved=1'
    && in_array('update 1 {"status":"inactive"}', $GLOBALS['log'], true) && in_array('sync 1', $GLOBALS['log'], true)
    && $GLOBALS['log'][0] === 'nonce avpvh_set_status');
check('ex-lid: board roles stripped', in_array('strip 1', $GLOBALS['log'], true));

reset_state();
AVPVH_Roles::$strips = true;
check('stripped roles: noted for the notice', post_status(1, 'visitor') === 'redirect page=avpvh-member-detail&id=1&status_saved=1&bestuur_stripped=1');

reset_state();
$r = post_status(2, 'active');
check('ex-lid -> lid: saved, nothing stripped', $r === 'redirect page=avpvh-member-detail&id=2&status_saved=1' && !in_array('strip 2', $GLOBALS['log'], true));

reset_state();
$r = post_status(1, 'active');
check('unchanged: no write, no sync', $r === 'redirect page=avpvh-member-detail&id=1&status_saved=1' && $GLOBALS['log'] === ['nonce avpvh_set_status']);

reset_state();
AVPVH_DB::$inactivating = [2];
$r = post_status(2, 'active');
check('Overleden/Geroyeerd: not made active again', $r === 'redirect page=avpvh-member-detail&id=2&status_error=flag' && !in_array('update 2 {"status":"active"}', $GLOBALS['log'], true));

reset_state();
AVPVH_Roles::$sync_fails = true;
check('directory down: status saved, sync error shown', post_status(1, 'inactive') === 'redirect page=avpvh-member-detail&id=1&status_saved=1&status_error=sync');

reset_state();
$GLOBALS['can_manage'] = false;
check('no member admin: refused', str_starts_with(post_status(1, 'inactive'), 'die 403 Geen toegang.'));

reset_state();
check('unknown status: refused', str_starts_with(post_status(1, 'deleted'), 'die 400'));
check('unknown member: refused', str_starts_with(post_status(9, 'inactive'), 'die 400'));

reset_state();
AVPVH_Roles::$elevated = [1];
check('board member, non-admin: refused', str_starts_with(post_status(1, 'inactive'), 'die 403 Alleen een beheerder'));
reset_state();
AVPVH_Roles::$elevated = [1];
$GLOBALS['is_admin'] = true;
check('board member, WordPress admin: allowed', str_starts_with(post_status(1, 'inactive'), 'redirect '));

// --- Rendering ----------------------------------------------------------------
reset_state();
$h = render(fn() => AVPVH_Member_Status::render_status_row(AVPVH_DB::$members[2]));
check('status row: choice with the current status selected', str_contains($h, 'name="action" value="avpvh_set_status"') && str_contains($h, 'value="inactive"  selected>Ex-lid')
    && str_contains($h, '>Bezoeker</option>') && str_contains($h, 'nonce-avpvh_set_status'));
$GLOBALS['can_manage'] = false;
$h = render(fn() => AVPVH_Member_Status::render_status_row(AVPVH_DB::$members[2]));
check('status row: only the label without member admin rights', str_contains($h, 'Ex-lid') && !str_contains($h, '<select'));

$h = render(fn() => AVPVH_Member_Status::render_group_checkbox('leden', ['leden']));
check('groups: leden greyed out, still shown as ticked', str_contains($h, ' checked disabled') && str_contains($h, 'title="Volgt de status"'));
$h = render(fn() => AVPVH_Member_Status::render_group_checkbox('boek', ['leden']));
check('groups: other groups can be changed', !str_contains($h, 'disabled') && !str_contains($h, 'checked') && !str_contains($h, 'title='));

$notice = static function (array $get): string {
    $_GET = $get;
    return render(fn() => AVPVH_Member_Status::render_notice());
};
check('notice: none without a status change', $notice([]) === '');
check('notice: saved', str_contains($notice(['status_saved' => '1']), 'notice-success') && str_contains($notice(['status_saved' => '1']), 'Status opgeslagen.'));
check('notice: roles stripped', str_contains($notice(['status_saved' => '1', 'bestuur_stripped' => '1']), 'notice-warning'));
check('notice: kenmerk blocks', str_contains($notice(['status_error' => 'flag']), 'notice-error') && str_contains($notice(['status_error' => 'flag']), 'kenmerk'));
check('notice: sync failed', str_contains($notice(['status_saved' => '1', 'status_error' => 'sync']), 'notice-error'));

echo "Member status tests: OK\n";

// Last: a real redirect ends the request (exit), as in WordPress.
reset_state();
$GLOBALS['really_exit'] = true;
$_POST = ['member_id' => '1', 'status' => 'inactive'];
register_shutdown_function(static function (): void {
    $ok = ($GLOBALS['redirected_to'] ?? '') === 'https://example.test/wp-admin/admin.php?page=avpvh-member-detail&id=1&status_saved=1';
    echo ($ok ? '[OK] ' : '[FAIL] ') . 'redirect ends the request' . PHP_EOL;
});
AVPVH_Member_Status::handle_set_status();
echo "[FAIL] still running after the redirect\n";
