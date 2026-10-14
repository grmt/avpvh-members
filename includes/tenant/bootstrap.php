<?php
/**
 * Plugin start on a Rechtspreker tenant site; see avpvh-members.php.
 */

defined('ABSPATH') || exit;

require_once AVPVH_PLUGIN_DIR . 'includes/tenant/class-tenant-identity.php';
require_once AVPVH_PLUGIN_DIR . 'includes/tenant/class-tenant-sso.php';
require_once AVPVH_PLUGIN_DIR . 'includes/tenant/class-tenant-content.php';
require_once AVPVH_PLUGIN_DIR . 'includes/tenant/class-tenant-shares.php';

$avpvh_tenant_config = AVPVH_Tenant_Config::current();

// Only the plugin's own texts are translated; the AVP-PvH language switcher
// (AVPVH_I18n) would change the locale of the whole site, so it is not
// loaded. plugin_locale sets the language of these texts only. WordPress
// keeps translations per locale, so that language is applied here directly.
$avpvh_plugin_locale = (string) $avpvh_tenant_config->get('plugin_locale');
if ($avpvh_plugin_locale !== '') {
    add_filter('gettext_avpvh-members', static function (string $translation, string $text) use ($avpvh_plugin_locale): string {
        static $mo = null;
        if ($mo === null) {
            $mo = new MO();
            $mo->import_from_file(AVPVH_PLUGIN_DIR . 'languages/avpvh-members-' . $avpvh_plugin_locale . '.mo');
        }
        return $mo->translate($text);
    }, 10, 2);
} else {
    add_action('init', static function (): void {
        load_plugin_textdomain('avpvh-members', false, dirname(plugin_basename(AVPVH_PLUGIN_DIR . 'avpvh-members.php')) . '/languages');
    });
}
if (!$avpvh_tenant_config->is_valid()) {
    // Fail closed: no login or provisioning with an incomplete or
    // inconsistent configuration. Only the setting names are shown.
    add_action('admin_notices', static function () use ($avpvh_tenant_config): void {
        if (current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>'
                . esc_html(sprintf(
                    /* translators: %s: comma-separated names of invalid settings */
                    __('AV-PvH Members: de tenantconfiguratie is ongeldig (%s). Inloggen via Authelia staat uit.', 'avpvh-members'),
                    implode(', ', $avpvh_tenant_config->errors())
                ))
                . '</p></div>';
        }
    });
    return;
}

$avpvh_tenant_sso = new AVPVH_Tenant_SSO($avpvh_tenant_config);
$avpvh_tenant_content = new AVPVH_Tenant_Content($avpvh_tenant_config, $avpvh_tenant_sso);
$avpvh_tenant_shares = new AVPVH_Tenant_Shares($avpvh_tenant_config, $avpvh_tenant_content);
