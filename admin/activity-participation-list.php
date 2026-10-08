<?php
defined('ABSPATH') || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- this is a single-execution admin-page template (included once per request via AVPVH_Admin::render_*()), not shared library code; its top-level variables are effectively function-local to this one include, not a real global-namespace collision risk
if (!AVPVH_Roles::can_manage_activities()) wp_die(__('Geen toegang.', 'avpvh-members'));

require_once AVPVH_PLUGIN_DIR . 'admin/class-activity-participation-list-table.php';

$activities = AVPVH_DB::get_activities();
$current_activity = AVPVH_DB::get_current_camp_activity();
$activity_id = absint(wp_unslash($_GET['activity_id'] ?? ($current_activity->id ?? 0)));
$activity = $activity_id ? AVPVH_DB::get_activity($activity_id) : null;
$activity_saved = !empty($_GET['activity_saved']);
$types_saved = !empty($_GET['types_saved']);
$activity_created = !empty($_GET['activity_created']);
$activity_types = AVPVH_DB::get_activity_types();

$is_contribution = $activity && ($activity->type_name ?? '') === 'Contributie';
$table = new AVPVH_Activity_Participation_List_Table($activity_id, $is_contribution);
$table->prepare_items();

$new_url = add_query_arg(['page' => 'avpvh-activity-participation-detail', 'activity_id' => $activity_id], admin_url('admin.php'));
$export_url = wp_nonce_url(
    add_query_arg(['action' => 'avpvh_export_activity_participation', 'activity_id' => $activity_id], admin_url('admin-post.php')),
    'avpvh_export_activity_participation'
);

