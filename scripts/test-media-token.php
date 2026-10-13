<?php
declare(strict_types=1);
ob_start(); // Cookie headers must remain possible while reporting CLI checks.

/**
 * Ad-hoc compatibility checks for the private-media JWT.
 *
 * Run: php scripts/test-media-token.php
 */

define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);
define('YEAR_IN_SECONDS', 31536000);
define('AVPVH_JWT_SECRET', '0123456789abcdef0123456789abcdef');

class WP_User {}

$GLOBALS['test_logged_in'] = false;
$GLOBALS['test_logout_count'] = 0;
$GLOBALS['test_member_status'] = 'active';
$GLOBALS['test_admin'] = false;
$GLOBALS['test_scripts'] = [];

function add_action(...$args): void {}
function is_user_logged_in(): bool { return $GLOBALS['test_logged_in']; }
function get_current_user_id(): int { return 42; }
function get_userdata(int $user_id): WP_User { return new WP_User(); }
function user_can(WP_User $user, string $capability): bool { return $GLOBALS['test_admin']; }
function avpvh_get_member_by_wp_user(int $user_id): ?object { return $GLOBALS['test_member_status'] === null ? null : (object) ['status' => $GLOBALS['test_member_status']]; }
function wp_logout(): void { $GLOBALS['test_logout_count']++; }
function sanitize_text_field(string $value): string { return $value; }
function wp_unslash(string $value): string { return $value; }
function wp_json_encode($value): string { return (string) json_encode($value, JSON_UNESCAPED_SLASHES); }
function plugin_dir_url($path): string { return 'https://example.test/plugin/'; }
function avpvh_asset_version($path): string { return 'test'; }
function wp_enqueue_script($handle, ...$args): void { $GLOBALS['test_scripts'][] = $handle; }
function nocache_headers(): void {}
class Test_JSON_Response extends RuntimeException {
    public function __construct(public array $data, public int $status, public bool $success) { parent::__construct('JSON response'); }
}
function wp_send_json_error($data, $status): void { throw new Test_JSON_Response($data, $status, false); }
function wp_send_json_success($data): void { throw new Test_JSON_Response($data, 200, true); }

require __DIR__ . '/../includes/class-media-token.php';

function decode_base64url(string $value): string {
    $padding = (4 - strlen($value) % 4) % 4;
    return (string) base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
}

function check(string $label, bool $ok): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) {
        exit(1);
    }
}

$issued_at = 1_800_000_000;
$expires   = $issued_at + 8 * HOUR_IN_SECONDS;
$jwt       = AVPVH_Media_Token::build_token(42, $issued_at, $expires);

check('token created', is_string($jwt));
$parts = explode('.', (string) $jwt);
check('token has three URL-safe parts', count($parts) === 3 && !str_contains((string) $jwt, '='));

$header  = json_decode(decode_base64url($parts[0]), true);
$payload = json_decode(decode_base64url($parts[1]), true);
check('header declares HS256 JWT', $header === ['alg' => 'HS256', 'typ' => 'JWT']);
check('issuer and audience are scoped', $payload['iss'] === 'avpvh-members' && $payload['aud'] === 'private-media');
check('subject and timestamps are exact', $payload['sub'] === 42 && $payload['iat'] === $issued_at && $payload['exp'] === $expires);

$expected = hash_hmac('sha256', $parts[0] . '.' . $parts[1], AVPVH_JWT_SECRET, true);
check('signature matches HS256', hash_equals($expected, decode_base64url($parts[2])));
check('invalid lifetime is rejected', AVPVH_Media_Token::build_token(42, $expires, $issued_at) === null);

$validator = new AVPVH_Media_Token();
$token_expiration = new ReflectionMethod(AVPVH_Media_Token::class, 'token_expiration');
$token_expiration->setAccessible(true);
$now = time();
$live_token = AVPVH_Media_Token::build_token(42, $now, $now + 60);
$expired_token = AVPVH_Media_Token::build_token(42, $now - 120, $now - 60);
check('current token validates', $token_expiration->invoke($validator, $live_token, 42) === $now + 60);
check('expired token is rejected', $token_expiration->invoke($validator, $expired_token, 42) === null);
check('token for another user is rejected', $token_expiration->invoke($validator, $live_token, 7) === null);

$GLOBALS['test_logged_in'] = true;
$_COOKIE['avpvh_media_token'] = $live_token;
$validator->enforce_cookie();
check('valid cookie keeps WordPress session active', $GLOBALS['test_logout_count'] === 0);

unset($_COOKIE['avpvh_media_token']);
$validator->enforce_cookie();
check('missing or expired media cookie logs out WordPress', $GLOBALS['test_logout_count'] === 1);

foreach (['visitor', 'inactive', null] as $status) {
    $GLOBALS['test_member_status'] = $status;
    $GLOBALS['test_scripts'] = [];
    $before = $GLOBALS['test_logout_count'];
    $_COOKIE['avpvh_media_token'] = $live_token;
    $validator->enforce_cookie();
    check('non-member keeps WP login and loses private-media token: ' . ($status ?? 'no member'), $GLOBALS['test_logout_count'] === $before && !isset($_COOKIE['avpvh_media_token']));
    $validator->enqueue_session_watchdog();
    check('non-member does not load private-media watchdog', $GLOBALS['test_scripts'] === []);
    $validator->on_logged_in_cookie('fixture-cookie', $now + 3600, $now + 3600, 42, 'logged_in', 'fixture-token');
    check('non-member login does not issue a private-media JWT', !isset($_COOKIE['avpvh_media_token']));
    try { $validator->ajax_session_status(); } catch (Test_JSON_Response $response) {
        check('stale private tab denied without logging out non-member', $response->status === 403 && $response->data['code'] === 'no_private_media' && $GLOBALS['test_logout_count'] === $before);
    }
}
$GLOBALS['test_member_status'] = 'active';
$_COOKIE['avpvh_media_token'] = $live_token;
$validator->enqueue_session_watchdog();
check('active member still loads watchdog', $GLOBALS['test_scripts'] === ['avpvh-media-session']);
try { $validator->ajax_session_status(); } catch (Test_JSON_Response $response) {
    check('active member session endpoint retains token expiry', $response->success && $response->data['expires'] === $now + 60);
}
$GLOBALS['test_member_status'] = null;
$GLOBALS['test_admin'] = true;
$before = $GLOBALS['test_logout_count'];
$validator->enforce_cookie();
check('admin with valid media JWT keeps session', $GLOBALS['test_logout_count'] === $before);
unset($_COOKIE['avpvh_media_token']);
$validator->enforce_cookie();
check('admin without media JWT is still logged out', $GLOBALS['test_logout_count'] === $before + 1);

echo PHP_EOL . 'Done.' . PHP_EOL;
ob_end_flush();
