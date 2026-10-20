<?php
defined('ABSPATH') || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- this is a single-execution admin-page template (included once per request via AVPVH_Admin::render_*()), not shared library code; its top-level variables are effectively function-local to this one include, not a real global-namespace collision risk
if (!AVPVH_Roles::can_manage_members()) wp_die(esc_html__('Geen toegang.', 'avpvh-members'));

// Every member is rendered at once; sorting, filtering (under the column
// headers) and column show/hide happen client-side in
// assets/sortable-table.js, the same component as avpvh-bookkeeping's
// tables. Deep links: ?f_<column>=<value> (e.g. f_status=Bezoeker) and ?s=.
global $wpdb;
$members      = AVPVH_DB::get_members(['orderby' => 'last_name', 'order' => 'asc']);
$current_year = (int) current_time('Y');

// One query each instead of one per row.
$activity_counts = [];
foreach ($wpdb->get_results("SELECT member_id, COUNT(*) AS n FROM {$wpdb->prefix}avm_activity_participation GROUP BY member_id") as $row) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fixed query, no user input
    $activity_counts[(int) $row->member_id] = (int) $row->n;
}
// Recorded name variants (maiden name, old spelling, ...) — the search box
// also finds a member by those, like the old server-side search did.
$alias_names = [];
foreach ($wpdb->get_results("SELECT member_id, first_name, suffix, last_name FROM {$wpdb->prefix}avm_member_name_aliases") as $row) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fixed query, no user input
    $alias_names[(int) $row->member_id][] = trim(implode(' ', array_filter([$row->first_name, $row->suffix, $row->last_name])));
}

