<?php
declare(strict_types=1);

/**
 * Checks for access-control, role, media-bucket and tenant-SSO behavior:
 * - elevated-account classification and role/delegation boundaries
 * - REST query/comment/oEmbed filtering for restricted pages
 * - step-up 2FA checks for admin REST and admin-post routes
 * - safe WP user linking and birth-year minor checks
 * - private media bucketing for unpublished pages
 * - tenant 2FA check for administrator sessions and logout confirmation
 *
 * All names, addresses, domains and IDs are fictional.
 *
 * Run:
 *   php scripts/test-access-hardening.php
 */

define('ABSPATH', __DIR__ . '/');
define('AVPVH_DMARC_PAGE_OPTION', 'avpvh_dmarc_page_id');
define('AVPVH_PROXY_SECRET', 'test-proxy-secret-value');

$failures = 0;
function check(string $label, bool $ok): void {
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
}

// --- Minimal WordPress stubs ------------------------------------------------

class WP_Error {
    public function __construct(public string $code = '', public string $message = '', public array $data = []) {}
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
}
class WP_Post {
    public int $ID = 0;
    public string $post_type = 'page';
    public string $post_status = 'publish';
    public string $post_password = '';
    public string $post_content = '';
}
class WP_User {
    public array $roles = [];
    public array $caps = [];
    public array $allcaps = [];
    public string $user_login = '';
    public string $user_email = '';
    public string $display_name = '';
    public function __construct(public int $ID = 0, array $roles = ['subscriber'], array $caps = []) {
        $this->roles = $roles;
        $this->caps = $caps;
        $this->allcaps = array_fill_keys($caps, true);
    }
    public function set_role(string $role): void { $this->roles = [$role]; }
}
class WP_REST_Request {
    public function __construct(private string $route, private string $method = 'GET', private array $params = []) {}
    public function get_route(): string { return $this->route; }
    public function get_method(): string { return $this->method; }
    public function get_param(string $key): mixed { return $this->params[$key] ?? null; }
}
class WP_Query {
    public array $vars = [];
    public function __construct(private bool $search = false, private bool $feed = false, private bool $main = false, private bool $page = false) {}
    public function is_search(): bool { return $this->search; }
    public function is_feed(): bool { return $this->feed; }
    public function is_main_query(): bool { return $this->main; }
    public function is_page(): bool { return $this->page; }
    public function get(string $key) { return $this->vars[$key] ?? ''; }
    public function set(string $key, $value): void { $this->vars[$key] = $value; }
}
class Test_Exit extends RuntimeException {
    public function __construct(public string $kind, public int $status = 0, public string $detail = '') {
        parent::__construct($kind);
    }
}

$GLOBALS['test_logged_in'] = false;
$GLOBALS['test_user_id'] = 0;
$GLOBALS['test_users'] = [];
$GLOBALS['test_posts'] = [];
$GLOBALS['test_options'] = [AVPVH_DMARC_PAGE_OPTION => 15];
$GLOBALS['test_groups'] = [];       // uid => array|WP_Error
$GLOBALS['test_delegations'] = [];  // member_id => string[]
$GLOBALS['test_members'] = [];      // member_id => object
$GLOBALS['test_session_data'] = [];
$GLOBALS['test_admin'] = false;
$GLOBALS['test_remote_handler'] = null;
$GLOBALS['test_meta'] = [];

class WP_Session_Tokens {
    public static function get_instance(int $user_id): self { return new self(); }
    public function get(string $token): ?array { return $GLOBALS['test_session_data']; }
    public function update(string $token, array $session): void { $GLOBALS['test_session_data'] = $session; }
}

$wpdb = new class {
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public string $comments = 'wp_comments';
    public function prepare(string $sql, mixed ...$args): array { return [$sql, $args]; }
    public function get_var(mixed $q): mixed {
        if (is_array($q)) {
            [$sql, $args] = $q;
            if (str_contains($sql, 'avm_role_delegations') && str_contains($sql, 'role = %s')) {
                [$role, $mid] = $args;
                return in_array($role, $GLOBALS['test_delegations'][(int) $mid] ?? [], true) ? 1 : 0;
            }
            if (str_contains($sql, 'avm_role_delegations')) {
                $mid = (int) $args[0];
                return !empty($GLOBALS['test_delegations'][$mid]) ? 1 : 0;
            }
            if (str_contains($sql, 'avm_members WHERE wp_user_id = %d')) {
                [$wp_uid, $exclude_id] = $args;
                foreach ($GLOBALS['test_members'] as $m) {
                    if ((int) ($m->wp_user_id ?? 0) === (int) $wp_uid && (int) $m->id !== (int) $exclude_id) {
                        return (int) $m->id;
                    }
                }
                return 0;
            }
        }
        return 0;
    }
    public function get_col(string $sql): array {
        $ids = [];
        foreach ($GLOBALS['test_posts'] as $p) {
            if (!empty($p->post_password)) {
                $ids[] = $p->ID;
            }
        }
        return $ids;
    }
    public function get_results(mixed $q): array {
        if (is_string($q) && str_contains($q, 'YEAR(post_date)')) { return [(object) ['ID' => 501, 'y' => 2015], (object) ['ID' => 502, 'y' => 2025]]; }
        return $GLOBALS['test_address_rows'] ?? [];
    }
};

