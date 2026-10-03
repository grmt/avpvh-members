<?php
defined('ABSPATH') || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- this is a single-execution admin-page template (included once per request via AVPVH_Admin::render_*()), not shared library code; its top-level variables are effectively function-local to this one include, not a real global-namespace collision risk
if (!current_user_can('manage_options')) {
    wp_die('Geen toegang.');
}

$keep_id   = absint(wp_unslash($_GET['keep'] ?? 0));
$remove_id = absint(wp_unslash($_GET['remove'] ?? 0));
$page_url  = fn(array $args = []): string => add_query_arg(array_merge(['page' => 'avpvh-merge-members'], $args), admin_url('admin.php'));
$detail_url = fn(int $id): string => add_query_arg(['page' => 'avpvh-member-detail', 'id' => $id], admin_url('admin.php'));
$option_label = fn(object $m): string => avpvh_format_name($m, 'list') . ' — #' . $m->id . ', ' . $m->status
    . ($m->birth_date ? ', ' . $m->birth_date : ($m->birth_year ? ', ' . $m->birth_year : ''));

// Outcome of the last merge survives the redirect via a per-user transient
// (same pattern as add-member.php's duplicate warning), so error text never
// travels through the URL.
$result = get_transient('avpvh_merge_result_' . get_current_user_id());
if ($result) {
    delete_transient('avpvh_merge_result_' . get_current_user_id());
}

