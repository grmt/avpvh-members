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

echo "Admin e-mail return tests: OK\n";
