<?php
declare(strict_types=1);
ob_start();

/**
 * Verifies the parent-domain WordPress identity hint cookie and safe
 * post-logout redirect flow used to lock Authelia 2FA step-up to the
 * active WordPress account.
 *
 * Run: php scripts/test-wp-identity-hint.php
 */

define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);
define('YEAR_IN_SECONDS', 31536000);
define('MINUTE_IN_SECONDS', 60);
define('COOKIEPATH', '/');
define('COOKIE_DOMAIN', '');

class Test_Redirect extends RuntimeException {
    public function __construct(public readonly string $url) {
        parent::__construct('Redirect: ' . $url);
    }
}

class Test_Die extends RuntimeException {
    public function __construct(public readonly string $html, public readonly int $status = 200) {
        parent::__construct('wp_die');
    }
}

$GLOBALS['test_logged_in'] = false;
$GLOBALS['test_current_user_id'] = 0;
$GLOBALS['test_ajax'] = false;
$GLOBALS['test_cron'] = false;
$GLOBALS['test_home'] = 'https://www.avphilipsvanhorne.nl';
$GLOBALS['test_members'] = [];
$GLOBALS['test_users'] = [];
$GLOBALS['test_logout_called'] = false;

function add_action(...$args): void {}
function add_filter(...$args): void {}
function is_ssl(): bool { return true; }
function is_user_logged_in(): bool { return $GLOBALS['test_logged_in']; }
function get_current_user_id(): int { return $GLOBALS['test_current_user_id']; }
function wp_doing_ajax(): bool { return $GLOBALS['test_ajax']; }
function wp_doing_cron(): bool { return $GLOBALS['test_cron']; }
function home_url(string $path = '/'): string { return $GLOBALS['test_home'] . $path; }
function wp_parse_url(string $url, int $component = -1): mixed { return parse_url($url, $component); }
function wp_unslash(mixed $value): mixed { return $value; }
function sanitize_text_field(string $value): string { return trim($value); }
function sanitize_email(string $value): string { return filter_var(trim($value), FILTER_VALIDATE_EMAIL) ? trim($value) : ''; }
function __(string $text, string $domain = ''): string { return $text; }
function esc_html__(string $text, string $domain = ''): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function esc_url(string $url): string { return htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); }
function wp_json_encode(mixed $value): string { return (string) json_encode($value, JSON_UNESCAPED_SLASHES); }
function wp_create_nonce(string $action): string { return 'valid-nonce-' . $action; }
function wp_verify_nonce(string $nonce, string $action): bool { return $nonce === 'valid-nonce-' . $action; }
function wp_logout(): void {
    $GLOBALS['test_logout_called'] = true;
    $GLOBALS['test_logged_in'] = false;
    AVPVH_Nav_Auth::clear_wp_identity_cookie();
}
function wp_safe_redirect(string $url): void { throw new Test_Redirect($url); }
function wp_die(string $html, string $title = '', array $args = []): void { throw new Test_Die($html, $args['response'] ?? 200); }
function add_query_arg(array|string $key, mixed $value = null, ?string $url = null): string {
    if (is_array($key)) {
        return $value . '?' . http_build_query($key);
    }
    return $url . '?' . http_build_query([$key => $value]);
}
function avpvh_get_member_by_wp_user(int $user_id): ?object { return $GLOBALS['test_members'][$user_id] ?? null; }
function get_user_by(string $field, int $id): ?object { return $GLOBALS['test_users'][$id] ?? null; }
function wp_remote_get(string $url, array $args = []): array { return ['code' => 401, 'body' => '']; }
function wp_remote_post(string $url, array $args = []): array { return ['code' => 200, 'body' => '']; }
function is_wp_error(mixed $value): bool { return false; }
function wp_remote_retrieve_response_code(array $response): int { return $response['code']; }

require_once dirname(__DIR__) . '/includes/class-nav-auth.php';

function check(string $label, bool $ok): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) {
        exit(1);
    }
}

// 1. Shared cookie domain calculation
check('www + auth share .avphilipsvanhorne.nl',
    AVPVH_Nav_Auth::shared_cookie_domain('www.avphilipsvanhorne.nl', 'auth.avphilipsvanhorne.nl') === '.avphilipsvanhorne.nl');