function __(string $t, string $d = ''): string { return $t; }
function esc_html__(string $t, string $d = ''): string { return $t; }
function esc_html(string $t): string { return htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); }
function esc_html_e(string $t, string $d = ''): void { echo esc_html($t); }
function admin_url(string $path = ''): string { return home_url('/wp-admin/' . $path); }
function esc_url(string $u): string { return $u; }
function add_action(string $h, mixed ...$a): void { $GLOBALS['test_hooks'][$h][] = $a; }
function add_filter(string $h, mixed ...$a): void { $GLOBALS['test_hooks'][$h][] = $a; }
function apply_filters(string $h, mixed $value, mixed ...$a): mixed {
    return isset($GLOBALS['test_filters'][$h]) ? ($GLOBALS['test_filters'][$h])($value, ...$a) : $value;
}
function add_shortcode(string $t, callable $c): void {}
function sanitize_key(string $k): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($k)); }
function sanitize_text_field(string $v): string { return trim($v); }
function sanitize_email(string $v): string { return filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : ''; }
function sanitize_user(string $v, bool $strict = false): string { return preg_replace('/[^a-z0-9._@-]/', '', strtolower($v)); }
function sanitize_title(string $v): string { return strtolower(trim($v)); }
function wp_unslash(mixed $v): mixed { return $v; }
function wp_parse_url(string $u, int $c = -1): mixed { return parse_url($u, $c); }
function untrailingslashit(string $u): string { return rtrim($u, '/'); }
function home_url(string $p = '/'): string { return 'https://site.example.invalid/' . ltrim($p, '/'); }
function add_query_arg(array|string $k, mixed $v, ?string $u = null): string {
    if (is_array($k)) { return $v . '?' . http_build_query($k); }
    return $u . (str_contains($u, '?') ? '&' : '?') . $k . '=' . $v;
}
function is_wp_error(mixed $v): bool { return $v instanceof WP_Error; }
function is_admin(): bool { return $GLOBALS['test_admin']; }
function wp_doing_ajax(): bool { return $GLOBALS['test_ajax'] ?? false; }
function wp_doing_cron(): bool { return $GLOBALS['test_cron'] ?? false; }
function is_user_logged_in(): bool { return $GLOBALS['test_logged_in']; }
function get_current_user_id(): int { return $GLOBALS['test_user_id']; }
function wp_get_current_user(): WP_User { return $GLOBALS['test_users'][get_current_user_id()]; }
function absint(mixed $v): int { return abs((int) $v); }
function check_admin_referer(string $action): void { $GLOBALS['test_nonce_action'] = $action; }
function check_ajax_referer(string $action, string $key): void {}
function wp_send_json_error(mixed $data, ?int $status = null): void { throw new Test_Exit('json_error', $status ?? 200, (string) $data); }
function wp_get_session_token(): string { return $GLOBALS['test_session_token'] ?? 'tok-1'; }
function get_userdata(int $id): WP_User|false { return $GLOBALS['test_users'][$id] ?? false; }
function get_user_by(string $f, mixed $v): WP_User|false { return $f === 'id' ? ($GLOBALS['test_users'][(int) $v] ?? false) : false; }
function user_can(WP_User $u, string $cap): bool { return in_array($cap, $u->caps, true) || in_array('administrator', $u->roles, true); }
function current_user_can(string $cap): bool {
    $u = $GLOBALS['test_users'][$GLOBALS['test_user_id']] ?? null;
    return $u ? user_can($u, $cap) : false;
}
function get_option(string $name, mixed $default = false): mixed { return $GLOBALS['test_options'][$name] ?? $default; }
function get_comment(int $id): ?object { return isset($GLOBALS['test_comment_posts'][$id]) ? (object) ['comment_post_ID' => $GLOBALS['test_comment_posts'][$id]] : null; }
function url_to_postid(string $url): int { return $GLOBALS['test_url_posts'][$url] ?? 0; }
function get_post(int $id): ?WP_Post { return $GLOBALS['test_posts'][$id] ?? null; }
function get_post_meta(int $id, string $key, bool $single = false): mixed { return $GLOBALS['test_post_meta'][$id][$key] ?? ''; }
function get_posts(array $args): array {
    if (($args['name'] ?? '') === 'leden') { return [10]; }
    return [];
}
function get_pages(array $args): array {
    if (($args['child_of'] ?? 0) === 10) { return [(object) ['ID' => 11], (object) ['ID' => 15], (object) ['ID' => 1920]]; }
    return [];
}
function get_page_by_path(string $p): ?object { return null; }
function is_page(mixed $id = null): bool { return ($GLOBALS['test_page_id'] ?? null) === $id; }
function get_post_ancestors(int $id): array { return in_array($id, [11, 15, 1920, 77], true) ? [10] : []; }
function wp_list_pluck(array $list, string $field): array { return array_map(static fn($o) => $o->$field, $list); }
function current_time(string $fmt): string { return $fmt === 'Y' ? '2026' : ($fmt === 'Y-m-d' ? '2026-06-15' : '2026-06-15 12:00:00'); }
function wp_verify_nonce(string $n, string $a): bool { return $n === 'valid-' . $a; }
function wp_create_nonce(string $a): string { return 'valid-' . $a; }
function wp_logout(): void { $GLOBALS['test_logged_in'] = false; $GLOBALS['test_user_id'] = 0; }
function wp_set_current_user(int $id): void { $GLOBALS['test_user_id'] = $id; $GLOBALS['test_logged_in'] = $id > 0; }
function wp_set_auth_cookie(int $id): void {}
function do_action(string $h, mixed ...$a): void {}
function wp_safe_redirect(string $url): void { throw new Test_Exit('redirect', 302, $url); }
function wp_die(string $msg, string $title = '', array $args = []): void { throw new Test_Exit('die', (int) ($args['response'] ?? 500), $msg); }
function wp_remote_get(string $url, array $args): mixed {
    $GLOBALS['test_remote_calls'] = ($GLOBALS['test_remote_calls'] ?? 0) + 1;
    return ($GLOBALS['test_remote_handler'])($url, $args);
}
function wp_remote_retrieve_response_code(array $r): int { return $r['code']; }
function wp_remote_retrieve_header(array $r, string $n): string { return $r['headers'][$n] ?? ''; }
function get_user_meta(int $id, string $k, bool $s = false): mixed { return $GLOBALS['test_meta'][$id][$k] ?? ''; }
function update_user_meta(int $id, string $k, mixed $v): bool { $GLOBALS['test_meta'][$id][$k] = $v; return true; }
function get_users(array $args): array { return []; }
function username_exists(string $l): int|false { return false; }
function email_exists(string $e): int|false { return false; }
function wp_generate_password(int $len = 12): string { return str_repeat('a', $len); }
function wp_insert_user(array $a): int {
    $id = 500 + count($GLOBALS['test_users']);
    $u = new WP_User($id, [$a['role'] ?? 'subscriber']);
    $u->user_login = $a['user_login'] ?? '';
    $GLOBALS['test_users'][$id] = $u;
    return $id;
}
function wp_update_user(array $a): int { return (int) $a['ID']; }
function add_option(string $k, mixed $v): bool { return true; }
function delete_option(string $k): bool { return true; }

class AVPVH_Directory {
    public static function cached_user_groups(string $uid): array {
        $g = $GLOBALS['test_groups'][$uid] ?? [];
        return is_array($g) ? $g : [];
    }
    public static function get_user_groups(string $uid): array|WP_Error {
        $GLOBALS['test_group_reads'][$uid] = ($GLOBALS['test_group_reads'][$uid] ?? 0) + 1;
        return $GLOBALS['test_groups'][$uid] ?? [];
    }
    public static function remove_from_group(string $uid, string $group): bool {
        $GLOBALS['test_groups'][$uid] = array_values(array_diff($GLOBALS['test_groups'][$uid], [$group]));
        return true;
    }
    public static function forget_groups(string ...$uids): void { AVPVH_Roles::forget_elevated_groups(...$uids); }
}
class AVPVH_Directory_Cache {
    public static int $refreshes = 0;
    public static function table(): string { return 'wp_avm_directory_users'; }
    public static function refresh_user(string $uid): void { self::$refreshes++; }
}

require_once dirname(__DIR__) . '/includes/class-avpvh-address-normalizer.php';
require_once dirname(__DIR__) . '/includes/class-avpvh-address-matcher.php';
require_once dirname(__DIR__) . '/includes/class-db.php';
require_once dirname(__DIR__) . '/includes/class-admin.php';
require_once dirname(__DIR__) . '/includes/class-roles.php';
require_once dirname(__DIR__) . '/includes/class-nav-auth.php';
require_once dirname(__DIR__) . '/includes/class-access.php';
require_once dirname(__DIR__) . '/includes/class-archive-access.php';
require_once dirname(__DIR__) . '/includes/class-avpvh-address-matcher.php';
require_once dirname(__DIR__) . '/includes/class-avpvh-address-normalizer.php';
require_once dirname(__DIR__) . '/includes/class-member-profile-form.php';
require_once dirname(__DIR__) . '/includes/class-member-profile-page.php';
require_once dirname(__DIR__) . '/includes/class-member-profile-sections.php';
require_once dirname(__DIR__) . '/includes/class-media-protection.php';
require_once dirname(__DIR__) . '/includes/tenant/class-tenant-config.php';
require_once dirname(__DIR__) . '/includes/tenant/class-tenant-identity.php';
require_once dirname(__DIR__) . '/includes/tenant/class-tenant-sso.php';
require_once dirname(__DIR__) . '/includes/tenant/class-tenant-content.php';

