<?php
defined('ABSPATH') || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- this is a single-execution admin-page template (included once per request via AVPVH_Admin::render_*()), not shared library code; its top-level variables are effectively function-local to this one include, not a real global-namespace collision risk
if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('secretaris')) wp_die('Geen toegang.');

$member_id = absint(wp_unslash($_GET['id'] ?? 0));
$member    = $member_id ? AVPVH_DB::get_member($member_id) : null;
if (!$member) {
    $search  = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
    $results = $search ? AVPVH_DB::get_members(['search' => $search]) : [];
    ?>
    <div class="wrap">
        <h1>Ledendetail</h1>
        <form method="get">
            <input type="hidden" name="page" value="avpvh-member-detail">
            <p class="search-box">
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Naam of e-mail" autofocus>
                <button type="submit" class="button">Zoeken</button>
            </p>
        </form>
        <?php if ($search && !$results) : ?>
            <p>Geen leden gevonden.</p>
        <?php elseif ($results) : ?>
            <table class="wp-list-table widefat striped" style="max-width:600px">
                <thead><tr><th>Naam</th><th>E-mail</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($results as $r) :
                    $url = add_query_arg(['page' => 'avpvh-member-detail', 'id' => $r->id], admin_url('admin.php'));
                ?>
                    <tr>
                        <td><a href="<?php echo esc_url($url); ?>"><?php echo esc_html(avpvh_format_name($r, 'list')); ?></a></td>
                        <td><?php echo esc_html($r->email); ?></td>
                        <td><?php echo esc_html($r->status); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
    return;
}

$addresses  = AVPVH_DB::get_addresses($member_id);
$today = current_time('Y-m-d');
$current_address_count = count(array_filter(
    $addresses,
    static fn(object $address): bool =>
        (!$address->valid_from || $address->valid_from <= $today)
        && (!$address->valid_until || $address->valid_until >= $today)
));
$activities = AVPVH_DB::get_activities_for_member($member_id);
$fees       = AVPVH_DB::get_fees_for_member($member_id);
$identities = AVPVH_DB::get_member_identities($member_id);
$name_variants = AVPVH_DB::get_member_name_variants($member_id);
$name_aliases = array_values(array_filter(
    $name_variants,
    static fn(object $variant): bool => ($variant->alias_type ?? 'official') !== 'official'
));
$all_flags  = AVPVH_DB::get_all_flags();
$member_flag_ids = wp_list_pluck(AVPVH_DB::get_flags_for_member($member_id), 'id');
$active_tab = sanitize_key(wp_unslash($_GET['tab'] ?? 'contact'));
$updated    = !empty($_GET['updated']);
$created    = !empty($_GET['created']);
$identity_ok = !empty($_GET['identity_ok']);
$identity_deleted = !empty($_GET['identity_deleted']);
$identity_primary = !empty($_GET['identity_primary']);
$identity_error = sanitize_key(wp_unslash($_GET['identity_error'] ?? ''));

$tab_url = fn(string $tab): string => add_query_arg(
    ['page' => 'avpvh-member-detail', 'id' => $member_id, 'tab' => $tab],
    admin_url('admin.php')
);

