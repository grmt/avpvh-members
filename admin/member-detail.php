<?php
defined('ABSPATH') || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- this is a single-execution admin-page template (included once per request via AVPVH_Admin::render_*()), not shared library code; its top-level variables are effectively function-local to this one include, not a real global-namespace collision risk
if (!AVPVH_Roles::can_manage_members()) wp_die(__('Geen toegang.', 'avpvh-members'));

$member_id = absint(wp_unslash($_GET['id'] ?? 0));
$member    = $member_id ? AVPVH_DB::get_member($member_id) : null;
if (!$member) {
    $search  = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
    $results = $search ? AVPVH_DB::get_members(['search' => $search]) : [];
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Ledendetail', 'avpvh-members'); ?></h1>
        <form method="get">
            <input type="hidden" name="page" value="avpvh-member-detail">
            <p class="search-box">
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php echo esc_attr__('Naam of e-mail', 'avpvh-members'); ?>" autofocus>
                <button type="submit" class="button"><?php esc_html_e('Zoeken', 'avpvh-members'); ?></button>
            </p>
        </form>
        <?php if ($search && !$results) : ?>
            <p><?php esc_html_e('Geen leden gevonden.', 'avpvh-members'); ?></p>
        <?php elseif ($results) : ?>
            <table class="wp-list-table widefat striped" style="max-width:600px">
                <thead><tr><th><?php esc_html_e('Naam', 'avpvh-members'); ?></th><th><?php esc_html_e('E-mail', 'avpvh-members'); ?></th><th><?php esc_html_e('Status', 'avpvh-members'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($results as $r) :
                    $url = add_query_arg(['page' => 'avpvh-member-detail', 'id' => $r->id], admin_url('admin.php'));
                ?>
                    <tr>
                        <td><a href="<?php echo esc_url($url); ?>"><?php echo esc_html(avpvh_format_name($r, 'list')); ?></a></td>
                        <td><?php echo esc_html($r->email); ?></td>
                        <td><?php echo esc_html(AVPVH_Roles::get_status_label($r->status)); ?></td>
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
$activities = AVPVH_DB::get_activities_for_member($member_id);
$fees       = AVPVH_DB::get_fees_for_member($member_id);
$identities = AVPVH_DB::get_member_identities($member_id);
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
$sync_ok  = false;
if (!empty($_GET['sync_lldap']) && check_admin_referer('avpvh_sync_lldap_' . $member_id)) {
    $result = AVPVH_Directory::update_user($member->lldap_user_id, [
        'display_name' => avpvh_format_name($member),
    ]);
    $sync_ok  = !is_wp_error($result);
    $sync_msg = $sync_ok ? __('Naam bijgewerkt in het account.', 'avpvh-members') : sprintf(__('Bijwerken van het account is mislukt: %s', 'avpvh-members'), $result->get_error_message());
}
?>
<div class="wrap avpvh-member-detail">
    <h1><?php echo esc_html(avpvh_format_name($member, 'list')); ?></h1>
    <p class="avpvh-member-detail-actions">
    <a href="<?php echo esc_url(add_query_arg(['page' => 'avpvh-members'], admin_url('admin.php'))); ?>">&larr; <?php esc_html_e('Terug naar ledenlijst', 'avpvh-members'); ?></a>
    <span>&nbsp;|&nbsp;</span>
    <a href="<?php echo esc_url(add_query_arg(['member_id' => $member_id], home_url('/member-profile/'))); ?>"
       class="button button-small"><?php esc_html_e('Bewerk profiel', 'avpvh-members'); ?></a>
    <span>&nbsp;|&nbsp;</span>
    <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['page' => 'avpvh-member-detail', 'id' => $member_id, 'tab' => $active_tab, 'sync_lldap' => '1'], admin_url('admin.php')), 'avpvh_sync_lldap_' . $member_id)); ?>"
       class="button button-small" title="<?php echo esc_attr__('Meestal niet nodig — een naamwijziging op het profiel werkt het account al automatisch bij. Vooral bedoeld voor een lid van vóór die automatische koppeling.', 'avpvh-members'); ?>"><?php esc_html_e('Naam bijwerken in account', 'avpvh-members'); ?></a>
    <?php if (current_user_can('manage_options')) : ?>
        <?php if ($member->status === 'visitor') : ?>
            <span>&nbsp;|&nbsp;</span>
            <a href="<?php echo esc_url(add_query_arg(['page' => 'avpvh-delete-visitor', 'id' => $member_id], admin_url('admin.php'))); ?>" class="button button-small"><?php esc_html_e('Definitief verwijderen', 'avpvh-members'); ?></a>
        <?php endif; ?>
        <span>&nbsp;|&nbsp;</span>
        <a href="<?php echo esc_url(add_query_arg(['page' => 'avpvh-merge-members', 'keep' => $member_id], admin_url('admin.php'))); ?>"
           class="button button-small" title="<?php echo esc_attr__('Een dubbel ledenrecord samenvoegen met dit lid', 'avpvh-members'); ?>"><?php esc_html_e('Dubbel lid samenvoegen', 'avpvh-members'); ?></a>
    <?php endif; ?>
    </p>

    <?php if ($updated) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Bijgewerkt.', 'avpvh-members'); ?></p></div>
    <?php endif; ?>
    <?php if ($created) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Persoon aangemaakt.', 'avpvh-members'); ?></p></div>
    <?php endif; ?>
    <?php if ($identity_ok) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('E-mailadres gekoppeld.', 'avpvh-members'); ?></p></div>
    <?php elseif ($identity_deleted) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('E-mailadres verwijderd.', 'avpvh-members'); ?></p></div>
    <?php elseif ($identity_primary) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Primaire identiteit aangepast.', 'avpvh-members'); ?></p></div>
    <?php elseif ($identity_error === 'limiet') : ?>
        <div class="notice notice-error is-dismissible"><p><?php esc_html_e('Dit lid heeft al het maximale aantal van 3 e-mailadressen.', 'avpvh-members'); ?></p></div>
    <?php elseif ($identity_error === 'onvolledig') : ?>
        <div class="notice notice-error is-dismissible"><p><?php esc_html_e('Vul een e-mailadres in.', 'avpvh-members'); ?></p></div>
    <?php elseif ($identity_error === 'laatste') : ?>
        <div class="notice notice-error is-dismissible"><p><?php esc_html_e('Dit lid heeft nog maar één geverifieerd inlogadres — voeg eerst een tweede toe voordat je er een verwijdert.', 'avpvh-members'); ?></p></div>
    <?php elseif (!empty($_GET['address_updated'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Adres bijgewerkt.', 'avpvh-members'); ?></p></div>
    <?php elseif (!empty($_GET['address_deleted'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Adres verwijderd.', 'avpvh-members'); ?></p></div>
    <?php elseif (!empty($_GET['flags_saved']) && !empty($_GET['bestuur_stripped'])) : ?>
        <div class="notice notice-warning is-dismissible"><p><?php esc_html_e('Kenmerken opgeslagen. Dit lid is daardoor uit het bestuur en alle bestuursrollen gehaald en lopende delegaties zijn beëindigd. Vergeet de KVK niet als diegene daar als bestuurder staat.', 'avpvh-members'); ?></p></div>
    <?php elseif (!empty($_GET['flags_saved'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Kenmerken opgeslagen.', 'avpvh-members'); ?></p></div>
    <?php elseif (!empty($_GET['email_updated'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('E-mailadres bijgewerkt.', 'avpvh-members'); ?></p></div>
    <?php elseif (!empty($_GET['email_error'])) : ?>
        <div class="notice notice-error is-dismissible"><p><?php esc_html_e('Kon e-mailadres niet bijwerken (ongeldig adres, of fout bij het bijwerken van het account).', 'avpvh-members'); ?></p></div>
    <?php elseif (!empty($_GET['groups_saved'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Groepen bijgewerkt.', 'avpvh-members'); ?></p></div>
    <?php elseif (!empty($_GET['groups_error'])) : ?>
        <div class="notice notice-error is-dismissible"><p><?php esc_html_e('Groepen konden niet (volledig) worden bijgewerkt — zie het serverlog.', 'avpvh-members'); ?></p></div>
    <?php endif; ?>
    <?php if ($sync_msg) : ?>
        <div class="notice notice-<?php echo $sync_ok ? 'success' : 'error'; ?> is-dismissible"><p><?php echo esc_html($sync_msg); ?></p></div>
    <?php endif; ?>

    <nav class="nav-tab-wrapper" style="margin-top:1em">
        <a href="<?php echo esc_url($tab_url('contact')); ?>" class="nav-tab <?php echo $active_tab === 'contact' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Contact & Adressen', 'avpvh-members'); ?></a>
        <a href="<?php echo esc_url($tab_url('activities')); ?>" class="nav-tab <?php echo $active_tab === 'activities' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Activiteiten', 'avpvh-members'); ?></a>
        <a href="<?php echo esc_url($tab_url('fees')); ?>"    class="nav-tab <?php echo $active_tab === 'fees'    ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Contributie', 'avpvh-members'); ?></a>
        <a href="<?php echo esc_url($tab_url('relationships')); ?>" class="nav-tab <?php echo $active_tab === 'relationships' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Relaties', 'avpvh-members'); ?></a>
    </nav>

    <?php if ($active_tab === 'contact') : ?>
    <h2><?php esc_html_e('Contactgegevens', 'avpvh-members'); ?></h2>
    <table class="form-table">
        <tr><th><?php esc_html_e('Gebruikersnaam', 'avpvh-members'); ?></th><td><code><?php echo esc_html($member->lldap_user_id); ?></code></td></tr>
        <tr><th><?php esc_html_e('Voornaam', 'avpvh-members'); ?></th><td><?php echo esc_html($member->first_name); ?></td></tr>
        <tr><th><?php esc_html_e('Tussenvoegsel', 'avpvh-members'); ?></th><td><?php echo esc_html($member->suffix ?: '—'); ?></td></tr>
        <tr><th><?php esc_html_e('Achternaam', 'avpvh-members'); ?></th><td><?php echo esc_html($member->last_name); ?></td></tr>
        <tr><th><?php esc_html_e('Paspoortnaam', 'avpvh-members'); ?></th><td><?php echo esc_html($member->passport_name ?: '—'); ?></td></tr>
        <tr>
            <th><?php esc_html_e('Voorletters', 'avpvh-members'); ?></th>
            <td>
                <?php echo esc_html($member->initials ?: '—'); ?>
                <?php $mismatch = avpvh_initials_mismatch($member); ?>
                <?php if ($mismatch) : ?>
                    <br><span style="color:#b32d2e;font-weight:600">&#9888; <?php echo esc_html(sprintf(__('Komt niet overeen met de paspoortnaam (die geeft %s).', 'avpvh-members'), $mismatch)); ?></span>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th><?php esc_html_e('E-mail', 'avpvh-members'); ?></th>
            <td>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:.5rem;align-items:center"
                    onsubmit="return confirm('<?php echo esc_js(__('Contactadres van het account wijzigen?', 'avpvh-members')); ?>');">
                    <?php wp_nonce_field('avpvh_update_email'); ?>
                    <input type="hidden" name="action" value="avpvh_update_email">
                    <input type="hidden" name="member_id" value="<?php echo esc_attr($member_id); ?>">
                    <input type="email" name="email" value="<?php echo esc_attr($member->email); ?>" class="regular-text">
                    <button type="submit" class="button button-small"><?php esc_html_e('Wijzigen', 'avpvh-members'); ?></button>
                </form>
                <p class="description"><?php echo esc_html(sprintf(__('Dit is het contactadres van het account zelf, los van de Inlogadressen hieronder — al wordt het automatisch bijgewerkt naar het adres dat daar als primair wordt ingesteld. Leeg laten en opslaan zet het om naar een placeholder-adres (%s@avpvh.local), hetzelfde als bij een lid zonder echt e-mailadres.', 'avpvh-members'), $member->lldap_user_id)); ?></p>
            </td>
        </tr>
        <tr><th><?php esc_html_e('Status', 'avpvh-members'); ?></th><td><?php echo esc_html(AVPVH_Roles::get_status_label($member->status)); ?></td></tr>
        <tr><th><?php esc_html_e('Telefoon', 'avpvh-members'); ?></th><td><?php echo esc_html($member->phone); ?></td></tr>
        <tr><th><?php esc_html_e('Mobiel', 'avpvh-members'); ?></th><td><?php echo esc_html($member->mobile); ?></td></tr>
        <tr><th><?php esc_html_e('Noodcontact', 'avpvh-members'); ?></th><td><?php echo esc_html($member->emergency_contact); ?></td></tr>
        <tr>
            <th><?php esc_html_e('Geboortedatum', 'avpvh-members'); ?></th>
            <td>
                <?php if (!empty($member->birth_date)) : ?>
                    <?php echo esc_html($member->birth_date); ?>
                <?php elseif (!empty($member->birth_year)) : ?>
                    <?php echo esc_html($member->birth_year); ?> <span style="color:#777"><?php esc_html_e('(alleen geboortejaar bekend)', 'avpvh-members'); ?></span>
                <?php else : ?>
                    &mdash;
                <?php endif; ?>
            </td>
        </tr>
        <tr><th><?php esc_html_e('Scholier/student', 'avpvh-members'); ?></th><td><?php echo !empty($member->is_student) ? esc_html__('Ja', 'avpvh-members') : esc_html__('Nee', 'avpvh-members'); ?></td></tr>
        <tr><th><?php esc_html_e('Lid sinds', 'avpvh-members'); ?></th><td><?php echo esc_html($member->joined_year ?: '—'); ?></td></tr>
        <tr><th><?php esc_html_e('Vertrokken', 'avpvh-members'); ?></th><td><?php echo esc_html($member->left_year ?: '—'); ?></td></tr>
    </table>

    <details class="avpvh-detail-section" name="avpvh-detail-section">
    <summary><h2><?php esc_html_e('Inlogadressen', 'avpvh-members'); ?></h2></summary>
    <div style="overflow-x:auto">
    <table class="wp-list-table widefat striped">
        <thead><tr><th><?php esc_html_e('Provider', 'avpvh-members'); ?></th><th><?php esc_html_e('E-mail', 'avpvh-members'); ?></th><th><?php esc_html_e('Geverifieerd', 'avpvh-members'); ?></th><th><?php esc_html_e('Eerste login', 'avpvh-members'); ?></th><th><?php esc_html_e('Laatste login', 'avpvh-members'); ?></th><th><?php esc_html_e('Primair', 'avpvh-members'); ?></th><th><?php esc_html_e('Actie', 'avpvh-members'); ?></th></tr></thead>
        <tbody>
        <?php if (!$identities) : ?>
            <tr><td colspan="7"><?php esc_html_e('Geen gekoppelde adressen.', 'avpvh-members'); ?></td></tr>
        <?php else : foreach ($identities as $identity) :
            $login_stats = AVPVH_DB::get_login_stats_for_email($identity->email);
        ?>
            <tr>
                <td><?php echo esc_html(ucfirst($identity->provider)); ?></td>
                <td><?php echo esc_html($identity->email); ?></td>
                <td>
                    <?php if ($identity->verified_at) : ?>
                        <?php esc_html_e('Ja', 'avpvh-members'); ?>
                    <?php else : ?>
                        <span style="color:#b32d2e;font-weight:600"><?php esc_html_e('Nee (door beheerder toegevoegd)', 'avpvh-members'); ?></span>
                    <?php endif; ?>
                </td>
                <td><?php echo $login_stats->first_login ? esc_html(wp_date('d-m-Y H:i', strtotime($login_stats->first_login))) : '—'; ?></td>
                <td><?php echo $login_stats->last_login ? esc_html(wp_date('d-m-Y H:i', strtotime($login_stats->last_login))) : '—'; ?></td>
                <td><?php echo $identity->is_primary ? esc_html__('Ja', 'avpvh-members') : esc_html__('Nee', 'avpvh-members'); ?></td>
                <td>
                    <?php if (!$identity->is_primary) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:.5rem"
                        title="<?php echo esc_attr__('Wordt ook het contactadres van het account hierboven, los van waarmee wordt ingelogd.', 'avpvh-members'); ?>">
                        <?php wp_nonce_field('avpvh_primary_identity'); ?>
                        <input type="hidden" name="action" value="avpvh_primary_identity">
                        <input type="hidden" name="member_id" value="<?php echo esc_attr($member_id); ?>">
                        <input type="hidden" name="identity_id" value="<?php echo esc_attr($identity->id); ?>">
                        <button type="submit" class="button button-small"><?php esc_html_e('Maak primair', 'avpvh-members'); ?></button>
                    </form>
                    <?php endif; ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block"
                        onsubmit="return confirm('<?php echo esc_js(__('Dit e-mailadres verwijderen?', 'avpvh-members')); ?>');">
                        <?php wp_nonce_field('avpvh_delete_identity'); ?>
                        <input type="hidden" name="action" value="avpvh_delete_identity">
                        <input type="hidden" name="member_id" value="<?php echo esc_attr($member_id); ?>">
                        <input type="hidden" name="identity_id" value="<?php echo esc_attr($identity->id); ?>">
                        <button type="submit" class="button button-small"><?php esc_html_e('Verwijder', 'avpvh-members'); ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>

    <h3 style="margin-top:1rem"><?php esc_html_e('Nieuw e-mailadres koppelen', 'avpvh-members'); ?></h3>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="form-table">
        <?php wp_nonce_field('avpvh_add_identity'); ?>
        <input type="hidden" name="action" value="avpvh_add_identity">
        <input type="hidden" name="member_id" value="<?php echo esc_attr($member_id); ?>">
        <table class="form-table">
            <tr>
                <th><label for="identity_email"><?php esc_html_e('E-mail', 'avpvh-members'); ?></label></th>
                <td>
                    <input type="email" id="identity_email" name="email" class="regular-text" value="">
                    <p class="description"><?php esc_html_e('Wordt als niet-geverifieerd toegevoegd — de daadwerkelijke inlogmethode (Google, Microsoft, of e-maillink) wordt vastgesteld zodra het lid er zelf mee inlogt.', 'avpvh-members'); ?></p>
                </td>
            </tr>
        </table>
        <?php submit_button(__('Koppelen', 'avpvh-members'), 'secondary'); ?>
    </form>
    </details>

    <details class="avpvh-detail-section" name="avpvh-detail-section">
    <summary><h2><?php esc_html_e('Kenmerken', 'avpvh-members'); ?></h2></summary>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('avpvh_save_member_flags'); ?>
        <input type="hidden" name="action" value="avpvh_save_member_flags">
        <input type="hidden" name="member_id" value="<?php echo esc_attr($member_id); ?>">
        <?php if (!$all_flags) : ?>
            <p class="description"><?php esc_html_e('Nog geen kenmerken aangemaakt.', 'avpvh-members'); ?></p>
        <?php else : ?>
            <ul style="margin:0 0 1em">
                <?php foreach ($all_flags as $flag) : ?>
                    <li>
                        <label>
                            <input type="checkbox" name="flag_ids[]" value="<?php echo esc_attr($flag->id); ?>"
                                <?php checked(in_array((int) $flag->id, $member_flag_ids, true)); ?>>
                            <?php echo esc_html($flag->label); ?>
                            <?php if ($flag->affects_fees) : ?>
                                <span title="<?php echo esc_attr__('Vrijgesteld van contributie', 'avpvh-members'); ?>" style="color:#787c82">(<?php esc_html_e('vrijgesteld van contributie', 'avpvh-members'); ?>)</span>
                            <?php endif; ?>
                            <?php if ($flag->sets_inactive) : ?>
                                <span title="<?php echo esc_attr__('Zet status automatisch op inactief', 'avpvh-members'); ?>" style="color:#b32d2e">(<?php esc_html_e('zet op inactief', 'avpvh-members'); ?>)</span>
                            <?php endif; ?>
                        </label>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php submit_button(__('Kenmerken opslaan', 'avpvh-members'), 'secondary'); ?>
    </form>
    <p class="description">
        <?php printf(esc_html__('Nieuw kenmerk nodig? %s bij Instellingen.', 'avpvh-members'), '<a href="' . esc_url(add_query_arg(['page' => 'avpvh-settings'], admin_url('admin.php'))) . '">' . esc_html__('Beheer de lijst met kenmerken', 'avpvh-members') . '</a>'); ?>
    </p>
    </details>

    <details class="avpvh-detail-section" name="avpvh-detail-section">
    <summary><h2><?php esc_html_e('Adreshistorie', 'avpvh-members'); ?></h2></summary>
    <p class="description"><?php esc_html_e('Elke keer dat een adres wordt opgeslagen via het profiel komt er een nieuwe rij bij (nooit een wijziging van een bestaande) — hier kun je de geldigheidsdatums van een rij corrigeren of een foutieve/dubbele rij verwijderen.', 'avpvh-members'); ?></p>
    <div style="overflow-x:auto">
    <table class="wp-list-table widefat striped">
        <thead><tr><th><?php esc_html_e('Straat', 'avpvh-members'); ?></th><th><?php esc_html_e('Nr', 'avpvh-members'); ?></th><th><?php esc_html_e('Postcode', 'avpvh-members'); ?></th><th><?php esc_html_e('Stad', 'avpvh-members'); ?></th><th><?php esc_html_e('Land', 'avpvh-members'); ?></th><th><?php esc_html_e('Van', 'avpvh-members'); ?></th><th><?php esc_html_e('Tot', 'avpvh-members'); ?></th><th></th></tr></thead>
        <tbody>
        <?php if (!$addresses) : ?>
            <tr><td colspan="8"><?php esc_html_e('Geen adressen.', 'avpvh-members'); ?></td></tr>
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
                        <button type="submit" class="button button-small"><?php esc_html_e('Opslaan', 'avpvh-members'); ?></button>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                    <?php wp_nonce_field('avpvh_delete_address'); ?>
                    <input type="hidden" name="action" value="avpvh_delete_address">
                    <input type="hidden" name="id" value="<?php echo esc_attr($a->id); ?>">
                    <input type="hidden" name="member_id" value="<?php echo esc_attr($member_id); ?>">
                    <button type="submit" class="button button-small" onclick="return confirm('<?php echo esc_js(__('Dit adres verwijderen?', 'avpvh-members')); ?>');"><?php esc_html_e('Verwijderen', 'avpvh-members'); ?></button>
                </form>
                    </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
    </details>

    <details class="avpvh-detail-section" name="avpvh-detail-section">
    <summary><h2><?php esc_html_e('Groepen (toegangsrechten)', 'avpvh-members'); ?></h2></summary>
    <p class="description"><?php esc_html_e('Groepslidmaatschap regelt echte toegang (bijv. secretaris-rechten, de boek-groep voor "Zoeken in documenten") — los van de kenmerken hierboven, die alleen labels/filters zijn.', 'avpvh-members'); ?></p>
    <?php
    $all_groups     = AVPVH_Directory::list_groups();
    $current_groups = AVPVH_Directory::get_user_groups($member->lldap_user_id);
    if (!is_wp_error($all_groups)) {
        $all_groups = AVPVH_Directory::only_pvh_groups($all_groups);
    }
    ?>
    <?php if (is_wp_error($all_groups) || is_wp_error($current_groups)) : ?>
        <p class="description"><?php esc_html_e('Kon de groepen niet ophalen.', 'avpvh-members'); ?></p>
    <?php else : ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('avpvh_save_groups'); ?>
            <input type="hidden" name="action" value="avpvh_save_groups">
            <input type="hidden" name="member_id" value="<?php echo esc_attr($member_id); ?>">
            <p>
                <?php foreach ($all_groups as $group) : ?>
                    <label style="display:inline-block;margin-right:1.5rem">
                        <input type="checkbox" name="groups[]" value="<?php echo esc_attr($group); ?>"
                            <?php checked(in_array($group, $current_groups, true)); ?>>
                        <?php echo esc_html($group); ?>
                    </label>
                <?php endforeach; ?>
                <?php if (!$all_groups) : ?>
                    <em><?php esc_html_e('Geen groepen gevonden.', 'avpvh-members'); ?></em>
                <?php endif; ?>
            </p>
            <?php submit_button(__('Groepen opslaan', 'avpvh-members'), 'secondary'); ?>
        </form>
    <?php endif; ?>
    </details>

    <?php elseif ($active_tab === 'activities') : ?>
    <h2><?php esc_html_e('Deelname', 'avpvh-members'); ?></h2>
    <div style="overflow-x:auto">
    <table class="wp-list-table widefat striped">
        <thead><tr><th><?php esc_html_e('Activiteit', 'avpvh-members'); ?></th><th><?php esc_html_e('Jaar', 'avpvh-members'); ?></th><th><?php esc_html_e('Locatie/kenmerk', 'avpvh-members'); ?></th><th><?php esc_html_e('Nachten', 'avpvh-members'); ?></th><th><?php esc_html_e('Nawacht', 'avpvh-members'); ?></th><th><?php esc_html_e('Dieet', 'avpvh-members'); ?></th><th><?php esc_html_e('Notities', 'avpvh-members'); ?></th></tr></thead>
        <tbody>
        <?php if (!$activities) : ?>
            <tr><td colspan="7"><?php esc_html_e('Geen activiteiten.', 'avpvh-members'); ?></td></tr>
        <?php else : foreach ($activities as $c) : ?>
            <tr>
                <td><?php echo esc_html($c->name); ?></td>
                <td><?php echo esc_html($c->year); ?></td>
                <td><?php echo esc_html($c->kenmerk); ?></td>
                <td><?php echo esc_html($c->nights ?? '—'); ?></td>
                <td><?php echo $c->nawacht ? esc_html__('Ja', 'avpvh-members') : esc_html__('Nee', 'avpvh-members'); ?></td>
                <td><?php echo esc_html($c->diet ?: '—'); ?></td>
                <td><?php echo esc_html($c->notes ?: '—'); ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>

    <?php elseif ($active_tab === 'fees') : ?>
    <h2><?php esc_html_e('Contributieoverzicht', 'avpvh-members'); ?></h2>
    <div style="overflow-x:auto">
    <table class="wp-list-table widefat striped">
        <thead><tr><th><?php esc_html_e('Jaar', 'avpvh-members'); ?></th><th><?php esc_html_e('Verschuldigd', 'avpvh-members'); ?></th><th><?php esc_html_e('Betaald', 'avpvh-members'); ?></th><th><?php esc_html_e('Betaaldatum', 'avpvh-members'); ?></th><th><?php esc_html_e('Status', 'avpvh-members'); ?></th><th><?php esc_html_e('Actie', 'avpvh-members'); ?></th></tr></thead>
        <tbody>
        <?php if (!$fees) : ?>
            <tr><td colspan="6"><?php esc_html_e('Geen contributierecords.', 'avpvh-members'); ?></td></tr>
        <?php else : foreach ($fees as $f) : ?>
            <tr>
                <td><?php echo esc_html($f->year); ?></td>
                <td><?php echo $f->amount_due !== null ? '€ ' . number_format((float) $f->amount_due, 2, ',', '.') : '—'; ?></td>
                <td><?php echo $f->amount_paid !== null ? '€ ' . number_format((float) $f->amount_paid, 2, ',', '.') : '—'; ?></td>
                <td><?php echo esc_html($f->paid_date ?: '—'); ?></td>
                <td><?php echo esc_html($f->status === 'paid' ? __('Betaald', 'avpvh-members') : ($f->status === 'unpaid' ? __('Openstaand', 'avpvh-members') : $f->status)); ?></td>
                <td>
                    <?php if ($f->status !== 'paid') : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('avpvh_mark_fee_paid'); ?>
                        <input type="hidden" name="action"    value="avpvh_mark_fee_paid">
                        <input type="hidden" name="fee_id"    value="<?php echo esc_attr($f->id); ?>">
                        <input type="hidden" name="member_id" value="<?php echo esc_attr($member_id); ?>">
                        <button type="submit" class="button button-small"><?php esc_html_e('Markeer als betaald', 'avpvh-members'); ?></button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>

    <?php elseif ($active_tab === 'relationships') : ?>
    <?php AVPVH_Member_Profile_Form::render_relationships($member); ?>
    <?php endif; ?>
</div>
