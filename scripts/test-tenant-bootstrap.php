<?php
declare(strict_types=1);

/**
 * With AVPVH_TENANT_CONFIG defined, the plugin loads only the tenant layer:
 * no AVP-PvH classes, no activation hook that would create pvh_avm_* tables.
 *
 * Run:
 *   php scripts/test-tenant-bootstrap.php
 */

define('ABSPATH', __DIR__ . '/');
$test_locale = $argv[1] ?? '';
define('AVPVH_TENANT_CONFIG', [
    'plugin_locale'            => $test_locale,
    'tenant_id'               => 'rsp_vve_vp4042',
    'ancestors'               => ['rsp', 'rsp_vve'],
    'canonical_host'          => 'site.example.invalid',
    'authelia_url'            => 'https://auth.example.invalid',
    'authelia_authz_url'      => 'http://authelia:9091/api/authz/auth-request',
    'authelia_session_cookie' => 'test_session',
    'member_group'            => 'vp4042-leden',
    'admin_group'             => 'rsp-vve-vp4042-admins',
    'ancestor_admin_groups'   => ['rsp-admins', 'rsp-vve-admins'],
]);

$activation_hooks = 0;
$hooks = [];
function plugin_dir_path(string $file): string { return dirname($file) . '/'; }
function home_url(string $path = '/'): string { return 'https://site.example.invalid/' . ltrim($path, '/'); }
function wp_parse_url(string $url, int $component = -1): mixed { return parse_url($url, $component); }
function register_activation_hook(string $file, mixed $callback): void { $GLOBALS['activation_hooks']++; }
function add_action(string $hook, mixed ...$args): void { $GLOBALS['hooks'][] = $hook; $GLOBALS['callbacks'][$hook][] = $args[0]; }
function add_filter(string $hook, mixed ...$args): void { add_action($hook, ...$args); }
function plugin_basename(string $path): string { return basename(dirname($path)) . '/' . basename($path); }
function load_plugin_textdomain(string $domain, bool $deprecated, string $path): void { $GLOBALS['textdomain'] = [$domain, $path]; }
class MO {
    public function import_from_file(string $path): void { $GLOBALS['mo_loads'][] = $path; }
    public function translate(string $text): string { return 'translated:' . $text; }
}

require_once dirname(__DIR__) . '/avpvh-members.php';

$locale_ok = false;
if ($test_locale !== '') {
    $translate = $GLOBALS['callbacks']['gettext_avpvh-members'][0];
    $locale_ok = $translate('fallback', 'Fixture') === 'translated:Fixture'
        && $translate('fallback', 'Second') === 'translated:Second'
        && count($GLOBALS['mo_loads']) === 1 && str_ends_with($GLOBALS['mo_loads'][0], 'avpvh-members-nl_NL.mo');
} else {
    foreach ($GLOBALS['callbacks']['init'] as $callback) {
        if ($callback instanceof Closure) { $callback(); }
    }
    $locale_ok = ($GLOBALS['textdomain'] ?? []) === ['avpvh-members', 'avpvh-members/languages'];
}
$failures = 0;
foreach ([
    'locale setup applies to plugin text'   => $locale_ok,
    'tenant SSO layer is loaded'            => class_exists('AVPVH_Tenant_SSO', false),
    'AVP member database class is not loaded' => !class_exists('AVPVH_DB', false),
    'AVP access class is not loaded'        => !class_exists('AVPVH_Access', false),
    'no activation hook (no table install)' => $activation_hooks === 0,
    'login callback is hooked'              => in_array('init', $hooks, true),
    'no admin notice for a valid config'    => !in_array('admin_notices', $hooks, true),
] as $label => $ok) {
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
    $failures += $ok ? 0 : 1;
}
exit($failures ? 1 : 0);
