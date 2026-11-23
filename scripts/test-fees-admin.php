<?php
declare(strict_types=1);

/**
 * The own contribution administration: AVPVH_DB's avm_fees writes,
 * AVPVH_Fees_Admin's handlers (access, input, redirects) and its screens,
 * alone and with a bookkeeping plugin in charge. In-memory $wpdb; members
 * and amounts are made up.
 *
 * Run:
 *   php scripts/test-fees-admin.php
 */

define('ABSPATH', __DIR__ . '/');
ini_set('error_log', '/dev/null');

// --- WordPress stubs -------------------------------------------------------
final class Redirected extends Exception {}
final class Died extends Exception {}
$GLOBALS['filters'] = [];
function __($s, $d = '') { return $s; }
function esc_html__($s, $d = '') { return $s; }
function esc_html_e($s, $d = '') { echo $s; }
function esc_attr_e($s, $d = '') { echo $s; }
function esc_html($s) { return htmlspecialchars((string) $s); }
function esc_attr($s) { return htmlspecialchars((string) $s); }
function esc_url($s) { return (string) $s; }
function esc_js($s) { return addslashes((string) $s); }
function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }
function add_query_arg(array $args, string $url) { return $url . '?' . http_build_query($args); }
function wp_nonce_field($a) { echo '<input type="hidden" name="_wpnonce" value="' . $a . '">'; }
function submit_button($text, $type = '', $name = '', $wrap = true) { echo '<button>' . $text . '</button>'; }
function selected($a, $b) { echo $a === $b ? ' selected' : ''; }
function wp_date($f, $t) { return date($f, $t); }
function current_time($f) { return $f === 'Y' ? '2026' : '2026-06-01'; }
function absint($v) { return abs((int) $v); }
function wp_unslash($v) { return $v; }
function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $v)); }
function sanitize_text_field($v) { return trim((string) $v); }
function check_admin_referer($a) { return true; }
function wp_safe_redirect($url) { if ($GLOBALS['redirect_throws'] ?? true) { throw new Redirected($url); } $GLOBALS['redirected'] = $url; }
function wp_die($msg = '', $code = 0) { throw new Died((string) $code); }
function wp_list_pluck(array $list, string $field) { return array_map(static fn($o) => $o->$field, $list); }
function do_action(string $hook, ...$args) { $GLOBALS['actions'][] = [$hook, ...$args]; }
function add_action(...$args) {}
function apply_filters(string $hook, $value, ...$args) {
    return isset($GLOBALS['filters'][$hook]) ? ($GLOBALS['filters'][$hook])($value, ...$args) : $value;
}
final class AVPVH_Roles { public static bool $allow = true; public static function can_manage_members(): bool { return self::$allow; } }
final class AVPVH_Directory_Cache { public static function table(): string { return 'wp_avm_directory_users'; } }

