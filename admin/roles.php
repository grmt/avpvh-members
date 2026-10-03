<?php
defined('ABSPATH') || exit;
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the only request data read on this page are the notice flags (delegate_ok, appoint_ok, ...) set by this plugin's own admin-post redirects, to choose which message to show; every action itself is a nonce-checked admin-post handler in AVPVH_Admin
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- this is a single-execution admin-page template (included once per request via AVPVH_Admin::render_*()), not shared library code; its top-level variables are effectively function-local to this one include, not a real global-namespace collision risk
if (!AVPVH_Roles::can_view_roles_page()) {
    wp_die('Geen toegang.');
}
$can_manage_delegations = AVPVH_Roles::can_manage_roles();

$delegations = AVPVH_Roles::get_active_delegations();
$bestuur_members = AVPVH_Roles::get_role_holders('bestuur');

$role_label = [
    'bestuur'       => 'Bestuur',
    'voorzitter'    => 'Voorzitter',
    'secretaris'    => 'Secretaris',
    'penningmeester' => 'Penningmeester',
];
?>
<style>
    .avpvh-roles-section { margin: 1.5rem 0; }
    .avpvh-roles-section > summary { cursor: pointer; }
    .avpvh-roles-section > summary h2 { display: inline; margin: 0; }
</style>
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
        <div class="notice notice-success"><p>Nieuwe <?php echo esc_html(strtolower($role_label[$appointed_role] ?? 'functionaris')); ?> aangewezen.</p></div>
    <?php elseif (isset($_GET['bestuur_added'])) : ?>
        <div class="notice notice-success"><p>Bestuurslid toegevoegd.</p></div>
    <?php elseif (isset($_GET['bestuur_removed'])) : ?>
        <div class="notice notice-success"><p>Bestuurslid verwijderd.</p></div>
    <?php elseif (isset($_GET['bestuur_error'])) : ?>
        <div class="notice notice-error"><p>Bestuur wijzigen is niet gelukt. Alleen actieve leden met een eigen login kunnen bestuurslid worden.</p></div>
    <?php elseif (isset($_GET['self_delegate_ok'])) : ?>
        <div class="notice notice-success"><p>Je bent tijdelijk secretaris tot de gekozen einddatum.</p></div>
    <?php elseif (isset($_GET['self_delegate_error'])) : ?>
        <div class="notice notice-error"><p>Tijdelijk secretaris worden is niet gelukt: kies een einddatum in de toekomst, uiterlijk <?php echo (int) AVPVH_Roles::SELF_DELEGATION_MAX_HOURS / 24; ?> dagen vanaf nu.</p></div>
    <?php elseif (isset($_GET['appoint_needs_exception'])) : ?>
        <div class="notice notice-error"><p>Dit lid is geen bestuurslid. Wil je toch iemand buiten het bestuur aanwijzen, vink dan "Lid is geen bestuurslid (uitzondering)" aan.</p></div>
    <?php elseif (isset($_GET['step_down_ok'])) :
        $stepped_role = sanitize_key(wp_unslash($_GET['step_down_ok'])); ?>
        <div class="notice notice-warning"><p>Functie <?php echo esc_html(strtolower($role_label[$stepped_role] ?? '')); ?> neergelegd; diegene blijft bestuurslid. De functie is nu niet ingevuld tot er iemand wordt aangewezen.</p></div>
    <?php elseif (isset($_GET['step_down_error'])) : ?>
        <div class="notice notice-error"><p>Aftreden is niet gelukt; neem contact op met de beheerder.</p></div>
    <?php elseif (isset($_GET['appoint_error'])) : ?>
        <div class="notice notice-error"><p>Aanwijzen is niet gelukt. Kies een rol en een lid en vink de bevestiging aan; lukt het dan nog niet, neem contact op met de beheerder.</p></div>
    <?php endif; ?>

    <?php if (isset($_GET['appoint_ok']) || isset($_GET['bestuur_added']) || isset($_GET['bestuur_removed']) || isset($_GET['step_down_ok'])) : ?>
        <div class="notice notice-warning">
            <p>
                <strong>Vergeet de KVK niet:</strong> een bestuurswissel moet binnen een week worden doorgegeven aan de KVK
                (<a href="https://www.kvk.nl/wijzigen/bestuurswissel-stichting-of-vereniging/" target="_blank" rel="noopener">bestuurswissel doorgeven</a>,
                via Mijn KVK; nieuwe bestuursleden ondertekenen met DigiD), en daarna in het
                <a href="https://www.kvk.nl/veilig-zakendoen/bestuurswissel-pas-de-ubo-registratie-aan/" target="_blank" rel="noopener">UBO-register</a>.
                Dit gaat niet automatisch.
            </p>
        </div>
    <?php endif; ?>

    <details class="avpvh-roles-section" open>
        <summary><h2>Dagelijks bestuur</h2></summary>
    <p class="description">
        Voorzitter, secretaris en penningmeester vormen het dagelijks bestuur. Wie aftreedt blijft gewoon bestuurslid; de functie is dan tijdelijk niet ingevuld.
        Treedt de voorzitter af, dan kan alleen de beheerder een nieuwe voorzitter aanwijzen.
        Een nieuwe voorzitter, secretaris of penningmeester wijs je hieronder aan bij "Bestuursfuncties aanwijzen"; bestuursleden toevoegen of verwijderen doe je bij "Bestuursleden".
    </p>
    <table class="wp-list-table widefat striped" style="max-width:600px">
        <thead><tr><th>Rol</th><th>Leden</th></tr></thead>
        <tbody>
        <?php foreach (['voorzitter', 'secretaris', 'penningmeester'] as $role) :
            $holders = AVPVH_Roles::get_role_holders($role); ?>
            <tr>
                <td><?php echo esc_html($role_label[$role]); ?></td>
                <td>
                    <?php if (!$holders) : ?>
                        <em>niet ingevuld</em>
                    <?php endif; ?>
                    <?php foreach ($holders as $holder) : ?>
                        <?php echo esc_html(avpvh_format_name($holder, 'list')); ?>
                        <?php if (AVPVH_Roles::can_step_down($role, (int) $holder->id)) : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;margin-left:.5rem">
                                <?php wp_nonce_field('avpvh_step_down'); ?>
                                <input type="hidden" name="action" value="avpvh_step_down">
                                <input type="hidden" name="role" value="<?php echo esc_attr($role); ?>">
                                <input type="hidden" name="member_id" value="<?php echo esc_attr($holder->id); ?>">
                                <button type="submit" class="button button-small" onclick="return confirm('<?php echo esc_js($role === 'voorzitter' ? 'Voorzitterschap neerleggen? Diegene blijft bestuurslid. Daarna kan alleen de beheerder een nieuwe voorzitter aanwijzen.' : 'Functie neerleggen? Diegene blijft bestuurslid; de functie is daarna niet ingevuld.'); ?>');">Aftreden</button>
                            </form>
                        <?php endif; ?>
                        <br>
                    <?php endforeach; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <tr>
            <td>Alle bestuursleden</td>
            <td><?php echo esc_html(implode(', ', array_map(fn($m) => avpvh_format_name($m, 'list'), $bestuur_members)) ?: '—'); ?></td>
        </tr>
        </tbody>
    </table>
    </details>

    <?php if (AVPVH_Roles::can_self_delegate_secretaris()) :
        $self_min = wp_date('Y-m-d\TH:i', time() + 300);
        $self_max = wp_date('Y-m-d\TH:i', time() + AVPVH_Roles::SELF_DELEGATION_MAX_HOURS * HOUR_IN_SECONDS); ?>
    <details class="avpvh-roles-section">
        <summary><h2>Tijdelijk secretaris (penningmeester)</h2></summary>
        <p class="description">Als penningmeester kun je jezelf tijdelijk de rechten van de secretaris geven, voor maximaal <?php echo (int) AVPVH_Roles::SELF_DELEGATION_MAX_HOURS / 24; ?> dagen. Langer of voor iemand anders gaat via de voorzitter.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('avpvh_self_delegate_secretaris'); ?>
            <input type="hidden" name="action" value="avpvh_self_delegate_secretaris">
            <label for="self_ends_at">Tot:</label>
            <input type="datetime-local" id="self_ends_at" name="ends_at" required min="<?php echo esc_attr($self_min); ?>" max="<?php echo esc_attr($self_max); ?>">
            <?php submit_button('Tijdelijk secretaris worden', 'secondary', 'submit', false); ?>
        </form>
    </details>

    <?php endif; ?>

    <?php if (AVPVH_Roles::can_appoint_officers()) : ?>
        <details class="avpvh-roles-section">
            <summary><h2>Bestuursfuncties aanwijzen</h2></summary>
        <p class="description">
            Wijs een nieuwe voorzitter, secretaris of penningmeester aan, ook voor een rol die niet ingevuld is. Diegene krijgt de rol,
            wie de functie nu heeft raakt die kwijt en actieve delegaties van die functie worden beëindigd. Dit is blijvend, geen tijdelijke delegatie.
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
                    <th><label for="new_holder_id">Lid</label></th>
                    <td>
                        <select name="new_holder_id" id="new_holder_id" required style="min-width:300px">
                            <option value="">— Kies lid —</option>
                            <optgroup label="Bestuursleden">
                                <?php foreach ($bestuur_members as $m) : ?>
                                    <option value="<?php echo esc_attr($m->id); ?>"><?php echo esc_html(avpvh_format_name($m, 'list')); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Overige leden (alleen met uitzondering hieronder)">
                                <?php
                                $appoint_bestuur_ids = array_map(static fn($m) => (int) $m->id, $bestuur_members);
                                foreach (AVPVH_Roles::get_officer_candidates() as $m) :
                                    if (in_array((int) $m->id, $appoint_bestuur_ids, true)) {
                                        continue;
                                    } ?>
                                    <option value="<?php echo esc_attr($m->id); ?>"><?php echo esc_html(avpvh_format_name($m, 'list')); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Uitzondering</th>
                    <td>
                        <label><input type="checkbox" name="allow_non_bestuur" value="1"> Lid is geen bestuurslid (uitzondering)</label>
                        <p class="description">Normaal wordt alleen een bestuurslid voorzitter, secretaris of penningmeester. Vink dit aan om toch een ander lid aan te wijzen; diegene wordt daarmee bestuurslid via de rol.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Aanwijzen', 'secondary'); ?>
        </form>
        </details>

        <details class="avpvh-roles-section">
            <summary><h2>Bestuursleden</h2></summary>
        <p class="description">Bestuursleden moeten actieve leden met een eigen login zijn. Voorzitter, secretaris en penningmeester zijn als dagelijks bestuur automatisch bestuurslid; die vervang je via "Bestuursfuncties aanwijzen". Wie uit het bestuur gaat (verwijderd, of als voorzitter, secretaris of penningmeester vervangen en geen bestuurslid meer) krijgt het kenmerk Oud-bestuurder.</p>
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
                        <?php // Rolhouders stay bestuur through their role, so removing them
                        // from the group would change nothing visible — they're replaced
                        // via "Rolhouder aanwijzen" instead.
                        if (!$officer_roles && in_array((int) $m->id, $bestuur_direct_ids, true)) : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                                <?php wp_nonce_field('avpvh_set_bestuur'); ?>
                                <input type="hidden" name="action" value="avpvh_set_bestuur">
                                <input type="hidden" name="op" value="remove">
                                <input type="hidden" name="member_id" value="<?php echo esc_attr($m->id); ?>">
                                <button type="submit" class="button button-small" onclick="return confirm('Uit het bestuur verwijderen? Diegene krijgt het kenmerk Oud-bestuurder.');">Verwijderen</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($officer_roles) : ?>
                            <span class="description"><?php echo esc_html(implode(', ', array_map(static fn($r) => $role_label[$r], $officer_roles))); ?> (dagelijks bestuur)</span>
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
        </details>

    <?php endif; ?>

    <?php if ($can_manage_delegations) : ?>
    <details class="avpvh-roles-section">
        <summary><h2>Actieve delegaties</h2></summary>
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
                <td><?php echo esc_html($by ? avpvh_format_name($by, 'list') : ((int) $d->delegated_by_member_id === 0 ? 'Beheerder' : '#' . $d->delegated_by_member_id)); ?></td>
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
    </details>

    <details class="avpvh-roles-section">
        <summary><h2>Verlopen delegaties</h2></summary>
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
                <td><?php echo esc_html($by ? avpvh_format_name($by, 'list') : ((int) $d->delegated_by_member_id === 0 ? 'Beheerder' : '#' . $d->delegated_by_member_id)); ?></td>
                <td><?php echo esc_html(wp_date('D d M Y H:i', strtotime($d->starts_at))); ?></td>
                <td><?php echo esc_html(wp_date('D d M Y H:i', strtotime($d->ends_at))); ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </details>

    <details class="avpvh-roles-section">
        <summary><h2>Nieuwe delegatie</h2></summary>
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
    </details>

    <?php endif; ?>
</div>
