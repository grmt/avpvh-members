<?php
declare(strict_types=1);

/**
 * Checks for the Rechtspreker tenant layer (includes/tenant/): config
 * validation, the admin hierarchy, Authelia identity parsing, local
 * provisioning, role revocation and the members-only pages. All accounts
 * and groups are fictitious.
 *
 * Run:
 *   php scripts/test-tenant-sso.php
 */

define('ABSPATH', __DIR__ . '/');

$failures = 0;
function check(string $label, bool $ok): void {
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
}

// --- WordPress stubs -------------------------------------------------------

class WP_Error {
    public function __construct(private string $code = '', private string $message = '', private mixed $data = null) {}
    public function get_error_code(): string { return $this->code; }
}
class WP_REST_Response {}
class WP_REST_Request {
    public function __construct(private string $route = '') {}
    public function get_route(): string { return $this->route; }
}
class WP_Post { public int $ID = 0; }
class WP_User {
    public array $roles = [];
    public string $user_login = '';
    public string $user_email = '';
    public string $display_name = '';
    public function __construct(public int $ID = 0) {}
    public function set_role(string $role): void { $this->roles = [$role]; }
}
class WP_Query {}
class AVPVH_Test_Exit extends RuntimeException {
    public function __construct(public string $kind, public string $detail = '', public int $status = 0) {
        parent::__construct($kind);
    }
}
class AVPVH_I18n {}

$test_users = [];      // id => WP_User
$test_meta = [];       // id => [key => value]
$test_options = [];
$test_current = 0;
$test_auth_cookie = 0;
$test_remote = null;   // next response of wp_remote_get()
$test_remote_calls = 0;
// error_log() output goes to a temporary file that is checked at the end.
$test_log_file = tempnam(sys_get_temp_dir(), 'avpvh-tenant-log');
ini_set('error_log', $test_log_file);

