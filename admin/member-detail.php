<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options')) wp_die('Geen toegang.');

$member_id = (int) ($_GET['id'] ?? 0);
$member    = $member_id ? AVPVH_DB::get_member($member_id) : null;
if (!$member) {
    $search  = sanitize_text_field($_GET['s'] ?? '');
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
$camps      = AVPVH_DB::get_camps_for_member($member_id);
$fees       = AVPVH_DB::get_fees_for_member($member_id);
$identities = AVPVH_DB::get_member_identities($member_id);
$active_tab = sanitize_key($_GET['tab'] ?? 'contact');
$updated    = !empty($_GET['updated']);
$created    = !empty($_GET['created']);
$identity_ok = !empty($_GET['identity_ok']);
$identity_deleted = !empty($_GET['identity_deleted']);
$identity_primary = !empty($_GET['identity_primary']);
$identity_error = sanitize_key($_GET['identity_error'] ?? '');

$tab_url = fn(string $tab): string => add_query_arg(
    ['page' => 'avpvh-member-detail', 'id' => $member_id, 'tab' => $tab],
    admin_url('admin.php')
);

// Sync-to-LLDAP action
$sync_msg = null;
if (!empty($_GET['sync_lldap']) && check_admin_referer('avpvh_sync_lldap_' . $member_id)) {
    $result = AVPVH_LLDAP::update_user($member->lldap_user_id, [
        'email'       => $member->email,
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
       class="button button-small">Sync naar LLDAP</a>

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
        <div class="notice notice-error is-dismissible"><p>Vul provider en e-mailadres volledig in.</p></div>
    <?php elseif (!empty($_GET['address_updated'])) : ?>
        <div class="notice notice-success is-dismissible"><p>Adres bijgewerkt.</p></div>
    <?php elseif (!empty($_GET['address_deleted'])) : ?>
        <div class="notice notice-success is-dismissible"><p>Adres verwijderd.</p></div>
    <?php endif; ?>
    <?php if ($sync_msg) : ?>
        <div class="notice notice-<?php echo str_contains($sync_msg, 'LLDAP') && !str_contains($sync_msg, 'fout') ? 'success' : 'error'; ?> is-dismissible"><p><?php echo esc_html($sync_msg); ?></p></div>
    <?php endif; ?>

    <nav class="nav-tab-wrapper" style="margin-top:1em">
        <a href="<?php echo esc_url($tab_url('contact')); ?>" class="nav-tab <?php echo $active_tab === 'contact' ? 'nav-tab-active' : ''; ?>">Contact & Adressen</a>
        <a href="<?php echo esc_url($tab_url('camps')); ?>"   class="nav-tab <?php echo $active_tab === 'camps'   ? 'nav-tab-active' : ''; ?>">Kampen</a>
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
        <tr><th>E-mail</th><td><?php echo esc_html($member->email); ?></td></tr>
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

    <h2>Inlogadressen</h2>
    <table class="wp-list-table widefat striped">
        <thead><tr><th>Provider</th><th>E-mail</th><th>Primair</th><th>Actie</th></tr></thead>
        <tbody>
        <?php if (!$identities) : ?>
            <tr><td colspan="4">Geen gekoppelde adressen.</td></tr>
        <?php else : foreach ($identities as $identity) : ?>
            <tr>
                <td><?php echo esc_html(ucfirst($identity->provider)); ?></td>
                <td><?php echo esc_html($identity->email); ?></td>
                <td><?php echo $identity->is_primary ? 'Ja' : 'Nee'; ?></td>
                <td>
                    <?php if (!$identity->is_primary) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:.5rem">
                        <?php wp_nonce_field('avpvh_primary_identity'); ?>
                        <input type="hidden" name="action" value="avpvh_primary_identity">
                        <input type="hidden" name="member_id" value="<?php echo esc_attr($member_id); ?>">
                        <input type="hidden" name="identity_id" value="<?php echo esc_attr($identity->id); ?>">
                        <button type="submit" class="button button-small">Maak primair</button>
                    </form>
                    <?php endif; ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block">
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
                <th><label for="provider">Provider</label></th>
                <td>
                    <select id="provider" name="provider">
                        <option value="email">E-mail</option>
                        <option value="google">Google</option>
                        <option value="microsoft">Microsoft</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="identity_email">E-mail</label></th>
                <td><input type="email" id="identity_email" name="email" class="regular-text" value=""></td>
            </tr>
        </table>
        <?php submit_button('Koppelen', 'secondary'); ?>
    </form>

    <h2>Adreshistorie</h2>
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

    <?php elseif ($active_tab === 'camps') : ?>
    <h2>Kampledeelname</h2>
    <table class="wp-list-table widefat striped">
        <thead><tr><th>Kamp</th><th>Jaar</th><th>Locatie/kenmerk</th><th>Nachten</th><th>Nawacht</th><th>Dieet</th><th>Notities</th></tr></thead>
        <tbody>
        <?php if (!$camps) : ?>
            <tr><td colspan="7">Geen kampen.</td></tr>
        <?php else : foreach ($camps as $c) : ?>
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
