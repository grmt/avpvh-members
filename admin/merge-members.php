<?php
defined('ABSPATH') || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- this is a single-execution admin-page template (included once per request via AVPVH_Admin::render_*()), not shared library code; its top-level variables are effectively function-local to this one include, not a real global-namespace collision risk
if (!current_user_can('manage_options')) {
    wp_die(__('Geen toegang.', 'avpvh-members'));
}

$keep_id   = absint(wp_unslash($_GET['keep'] ?? 0));
$remove_id = absint(wp_unslash($_GET['remove'] ?? 0));
$page_url  = fn(array $args = []): string => add_query_arg(array_merge(['page' => 'avpvh-merge-members'], $args), admin_url('admin.php'));
$detail_url = fn(int $id): string => add_query_arg(['page' => 'avpvh-member-detail', 'id' => $id], admin_url('admin.php'));
$option_label = fn(object $m): string => avpvh_format_name($m, 'list') . ' — #' . $m->id . ', ' . AVPVH_Roles::get_status_label($m->status)
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
    <h1><?php esc_html_e('Leden samenvoegen', 'avpvh-members'); ?></h1>
    <p class="description">
        <?php esc_html_e('Voeg een dubbel ledenrecord samen met het lid dat blijft bestaan. Activiteiten, contributie, adressen, inlogadressen, relaties, kenmerken en boekhoudregels gaan mee; het dubbele lid en zijn account worden daarna verwijderd. Dit kan niet ongedaan worden gemaakt.', 'avpvh-members'); ?>
    </p>

    <?php if ($result) : ?>
        <?php if (!empty($result['error'])) : ?>
            <div class="notice notice-error"><p><?php echo esc_html($result['error']); ?></p></div>
        <?php else : ?>
            <div class="notice notice-success">
                <p>
                    <?php esc_html_e('Samengevoegd in', 'avpvh-members'); ?>
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
                <th><label for="avpvh-merge-keep"><?php esc_html_e('Behouden', 'avpvh-members'); ?></label></th>
                <td>
                    <select id="avpvh-merge-keep" name="keep" required style="max-width: 100%;">
                        <option value="">— <?php esc_html_e('kies het lid dat blijft', 'avpvh-members'); ?> —</option>
                        <?php foreach ($members as $m) : ?>
                            <option value="<?php echo esc_attr($m->id); ?>" <?php selected($keep_id, (int) $m->id); ?>><?php echo esc_html($option_label($m)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="avpvh-merge-remove"><?php esc_html_e('Opheffen (dubbel)', 'avpvh-members'); ?></label></th>
                <td>
                    <select id="avpvh-merge-remove" name="remove" required style="max-width: 100%;">
                        <option value="">— <?php esc_html_e('kies het dubbele lid', 'avpvh-members'); ?> —</option>
                        <?php foreach ($members as $m) : ?>
                            <option value="<?php echo esc_attr($m->id); ?>" <?php selected($remove_id, (int) $m->id); ?>><?php echo esc_html($option_label($m)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
        </table>
        <button type="submit" class="button"><?php esc_html_e('Vergelijken', 'avpvh-members'); ?></button>
        <?php if ($keep_id && $remove_id) : ?>
            <a class="button-link" style="margin-left: 1em;" href="<?php echo esc_url($page_url(['keep' => $remove_id, 'remove' => $keep_id])); ?>">&#8645; <?php esc_html_e('Richting omwisselen', 'avpvh-members'); ?></a>
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

            <h3><?php esc_html_e('Account', 'avpvh-members'); ?></h3>
            <table class="widefat striped" style="max-width: 900px;">
                <thead><tr><th></th><th><?php esc_html_e('Behouden', 'avpvh-members'); ?></th><th><?php esc_html_e('Opheffen', 'avpvh-members'); ?></th></tr></thead>
                <tbody>
                    <tr>
                        <th><?php esc_html_e('Account', 'avpvh-members'); ?></th>
                        <td><?php echo esc_html($accounts['keep']['uid']); ?></td>
                        <td><?php echo esc_html($accounts['remove']['uid']); ?><?php echo $accounts['remove']['exists'] ? ' <em>(' . esc_html__('wordt verwijderd', 'avpvh-members') . ')</em>' : ''; ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('E-mail', 'avpvh-members'); ?></th>
                        <td><?php echo esc_html($accounts['keep']['email']); ?></td>
                        <td><?php echo esc_html($accounts['remove']['email']); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Groepen', 'avpvh-members'); ?></th>
                        <td><?php echo esc_html(implode(', ', $accounts['keep']['groups'])); ?></td>
                        <td><?php echo esc_html(implode(', ', $accounts['remove']['groups'])); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Ingelogd', 'avpvh-members'); ?></th>
                        <td><?php echo $keep->wp_user_id !== null ? esc_html__('ja', 'avpvh-members') : esc_html__('nee', 'avpvh-members'); ?></td>
                        <td><?php echo $remove->wp_user_id !== null ? esc_html__('ja', 'avpvh-members') : esc_html__('nee', 'avpvh-members'); ?></td>
                    </tr>
                </tbody>
            </table>
            <p class="description"><?php esc_html_e('Een echt e-mailadres van het dubbele account wordt als (onbevestigd) inlogadres aan het behouden lid toegevoegd.', 'avpvh-members'); ?></p>

            <h3><?php esc_html_e('Gegevens', 'avpvh-members'); ?></h3>
            <?php if (!$preview['fields']) : ?>
                <p><?php esc_html_e('Alle gegevens zijn gelijk.', 'avpvh-members'); ?></p>
            <?php else : ?>
                <p class="description"><?php esc_html_e('Alleen velden die verschillen. Privacykeuzes staan standaard op de strengste van de twee. Een naam die wegvalt wordt bewaard als naamvariant.', 'avpvh-members'); ?></p>
                <table class="widefat striped" style="max-width: 900px;">
                    <thead><tr><th><?php esc_html_e('Veld', 'avpvh-members'); ?></th><th><?php esc_html_e('Behouden', 'avpvh-members'); ?></th><th><?php esc_html_e('Opheffen', 'avpvh-members'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($preview['fields'] as $field => $info) : ?>
                        <tr>
                            <th><?php echo esc_html(AVPVH_Member_Merge::get_field_label($field)); ?></th>
                            <?php foreach (['keep', 'remove'] as $side) : ?>
                                <td>
                                    <label>
                                        <input type="radio" name="fields[<?php echo esc_attr($field); ?>]" value="<?php echo esc_attr($side); ?>" <?php checked($info['default'], $side); ?>>
                                        <?php echo $info[$side] === '' ? '<em>' . esc_html__('leeg', 'avpvh-members') . '</em>' : esc_html($info[$side]); ?>
                                    </label>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h3><?php esc_html_e('Adressen', 'avpvh-members'); ?></h3>
            <?php
            $format_address = fn(object $a): string => trim("{$a->street} {$a->house_number}, {$a->postal_code} {$a->city}", ' ,');
            $format_address_date = fn(?string $date, string $empty): string => $date
                ? wp_date((string) get_option('date_format'), strtotime($date))
                : $empty;
            $format_address_period = fn(object $a): string => $format_address_date($a->valid_from, __('onbekend', 'avpvh-members'))
                . ' – ' . $format_address_date($a->valid_until, __('heden', 'avpvh-members'));
            ?>
            <p class="description">
                <?php esc_html_e('Overnemen bewaart de getoonde begin- en einddatum. Bestaande periodes worden niet automatisch ingekort of aaneengesloten; controleer daarom eventuele overlap hieronder.', 'avpvh-members'); ?>
            </p>
            <h4><?php esc_html_e('Adreshistorie van het behouden lid', 'avpvh-members'); ?></h4>
            <?php if (!$preview['addresses']['keep']) : ?>
                <p><em><?php esc_html_e('Geen adressen.', 'avpvh-members'); ?></em></p>
            <?php else : ?>
                <table class="widefat striped avpvh-address-history">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Adres', 'avpvh-members'); ?></th>
                            <th><?php esc_html_e('Van', 'avpvh-members'); ?></th>
                            <th><?php esc_html_e('Tot', 'avpvh-members'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($preview['addresses']['keep'] as $address) : ?>
                        <tr>
                            <td><?php echo esc_html($format_address($address)); ?></td>
                            <td><?php echo esc_html($format_address_date($address->valid_from, __('onbekend', 'avpvh-members'))); ?></td>
                            <td><?php echo esc_html($format_address_date($address->valid_until, __('heden', 'avpvh-members'))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <?php if (!$preview['addresses']['remove']) : ?>
                <p><?php esc_html_e('Het dubbele lid heeft geen adressen.', 'avpvh-members'); ?></p>
            <?php else : ?>
                <h4><?php esc_html_e('Adressen van het dubbele lid', 'avpvh-members'); ?></h4>
                <table class="widefat striped avpvh-address-merge">
                    <thead><tr>
                        <th><?php esc_html_e('Adres', 'avpvh-members'); ?></th>
                        <th><?php esc_html_e('Periode', 'avpvh-members'); ?></th>
                        <th><?php esc_html_e('Aansluiting op bestaande historie', 'avpvh-members'); ?></th>
                        <th><?php esc_html_e('Actie', 'avpvh-members'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($preview['addresses']['remove'] as $address) :
                        $address_id = (int) $address->id;
                        $default = $preview['address_defaults'][$address_id];
                        $overlaps = $preview['address_overlaps'][$address_id] ?? [];
                        ?>
                        <tr>
                            <td data-col="<?php esc_attr_e('Adres', 'avpvh-members'); ?>"><?php echo esc_html($format_address($address)); ?></td>
                            <td data-col="<?php esc_attr_e('Periode', 'avpvh-members'); ?>"><?php echo esc_html($format_address_period($address)); ?></td>
                            <td data-col="<?php esc_attr_e('Aansluiting', 'avpvh-members'); ?>">
                                <?php if (!$overlaps) : ?>
                                    <span class="avpvh-address-fit avpvh-address-fit--ok"><?php esc_html_e('Geen overlap met bekende periodes.', 'avpvh-members'); ?></span>
                                <?php else : ?>
                                    <span class="avpvh-address-fit avpvh-address-fit--warning">
                                        <?php echo esc_html(sprintf(_n('Overlapt met %d bekende periode:', 'Overlapt met %d bekende periodes:', count($overlaps), 'avpvh-members'), count($overlaps))); ?>
                                    </span>
                                    <ul class="avpvh-address-overlaps">
                                        <?php foreach ($overlaps as $overlap) : ?>
                                            <li><?php echo esc_html($format_address($overlap) . ' (' . $format_address_period($overlap) . ')'); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </td>
                            <td>
                                <select name="addresses[<?php echo esc_attr($address->id); ?>]">
                                    <option value="move" <?php selected($default, 'move'); ?>><?php esc_html_e('Overnemen — periode behouden', 'avpvh-members'); ?></option>
                                    <?php if (empty($address->valid_until)) : ?>
                                        <option value="history" <?php selected($default, 'history'); ?>><?php esc_html_e('Overnemen — afsluiten op vandaag', 'avpvh-members'); ?></option>
                                    <?php endif; ?>
                                    <option value="delete" <?php selected($default, 'delete'); ?>><?php esc_html_e('Niet overnemen', 'avpvh-members'); ?></option>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h3><?php esc_html_e('Wat er verder meegaat', 'avpvh-members'); ?></h3>
            <?php
            $count_labels = [
                'Inlogadressen'        => __('Inlogadressen', 'avpvh-members'),
                'Kenmerken'            => __('Kenmerken', 'avpvh-members'),
                'Relaties'             => __('Relaties', 'avpvh-members'),
                'Naamvarianten'        => __('Naamvarianten', 'avpvh-members'),
                'Wijzigingslog-regels' => __('Wijzigingslog-regels', 'avpvh-members'),
                'Roldelegaties'        => __('Roldelegaties', 'avpvh-members'),
            ];
            ?>
            <ul style="list-style: disc; margin-left: 1.5rem;">
                <?php foreach ($preview['participation'] as $row) : ?>
                    <li>
                        <?php echo esc_html(sprintf(__('Activiteit %s', 'avpvh-members'), $row['name'])); ?>
                        <?php if ($row['conflict']) : ?>
                            — <em><?php esc_html_e('beiden deden mee: de bestaande deelname blijft, aangevuld met ontbrekende gegevens en dagen', 'avpvh-members'); ?></em>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
                <?php foreach ($preview['fees'] as $fee) : ?>
                    <li>
                        <?php echo esc_html(sprintf(__('Contributie %s (%s)', 'avpvh-members'), $fee['year'], $fee['status'] === 'paid' ? __('Betaald', 'avpvh-members') : ($fee['status'] === 'unpaid' ? __('Openstaand', 'avpvh-members') : $fee['status']))); ?>
                        <?php if ($fee['conflict']) : ?>
                            — <em><?php echo $fee['wins'] ? esc_html__('deze vervangt de openstaande regel van het behouden lid', 'avpvh-members') : esc_html__('de regel van het behouden lid blijft, deze vervalt', 'avpvh-members'); ?></em>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
                <?php foreach ($preview['counts'] as $label => $count) : ?>
                    <li><?php echo esc_html(($count_labels[$label] ?? $label) . ": $count"); ?></li>
                <?php endforeach; ?>
                <?php foreach ($preview['external'] as $column => $count) : ?>
                    <li><?php echo esc_html("$column: $count"); ?> <em>(<?php esc_html_e('andere plugin', 'avpvh-members'); ?>)</em></li>
                <?php endforeach; ?>
                <?php if (!$preview['participation'] && !$preview['fees'] && !$preview['counts'] && !$preview['external']) : ?>
                    <li><?php esc_html_e('Niets — het dubbele lid heeft geen verdere gegevens.', 'avpvh-members'); ?></li>
                <?php endif; ?>
            </ul>

            <?php if (!$preview['blockers']) : ?>
                <p>
                    <label>
                        <input type="checkbox" name="confirm" value="1" required>
                        <?php esc_html_e('Ik heb gecontroleerd dat dit dezelfde persoon is.', 'avpvh-members'); ?>
                    </label>
                </p>
                <?php submit_button(__('Samenvoegen', 'avpvh-members'), 'primary', 'submit', false); ?>
            <?php endif; ?>
        </form>
    <?php else :
        $candidates = AVPVH_Member_Merge::find_duplicate_candidates(); ?>
        <hr>
        <h2><?php esc_html_e('Mogelijke dubbelen', 'avpvh-members'); ?></h2>
        <p class="description"><?php esc_html_e('Leden met dezelfde voornaam en achternaam (hoofdletters en de plek van het tussenvoegsel genegeerd), ook via een vastgelegde naamvariant. Dit is alleen een suggestie — controleer altijd of het echt dezelfde persoon is.', 'avpvh-members'); ?></p>
        <?php if (!$candidates) : ?>
            <p><?php esc_html_e('Geen mogelijke dubbelen gevonden.', 'avpvh-members'); ?></p>
        <?php else : ?>
            <table class="widefat striped" style="max-width: 900px;">
                <tbody>
                <?php foreach ($candidates as $group) : ?>
                    <tr>
                        <td>
                            <?php foreach ($group as $m) : ?>
                                <a href="<?php echo esc_url($detail_url((int) $m->id)); ?>" target="_blank"><?php echo esc_html($option_label($m)); ?></a>
                                <?php if ($m->matched_via !== '') : ?>
                                    <em>(<?php echo esc_html(sprintf(__('via naamvariant %s', 'avpvh-members'), $m->matched_via)); ?>)</em>
                                <?php endif; ?>
                                <br>
                            <?php endforeach; ?>
                        </td>
                        <td>
                            <a class="button button-small" href="<?php echo esc_url($page_url(['keep' => $group[0]->id, 'remove' => $group[1]->id])); ?>"><?php esc_html_e('Vergelijken', 'avpvh-members'); ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>
</div>
