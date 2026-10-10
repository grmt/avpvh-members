<?php
declare(strict_types=1);

/** Run: php scripts/test-account-access.php. All account data is fictional. */
define('ABSPATH', __DIR__ . '/');

class WP_Post {
    public string $post_type = 'page';
    public string $post_content = '';
}
class WP_User {
    public int $ID = 1;
    public array $roles = ['subscriber'];
    public string $display_name = 'Testbezoeker <voorbeeld>';
    public string $user_login = 'test-account';
}
class AVPVH_Test_Redirect extends RuntimeException {}
class AVPVH_Directory {
    public static function cached_user_groups(string $uid): array { return []; }
}

$logged_in = true;
$member_status = 'visitor';
$page_slug = 'member-profile';
$post = new WP_Post();
$footer_callbacks = [];

function add_action($hook, $callback, ...$args): void {
    if ($hook === 'wp_footer') $GLOBALS['footer_callbacks'][] = $callback;
}
function add_filter(...$args): void {}
function is_user_logged_in(): bool { return $GLOBALS['logged_in']; }
function get_current_user_id(): int { return is_user_logged_in() ? 1 : 0; }
function wp_get_current_user(): WP_User { return new WP_User(); }
function get_user_meta(...$args): string { return ''; }
function avpvh_get_member_by_wp_user(int $id): ?object {
    return is_user_logged_in() ? (object) ['status' => $GLOBALS['member_status'], 'user_id' => 'test-account'] : null;
}
function avpvh_format_name(object $member): string { return (new WP_User())->display_name; }
function __(string $text, string $domain = ''): string { return $text; }
function esc_html(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function esc_html__(string $text, string $domain = ''): string { return esc_html($text); }
function esc_url(string $url): string { return htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); }
function home_url(string $path = ''): string { return 'https://example.test' . $path; }
function rest_url(string $path): string { return home_url('/wp-json/' . $path); }
function wp_logout_url(): string { return rest_url('avpvh/v1/logout'); }
function wp_unslash(string $text): string { return $text; }
function sanitize_key(string $text): string { return preg_replace('/[^a-z0-9_-]/', '', strtolower($text)); }
function sanitize_text_field(string $text): string { return strip_tags($text); }
function wp_validate_redirect(string $url, string $fallback): string { return $url; }
function wp_safe_redirect(string $url): void { throw new AVPVH_Test_Redirect($url); }
function is_page($slug = null): bool {
    if ($GLOBALS['post']->post_type !== 'page') return false;
    return $slug === null || is_array($slug) || $slug === $GLOBALS['page_slug'];
}
function is_single(): bool { return $GLOBALS['post']->post_type === 'post'; }
function is_author(): bool { return false; }
function is_singular(): bool { return true; }
function post_password_required(): bool { return true; }
function get_post(): WP_Post { return $GLOBALS['post']; }
function has_shortcode(string $content, string $tag): bool { return str_contains($content, '[' . $tag . ']'); }
function shortcode_exists(string $tag): bool { return true; }
function get_posts(array $args): array { return [10]; }
function get_pages(array $args): array { return [(object) ['ID' => 11]]; }
function wp_list_pluck(array $rows, string $field): array { return array_column($rows, $field); }
function get_page_by_path(string $path): ?WP_Post { return $path === 'leden/beheer/member-profile' ? new WP_Post() : null; }
function get_permalink($page): string { return home_url('/leden/beheer/member-profile/'); }
function add_query_arg(string $name, string $value, string $url): string { return $url . '?' . urlencode($name) . '=' . urlencode($value); }
function plugin_dir_url(string $path): string { return home_url('/plugin/'); }
function avpvh_asset_version(string $path): string { return 'test'; }
function wp_enqueue_script(...$args): void {}
function wp_json_encode($value): string { return json_encode($value); }

require dirname(__DIR__) . '/includes/class-access.php';
require dirname(__DIR__) . '/includes/class-nav-auth.php';

function check_account(string $label, bool $ok): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    echo 'PASS ' . $label . "\n";
}
function nav_config(): array {
    $GLOBALS['footer_callbacks'] = [];
    (new AVPVH_Nav_Auth())->enqueue();
    ob_start();
    foreach ($GLOBALS['footer_callbacks'] as $callback) $callback();
    $html = ob_get_clean();
    preg_match('/id="avpvh-auth-config">(.*?)<\/script>/s', $html, $match);
    return json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
}
function expect_redirect(AVPVH_Access $access, string $expected): void {
    try {
        $access->handle_login_bridge();
        throw new RuntimeException('Expected a redirect');
    } catch (AVPVH_Test_Redirect $redirect) {
        check_account('restricted request redirects to ' . $expected, $redirect->getMessage() === home_url($expected));
    }
}

if (($argv[1] ?? '') === '--config') {
    $member_status = $argv[2] ?? 'visitor';
    $logged_in = $member_status !== 'guest';
    echo json_encode(nav_config());
    exit;
}

$access = new AVPVH_Access();
foreach (['visitor' => 'Bezoeker', 'inactive' => 'Oud lid', 'active' => 'Lid'] as $status => $label) {
    $member_status = $status;
    $cfg = nav_config();
    check_account($status . ' has authenticated navigation and an accurate status', $cfg['isLoggedIn'] && $cfg['memberStatusLabel'] === $label && $cfg['isActiveMember'] === ($status === 'active'));
    check_account($status . ' has profile and payments links', $cfg['profileUrl'] === get_permalink($post) && $cfg['paymentsUrl'] === get_permalink($post) . '#bijdrage');
    check_account($status . ' has a membership application link only as a visitor', ($cfg['membershipApplicationUrl'] !== '') === ($status === 'visitor'));
    foreach (['avpvh_member_profile', 'avpvh_bk_balance'] as $shortcode) {
        $post->post_content = '[' . $shortcode . ']';
        $access->handle_login_bridge();
        check_account($status . ' can open a protected personal account page', !$access->bypass_for_active_member(true, $post));
        check_account($status . ' keeps personal account content', $access->ex_member_notice('personal account') === 'personal account');
    }
}
$member_status = 'visitor';
$post->post_content = 'members-only information';
expect_redirect($access, '/avpvh-login/?login_notice=members_only');
check_account('visitor cannot bypass ordinary page passwords', $access->bypass_for_active_member(true, $post));
$post->post_content = '[avpvh_member_profile]';
$post->post_type = 'post';
expect_redirect($access, '/avpvh-login/?login_notice=members_only');
check_account('a profile shortcode does not make a blog post public', $access->bypass_for_active_member(true, $post));
$post->post_type = 'page';
$page_slug = 'avpvh-login';
$_GET['login_notice'] = 'members_only';
foreach (['visitor', 'inactive'] as $status) {
    $member_status = $status;
    $access->handle_login_bridge();
    $notice = $access->ex_member_notice($access->inject_login_form('login page'));
    check_account($status . ' sees login confirmation and a permission explanation', str_contains($notice, 'Ingelogd als:') && str_contains($notice, 'geen toegang'));
    check_account('account names are escaped in the notice', str_contains($notice, '&lt;voorbeeld&gt;') && !str_contains($notice, '<voorbeeld>'));
}
$logged_in = false;
$page_slug = 'member-profile';
expect_redirect($access, '/avpvh-login/');
check_account('a guest cannot bypass the profile password', $access->bypass_for_active_member(true, $post));
check_account('a guest is not marked as logged in', !nav_config()['isLoggedIn']);
