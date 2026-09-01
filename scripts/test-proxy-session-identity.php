<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

$test_logged_in = true;
$test_current_user_id = 101;
$test_logout_called = false;
$test_invalidation_called = false;

class AVPVH_Test_Identity_Redirect extends RuntimeException {
    public function __construct(public readonly string $url) {
        parent::__construct('Redirect captured');
    }
}

class AVPVH_DB {
    public static object $proxy_member;

    public static function get_member_by_lldap_uid(string $uid): object {
        return self::$proxy_member;
    }
}

class AVPVH_Nav_Auth {
    public static function invalidate_authelia_session(): bool {
        $GLOBALS['test_invalidation_called'] = true;
        return true;
    }

    public static function authelia_logout_url(string $redirect_url): string {
        return 'https://auth.example.invalid/logout?rd=' . rawurlencode($redirect_url);
    }
}

function add_action(): void {}
function add_filter(): void {}
function sanitize_text_field(string $value): string { return $value; }
function wp_unslash(string $value): string { return $value; }
function is_user_logged_in(): bool { return $GLOBALS['test_logged_in']; }
function get_current_user_id(): int { return $GLOBALS['test_current_user_id']; }
function wp_logout(): void { $GLOBALS['test_logout_called'] = true; }
function home_url(string $path): string { return 'https://www.example.invalid/' . ltrim($path, '/'); }
function wp_safe_redirect(string $url): void { throw new AVPVH_Test_Identity_Redirect($url); }

require_once dirname(__DIR__) . '/includes/class-access.php';

$access = (new ReflectionClass(AVPVH_Access::class))->newInstanceWithoutConstructor();
$_SERVER['HTTP_REMOTE_USER'] = 'second.account';
AVPVH_DB::$proxy_member = (object) ['wp_user_id' => 202];

try {
    $access->auto_login_from_proxy_header();
    fwrite(STDERR, "Mismatched identities were not blocked\n");
    exit(1);
} catch (AVPVH_Test_Identity_Redirect $redirect) {
    if (!$test_logout_called || !$test_invalidation_called) {
        fwrite(STDERR, "Mismatch did not destroy both sessions\n");
        exit(1);
    }
    if (!str_contains($redirect->url, '/logout?rd=') || !str_contains($redirect->url, 'identity_mismatch')) {
        fwrite(STDERR, "Mismatch redirect is invalid\n");
        exit(1);
    }
}

$test_logout_called = false;
$test_invalidation_called = false;
$test_current_user_id = 202;
$access->auto_login_from_proxy_header();
if ($test_logout_called || $test_invalidation_called) {
    fwrite(STDERR, "Matching identities were logged out\n");
    exit(1);
}

echo "Proxy session identity tests: OK\n";
