<?php
defined('ABSPATH') || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- this is a single-execution admin-page template (included once per request via AVPVH_Admin::render_*()), not shared library code; its top-level variables are effectively function-local to this one include, not a real global-namespace collision risk
if (!AVPVH_Roles::can_merge_members()) {
    wp_die(esc_html__('Geen toegang.', 'avpvh-members'));
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
$members = AVPVH_Member_Merge::get_member_options();

/**
 * Filterable text+list combobox (see assets/merge-members.js) instead of
 * a native <select> — a plain dropdown with every lid in one long list
 * is unworkable for picking a specific member by typing their name, same
 * reason avpvh-bookkeeping's review-queue person-picker isn't a <select>.
 */
function avpvh_merge_member_combo(string $name, array $members, int $selected_id, callable $option_label): void {
    $selected_label = '';
    if ($selected_id) {
        foreach ($members as $m) {
            if ((int) $m->id === $selected_id) {
                $selected_label = $option_label($m);
                break;
            }
        }
    }
    ?>
    <div class="avpvh-member-combo">
        <input type="hidden" name="<?php echo esc_attr($name); ?>" class="avpvh-member-combo-value" value="<?php echo esc_attr($selected_id ?: ''); ?>">
        <input type="text" id="avpvh-merge-<?php echo esc_attr($name); ?>" class="avpvh-member-combo-input" autocomplete="off" placeholder="&mdash; kies lid &mdash;" value="<?php echo esc_attr($selected_label); ?>">
        <div class="avpvh-member-combo-list" hidden></div>
    </div>
    <?php
}
?>
<script type="application/json" id="avpvh-merge-members-config"><?php echo wp_json_encode([
    'members' => array_map(fn($m) => ['id' => (int) $m->id, 'label' => $option_label($m)], $members),
]); ?></script>
<div class="wrap">
    <h1><?php esc_html_e('Leden samenvoegen', 'avpvh-members'); ?></h1>
    <nav class="nav-tab-wrapper">
        <a href="<?php echo esc_url($page_url()); ?>" class="nav-tab nav-tab-active"><?php esc_html_e('Dubbele leden', 'avpvh-members'); ?></a>
        <a href="<?php echo esc_url($page_url(['tab' => 'addresses'])); ?>" class="nav-tab"><?php esc_html_e('Adressen opschonen', 'avpvh-members'); ?></a>
    </nav>
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
                <?php foreach ($result['notes'] ?? [] as $note) : ?>
                    <p><?php echo esc_html($note); ?></p>
                <?php endforeach; ?>
            </div>
            <?php foreach ($result['warnings'] as $warning) : ?>
                <div class="notice notice-warning"><p><?php echo esc_html($warning); ?></p></div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>

    <form method="get" style="margin: 1em 0;">
        <input type="hidden" name="page" value="avpvh-merge-members">
        <table class="form-table">
            <tr>
                <th><label for="avpvh-merge-keep"><?php esc_html_e('Behouden', 'avpvh-members'); ?></label></th>
                <td>
                    <?php avpvh_merge_member_combo('keep', $members, $keep_id, $option_label); ?>
                </td>
            </tr>
            <tr>
                <th><label for="avpvh-merge-remove"><?php esc_html_e('Opheffen (dubbel)', 'avpvh-members'); ?></label></th>
                <td>
                    <?php avpvh_merge_member_combo('remove', $members, $remove_id, $option_label); ?>
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

            <?php
            $email = $preview['email'];
            $email_label = fn(string $address): string => $address === '' ? '<em>' . esc_html__('geen', 'avpvh-members') . '</em>' : esc_html($address);
            ?>
            <h3><?php esc_html_e('E-mailadres na samenvoegen', 'avpvh-members'); ?></h3>
            <fieldset class="avpvh-merge-email">
                <p><label>
                    <input type="radio" name="email[choice]" value="keep" <?php checked($email['default'], 'keep'); ?>>
                    <?php echo $email_label($email['keep']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $email_label ?>
                    <span class="description">(<?php esc_html_e('behouden lid', 'avpvh-members'); ?>)</span>
                </label></p>
                <?php if ($email['remove'] !== '' && strcasecmp($email['remove'], $email['keep']) !== 0) : ?>
                    <p><label>
                        <input type="radio" name="email[choice]" value="remove" <?php checked($email['default'], 'remove'); ?>>
                        <?php echo esc_html($email['remove']); ?>
                        <span class="description">(<?php esc_html_e('dubbele lid', 'avpvh-members'); ?>)</span>
                    </label></p>
                <?php endif; ?>
                <p>
                    <label>
                        <input type="radio" name="email[choice]" value="custom" id="avpvh-merge-email-custom-choice">
                        <?php esc_html_e('Ander of gecorrigeerd adres:', 'avpvh-members'); ?>
                    </label>
                    <label class="screen-reader-text" for="avpvh-merge-email-custom"><?php esc_html_e('Ander of gecorrigeerd adres', 'avpvh-members'); ?></label>
                    <input type="text" inputmode="email" autocomplete="off" class="regular-text" id="avpvh-merge-email-custom" name="email[custom]" value="<?php echo esc_attr($email['remove'] ?: $email['keep']); ?>">
                </p>
                <?php if ($email['remove'] !== '' && strcasecmp($email['remove'], $email['keep']) !== 0) : ?>
                    <p>
                        <input type="hidden" name="email[add_other]" value="0">
                        <label>
                            <input type="checkbox" name="email[add_other]" value="1" checked>
                            <?php echo esc_html(sprintf(/* translators: %s: e-mail address */ __('%s bewaren als extra (onbevestigd) inlogadres als het niet het e-mailadres wordt — vink uit als het adres fout is', 'avpvh-members'), $email['remove'])); ?>
                        </label>
                    </p>
                <?php endif; ?>
                <p class="description"><?php esc_html_e('Het gekozen adres wordt het e-mailadres van het account en het primaire inlogadres. Inlogadressen waarmee het dubbele lid al eens heeft ingelogd gaan altijd mee; een fout adres verwijder je daarna in Ledendetail.', 'avpvh-members'); ?></p>
            </fieldset>

            <h3><?php esc_html_e('Gegevens', 'avpvh-members'); ?></h3>
            <?php if (!$preview['fields']) : ?>
                <p><?php esc_html_e('Alle gegevens zijn gelijk.', 'avpvh-members'); ?></p>
            <?php else : ?>
                <p class="description"><?php esc_html_e('Alleen velden die verschillen. Privacykeuzes staan standaard op de strengste van de twee. Bij de naam kun je ook zelf een naam invullen. Een naam die wegvalt wordt bewaard als naamvariant.', 'avpvh-members'); ?></p>
                <table class="widefat striped" style="max-width: 900px;">
                    <thead><tr><th><?php esc_html_e('Veld', 'avpvh-members'); ?></th><th><?php esc_html_e('Behouden', 'avpvh-members'); ?></th><th><?php esc_html_e('Opheffen', 'avpvh-members'); ?></th><th><?php esc_html_e('Anders', 'avpvh-members'); ?></th></tr></thead>
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
                            <td>
                                <?php if ($field === 'name') : ?>
                                    <label>
                                        <input type="radio" name="fields[name]" value="custom" class="avpvh-merge-name-custom">
                                        <?php esc_html_e('Eigen naam:', 'avpvh-members'); ?>
                                    </label>
                                    <span class="avpvh-merge-name-fields">
                                        <?php foreach (['first_name' => __('Voornaam', 'avpvh-members'), 'suffix' => __('Tussenvoegsel', 'avpvh-members'), 'last_name' => __('Achternaam', 'avpvh-members')] as $part => $label) : ?>
                                            <input type="text" name="name_custom[<?php echo esc_attr($part); ?>]" size="<?php echo $part === 'suffix' ? 6 : 14; ?>"
                                                placeholder="<?php echo esc_attr($label); ?>" aria-label="<?php echo esc_attr($label); ?>"
                                                value="<?php echo esc_attr((string) $preview['keep']->$part); ?>">
                                        <?php endforeach; ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h3><?php esc_html_e('Adressen', 'avpvh-members'); ?></h3>
            <?php
            // Both records' addresses as one history, the same way and in the
            // same layout as Adressen opschonen (AVPVH_Address_Cleanup::plan_history()).
            $keep_member_id = (int) $preview['keep']->id;
            $format_date = fn(?string $date, string $empty): string => $date ? wp_date('j M Y', strtotime($date)) : $empty;
            $period = fn(?string $from, ?string $until): string => $format_date($from, __('onbekend', 'avpvh-members')) . ' – ' . $format_date($until, __('heden', 'avpvh-members'));
            AVPVH_Address_Lookup::prefetch(array_merge($preview['addresses']['keep'], $preview['addresses']['remove']));
            ?>
            <p class="description">
                <?php esc_html_e('De adressen van beide records samen, zoals bij Adressen opschonen: hetzelfde adres (ook anders geschreven) is één groep, periodes die aansluiten of overlappen worden één regel, en alleen het adres met de laatste begindatum loopt door. Kies per adres de schrijfwijze; een eigen einddatum en "niet overnemen" kan per adres.', 'avpvh-members'); ?>
            </p>
            <?php if (!$preview['address_plan']) : ?>
                <p><em><?php esc_html_e('Geen adressen.', 'avpvh-members'); ?></em></p>
            <?php endif; ?>
            <?php foreach ($preview['address_plan'] as $group) :
                $options = AVPVH_Address_Cleanup::spelling_options($group['variants'], $group['rows']);
                $prefix = 'address_groups[' . $group['id'] . ']';
                $only_remove = !array_filter($group['rows'], fn($r) => (int) $r->member_id === $keep_member_id);
                // Per stored row: the period it becomes, or null when it folds into another row.
                $owner = [];
                foreach ($group['rows'] as $row) {
                    $owner[(int) $row->id] = (int) $row->member_id;
                }
                $outcome = [];
                foreach ($group['results'] as $result) {
                    $ids = $result['ids'];
                    sort($ids);
                    $own = array_values(array_filter($ids, fn($id) => $owner[$id] === $keep_member_id));
                    $target = $own ? $own[0] : $ids[0];
                    foreach ($ids as $id) {
                        $outcome[$id] = $id === $target ? $result + ['folded' => count($ids) > 1] : null;
                    }
                }
                ?>
                <div class="avpvh-address-cleanup card" data-prefix="<?php echo esc_attr($prefix . '[fields]'); ?>" style="max-width:none">
                    <h4 class="title"><?php echo esc_html(AVPVH_Address_Cleanup::format($options[0]['fields'])); ?></h4>
                    <table class="widefat striped">
                        <thead><tr>
                            <th><?php esc_html_e('Record', 'avpvh-members'); ?></th>
                            <th><?php esc_html_e('Zoals opgeslagen', 'avpvh-members'); ?></th>
                            <th><?php esc_html_e('Van', 'avpvh-members'); ?></th>
                            <th><?php esc_html_e('Tot', 'avpvh-members'); ?></th>
                            <th><?php esc_html_e('Na samenvoegen', 'avpvh-members'); ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($group['rows'] as $row) :
                            $result = $outcome[(int) $row->id];
                            ?>
                            <tr>
                                <td><?php echo (int) $row->member_id === $keep_member_id ? esc_html__('Behouden', 'avpvh-members') : esc_html__('Opheffen', 'avpvh-members'); ?></td>
                                <td><?php echo esc_html(AVPVH_Address_Cleanup::format(AVPVH_Address_Cleanup::fields($row))); ?></td>
                                <td><?php echo esc_html($format_date($row->valid_from, __('onbekend', 'avpvh-members'))); ?></td>
                                <td><?php echo esc_html($format_date($row->valid_until, __('heden', 'avpvh-members'))); ?></td>
                                <td>
                                    <?php if ($result === null) : ?>
                                        <span class="avpvh-address-fit avpvh-address-fit--warning"><?php esc_html_e('vervalt (samengevoegd)', 'avpvh-members'); ?></span>
                                    <?php else : ?>
                                        <span class="avpvh-result-address"><?php echo esc_html(AVPVH_Address_Cleanup::format($options[0]['fields'])); ?></span>,
                                        <strong><?php echo esc_html($period($result['from'], $result['until'])); ?></strong>
                                        <?php if ($result['folded']) : ?>
                                            <span class="avpvh-address-fit avpvh-address-fit--ok"><?php esc_html_e('samengevoegd', 'avpvh-members'); ?></span>
                                        <?php endif; ?>
                                        <?php if ($result['closed']) : ?>
                                            <span class="avpvh-address-fit avpvh-address-fit--warning"><?php esc_html_e('afgesloten: er is een nieuwer adres', 'avpvh-members'); ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if (count($options) > 1) : ?>
                        <fieldset style="margin-top:1em">
                            <legend><strong><?php esc_html_e('Schrijfwijze', 'avpvh-members'); ?></strong></legend>
                            <?php foreach ($options as $i => $option) : ?>
                                <label style="display:block">
                                    <input type="radio" name="<?php echo esc_attr($prefix . '[variant]'); ?>" class="avpvh-address-variant" <?php checked($i, 0); ?>
                                        data-fields="<?php echo esc_attr((string) wp_json_encode($option['fields'])); ?>">
                                    <?php echo esc_html(AVPVH_Address_Cleanup::option_label($option)); ?>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                    <?php endif; ?>

                    <p class="avpvh-address-cleanup-fields">
                        <?php foreach ([
                            'street'                => [__('Straat', 'avpvh-members'), 24],
                            'house_number'          => [__('Huisnummer', 'avpvh-members'), 6],
                            'house_number_addition' => [__('Toevoeging', 'avpvh-members'), 4],
                            'postal_code'           => [__('Postcode', 'avpvh-members'), 8],
                            'city'                  => [__('Plaats', 'avpvh-members'), 16],
                        ] as $field => [$label, $size]) : ?>
                            <label><?php echo esc_html($label); ?>
                                <input type="text" name="<?php echo esc_attr($prefix . '[fields][' . $field . ']'); ?>" size="<?php echo esc_attr((string) $size); ?>" value="<?php echo esc_attr($options[0]['fields'][$field]); ?>">
                            </label>
                        <?php endforeach; ?>
                    </p>
                    <p>
                        <label><?php esc_html_e('Eigen einddatum (optioneel, laatste periode)', 'avpvh-members'); ?>
                            <input type="date" name="<?php echo esc_attr($prefix . '[until]'); ?>">
                        </label>
                        <?php if ($only_remove) : ?>
                            <label style="margin-left:1em">
                                <input type="checkbox" name="<?php echo esc_attr($prefix . '[drop]'); ?>" value="1">
                                <?php esc_html_e('Niet overnemen', 'avpvh-members'); ?>
                            </label>
                        <?php endif; ?>
                    </p>
                </div>
            <?php endforeach; ?>

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
                        <?php echo esc_html(sprintf(/* translators: %s: activity name */ __('Activiteit %s', 'avpvh-members'), $row['name'])); ?>
                        <?php if ($row['conflict']) : ?>
                            — <em><?php esc_html_e('beiden deden mee: de bestaande deelname blijft, aangevuld met ontbrekende gegevens en dagen', 'avpvh-members'); ?></em>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
                <?php foreach ($preview['fees'] as $fee) : ?>
                    <li>
                        <?php echo esc_html(sprintf(/* translators: 1: year, 2: fee status */ __('Contributie %1$s (%2$s)', 'avpvh-members'), $fee['year'], $fee['status'] === 'paid' ? __('Betaald', 'avpvh-members') : ($fee['status'] === 'unpaid' ? __('Openstaand', 'avpvh-members') : $fee['status']))); ?>
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
        <p class="description"><?php esc_html_e('Leden met dezelfde voornaam en achternaam (hoofdletters, accenten, leestekens en tussenvoegsels zoals v, vd, v/d, van de(n/r) genegeerd), ook via een vastgelegde naamvariant. Dit is alleen een suggestie — controleer altijd of het echt dezelfde persoon is.', 'avpvh-members'); ?></p>
        <?php if (!$candidates) : ?>
            <p><?php esc_html_e('Geen mogelijke dubbelen gevonden.', 'avpvh-members'); ?></p>
        <?php else : ?>
            <table class="widefat striped" style="max-width: 900px;">
                <thead><tr>
                    <th><?php esc_html_e('Mogelijke dubbelen', 'avpvh-members'); ?></th>
                    <th><span class="screen-reader-text"><?php esc_html_e('Actie', 'avpvh-members'); ?></span></th>
                </tr></thead>
                <tbody>
                <?php foreach ($candidates as $group) : ?>
                    <tr>
                        <td>
                            <?php foreach ($group as $m) : ?>
                                <a href="<?php echo esc_url($detail_url((int) $m->id)); ?>" target="_blank"><?php echo esc_html($option_label($m)); ?></a>
                                <?php if ($m->matched_via !== '') : ?>
                                    <em>(<?php echo esc_html(sprintf(/* translators: %s: the name variant that matched */ __('via naamvariant %s', 'avpvh-members'), $m->matched_via)); ?>)</em>
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