$preview = ($keep_id && $remove_id) ? AVPVH_Member_Merge::preview($keep_id, $remove_id) : null;
?>
<div class="wrap">
    <h1>Leden samenvoegen</h1>
    <p class="description">
        Voeg een dubbel ledenrecord samen met het lid dat blijft bestaan. Activiteiten, contributie, adressen,
        inlogadressen, relaties, kenmerken en boekhoudregels gaan mee; het dubbele lid en zijn account worden daarna verwijderd.
        Dit kan niet ongedaan worden gemaakt.
    </p>

    <?php if ($result) : ?>
        <?php if (!empty($result['error'])) : ?>
            <div class="notice notice-error"><p><?php echo esc_html($result['error']); ?></p></div>
        <?php else : ?>
            <div class="notice notice-success">
                <p>
                    Samengevoegd in
                    <a href="<?php echo esc_url($detail_url((int) $result['keep_id'])); ?>"><?php echo esc_html($result['keep_name']); ?></a>.
                </p>
            </div>
            <?php foreach ($result['warnings'] as $warning) : ?>
                <div class="notice notice-warning"><p><?php echo esc_html($warning); ?></p></div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>

    <form method="get" style="margin: 1em 0;">
        <input type="hidden" name="page" value="avpvh-merge-members">
        <?php $members = AVPVH_Member_Merge::get_member_options(); ?>
        <table class="form-table">
            <tr>
                <th><label for="avpvh-merge-keep">Behouden</label></th>
                <td>
                    <select id="avpvh-merge-keep" name="keep" required style="max-width: 100%;">
                        <option value="">— kies het lid dat blijft —</option>
                        <?php foreach ($members as $m) : ?>
                            <option value="<?php echo esc_attr($m->id); ?>" <?php selected($keep_id, (int) $m->id); ?>><?php echo esc_html($option_label($m)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="avpvh-merge-remove">Opheffen (dubbel)</label></th>
                <td>
                    <select id="avpvh-merge-remove" name="remove" required style="max-width: 100%;">
                        <option value="">— kies het dubbele lid —</option>
                        <?php foreach ($members as $m) : ?>
                            <option value="<?php echo esc_attr($m->id); ?>" <?php selected($remove_id, (int) $m->id); ?>><?php echo esc_html($option_label($m)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
        </table>
        <button type="submit" class="button">Vergelijken</button>
        <?php if ($keep_id && $remove_id) : ?>
            <a class="button-link" style="margin-left: 1em;" href="<?php echo esc_url($page_url(['keep' => $remove_id, 'remove' => $keep_id])); ?>">&#8645; Richting omwisselen</a>
        <?php endif; ?>
    </form>

    <?php if (is_wp_error($preview)) : ?>
        <div class="notice notice-error"><p><?php echo esc_html($preview->get_error_message()); ?></p></div>
    <?php elseif ($preview) :
        $keep = $preview['keep'];
        $remove = $preview['remove'];
        $accounts = $preview['accounts'];
        ?>
        <hr>
        <h2>
            <a href="<?php echo esc_url($detail_url((int) $remove->id)); ?>" target="_blank"><?php echo esc_html(avpvh_format_name($remove)); ?> (#<?php echo esc_html($remove->id); ?>)</a>
            &rarr;
            <a href="<?php echo esc_url($detail_url((int) $keep->id)); ?>" target="_blank"><?php echo esc_html(avpvh_format_name($keep)); ?> (#<?php echo esc_html($keep->id); ?>)</a>
        </h2>

        <?php foreach ($preview['blockers'] as $blocker) : ?>
            <div class="notice notice-error inline"><p><?php echo esc_html($blocker); ?></p></div>
        <?php endforeach; ?>
        <?php foreach ($preview['warnings'] as $warning) : ?>
            <div class="notice notice-warning inline"><p><?php echo esc_html($warning); ?></p></div>
        <?php endforeach; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('avpvh_merge_members'); ?>
            <input type="hidden" name="action" value="avpvh_merge_members">
            <input type="hidden" name="keep" value="<?php echo esc_attr($keep->id); ?>">
            <input type="hidden" name="remove" value="<?php echo esc_attr($remove->id); ?>">
            <input type="hidden" name="fingerprint" value="<?php echo esc_attr($preview['fingerprint']); ?>">

            <h3>Account</h3>
            <table class="widefat striped" style="max-width: 900px;">
                <thead><tr><th></th><th>Behouden</th><th>Opheffen</th></tr></thead>
                <tbody>
                    <tr>
                        <th>Account</th>
                        <td><?php echo esc_html($accounts['keep']['uid']); ?></td>
                        <td><?php echo esc_html($accounts['remove']['uid']); ?><?php echo $accounts['remove']['exists'] ? ' <em>(wordt verwijderd)</em>' : ''; ?></td>
                    </tr>
                    <tr>
                        <th>E-mail</th>
                        <td><?php echo esc_html($accounts['keep']['email']); ?></td>
                        <td><?php echo esc_html($accounts['remove']['email']); ?></td>
                    </tr>
                    <tr>
                        <th>Groepen</th>
                        <td><?php echo esc_html(implode(', ', $accounts['keep']['groups'])); ?></td>
                        <td><?php echo esc_html(implode(', ', $accounts['remove']['groups'])); ?></td>
                    </tr>
                    <tr>
                        <th>Ingelogd</th>
                        <td><?php echo $keep->wp_user_id !== null ? 'ja' : 'nee'; ?></td>
                        <td><?php echo $remove->wp_user_id !== null ? 'ja' : 'nee'; ?></td>
                    </tr>
                </tbody>
            </table>
            <p class="description">Een echt e-mailadres van het dubbele account wordt als (onbevestigd) inlogadres aan het behouden lid toegevoegd.</p>

            <h3>Gegevens</h3>
            <?php if (!$preview['fields']) : ?>
                <p>Alle gegevens zijn gelijk.</p>
            <?php else : ?>
                <p class="description">Alleen velden die verschillen. Privacykeuzes staan standaard op de strengste van de twee. Een naam die wegvalt wordt bewaard als naamvariant.</p>
                <table class="widefat striped" style="max-width: 900px;">
                    <thead><tr><th>Veld</th><th>Behouden</th><th>Opheffen</th></tr></thead>
                    <tbody>
                    <?php foreach ($preview['fields'] as $field => $info) : ?>
                        <tr>
                            <th><?php echo esc_html($info['label']); ?></th>
                            <?php foreach (['keep', 'remove'] as $side) : ?>
                                <td>
                                    <label>
                                        <input type="radio" name="fields[<?php echo esc_attr($field); ?>]" value="<?php echo esc_attr($side); ?>" <?php checked($info['default'], $side); ?>>
                                        <?php echo $info[$side] === '' ? '<em>leeg</em>' : esc_html($info[$side]); ?>
                                    </label>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h3>Adressen</h3>
            <?php
            $format_address = fn(object $a): string => trim("{$a->street} {$a->house_number}, {$a->postal_code} {$a->city}", ' ,')
                . ' (' . ($a->valid_from ?: '?') . ' – ' . ($a->valid_until ?: 'heden') . ')';
            ?>
            <p><strong>Behouden lid:</strong>
                <?php echo $preview['addresses']['keep'] ? esc_html(implode(' · ', array_map($format_address, $preview['addresses']['keep']))) : '<em>geen</em>'; ?>
            </p>
            <?php if (!$preview['addresses']['remove']) : ?>
                <p>Het dubbele lid heeft geen adressen.</p>
            <?php else : ?>
                <table class="widefat striped" style="max-width: 900px;">
                    <thead><tr><th>Adres van het dubbele lid</th><th>Actie</th></tr></thead>
                    <tbody>
                    <?php foreach ($preview['addresses']['remove'] as $address) :
                        $default = $preview['address_defaults'][(int) $address->id]; ?>
                        <tr>
                            <td><?php echo esc_html($format_address($address)); ?></td>
                            <td>
                                <select name="addresses[<?php echo esc_attr($address->id); ?>]">
                                    <option value="move" <?php selected($default, 'move'); ?>>Overnemen</option>
                                    <option value="history" <?php selected($default, 'history'); ?>>Overnemen als oud adres (einddatum vandaag)</option>
                                    <option value="delete" <?php selected($default, 'delete'); ?>>Verwijderen</option>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h3>Wat er verder meegaat</h3>
            <ul style="list-style: disc; margin-left: 1.5rem;">
                <?php foreach ($preview['participation'] as $row) : ?>
                    <li>
                        Activiteit <?php echo esc_html($row['name']); ?>
                        <?php if ($row['conflict']) : ?>
                            — <em>beiden deden mee: de bestaande deelname blijft, aangevuld met ontbrekende gegevens en dagen</em>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
                <?php foreach ($preview['fees'] as $fee) : ?>
                    <li>
                        Contributie <?php echo esc_html($fee['year']); ?> (<?php echo esc_html($fee['status']); ?>)
                        <?php if ($fee['conflict']) : ?>
                            — <em>beiden hebben dit jaar: <?php echo $fee['wins'] ? 'deze vervangt de openstaande regel van het behouden lid' : 'de regel van het behouden lid blijft, deze vervalt'; ?></em>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
                <?php foreach ($preview['counts'] as $label => $count) : ?>
                    <li><?php echo esc_html("$label: $count"); ?></li>
                <?php endforeach; ?>
                <?php foreach ($preview['external'] as $column => $count) : ?>
                    <li><?php echo esc_html("$column: $count"); ?> <em>(andere plugin)</em></li>
                <?php endforeach; ?>
                <?php if (!$preview['participation'] && !$preview['fees'] && !$preview['counts'] && !$preview['external']) : ?>
                    <li>Niets — het dubbele lid heeft geen verdere gegevens.</li>
                <?php endif; ?>
            </ul>

            <?php if (!$preview['blockers']) : ?>
                <p>
                    <label>
                        <input type="checkbox" name="confirm" value="1" required>
                        Ik heb gecontroleerd dat dit dezelfde persoon is.
                    </label>
                </p>
                <?php submit_button('Samenvoegen', 'primary', 'submit', false); ?>
            <?php endif; ?>
        </form>
    <?php else :
        $candidates = AVPVH_Member_Merge::find_duplicate_candidates(); ?>
        <hr>
        <h2>Mogelijke dubbelen</h2>
        <p class="description">Leden met dezelfde voornaam en achternaam (tussenvoegsel en schrijfwijze genegeerd). Dit is alleen een suggestie — controleer altijd of het echt dezelfde persoon is.</p>
        <?php if (!$candidates) : ?>
            <p>Geen mogelijke dubbelen gevonden.</p>
        <?php else : ?>
            <table class="widefat striped" style="max-width: 900px;">
                <tbody>
                <?php foreach ($candidates as $group) : ?>
                    <tr>
                        <td>
                            <?php foreach ($group as $m) : ?>
                                <a href="<?php echo esc_url($detail_url((int) $m->id)); ?>" target="_blank"><?php echo esc_html($option_label($m)); ?></a><br>
                            <?php endforeach; ?>
                        </td>
                        <td>
                            <a class="button button-small" href="<?php echo esc_url($page_url(['keep' => $group[0]->id, 'remove' => $group[1]->id])); ?>">Vergelijken</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>
</div>
