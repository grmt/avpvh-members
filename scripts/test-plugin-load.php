<?php
declare(strict_types=1);

/**
 * The plugin file loads on a normal (non-tenant) site: every class is
 * defined and the admin parts, including the contribution screens, hook
 * in. Counterpart of test-tenant-bootstrap.php.
 *
 * Run:
 *   php scripts/test-plugin-load.php
 */

define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('MINUTE_IN_SECONDS', 60);
define('WP_CONTENT_DIR', __DIR__ . '/no-such-dir');

$GLOBALS['hooks'] = [];
function plugin_dir_path(string $file): string { return dirname($file) . '/'; }
function register_activation_hook(...$args): void {}
function add_action(string $hook, ...$args): void { $GLOBALS['hooks'][] = $hook; }
function add_filter(string $hook, ...$args): void { $GLOBALS['hooks'][] = $hook; }
function remove_action(...$args): void {}
function add_shortcode(...$args): void {}
function wp_parse_url(string $url, int $component = -1): mixed { return parse_url($url, $component); }
function home_url(string $path = '/'): string { return 'https://example.test' . $path; }
function is_admin(): bool { return false; }
function get_option(string $name, mixed $default = false): mixed { return $default; }
function __($s, $d = '') { return $s; }

require_once dirname(__DIR__) . '/avpvh-members.php';

foreach ([
    'member database class loaded'      => class_exists('AVPVH_DB', false),
    'contribution classes loaded'       => class_exists('AVPVH_Fees', false) && class_exists('AVPVH_Fees_Admin', false),
    'contribution handlers hooked'      => in_array('admin_post_avpvh_generate_fees', $GLOBALS['hooks'], true)
        && in_array('admin_post_avpvh_save_fee', $GLOBALS['hooks'], true)
        && in_array('admin_post_avpvh_delete_fee', $GLOBALS['hooks'], true),
    'old mark-paid handler is gone'     => !in_array('admin_post_avpvh_mark_fee_paid', $GLOBALS['hooks'], true),
] as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
}
echo PHP_EOL . 'Done.' . PHP_EOL;
