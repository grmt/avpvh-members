<?php
declare(strict_types=1);

/** An incomplete fictional tenant must block requests while leaving administrators a diagnostic. */
define('ABSPATH', __DIR__ . '/');
define('AVPVH_TENANT_CONFIG', ['tenant_id' => 'fixture_tenant', 'canonical_host' => 'site.example.invalid']);
final class Invalid_Tenant_Exit extends Error {
    public function __construct(public int $status) {}
}
class WP_Error {
    public function __construct(public string $code, public string $message, public array $data) {}
}
$hooks = $checks = [];
$admin = $no_cache = false;
$response = 200;
function plugin_dir_path(string $path): string { return dirname($path) . '/'; }
function home_url(string $path = '/'): string { return 'https://site.example.invalid/' . ltrim($path, '/'); }
function wp_parse_url(string $url, int $component = -1): mixed { return parse_url($url, $component); }
function sanitize_text_field(string $value): string { return strip_tags($value); }
function wp_unslash(mixed $value): mixed { return $value; }
function current_user_can(string $cap): bool { return $GLOBALS['admin']; }
function __(string $text, string $domain = ''): string { return $text; }
function esc_html(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function esc_html__(string $text, string $domain = ''): string { return esc_html($text); }
function add_action(string $hook, mixed $callback, int $priority = 10, int $accepted = 1): void { $GLOBALS['hooks'][$hook][$priority][] = $callback; }
function add_filter(string $hook, mixed $callback, int $priority = 10, int $accepted = 1): void { add_action($hook, $callback, $priority, $accepted); }
function status_header(int $status): void { $GLOBALS['response'] = $status; }
function nocache_headers(): void { $GLOBALS['no_cache'] = true; }
function wp_die(string $message, string $title = '', array $args = []): void { throw new Invalid_Tenant_Exit($args['response'] ?? 500); }
function check(string $label, bool $ok): void { $GLOBALS['checks'][] = [$label, $ok]; }
function blocked(callable $callback): bool {
    try { $callback(); } catch (Invalid_Tenant_Exit $exit) { return $exit->status === 503; }
    return false;
}
require_once dirname(__DIR__) . '/avpvh-members.php';
check('invalid tenant does not load the member database', !class_exists('AVPVH_DB', false));
check('invalid tenant does not instantiate the normal login layer', !isset($avpvh_tenant_sso));
$notice = $hooks['admin_notices'][10][0];
ob_start(); $notice(); $text = ob_get_clean();
check('configuration details are absent for ordinary visitors', $text === '');
$admin = true;
ob_start(); $notice(); $text = ob_get_clean();
check('administrator receives a configuration diagnostic', str_contains($text, 'tenantconfiguratie is ongeldig') && str_contains($text, 'authelia_url'));
$existing = new WP_Error('existing', 'Fixture error', ['status' => 401]);
check('administrator REST authentication errors are preserved', $hooks['rest_authentication_errors'][1][0]($existing) === $existing);
check('administrator can still reach diagnostic pages', !blocked($hooks['template_redirect'][0][0]));
$admin = false;
check('ordinary front-end requests fail closed', blocked($hooks['template_redirect'][0][0]));
$error = $hooks['rest_authentication_errors'][1][0](null);
check('REST requests receive a configuration error instead of content', $error->code === 'avpvh_tenant_config_invalid' && $error->data['status'] === 503);
check('local login is unavailable unless explicitly configured', blocked($hooks['login_init'][0][0]));
$_SERVER['REQUEST_URI'] = '/public/';
$hooks['init'][0][0]();
check('file-auth handler does not terminate unrelated routes', $response === 200 && !$no_cache);

// Run the production file-auth exit last, and assert its response on shutdown.
register_shutdown_function(static function (): void {
    check('private-file authorization exits with an uncached 503', $GLOBALS['response'] === 503 && $GLOBALS['no_cache']);
    $failed = 0;
    foreach ($GLOBALS['checks'] as [$label, $ok]) {
        echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
        $failed += $ok ? 0 : 1;
    }
    if ($failed) { exit(1); }
});
$_SERVER['REQUEST_URI'] = '/avpvh-file-auth/';
$hooks['init'][0][0]();
check('file-auth must terminate the request', false);