/** avm_fees in memory, plus just enough of avm_members and flags. */
final class Fake_WPDB {
    public string $prefix = 'wp_';
    public array $fees = [];
    public array $members = [1 => 'active', 2 => 'active', 3 => 'active', 4 => 'inactive'];
    public array $exempt = [3];
    private int $next = 100;
    public function prepare($sql, ...$args) {
        $args = is_array($args[0] ?? null) ? $args[0] : $args;
        return vsprintf(str_replace(['%s', '%d', '%f'], ["'%s'", '%d', '%F'], $sql), $args);
    }
    public function esc_like($s) { return $s; }
    public function get_results($sql) {
        if (preg_match('/avm_fees WHERE year = (\d+) AND member_id IN \(([\d,]+)\)/', $sql, $m)) {
            $ids = array_map('intval', explode(',', $m[2]));
            return array_values(array_filter($this->fees, fn($f) => $f->year === (int) $m[1] && in_array($f->member_id, $ids, true)));
        }
        if (preg_match('/avm_fees WHERE member_id = (\d+) ORDER BY year DESC/', $sql, $m)) {
            $rows = array_values(array_filter($this->fees, fn($f) => $f->member_id === (int) $m[1]));
            usort($rows, fn($a, $b) => $b->year <=> $a->year);
            return $rows;
        }
        if (str_contains($sql, 'avm_members m')) {
            $rows = [];
            $only_active = (bool) preg_match("/m\.status IN \('active'\)/", $sql);
            foreach ($this->members as $id => $status) {
                if (!$only_active || $status === 'active') {
                    $rows[] = (object) ['id' => $id, 'status' => $status];
                }
            }
            return $rows;
        }
        return [];
    }
    public function get_row($sql) {
        if (preg_match('/avm_fees WHERE member_id = (\d+) AND year = (\d+)/', $sql, $m)) {
            foreach ($this->fees as $f) {
                if ($f->member_id === (int) $m[1] && $f->year === (int) $m[2]) { return $f; }
            }
            return null;
        }
        if (preg_match('/WHERE m\.id = (\d+)/', $sql, $m) && isset($this->members[(int) $m[1]])) {
            return (object) ['id' => (int) $m[1], 'status' => $this->members[(int) $m[1]]];
        }
        return null;
    }
    public function get_var($sql) {
        if (preg_match('/affects_fees = 1.*a\.member_id = (\d+)|a\.member_id = (\d+).*affects_fees = 1/s', $sql, $m)) {
            return in_array((int) ($m[1] ?: $m[2]), $this->exempt, true) ? '1' : null;
        }
        if (preg_match('/SELECT member_id FROM wp_avm_fees WHERE id = (\d+)/', $sql, $m)) {
            return isset($this->fees[(int) $m[1]]) ? (string) $this->fees[(int) $m[1]]->member_id : null;
        }
        if (preg_match('/SELECT id FROM wp_avm_fees WHERE member_id = (\d+) AND year = (\d+)/', $sql, $m)) {
            foreach ($this->fees as $f) {
                if ($f->member_id === (int) $m[1] && $f->year === (int) $m[2]) { return (string) $f->id; }
            }
        }
        return null;
    }
    public function get_col($sql) { return str_contains($sql, 'avm_activity_participation') ? ['2019', '2021'] : []; }
    public int $insert_id = 0;
    public function insert($table, $data, $format = null) {
        if ($table === 'wp_avm_members') { $this->insert_id = 50; return 1; }
        if ($table !== 'wp_avm_fees') { return false; }
        $id = $this->next++;
        $this->fees[$id] = (object) (['id' => $id] + $data + [
            'amount_due' => null, 'amount_paid' => null, 'paid_date' => null, 'status' => 'pending',
        ]);
        $this->fees[$id]->member_id = (int) $data['member_id'];
        $this->fees[$id]->year = (int) $data['year'];
        return 1;
    }
    public function update($table, $data, $where, $f = null, $wf = null) {
        foreach ($data as $k => $v) { $this->fees[$where['id']]->$k = $v; }
        return 1;
    }
    public function delete($table, $where, $f = null) { unset($this->fees[$where['id']]); return 1; }
}
$wpdb = new Fake_WPDB();
$GLOBALS['wpdb'] = $wpdb;

require __DIR__ . '/../includes/class-db.php';
require __DIR__ . '/../includes/class-fees.php';
require __DIR__ . '/../includes/class-fees-admin.php';

function check(string $what, bool $ok): void { echo ($ok ? '[OK] ' : '[FAIL] ') . $what . PHP_EOL; }
function redirect_of(callable $fn): string {
    try { $fn(); } catch (Redirected $r) { return $r->getMessage(); } catch (Died $d) { return 'died:' . $d->getMessage(); }
    return '';
}
function html_of(callable $fn): string { ob_start(); $fn(); return (string) ob_get_clean(); }
function fee_of(int $member, int $year): ?object {
    foreach ($GLOBALS['wpdb']->fees as $f) { if ($f->member_id === $member && $f->year === $year) { return $f; } }
    return null;
}

// --- AVPVH_DB ---------------------------------------------------------------
$wpdb->insert('wp_avm_fees', ['member_id' => 1, 'year' => 2026, 'amount_due' => 20.0, 'status' => 'paid', 'amount_paid' => 20.0, 'paid_date' => '2026-01-05']);
$result = AVPVH_DB::create_fees_for_year(2026, 25.0);
check('create: one new, one exempt, one existing', $result === ['created' => 1, 'waived' => 1, 'existing' => 1]);
check('create: new record pending with amount', fee_of(2, 2026)->status === 'pending' && (float) fee_of(2, 2026)->amount_due === 25.0);
check('create: exempt member waived at 0', fee_of(3, 2026)->status === 'waived' && (float) fee_of(3, 2026)->amount_due === 0.0);
check('create: existing record untouched', fee_of(1, 2026)->status === 'paid' && (float) fee_of(1, 2026)->amount_due === 20.0);
check('create: inactive member skipped', fee_of(4, 2026) === null);
check('create twice is harmless', AVPVH_DB::create_fees_for_year(2026, 25.0) === ['created' => 0, 'waived' => 0, 'existing' => 3]);