// Monkey-patch AVPVH_DB::get_member via test helper override? AVPVH_DB::get_member uses $wpdb->get_row,
// so let's verify direct methods and use a subclass / reflection where $wpdb is bypassed.
// First: wire $wpdb->get_row to return from $GLOBALS['test_members']!
$wpdb = new class($wpdb) {
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public string $comments = 'wp_comments';
    public function __construct(private object $inner) {}
    public function prepare(string $sql, mixed ...$args): array { return [$sql, $args]; }
    public function get_var(mixed $q): mixed { return $this->inner->get_var($q); }
    public function get_col(string $sql): array { return $this->inner->get_col($sql); }
    public function get_results(mixed $q): ?array {
        if (!empty($GLOBALS['test_address_unavailable'])) { return null; }
        if (is_string($q) && str_contains($q, 'YEAR(post_date)')) { return [(object) ['ID' => 501, 'y' => 2015], (object) ['ID' => 502, 'y' => 2025]]; }
        return $GLOBALS['test_address_rows'] ?? [];
    }
    public function query(mixed $q): int {
        if (is_array($q) && str_contains($q[0], 'DELETE FROM wp_avm_relationships')) { $GLOBALS['test_db_writes'][] = ['query', $q]; return 1; }
        return 0;
    }
    public function update(string $table, array $data, array $where, mixed ...$formats): int { $GLOBALS['test_db_writes'][] = ['update', $table, $data, $where]; return 1; }
    public function delete(string $table, array $where, mixed ...$formats): int { $GLOBALS['test_db_writes'][] = ['delete', $table, $where]; return 1; }
    public function get_row(mixed $q): ?object {
        if (is_array($q)) {
            [$sql, $args] = $q;
            if (str_contains($sql, 'FROM wp_avm_relationships')) {
                $row = $GLOBALS['test_relationship'] ?? null;
                return $row && $row->id === (int) $args[0] ? $row : null;
            }
            if (str_contains($sql, 'INNER JOIN') && !empty($GLOBALS['test_cache_missing'])) { return null; }
            if (str_contains($sql, 'avm_members WHERE id = %d') || str_contains($sql, 'WHERE m.id = %d')) {
                return $GLOBALS['test_members'][(int) $args[0]] ?? null;
            }
            if (str_contains($sql, 'WHERE m.wp_user_id = %d')) {
                foreach ($GLOBALS['test_members'] as $m) {
                    if ((int) ($m->wp_user_id ?? 0) === (int) $args[0]) { return $m; }
                }
            }
            if (str_contains($sql, 'WHERE m.lldap_user_id = %s') || str_contains($sql, 'WHERE u.user_id = %s')) {
                foreach ($GLOBALS['test_members'] as $m) {
                    if (($m->lldap_user_id ?? '') === $args[0]) { return $m; }
                }
            }
        }
        return null;
    }
};

// Setup fictional members & WP users.
$GLOBALS['test_users'] = [
    1 => new WP_User(1, ['administrator'], ['manage_options', 'edit_users']),
    2 => new WP_User(2, ['subscriber']),
    3 => new WP_User(3, ['subscriber']),
    4 => new WP_User(4, ['subscriber']),
    5 => new WP_User(5, ['editor']),
    6 => new WP_User(6, ['subscriber']),
    7 => new WP_User(7, ['subscriber']),
];
$GLOBALS['test_members'] = [
    10 => (object) ['id' => 10, 'wp_user_id' => 1, 'lldap_user_id' => 'admin.test', 'user_id' => 'admin.test', 'status' => 'active', 'first_name' => 'Admin', 'last_name' => 'Test', 'email' => 'admin@example.invalid'],
    20 => (object) ['id' => 20, 'wp_user_id' => 2, 'lldap_user_id' => 'lid.test', 'user_id' => 'lid.test', 'status' => 'active', 'first_name' => 'Gewoon', 'last_name' => 'Lid', 'email' => 'lid@example.invalid'],
    30 => (object) ['id' => 30, 'wp_user_id' => 3, 'lldap_user_id' => 'voorz.test', 'user_id' => 'voorz.test', 'status' => 'active', 'first_name' => 'Real', 'last_name' => 'Voorzitter', 'email' => 'voorz@example.invalid'],
    40 => (object) ['id' => 40, 'wp_user_id' => 4, 'lldap_user_id' => 'deleg.test', 'user_id' => 'deleg.test', 'status' => 'active', 'first_name' => 'Temp', 'last_name' => 'Delegated', 'email' => 'deleg@example.invalid'],
    50 => (object) ['id' => 50, 'wp_user_id' => 6, 'lldap_user_id' => 'ex.it', 'user_id' => 'ex.it', 'status' => 'inactive', 'first_name' => 'Ex', 'last_name' => 'IT', 'email' => 'exit@example.invalid'],
    60 => (object) ['id' => 60, 'wp_user_id' => 7, 'lldap_user_id' => 'boek.test', 'user_id' => 'boek.test', 'status' => 'active', 'first_name' => 'Boek', 'last_name' => 'Lid', 'email' => 'boek@example.invalid'],
    70 => (object) ['id' => 70, 'wp_user_id' => null, 'lldap_user_id' => 'err.test', 'user_id' => 'err.test', 'status' => 'active', 'first_name' => 'Err', 'last_name' => 'Lid', 'email' => 'err@example.invalid'],
];
$GLOBALS['test_groups'] = [
    'admin.test' => ['leden'],
    'lid.test'   => ['leden'],
    'voorz.test' => ['leden', 'voorzitter'],
    'deleg.test' => ['leden'],
    'ex.it'      => ['ex-leden', 'it-beheer', 'bloggers', 'secretaris'],
    'boek.test'  => ['leden', 'boek'],
    'err.test'   => new WP_Error('ldap_down', 'LDAP unreachable'),
];
$GLOBALS['test_delegations'] = [
    40 => ['voorzitter'],
];

// --- Elevated-account classification ----------------------------------------
check('WP admin counts as elevated', AVPVH_Roles::member_has_elevated_access(10) === true);
check('ordinary active member is not elevated', AVPVH_Roles::member_has_elevated_access(20) === false);
check('real voorzitter counts as elevated', AVPVH_Roles::member_has_elevated_access(30) === true);
check('member with active delegation counts as elevated', AVPVH_Roles::member_has_elevated_access(40) === true);
check('boek member counts as elevated', AVPVH_Roles::member_has_elevated_access(60) === true);
check('unreachable directory fails closed as elevated', AVPVH_Roles::member_has_elevated_access(70) === true);
AVPVH_Roles::member_has_elevated_access(20);
check('security group reads are reused within the request', $GLOBALS['test_group_reads']['lid.test'] === 1);
$GLOBALS['test_groups']['lid.test'][] = 'bloggers';
AVPVH_Directory::forget_groups('lid.test');
check('group write invalidates request security cache', AVPVH_Roles::member_has_elevated_access(20));
array_pop($GLOBALS['test_groups']['lid.test']);
AVPVH_Directory::forget_groups('lid.test');
check('removing only boek reports a privilege removal', AVPVH_Roles::strip_bestuur_roles(60));
$GLOBALS['test_groups']['boek.test'] = ['leden', 'boek'];
AVPVH_Directory::forget_groups('boek.test');

// --- Role & delegation boundaries -------------------------------------------
wp_set_current_user(3); // real voorzitter
check('real voorzitter can manage roles', AVPVH_Roles::can_manage_roles() === true);
check('real voorzitter can manage page permissions', AVPVH_Roles::can_manage_page_permissions() === true);
wp_set_current_user(4); // temporarily delegated voorzitter
check('delegated voorzitter cannot manage roles', AVPVH_Roles::can_manage_roles() === false);
check('delegated voorzitter cannot manage page permissions', AVPVH_Roles::can_manage_page_permissions() === false);
wp_set_current_user(6); // inactive member
check('inactive member has no it-beheer access', AVPVH_Roles::current_user_is_it_admin() === false);
check('inactive member has no blogger access', AVPVH_Roles::current_user_is_blogger() === false);
check('inactive member has no secretaris role', AVPVH_Roles::current_user_has_role('secretaris') === false);

