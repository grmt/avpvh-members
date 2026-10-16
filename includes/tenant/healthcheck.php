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

// Plain-text output to the terminal, never to a browser.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

$plugin_dir = dirname(__DIR__, 2);
require_once $plugin_dir . '/includes/tenant/class-tenant-config.php';
require_once $plugin_dir . '/includes/tenant/class-tenant-identity.php';
require_once $plugin_dir . '/includes/tenant/class-tenant-directory.php';

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

// Straight from the database: once the plugin is active it hides these
// pages from every query of a visitor without access, WP-CLI included.
global $wpdb;
$pages = 0;
foreach ($config->get('members_only_pages') as $slug) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off check from WP-CLI
    $found = $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = 'page' AND post_status NOT IN ('trash', 'auto-draft', 'inherit')",
        sanitize_title($slug)
    ));
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
$directory = AVPVH_Tenant_Directory::for_config($config);
$directory_ok = true;
if ($directory) {
    $directory_ok = $directory->reachable();
    echo 'Directory (alleen lezen): ' . ($directory_ok ? 'verbonden' : 'NIET bereikbaar of inloggen mislukt') . "\n";
} else {
    echo "Directory: niet ingesteld (inloggen met Google/Microsoft staat uit)\n";
}
$oauth_ok = true;
foreach ($config->oauth_clients() as $provider => $client) {
    $secret = 'secret in het tenantbestand';
    if ($client['client_secret_file'] !== '') {
        $readable = is_readable($client['client_secret_file']);
        $oauth_ok = $oauth_ok && $readable;
        $secret = $readable ? 'secret-bestand leesbaar' : 'secret-bestand NIET leesbaar';
    }
    echo "Inloggen met {$provider}: {$secret}\n";
}
if (!in_array($code, [401, 302], true)) {
    fwrite(STDERR, "FOUT: verwacht 401/302. Controleer authelia_authz_url en de one_factor-regel voor het probepad.\n");
    exit(1);
}
exit(($pages || !$config->get('members_only_pages')) && $directory_ok && $oauth_ok ? 0 : 1);