check('save: paid fills amount and date', AVPVH_DB::save_fee(2, 2026, 'paid', 25.0, null, null) && (float) fee_of(2, 2026)->amount_paid === 25.0 && fee_of(2, 2026)->paid_date === '2026-06-01');
check('save: waived clears date', AVPVH_DB::save_fee(2, 2026, 'waived', 0.0, null, '2026-02-02') && fee_of(2, 2026)->paid_date === null);
check('save: new year inserts', AVPVH_DB::save_fee(2, 2024, 'pending', 18.0, 5.0, null) && fee_of(2, 2024)->status === 'pending');
check('save: unknown status refused', !AVPVH_DB::save_fee(2, 2026, 'unpaid', 1.0, null, null));
check('save: unknown member refused', !AVPVH_DB::save_fee(99, 2026, 'paid', 1.0, null, null));
check('save: silly year refused', !AVPVH_DB::save_fee(2, 12, 'paid', 1.0, null, null));
check('fees for year by member', count(AVPVH_DB::get_fees_for_year([1, 2], 2026)) === 2 && AVPVH_DB::get_fees_for_year([], 2026) === []);
check('single fee for year', AVPVH_DB::get_fee_for_year(1, 2026)->status === 'paid');

$years = AVPVH_DB::suggest_membership_years(1);
check('membership years from activities and settled fees', $years === ['from' => 2019, 'to' => 2026]);
$deleted = fee_of(2, 2024)->id;
check('delete returns the member', AVPVH_DB::delete_fee($deleted) === 2 && fee_of(2, 2024) === null);
check('delete unknown returns 0', AVPVH_DB::delete_fee(9999) === 0);

$GLOBALS['actions'] = [];
check('create_member announces the new member', AVPVH_DB::create_member('u50', 'Cas', '', 'Jansen', null, 'active') === 50 && $GLOBALS['actions'] === [['avpvh_mbrs_member_changed', 50]]);

// --- handlers ---------------------------------------------------------------
$admin = new AVPVH_Fees_Admin();
$_POST = ['year' => '2027', 'amount' => '30,00'];
$url = redirect_of([$admin, 'handle_generate']);
check('generate redirects with counts', str_contains($url, 'page=avpvh-members') && str_contains($url, 'fees_created=2') && str_contains($url, 'fees_waived=1'));
$_POST = ['year' => '2027', 'amount' => ''];
check('generate without amount reports input error', str_contains(redirect_of([$admin, 'handle_generate']), 'fees_error=input'));

$_POST = ['member_id' => '1', 'year' => '2027', 'status' => 'paid', 'amount_due' => '30', 'amount_paid' => '', 'paid_date' => '2027-01-10'];
$url = redirect_of([$admin, 'handle_save']);
check('save handler stores and returns to the tab', str_contains($url, 'tab=fees') && str_contains($url, 'updated=1') && fee_of(1, 2027)->paid_date === '2027-01-10');
$_POST = ['member_id' => '1', 'year' => '2027', 'status' => 'paid', 'amount_due' => '30', 'amount_paid' => '', 'paid_date' => '10-01-2027'];
redirect_of([$admin, 'handle_save']);
check('save handler treats a malformed date as none (paid: today)', fee_of(1, 2027)->paid_date === '2026-06-01');
$_POST = ['member_id' => '1', 'year' => '2027', 'status' => 'bogus'];
check('save handler reports failure', str_contains(redirect_of([$admin, 'handle_save']), 'fee_error=1'));

$_POST = ['fee_id' => (string) fee_of(1, 2027)->id, 'member_id' => '1'];
check('delete handler', str_contains(redirect_of([$admin, 'handle_delete']), 'updated=1') && fee_of(1, 2027) === null);
$_POST = ['fee_id' => '9999', 'member_id' => '1'];
check('delete handler with unknown id', str_contains(redirect_of([$admin, 'handle_delete']), 'fee_error=1'));