// A denied explicit target must stop the save, never select the actor instead.
wp_set_current_user(3);
$GLOBALS['test_groups']['voorz.test'][] = 'secretaris';
$GLOBALS['test_session_data'] = ['avpvh_auth_method' => 'proxy', 'avpvh_2fa' => true];
$_POST = ['member_id' => 10, 'first_name' => 'Fictieve doelnaam', 'street' => 'Voorbeeldstraat'];
$profile_form = new AVPVH_Member_Profile_Form();
$save_error = null;
try { $profile_form->handle_save_profile(); } catch (Test_Exit $e) { $save_error = $e; }
check('elevated target save stops with 403 before any writes', $save_error instanceof Test_Exit && $save_error->kind === 'json_error' && $save_error->status === 403);
[$profile_target, $profile_view] = AVPVH_Member_Profile_Page::resolve($GLOBALS['test_members'][30], 10);
check('officer does not get an elevated target edit form', $profile_target->id === 30 && $profile_view === 'own');
$resolve_save = new ReflectionMethod($profile_form, 'get_target_member_for_save');
$_POST = [];
check('omitted save target still selects own profile', $resolve_save->invoke($profile_form)->id === 30);
$_POST = ['member_id' => 0];
$save_error = null;
try { $profile_form->handle_save_profile(); } catch (Test_Exit $e) { $save_error = $e; }
check('invalid explicit save target stops with 403', $save_error instanceof Test_Exit && $save_error->status === 403);
$_POST = ['member_id' => 20];
check('2FA officer can still select an ordinary target', $resolve_save->invoke($profile_form)->id === 20);
$GLOBALS['test_session_data']['avpvh_2fa'] = false;
$save_error = null;
try { $profile_form->handle_save_profile(); } catch (Test_Exit $e) { $save_error = $e; }
check('1FA officer cannot redirect another member save to own record', $save_error instanceof Test_Exit && $save_error->status === 403);
[$profile_target, $profile_view] = AVPVH_Member_Profile_Page::resolve($GLOBALS['test_members'][30], 20);
check('1FA officer does not get another member admin edit form', $profile_target->id === 30 && $profile_view === 'own');
ob_start();
AVPVH_Member_Profile_Sections::render_relationships($GLOBALS['test_members'][20]);
$relationship_html = ob_get_clean();
check('1FA relationship view offers step-up before editing', str_contains($relationship_html, 'tweestapsverificatie') && !str_contains($relationship_html, 'value="avpvh_add_relationship"'));
$_POST = [];
array_pop($GLOBALS['test_groups']['voorz.test']);

// --- Safe WP user linking ---------------------------------------------------
check('refuses linking WP administrator', AVPVH_DB::can_safely_link_wp_user($GLOBALS['test_users'][1], 99) === false);
check('refuses linking WP editor', AVPVH_DB::can_safely_link_wp_user($GLOBALS['test_users'][5], 99) === false);
check('refuses linking subscriber already linked to another member', AVPVH_DB::can_safely_link_wp_user($GLOBALS['test_users'][2], 99) === false);
$unlinked_sub = new WP_User(88, ['subscriber']);
check('allows linking unlinked subscriber', AVPVH_DB::can_safely_link_wp_user($unlinked_sub, 99) === true);

// --- Minor age checks (birth_date and birth_year) ---------------------------
check('birth_date age 15 is minor', AVPVH_DB::is_minor_member((object) ['birth_date' => '2011-01-01', 'birth_year' => null], 16) === true);
check('birth_date age 16 is not minor', AVPVH_DB::is_minor_member((object) ['birth_date' => '2010-01-01', 'birth_year' => null], 16) === false);
check('birth_year-only 2012 (in 2026) is minor', AVPVH_DB::is_minor_member((object) ['birth_date' => null, 'birth_year' => 2012], 16) === true);
check('birth_year-only 2010 (in 2026) conservatively minor', AVPVH_DB::is_minor_member((object) ['birth_date' => null, 'birth_year' => 2010], 16) === true);
check('birth_year-only 2005 (in 2026) is not minor', AVPVH_DB::is_minor_member((object) ['birth_date' => null, 'birth_year' => 2005], 16) === false);
check('uncertain sixteenth birthday grants no identity/consent rights', !AVPVH_DB::is_minor_member((object) ['birth_year' => 2010], 16, true));
check('certain under-sixteen birth year still grants guardian rights', AVPVH_DB::is_minor_member((object) ['birth_year' => 2011], 16, true));
check('uncertain eighteenth birthday grants no parent management rights', !AVPVH_DB::is_minor_member((object) ['birth_year' => 2008], 18, true));

// --- REST, oEmbed and comment filtering -------------------------------------
$pw_page = new WP_Post();
$pw_page->ID = 42;
$pw_page->post_password = 'secret';
$GLOBALS['test_posts'][42] = $pw_page;

$access = new AVPVH_Access();
wp_logout(); // anonymous visitor
$res = $access->exclude_members_pages_from_rest_query(['post__in' => [10, 11, 15]]);
check('anonymous REST post__in of /leden/ pages becomes [0]', ($res['post__in'] ?? null) === [0]);
$res_mixed = $access->exclude_members_pages_from_rest_query(['post__in' => [11, 20]]);
check('anonymous REST post__in mixed keeps only public page 20', ($res_mixed['post__in'] ?? null) === [20]);
check('anonymous REST query sets has_password=false', ($res_mixed['has_password'] ?? null) === false);
check('anonymous single REST password-protected page returns 404', $access->hide_members_page_from_rest(null, [], new WP_REST_Request('/wp/v2/pages/42')) instanceof WP_Error);
check('anonymous REST /wp/v2/media listing passes to the bucket filter', $access->hide_members_page_from_rest(null, [], new WP_REST_Request('/wp/v2/media')) === null);
$GLOBALS['test_post_meta'][90]['_wp_attached_file'] = 'public/2026/example.jpg';
$GLOBALS['test_post_meta'][91]['_wp_attached_file'] = 'private/2026/example.jpg';
check('anonymous REST public media item is allowed', $access->hide_members_page_from_rest(null, [], new WP_REST_Request('/wp/v2/media/90')) === null);
check('anonymous REST private media item is blocked', $access->hide_members_page_from_rest(null, [], new WP_REST_Request('/wp/v2/media/91')) instanceof WP_Error);
$media_args = $access->filter_public_media_query([]);
check('public media collections restrict the attachment file prefix', $media_args['meta_query'][0] === ['key' => '_wp_attached_file', 'value' => '^public/', 'compare' => 'REGEXP']);
$existing_meta = ['relation' => 'OR', ['key' => 'a'], ['key' => 'b']];
$media_args = $access->filter_public_media_query(['meta_query' => $existing_meta]);
check('public media restriction is ANDed with existing OR queries', $media_args['meta_query']['relation'] === 'AND' && $media_args['meta_query'][0] === $existing_meta);
check('anonymous oEmbed password-protected page returns 0', $access->hide_members_page_from_oembed(42) === 0);
$c_res = $access->exclude_restricted_comments_from_rest_query(['post_id' => 11]);
check('anonymous REST comment query for /leden/ page becomes [0]', ($c_res['post__in'] ?? null) === [0] && !isset($c_res['post_id']));
$comment_clauses = $access->exclude_restricted_comments_from_clauses(['where' => '']);
check('empty member comment WHERE starts with a valid predicate', str_starts_with($comment_clauses['where'], '1=1 AND '));
$GLOBALS['test_cron'] = true;
check('member comments are unfiltered during cron', $access->exclude_restricted_comments_from_clauses(['where' => '']) === ['where' => '']);
$GLOBALS['test_cron'] = false;

wp_set_current_user(2); // ordinary active member
check('active member media collections are not restricted to public files', $access->filter_public_media_query([]) === []);
$m_dmarc = $access->exclude_members_pages_from_rest_query(['post__in' => [15, 1920]]);
check('ordinary member REST post__in of restricted pages becomes [0]', ($m_dmarc['post__in'] ?? null) === [0]);
check('ordinary member single REST DMARC page returns 404', $access->hide_members_page_from_rest(null, [], new WP_REST_Request('/wp/v2/pages/15')) instanceof WP_Error);
check('ordinary member single REST boek page returns 404', $access->hide_members_page_from_rest(null, [], new WP_REST_Request('/wp/v2/pages/1920')) instanceof WP_Error);
check('ordinary member oEmbed boek page returns 0', $access->hide_members_page_from_oembed(1920) === 0);

wp_set_current_user(7); // active member with boek group
check('boek member single REST boek page passes', $access->hide_members_page_from_rest(null, [], new WP_REST_Request('/wp/v2/pages/1920')) === null);
check('boek member without DMARC permission gets 404 on DMARC page', $access->hide_members_page_from_rest(null, [], new WP_REST_Request('/wp/v2/pages/15')) instanceof WP_Error);

