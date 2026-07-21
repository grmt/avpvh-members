<?php
/**
 * Plugin Name: AV-PvH Members
 * Description: Member login, access control, fee tracking and admin for AV Philips van Horne.
 * Version:     1.0.20+24c5de1
 * Author:      grmt
 * Text Domain: avpvh-members
 */

defined('ABSPATH') || exit;

define('AVPVH_PLUGIN_DIR', plugin_dir_path(__FILE__));
// LLDAP database name in the shared MariaDB instance. Override in wp-config.php if needed.
if (!defined('AVPVH_LLDAP_DB')) {
    define('AVPVH_LLDAP_DB', 'lldap');
}

require_once AVPVH_PLUGIN_DIR . 'includes/class-db.php';
require_once AVPVH_PLUGIN_DIR . 'includes/class-registration-db.php';
require_once AVPVH_PLUGIN_DIR . 'includes/class-google-sheets-sync.php';
require_once AVPVH_PLUGIN_DIR . 'includes/class-google-sheets-auth.php';
require_once AVPVH_PLUGIN_DIR . 'includes/class-registration-form.php';
require_once AVPVH_PLUGIN_DIR . 'includes/class-member-profile-form.php';
require_once AVPVH_PLUGIN_DIR . 'includes/class-lldap.php';
require_once AVPVH_PLUGIN_DIR . 'includes/class-access.php';
require_once AVPVH_PLUGIN_DIR . 'includes/class-nav-auth.php';
require_once AVPVH_PLUGIN_DIR . 'includes/class-oauth.php';
require_once AVPVH_PLUGIN_DIR . 'includes/class-ledenlijst.php';
require_once AVPVH_PLUGIN_DIR . 'includes/class-kamp-overzicht.php';
require_once AVPVH_PLUGIN_DIR . 'includes/class-directory-consent.php';
require_once AVPVH_PLUGIN_DIR . 'includes/class-fee-popup.php';
require_once AVPVH_PLUGIN_DIR . 'includes/class-admin.php';
require_once AVPVH_PLUGIN_DIR . 'includes/class-media-protection.php';

register_activation_hook(__FILE__, ['AVPVH_DB', 'install']);
add_action('plugins_loaded', ['AVPVH_DB', 'maybe_upgrade']);

add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('avpvh', plugin_dir_url(__FILE__) . 'assets/avpvh.css', [], '1.0');
});

add_action('after_setup_theme', function () {
    if (!current_user_can('edit_posts')) {
        show_admin_bar(false);
    }
});

add_action('login_init', function () {
    wp_safe_redirect(home_url('/avpvh-login/'));
    exit;
});

add_filter('logout_url', function (): string {
    return rest_url('avpvh/v1/logout');
});


new AVPVH_Access();
new AVPVH_Nav_Auth();
new AVPVH_OAuth();
new AVPVH_Ledenlijst();
new AVPVH_Kamp_Overzicht();
new AVPVH_Directory_Consent();
new AVPVH_Fee_Popup();
new AVPVH_Registration_Form();
new AVPVH_Member_Profile_Form();
new AVPVH_Admin();
new AVPVH_Media_Protection();
