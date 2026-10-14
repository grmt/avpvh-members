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
define('AVPVH_TENANT_CONFIG', [
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
function register_activation_hook(): void { $GLOBALS['activation_hooks']++; }
function add_action(string $hook): void { $GLOBALS['hooks'][] = $hook; }
function add_filter(string $hook): void { $GLOBALS['hooks'][] = $hook; }

require dirname(__DIR__) . '/avpvh-members.php';

$failures = 0;
foreach ([
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
