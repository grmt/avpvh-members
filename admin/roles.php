<?php
defined('ABSPATH') || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- this is a single-execution admin-page template (included once per request via AVPVH_Admin::render_*()), not shared library code; its top-level variables are effectively function-local to this one include, not a real global-namespace collision risk
if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('bestuur')) {
    wp_die('Geen toegang.');
}

$delegations = AVPVH_Roles::get_active_delegations();
$bestuur_members = AVPVH_Roles::get_role_holders('bestuur');
$active_members = AVPVH_Roles::current_user_is_chair() ? AVPVH_DB::get_members(['status' => 'active']) : [];

$role_label = [
    'bestuur'       => 'Bestuur',
    'voorzitter'    => 'Voorzitter',
    'secretaris'    => 'Secretaris',
    'penningmeester' => 'Penningmeester',
    AVPVH_Roles::IT_ROLE => 'IT-beheerder',
];
?>
<div class="wrap">
    <h1>Rollen &amp; delegatie</h1>

    <?php if (isset($_GET['delegate_ok'])) : ?>
        <div class="notice notice-success"><p>Delegatie aangemaakt.</p></div>
    <?php elseif (isset($_GET['delegate_error'])) : ?>
        <div class="notice notice-error"><p>Delegatie kon niet worden aangemaakt — controleer de invoer, of je hebt zelf niet de rechten om deze rol te delegeren.</p></div>
    <?php elseif (isset($_GET['revoke_ok'])) : ?>
        <div class="notice notice-success"><p>Delegatie ingetrokken.</p></div>
    <?php endif; ?>

    <h2>Huidige rolhouders (LLDAP)</h2>
    <p class="description">
        Rollen worden beheerd in LLDAP-groepen. Voorzitter, secretaris en penningmeester tellen automatisch ook als bestuur.
        Iemand toevoegen aan of verwijderen uit een rol kan hier niet — dat doe je in
        <a href="https://leden-admin.avphilipsvanhorne.nl" target="_blank" rel="noopener">het LLDAP-beheer (leden-admin.avphilipsvanhorne.nl)</a>,
        bij de groepen "voorzitter", "secretaris" en "penningmeester".
    </p>
    <table class="wp-list-table widefat striped" style="max-width:600px">
        <thead><tr><th>Rol</th><th>Leden</th></tr></thead>
        <tbody>
        <?php foreach (['voorzitter', 'secretaris', 'penningmeester', 'bestuur'] as $role) :
            $holders = AVPVH_Roles::get_role_holders($role); ?>
            <tr>
                <td><?php echo esc_html($role_label[$role]); ?></td>
                <td><?php echo $holders
                    ? esc_html(implode(', ', array_map(fn($m) => avpvh_format_name($m, 'list'), $holders)))
                    : '—'; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2>Actieve delegaties</h2>
    <table class="wp-list-table widefat striped">
        <thead>
            <tr><th>Rol</th><th>Gedelegeerd aan</th><th>Door</th><th>Tot</th><th>Sinds</th><th></th></tr>
        </thead>
        <tbody>
        <?php if (!$delegations) : ?>
            <tr><td colspan="6">Geen actieve delegaties.</td></tr>
        <?php else : foreach ($delegations as $d) :
            $to     = AVPVH_DB::get_member((int) $d->delegated_to_member_id);
            $by     = AVPVH_DB::get_member((int) $d->delegated_by_member_id);
            ?>
            <tr>
                <td><?php echo esc_html($role_label[$d->role] ?? $d->role); ?></td>
                <td><?php echo esc_html($to ? avpvh_format_name($to, 'list') : '#' . $d->delegated_to_member_id); ?></td>
                <td><?php echo esc_html($by ? avpvh_format_name($by, 'list') : '#' . $d->delegated_by_member_id); ?></td>
                <td><?php echo $d->ends_at ? esc_html(wp_date('D d M Y H:i', strtotime($d->ends_at))) : 'Onbepaalde tijd'; ?></td>
                <td><?php echo esc_html(wp_date('D d M Y H:i', strtotime($d->created_at))); ?></td>
                <td>
                    <?php if ($d->role !== AVPVH_Roles::IT_ROLE || AVPVH_Roles::current_user_is_chair()) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                        <?php wp_nonce_field('avpvh_revoke_delegation'); ?>
                        <input type="hidden" name="action" value="avpvh_revoke_delegation">
                        <input type="hidden" name="delegation_id" value="<?php echo esc_attr($d->id); ?>">
                        <button type="submit" class="button button-small" onclick="return confirm('Delegatie intrekken?');">Intrekken</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

    <h2>Nieuwe delegatie</h2>
    <p class="description">Tijdelijk delegeren (bijv. tijdens kamp, of secretariaat overdragen aan een ander bestuurslid). Laat "Tot" leeg voor onbepaalde tijd.</p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('avpvh_delegate_role'); ?>
        <input type="hidden" name="action" value="avpvh_delegate_role">
        <table class="form-table">
            <tr>
                <th><label for="role">Rol</label></th>
                <td>
                    <select name="role" id="role" required>
                        <option value="voorzitter">Voorzitter</option>
                        <option value="secretaris">Secretaris</option>
                        <option value="penningmeester">Penningmeester</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="delegated_to_member_id">Delegeren aan</label></th>
                <td>
                    <select name="delegated_to_member_id" id="delegated_to_member_id" required style="min-width:300px">
                        <option value="">— Kies bestuurslid —</option>
                        <?php foreach ($bestuur_members as $m) : ?>
                            <option value="<?php echo esc_attr($m->id); ?>"><?php echo esc_html(avpvh_format_name($m, 'list')); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="ends_at">Tot (optioneel)</label></th>
                <td><input type="datetime-local" id="ends_at" name="ends_at"></td>
            </tr>
        </table>
        <?php submit_button('Delegeren'); ?>
    </form>

    <?php if (AVPVH_Roles::current_user_is_chair()) : ?>
    <h2>IT-beheerder aanwijzen</h2>
    <p class="description">Alleen de voorzitter kan deze rol toekennen of intrekken. Welke beheerpagina's de rol mag gebruiken stel je in bij <a href="<?php echo esc_url(add_query_arg(['page' => 'avpvh-page-permissions'], admin_url('admin.php'))); ?>">Paginarechten</a>. Inlogidentiteiten en authenticatie-instellingen zijn nooit onderdeel van deze rol.</p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('avpvh_delegate_role'); ?>
        <input type="hidden" name="action" value="avpvh_delegate_role">
        <input type="hidden" name="role" value="<?php echo esc_attr(AVPVH_Roles::IT_ROLE); ?>">
        <table class="form-table">
            <tr>
                <th><label for="it_delegated_to_member_id">Aanwijzen</label></th>
                <td>
                    <select name="delegated_to_member_id" id="it_delegated_to_member_id" required style="min-width:300px">
                        <option value="">— Kies lid —</option>
                        <?php foreach ($active_members as $m) : ?>
                            <option value="<?php echo esc_attr($m->id); ?>"><?php echo esc_html(avpvh_format_name($m, 'list')); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="it_ends_at">Tot (optioneel)</label></th>
                <td><input type="datetime-local" id="it_ends_at" name="ends_at"></td>
            </tr>
        </table>
        <?php submit_button('IT-beheerder aanwijzen'); ?>
    </form>
    <?php endif; ?>
</div>
