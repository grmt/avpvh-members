<?php
defined('ABSPATH') || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- single-execution admin template
if (!AVPVH_Roles::current_user_is_it_admin()) {
    wp_die('Alleen de IT-beheerder kan paginarechten beheren.', 403);
}

$pages = AVPVH_Roles::get_assignable_pages();
$permissions = AVPVH_Roles::get_page_permissions();
$roles = [
    'bestuur' => 'Bestuur',
    'voorzitter' => 'Voorzitter',
    'secretaris' => 'Secretaris',
    'penningmeester' => 'Penningmeester',
    AVPVH_Roles::IT_ROLE => 'IT-beheerder',
];
?>
<div class="wrap">
    <h1>Paginarechten</h1>
    <p>Als IT-beheerder wijs je hier beheerpagina's toe aan functies. WordPress-beheerders houden altijd toegang tot de toegewezen pluginpagina's.</p>
    <div class="notice notice-info inline"><p><strong>Niet toewijsbaar:</strong> authenticatie-instellingen, inlogadressen van leden, LLDAP-groepen, rollen en delegaties. Die blijven met vaste, strengere rechten afgeschermd.</p></div>
    <?php if (!empty($_GET['updated'])) : ?>
        <div class="notice notice-success is-dismissible"><p>Paginarechten opgeslagen.</p></div>
    <?php endif; ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('avpvh_save_page_permissions'); ?>
        <input type="hidden" name="action" value="avpvh_save_page_permissions">
        <table class="wp-list-table widefat striped" style="max-width:1100px">
            <thead>
                <tr>
                    <th>Pagina</th>
                    <?php foreach ($roles as $label) : ?><th><?php echo esc_html($label); ?></th><?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pages as $page => $page_label) : ?>
                    <tr>
                        <th scope="row"><?php echo esc_html($page_label); ?></th>
                        <?php foreach ($roles as $role => $role_label) : ?>
                            <td>
                                <label class="screen-reader-text" for="permission_<?php echo esc_attr($page . '_' . $role); ?>"><?php echo esc_html($page_label . ' voor ' . $role_label); ?></label>
                                <input type="checkbox"
                                    id="permission_<?php echo esc_attr($page . '_' . $role); ?>"
                                    name="permissions[<?php echo esc_attr($page); ?>][]"
                                    value="<?php echo esc_attr($role); ?>"
                                    <?php checked(in_array($role, $permissions[$page] ?? [], true)); ?>>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php submit_button('Paginarechten opslaan'); ?>
    </form>
</div>