$status_labels = [
    'active'   => __('Actief', 'avpvh-members'),
    'inactive' => __('Ex-lid', 'avpvh-members'),
    'visitor'  => __('Bezoeker', 'avpvh-members'),
];
$fee_labels = [
    'paid'    => __('Betaald', 'avpvh-members'),
    'pending' => __('Openstaand', 'avpvh-members'),
    'waived'  => __('Vrijgesteld', 'avpvh-members'),
];
?>
<div class="wrap">
    <h1><?php esc_html_e('AVP-PvH Leden', 'avpvh-members'); ?></h1>
    <?php if (!empty($_GET['visitor_deleted']) && current_user_can('manage_options')) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('De bezoeker en alle gekoppelde testgegevens zijn definitief verwijderd.', 'avpvh-members'); ?></p></div>
    <?php endif; ?>
    <p>
        <a href="<?php echo esc_url(add_query_arg(['page' => 'avpvh-add-member'], admin_url('admin.php'))); ?>" class="button"><?php esc_html_e('Nieuwe persoon', 'avpvh-members'); ?></a>
    </p>

    <?php if (!$members) : ?>
        <p><?php esc_html_e('Geen leden gevonden.', 'avpvh-members'); ?></p>
    <?php else : ?>
    <div data-avpvh-sortable
         data-i18n-all="<?php esc_attr_e('Alle', 'avpvh-members'); ?>"
         data-i18n-selected="<?php /* translators: %d: number of checked filter values */ esc_attr_e('%d geselecteerd', 'avpvh-members'); ?>"
         data-i18n-excluded="<?php /* translators: %d: number of excluded filter values */ esc_attr_e('%d uitgesloten', 'avpvh-members'); ?>"
         data-i18n-filter="<?php esc_attr_e('Filter…', 'avpvh-members'); ?>"
         data-i18n-count="<?php /* translators: 1: number of members shown, 2: total number of members */ esc_attr_e('%1$d van %2$d personen', 'avpvh-members'); ?>">
        <div class="avpvh-table-tools">
            <label for="avpvh-search" class="screen-reader-text"><?php esc_html_e('Zoeken:', 'avpvh-members'); ?></label>
            <input type="search" id="avpvh-search" class="avpvh-table-search" placeholder="<?php esc_attr_e('Naam of e-mail', 'avpvh-members'); ?>">
            <span>
                <button type="button" class="button button-small avpvh-col-toggle-btn"><?php esc_html_e('Kolommen', 'avpvh-members'); ?></button>
                <span class="avpvh-col-toggle-panel" hidden></span>
            </span>
            <span class="avpvh-table-count" aria-live="polite"></span>
        </div>
        <div class="avpvh-table-wrap">
        <table class="widefat striped avpvh-sortable-table" data-storage-key="avpvh_members_hidden_cols">
            <thead>
                <tr>
                    <th scope="col" data-col="name"><?php esc_html_e('Naam', 'avpvh-members'); ?></th>
                    <th scope="col" data-col="first_name" class="avpvh-col-optional"><?php esc_html_e('Voornaam', 'avpvh-members'); ?></th>
                    <th scope="col" data-col="suffix" class="avpvh-col-optional"><?php esc_html_e('Tussenvoegsel', 'avpvh-members'); ?></th>
                    <th scope="col" data-col="last_name" class="avpvh-col-optional"><?php esc_html_e('Achternaam', 'avpvh-members'); ?></th>
                    <th scope="col" data-col="passport_name" class="avpvh-col-optional"><?php esc_html_e('Paspoortnaam', 'avpvh-members'); ?></th>
                    <th scope="col" data-col="email"><?php esc_html_e('E-mailadressen', 'avpvh-members'); ?></th>
                    <th scope="col" data-col="status" data-filter="select"><?php esc_html_e('Status', 'avpvh-members'); ?></th>
                    <th scope="col" data-col="joined_year" data-type="number" class="avpvh-col-optional"><?php esc_html_e('Lid sinds', 'avpvh-members'); ?></th>
                    <th scope="col" data-col="fee_status" data-filter="select"><?php echo esc_html(sprintf(/* translators: %d: year */ __('Contributie %d', 'avpvh-members'), $current_year)); ?></th>
                    <th scope="col" data-col="activity_count" data-type="number" class="avpvh-col-optional"><?php esc_html_e('Activiteiten', 'avpvh-members'); ?></th>
                    <th scope="col" data-col="flags" data-filter="select" data-filter-sep="|"><?php esc_html_e('Kenmerken', 'avpvh-members'); ?></th>
                    <th scope="col"><span class="screen-reader-text"><?php esc_html_e('Acties', 'avpvh-members'); ?></span></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($members as $item) :
                $member_id  = (int) $item->id;
                $detail_url = add_query_arg(['page' => 'avpvh-member-detail', 'id' => $member_id], admin_url('admin.php'));
                $identities = AVPVH_DB::get_member_identities($member_id);
                $fee        = AVPVH_DB::get_fee_for_year($member_id, $current_year);
                $fee_label  = $fee ? ($fee_labels[$fee->status] ?? $fee->status) : __('Geen record', 'avpvh-members');
                $flag_names = wp_list_pluck(AVPVH_DB::get_flags_for_member($member_id), 'label');
                $status     = $status_labels[$item->status] ?? $item->status;
                ?>
                <tr class="avpvh-member-row-<?php echo esc_attr($item->status); ?>"
                    data-search="<?php echo esc_attr(implode(' ', $alias_names[$member_id] ?? [])); ?>">
                    <td data-sort-value="<?php echo esc_attr($item->last_name . ' ' . $item->first_name . ' ' . $item->suffix); ?>">
                        <a href="<?php echo esc_url($detail_url); ?>"><strong><?php echo esc_html(avpvh_format_name($item, 'list_suffix')); ?></strong></a>
                    </td>
                    <td><?php echo esc_html($item->first_name); ?></td>
                    <td data-filter-value="<?php echo esc_attr($item->suffix); ?>"><?php echo esc_html($item->suffix ?: '—'); ?></td>
                    <td><?php echo esc_html($item->last_name); ?></td>
                    <td data-filter-value="<?php echo esc_attr($item->passport_name); ?>"><?php echo esc_html($item->passport_name ?: '—'); ?></td>
                    <td>
                        <?php if (!$identities) :
                            $is_placeholder = str_ends_with(strtolower($item->email ?? ''), '@avpvh.local'); ?>
                            <div<?php echo $is_placeholder ? ' style="color:#d63638"' : ''; ?>><?php echo esc_html($item->email); ?></div>
                        <?php else :
                            foreach ($identities as $identity) :
                                $is_placeholder = str_ends_with(strtolower($identity->email), '@avpvh.local'); ?>
                                <div<?php echo $is_placeholder ? ' style="color:#d63638"' : ''; ?>>
                                    <?php echo esc_html($identity->email); ?>
                                    <?php if (empty($identity->verified_at)) : ?>
                                        <span style="color:#b32d2e;font-size:.85em;font-weight:600" title="<?php esc_attr_e('Toegevoegd door een beheerder, niet zelf geverifieerd', 'avpvh-members'); ?>">(<?php esc_html_e('niet geverifieerd', 'avpvh-members'); ?>)</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach;
                        endif; ?>
                    </td>
                    <td><?php echo esc_html($status); ?></td>
                    <td data-sort-value="<?php echo esc_attr($item->joined_year ?: ''); ?>" data-filter-value="<?php echo esc_attr($item->joined_year ?: ''); ?>"><?php echo esc_html($item->joined_year ?: '—'); ?></td>
                    <td data-filter-value="<?php echo esc_attr($fee_label); ?>"><?php echo esc_html($fee ? $fee_label : '—'); ?></td>
                    <td><?php echo esc_html((string) ($activity_counts[$member_id] ?? 0)); ?></td>
                    <td data-filter-value="<?php echo esc_attr(implode('|', $flag_names)); ?>"><?php echo esc_html(implode(', ', $flag_names)); ?></td>
                    <td>
                        <a href="<?php echo esc_url($detail_url); ?>" class="button button-small"><?php esc_html_e('Details', 'avpvh-members'); ?></a>
                        <?php if ($item->status === 'visitor' && current_user_can('manage_options')) : ?>
                            <a href="<?php echo esc_url(add_query_arg(['page' => 'avpvh-delete-visitor', 'id' => $member_id], admin_url('admin.php'))); ?>" class="button button-small"><?php esc_html_e('Definitief verwijderen', 'avpvh-members'); ?></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>
</div>