AVPVH_Roles::$allow = false;
check('no access without the members role', redirect_of([$admin, 'handle_generate']) === 'died:403');
AVPVH_Roles::$allow = true;

// --- screens, own administration ------------------------------------------------
$panel = html_of(static fn() => AVPVH_Fees_Admin::render_year_panel(2026));
check('panel offers generating a year', str_contains($panel, 'avpvh_generate_fees') && str_contains($panel, 'value="2026"'));
$_GET = ['fees_year' => '2026', 'fees_created' => '5', 'fees_waived' => '1', 'fees_existing' => '2'];
check('panel reports the counts', str_contains(html_of(static fn() => AVPVH_Fees_Admin::render_year_panel(2026)), 'Contributie 2026: 5 aangemaakt, 1 vrijgesteld, 2 hadden er al een.'));
$_GET = ['fees_error' => 'input'];
check('panel reports bad input', str_contains(html_of(static fn() => AVPVH_Fees_Admin::render_year_panel(2026)), 'Vul een jaar en een bedrag in.'));
$_GET = [];

$tab = html_of(static fn() => AVPVH_Fees_Admin::render_member_tab(1));
check('tab lists the year with status and amount', str_contains($tab, '<td>2026</td>') && str_contains($tab, 'Betaald') && str_contains($tab, '€ 20,00'));
check('tab offers change, delete and add', str_contains($tab, 'avpvh_save_fee') && str_contains($tab, 'avpvh_delete_fee') && str_contains($tab, 'Jaar toevoegen'));
check('tab shows the date as d-m-Y', str_contains($tab, '05-01-2026'));
$wpdb->insert('wp_avm_fees', ['member_id' => 2, 'year' => 2025, 'amount_due' => 25.0, 'amount_paid' => 10.0, 'status' => 'pending']);
$tab = html_of(static fn() => AVPVH_Fees_Admin::render_member_tab(2));
check('partial shows as Deels betaald, edited as Openstaand', str_contains($tab, 'Deels betaald') && (bool) preg_match('/value="pending"\s+selected/', $tab));
$_GET = ['fee_error' => '1'];
check('tab without records says so, and shows the error', (static function () {
    $html = html_of(static fn() => AVPVH_Fees_Admin::render_member_tab(4));
    return str_contains($html, 'Geen contributie vastgelegd.') && str_contains($html, 'Kon de contributie niet opslaan.');
})());
$_GET = [];

// --- with a bookkeeping plugin in charge ---------------------------------------
$GLOBALS['filters']['avpvh_mbrs_fee_manager'] = static fn($v, int $id = 0) => ['label' => 'boekhouding', 'url' => 'https://example.test/books/' . $id];
$GLOBALS['filters']['avpvh_mbrs_member_fees'] = static fn($v, int $id) => [['year' => 2026, 'status' => 'partial', 'amount_due' => 25, 'amount_paid' => 5]];
$panel = html_of(static fn() => AVPVH_Fees_Admin::render_year_panel(2026));
check('panel points to the bookkeeping', str_contains($panel, 'bijgehouden in de boekhouding') && str_contains($panel, 'books/0') && !str_contains($panel, 'avpvh_generate_fees'));
$tab = html_of(static fn() => AVPVH_Fees_Admin::render_member_tab(1));
check('tab shows the bookkeeping status without forms', str_contains($tab, 'Deels betaald') && str_contains($tab, 'books/1') && !str_contains($tab, 'avpvh_save_fee'));
$_POST = ['member_id' => '1', 'year' => '2026', 'status' => 'paid'];
check('handlers refuse while the bookkeeping is in charge', redirect_of([$admin, 'handle_save']) === 'died:409');

// Last: a real redirect ends the request (exit), so the rest is reported at shutdown.
$GLOBALS['filters'] = [];
$GLOBALS['redirect_throws'] = false;
register_shutdown_function(static function (): void {
    check('handler exits right after the redirect', str_contains($GLOBALS['redirected'] ?? '', 'fees_error=input'));
    echo PHP_EOL . 'Done.' . PHP_EOL;
});
$_POST = ['year' => '2027', 'amount' => ''];
$admin->handle_generate();
check('handler exits right after the redirect', false);
