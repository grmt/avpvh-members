<?php
defined('ABSPATH') || exit;
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the only request data read here is the "updated" notice flag set by this plugin's own admin-post redirect; saving is the nonce-checked AVPVH_Admin::handle_save_page_permissions()
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- single-execution admin-page template (included once per request via AVPVH_Admin::render_page_permissions())
if (!AVPVH_Roles::can_manage_page_permissions()) {
    wp_die(esc_html__('Geen toegang.', 'avpvh-members'), '', ['response' => 403]);
}

$pages       = AVPVH_Roles::get_assignable_pages();
$permissions = AVPVH_Roles::get_page_permissions();
$roles       = AVPVH_Roles::get_role_labels() + [AVPVH_Roles::IT_ROLE => __('IT-beheerder', 'avpvh-members')];
$roles       = array_intersect_key($roles, array_flip(AVPVH_Roles::PAGE_PERMISSION_ROLES));
?>
<div class="wrap">
    <h1><?php esc_html_e('Paginarechten', 'avpvh-members'); ?></h1>
    <?php if (!empty($_GET['updated'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Paginarechten opgeslagen.', 'avpvh-members'); ?></p></div>
    <?php endif; ?>
    <p><?php esc_html_e('Kies welke functies welke beheerpagina zien. Een tijdelijke delegatie van een functie geeft dezelfde toegang. WordPress-beheerders zien altijd alle pagina\'s. De IT-beheerder is wie in de groep it-beheer zit.', 'avpvh-members'); ?></p>
    <p><?php esc_html_e('Niet toewijsbaar, met vaste rechten: Instellingen, groepen en kenmerken (alleen WordPress-beheerders) en Rollen & delegatie (voorzitter). Leden samenvoegen verwijdert een account en kan niet ongedaan worden gemaakt; wie geen WordPress-beheerder is, kan geen leden met een bestuursfunctie of beheerrechten samenvoegen.', 'avpvh-members'); ?></p>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('avpvh_save_page_permissions'); ?>
        <input type="hidden" name="action" value="avpvh_save_page_permissions">
        <table class="wp-list-table widefat striped" style="max-width:900px">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Pagina', 'avpvh-members'); ?></th>
                    <?php foreach ($roles as $role_label) : ?>
                        <th scope="col"><?php echo esc_html($role_label); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pages as $page => $page_label) : ?>
                    <tr>
                        <th scope="row"><?php echo esc_html($page_label); ?></th>
                        <?php foreach ($roles as $role => $role_label) : ?>
                            <td>
                                <input type="checkbox"
                                    name="permissions[<?php echo esc_attr($page); ?>][]"
                                    value="<?php echo esc_attr($role); ?>"
                                    aria-label="<?php echo esc_attr($page_label . ' – ' . $role_label); ?>"
                                    <?php checked(in_array($role, $permissions[$page] ?? [], true)); ?>>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php submit_button(__('Paginarechten opslaan', 'avpvh-members')); ?>
    </form>
</div>
