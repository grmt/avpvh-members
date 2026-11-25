<?php
declare(strict_types=1);

/**
 * Ad-hoc checks for where Ledendetail's e-mail/identity handlers send a
 * member admin back to: Ledendetail itself, or the member-profile page when
 * the form came from its admin e-mail block (avpvh_return=profile), without
 * the previous action's notice.
 *
 * Run:
 *   php scripts/test-admin-email-return.php
 */

define('ABSPATH', __DIR__ . '/');

class Redirected extends RuntimeException {}
class Admin_Action_Denied extends Error { public function __construct(public int $status) {} }

function __(string $text, string $domain = ''): string { return $text; }
function esc_html__(string $text, string $domain = ''): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function absint(mixed $value): int { return abs((int) $value); }
function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function sanitize_email(string $value): string { return $value; }
function current_user_can(string $cap): bool { return $GLOBALS['wp_admin'] ?? false; }
function check_admin_referer(string $action): void {}
function wp_die(string $message, mixed $title = '', array $args = []): void { throw new Admin_Action_Denied(is_int($title) ? $title : ($args['response'] ?? 500)); }
class AVPVH_Roles {
    public static function can_manage_members(): bool { return $GLOBALS['member_admin'] ?? true; }
    public static function member_has_elevated_access(int $id): bool { return $id === 45; }
    public static function sync_status_group(int $id): bool { return true; }
}
class AVPVH_DB {
    public static function ensure_identity(int $member, string $provider, string $email): bool { $GLOBALS['admin_writes'][] = compact('member', 'provider', 'email'); return true; }
    public static function flag_ids_include_inactivating(array $ids): bool { return in_array(7, $ids, true); }
    public static function set_member_flags(int $member, array $flags): void { $GLOBALS['admin_writes'][] = compact('member', 'flags'); }
    public static function member_has_inactivating_flag(int $member): bool { return false; }
    public static function set_death_date(int $member, ?string $date): array { $GLOBALS['admin_writes'][] = compact('member', 'date'); return ['closed' => 0, 'later' => 0]; }
}

$GLOBALS['referer'] = false;
function wp_safe_redirect(string $url): void { throw new Redirected($url); }
function wp_get_referer() { return $GLOBALS['referer']; }
function home_url(string $path = ''): string { return 'https://example.test' . $path; }
function admin_url(string $path = ''): string { return 'https://example.test/wp-admin/' . $path; }
function sanitize_key($key): string { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $key)); }
function wp_unslash($value) { return $value; }
function add_action(...$args): void { /* Not needed in this test. */ }
function add_shortcode(...$args): void { /* Not needed in this test. */ }

function split_url(string $url): array {
    $parts = explode('?', $url, 2);
    parse_str($parts[1] ?? '', $query);
    return [$parts[0], $query];
}
function add_query_arg(array $args, string $url): string {
    [$base, $query] = split_url($url);
    return $base . '?' . http_build_query(array_merge($query, $args));
}
function remove_query_arg(array $keys, string $url): string {
    [$base, $query] = split_url($url);
    $query = array_diff_key($query, array_flip($keys));
    return $query ? $base . '?' . http_build_query($query) : $base;
}

require_once dirname(__DIR__) . '/tests/fixtures/access-session.php';
require_once dirname(__DIR__) . '/includes/class-member-profile-form.php';
require_once dirname(__DIR__) . '/includes/class-admin.php';