check('apex + auth share .avphilipsvanhorne.nl',
    AVPVH_Nav_Auth::shared_cookie_domain('avphilipsvanhorne.nl', 'auth.avphilipsvanhorne.nl') === '.avphilipsvanhorne.nl');
check('unrelated .nl domains never share bare TLD',
    AVPVH_Nav_Auth::shared_cookie_domain('example.nl', 'auth.avphilipsvanhorne.nl') === '');
check('localhost returns empty domain',
    AVPVH_Nav_Auth::shared_cookie_domain('localhost', 'auth.avphilipsvanhorne.nl') === '');
check('IP host returns empty domain',
    AVPVH_Nav_Auth::shared_cookie_domain('127.0.0.1', '127.0.0.1') === '');

// 2. Identity email resolution prefers member directory email
$GLOBALS['test_members'][5] = (object) ['email' => 'member.primary@example.invalid'];
$GLOBALS['test_users'][5]   = (object) ['user_email' => 'secondary.oauth@example.invalid'];
$GLOBALS['test_users'][6]   = (object) ['user_email' => 'wp.only@example.invalid'];

check('member primary email wins over WP user_email',
    AVPVH_Nav_Auth::identity_email_for_user(5) === 'member.primary@example.invalid');
check('falls back to WP user_email when no member record exists',
    AVPVH_Nav_Auth::identity_email_for_user(6) === 'wp.only@example.invalid');
check('invalid user id returns empty email',
    AVPVH_Nav_Auth::identity_email_for_user(0) === '');

// 3. Cookie lifecycle hooks
$nav = new AVPVH_Nav_Auth();
check('unknown user id returns empty email',
    AVPVH_Nav_Auth::identity_email_for_user(999) === '');
$_COOKIE = [];
$nav->on_logged_in_cookie('cookie', time() + 3600, time() + 3600, 5, 'logged_in', 'tok');
check('on_logged_in_cookie sets hint cookie',
    ($_COOKIE[AVPVH_Nav_Auth::WP_IDENTITY_COOKIE] ?? '') === 'member.primary@example.invalid');

$nav->clear_wp_identity_cookie();
check('clear_wp_identity_cookie removes hint cookie',
    !isset($_COOKIE[AVPVH_Nav_Auth::WP_IDENTITY_COOKIE]));

// sync sets missing cookie when logged in
$GLOBALS['test_logged_in'] = true;
$GLOBALS['test_current_user_id'] = 5;
$nav->sync_wp_identity_cookie();
check('sync_wp_identity_cookie sets cookie for logged-in user',
    ($_COOKIE[AVPVH_Nav_Auth::WP_IDENTITY_COOKIE] ?? '') === 'member.primary@example.invalid');

// sync skips during AJAX
$GLOBALS['test_logged_in'] = false;
$GLOBALS['test_ajax'] = true;
$nav->sync_wp_identity_cookie();
check('sync_wp_identity_cookie skips AJAX requests',
    ($_COOKIE[AVPVH_Nav_Auth::WP_IDENTITY_COOKIE] ?? '') === 'member.primary@example.invalid');

// sync clears stale cookie when logged out
$GLOBALS['test_ajax'] = false;
$nav->sync_wp_identity_cookie();
check('sync_wp_identity_cookie clears stale cookie when logged out',
    !isset($_COOKIE[AVPVH_Nav_Auth::WP_IDENTITY_COOKIE]));

// clearing when absent is a no-op; invalid email / no shared domain is a no-op
$nav->clear_wp_identity_cookie();
AVPVH_Nav_Auth::set_wp_identity_cookie('not-an-email', time() + 3600);
check('invalid email does not set cookie',
    !isset($_COOKIE[AVPVH_Nav_Auth::WP_IDENTITY_COOKIE]));

// on_logged_in_cookie and sync_wp_identity_cookie clear cookie when user has no email
$_COOKIE[AVPVH_Nav_Auth::WP_IDENTITY_COOKIE] = 'stale@example.invalid';
$nav->on_logged_in_cookie('cookie', 0, time() + 3600, 999, 'logged_in', 'tok');
check('on_logged_in_cookie clears cookie when user has no email',
    !isset($_COOKIE[AVPVH_Nav_Auth::WP_IDENTITY_COOKIE]));

$_COOKIE[AVPVH_Nav_Auth::WP_IDENTITY_COOKIE] = 'stale@example.invalid';
$GLOBALS['test_logged_in'] = true;
$GLOBALS['test_current_user_id'] = 999;
$nav->sync_wp_identity_cookie();
check('sync_wp_identity_cookie clears cookie when logged-in user has no email',
    !isset($_COOKIE[AVPVH_Nav_Auth::WP_IDENTITY_COOKIE]));