function __(string $text, string $domain = ''): string { return $text; }
function esc_html__(string $text, string $domain = ''): string { return $text; }
function esc_html(string $text): string { return htmlspecialchars($text); }
function esc_attr(string $text): string { return htmlspecialchars($text); }
function esc_url(string $url): string { return $url; }
function add_action(string $hook, mixed ...$args): void {
    // Hooks are called directly in this test.
}
function add_filter(string $hook, mixed ...$args): void {
    // Filters are called directly in this test.
}
function wp_parse_url(string $url, int $component = -1): mixed { return parse_url($url, $component); }
function home_url(string $path = '/'): string { return 'https://site.example.invalid/' . ltrim($path, '/'); }
function sanitize_text_field(string $value): string { return trim($value); }
function sanitize_key(string $value): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)); }
function sanitize_email(string $value): string { return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : ''; }
function sanitize_user(string $value, bool $strict = false): string { return preg_replace('/[^a-z0-9._@-]/', '', strtolower($value)); }
function wp_unslash(mixed $value): mixed { return $value; }
function untrailingslashit(string $value): string { return rtrim($value, '/'); }
function add_query_arg(string $key, string $value, string $url): string { return $url . (str_contains($url, '?') ? '&' : '?') . $key . '=' . $value; }
function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function wp_remote_get(string $url, array $args): mixed {
    $GLOBALS['test_remote_calls']++;
    $GLOBALS['test_last_request'] = $args;
    return $GLOBALS['test_remote'];
}
function wp_remote_retrieve_response_code(array $response): int { return $response['code']; }
function wp_remote_retrieve_header(array $response, string $name): string { return $response['headers'][$name] ?? ''; }
function wp_validate_redirect(string $url, string $fallback): string {
    $host = parse_url($url, PHP_URL_HOST);
    return ($host === null || $host === 'site.example.invalid' || $host === 'auth.example.invalid') ? $url : $fallback;
}
function wp_safe_redirect(string $url): void { throw new AVPVH_Test_Exit('redirect', $url); }
function wp_die(string $message, string $title = '', array $args = []): void { throw new AVPVH_Test_Exit('die', $message, $args['response'] ?? 0); }
function is_user_logged_in(): bool { return $GLOBALS['test_current'] > 0; }
function get_current_user_id(): int { return $GLOBALS['test_current']; }
function wp_get_current_user(): WP_User { return $GLOBALS['test_users'][$GLOBALS['test_current']] ?? new WP_User(); }
function wp_set_current_user(int $id): void { $GLOBALS['test_current'] = $id; }
function wp_set_auth_cookie(int $id): void { $GLOBALS['test_auth_cookie'] = $id; }
function wp_logout(): void { $GLOBALS['test_current'] = 0; $GLOBALS['test_auth_cookie'] = 0; }
function do_action(string $hook, mixed ...$args): void {
    // Other login listeners are not part of this test.
}
function get_user_by(string $field, int $id): WP_User|false { return $GLOBALS['test_users'][$id] ?? false; }
function get_user_meta(int $id, string $key, bool $single = false): mixed { return $GLOBALS['test_meta'][$id][$key] ?? ''; }
function update_user_meta(int $id, string $key, mixed $value): bool { $GLOBALS['test_meta'][$id][$key] = $value; return true; }
function get_users(array $args): array {
    $ids = [];
    foreach ($GLOBALS['test_meta'] as $id => $meta) {
        if (($meta[$args['meta_key']] ?? null) === $args['meta_value']) {
            $ids[] = $id;
        }
    }
    return array_slice($ids, 0, $args['number']);
}
function username_exists(string $login): int|false {
    foreach ($GLOBALS['test_users'] as $user) {
        if ($user->user_login === $login) {
            return $user->ID;
        }
    }
    return false;
}
function email_exists(string $email): int|false {
    foreach ($GLOBALS['test_users'] as $user) {
        if ($user->user_email === $email) {
            return $user->ID;
        }
    }
    return false;
}
function wp_generate_password(int $length, bool $special = true, bool $extra = false): string { return str_repeat('x', $length); }
function wp_insert_user(array $data): int {
    $id = count($GLOBALS['test_users']) + 100;
    $user = new WP_User($id);
    $user->user_login = $data['user_login'];
    $user->user_email = $data['user_email'];
    $user->display_name = $data['display_name'];
    $user->roles = [$data['role']];
    $GLOBALS['test_users'][$id] = $user;
    return $id;
}
function wp_update_user(array $data): int {
    $user = $GLOBALS['test_users'][$data['ID']];
    foreach (['display_name', 'user_email'] as $field) {
        if (isset($data[$field])) {
            $user->$field = $data[$field];
        }
    }
    return $data['ID'];
}
function add_option(string $name, mixed $value, string $deprecated = '', mixed $autoload = null): bool {
    if (array_key_exists($name, $GLOBALS['test_options'])) {
        return false;
    }
    $GLOBALS['test_options'][$name] = $value;
    return true;
}
function get_option(string $name, mixed $default = false): mixed { return $GLOBALS['test_options'][$name] ?? $default; }
function update_option(string $name, mixed $value, mixed $autoload = null): bool { $GLOBALS['test_options'][$name] = $value; return true; }
function delete_option(string $name): bool { unset($GLOBALS['test_options'][$name]); return true; }
function wp_create_nonce(string $action): string { return 'nonce-' . $action; }
function wp_verify_nonce(string $nonce, string $action): bool { return $nonce === 'nonce-' . $action; }
function wp_logout_url(): string { return 'https://site.example.invalid/?avpvh_tenant_logout=nonce-avpvh_tenant_logout'; }
function current_user_can(string $cap): bool {
    $user = $GLOBALS['test_users'][$GLOBALS['test_current']] ?? null;
    return $user && in_array('administrator', $user->roles, true);
}

