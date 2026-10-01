<?php
defined('ABSPATH') || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- this is a single-execution admin-page template (included once per request via AVPVH_Admin::render_*()), not shared library code; its top-level variables are effectively function-local to this one include, not a real global-namespace collision risk
if (!AVPVH_Roles::can_manage_roles()) {
    wp_die('Geen toegang.');
}

$delegations = AVPVH_Roles::get_active_delegations();
$bestuur_members = AVPVH_Roles::get_role_holders('bestuur');

$role_label = [
    'bestuur'       => 'Bestuur',
    'voorzitter'    => 'Voorzitter',
    'secretaris'    => 'Secretaris',
    'penningmeester' => 'Penningmeester',
];
?>
<div class="wrap">
    <h1>Rollen &amp; delegatie</h1>

    <?php if (isset($_GET['delegate_ok'])) : ?>
        <div class="notice notice-success"><p>Delegatie aangemaakt.</p></div>
    <?php elseif (isset($_GET['delegate_needs_end'])) : ?>
        <div class="notice notice-error"><p>Delegeren aan iemand die geen bestuurslid is kan alleen tijdelijk: vul bij "Tot" een einddatum in.</p></div>
    <?php elseif (isset($_GET['delegate_past'])) : ?>
        <div class="notice notice-error"><p>De einddatum bij "Tot" moet in de toekomst liggen.</p></div>
    <?php elseif (isset($_GET['delegate_error'])) : ?>
        <div class="notice notice-error"><p>Delegatie kon niet worden aangemaakt — controleer de invoer, of je hebt zelf niet de rechten om deze rol te delegeren.</p></div>
    <?php elseif (isset($_GET['revoke_ok'])) : ?>
        <div class="notice notice-success"><p>Delegatie ingetrokken.</p></div>
    <?php elseif (isset($_GET['appoint_ok'])) :
        $appointed_role = sanitize_key(wp_unslash($_GET['appoint_ok'])); ?>
        <div class="notice notice-success"><p>Nieuwe <?php echo esc_html(strtolower($role_label[$appointed_role] ?? 'rolhouder')); ?> aangewezen.</p></div>
    <?php elseif (isset($_GET['bestuur_added'])) : ?>
        <div class="notice notice-success"><p>Bestuurslid toegevoegd.</p></div>
    <?php elseif (isset($_GET['bestuur_removed'])) : ?>
        <div class="notice notice-success"><p>Bestuurslid verwijderd.</p></div>
    <?php elseif (isset($_GET['bestuur_error'])) : ?>
        <div class="notice notice-error"><p>Bestuur wijzigen is niet gelukt. Alleen actieve leden met een eigen login kunnen bestuurslid worden.</p></div>
    <?php elseif (isset($_GET['appoint_error'])) : ?>
        <div class="notice notice-error"><p>Aanwijzen is niet gelukt. Kies een rol en een lid en vink de bevestiging aan; lukt het dan nog niet, neem contact op met de beheerder.</p></div>
    <?php endif; ?>

    <h2>Huidige rolhouders (LLDAP)</h2>
    <p class="description">
        Rollen worden beheerd in LLDAP-groepen. Voorzitter, secretaris en penningmeester tellen automatisch ook als bestuur.
        Een nieuwe voorzitter, secretaris of penningmeester wijs je hieronder aan bij "Rolhouder aanwijzen"; bestuursleden toevoegen of verwijderen doe je bij "Bestuursleden".
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

    <?php if (AVPVH_Roles::can_appoint_officers()) : ?>
        <h2>Rolhouder aanwijzen</h2>
        <p class="description">
            Wijs een nieuwe voorzitter, secretaris of penningmeester aan. Diegene krijgt de rol, de huidige rolhouder raakt hem kwijt
            en actieve delegaties van die rol worden beëindigd. Dit is blijvend, geen tijdelijke delegatie.
        </p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('avpvh_appoint_officer'); ?>
            <input type="hidden" name="action" value="avpvh_appoint_officer">
            <table class="form-table">
                <tr>
                    <th><label for="appoint_role">Rol</label></th>
                    <td>
                        <select name="role" id="appoint_role" required>
                            <option value="voorzitter">Voorzitter</option>
                            <option value="secretaris">Secretaris</option>
                            <option value="penningmeester">Penningmeester</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="new_holder_id">Nieuwe rolhouder</label></th>
                    <td>
                        <select name="new_holder_id" id="new_holder_id" required style="min-width:300px">
                            <option value="">— Kies lid —</option>
                            <?php foreach (AVPVH_Roles::get_officer_candidates() as $m) : ?>
                                <option value="<?php echo esc_attr($m->id); ?>"><?php echo esc_html(avpvh_format_name($m, 'list')); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Bevestigen</th>
                    <td><label><input type="checkbox" name="confirm_appoint" value="1" required> Ik wijs dit lid aan als nieuwe rolhouder</label></td>
                </tr>
            </table>
            <?php submit_button('Rolhouder aanwijzen', 'secondary'); ?>
        </form>

        <h2>Bestuursleden</h2>
        <p class="description">Bestuursleden moeten actieve leden met een eigen login zijn. Voorzitter, secretaris en penningmeester zijn automatisch bestuurslid via hun rol. Wie uit het bestuur gaat (verwijderd, of als rolhouder vervangen en geen bestuurslid meer) krijgt het kenmerk Oud-bestuurder.</p>
        <?php
        $bestuur_direct_ids = array_map(static fn($m) => (int) $m->id, AVPVH_Roles::get_role_holders('bestuur', false));
        ?>
        <table class="wp-list-table widefat striped" style="max-width:600px">
            <thead><tr><th>Lid</th><th></th></tr></thead>
            <tbody>
            <?php if (!$bestuur_members) : ?>
                <tr><td colspan="2">Geen bestuursleden.</td></tr>
            <?php else : foreach ($bestuur_members as $m) :
                $officer_roles = array_intersect(AVPVH_Roles::OFFICER_ROLES, AVPVH_Roles::get_member_roles((int) $m->id)); ?>
                <tr>
                    <td><?php echo esc_html(avpvh_format_name($m, 'list')); ?></td>
                    <td>
                        <?php if (in_array((int) $m->id, $bestuur_direct_ids, true)) : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                                <?php wp_nonce_field('avpvh_set_bestuur'); ?>
                                <input type="hidden" name="action" value="avpvh_set_bestuur">
                                <input type="hidden" name="op" value="remove">
                                <input type="hidden" name="member_id" value="<?php echo esc_attr($m->id); ?>">
                                <button type="submit" class="button button-small" onclick="return confirm('Uit het bestuur verwijderen? Diegene krijgt het kenmerk Oud-bestuurder.');">Verwijderen</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($officer_roles) : ?>
                            <span class="description">via rol <?php echo esc_html(implode(', ', array_map(static fn($r) => strtolower($role_label[$r]), $officer_roles))); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:1rem">
            <?php wp_nonce_field('avpvh_set_bestuur'); ?>
            <input type="hidden" name="action" value="avpvh_set_bestuur">
            <input type="hidden" name="op" value="add">
            <label for="bestuur_member_id">Bestuurslid toevoegen:</label>
            <select name="member_id" id="bestuur_member_id" required style="min-width:300px">
                <option value="">— Kies lid —</option>
                <?php
                $bestuur_ids = array_map(static fn($m) => (int) $m->id, $bestuur_members);
                foreach (AVPVH_Roles::get_officer_candidates() as $m) :
                    if (in_array((int) $m->id, $bestuur_ids, true)) {
                        continue;
                    } ?>
                    <option value="<?php echo esc_attr($m->id); ?>"><?php echo esc_html(avpvh_format_name($m, 'list')); ?></option>
                <?php endforeach; ?>
            </select>
            <?php submit_button('Toevoegen', 'secondary', 'submit', false); ?>
        </form>
    <?php endif; ?>

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
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                        <?php wp_nonce_field('avpvh_revoke_delegation'); ?>
                        <input type="hidden" name="action" value="avpvh_revoke_delegation">
                        <input type="hidden" name="delegation_id" value="<?php echo esc_attr($d->id); ?>">
                        <button type="submit" class="button button-small" onclick="return confirm('Delegatie intrekken?');">Intrekken</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

    <h2>Verlopen delegaties</h2>
    <p class="description">De laatste 10 verlopen of ingetrokken delegaties.</p>
    <?php $expired = AVPVH_Roles::get_expired_delegations(10); ?>
    <table class="wp-list-table widefat striped">
        <thead>
            <tr><th>Rol</th><th>Gedelegeerd aan</th><th>Door</th><th>Van</th><th>Tot</th></tr>
        </thead>
        <tbody>
        <?php if (!$expired) : ?>
            <tr><td colspan="5">Geen verlopen delegaties.</td></tr>
        <?php else : foreach ($expired as $d) :
            $to = AVPVH_DB::get_member((int) $d->delegated_to_member_id);
            $by = AVPVH_DB::get_member((int) $d->delegated_by_member_id);
            ?>
            <tr>
                <td><?php echo esc_html($role_label[$d->role] ?? $d->role); ?></td>
                <td><?php echo esc_html($to ? avpvh_format_name($to, 'list') : '#' . $d->delegated_to_member_id); ?></td>
                <td><?php echo esc_html($by ? avpvh_format_name($by, 'list') : '#' . $d->delegated_by_member_id); ?></td>
                <td><?php echo esc_html(wp_date('D d M Y H:i', strtotime($d->starts_at))); ?></td>
                <td><?php echo esc_html(wp_date('D d M Y H:i', strtotime($d->ends_at))); ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

    <h2>Nieuwe delegatie</h2>
    <p class="description">
        Tijdelijk delegeren (bijv. tijdens kamp, of secretariaat overdragen aan een ander bestuurslid). Laat "Tot" leeg voor onbepaalde tijd.
        Een rol kan ook tijdelijk worden uitgevoerd door een lid dat geen bestuurslid is; dan is "Tot" verplicht. Diegene krijgt de rechten van de rol, maar wordt geen bestuurslid.
    </p>
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
                        <option value="">— Kies lid —</option>
                        <optgroup label="Bestuursleden">
                            <?php foreach ($bestuur_members as $m) : ?>
                                <option value="<?php echo esc_attr($m->id); ?>"><?php echo esc_html(avpvh_format_name($m, 'list')); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="Overige leden (alleen tijdelijk, Tot verplicht)">
                            <?php
                            $delegate_bestuur_ids = array_map(static fn($m) => (int) $m->id, $bestuur_members);
                            foreach (AVPVH_Roles::get_officer_candidates() as $m) :
                                if (in_array((int) $m->id, $delegate_bestuur_ids, true)) {
                                    continue;
                                } ?>
                                <option value="<?php echo esc_attr($m->id); ?>"><?php echo esc_html(avpvh_format_name($m, 'list')); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="ends_at">Tot</label></th>
                <td>
                    <input type="datetime-local" id="ends_at" name="ends_at">
                    <p class="description">Optioneel voor bestuursleden, verplicht voor overige leden.</p>
                </td>
            </tr>
        </table>
        <?php submit_button('Delegeren'); ?>
    </form>
</div>
