<?php
/**
 * Plugin start on a Rechtspreker tenant site; see avpvh-members.php.
 */

defined('ABSPATH') || exit;

require_once AVPVH_PLUGIN_DIR . 'includes/tenant/class-tenant-identity.php';
require_once AVPVH_PLUGIN_DIR . 'includes/tenant/class-tenant-sso.php';
require_once AVPVH_PLUGIN_DIR . 'includes/tenant/class-tenant-content.php';

$avpvh_i18n = new AVPVH_I18n();

$avpvh_tenant_config = AVPVH_Tenant_Config::current();
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