require_once dirname(__DIR__) . '/includes/tenant/class-tenant-config.php';
require_once dirname(__DIR__) . '/includes/tenant/class-tenant-identity.php';
require_once dirname(__DIR__) . '/includes/tenant/class-tenant-sso.php';
require_once dirname(__DIR__) . '/includes/tenant/class-tenant-content.php';

// --- Configuration ---------------------------------------------------------

function tenant_config(array $override = []): AVPVH_Tenant_Config {
    return AVPVH_Tenant_Config::from_array($override + [
        'tenant_id'               => 'rsp_vve_vp4042',
        'ancestors'               => ['rsp', 'rsp_vve'],
        'canonical_host'          => 'site.example.invalid',
        'authelia_url'            => 'https://auth.example.invalid',
        'authelia_authz_url'      => 'https://authelia.example.invalid/api/authz/auth-request',
        'authelia_session_cookie' => 'test_session',
        'member_group'            => 'vp4042-leden',
        'admin_group'             => 'rsp-vve-vp4042-admins',
        'ancestor_admin_groups'   => ['rsp-admins', 'rsp-vve-admins'],
        'members_only_pages'      => ['leden'],
        'menu_locations'          => ['primary'],
    ], 'site.example.invalid');
}

$vp = tenant_config();
check('valid VP40-42 config', $vp->is_valid());
check('admin groups include both ancestors', $vp->admin_groups() === ['rsp-admins', 'rsp-vve-admins', 'rsp-vve-vp4042-admins']);
check('content groups default to the member group', $vp->content_groups() === ['vp4042-leden']);
check('ancestor chain must prefix the tenant ID', in_array('ancestors', tenant_config(['ancestors' => ['rsp', 'other']])->errors(), true));
check('canonical host must match the site', in_array('canonical_host', tenant_config(['canonical_host' => 'other.example.invalid'])->errors(), true));
check('Authelia portal must be https', in_array('authelia_url', tenant_config(['authelia_url' => 'http://auth.example.invalid'])->errors(), true));
check('member group may not be an admin group', in_array('groups', tenant_config(['member_group' => 'rsp-admins'])->errors(), true));
check('one admin group per ancestor', in_array('ancestor_admin_groups', tenant_config(['ancestor_admin_groups' => ['rsp-admins']])->errors(), true));
check('unknown keys are ignored', tenant_config(['local_login' => false, 'evil' => 1])->get('evil') === null);

// --- Authorisation matrix --------------------------------------------------

$rsp = tenant_config([
    'tenant_id' => 'rsp', 'ancestors' => [], 'admin_group' => 'rsp-admins',
    'ancestor_admin_groups' => [], 'member_group' => 'rsp-leden',
]);
$vve = tenant_config([
    'tenant_id' => 'rsp_vve', 'ancestors' => ['rsp'], 'admin_group' => 'rsp-vve-admins',
    'ancestor_admin_groups' => ['rsp-admins'], 'member_group' => 'vve-leden',
]);
check('RSP and VvE configs are valid', $rsp->is_valid() && $vve->is_valid());
$matrix = [
    // groups, [RSP, VvE, VP40-42]
    [['rsp-admins'],            ['administrator', 'administrator', 'administrator']],
    [['rsp-vve-admins'],        [null, 'administrator', 'administrator']],
    [['rsp-vve-vp4042-admins'], [null, null, 'administrator']],
    [['vp4042-leden'],          [null, null, 'subscriber']],
    [['boek', 'leden'],         [null, null, null]],
    [[],                        [null, null, null]],
];
foreach ($matrix as [$groups, $expected]) {
    $actual = array_map(static fn($config) => AVPVH_Tenant_SSO::role_for_groups($groups, $config), [$rsp, $vve, $vp]);
    check('matrix ' . (implode('+', $groups) ?: 'no groups'), $actual === $expected);
}

// --- Identity from Authelia ------------------------------------------------

