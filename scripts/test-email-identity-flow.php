<?php
declare(strict_types=1);

/** Exercise the real e-mail confirmation handlers with fictional accounts and in-memory storage. */
define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);

final class Email_Flow_Exit extends Error {
    public function __construct(public string $kind, public int $status = 0, public string $body = '') {}
}
class WP_REST_Request {
    public function __construct(private string $method, private array $params) {}
    public function get_method(): string { return $this->method; }
    public function get_param(string $key): mixed { return $this->params[$key] ?? null; }
}
class WP_User { public function __construct(public int $ID) {} }
function __(string $text, string $domain = ''): string { return $text; }
function esc_html__(string $text, string $domain = ''): string { return esc_html($text); }
function esc_html(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function esc_attr(string $text): string { return esc_html($text); }
function esc_url(string $url): string { return esc_attr($url); }
function absint(mixed $value): int { return abs((int) $value); }
function wp_unslash(mixed $value): mixed { return $value; }
function sanitize_email(string $value): string { return filter_var(trim($value), FILTER_VALIDATE_EMAIL) ? trim($value) : ''; }
function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function is_email(string $value): bool { return filter_var($value, FILTER_VALIDATE_EMAIL) !== false; }
function is_user_logged_in(): bool { return $GLOBALS['actor'] > 0; }
function get_current_user_id(): int { return $GLOBALS['actor']; }
function get_userdata(int $id): ?WP_User { return $id > 0 ? new WP_User($id) : null; }
function home_url(string $path): string { return 'https://club.example.invalid' . $path; }
function rest_url(string $path): string { return home_url('/wp-json/' . $path); }
function add_query_arg(array $args, string $url): string { return $url . '?' . http_build_query($args); }
function add_action(string $hook, mixed ...$args): void {}
function check_admin_referer(string $action): void { $GLOBALS['nonces'][] = $action; }
function register_rest_route(string $namespace, string $path, array $args): void { $GLOBALS['route'] = [$namespace, $path, $args]; }
function nocache_headers(): void { $GLOBALS['no_cache'] = true; }
function wp_json_encode(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR); }
function get_transient(string $key): mixed { return $GLOBALS['transients'][$key] ?? false; }
function set_transient(string $key, mixed $value, int $ttl): bool {
    $GLOBALS['transients'][$key] = $value;
    $GLOBALS['ttls'][$key] = $ttl;
    return true;
}
function delete_transient(string $key): bool { unset($GLOBALS['transients'][$key]); return true; }
function wp_mail(string $to, string $subject, string $body): bool { $GLOBALS['mail'][] = compact('to', 'subject', 'body'); return true; }
function wp_safe_redirect(string $url): void { throw new Email_Flow_Exit('redirect', 302, $url); }
function wp_die(string $message, string $title = '', array $args = []): void { throw new Email_Flow_Exit('die', $args['response'] ?? 500, $message); }

class AVPVH_DB {
    public static function get_member_by_wp_user(int $id): ?object {
        foreach ($GLOBALS['members'] as $member) {
            if ($member->wp_user_id === $id) { return $member; }
        }
        return null;
    }
    public static function get_member(int $id): ?object { return $GLOBALS['members'][$id] ?? null; }
    public static function normalize_identity_email(string $email): string { return strtolower(trim($email)); }
    public static function get_member_identity_count(int $id): int { return $GLOBALS['identity_count']; }
    public static function get_identity_by_email(string $email): ?object { return $GLOBALS['identity_owner']; }
    public static function get_member_by_email(string $email): ?object { return $GLOBALS['directory_owner']; }
    public static function ensure_identity(int $id, string $provider, string $email, bool $primary, bool $verified): bool {
        $GLOBALS['saved'][] = compact('id', 'provider', 'email', 'primary', 'verified');
        return $GLOBALS['ensure_result'];
    }
}
class AVPVH_Member_Profile_Form {
    public static function notify_identity_change(mixed ...$args): void { $GLOBALS['notifications'][] = $args; }
}
require_once dirname(__DIR__) . '/includes/class-email-identity.php';

$results = [];
function check(string $label, bool $ok): void { $GLOBALS['results'][] = [$label, $ok]; }
function reset_flow(): void {
    $GLOBALS['actor'] = 101;
    $GLOBALS['members'] = [10 => (object) ['id' => 10, 'wp_user_id' => 101]];
    foreach (['transients', 'ttls', 'mail', 'saved', 'notifications', 'nonces'] as $key) { $GLOBALS[$key] = []; }
    $GLOBALS['identity_count'] = 0;
    $GLOBALS['identity_owner'] = $GLOBALS['directory_owner'] = null;
    $GLOBALS['ensure_result'] = true;
    $GLOBALS['no_cache'] = false;
    $_POST = ['member_id' => 10, 'email' => 'NEW@example.invalid'];
}
function run_flow(callable $callback): Email_Flow_Exit {
    try { $callback(); } catch (Email_Flow_Exit $exit) { return $exit; }
    throw new RuntimeException('Handler did not terminate as expected.');
}
function token_from_mail(): string {
    preg_match('/[?]token=([a-f0-9]{64})/', $GLOBALS['mail'][0]['body'], $matches);
    return $matches[1] ?? '';
}

