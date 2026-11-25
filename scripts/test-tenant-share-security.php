<?php
declare(strict_types=1);

/** Exercise actual download exits, revocation and cross-account file authorization. Fictional data only. */
define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);
define('MINUTE_IN_SECONDS', 60);

class AVPVH_Tenant_Config {
    public function get(string $key): mixed { return $key === 'authelia_session_cookie' ? 'fixture_session' : null; }
    public function content_groups(): array { return ['fixture-members']; }
    public function tenant_id(): string { return 'fixture'; }
}
class AVPVH_Tenant_Content {
    public function current_user_may_read(): bool { return $GLOBALS['may_read']; }
}
class AVPVH_Tenant_SSO {}
class AVPVH_Tenant_Identity {
    const OK = 'ok';
    public static function probe(AVPVH_Tenant_Config $config): array {
        $GLOBALS['probes']++;
        return $GLOBALS['identity'];
    }
}
function add_action(mixed ...$args): void {}
function is_user_logged_in(): bool { return $GLOBALS['user'] > 0; }
function get_current_user_id(): int { return $GLOBALS['user']; }
function get_transient(string $key): mixed { return $GLOBALS['transients'][$key] ?? false; }
function set_transient(string $key, mixed $value, int $ttl): bool { $GLOBALS['transients'][$key] = $value; return true; }
function delete_transient(string $key): bool { unset($GLOBALS['transients'][$key]); return true; }
function __(string $value, string $domain = ''): string { return $value; }
function esc_html(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_html__(string $value, string $domain = ''): string { return esc_html($value); }
function esc_attr(mixed $value): string { return esc_html($value); }
function esc_attr__(string $value, string $domain = ''): string { return esc_html($value); }
function esc_url(string $value): string { return esc_html($value); }
function home_url(string $path): string { return 'https://fixture.example.invalid' . $path; }
function add_query_arg(string $key, string $value, string $url): string { return $url . '?' . http_build_query([$key => $value]); }
function wp_create_nonce(string $action): string { return 'fixture-nonce'; }
function wp_verify_nonce(string $nonce, string $action): bool { return $nonce === 'fixture-nonce'; }
function wp_unslash(string $value): string { return $value; }
function sanitize_text_field(string $value): string { return $value; }
function sanitize_email(string $value): string { return $value; }
function wp_upload_dir(): array { return ['basedir' => $GLOBALS['uploads']]; }
function status_header(int $status): void { $GLOBALS['status'] = $status; }

require_once dirname(__DIR__) . '/includes/tenant/class-tenant-shares.php';
require_once dirname(__DIR__) . '/includes/tenant/class-tenant-files.php';

$GLOBALS['user'] = 42;
$GLOBALS['may_read'] = false;
$GLOBALS['identity'] = ['status' => 'ok', 'uid' => 'fixture-a', 'groups' => ['fixture-members']];
$GLOBALS['probes'] = 0;
$GLOBALS['transients'] = [];
$config = new AVPVH_Tenant_Config();
$content = new AVPVH_Tenant_Content();
$files = new AVPVH_Tenant_Files($config, new AVPVH_Tenant_SSO(), $content);

// A child invokes the real stream()/render() methods, which deliberately exit.
$case = $argv[1] ?? '';
if ($case !== '') {
    $shares = new AVPVH_Tenant_Shares($config, $content);
    $share = ['title' => 'Fixture share', 'file' => 'fixture.txt', 'emails' => ['allowed@example.invalid']];
    if ($case === 'share_throttle') {
        $key = 'avpvh_share_total_' . hash('sha256', 'fixture');
        $GLOBALS['transients'][$key] = AVPVH_Tenant_Shares::MAX_SHARE_TRIES;
        $_SERVER['REMOTE_ADDR'] = '192.0.2.123'; // a fresh IP must still hit the share-wide cap
        $_POST = ['_wpnonce' => 'fixture-nonce', 'email' => 'allowed@example.invalid'];
        (new ReflectionMethod($shares, 'handle_form'))->invoke($shares, 'fixture', $share);
        exit(1);
    }
    $GLOBALS['uploads'] = sys_get_temp_dir() . '/avpvh-share-fixture-' . getmypid();
    mkdir($GLOBALS['uploads'] . '/private/delen', 0700, true);
    $path = $GLOBALS['uploads'] . '/private/delen/fixture.txt';
    file_put_contents($path, 'FICTIONAL DOWNLOAD CONTENT');
    register_shutdown_function(static function () use ($path): void {
        unlink($path);
        rmdir(dirname($path));
        rmdir(dirname($path, 2));
        rmdir(dirname($path, 3));
    });
    $who = 'user:42';
    if ($case === 'member_allowed') { $GLOBALS['may_read'] = true; }
    if ($case === 'other_user') { $GLOBALS['user'] = 43; $GLOBALS['may_read'] = true; }
    if ($case === 'uid_allowed' || $case === 'uid_revoked') {
        $who = 'uid:fixture-a';
        $GLOBALS['user'] = 0;
        if ($case === 'uid_revoked') { $GLOBALS['identity']['groups'] = []; }
    }
    if ($case === 'email_allowed' || $case === 'email_removed') {
        $who = 'allowed@example.invalid';
        if ($case === 'email_removed') { $share['emails'] = []; }
    }
    $key = (new ReflectionMethod($shares, 'token_key'))->invoke(null, 'fixture', 'fixture-token');
    $GLOBALS['transients'][$key] = $who;
    ini_set('error_log', '/dev/null');
    (new ReflectionMethod($shares, 'stream'))->invoke($shares, 'fixture', $share, 'fixture-token');
    exit(1);
}

$failures = 0;
function check(string $label, bool $ok): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    $GLOBALS['failures'] += $ok ? 0 : 1;
}
$decide = new ReflectionMethod($files, 'decide');
check('logged-in non-member cannot use another account\'s Authelia cookie', $decide->invoke($files) === 403 && $GLOBALS['probes'] === 0);
$GLOBALS['user'] = 0;
check('anonymous file request may use its own Authelia session', $decide->invoke($files) === 204);
foreach (['member_allowed', 'member_revoked', 'other_user', 'uid_allowed', 'uid_revoked', 'email_allowed', 'email_removed', 'share_throttle'] as $case) {
    $process = proc_open([PHP_BINARY, __FILE__, $case], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    $allowed = str_ends_with($case, '_allowed');
    $expected = $allowed ? 'FICTIONAL DOWNLOAD CONTENT' : ($case === 'share_throttle' ? 'Te veel pogingen' : 'niet meer geldig');
    check($case, $status === 0 && $errors === '' && str_contains($output, $expected) && ($allowed || !str_contains($output, 'FICTIONAL DOWNLOAD CONTENT')));
}
exit($failures ? 1 : 0);