function authz(int $code, array $headers = []): array {
    return ['code' => $code, 'headers' => $headers];
}
$ok = AVPVH_Tenant_Identity::interpret(authz(200, [
    'remote-user' => 'Test.Beheerder', 'remote-groups' => 'vp4042-leden, RSP-ADMINS',
    'remote-email' => 'beheerder@example.invalid', 'remote-name' => 'Test Beheerder',
]));
check('200 gives the user and lowercased groups', $ok['status'] === 'ok' && $ok['uid'] === 'test.beheerder' && $ok['groups'] === ['vp4042-leden', 'rsp-admins']);
check('401 is anonymous', AVPVH_Tenant_Identity::interpret(authz(401))['status'] === 'anonymous');
check('200 without a user is an error, not a login', AVPVH_Tenant_Identity::interpret(authz(200))['status'] === 'error');
check('a transport error is an error', AVPVH_Tenant_Identity::interpret(new WP_Error('http_request_failed'))['status'] === 'error');
check('a 500 is an error', AVPVH_Tenant_Identity::interpret(authz(500))['status'] === 'error');

$_SERVER['HTTP_REMOTE_USER'] = 'test.beheerder';
$_SERVER['HTTP_REMOTE_GROUPS'] = 'rsp-admins';
$_COOKIE = [];
$test_remote_calls = 0;
check('spoofed Remote-* headers without a session are ignored', AVPVH_Tenant_Identity::probe($vp)['status'] === 'anonymous' && $test_remote_calls === 0);

$_COOKIE['test_session'] = 'session-value';
$test_remote = authz(401);
AVPVH_Tenant_Identity::probe($vp);
$sent = $test_last_request['headers'];
check('probe replays only the session cookie for the probe URL', $sent['Cookie'] === 'test_session=session-value' && $sent['X-Original-URL'] === 'https://site.example.invalid/avpvh-sso/');

// --- SSO callback and provisioning -----------------------------------------

$sso = new AVPVH_Tenant_SSO($vp);
function callback(AVPVH_Tenant_SSO $sso, string $redirect_to = ''): AVPVH_Test_Exit {
    $_SERVER['REQUEST_URI'] = '/avpvh-sso/' . ($redirect_to !== '' ? '?redirect_to=' . rawurlencode($redirect_to) : '');
    $_GET = $redirect_to !== '' ? ['redirect_to' => $redirect_to] : [];
    try {
        $sso->handle_sso_callback();
    } catch (AVPVH_Test_Exit $exit) {
        return $exit;
    }
    return new AVPVH_Test_Exit('none');
}
function member_response(array $groups, string $uid = 'test.lid', string $email = 'lid@example.invalid'): array {
    return authz(200, ['remote-user' => $uid, 'remote-groups' => implode(',', $groups), 'remote-email' => $email, 'remote-name' => 'Test Lid']);
}

$test_remote = member_response(['vp4042-leden']);
$exit = callback($sso, 'https://site.example.invalid/leden/');
$member_id = $test_current;
check('member logs in and returns to the page', $exit->kind === 'redirect' && $exit->detail === 'https://site.example.invalid/leden/' && $test_auth_cookie === $member_id && $member_id > 0);
check('member is provisioned as subscriber', $test_users[$member_id]->roles === ['subscriber']);
check('user is linked to the tenant-bound subject', $test_meta[$member_id]['avpvh_directory_subject'] === 'rsp_vve_vp4042/test.lid');

$exit = callback($sso, 'https://evil.example.invalid/');
check('second login reuses the user', $test_current === $member_id && count($test_users) === 1);
check('external redirect is refused', $exit->detail === 'https://site.example.invalid/');

wp_logout();
$test_remote = member_response(['boek']);
$exit = callback($sso);
check('identity without tenant access gets 403 and no session', $exit->kind === 'die' && $exit->status === 403 && $test_current === 0);

$test_remote = authz(503);
$exit = callback($sso);
check('Authelia failure gives no session', $exit->kind === 'die' && $test_current === 0);