// --- Step-up 2FA checks -----------------------------------------------------
wp_set_current_user(1); // WP Admin
$GLOBALS['test_session_data'] = ['avpvh_auth_method' => 'oauth', 'avpvh_2fa' => false];
check('1FA OAuth session reports no 2FA', AVPVH_Access::current_session_has_2fa() === false);
$r_post = $access->enforce_step_up_2fa_for_rest(null, [], new WP_REST_Request('/wp/v2/posts', 'POST'));
check('1FA admin blocked on POST /wp/v2/posts', $r_post instanceof WP_Error && $r_post->data['status'] === 403);
$r_users = $access->enforce_step_up_2fa_for_rest(null, [], new WP_REST_Request('/wp/v2/users', 'GET'));
check('1FA admin blocked on GET /wp/v2/users', $r_users instanceof WP_Error && $r_users->data['status'] === 403);
$r_own = $access->enforce_step_up_2fa_for_rest(null, [], new WP_REST_Request('/avpvh/v1/oauth/google/start', 'GET'));
check('1FA admin allowed on /avpvh/v1/ routes', $r_own === null);
check('1FA admin blocked on POST to an unregistered read route', $access->enforce_step_up_2fa_for_rest(null, [], new WP_REST_Request('/avpvh-fav/v1/search', 'POST')) instanceof WP_Error);
$GLOBALS['test_filters']['avpvh_mbrs_2fa_exempt_rest_routes'] = static fn(array $r): array => [...$r, '/avpvh-fav/v1/search', '/x/v1/*', 42];
check('1FA admin allowed on a registered read-only POST route', $access->enforce_step_up_2fa_for_rest(null, [], new WP_REST_Request('/avpvh-fav/v1/search', 'POST')) === null);
check('exempt routes match exactly, not by prefix', $access->enforce_step_up_2fa_for_rest(null, [], new WP_REST_Request('/avpvh-fav/v1/search/x', 'POST')) instanceof WP_Error);
check('other routes of that plugin stay behind 2FA', $access->enforce_step_up_2fa_for_rest(null, [], new WP_REST_Request('/avpvh-fav/v1/scan/delete', 'POST')) instanceof WP_Error);
check('wildcard-looking exempt entries are ignored', $access->enforce_step_up_2fa_for_rest(null, [], new WP_REST_Request('/x/v1/anything', 'POST')) instanceof WP_Error);
unset($GLOBALS['test_filters']['avpvh_mbrs_2fa_exempt_rest_routes']);

$pagenow = 'admin-post.php';
$_REQUEST['action'] = 'avpvh_save_settings';
$blocked_admin_post = false;
try {
    $access->enforce_step_up_2fa_for_admin_post();
} catch (Test_Exit $e) {
    $blocked_admin_post = ($e->kind === 'die' && $e->status === 403);
}
check('1FA admin blocked on admin-post avpvh_save_settings', $blocked_admin_post);

$_REQUEST['action'] = 'avpvh_set_directory_consent';
$access->enforce_step_up_2fa_for_admin_post();
check('1FA user allowed on front-end admin-post avpvh_set_directory_consent', true);
foreach (['hide', 'tidy', 'delete', 'recreate'] as $gallery_action) {
    $_REQUEST['action'] = 'avpvh_gallery_share_' . $gallery_action;
    $access->enforce_step_up_2fa_for_admin_post();
    check('1FA member allowed to ' . $gallery_action . ' own gallery share', true);
}
$_REQUEST['action'] = 'avpvh_add_relationship';
$relationship_blocked = false;
try { $access->enforce_step_up_2fa_for_admin_post(); } catch (Test_Exit $e) { $relationship_blocked = $e->status === 403; }
check('granting relationship-based access still requires 2FA', $relationship_blocked);

$GLOBALS['test_session_data']['avpvh_2fa'] = true;
check('2FA session passes POST /wp/v2/posts', $access->enforce_step_up_2fa_for_rest(null, [], new WP_REST_Request('/wp/v2/posts', 'POST')) === null);

// --- Proxy header scoping & optional secret ---------------------------------
wp_logout();
$_SERVER['HTTP_REMOTE_USER'] = 'admin.test';
$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts';
$_SERVER['HTTP_X_AVPVH_PROXY_SECRET'] = AVPVH_PROXY_SECRET;
$access->auto_login_from_proxy_header();
check('proxy header ignored on /wp-json/', !is_user_logged_in());
$_SERVER['REQUEST_URI'] = '/?rest_route=%2Fwp%2Fv2%2Fposts';
$_GET['rest_route'] = '/wp/v2/posts';
$access->auto_login_from_proxy_header();
check('proxy header ignored on query-string REST route before REST_REQUEST exists', !is_user_logged_in());
unset($_GET['rest_route']);

$_SERVER['REQUEST_URI'] = '/avpvh-sso/';
$_SERVER['HTTP_X_AVPVH_PROXY_SECRET'] = 'wrong-secret';
$access->auto_login_from_proxy_header();
check('proxy header rejected when AVPVH_PROXY_SECRET mismatches', !is_user_logged_in());

// --- Media bucketing for unpublished pages ----------------------------------
$mp = new AVPVH_Media_Protection();
$determine = new ReflectionMethod(AVPVH_Media_Protection::class, 'determine_bucket');
$draft_pub = new WP_Post(); $draft_pub->ID = 99; $draft_pub->post_status = 'draft';
$priv_pub  = new WP_Post(); $priv_pub->ID = 98; $priv_pub->post_status = 'private';
$draft_mem = new WP_Post(); $draft_mem->ID = 77; $draft_mem->post_status = 'draft';
$pub_page  = new WP_Post(); $pub_page->ID = 97; $pub_page->post_status = 'publish';
check('draft page uploads to private', $determine->invoke($mp, $draft_pub) === 'private');
check('private page uploads to private', $determine->invoke($mp, $priv_pub) === 'private');
check('draft child of /leden/ uploads to private', $determine->invoke($mp, $draft_mem) === 'private');
check('published public page uploads to public', $determine->invoke($mp, $pub_page) === 'public');

// --- Tenant REST filtering, 2FA admin check & logout confirmation -----------
$t_cfg = AVPVH_Tenant_Config::from_array([
    'tenant_id' => 'rsp_vve_vp4042', 'ancestors' => ['rsp', 'rsp_vve'],
    'canonical_host' => 'site.example.invalid',
    'authelia_url' => 'https://auth.example.invalid',
    'authelia_authz_url' => 'http://authelia:9091/api/authz/auth-request',
    'authelia_session_cookie' => 'test_session',
    'member_group' => 'vp4042-leden',
    'admin_group' => 'rsp-vve-vp4042-admins',
    'ancestor_admin_groups' => ['rsp-admins', 'rsp-vve-admins'],
    'members_only_pages' => ['leden'],
], 'site.example.invalid');
$t_sso = new AVPVH_Tenant_SSO($t_cfg);
$t_content = new AVPVH_Tenant_Content($t_cfg, $t_sso);

wp_logout();
$t_rest = $t_content->exclude_from_rest_query(['post__in' => [10, 11]]);
check('tenant REST post__in of protected pages becomes [0]', ($t_rest['post__in'] ?? null) === [0]);
check('tenant oEmbed protected page returns 0', $t_content->hide_from_oembed(11) === 0);
$tenant_comments = $t_content->exclude_comments_from_clauses(['where' => '']);
check('empty tenant comment WHERE starts with a valid predicate', str_starts_with($tenant_comments['where'], '1=1 AND '));
$GLOBALS['test_cron'] = true;
check('tenant comments are unfiltered during cron', $t_content->exclude_comments_from_clauses(['where' => '']) === ['where' => '']);
$GLOBALS['test_cron'] = false;

// 1FA admin who is also a member -> downgraded to subscriber.
$_COOKIE['test_session'] = 'sess-ok';
$GLOBALS['test_remote_handler'] = static function (string $url, array $args): array {
    $orig = $args['headers']['X-Original-URL'] ?? '';
    if (str_ends_with($orig, '/wp-admin/')) {
        return ['code' => 302, 'headers' => []];
    }
    return ['code' => 200, 'headers' => [
        'remote-user' => 'admin.member',
        'remote-groups' => 'rsp-admins,vp4042-leden',
        'remote-email' => 'am@example.invalid',
        'remote-name' => 'Admin Member',
    ]];
};
$_SERVER['REQUEST_URI'] = '/avpvh-sso/';
$_GET = [];
try { $t_sso->handle_sso_callback(); } catch (Test_Exit $e) {}
check('tenant 1FA admin+member gets subscriber role until 2FA', $GLOBALS['test_users'][$GLOBALS['test_user_id']]->roles === ['subscriber']);

