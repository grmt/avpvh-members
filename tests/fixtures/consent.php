<?php
declare(strict_types=1);

/** Shared boundaries for consent handler tests; no live accounts or services. */
define('ABSPATH', __DIR__ . '/');

final class Consent_Exit extends RuntimeException {
    public function __construct(public string $kind, public int $status, public string $body = '') {
        parent::__construct($body);
    }
}
function add_action(string $hook, callable $callback): void { $GLOBALS['hooks'][$hook] = $callback; }
function check_admin_referer(string $action): void {
    $GLOBALS['nonces'][] = $action;
    if (!$GLOBALS['nonce_valid']) { throw new Consent_Exit('die', 403); }
}
function is_user_logged_in(): bool { return $GLOBALS['actor'] > 0; }
function get_current_user_id(): int { return $GLOBALS['actor']; }
function esc_html__(string $text, string $domain): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function absint(mixed $value): int { return abs((int) $value); }
function wp_unslash(mixed $value): mixed { return is_string($value) ? stripslashes($value) : $value; }
function sanitize_key(string $value): string { return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)); }
function current_time(string $format): string { return '2026-06-15 12:00:00'; }
function wp_get_referer(): string|false { return $GLOBALS['referer']; }
function home_url(string $path): string { return 'https://club.example.invalid' . $path; }
function add_query_arg(string $key, string $value, string $url): string {
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query([$key => $value]);
}
function wp_safe_redirect(string $url): void { throw new Consent_Exit('redirect', 302, $url); }
function wp_die(string $message, string $title, array $args): void { throw new Consent_Exit('die', $args['response'], $message); }

class AVPVH_DB {
    public static function get_member_by_wp_user(int $id): ?object {
        $GLOBALS['member_lookups'][] = $id;
        return $GLOBALS['own_member'];
    }
    public static function get_manageable_members(int $id): array {
        $GLOBALS['household_lookups'][] = $id;
        return $GLOBALS['manageable'];
    }
    public static function is_minor_member(object $member, int $threshold, bool $certain): bool {
        $GLOBALS['minor_checks'][] = [$member->id, $threshold, $certain];
        return $member->minor;
    }
    public static function update_member_with_audit(int $id, array $data, array $formats): void {
        $GLOBALS['writes'][] = compact('id', 'data', 'formats');
    }
    public static function set_member_flag_by_slug(int $id, string $slug, bool $enabled): void {
        $GLOBALS['writes'][] = compact('id', 'slug', 'enabled');
    }
}

function consent_check(string $label, bool $ok): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) { exit(1); }
}
function reset_consent(): void {
    $GLOBALS['actor'] = 101;
    $GLOBALS['own_member'] = (object) ['id' => 10, 'minor' => false];
    // Include the caller first so the handler must find the requested child.
    $GLOBALS['manageable'] = [$GLOBALS['own_member'], (object) ['id' => 20, 'minor' => true]];
    $GLOBALS['nonce_valid'] = true;
    $GLOBALS['referer'] = 'https://club.example.invalid/profiel/?tab=privacy';
    foreach (['writes', 'nonces', 'member_lookups', 'household_lookups', 'minor_checks'] as $key) { $GLOBALS[$key] = []; }
    $_POST = ['consent' => 'granted', 'newsletter' => '1'];
}
function run_consent(object $handler): Consent_Exit {
    try { $handler->handle_set_consent(); } catch (Consent_Exit $exit) { return $exit; }
    throw new RuntimeException('Consent handler did not terminate.');
}

require_once __DIR__ . '/access-session.php';

/** Both consent handlers must enforce the same session, nonce and household checks. */
function consent_authorization_cases(object $handler, string $nonce_action): void {
    foreach (['nonce', 'anonymous', 'missing_profile', 'stranger', 'adult_household'] as $case) {
        reset_consent();
        switch ($case) {
            case 'nonce': $GLOBALS['nonce_valid'] = false; break;
            case 'anonymous': $GLOBALS['actor'] = 0; break;
            case 'missing_profile': $GLOBALS['own_member'] = null; break;
            case 'stranger': $_POST['member_id'] = '999'; break;
            case 'adult_household':
                $_POST['member_id'] = '20';
                $GLOBALS['manageable'][1]->minor = false;
                break;
        }
        $exit = run_consent($handler);
        consent_check($case . ' is rejected before any write or redirect', $exit->kind === 'die' && $exit->status === ($case === 'missing_profile' ? 404 : 403) && !$GLOBALS['writes']);
        consent_check($case . ' validates the action nonce', $GLOBALS['nonces'] === [$nonce_action]);
        if ($case === 'nonce' || $case === 'anonymous') {
            consent_check($case . ' never looks up a member profile', !$GLOBALS['member_lookups']);
        }
    }
    foreach ([null, '0', '10', '20'] as $target) {
        reset_consent();
        if ($target !== null) { $_POST['member_id'] = $target; }
        $exit = run_consent($handler);
        $child = $target === '20';
        consent_check('authorized target ' . ($target ?? 'omitted') . ' saves exactly once for the correct member', $exit->kind === 'redirect' && count($GLOBALS['writes']) === 1 && $GLOBALS['writes'][0]['id'] === ($child ? 20 : 10));
        consent_check('profile lookup uses the logged-in WordPress account', $GLOBALS['member_lookups'] === [101]);
        consent_check('delegation is restricted to a certain minor under 16', $GLOBALS['minor_checks'] === ($child ? [[20, 16, true]] : []) && $GLOBALS['household_lookups'] === ($child ? [10] : []));
    }
}
