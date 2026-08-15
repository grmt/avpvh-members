<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options')) wp_die('Geen toegang.');

require_once AVPVH_PLUGIN_DIR . 'admin/class-activity-participation-list-table.php';

$activities = AVPVH_DB::get_activities();
$current_activity = AVPVH_DB::get_current_camp_activity();
$activity_id = (int) ($_GET['activity_id'] ?? ($current_activity->id ?? 0));
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
?>
<div class="wrap">
    <h1 class="wp-heading-inline">Activiteiten</h1>
    <?php if (!$is_contribution) : ?>
        <a href="<?php echo esc_url($new_url); ?>" class="page-title-action">Nieuwe deelname</a>
        <?php if ($activity_id) : ?>
            <a href="<?php echo esc_url($export_url); ?>" class="page-title-action">Exporteer naar Excel</a>
        <?php endif; ?>
    <?php endif; ?>

    <form method="get" style="margin: 1rem 0;">
        <input type="hidden" name="page" value="avpvh-activity-participation">
        <label>Activiteit:
            <select name="activity_id" onchange="this.form.submit()">
                <?php foreach ($activities as $activity_option) : ?>
                    <option value="<?php echo esc_attr($activity_option->id); ?>" <?php selected($activity_option->id, $activity_id); ?>>
                        <?php echo esc_html($activity_option->name . ' (' . $activity_option->year . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <noscript><button type="submit" class="button">Bekijken</button></noscript>
    </form>

    <?php if ($activity_created) : ?>
        <div class="notice notice-success"><p>
            Activiteit aangemaakt.
            <a href="<?php echo esc_url(add_query_arg(['page' => 'avbk-rates', 'activity_id' => $activity_id], admin_url('admin.php'))); ?>">Tarieven instellen voor deze activiteit &rarr;</a>
        </p></div>
    <?php endif; ?>

    <details style="margin-bottom:1rem;">
        <summary>Nieuwe activiteit aanmaken</summary>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:.5rem;">
            <?php wp_nonce_field('avpvh_create_activity'); ?>
            <input type="hidden" name="action" value="avpvh_create_activity">
            <table class="form-table">
                <tr>
                    <th><label for="new_name">Naam</label></th>
                    <td><input type="text" id="new_name" name="name" class="regular-text" required placeholder="bijv. Contributie, of een locatienaam voor een kamp"></td>
                </tr>
                <tr>
                    <th><label for="new_year">Jaar</label></th>
                    <td><input type="number" id="new_year" name="year" required min="2000" max="2100" value="<?php echo esc_attr(current_time('Y')); ?>" style="width:6em"></td>
                </tr>
                <tr>
                    <th><label for="new_type_id">Type</label></th>
                    <td>
                        <select id="new_type_id" name="type_id">
                            <option value="">&mdash; geen &mdash;</option>
                            <?php foreach ($activity_types as $activity_type) : ?>
                                <option value="<?php echo esc_attr($activity_type->id); ?>"><?php echo esc_html($activity_type->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="new_kenmerk">Locatie/kenmerk</label></th>
                    <td><input type="text" id="new_kenmerk" name="kenmerk" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="new_start_date">Startdatum</label></th>
                    <td><input type="date" id="new_start_date" name="start_date"></td>
                </tr>
                <tr>
                    <th><label for="new_end_date">Einddatum</label></th>
                    <td><input type="date" id="new_end_date" name="end_date"></td>
                </tr>
            </table>
            <p class="description">Bestaat er al een activiteit met deze naam en dit jaar, dan wordt die geopend in plaats van een dubbele aan te maken.</p>
            <p class="submit"><button type="submit" class="button button-primary">Activiteit aanmaken</button></p>
        </form>
    </details>

    <?php if ($activity) : ?>
        <?php if ($activity_saved) : ?>
            <div class="notice notice-success"><p>Instellingen opgeslagen.</p></div>
        <?php endif; ?>
        <?php if ($types_saved) : ?>
            <div class="notice notice-success"><p>Activiteitstypes opgeslagen.</p></div>
        <?php endif; ?>
        <details style="margin-bottom:1rem;">
            <summary>Instellingen (type, locatie/kenmerk, start-/einddatum)</summary>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:.5rem;">
                <?php wp_nonce_field('avpvh_save_activity'); ?>
                <input type="hidden" name="action" value="avpvh_save_activity">
                <input type="hidden" name="activity_id" value="<?php echo esc_attr($activity->id); ?>">
                <table class="form-table">
                    <tr>
                        <th><label for="type_id">Type</label></th>
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
                        <th><label for="kenmerk">Locatie/kenmerk</label></th>
                        <td><input type="text" id="kenmerk" name="kenmerk" class="regular-text" value="<?php echo esc_attr($activity->kenmerk); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="start_date">Startdatum</label></th>
                        <td><input type="date" id="start_date" name="start_date" value="<?php echo esc_attr($activity->start_date); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="end_date">Einddatum</label></th>
                        <td><input type="date" id="end_date" name="end_date" value="<?php echo esc_attr($activity->end_date); ?>"></td>
                    </tr>
                </table>
                <p class="submit"><button type="submit" class="button">Opslaan</button></p>
            </form>
        </details>

        <details style="margin-bottom:1rem;">
            <summary>Activiteitstypes beheren (hernoemen of nieuwe toevoegen)</summary>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:.5rem;">
                <?php wp_nonce_field('avpvh_save_activity_types'); ?>
                <input type="hidden" name="action" value="avpvh_save_activity_types">
                <input type="hidden" name="activity_id" value="<?php echo esc_attr($activity->id); ?>">
                <table class="form-table">
                    <?php foreach ($activity_types as $activity_type) : ?>
                        <tr>
                            <th><label for="type_name_<?php echo esc_attr($activity_type->id); ?>">Type</label></th>
                            <td>
                                <input type="text" id="type_name_<?php echo esc_attr($activity_type->id); ?>"
                                       name="type_name[<?php echo esc_attr($activity_type->id); ?>]"
                                       class="regular-text" value="<?php echo esc_attr($activity_type->name); ?>">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <th><label for="new_type_name">Nieuw type</label></th>
                        <td><input type="text" id="new_type_name" name="new_type_name" class="regular-text" placeholder="bijv. Excursie"></td>
                    </tr>
                </table>
                <p class="submit"><button type="submit" class="button">Opslaan</button></p>
            </form>
        </details>
    <?php endif; ?>

    <?php $table->display(); ?>
</div>
