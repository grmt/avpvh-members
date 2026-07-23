<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options')) wp_die('Geen toegang.');

require_once AVPVH_PLUGIN_DIR . 'admin/class-kampdeelname-list-table.php';

$camps = AVPVH_DB::get_camps();
$current_camp = AVPVH_DB::get_current_camp();
$camp_id = (int) ($_GET['camp_id'] ?? ($current_camp->id ?? 0));
$camp = $camp_id ? AVPVH_DB::get_camp($camp_id) : null;
$camp_saved = !empty($_GET['camp_saved']);

$table = new AVPVH_Kampdeelname_List_Table($camp_id);
$table->prepare_items();

$new_url = add_query_arg(['page' => 'avpvh-kampdeelname-detail', 'camp_id' => $camp_id], admin_url('admin.php'));
$export_url = wp_nonce_url(
    add_query_arg(['action' => 'avpvh_export_kampdeelname', 'camp_id' => $camp_id], admin_url('admin-post.php')),
    'avpvh_export_kampdeelname'
);
?>
<div class="wrap">
    <h1 class="wp-heading-inline">Kampdeelname</h1>
    <a href="<?php echo esc_url($new_url); ?>" class="page-title-action">Nieuwe deelname</a>
    <?php if ($camp_id) : ?>
        <a href="<?php echo esc_url($export_url); ?>" class="page-title-action">Exporteer naar Excel</a>
    <?php endif; ?>

    <form method="get" style="margin: 1rem 0;">
        <input type="hidden" name="page" value="avpvh-kampdeelname">
        <label>Kamp:
            <select name="camp_id" onchange="this.form.submit()">
                <?php foreach ($camps as $camp) : ?>
                    <option value="<?php echo esc_attr($camp->id); ?>" <?php selected($camp->id, $camp_id); ?>>
                        <?php echo esc_html($camp->name . ' (' . $camp->year . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <noscript><button type="submit" class="button">Bekijken</button></noscript>
    </form>

    <?php if ($camp) : ?>
        <?php if ($camp_saved) : ?>
            <div class="notice notice-success"><p>Kampinstellingen opgeslagen.</p></div>
        <?php endif; ?>
        <details style="margin-bottom:1rem;">
            <summary>Kampinstellingen (locatie, start-/einddatum)</summary>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:.5rem;">
                <?php wp_nonce_field('avpvh_save_camp'); ?>
                <input type="hidden" name="action" value="avpvh_save_camp">
                <input type="hidden" name="camp_id" value="<?php echo esc_attr($camp->id); ?>">
                <table class="form-table">
                    <tr>
                        <th><label for="location">Locatie</label></th>
                        <td><input type="text" id="location" name="location" class="regular-text" value="<?php echo esc_attr($camp->location); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="start_date">Startdatum</label></th>
                        <td><input type="date" id="start_date" name="start_date" value="<?php echo esc_attr($camp->start_date); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="end_date">Einddatum</label></th>
                        <td><input type="date" id="end_date" name="end_date" value="<?php echo esc_attr($camp->end_date); ?>"></td>
                    </tr>
                </table>
                <p class="submit"><button type="submit" class="button">Opslaan</button></p>
            </form>
        </details>
    <?php endif; ?>

    <?php $table->display(); ?>
</div>
