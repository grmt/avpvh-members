<?php
declare(strict_types=1);
ob_start();

/** Complete callback flows against fictional provider, directory and session boundaries. */
define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
ini_set('error_log', '/dev/null');
class WP_Error {
    public function __construct(public string $code) {}
    public function get_error_code(): string { return $this->code; }
}
class Callback_Exit extends Error {
    public function __construct(public string $url, public int $status = 302) {}
}
class WP_REST_Request {
    public function __construct(private array $params) {}
    public function get_param(string $key): mixed { return $this->params[$key] ?? null; }
}
class WP_User {
    public array $roles = ['subscriber'];
    public array $allcaps = ['read' => true, 'subscriber' => true];
    public string $user_login = 'fixture.member';
    public string $user_email = 'member@example.invalid';
    public string $display_name = 'Fictieve Gebruiker';
    public function __construct(public int $ID = 42) {}
}
function add_action(mixed ...$args): void {}
function add_filter(mixed ...$args): void {}
function apply_filters(string $hook, mixed $value): mixed { return $value; }
function __(string $text, string $domain = ''): string { return $text; }
function esc_html(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function sanitize_text_field(string $text): string { return trim($text); }
function sanitize_email(string $text): string { return filter_var($text, FILTER_VALIDATE_EMAIL) ? $text : ''; }
function sanitize_key(string $text): string { return preg_replace('/[^a-z0-9_-]/', '', strtolower($text)); }
function wp_unslash(mixed $value): mixed { return $value; }
function is_ssl(): bool { return true; }
function is_email(string $email): bool { return (bool) filter_var($email, FILTER_VALIDATE_EMAIL); }
function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function home_url(string $path = '/'): string { return 'https://fixture.example.invalid' . $path; }
function rest_url(string $path): string { return home_url('/wp-json/' . $path); }
function wp_parse_url(string $url, int $component = -1): mixed { return parse_url($url, $component); }
function wp_validate_redirect(string $url, string $fallback): string { return $url; }
function untrailingslashit(string $url): string { return rtrim($url, '/'); }
function add_query_arg(array|string $key, mixed $value, ?string $url = null): string {
    return is_array($key) ? $value . '?' . http_build_query($key) : $url . '?' . http_build_query([$key => $value]);
}
function get_option(string $name, mixed $default = false): mixed { return str_ends_with($name, '_client_id') ? 'fixture-client' : 'fixture-secret'; }
function get_transient(string $key): mixed { return $GLOBALS['transients'][$key] ?? false; }
function set_transient(string $key, mixed $value, int $ttl): void { $GLOBALS['transients'][$key] = $value; }
function delete_transient(string $key): void { unset($GLOBALS['transients'][$key]); }
function get_current_user_id(): int { return $GLOBALS['current_user']; }
function is_user_logged_in(): bool { return get_current_user_id() > 0; }
function wp_set_current_user(int $id): void { $GLOBALS['current_user'] = $id; }
function wp_logout(): void { $GLOBALS['current_user'] = 0; $GLOBALS['events'][] = 'logout'; }
function wp_clear_auth_cookie(): void { $GLOBALS['events'][] = 'clear'; }
function wp_parse_auth_cookie(string $cookie, string $scheme): array { return ['token' => 'prior-browser-token']; }
function wp_validate_auth_cookie(string $cookie, string $scheme): int|false { return $GLOBALS['cookie_valid'] ? 7 : false; }
class WP_Session_Tokens {
    public function __construct(private int $id) {}
    public static function get_instance(int $id): self { return new self($id); }
    public function destroy(string $token): void { $GLOBALS['events'][] = ['retire', $this->id, $token]; }
}
function wp_set_auth_cookie(int $id, bool $remember = false): void {
    $access = (new ReflectionClass(AVPVH_Access::class))->newInstanceWithoutConstructor();
    $session = $GLOBALS['mode'] === 'main' ? $access->attach_session_auth_metadata([]) : $GLOBALS['sso']->attach_login_method([]);
    $GLOBALS['events'][] = ['login', $id, $session];
}
function do_action(string $hook, mixed ...$args): void { $GLOBALS['events'][] = $hook; }
function wp_safe_redirect(string $url): void { throw new Callback_Exit($url); }
function wp_die(string $message, string $title = '', array $args = []): void { throw new Callback_Exit($message, $args['response'] ?? 500); }
function wp_remote_post(string $url, array $args): array {
    $GLOBALS['exchange'] = $args['body'];
    $claims = ['iss' => 'https://accounts.google.com', 'aud' => 'fixture-client', 'exp' => time() + 300,
        'nonce' => 'fixture-nonce', 'email' => 'member@example.invalid', 'email_verified' => true];
    return ['code' => 200, 'body' => json_encode(['id_token' => 'h.' . AVPVH_OIDC::base64url(json_encode($claims)) . '.s'])];
}
function wp_remote_retrieve_response_code(array $response): int { return $response['code']; }
function wp_remote_retrieve_body(array $response): string { return $response['body']; }
function get_user_by(string $field, int $id): WP_User { return $GLOBALS['user']; }
function get_user_meta(int $id, string $key, bool $single): mixed { return $GLOBALS['meta'][$key] ?? ''; }
function update_user_meta(int $id, string $key, mixed $value): void { $GLOBALS['meta'][$key] = $value; }
function get_users(array $args): array { return $GLOBALS['linked_users']; }
class AVPVH_DB {
    public static function get_member_identity(string $provider, string $email): object { return (object) ['member_id' => 42, 'provider' => $provider, 'verified_at' => '2026-01-01']; }
    public static function get_member(int $id): object { return (object) ['id' => $id]; }
    public static function get_or_create_wp_user(string $email, object $member): WP_User { return $GLOBALS['user']; }
    public static function log_attempt(string ...$args): void { $GLOBALS['attempts'][] = $args; }
}
class AVPVH_Nav_Auth {
    public static function invalidate_authelia_session(): void { $GLOBALS['events'][] = 'authelia'; }
    public static function authelia_logout_url(string $target): string { return 'https://auth.example.invalid/logout?rd=' . rawurlencode($target); }
}
class AVPVH_Tenant_Config {
    public function tenant_id(): string { return 'fixture'; }
    public function member_group(): string { return 'fixture-members'; }
    public function admin_groups(): array { return ['fixture-admins']; }
    public function oauth_clients(): array { return ['google' => ['client_id' => 'fixture-client', 'client_secret' => 'fixture-secret', 'client_secret_file' => '']]; }
    public function get(string $key): mixed { return ['canonical_host' => 'fixture.example.invalid', 'authelia_url' => 'https://auth.example.invalid', 'authelia_session_cookie' => 'fixture_authelia'][$key] ?? null; }
}
class AVPVH_Tenant_Directory {
    const OK = 'ok';
    const ERROR = 'error';
    public static function for_config(AVPVH_Tenant_Config $config): self { return new self(); }
    public function find_by_email(string $email): array { return ['status' => self::OK, 'uid' => 'fixture.member', 'groups' => ['fixture-members'], 'name' => 'Fictieve Gebruiker', 'email' => $email]; }
}
require_once dirname(__DIR__) . '/includes/class-avpvh-oidc.php';
require_once dirname(__DIR__) . '/includes/class-access.php';
require_once dirname(__DIR__) . '/includes/class-oauth.php';
require_once dirname(__DIR__) . '/includes/tenant/class-tenant-identity.php';
require_once dirname(__DIR__) . '/includes/tenant/class-tenant-sso.php';
require_once dirname(__DIR__) . '/includes/tenant/class-tenant-oauth.php';

function check(string $label, bool $ok): void { echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL; if (!$ok) { exit(1); } }
function callback_case(string $mode, bool $authelia = false, bool $elevated = false, bool $duplicate = false): Callback_Exit {
    $GLOBALS['mode'] = $mode;
    $GLOBALS['current_user'] = 0;
    $GLOBALS['cookie_valid'] = true;
    $GLOBALS['events'] = $GLOBALS['attempts'] = [];
    $GLOBALS['user'] = new WP_User();
    if ($elevated) { $GLOBALS['user']->allcaps['fixture_admin_capability'] = true; }
    $GLOBALS['meta'] = ['avpvh_directory_subject' => 'fixture/fixture.member'];
    $GLOBALS['linked_users'] = $duplicate ? [42, 43] : [42];
    $_COOKIE = [AVPVH_OIDC::state_cookie_name() => 'fixture-state'];
    if ($authelia) { $_COOKIE['fixture_authelia'] = 'fixture-cookie'; }
    $stored = ['provider' => 'google', 'verifier' => 'fixture-verifier', 'nonce' => 'fixture-nonce', 'redirect_to' => home_url('/members/')];
    $prefix = $mode === 'main' ? 'avpvh_oauth_state_' : 'avpvh_toauth_';
    $GLOBALS['transients'] = [AVPVH_OIDC::state_key($prefix, 'fixture-state') => $mode === 'main' ? json_encode($stored) : $stored];
    $request = new WP_REST_Request(['state' => 'fixture-state', 'code' => 'fixture-code']);
    $config = new AVPVH_Tenant_Config();
    $GLOBALS['sso'] = new AVPVH_Tenant_SSO($config);
    try {
        if ($mode === 'main') { (new AVPVH_OAuth())->handle_callback('google', $request); }
        else { (new AVPVH_Tenant_OAuth($config, $GLOBALS['sso']))->callback('google', $request); }
    } catch (Callback_Exit $exit) { return $exit; }
    throw new RuntimeException('Callback did not redirect.');
}
$exit = callback_case('main');
check('main callback retires the incoming browser session', in_array(['retire', 7, 'prior-browser-token'], $GLOBALS['events'], true) && get_current_user_id() === 42);
$login = array_values(array_filter($GLOBALS['events'], static fn($e): bool => is_array($e) && $e[0] === 'login'))[0];
check('main callback starts a fresh session with its own metadata', $login[2] === ['avpvh_auth_method' => 'oauth', 'avpvh_2fa' => false]);
check('provider exchange includes the stored verifier', $GLOBALS['exchange']['code_verifier'] === 'fixture-verifier');
check('main callback returns through the configured identity service', str_starts_with($exit->url, 'https://auth.example.invalid/logout?rd=') && !$GLOBALS['transients']);
foreach ([false, true] as $authelia) {
    $exit = callback_case('tenant', $authelia);
    check('tenant callback starts the linked reading session', get_current_user_id() === 42 && $GLOBALS['meta']['avpvh_directory_groups'] === ['fixture-members']);
    check('tenant callback retires its prior cookie session', in_array(['retire', 7, 'prior-browser-token'], $GLOBALS['events'], true));
    check('tenant callback chooses the appropriate return path', $authelia ? str_starts_with($exit->url, 'https://auth.example.invalid/logout?rd=') : $exit->url === home_url('/members/'));
}
foreach (['account' => [true, false, 'admin'], 'link' => [false, true, 'link']] as [$elevated, $duplicate, $reason]) {
    $exit = callback_case('tenant', false, $elevated, $duplicate);
    parse_str((string) parse_url($exit->url, PHP_URL_QUERY), $query);
    check('unavailable ' . $reason . ' flow selects the matching notice', ($query['fout'] ?? '') === $reason);
    check('unsuccessful callback preserves existing account metadata', $GLOBALS['events'] === [] && $GLOBALS['meta'] === ['avpvh_directory_subject' => 'fixture/fixture.member']);
}