$GLOBALS['test_logged_in'] = false;

// 4. Safe post-logout redirect validation
check('empty redirect_to falls back to home',
    AVPVH_Nav_Auth::safe_logout_redirect('') === 'https://www.avphilipsvanhorne.nl/');
check('malformed redirect_to falls back to home',
    AVPVH_Nav_Auth::safe_logout_redirect('http://:invalid') === 'https://www.avphilipsvanhorne.nl/');
check('same-site redirect_to allowed',
    AVPVH_Nav_Auth::safe_logout_redirect('https://www.avphilipsvanhorne.nl/wp-admin/about.php')
        === 'https://www.avphilipsvanhorne.nl/wp-admin/about.php');
check('off-site redirect_to rejected',
    AVPVH_Nav_Auth::safe_logout_redirect('https://evil.example/phish') === 'https://www.avphilipsvanhorne.nl/');
check('javascript redirect_to rejected',
    AVPVH_Nav_Auth::safe_logout_redirect('javascript:alert(1)') === 'https://www.avphilipsvanhorne.nl/');

// 5. handle_logout with redirect_to
// 5a. Logged out with stale cookie -> clears cookie and redirects to target
$_COOKIE[AVPVH_Nav_Auth::WP_IDENTITY_COOKIE] = 'stale@example.invalid';
$_GET = [
    AVPVH_Nav_Auth::LOGOUT_ACTION => 'confirm',
    'redirect_to' => 'https://www.avphilipsvanhorne.nl/wp-admin/about.php',
];
$GLOBALS['test_logged_in'] = false;
try {
    $nav->handle_logout();
    check('logged-out logout redirected', false);
} catch (Test_Redirect $r) {
    check('logged-out logout clears stale cookie', !isset($_COOKIE[AVPVH_Nav_Auth::WP_IDENTITY_COOKIE]));
    check('logged-out logout redirects to safe target', $r->url === 'https://www.avphilipsvanhorne.nl/wp-admin/about.php');
}

// 5b. Logged in with confirm (invalid nonce) -> confirmation button preserves redirect_to
$GLOBALS['test_logged_in'] = true;
$GLOBALS['test_current_user_id'] = 5;
try {
    $nav->handle_logout();
    check('confirm logout prompted', false);
} catch (Test_Die $d) {
    check('confirm dialog preserves redirect_to in nonce link',
        str_contains($d->html, 'valid-nonce-avpvh_logout') && str_contains($d->html, rawurlencode('https://www.avphilipsvanhorne.nl/wp-admin/about.php')));
}

// 5c. Logged in with valid nonce -> logs out, clears cookie, redirects through Authelia logout to target
$_COOKIE[AVPVH_Nav_Auth::WP_IDENTITY_COOKIE] = 'member.primary@example.invalid';
$_GET[AVPVH_Nav_Auth::LOGOUT_ACTION] = 'valid-nonce-avpvh_logout';
try {
    $nav->handle_logout();
    check('confirmed logout redirected', false);
} catch (Test_Redirect $r) {
    check('confirmed logout called wp_logout', $GLOBALS['test_logout_called']);
    check('confirmed logout cleared hint cookie', !isset($_COOKIE[AVPVH_Nav_Auth::WP_IDENTITY_COOKIE]));
    check('confirmed logout redirects via Authelia logout to target',
        str_contains($r->url, 'https://auth.avphilipsvanhorne.nl/logout?')
        && str_contains($r->url, rawurlencode(rawurlencode('https://www.avphilipsvanhorne.nl/wp-admin/about.php'))));
}

// 6. safe_logout_redirect delegates to AVPVH_Access when loaded
if (!class_exists('AVPVH_Access', false)) {
    class AVPVH_Access {
        public static function safe_same_site_redirect(string $url): string {
            return 'https://www.avphilipsvanhorne.nl/delegated';
        }
    }
}
check('safe_logout_redirect delegates to AVPVH_Access when available',
    AVPVH_Nav_Auth::safe_logout_redirect('https://www.avphilipsvanhorne.nl/anything')
        === 'https://www.avphilipsvanhorne.nl/delegated');

echo "WP identity hint tests: OK\n";
