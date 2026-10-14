<?php
/**
 * Read-only check of a tenant site's configuration, also while the plugin
 * is still inactive. Validates wp-content/avpvh-tenant.php and asks
 * Authelia's auth-request endpoint about the identity probe URL without a
 * session: a reachable, correctly configured Authelia answers 401 (or 302).
 * A 200 means the probe path is not protected by a one_factor rule.
 *
 * Run inside the tenant's WP-CLI container:
 *   wp eval-file wp-content/plugins/avpvh-members/includes/tenant/healthcheck.php
 */

defined('ABSPATH') || exit;

$plugin_dir = dirname(__DIR__, 2);
require_once $plugin_dir . '/includes/tenant/class-tenant-config.php';
require_once $plugin_dir . '/includes/tenant/class-tenant-identity.php';

$config = AVPVH_Tenant_Config::current();
if (!$config) {
    fwrite(STDERR, "Geen tenantconfiguratie: dit is (voor de plugin) de AVP-PvH-site.\n");
    exit(1);
}
echo 'Tenant: ' . $config->tenant_id() . "\n";
echo 'Beheergroepen: ' . implode(', ', $config->admin_groups()) . "\n";
echo 'Ledengroep: ' . $config->member_group() . '; inhoudsgroepen: ' . implode(', ', $config->content_groups()) . "\n";
if (!$config->is_valid()) {
    fwrite(STDERR, 'ONGELDIG: ' . implode(', ', $config->errors()) . "\n");
    exit(1);
}
echo "Configuratie geldig.\n";

$pages = 0;
foreach ($config->get('members_only_pages') as $slug) {
    $found = get_posts(['name' => $slug, 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids']);
    echo "Afgeschermde pagina '{$slug}': " . ($found ? implode(',', $found) : 'NIET GEVONDEN') . "\n";
    $pages += count($found);
}

$response = wp_remote_get((string) $config->get('authelia_authz_url'), [
    'timeout'     => 5,
    'redirection' => 0,
    'headers'     => ['X-Original-Method' => 'GET', 'X-Original-URL' => $config->identity_probe_url()],
]);
$code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
echo 'Authelia zonder sessie voor ' . $config->identity_probe_url() . ': '
    . ($code ?: 'onbereikbaar (' . $response->get_error_message() . ')') . "\n";
if (!in_array($code, [401, 302], true)) {
    fwrite(STDERR, "FOUT: verwacht 401/302. Controleer authelia_authz_url en de one_factor-regel voor het probepad.\n");
    exit(1);
}
exit($pages || !$config->get('members_only_pages') ? 0 : 1);