// A second browser's 2FA login changes the account role, not the first browser's proof.
$tenant_user_id = get_current_user_id();
$GLOBALS['test_users'][$tenant_user_id]->set_role('administrator');
$GLOBALS['test_meta'][$tenant_user_id][AVPVH_Tenant_SSO::CHECKED_META] = time();
$GLOBALS['test_session_data'] = ['avpvh_login' => 'authelia', 'avpvh_checked' => time()];
$_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php';
$t_sso->recheck_session(); // cached membership check must not authorize elevated capabilities
$admin_caps = ['read' => true, 'level_0' => true, 'manage_options' => true, 'edit_users' => true, 'edit_posts' => true, 'custom_admin_action' => true];
$GLOBALS['test_remote_calls'] = 0;
$low_caps = $t_sso->filter_session_capabilities($admin_caps, ['manage_options'], [], $GLOBALS['test_users'][$tenant_user_id]);
check('tenant 1FA browser cannot inherit another browser admin role', !$low_caps['manage_options'] && !$low_caps['edit_users'] && !$low_caps['edit_posts'] && !$low_caps['custom_admin_action']);
check('tenant 1FA browser retains read access', $low_caps['read'] && $low_caps['level_0']);
$t_sso->filter_session_capabilities($admin_caps, ['edit_posts'], [], $GLOBALS['test_users'][$tenant_user_id]);
check('tenant privileged checks share one probe within the request', $GLOBALS['test_remote_calls'] === 1);
check('tenant session starts its own recheck clock', isset($t_sso->attach_login_method([])['avpvh_checked']));

$_COOKIE['test_session'] = 'two-factor-browser';
$GLOBALS['test_remote_handler'] = static fn(string $url, array $args): array => ['code' => 200, 'headers' => [
    'remote-user' => 'admin.member', 'remote-groups' => 'rsp-admins,vp4042-leden',
    'remote-email' => 'am@example.invalid', 'remote-name' => 'Admin Member',
]];
check('tenant 2FA browser keeps administrator capabilities', $t_sso->filter_session_capabilities($admin_caps, ['manage_options'], [], $GLOBALS['test_users'][$tenant_user_id]) === $admin_caps);
$GLOBALS['test_session_data']['avpvh_login'] = 'google';
$oauth_caps = $t_sso->filter_session_capabilities($admin_caps, ['manage_options'], [], $GLOBALS['test_users'][$tenant_user_id]);
check('tenant OAuth session stays read-only even with a 2FA Authelia cookie', !$oauth_caps['manage_options'] && !$oauth_caps['custom_admin_action']);
$GLOBALS['test_session_data']['avpvh_login'] = 'authelia';
$_COOKIE['test_session'] = 'manual-editor-browser';
$GLOBALS['test_users'][$tenant_user_id]->set_role('editor');
$GLOBALS['test_remote_handler'] = static fn(string $url, array $args): array => ['code' => 200, 'headers' => [
    'remote-user' => 'admin.member', 'remote-groups' => 'vp4042-leden',
]];
$editor_caps = ['read' => true, 'edit_posts' => true, 'manage_options' => false];
check('tenant manually assigned editor keeps writing rights after 2FA', $t_sso->filter_session_capabilities($editor_caps, ['edit_posts'], [], $GLOBALS['test_users'][$tenant_user_id]) === $editor_caps);
$GLOBALS['test_users'][$tenant_user_id]->set_role('administrator');
check('tenant administrator still needs an actual admin group after 2FA', !$t_sso->filter_session_capabilities($admin_caps, ['manage_options'], [], $GLOBALS['test_users'][$tenant_user_id])['manage_options']);
$_COOKIE['test_session'] = 'wrong-identity-browser';
$GLOBALS['test_remote_handler'] = static fn(string $url, array $args): array => ['code' => 200, 'headers' => [
    'remote-user' => 'other.admin', 'remote-groups' => 'rsp-admins',
]];
check('tenant 2FA for a different identity grants no admin capability', !$t_sso->filter_session_capabilities($admin_caps, ['manage_options'], [], $GLOBALS['test_users'][$tenant_user_id])['manage_options']);
$_COOKIE['test_session'] = 'unreachable-browser';
$GLOBALS['test_remote_handler'] = static fn(string $url, array $args): array => ['code' => 503, 'headers' => []];
check('tenant capability check fails closed when Authelia is unavailable', !$t_sso->filter_session_capabilities($admin_caps, ['manage_options'], [], $GLOBALS['test_users'][$tenant_user_id])['manage_options']);
wp_set_current_user(1);
check('local emergency admin retains capabilities without Authelia', $t_sso->filter_session_capabilities($admin_caps, ['manage_options'], [], $GLOBALS['test_users'][1]) === $admin_caps);

// 1FA admin-only (not in member_group) -> redirected to Authelia for /wp-admin/ 2FA.
wp_logout();
$_SERVER['REQUEST_URI'] = '/avpvh-sso/';
$GLOBALS['test_remote_handler'] = static function (string $url, array $args): array {
    $orig = $args['headers']['X-Original-URL'] ?? '';
    if (str_ends_with($orig, '/wp-admin/')) {
        return ['code' => 302, 'headers' => []];
    }
    return ['code' => 200, 'headers' => [
        'remote-user' => 'admin.only',
        'remote-groups' => 'rsp-admins',
        'remote-email' => 'ao@example.invalid',
        'remote-name' => 'Admin Only',
    ]];
};
$bounced = '';
try { $t_sso->handle_sso_callback(); } catch (Test_Exit $e) { $bounced = $e->detail; }
check('tenant 1FA admin-only gets no WP session and redirects to 2FA', !is_user_logged_in() && str_contains($bounced, 'auth.example.invalid') && str_contains($bounced, 'wp-admin'));

// Invalid tenant logout nonce -> asks confirmation instead of redirecting to Authelia /logout.
wp_set_current_user(2);
$_GET = [AVPVH_Tenant_SSO::LOGOUT_ACTION => 'bogus-nonce'];
$logout_exit = null;
try { $t_sso->handle_logout(); } catch (Test_Exit $e) { $logout_exit = $e; }
check('tenant invalid logout nonce shows confirmation', $logout_exit instanceof Test_Exit && $logout_exit->kind === 'die' && is_user_logged_in());

// Strict subscriber-only check for tenant OAuth accounts.
check('tenant subscriber-only check accepts plain subscriber', AVPVH_Tenant_SSO::user_is_subscriber_only(2) === true);
check('tenant subscriber-only check rejects editor', AVPVH_Tenant_SSO::user_is_subscriber_only(5) === false);
check('tenant subscriber-only check rejects administrator', AVPVH_Tenant_SSO::user_is_subscriber_only(1) === false);

// Same-site redirect validation refuses external IdP hosts.
if (!function_exists('wp_validate_redirect')) {
    function wp_validate_redirect(string $url, string $fallback): string { return $url; }
}
check('same-site redirect keeps local URL', AVPVH_Access::safe_same_site_redirect('https://site.example.invalid/leden/') === 'https://site.example.invalid/leden/');
check('same-site redirect rejects external IdP host', AVPVH_Access::safe_same_site_redirect('https://accounts.google.com/o/oauth2/v2/auth') === 'https://site.example.invalid/');

