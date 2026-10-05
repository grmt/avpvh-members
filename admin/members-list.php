<?php
defined('ABSPATH') || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- this is a single-execution admin-page template (included once per request via AVPVH_Admin::render_*()), not shared library code; its top-level variables are effectively function-local to this one include, not a real global-namespace collision risk
if (!AVPVH_Roles::can_manage_members()) wp_die(esc_html__('Geen toegang.', 'avpvh-members'));

require_once AVPVH_PLUGIN_DIR . 'admin/class-members-list-table.php';

$table = new AVPVH_Members_List_Table();
$table->prepare_items();

$search      = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
$f_first     = sanitize_text_field(wp_unslash($_GET['f_first_name'] ?? ''));
$f_suffix    = sanitize_text_field(wp_unslash($_GET['f_suffix'] ?? ''));
$f_last      = sanitize_text_field(wp_unslash($_GET['f_last_name'] ?? ''));
$statuses    = array_map('sanitize_key', (array) wp_unslash($_GET['status'] ?? []));
$joined_year = sanitize_text_field(wp_unslash($_GET['joined_year'] ?? ''));
$fee_statuses = array_map('sanitize_key', (array) wp_unslash($_GET['fee_status'] ?? []));
$flag_ids     = array_map('intval', (array) wp_unslash($_GET['flag_id'] ?? []));
$all_flags    = AVPVH_DB::get_all_flags();
$current_year = (int) current_time('Y');
$has_filters = $search || $f_first || $f_suffix || $f_last || $statuses || $joined_year || $fee_statuses || $flag_ids;
?>
<div class="wrap">
    <h1><?php esc_html_e('AVP-PvH Leden', 'avpvh-members'); ?></h1>
    <p>
        <a href="<?php echo esc_url(add_query_arg(['page' => 'avpvh-add-member'], admin_url('admin.php'))); ?>" class="button"><?php esc_html_e('Nieuwe persoon', 'avpvh-members'); ?></a>
    </p>

    <form method="get" id="avpvh-filter-form">
        <input type="hidden" name="page" value="avpvh-members">

        <p style="margin-bottom:.25rem">
            <label for="avpvh-search" style="font-weight:600;margin-right:.4em"><?php esc_html_e('Zoeken:', 'avpvh-members'); ?></label>
            <input type="search" id="avpvh-search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Naam of e-mail', 'avpvh-members'); ?>">
        </p>

        <p style="font-weight:600;margin-bottom:.25rem"><?php esc_html_e('Filter:', 'avpvh-members'); ?></p>
        <table class="avpvh-column-filters" style="margin-bottom: .5rem;">
            <tr>
                <td>
                    <input type="text" name="f_first_name" value="<?php echo esc_attr($f_first); ?>" placeholder="<?php esc_attr_e('Filter voornaam', 'avpvh-members'); ?>" style="width:100%">
                </td>
                <td>
                    <input type="text" name="f_suffix" value="<?php echo esc_attr($f_suffix); ?>" placeholder="<?php esc_attr_e('Filter tussenvoegsel', 'avpvh-members'); ?>" style="width:100%">
                </td>
                <td>
                    <input type="text" name="f_last_name" value="<?php echo esc_attr($f_last); ?>" placeholder="<?php esc_attr_e('Filter achternaam', 'avpvh-members'); ?>" style="width:100%">
                </td>
                <td>
                    <div class="avpvh-multiselect" data-default-label="<?php esc_attr_e('Alle statussen', 'avpvh-members'); ?>">
                        <button type="button" class="avpvh-multiselect__toggle"><?php esc_html_e('Alle statussen', 'avpvh-members'); ?></button>
                        <div class="avpvh-multiselect__panel">
                            <?php foreach (['active' => __('Actief', 'avpvh-members'), 'inactive' => __('Ex-lid', 'avpvh-members'), 'visitor' => __('Bezoeker', 'avpvh-members')] as $value => $option_label) : ?>
                                <label>
                                    <input type="checkbox" name="status[]" value="<?php echo esc_attr($value); ?>"
                                        <?php checked(in_array($value, $statuses, true)); ?>>
                                    <?php echo esc_html($option_label); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </td>
                <td>
                    <input type="number" name="joined_year" value="<?php echo esc_attr($joined_year); ?>"
                           placeholder="<?php esc_attr_e('Lid sinds jaar', 'avpvh-members'); ?>" min="1900" max="<?php echo esc_attr($current_year); ?>" style="width:100%">
                </td>
                <td>
                    <div class="avpvh-multiselect" data-default-label="<?php esc_attr_e('Alle contributies', 'avpvh-members'); ?>">
                        <button type="button" class="avpvh-multiselect__toggle"><?php esc_html_e('Alle contributies', 'avpvh-members'); ?></button>
                        <div class="avpvh-multiselect__panel">
                            <?php foreach (['paid' => __('Betaald', 'avpvh-members'), 'pending' => __('Openstaand', 'avpvh-members'), 'waived' => __('Vrijgesteld', 'avpvh-members'), 'none' => __('Geen record', 'avpvh-members')] as $value => $option_label) : ?>
                                <label>
                                    <input type="checkbox" name="fee_status[]" value="<?php echo esc_attr($value); ?>"
                                        <?php checked(in_array($value, $fee_statuses, true)); ?>>
                                    <?php echo esc_html($option_label); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </td>
                <td>
                    <?php if (!$all_flags) : ?>
                        <em class="description"><?php esc_html_e('Geen kenmerken', 'avpvh-members'); ?></em>
                    <?php else : ?>
                        <div class="avpvh-multiselect" data-default-label="<?php esc_attr_e('Alle kenmerken', 'avpvh-members'); ?>">
                            <button type="button" class="avpvh-multiselect__toggle"><?php esc_html_e('Alle kenmerken', 'avpvh-members'); ?></button>
                            <div class="avpvh-multiselect__panel">
                                <?php foreach ($all_flags as $flag) : ?>
                                    <label>
                                        <input type="checkbox" name="flag_id[]" value="<?php echo esc_attr($flag->id); ?>"
                                            <?php checked(in_array((int) $flag->id, $flag_ids, true)); ?>>
                                        <?php echo esc_html($flag->label); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </td>
                <td>
                    <button type="submit" class="button"><?php esc_html_e('Filteren', 'avpvh-members'); ?></button>
                    <?php if ($has_filters) : ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=avpvh-members')); ?>" class="button"><?php esc_html_e('Wis', 'avpvh-members'); ?></a>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <?php $table->display(); ?>
    </form>
</div>

