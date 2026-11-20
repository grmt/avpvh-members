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
    public string $user_login = '';
    public string $user_email = '';
    public string $display_name = '';
    public function __construct(public int $ID = 0, array $roles = ['subscriber'], array $caps = []) {
        $this->roles = $roles;
        $this->caps = $caps;
    }
    public function set_role(string $role): void { $this->roles = [$role]; }
}
class WP_REST_Request {
    public function __construct(private string $route, private string $method = 'GET') {}
    public function get_route(): string { return $this->route; }
    public function get_method(): string { return $this->method; }
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
    public function get_results(mixed $q): array { return []; }
};

function __(string $t, string $d = ''): string { return $t; }
function esc_html__(string $t, string $d = ''): string { return $t; }
function esc_html(string $t): string { return htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); }
function esc_url(string $u): string { return $u; }
function add_action(string $h, mixed ...$a): void {}
function add_filter(string $h, mixed ...$a): void {}
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
function add_query_arg(string $k, string $v, string $u): string { return $u . (str_contains($u, '?') ? '&' : '?') . $k . '=' . $v; }
function is_wp_error(mixed $v): bool { return $v instanceof WP_Error; }
function is_admin(): bool { return $GLOBALS['test_admin']; }
function wp_doing_ajax(): bool { return false; }
function wp_doing_cron(): bool { return false; }
function is_user_logged_in(): bool { return $GLOBALS['test_logged_in']; }
function get_current_user_id(): int { return $GLOBALS['test_user_id']; }
function wp_get_session_token(): string { return 'tok-1'; }
function get_userdata(int $id): WP_User|false { return $GLOBALS['test_users'][$id] ?? false; }
function get_user_by(string $f, mixed $v): WP_User|false { return $f === 'id' ? ($GLOBALS['test_users'][(int) $v] ?? false) : false; }
function user_can(WP_User $u, string $cap): bool { return in_array($cap, $u->caps, true) || in_array('administrator', $u->roles, true); }
function current_user_can(string $cap): bool {
    $u = $GLOBALS['test_users'][$GLOBALS['test_user_id']] ?? null;
    return $u ? user_can($u, $cap) : false;
}
function get_option(string $name, mixed $default = false): mixed { return $GLOBALS['test_options'][$name] ?? $default; }
function get_post(int $id): ?WP_Post { return $GLOBALS['test_posts'][$id] ?? null; }
function get_posts(array $args): array {
    if (($args['name'] ?? '') === 'leden') { return [10]; }
    return [];
}
function get_pages(array $args): array {
    if (($args['child_of'] ?? 0) === 10) { return [(object) ['ID' => 11], (object) ['ID' => 15], (object) ['ID' => 1920]]; }
    return [];
}
function get_page_by_path(string $p): ?object { return null; }
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
        return $GLOBALS['test_groups'][$uid] ?? [];
    }
}
class AVPVH_Directory_Cache {
    public static int $refreshes = 0;
    public static function table(): string { return 'wp_avm_directory_users'; }
    public static function refresh_user(string $uid): void { self::$refreshes++; }
}

require_once dirname(__DIR__) . '/includes/class-avpvh-address-normalizer.php';
require_once dirname(__DIR__) . '/includes/class-avpvh-address-matcher.php';
require_once dirname(__DIR__) . '/includes/class-db.php';
require_once dirname(__DIR__) . '/includes/class-roles.php';
require_once dirname(__DIR__) . '/includes/class-nav-auth.php';
require_once dirname(__DIR__) . '/includes/class-access.php';
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
    public function get_results(mixed $q): array { return []; }
    public function get_row(mixed $q): ?object {
        if (is_array($q)) {
            [$sql, $args] = $q;
            if (str_contains($sql, 'WHERE m.id = %d')) {
                return $GLOBALS['test_members'][(int) $args[0]] ?? null;
            }
            if (str_contains($sql, 'WHERE m.wp_user_id = %d')) {
                foreach ($GLOBALS['test_members'] as $m) {
                    if ((int) ($m->wp_user_id ?? 0) === (int) $args[0]) { return $m; }
                }
            }
            if (str_contains($sql, 'WHERE m.lldap_user_id = %s')) {
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
check('anonymous oEmbed password-protected page returns 0', $access->hide_members_page_from_oembed(42) === 0);
$c_res = $access->exclude_restricted_comments_from_rest_query(['post_id' => 11]);
check('anonymous REST comment query for /leden/ page becomes [0]', ($c_res['post__in'] ?? null) === [0] && !isset($c_res['post_id']));

wp_set_current_user(2); // ordinary active member
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

$GLOBALS['test_session_data']['avpvh_2fa'] = true;
check('2FA session passes POST /wp/v2/posts', $access->enforce_step_up_2fa_for_rest(null, [], new WP_REST_Request('/wp/v2/posts', 'POST')) === null);

// --- Proxy header scoping & optional secret ---------------------------------
wp_logout();
$_SERVER['HTTP_REMOTE_USER'] = 'admin.test';
$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts';
$_SERVER['HTTP_X_AVPVH_PROXY_SECRET'] = AVPVH_PROXY_SECRET;
$access->auto_login_from_proxy_header();
check('proxy header ignored on /wp-json/', !is_user_logged_in());

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

// 1FA admin-only (not in member_group) -> redirected to Authelia for /wp-admin/ 2FA.
wp_logout();
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

echo $failures ? "\n$failures failure(s)\n" : "\nAccess hardening tests: OK\n";
exit($failures ? 1 : 0);