// A local account with the same login is never taken over.
$local = new WP_User(50);
$local->user_login = 'test.lokaal';
$local->roles = ['administrator'];
$test_users[50] = $local;
$test_remote = member_response(['rsp-admins'], 'test.lokaal', 'lokaal@example.invalid');
$exit = callback($sso);
check('existing local login is not taken over', $exit->kind === 'die' && $exit->status === 409 && $test_current === 0);

// Admin through an ancestor group, then revoked.
$test_remote = member_response(['rsp-vve-admins'], 'test.vve', 'vve@example.invalid');
callback($sso);
$admin_id = $test_current;
check('VvE admin is administrator on VP40-42', $test_users[$admin_id]->roles === ['administrator']);

$test_meta[$admin_id]['avpvh_directory_checked'] = time() - 3600;
$test_remote = member_response(['vp4042-leden'], 'test.vve', 'vve@example.invalid');
$_SERVER['REQUEST_URI'] = '/';
$sso->recheck_session();
check('removed admin group is revoked to subscriber', $test_current === $admin_id && $test_users[$admin_id]->roles === ['subscriber']);

$test_meta[$admin_id]['avpvh_directory_checked'] = time() - 3600;
$test_remote = member_response([], 'test.vve', 'vve@example.invalid');
$sso->recheck_session();
check('losing all tenant groups ends the session', $test_current === 0);

// A role changed by hand is a deliberate exception and stays.
$test_users[$member_id]->roles = ['editor'];
$test_remote = member_response(['vp4042-leden']);
callback($sso);
check('manually changed role is left alone', $test_users[$member_id]->roles === ['editor']);
$test_users[$member_id]->roles = ['subscriber'];

// Recheck: a different Authelia user, a logged-out Authelia session, and
// an unreachable Authelia all end the WordPress session.
foreach ([
    'another Authelia user' => member_response(['vp4042-leden'], 'test.ander'),
    'ended Authelia session' => authz(401),
    'unreachable Authelia' => new WP_Error('http_request_failed'),
] as $label => $response) {
    $_SERVER['REQUEST_URI'] = '/';
    $test_current = $member_id;
    $test_meta[$member_id]['avpvh_directory_checked'] = time() - 3600;
    $test_remote = $response;
    $sso->recheck_session();
    check("recheck: $label ends the session", $test_current === 0);
}

$test_current = $member_id;
$test_meta[$member_id]['avpvh_directory_checked'] = time();
$test_remote_calls = 0;
$sso->recheck_session();
check('no Authelia call within the recheck interval', $test_remote_calls === 0 && $test_current === $member_id);

$test_current = 50;
$test_remote_calls = 0;
$sso->recheck_session();
check('local emergency account is not rechecked', $test_remote_calls === 0 && $test_current === 50);

// Concurrent first login: a held lock refuses instead of creating twice.
$test_current = 0;
$test_options['avpvh_tenant_lock_' . substr(hash('sha256', 'rsp_vve_vp4042/test.nieuw'), 0, 32)] = time();
$test_remote = member_response(['vp4042-leden'], 'test.nieuw', 'nieuw@example.invalid');
$users_before = count($test_users);
$exit = callback($sso);
check('held provisioning lock creates no user', $exit->kind === 'die' && count($test_users) === $users_before);

// --- Login and logout URLs -------------------------------------------------

$login = $sso->login_url('https://site.example.invalid/leden/');
check('login goes to Authelia with the probe path as target', str_starts_with($login, 'https://auth.example.invalid/?rd=') && str_contains(rawurldecode($login), 'https://site.example.invalid/avpvh-sso/?redirect_to='));
$_GET = ['avpvh_tenant_logout' => 'nonce-avpvh_tenant_logout'];
$test_current = $member_id;
try {
    $sso->handle_logout();
    $exit = new AVPVH_Test_Exit('none');
} catch (AVPVH_Test_Exit $exit) {
}
check('logout ends the session and the Authelia session', $test_current === 0 && str_starts_with($exit->detail, 'https://auth.example.invalid/logout?rd='));
$_GET = [];