$activity_years = array_values(array_unique(array_map(fn($a) => (int) $a->year, $activities)));
rsort($activity_years);
$activity_type_names = array_values(array_unique(array_map(fn($a) => (string) ($a->type_name ?? ''), $activities)));
sort($activity_type_names);
?>
<script type="application/json" id="avpvh-activity-picker-config"><?php echo wp_json_encode([
    'activities' => array_map(fn($a) => [
        'id'    => (int) $a->id,
        'year'  => (int) $a->year,
        'type'  => (string) ($a->type_name ?? ''),
        'label' => $a->name . ' (' . $a->year . ')',
    ], $activities),
]); ?></script>
<div class="wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e('Activiteiten', 'avpvh-members'); ?></h1>
    <?php if (!$is_contribution) : ?>
        <a href="<?php echo esc_url($new_url); ?>" class="page-title-action"><?php esc_html_e('Nieuwe deelname', 'avpvh-members'); ?></a>
        <?php if ($activity_id) : ?>
            <a href="<?php echo esc_url($export_url); ?>" class="page-title-action"><?php esc_html_e('Exporteer naar Excel', 'avpvh-members'); ?></a>
        <?php endif; ?>
    <?php endif; ?>

    <form method="get" style="margin: 1rem 0;" id="avpvh-activity-picker-form">
        <input type="hidden" name="page" value="avpvh-activity-participation">
        <label><?php esc_html_e('Jaar:', 'avpvh-members'); ?>
            <select id="avpvh-activity-year-filter">
                <option value="">&mdash; <?php esc_html_e('alle jaren', 'avpvh-members'); ?> &mdash;</option>
                <?php foreach ($activity_years as $year) : ?>
                    <option value="<?php echo esc_attr($year); ?>" <?php selected($activity && (int) $activity->year === $year); ?>>
                        <?php echo esc_html((string) $year); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><?php esc_html_e('Type:', 'avpvh-members'); ?>
            <select id="avpvh-activity-type-filter">
                <option value="">&mdash; <?php esc_html_e('alle types', 'avpvh-members'); ?> &mdash;</option>
                <?php foreach ($activity_type_names as $type_name) : ?>
                    <option value="<?php echo esc_attr($type_name); ?>" <?php selected($activity && ($activity->type_name ?? '') === $type_name); ?>>
                        <?php echo esc_html($type_name !== '' ? $type_name : __('(geen type)', 'avpvh-members')); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><?php esc_html_e('Activiteit:', 'avpvh-members'); ?>
            <div class="avpvh-activity-combo">
                <input type="hidden" name="activity_id" id="avpvh-activity-combo-value" value="<?php echo esc_attr($activity_id ?: ''); ?>">
                <input type="text" class="avpvh-activity-combo-input" autocomplete="off" placeholder="&mdash; <?php esc_attr_e('kies activiteit', 'avpvh-members'); ?> &mdash;" value="<?php echo esc_attr($activity ? $activity->name . ' (' . $activity->year . ')' : ''); ?>">
                <div class="avpvh-activity-combo-list" hidden></div>
            </div>
        </label>
        <noscript><button type="submit" class="button"><?php esc_html_e('Bekijken', 'avpvh-members'); ?></button></noscript>
    </form>

    <?php if ($activity_created) : ?>
        <div class="notice notice-success"><p>
            <?php esc_html_e('Activiteit aangemaakt.', 'avpvh-members'); ?>
            <a href="<?php echo esc_url(add_query_arg(['page' => 'avbk-rates', 'activity_id' => $activity_id], admin_url('admin.php'))); ?>"><?php esc_html_e('Tarieven instellen voor deze activiteit →', 'avpvh-members'); ?></a>
        </p></div>
    <?php endif; ?>

    <details style="margin-bottom:1rem;">
        <summary><?php esc_html_e('Nieuwe activiteit aanmaken', 'avpvh-members'); ?></summary>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:.5rem;">
            <?php wp_nonce_field('avpvh_create_activity'); ?>
            <input type="hidden" name="action" value="avpvh_create_activity">
            <table class="form-table">
                <tr>
                    <th><label for="new_name"><?php esc_html_e('Naam', 'avpvh-members'); ?></label></th>
                    <td><input type="text" id="new_name" name="name" class="regular-text" required placeholder="<?php echo esc_attr__('bijv. Contributie, of een locatienaam voor een kamp', 'avpvh-members'); ?>"></td>
                </tr>
                <tr>
                    <th><label for="new_year"><?php esc_html_e('Jaar', 'avpvh-members'); ?></label></th>
                    <td><input type="number" id="new_year" name="year" required min="2000" max="2100" value="<?php echo esc_attr(current_time('Y')); ?>" style="width:6em"></td>
                </tr>
                <tr>
                    <th><label for="new_type_id"><?php esc_html_e('Type', 'avpvh-members'); ?></label></th>
                    <td>
                        <select id="new_type_id" name="type_id">
                            <option value="">&mdash; <?php esc_html_e('geen', 'avpvh-members'); ?> &mdash;</option>
                            <?php foreach ($activity_types as $activity_type) : ?>
                                <option value="<?php echo esc_attr($activity_type->id); ?>"><?php echo esc_html($activity_type->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="new_kenmerk"><?php esc_html_e('Locatie/kenmerk', 'avpvh-members'); ?></label></th>
                    <td><input type="text" id="new_kenmerk" name="kenmerk" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="new_start_date"><?php esc_html_e('Startdatum', 'avpvh-members'); ?></label></th>
                    <td><input type="date" id="new_start_date" name="start_date"></td>
                </tr>
                <tr>
                    <th><label for="new_end_date"><?php esc_html_e('Einddatum', 'avpvh-members'); ?></label></th>
                    <td><input type="date" id="new_end_date" name="end_date"></td>
                </tr>
            </table>
            <p class="description"><?php esc_html_e('Bestaat er al een activiteit met deze naam en dit jaar, dan wordt die geopend in plaats van een dubbele aan te maken.', 'avpvh-members'); ?></p>
            <p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e('Activiteit aanmaken', 'avpvh-members'); ?></button></p>
        </form>
    </details>

    <?php if ($activity) : ?>
        <?php if ($activity_saved) : ?>
            <div class="notice notice-success"><p><?php esc_html_e('Instellingen opgeslagen.', 'avpvh-members'); ?></p></div>
        <?php endif; ?>
        <?php if ($types_saved) : ?>
            <div class="notice notice-success"><p><?php esc_html_e('Activiteitstypes opgeslagen.', 'avpvh-members'); ?></p></div>
        <?php endif; ?>
        <details style="margin-bottom:1rem;">
            <summary><?php esc_html_e('Instellingen (type, locatie/kenmerk, start-/einddatum, website)', 'avpvh-members'); ?></summary>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:.5rem;">
                <?php wp_nonce_field('avpvh_save_activity'); ?>
                <input type="hidden" name="action" value="avpvh_save_activity">
                <input type="hidden" name="activity_id" value="<?php echo esc_attr($activity->id); ?>">
                <table class="form-table">
                    <tr>
                        <th><label for="type_id"><?php esc_html_e('Type', 'avpvh-members'); ?></label></th>
                        <td>
                            <select id="type_id" name="type_id">
                                <?php foreach ($activity_types as $activity_type) : ?>
                                    <option value="<?php echo esc_attr($activity_type->id); ?>" <?php selected($activity->type_id ?? 0, $activity_type->id); ?>>
                                        <?php echo esc_html($activity_type->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="kenmerk"><?php esc_html_e('Locatie/kenmerk', 'avpvh-members'); ?></label></th>
                        <td><input type="text" id="kenmerk" name="kenmerk" class="regular-text" value="<?php echo esc_attr($activity->kenmerk); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="gallery_taggable"><?php esc_html_e("Foto's taggen", 'avpvh-members'); ?></label></th>
                        <td><label><input type="checkbox" id="gallery_taggable" name="gallery_taggable" value="1" <?php checked(!empty($activity->gallery_taggable)); ?>> <?php esc_html_e('Deelnemers van deze activiteit gebruiken als tag-suggesties in de fotogalerij', 'avpvh-members'); ?></label></td>
                    </tr>
                    <tr>
                        <th><label for="start_date"><?php esc_html_e('Startdatum', 'avpvh-members'); ?></label></th>
                        <td><input type="date" id="start_date" name="start_date" value="<?php echo esc_attr($activity->start_date); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="end_date"><?php esc_html_e('Einddatum', 'avpvh-members'); ?></label></th>
                        <td><input type="date" id="end_date" name="end_date" value="<?php echo esc_attr($activity->end_date); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="show_on_site"><?php esc_html_e('Website', 'avpvh-members'); ?></label></th>
                        <td>
                            <label><input type="checkbox" id="show_on_site" name="show_on_site" value="1" <?php checked(!empty($activity->show_on_site)); ?>> <?php esc_html_e('Tonen op de website', 'avpvh-members'); ?></label>
                            <p class="description"><?php esc_html_e('Komende activiteiten staan in de agenda, geweest activiteiten in de lijsten (reünieweekenden, uitjes).', 'avpvh-members'); ?> <code>[avpvh_activiteiten]</code></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="location"><?php esc_html_e('Waar', 'avpvh-members'); ?></label></th>
                        <td><textarea id="location" name="location" rows="3" class="large-text"><?php echo esc_textarea($activity->location ?? ''); ?></textarea>
                            <p class="description"><?php esc_html_e('Plaats, adres en eventueel een link; HTML-opmaak (vet, links) mag. Leeg: "Locatie/kenmerk" wordt getoond.', 'avpvh-members'); ?></p></td>
                    </tr>
                    <tr>
                        <th><label for="description"><?php esc_html_e('Beschrijving', 'avpvh-members'); ?></label></th>
                        <td><textarea id="description" name="description" rows="3" class="large-text"><?php echo esc_textarea($activity->description ?? ''); ?></textarea>
                            <p class="description"><?php esc_html_e('Wat er gedaan werd/wordt. Leeg: de naam wordt getoond.', 'avpvh-members'); ?></p></td>
                    </tr>
                    <tr>
                        <th><label for="details"><?php esc_html_e('Details', 'avpvh-members'); ?></label></th>
                        <td><textarea id="details" name="details" rows="2" class="large-text"><?php echo esc_textarea($activity->details ?? ''); ?></textarea></td>
                    </tr>
                </table>
                <p class="submit"><button type="submit" class="button"><?php esc_html_e('Opslaan', 'avpvh-members'); ?></button></p>
            </form>
        </details>

        <details style="margin-bottom:1rem;">
            <summary><?php esc_html_e('Activiteitstypes beheren (hernoemen of nieuwe toevoegen)', 'avpvh-members'); ?></summary>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:.5rem;">
                <?php wp_nonce_field('avpvh_save_activity_types'); ?>
                <input type="hidden" name="action" value="avpvh_save_activity_types">
                <input type="hidden" name="activity_id" value="<?php echo esc_attr($activity->id); ?>">
                <table class="form-table">
                    <?php foreach ($activity_types as $activity_type) : ?>
                        <tr>
                            <th><label for="type_name_<?php echo esc_attr($activity_type->id); ?>"><?php esc_html_e('Type', 'avpvh-members'); ?></label></th>
                            <td>
                                <input type="text" id="type_name_<?php echo esc_attr($activity_type->id); ?>"
                                       name="type_name[<?php echo esc_attr($activity_type->id); ?>]"
                                       class="regular-text" value="<?php echo esc_attr($activity_type->name); ?>">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <th><label for="new_type_name"><?php esc_html_e('Nieuw type', 'avpvh-members'); ?></label></th>
                        <td><input type="text" id="new_type_name" name="new_type_name" class="regular-text" placeholder="<?php echo esc_attr__('bijv. Excursie', 'avpvh-members'); ?>"></td>
                    </tr>
                </table>
                <p class="submit"><button type="submit" class="button"><?php esc_html_e('Opslaan', 'avpvh-members'); ?></button></p>
            </form>
        </details>
    <?php endif; ?>

    <?php $table->display(); ?>
</div>