$flow = new AVPVH_Email_Identity();
$flow->register_routes();
check('confirmation route supports GET and POST', $GLOBALS['route'][2]['methods'] === ['GET', 'POST']);
reset_flow();
$exit = run_flow([$flow, 'handle_start_add']);
$token = token_from_mail();
$key = AVPVH_Email_Identity::token_key($token);
check('request mails the normalized address and returns to the profile', $GLOBALS['mail'][0]['to'] === 'new@example.invalid' && str_contains($exit->body, 'identity_email_sent=1'));
check('only a hash of the confirmation token is stored', strlen($token) === 64 && isset($GLOBALS['transients'][$key]) && !isset($GLOBALS['transients']['avpvh_email_identity_' . $token]));
check('confirmation expires after one hour', $GLOBALS['ttls'][$key] === HOUR_IN_SECONDS);
$payload = json_decode($GLOBALS['transients'][$key], true);
check('stored request binds the member and requested address', $payload === ['mode' => 'add', 'member_id' => 10, 'email' => 'new@example.invalid']);
$exit = run_flow(fn() => $flow->handle_confirm(new WP_REST_Request('GET', ['token' => $token])));
check('GET renders a confirmation button without saving or consuming the token', $exit->status === 200 && str_contains($exit->body, '<form method="post"') && isset($GLOBALS['transients'][$key]) && !$GLOBALS['saved'] && $GLOBALS['no_cache']);
$exit = run_flow(fn() => $flow->handle_confirm(new WP_REST_Request('POST', ['token' => $token])));
check('POST saves a verified non-primary identity for the requested member', $GLOBALS['saved'] === [['id' => 10, 'provider' => 'email', 'email' => 'new@example.invalid', 'primary' => false, 'verified' => true]]);
check('successful confirmation consumes the token and notifies the member', !isset($GLOBALS['transients'][$key]) && count($GLOBALS['notifications']) === 1 && str_contains($exit->body, 'identity_added=1'));
$exit = run_flow(fn() => $flow->handle_confirm(new WP_REST_Request('POST', ['token' => $token])));
check('replaying a consumed token is rejected without another write', $exit->status === 400 && count($GLOBALS['saved']) === 1);

foreach (['anonymous', 'different_member', 'invalid_email', 'identity_claimed', 'directory_claimed', 'limit', 'rate'] as $case) {
    reset_flow();
    switch ($case) {
        case 'anonymous': $GLOBALS['actor'] = 0; break;
        case 'different_member': $_POST['member_id'] = 20; break;
        case 'invalid_email': $_POST['email'] = 'invalid'; break;
        case 'identity_claimed': $GLOBALS['identity_owner'] = (object) ['member_id' => 20]; break;
        case 'directory_claimed': $GLOBALS['directory_owner'] = (object) ['id' => 20]; break;
        case 'limit': $GLOBALS['identity_count'] = 3; break;
        case 'rate': $GLOBALS['transients']['avpvh_email_identity_sent_10'] = 5; break;
    }
    $exit = run_flow([$flow, 'handle_start_add']);
    $errors = ['invalid_email' => 'invalid_email', 'identity_claimed' => 'in_use', 'directory_claimed' => 'in_use', 'limit' => 'limit', 'rate' => 'too_many_mails'];
    check($case . ' request is rejected without sending mail', !$GLOBALS['mail'] && !$GLOBALS['saved'] && (isset($errors[$case]) ? str_contains($exit->body, 'identity_error=' . $errors[$case]) : $exit->status === 403));
    if ($case === 'identity_claimed') {
        check('rejected address probes count against the hourly limit', $GLOBALS['transients']['avpvh_email_identity_sent_10'] === 1);
    }
}
reset_flow();
$GLOBALS['identity_count'] = 3;
$GLOBALS['identity_owner'] = (object) ['member_id' => 10];
run_flow([$flow, 'handle_start_add']);
check('an existing identity of the same member can be reverified at the limit', count($GLOBALS['mail']) === 1);

foreach (['claimed_later', 'save_refused', 'member_deleted', 'no_wp_account'] as $case) {
    reset_flow();
    run_flow([$flow, 'handle_start_add']);
    $token = token_from_mail();
    switch ($case) {
        case 'claimed_later': $GLOBALS['identity_owner'] = (object) ['member_id' => 20]; break;
        case 'save_refused': $GLOBALS['ensure_result'] = false; break;
        case 'member_deleted': $GLOBALS['members'] = []; break;
        case 'no_wp_account': $GLOBALS['members'][10]->wp_user_id = 0; break;
    }
    $exit = run_flow(fn() => $flow->handle_confirm(new WP_REST_Request('POST', ['token' => $token])));
    $expected = match ($case) {
        'member_deleted' => $exit->status === 404 && !$GLOBALS['saved'],
        'no_wp_account' => str_contains($exit->body, 'identity_added=1') && count($GLOBALS['saved']) === 1,
        default => str_contains($exit->body, 'identity_error=in_use'),
    };
    check($case . ' is handled at confirmation time', $expected && !$GLOBALS['notifications'] && !isset($GLOBALS['transients'][AVPVH_Email_Identity::token_key($token)]));
}
reset_flow();
$GLOBALS['transients'][AVPVH_Email_Identity::token_key('wrong-mode')] = wp_json_encode(['mode' => 'login']);
check('tokens with the wrong purpose are rejected', run_flow(fn() => $flow->handle_confirm(new WP_REST_Request('GET', ['token' => 'wrong-mode'])))->status === 400);

$failures = 0;
foreach ($results as [$label, $ok]) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    $failures += $ok ? 0 : 1;
}
exit($failures ? 1 : 0);
