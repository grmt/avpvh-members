<?php
defined('ABSPATH') || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- this is a single-execution admin-page template (included once per request via AVPVH_Admin::render_*()), not shared library code; its top-level variables are effectively function-local to this one include, not a real global-namespace collision risk
if (!current_user_can('manage_options')) wp_die(esc_html__('Geen toegang.', 'avpvh-members'));

require_once AVPVH_PLUGIN_DIR . 'admin/class-login-attempts-list-table.php';
$table = new AVPVH_Login_Attempts_List_Table();
$table->prepare_items();
?>
<div class="wrap">
    <h1><?php esc_html_e('Loginpogingen', 'avpvh-members'); ?></h1>
    <form method="get" class="avpvh-login-attempts-form">
        <input type="hidden" name="page" value="avpvh-login-attempts">
        <?php $table->search_box(__('Loginpogingen zoeken', 'avpvh-members'), 'avpvh-login-attempts-search'); ?>
        <?php $table->display(); ?>
    </form>
</div>