// Request and session transitions exercise production filters, not copied rules.
AVPVH_Access::mark_next_session_auth('oauth', false);
$session = $access->attach_session_auth_metadata(['expiration' => 123]);
check('new session retains its expiry and records its login method and factor', $session === ['expiration' => 123, 'avpvh_auth_method' => 'oauth', 'avpvh_2fa' => false]);
check('pending authentication metadata is used only once', $access->attach_session_auth_metadata(['expiration' => 456]) === ['expiration' => 456]);
wp_logout();
check('anonymous viewer has no completed step-up', !AVPVH_Access::current_session_has_2fa());
wp_set_current_user(1);
$GLOBALS['test_session_token'] = '';
check('non-cookie authentication cannot satisfy 2FA', !AVPVH_Access::current_session_has_2fa());
unset($GLOBALS['test_session_token']);
$GLOBALS['test_session_data'] = ['expiration' => 123];
check('legacy session metadata cannot satisfy 2FA', !AVPVH_Access::current_session_has_2fa());
$GLOBALS['test_session_data'] = ['avpvh_auth_method' => 'oauth', 'avpvh_2fa' => false];
$_SERVER['HTTP_REMOTE_USER'] = 'admin.test';
$_SERVER['HTTP_X_AVPVH_PROXY_SECRET'] = AVPVH_PROXY_SECRET;
$_SERVER['REQUEST_URI'] = '/wp-admin/';
$GLOBALS['test_admin'] = true;
$pagenow = 'index.php';
$access->auto_login_from_proxy_header();
check('matching proxy identity upgrades only the current session after admin step-up', get_current_user_id() === 1 && $GLOBALS['test_session_data']['avpvh_2fa'] === true);
foreach (['admin-post.php', 'admin-ajax.php', 'async-upload.php'] as $endpoint) {
    $GLOBALS['test_session_data']['avpvh_2fa'] = false;
    $pagenow = $endpoint;
    $GLOBALS['test_ajax'] = $endpoint === 'admin-ajax.php';
    $access->auto_login_from_proxy_header();
    check($endpoint . ' does not upgrade a 1FA session', !$GLOBALS['test_session_data']['avpvh_2fa']);
}
$GLOBALS['test_ajax'] = $GLOBALS['test_admin'] = false;
$pagenow = 'index.php';
$access->enforce_step_up_2fa_for_admin_post();
check('admin-post gate ignores unrelated request types', true);
check('1FA administrator can read ordinary public REST content', $access->enforce_step_up_2fa_for_rest(null, [], new WP_REST_Request('/wp/v2/posts')) === null);
check('administrator can read the document page through REST', $access->hide_members_page_from_rest(null, [], new WP_REST_Request('/wp/v2/pages/1920')) === null);
wp_set_current_user(2);
check('ordinary member REST writes retain endpoint-specific permission checks', $access->enforce_step_up_2fa_for_rest(null, [], new WP_REST_Request('/example/v1/form', 'POST')) === null);
wp_set_current_user(6);
check('inactive member cannot read the document page', $access->hide_members_page_from_rest(null, [], new WP_REST_Request('/wp/v2/pages/1920')) instanceof WP_Error);
wp_logout();
$_SERVER['HTTP_REMOTE_USER'] = 'missing.fixture';
$refreshes = AVPVH_Directory_Cache::$refreshes;
$access->auto_login_from_proxy_header();
check('unknown proxy account is refreshed once and never logged in', !is_user_logged_in() && AVPVH_Directory_Cache::$refreshes === $refreshes + 1);
foreach ([[11, 20], [11]] as $include) {
    $query = new WP_Query(true);
    $query->set('post__in', $include);
    $access->exclude_members_pages_from_queries($query);
    check('member search include list excludes restricted IDs ' . implode(',', $include), $query->get('post__in') === (in_array(20, $include, true) ? [20] : [0]));
}
$comments = $access->exclude_restricted_comments_from_rest_query(['post__in' => [11, 20]]);
check('member REST comment include list retains public posts only', $comments['post__in'] === [20]);
$saved_db = $wpdb;
$wpdb = null;
check('comment filtering tolerates an unavailable query connection', $access->exclude_restricted_comments_from_clauses(['where' => 'x=1']) === ['where' => 'x=1']);
$wpdb = $saved_db;
$_SERVER['HTTP_REMOTE_USER'] = '';
$_SERVER['REQUEST_URI'] = '/';

$GLOBALS['test_page_id'] = 1920;
$book_guard = new ReflectionMethod($access, 'redirect_boek_page');
$book_exit = null;
try { $book_guard->invoke($access); } catch (Test_Exit $e) { $book_exit = $e; }
check('document-page visitor is sent to the login page', $book_exit?->detail === home_url('/avpvh-login/'));
wp_set_current_user(2);
$book_exit = null;
try { $book_guard->invoke($access); } catch (Test_Exit $e) { $book_exit = $e; }
check('document-page member without the group is sent home', $book_exit?->detail === home_url('/'));
wp_set_current_user(7);
check('document-page member with the group passes the guard', $book_guard->invoke($access) === null);
unset($GLOBALS['test_page_id']);

wp_logout();
foreach ([[11, 20], [11]] as $include) {
    $query = new WP_Query(true);
    $query->set('post__in', $include);
    $t_content->exclude_from_queries($query);
    check('tenant search include list excludes protected IDs ' . implode(',', $include), $query->get('post__in') === (in_array(20, $include, true) ? [20] : [0]));
}
$query = new WP_Query(true);
$query->set('post__not_in', [99]);
$t_content->exclude_from_queries($query);
check('tenant search preserves existing exclusions', in_array(99, $query->get('post__not_in'), true) && in_array(11, $query->get('post__not_in'), true));
$query = new WP_Query(false, false, true, true);
$query->set('post__in', [11]);
$t_content->exclude_from_queries($query);
check('direct tenant page queries remain for the request guard', $query->get('post__in') === [11]);
$comments = $t_content->exclude_comments_from_rest_query(['post_id' => 11]);
check('tenant comments on protected pages cannot be queried directly', $comments['post__in'] === [0] && !isset($comments['post_id']));
$comments = $t_content->exclude_comments_from_rest_query(['post__in' => [11, 20]]);
check('tenant mixed comment lists retain public page comments only', $comments['post__in'] === [20]);
check('tenant oEmbed preserves public pages', $t_content->hide_from_oembed(20) === 20);
wp_set_current_user(2);
$GLOBALS['test_meta'][2][AVPVH_Tenant_SSO::GROUPS_META] = ['vp4042-leden'];
check('tenant member collection args are unchanged', $t_content->exclude_from_rest_query(['post__in' => [11]]) === ['post__in' => [11]]);
check('tenant member comment args are unchanged', $t_content->exclude_comments_from_rest_query(['post_id' => 11]) === ['post_id' => 11]);

