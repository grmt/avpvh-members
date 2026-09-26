<?php
declare(strict_types=1);

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

function add_action(...$args): void {}
function is_user_logged_in(): bool { return $GLOBALS['test_logged_in']; }
function get_current_user_id(): int { return 42; }
function get_userdata(int $user_id): WP_User { return new WP_User(); }
function user_can(WP_User $user, string $capability): bool { return false; }
function avpvh_get_member_by_wp_user(int $user_id): object { return (object) ['status' => 'active']; }
function wp_logout(): void { $GLOBALS['test_logout_count']++; }
function sanitize_text_field(string $value): string { return $value; }
function wp_unslash(string $value): string { return $value; }
function wp_json_encode($value): string { return (string) json_encode($value, JSON_UNESCAPED_SLASHES); }

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

echo PHP_EOL . 'Done.' . PHP_EOL;
