<?php
defined('ABSPATH') || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- this is a single-execution admin-page template (included once per request via AVPVH_Admin::render_*()), not shared library code; its top-level variables are effectively function-local to this one include, not a real global-namespace collision risk
if (!AVPVH_Roles::current_user_can_access_page('login_attempts')) wp_die('Geen toegang.');

require_once AVPVH_PLUGIN_DIR . 'admin/class-login-attempts-list-table.php';
$table = new AVPVH_Login_Attempts_List_Table();
$table->prepare_items();
?>
<div class="wrap">
    <h1>Loginpogingen</h1>
    <form method="get">
        <input type="hidden" name="page" value="avpvh-login-attempts">
        <?php $table->search_box('Loginpogingen zoeken', 'avpvh-login-attempts-search'); ?>
        <?php $table->display(); ?>
    </form>
</div>