// Regression cases for the reported REST, session and elevated-target bypasses.
wp_logout();
foreach (['/WP/v2/PaGeS/11', '/wp/v2/PAGES/11/revisions'] as $route) {
    check('mixed-case restricted main page is hidden: ' . $route, $access->hide_members_page_from_rest(null, [], new WP_REST_Request($route)) instanceof WP_Error);
    check('mixed-case restricted tenant page is hidden: ' . $route, $t_content->hide_from_rest_item(null, [], new WP_REST_Request($route)) instanceof WP_Error);
}
$archive = new AVPVH_Archive_Access();
AVPVH_Archive_Access::reset();
check('mixed-case private blog post is hidden', $archive->hide_post_from_rest(null, [], new WP_REST_Request('/wp/v2/PoStS/501')) instanceof WP_Error);
$GLOBALS['test_comment_posts'] = [101 => 11, 102 => 20, 103 => 501];
foreach ([
    'main' => new AVPVH_Content_Guard([$access, 'post_is_restricted']),
    'tenant' => new AVPVH_Content_Guard(fn(int $id): bool => !$t_content->current_user_may_read() && in_array($id, $t_content->protected_ids(), true)),
    'archive' => new AVPVH_Content_Guard(fn(int $id): bool => in_array($id, AVPVH_Archive_Access::hidden_post_ids(), true)),
] as $mode => $guard) {
    $post = $mode === 'archive' ? 501 : 11;
    $comment = $mode === 'archive' ? 103 : 101;
    foreach (['GET', 'HEAD', 'POST', 'PUT', 'DELETE'] as $method) {
        $result = $guard->guard_rest_comments(null, [], new WP_REST_Request('/wP/v2/CoMmEnTs/' . $comment, $method));
        check($mode . ': direct comment ' . $method . ' cannot bypass parent access', $result instanceof WP_Error && $result->data['status'] === (in_array($method, ['GET', 'HEAD'], true) ? 404 : 403));
    }
    check($mode . ': comment creation on hidden parent is forbidden', $guard->guard_rest_comments(null, [], new WP_REST_Request('/wp/v2/Comments', 'POST', ['post' => $post])) instanceof WP_Error);
    check($mode . ': moving a public comment to a hidden parent is forbidden', $guard->guard_rest_comments(null, [], new WP_REST_Request('/wp/v2/comments/102', 'POST', ['post' => $post])) instanceof WP_Error);
    check($mode . ': public comment reads pass', $guard->guard_rest_comments(null, [], new WP_REST_Request('/wp/v2/comments/102')) === null);
    check($mode . ': hidden comments are closed, public comments retain their setting', !$guard->comments_open(true, $post) && $guard->comments_open(true, 20) && !$guard->comments_open(false, 20));
    $denied = false;
    try { $guard->guard_comment_submission($post); } catch (Test_Exit $e) { $denied = $e->status === 403; }
    check($mode . ': classic comment submissions are forbidden', $denied);
    $GLOBALS['test_url_posts']['https://site.example.invalid/hidden/'] = $post;
    check($mode . ': guessed hidden permalinks are not disclosed', $guard->guard_canonical_redirect('https://site.example.invalid/hidden/') === false);
    check($mode . ': unrelated canonical redirects pass', $guard->guard_canonical_redirect('https://site.example.invalid/public/') === 'https://site.example.invalid/public/');
}
wp_set_current_user(1);
$GLOBALS['test_session_data'] = ['avpvh_auth_method' => 'oauth', 'avpvh_2fa' => false];
foreach (['/wp/v2/UsErS', '/WP/v2/SeTTings'] as $route) {
    check('sensitive mixed-case REST read requires 2FA: ' . $route, $access->enforce_step_up_2fa_for_rest(null, [], new WP_REST_Request($route)) instanceof WP_Error);
}
foreach (['admin-post.php', 'admin-ajax.php'] as $endpoint) {
    $pagenow = $endpoint;
    $_REQUEST['action'] = 'companion_privileged_action';
    $blocked = false;
    try {
        $endpoint === 'admin-post.php' ? $access->enforce_step_up_2fa_for_admin_post() : $access->enforce_step_up_2fa_for_ajax();
    } catch (Test_Exit $e) { $blocked = $e->status === 403; }
    check($endpoint . ': companion actions require step-up', $blocked);
}
foreach (['avpvh_save_member_profile', 'avpvh_media_session_status', 'gallery_filter'] as $action) {
    $_REQUEST['action'] = $action;
    $access->enforce_step_up_2fa_for_ajax();
    check('1FA frontend AJAX remains accessible: ' . $action, true);
}
wp_logout();
$_SERVER['HTTP_REMOTE_USER'] = 'admin.test';
$_SERVER['HTTP_X_AVPVH_PROXY_SECRET'] = AVPVH_PROXY_SECRET;
$_SERVER['REQUEST_URI'] = '/leden/zoeken-in-documenten/';
$_POST['rest_route'] = '/wp/v2/pages';
$access->auto_login_from_proxy_header();
check('POST rest_route never authenticates from proxy headers', !is_user_logged_in());
$_POST = [];
$_SERVER['REQUEST_URI'] = '/WP-JSON/wp/v2/posts';
$access->auto_login_from_proxy_header();
check('mixed-case REST prefix never authenticates from proxy headers', !is_user_logged_in());
$GLOBALS['test_cache_missing'] = true;
check('cache miss cannot hide an elevated local WordPress account', AVPVH_Roles::member_has_elevated_access(10));
check('cache miss cannot hide directory groups', AVPVH_Roles::member_has_elevated_access(30));
check('cache miss cannot hide active delegations', AVPVH_Roles::member_has_elevated_access(40));
unset($GLOBALS['test_cache_missing']);
check('missing security target fails closed', AVPVH_Roles::member_has_elevated_access(999999));
wp_set_current_user(4);
$address = (object) ['street' => 'Fictieve Teststraat', 'house_number' => '1', 'house_number_addition' => '', 'postal_code' => '1234 AB', 'city' => 'Teststad', 'country' => 'Nederland'];
$GLOBALS['test_address_rows'] = [(object) (['member_id' => 10] + (array) $address)];
$denied = false;
try { AVPVH_Roles::require_address_household_admin(20, $address); } catch (Test_Exit $e) { $denied = $e->status === 403; }
check('officer cannot confirm an address matching an elevated account', $denied);
$GLOBALS['test_address_rows'] = [];
AVPVH_Roles::require_address_household_admin(20, $address);
check('ordinary address confirmations remain available to officers', true);
wp_set_current_user(6);
check('inactive member gets no stale File List groups', AVPVH_Roles::user_filelist_groups([], 6) === []);

// Administrative address requests use the real handlers and database layer.
wp_set_current_user(3);
$GLOBALS['test_groups']['voorz.test'] = ['leden', 'secretaris'];
AVPVH_Directory::forget_groups('voorz.test');
$GLOBALS['test_session_data'] = ['avpvh_auth_method' => 'proxy', 'avpvh_2fa' => true];
$admin = new AVPVH_Admin();
foreach (['handle_update_address', 'handle_confirm_address', 'handle_delete_address'] as $handler) {
    foreach ([20, 10] as $member_id) {
        $GLOBALS['test_db_writes'] = [];
        $GLOBALS['test_address_rows'] = [(object) (['id' => 101, 'member_id' => $member_id, 'member_entered' => 1] + (array) $address)];
        $_POST = ['id' => 101, 'member_id' => $member_id, 'valid_from' => '2026-01-01', 'valid_until' => '2027-01-01'];
        $result = null;
        try { $admin->$handler(); } catch (Test_Exit $e) { $result = $e; }
        check($handler . ': target permission is enforced before persistence', $member_id === 10
            ? $result?->status === 403 && !$GLOBALS['test_db_writes']
            : $result?->kind === 'redirect' && count($GLOBALS['test_db_writes']) === 1);
        if ($member_id === 20) {
            $write = $GLOBALS['test_db_writes'][0];
            $where = $write[$write[0] === 'update' ? 3 : 2];
            check($handler . ': writes bind both the address and member', $where === ['id' => 101, 'member_id' => 20]);
        }
    }
}
wp_set_current_user(1);
AVPVH_Roles::require_address_household_admin(20, $address);
check('WordPress administrator may administer household addresses', true);
wp_set_current_user(3);
$GLOBALS['test_address_unavailable'] = true;
$unavailable = false;
try { AVPVH_Roles::require_address_household_admin(20, $address); } catch (Test_Exit $e) { $unavailable = $e->status === 503; }
check('unavailable address checks stop before saving', $unavailable);
unset($GLOBALS['test_address_unavailable']);
$GLOBALS['test_relationship'] = (object) ['id' => 700, 'member_id' => 20, 'related_member_id' => 40];
check('relationship lookup returns the stored participants', AVPVH_DB::get_relationship(700) === $GLOBALS['test_relationship']);
check('relationship lookup reports missing rows', AVPVH_DB::get_relationship(701) === null);
foreach ([700 => 403, 701 => 400] as $id => $expected) {
    $_POST = ['member_id' => 20, 'relationship_id' => $id];
    $GLOBALS['test_db_writes'] = [];
    $result = null;
    try { $profile_form->handle_remove_relationship(); } catch (Test_Exit $e) { $result = $e; }
    check('relationship removal validates both stored participants: ' . $id, $result?->status === $expected && !$GLOBALS['test_db_writes']);
}
$guard = new AVPVH_Content_Guard([$access, 'post_is_restricted']);
$unchanged = new stdClass();
check('unrelated REST callbacks retain their response', $guard->guard_rest_comments($unchanged, [], new WP_REST_Request('/fixture/v1/items')) === $unchanged);
wp_logout();
$pagenow = 'admin-ajax.php';
$access->enforce_step_up_2fa_for_ajax();
check('anonymous AJAX keeps its handler-specific permissions', true);

echo $failures ? "\n$failures failure(s)\n" : "\nAccess hardening tests: OK\n";
exit($failures ? 1 : 0);