// --- Members-only pages ----------------------------------------------------

$content = (new ReflectionClass(AVPVH_Tenant_Content::class))->newInstanceWithoutConstructor();
foreach (['config' => $vp, 'sso' => $sso] as $property => $value) {
    $reflection = new ReflectionProperty(AVPVH_Tenant_Content::class, $property);
    $reflection->setValue($content, $value);
}
// protected_ids() caches in a static; prime it through a subclass-free path.
$ids_property = (new ReflectionMethod(AVPVH_Tenant_Content::class, 'protected_ids'));
function get_posts(array $args): array { return $args['name'] === 'leden' ? [10] : []; }
function sanitize_title(string $title): string { return $title; }
function get_pages(array $args): array {
    $child = new WP_Post();
    $child->ID = 11;
    return $args['child_of'] === 10 ? [$child] : [];
}
function wp_list_pluck(array $list, string $field): array { return array_map(static fn($item) => $item->$field, $list); }
check('protected pages are the root and its descendants', $content->protected_ids() === [10, 11]);

function menu_item(int $id, int $object_id, int $parent = 0): object {
    return (object) ['ID' => $id, 'object' => 'page', 'object_id' => $object_id, 'menu_item_parent' => $parent];
}
$menu = [menu_item(1, 5), menu_item(2, 10), menu_item(3, 99, 2), menu_item(4, 11)];
$test_current = 0;
check('menu hides protected pages and their submenu for visitors', array_column($content->filter_menu_objects($menu), 'ID') === [1]);
$test_current = $member_id;
$test_meta[$member_id]['avpvh_directory_groups'] = ['vp4042-leden'];
check('menu shows everything to members', count($content->filter_menu_objects($menu)) === 4);

$test_meta[$admin_id]['avpvh_directory_groups'] = ['rsp-vve-admins'];
$test_current = $admin_id;
check('admin group alone does not open members-only pages', !$content->current_user_may_read());

$page = new WP_Post();
$page->ID = 11;
$test_current = 0;
check('REST hides a protected page from visitors', $content->hide_from_rest_item(null, [], new WP_REST_Request('/wp/v2/pages/11')) instanceof WP_Error);
check('REST hides revisions of a protected page', $content->hide_from_rest_item(null, [], new WP_REST_Request('/wp/v2/pages/10/revisions')) instanceof WP_Error);
check('REST leaves public pages alone', $content->hide_from_rest_item(null, [], new WP_REST_Request('/wp/v2/pages/12')) === null);
check('REST listing excludes protected pages for visitors', $content->exclude_from_rest_query([])['post__not_in'] === [10, 11]);

$items = $content->add_account_item('', (object) ['theme_location' => 'primary']);
check('menu offers Inloggen to visitors', str_contains($items, 'Inloggen') && str_contains($items, 'auth.example.invalid'));
$test_current = $member_id;
$items = $content->add_account_item('', (object) ['theme_location' => 'primary']);
check('menu offers Uitloggen when logged in', str_contains($items, 'Uitloggen'));
check('other menus are left alone', $content->add_account_item('<li>x</li>', (object) ['theme_location' => 'footer']) === '<li>x</li>');

$test_logs = file($test_log_file, FILE_IGNORE_NEW_LINES) ?: [];
unlink($test_log_file);
check('decisions are logged', count(preg_grep('/\[avpvh-tenant\] tenant=rsp_vve_vp4042 /', $test_logs)) > 0);
foreach ($test_logs as $line) {
    if (preg_match('/test\.|example\.invalid/', $line)) {
        check('logs contain no user names or addresses', false);
        break;
    }
}

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
exit($failures ? 1 : 0);