// Sync-to-LLDAP action — displayName only. 'email' isn't included: $member->email
// is always read straight from LLDAP itself (member_select()'s u.email), so
// sending it back would be a no-op; real changes go through the "E-mail"
// field's own "Wijzigen" button above, or "Maak primair" on an Inlogadres.
// Saving a name change on the profile page now syncs displayName
// automatically (see handle_save_profile()) — this button mainly exists to
// catch up any record that drifted before that existed, or as a manual fallback.
$sync_msg = null;
if (!empty($_GET['sync_lldap']) && check_admin_referer('avpvh_sync_lldap_' . $member_id)) {
    $result = AVPVH_LLDAP::update_user($member->lldap_user_id, [
        'displayName' => avpvh_format_name($member),
    ]);
    $sync_msg = is_wp_error($result) ? $result->get_error_message() : 'Gesynchroniseerd met LLDAP.';
}
?>
<div class="wrap">
    <h1><?php echo esc_html(avpvh_format_name($member, 'list')); ?></h1>
    <a href="<?php echo esc_url(add_query_arg(['page' => 'avpvh-members'], admin_url('admin.php'))); ?>">&larr; Terug naar ledenlijst</a>
    &nbsp;|&nbsp;
    <a href="<?php echo esc_url(add_query_arg(['member_id' => $member_id], home_url('/member-profile/'))); ?>"
       class="button button-small">Bewerk profiel</a>
    &nbsp;|&nbsp;
    <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['page' => 'avpvh-member-detail', 'id' => $member_id, 'tab' => $active_tab, 'sync_lldap' => '1'], admin_url('admin.php')), 'avpvh_sync_lldap_' . $member_id)); ?>"
       class="button button-small" title="Meestal niet nodig — een naamwijziging op het profiel synchroniseert al automatisch. Vooral bedoeld om een record bij te werken dat van vóór die automatische sync dateert.">Sync naar LLDAP</a>

    <?php if ($updated) : ?>
        <div class="notice notice-success is-dismissible"><p>Bijgewerkt.</p></div>
    <?php endif; ?>
    <?php if ($created) : ?>
        <div class="notice notice-success is-dismissible"><p>Nieuw lid aangemaakt.</p></div>
    <?php endif; ?>
    <?php if ($identity_ok) : ?>
        <div class="notice notice-success is-dismissible"><p>E-mailadres gekoppeld.</p></div>
    <?php elseif ($identity_deleted) : ?>
        <div class="notice notice-success is-dismissible"><p>E-mailadres verwijderd.</p></div>
    <?php elseif ($identity_primary) : ?>
        <div class="notice notice-success is-dismissible"><p>Primaire identiteit aangepast.</p></div>
    <?php elseif ($identity_error === 'limiet') : ?>
        <div class="notice notice-error is-dismissible"><p>Dit lid heeft al het maximale aantal van 3 e-mailadressen.</p></div>
    <?php elseif ($identity_error === 'onvolledig') : ?>
        <div class="notice notice-error is-dismissible"><p>Vul een e-mailadres in.</p></div>
    <?php elseif ($identity_error === 'laatste') : ?>
        <div class="notice notice-error is-dismissible"><p>Dit lid heeft nog maar één geverifieerd inlogadres — voeg eerst een tweede toe voordat je er een verwijdert.</p></div>
    <?php elseif (!empty($_GET['address_updated'])) : ?>
        <div class="notice notice-success is-dismissible"><p>Adres bijgewerkt.</p></div>
    <?php elseif (!empty($_GET['address_deleted'])) : ?>
        <div class="notice notice-success is-dismissible"><p>Adres verwijderd.</p></div>
    <?php elseif (!empty($_GET['alias_saved'])) : ?>
        <div class="notice notice-success is-dismissible"><p>Naamvariant opgeslagen.</p></div>
    <?php elseif (!empty($_GET['alias_deleted'])) : ?>
        <div class="notice notice-success is-dismissible"><p>Naamvariant verwijderd.</p></div>
    <?php elseif (!empty($_GET['alias_error'])) : ?>
        <div class="notice notice-error is-dismissible"><p>Naamvariant kon niet worden opgeslagen. Controleer de naam of een bestaande dubbele variant.</p></div>
    <?php elseif (!empty($_GET['flags_saved'])) : ?>
        <div class="notice notice-success is-dismissible"><p>Kenmerken opgeslagen.</p></div>
    <?php elseif (!empty($_GET['email_updated'])) : ?>
        <div class="notice notice-success is-dismissible"><p>E-mailadres bijgewerkt.</p></div>
    <?php elseif (!empty($_GET['email_error'])) : ?>
        <div class="notice notice-error is-dismissible"><p>Kon e-mailadres niet bijwerken (ongeldig adres, of LLDAP-fout).</p></div>
    <?php elseif (!empty($_GET['groups_saved'])) : ?>
        <div class="notice notice-success is-dismissible"><p>Groepen bijgewerkt.</p></div>
    <?php elseif (!empty($_GET['groups_error'])) : ?>
        <div class="notice notice-error is-dismissible"><p>Groepen konden niet (volledig) worden bijgewerkt — zie het serverlog.</p></div>
    <?php endif; ?>
    <?php if ($sync_msg) : ?>
        <div class="notice notice-<?php echo str_contains($sync_msg, 'LLDAP') && !str_contains($sync_msg, 'fout') ? 'success' : 'error'; ?> is-dismissible"><p><?php echo esc_html($sync_msg); ?></p></div>
    <?php endif; ?>

    <nav class="nav-tab-wrapper" style="margin-top:1em">
        <a href="<?php echo esc_url($tab_url('contact')); ?>" class="nav-tab <?php echo $active_tab === 'contact' ? 'nav-tab-active' : ''; ?>">Contact & Adressen</a>
        <a href="<?php echo esc_url($tab_url('activities')); ?>" class="nav-tab <?php echo $active_tab === 'activities' ? 'nav-tab-active' : ''; ?>">Activiteiten</a>
        <a href="<?php echo esc_url($tab_url('fees')); ?>"    class="nav-tab <?php echo $active_tab === 'fees'    ? 'nav-tab-active' : ''; ?>">Contributie</a>
    </nav>

    <?php if ($active_tab === 'contact') : ?>
    <h2>Contactgegevens</h2>
    <table class="form-table">
        <tr><th>LLDAP user_id</th><td><code><?php echo esc_html($member->lldap_user_id); ?></code></td></tr>
        <tr><th>Voornaam</th><td><?php echo esc_html($member->first_name); ?></td></tr>
        <tr><th>Tussenvoegsel</th><td><?php echo esc_html($member->suffix ?: '—'); ?></td></tr>
        <tr><th>Achternaam</th><td><?php echo esc_html($member->last_name); ?></td></tr>
        <tr><th>Paspoortnaam</th><td><?php echo esc_html($member->passport_name ?: '—'); ?></td></tr>
        <tr>
            <th>Voorletters</th>
            <td>
                <?php echo esc_html($member->initials ?: '—'); ?>
                <?php $mismatch = avpvh_initials_mismatch($member); ?>
                <?php if ($mismatch) : ?>
                    <br><span style="color:#b32d2e;font-weight:600">&#9888; Komt niet overeen met de paspoortnaam (die geeft <?php echo esc_html($mismatch); ?>).</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>E-mail</th>
            <td>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:.5rem;align-items:center"
                    onsubmit="return confirm('LLDAP-e-mailadres wijzigen?');">
                    <?php wp_nonce_field('avpvh_update_email'); ?>
                    <input type="hidden" name="action" value="avpvh_update_email">
                    <input type="hidden" name="member_id" value="<?php echo esc_attr($member_id); ?>">
                    <input type="email" name="email" value="<?php echo esc_attr($member->email); ?>" class="regular-text">
                    <button type="submit" class="button button-small">Wijzigen</button>
                </form>
                <p class="description">Dit is het contactadres van het LLDAP-account zelf, los van de Inlogadressen hieronder — al wordt het automatisch bijgewerkt naar het adres dat daar als primair wordt ingesteld. Leeg laten en opslaan zet het om naar een placeholder-adres (<?php echo esc_html($member->lldap_user_id); ?>@avpvh.local), hetzelfde als bij een lid zonder echt e-mailadres.</p>
            </td>
        </tr>
        <tr><th>Status</th><td><?php echo esc_html($member->status); ?></td></tr>
        <tr><th>Telefoon</th><td><?php echo esc_html($member->phone); ?></td></tr>
        <tr><th>Mobiel</th><td><?php echo esc_html($member->mobile); ?></td></tr>
        <tr><th>Noodcontact</th><td><?php echo esc_html($member->emergency_contact); ?></td></tr>
        <tr>
            <th>Geboortedatum</th>
            <td>
                <?php if (!empty($member->birth_date)) : ?>
                    <?php echo esc_html($member->birth_date); ?>
                <?php elseif (!empty($member->birth_year)) : ?>
                    <?php echo esc_html($member->birth_year); ?> <span style="color:#777">(alleen geboortejaar bekend)</span>
                <?php else : ?>
                    &mdash;
                <?php endif; ?>
            </td>
        </tr>
        <tr><th>Scholier/student</th><td><?php echo !empty($member->is_student) ? 'Ja' : 'Nee'; ?></td></tr>
        <tr><th>Lid sinds</th><td><?php echo esc_html($member->joined_year ?: '—'); ?></td></tr>
        <tr><th>Vertrokken</th><td><?php echo esc_html($member->left_year ?: '—'); ?></td></tr>
    </table>

    <h2>Naamvarianten</h2>
    <p class="description">Gebruik alleen expliciet bekende varianten. Als dezelfde variant bij meerdere leden hoort, wordt automatische koppeling geblokkeerd.</p>
    <?php
    $alias_type_labels = [
        'maiden' => 'Geboortenaam',
        'married' => 'Getrouwde naam',
        'nickname' => 'Roepnaam',
        'spelling' => 'Spellingvariant',
        'abbreviation' => 'Afkorting',
        'historical' => 'Historische naam',
    ];
    ?>
    <?php if (!$name_aliases) : ?>
        <p>Nog geen naamvarianten vastgelegd.</p>
    <?php else : ?>
        <?php foreach ($name_aliases as $alias) :
            $alias_conflicts = AVPVH_DB::get_alias_conflicts((int) $alias->id);
        ?>
            <div style="max-width:1000px;margin:1em 0;padding:1em;border:1px solid #c3c4c7;background:#fff">
                <?php if ($alias_conflicts) : ?>
                    <div class="notice notice-warning inline"><p>Deze naamvariant hoort ook bij <?php echo esc_html(count($alias_conflicts)); ?> ander(e) lid/leden. Automatische koppeling wordt daarom geblokkeerd.</p></div>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('avpvh_save_name_alias'); ?>
                    <input type="hidden" name="action" value="avpvh_save_name_alias">
                    <input type="hidden" name="member_id" value="<?php echo esc_attr($member_id); ?>">
                    <input type="hidden" name="alias_id" value="<?php echo esc_attr($alias->id); ?>">
                    <table class="form-table" style="margin:0">
                        <tr>
                            <th>Naam</th>
                            <td>
                                <input name="first_name" value="<?php echo esc_attr($alias->first_name); ?>" placeholder="Voornaam" required>
                                <input name="suffix" value="<?php echo esc_attr($alias->suffix); ?>" placeholder="Tussenvoegsel" style="width:10em">
                                <input name="last_name" value="<?php echo esc_attr($alias->last_name); ?>" placeholder="Achternaam" required>
                            </td>
                        </tr>
                        <tr>
                            <th>Soort en geldigheid</th>
                            <td>
                                <select name="alias_type">
                                    <?php foreach ($alias_type_labels as $type => $label) : ?>
                                        <option value="<?php echo esc_attr($type); ?>" <?php selected($alias->alias_type, $type); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <label>van <input type="date" name="valid_from" value="<?php echo esc_attr($alias->valid_from); ?>"></label>
                                <label>tot <input type="date" name="valid_until" value="<?php echo esc_attr($alias->valid_until); ?>"></label>
                            </td>
                        </tr>
                        <tr><th>Herkomst</th><td><input class="regular-text" name="source" value="<?php echo esc_attr($alias->source); ?>"></td></tr>
                        <tr><th>Notitie</th><td><textarea class="large-text" rows="2" name="note"><?php echo esc_textarea($alias->note); ?></textarea></td></tr>
                    </table>
                    <button type="submit" class="button button-secondary">Naamvariant opslaan</button>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:.5em" onsubmit="return confirm('Deze naamvariant verwijderen?');">
                    <?php wp_nonce_field('avpvh_delete_name_alias'); ?>
                    <input type="hidden" name="action" value="avpvh_delete_name_alias">
                    <input type="hidden" name="member_id" value="<?php echo esc_attr($member_id); ?>">
                    <input type="hidden" name="alias_id" value="<?php echo esc_attr($alias->id); ?>">
                    <button type="submit" class="button-link-delete">Naamvariant verwijderen</button>
                </form>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <h3>Naamvariant toevoegen</h3>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:1000px">
        <?php wp_nonce_field('avpvh_save_name_alias'); ?>
        <input type="hidden" name="action" value="avpvh_save_name_alias">
        <input type="hidden" name="member_id" value="<?php echo esc_attr($member_id); ?>">
        <table class="form-table">
            <tr>
                <th>Naam</th>
                <td>
                    <input name="first_name" placeholder="Voornaam" required>
                    <input name="suffix" placeholder="Tussenvoegsel" style="width:10em">
                    <input name="last_name" placeholder="Achternaam" required>
                </td>
            </tr>
            <tr>
                <th>Soort en geldigheid</th>
                <td>
                    <select name="alias_type">
                        <?php foreach ($alias_type_labels as $type => $label) : ?>
                            <option value="<?php echo esc_attr($type); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label>van <input type="date" name="valid_from"></label>
                    <label>tot <input type="date" name="valid_until"></label>
                </td>
            </tr>
            <tr><th>Herkomst</th><td><input class="regular-text" name="source"></td></tr>
            <tr><th>Notitie</th><td><textarea class="large-text" rows="2" name="note"></textarea></td></tr>
        </table>
        <?php submit_button('Naamvariant toevoegen', 'secondary'); ?>
    </form>

    <h2>Inlogadressen</h2>
    <table class="wp-list-table widefat striped">
        <thead><tr><th>Provider</th><th>E-mail</th><th>Geverifieerd</th><th>Eerste login</th><th>Laatste login</th><th>Primair</th><th>Actie</th></tr></thead>
        <tbody>
        <?php if (!$identities) : ?>
            <tr><td colspan="7">Geen gekoppelde adressen.</td></tr>
        <?php else : foreach ($identities as $identity) :
            $login_stats = AVPVH_DB::get_login_stats_for_email($identity->email);
        ?>
            <tr>
                <td><?php echo esc_html(ucfirst($identity->provider)); ?></td>
                <td><?php echo esc_html($identity->email); ?></td>
                <td>
                    <?php if ($identity->verified_at) : ?>
                        Ja
                    <?php else : ?>
                        <span style="color:#b32d2e;font-weight:600">Nee (door beheerder toegevoegd)</span>
                    <?php endif; ?>
                </td>
                <td><?php echo $login_stats->first_login ? esc_html(wp_date('d-m-Y H:i', strtotime($login_stats->first_login))) : '—'; ?></td>
                <td><?php echo $login_stats->last_login ? esc_html(wp_date('d-m-Y H:i', strtotime($login_stats->last_login))) : '—'; ?></td>
                <td><?php echo $identity->is_primary ? 'Ja' : 'Nee'; ?></td>
                <td>
                    <?php if (!$identity->is_primary) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:.5rem"
                        title="Wordt ook het LLDAP-contactadres hierboven, los van waarmee wordt ingelogd.">
                        <?php wp_nonce_field('avpvh_primary_identity'); ?>
                        <input type="hidden" name="action" value="avpvh_primary_identity">
                        <input type="hidden" name="member_id" value="<?php echo esc_attr($member_id); ?>">
                        <input type="hidden" name="identity_id" value="<?php echo esc_attr($identity->id); ?>">
                        <button type="submit" class="button button-small">Maak primair</button>
                    </form>
                    <?php endif; ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block"
                        onsubmit="return confirm('Dit e-mailadres verwijderen?');">
                        <?php wp_nonce_field('avpvh_delete_identity'); ?>
                        <input type="hidden" name="action" value="avpvh_delete_identity">
                        <input type="hidden" name="member_id" value="<?php echo esc_attr($member_id); ?>">
                        <input type="hidden" name="identity_id" value="<?php echo esc_attr($identity->id); ?>">
                        <button type="submit" class="button button-small">Verwijder</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

    <h3 style="margin-top:1rem">Nieuw e-mailadres koppelen</h3>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="form-table">
        <?php wp_nonce_field('avpvh_add_identity'); ?>
        <input type="hidden" name="action" value="avpvh_add_identity">
        <input type="hidden" name="member_id" value="<?php echo esc_attr($member_id); ?>">
        <table class="form-table">
            <tr>
                <th><label for="identity_email">E-mail</label></th>
                <td>
                    <input type="email" id="identity_email" name="email" class="regular-text" value="">
                    <p class="description">Wordt als niet-geverifieerd toegevoegd — de daadwerkelijke inlogmethode (Google, Microsoft, of e-maillink) wordt vastgesteld zodra het lid er zelf mee inlogt.</p>
                </td>
            </tr>
        </table>
        <?php submit_button('Koppelen', 'secondary'); ?>
    </form>

    <h2>Kenmerken</h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('avpvh_save_member_flags'); ?>
        <input type="hidden" name="action" value="avpvh_save_member_flags">
        <input type="hidden" name="member_id" value="<?php echo esc_attr($member_id); ?>">
        <?php if (!$all_flags) : ?>
            <p class="description">Nog geen kenmerken aangemaakt.</p>
        <?php else : ?>
            <ul style="margin:0 0 1em">
                <?php foreach ($all_flags as $flag) : ?>
                    <li>
                        <label>
                            <input type="checkbox" name="flag_ids[]" value="<?php echo esc_attr($flag->id); ?>"
                                <?php checked(in_array((int) $flag->id, $member_flag_ids, true)); ?>>
                            <?php echo esc_html($flag->label); ?>
                            <?php if ($flag->affects_fees) : ?>
                                <span title="Vrijgesteld van contributie" style="color:#787c82">(vrijgesteld van contributie)</span>
                            <?php endif; ?>
                            <?php if ($flag->sets_inactive) : ?>
                                <span title="Zet status automatisch op inactief" style="color:#b32d2e">(zet op inactief)</span>
                            <?php endif; ?>
                        </label>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php submit_button('Kenmerken opslaan', 'secondary'); ?>
    </form>
    <p class="description">
        Nieuw kenmerk nodig? <a href="<?php echo esc_url(add_query_arg(['page' => 'avpvh-settings'], admin_url('admin.php'))); ?>">Beheer de lijst met kenmerken</a> bij Instellingen.
    </p>

    <h2>Adreshistorie</h2>
    <?php if ($current_address_count > 1) : ?>
        <div class="notice notice-warning inline"><p>Dit lid heeft <?php echo esc_html($current_address_count); ?> overlappende actuele adresregels. Controleer de geldigheidsdatums voordat je een regel als huidig adres gebruikt.</p></div>
    <?php endif; ?>
    <p class="description">Elke keer dat een adres wordt opgeslagen via het profiel komt er een nieuwe rij bij (nooit een wijziging van een bestaande) — hier kun je de geldigheidsdatums van een rij corrigeren of een foutieve/dubbele rij verwijderen.</p>
    <table class="wp-list-table widefat striped">
        <thead><tr><th>Straat</th><th>Nr</th><th>Postcode</th><th>Stad</th><th>Land</th><th>Van</th><th>Tot</th><th></th></tr></thead>
        <tbody>
        <?php if (!$addresses) : ?>
            <tr><td colspan="8">Geen adressen.</td></tr>
        <?php else : foreach ($addresses as $a) : ?>
            <tr>
                <td><?php echo esc_html($a->street); ?></td>
                <td><?php echo esc_html($a->house_number); ?></td>
                <td><?php echo esc_html($a->postal_code); ?></td>
                <td><?php echo esc_html($a->city); ?></td>
                <td><?php echo esc_html($a->country); ?></td>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('avpvh_update_address'); ?>
                    <input type="hidden" name="action" value="avpvh_update_address">
                    <input type="hidden" name="id" value="<?php echo esc_attr($a->id); ?>">
                    <input type="hidden" name="member_id" value="<?php echo esc_attr($member_id); ?>">
                    <td><input type="date" name="valid_from" value="<?php echo esc_attr($a->valid_from); ?>" style="width:9.5em"></td>
                    <td><input type="date" name="valid_until" value="<?php echo esc_attr($a->valid_until); ?>" style="width:9.5em"></td>
                    <td>
                        <button type="submit" class="button button-small">Opslaan</button>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                    <?php wp_nonce_field('avpvh_delete_address'); ?>
                    <input type="hidden" name="action" value="avpvh_delete_address">
                    <input type="hidden" name="id" value="<?php echo esc_attr($a->id); ?>">
                    <input type="hidden" name="member_id" value="<?php echo esc_attr($member_id); ?>">
                    <button type="submit" class="button button-small" onclick="return confirm('Dit adres verwijderen?');">Verwijderen</button>
                </form>
                    </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

    <h2>LLDAP-groepen</h2>
    <p class="description">Groepslidmaatschap regelt echte toegang (bijv. secretaris-rechten, de boek-groep voor "Zoeken in documenten") — los van de kenmerken hierboven, die alleen labels/filters zijn.</p>
    <?php
    $all_groups     = AVPVH_LLDAP::list_groups();
    $current_groups = AVPVH_LLDAP::get_user_groups($member->lldap_user_id);
    ?>
    <?php if (is_wp_error($all_groups) || is_wp_error($current_groups)) : ?>
        <p class="description">Kon groepen niet ophalen uit LLDAP.</p>
    <?php else :
        $current_group_ids = array_map('intval', array_column($current_groups, 'id'));
    ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('avpvh_save_groups'); ?>
            <input type="hidden" name="action" value="avpvh_save_groups">
            <input type="hidden" name="member_id" value="<?php echo esc_attr($member_id); ?>">
            <p>
                <?php foreach ($all_groups as $group) : ?>
                    <label style="display:inline-block;margin-right:1.5rem">
                        <input type="checkbox" name="groups[]" value="<?php echo esc_attr($group['id']); ?>"
                            <?php checked(in_array((int) $group['id'], $current_group_ids, true)); ?>>
                        <?php echo esc_html($group['displayName']); ?>
                    </label>
                <?php endforeach; ?>
                <?php if (!$all_groups) : ?>
                    <em>Geen groepen gevonden in LLDAP.</em>
                <?php endif; ?>
            </p>
            <?php submit_button('Groepen opslaan', 'secondary'); ?>
        </form>
    <?php endif; ?>

    <?php elseif ($active_tab === 'activities') : ?>
    <h2>Deelname</h2>
    <table class="wp-list-table widefat striped">
        <thead><tr><th>Activiteit</th><th>Jaar</th><th>Locatie/kenmerk</th><th>Nachten</th><th>Nawacht</th><th>Dieet</th><th>Notities</th></tr></thead>
        <tbody>
        <?php if (!$activities) : ?>
            <tr><td colspan="7">Geen activiteiten.</td></tr>
        <?php else : foreach ($activities as $c) : ?>
            <tr>
                <td><?php echo esc_html($c->name); ?></td>
                <td><?php echo esc_html($c->year); ?></td>
                <td><?php echo esc_html($c->kenmerk); ?></td>
                <td><?php echo esc_html($c->nights ?? '—'); ?></td>
                <td><?php echo $c->nawacht ? 'Ja' : 'Nee'; ?></td>
                <td><?php echo esc_html($c->diet ?: '—'); ?></td>
                <td><?php echo esc_html($c->notes ?: '—'); ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

    <?php elseif ($active_tab === 'fees') : ?>
    <h2>Contributieoverzicht</h2>
    <table class="wp-list-table widefat striped">
        <thead><tr><th>Jaar</th><th>Verschuldigd</th><th>Betaald</th><th>Betaaldatum</th><th>Status</th><th>Actie</th></tr></thead>
        <tbody>
        <?php if (!$fees) : ?>
            <tr><td colspan="6">Geen contributierecords.</td></tr>
        <?php else : foreach ($fees as $f) : ?>
            <tr>
                <td><?php echo esc_html($f->year); ?></td>
                <td><?php echo $f->amount_due !== null ? '€ ' . number_format((float) $f->amount_due, 2, ',', '.') : '—'; ?></td>
                <td><?php echo $f->amount_paid !== null ? '€ ' . number_format((float) $f->amount_paid, 2, ',', '.') : '—'; ?></td>
                <td><?php echo esc_html($f->paid_date ?: '—'); ?></td>
                <td><?php echo esc_html($f->status); ?></td>
                <td>
                    <?php if ($f->status !== 'paid') : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('avpvh_mark_fee_paid'); ?>
                        <input type="hidden" name="action"    value="avpvh_mark_fee_paid">
                        <input type="hidden" name="fee_id"    value="<?php echo esc_attr($f->id); ?>">
                        <input type="hidden" name="member_id" value="<?php echo esc_attr($member_id); ?>">
                        <button type="submit" class="button button-small">Markeer als betaald</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