function check(string $label, mixed $actual, mixed $expected): void {
    echo ($actual === $expected ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if ($actual !== $expected) {
        echo '  expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL;
        exit(1);
    }
}

function redirect_target(array $post, array $detail_args, array $profile_args): string {
    $_POST = $post;
    $admin = (new ReflectionClass(AVPVH_Admin::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(AVPVH_Admin::class, 'redirect_after_member_action');
    try {
        $method->invoke($admin, 45, $detail_args, $profile_args);
    } catch (Redirected $r) {
        return $r->getMessage();
    }
    return 'no redirect';
}

check(
    'without avpvh_return: back to Ledendetail with its own notice',
    redirect_target([], ['identity_ok' => '1'], ['identity_added' => '1']),
    'https://example.test/wp-admin/admin.php?page=avpvh-member-detail&id=45&identity_ok=1'
);

$GLOBALS['referer'] = 'https://example.test/leden/beheer/member-profile/?member_id=45&identity_removed=1';
check(
    'avpvh_return=profile: back to the profile, old notice gone, new one set, at the e-mail block',
    redirect_target(['avpvh_return' => 'profile'], ['identity_ok' => '1'], ['identity_added' => '1']),
    'https://example.test/leden/beheer/member-profile/?member_id=45&identity_added=1#avpvh-admin-emails'
);

$GLOBALS['referer'] = false;
check(
    'avpvh_return=profile without a referer: the default profile page',
    redirect_target(['avpvh_return' => 'profile'], ['email_updated' => '1'], ['email_updated' => '1']),
    'https://example.test/member-profile/?member_id=45&email_updated=1#avpvh-admin-emails'
);

check(
    'an unknown avpvh_return value goes to Ledendetail',
    redirect_target(['avpvh_return' => 'elsewhere'], ['identity_ok' => '1'], ['identity_added' => '1']),
    'https://example.test/wp-admin/admin.php?page=avpvh-member-detail&id=45&identity_ok=1'
);

$GLOBALS['referer'] = 'https://example.test/leden/beheer/member-profile/?member_id=45&email_error=1&identity_primary=1';
check('back_url() drops every notice arg', AVPVH_Member_Profile_Form::back_url(), 'https://example.test/leden/beheer/member-profile/?member_id=45');

$admin = (new ReflectionClass(AVPVH_Admin::class))->newInstanceWithoutConstructor();
$GLOBALS['admin_writes'] = [];
$denied = false;
try { $admin->render_settings(); } catch (Admin_Action_Denied $exit) { $denied = $exit->status === 403; }
check('settings require WordPress administration rights', $denied, true);
foreach (['handle_add_identity', 'handle_delete_identity', 'handle_primary_identity', 'handle_update_email', 'handle_save_member_flags', 'handle_set_death_date'] as $handler) {
    $_POST = ['member_id' => 45, 'identity_id' => 2, 'email' => 'fixture@example.invalid', 'flag_ids' => [7], 'death_date' => '2026-01-01'];
    $denied = false;
    try { $admin->$handler(); } catch (Admin_Action_Denied $exit) { $denied = $exit->status === 403; }
    check($handler . ' rejects an elevated target before writing', $denied && !$GLOBALS['admin_writes'], true);
}
$GLOBALS['wp_admin'] = true;
$_POST = ['member_id' => 45, 'email' => 'fixture@example.invalid'];
$url = '';
try { $admin->handle_add_identity(); } catch (Redirected $exit) { $url = $exit->getMessage(); }
check('WordPress administrator may add a target identity and returns to its detail', $GLOBALS['admin_writes'] === [['member' => 45, 'provider' => 'email', 'email' => 'fixture@example.invalid']] && str_contains($url, 'identity_ok=1'), true);
$GLOBALS['wp_admin'] = false;
$GLOBALS['admin_writes'] = [];
$_POST = ['member_id' => 46, 'email' => 'ordinary@example.invalid'];
try { $admin->handle_add_identity(); } catch (Redirected $exit) {}
check('member administrator can still modify ordinary targets', $GLOBALS['admin_writes'][0]['member'] === 46, true);
$GLOBALS['admin_writes'] = [];
$_POST = ['member_id' => 45, 'flag_ids' => [6]];
try { $admin->handle_save_member_flags(); } catch (Redirected $exit) {}
check('ordinary flags remain editable without changing target status', $GLOBALS['admin_writes'] === [['member' => 45, 'flags' => [6]]], true);
$GLOBALS['admin_writes'] = [];
$_POST = ['member_id' => 45, 'death_date' => ''];
try { $admin->handle_set_death_date(); } catch (Redirected $exit) {}
check('clearing a date remains distinct from applying an inactivating date', $GLOBALS['admin_writes'] === [['member' => 45, 'date' => null]], true);

echo "Admin e-mail return tests: OK\n";
