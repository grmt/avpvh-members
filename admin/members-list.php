<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('secretaris')) wp_die('Geen toegang.');

require_once AVPVH_PLUGIN_DIR . 'admin/class-members-list-table.php';

$table = new AVPVH_Members_List_Table();
$table->prepare_items();

$search      = sanitize_text_field($_GET['s'] ?? '');
$f_first     = sanitize_text_field($_GET['f_first_name'] ?? '');
$f_suffix    = sanitize_text_field($_GET['f_suffix'] ?? '');
$f_last      = sanitize_text_field($_GET['f_last_name'] ?? '');
$statuses    = array_map('sanitize_key', (array) ($_GET['status'] ?? []));
$joined_year = sanitize_text_field($_GET['joined_year'] ?? '');
$fee_statuses = array_map('sanitize_key', (array) ($_GET['fee_status'] ?? []));
$flag_ids     = array_map('intval', (array) ($_GET['flag_id'] ?? []));
$all_flags    = AVPVH_DB::get_all_flags();
$current_year = (int) current_time('Y');
$has_filters = $search || $f_first || $f_suffix || $f_last || $statuses || $joined_year || $fee_statuses || $flag_ids;
?>
<style>
    .avpvh-member-row-inactive,
    .avpvh-member-row-visitor {
        background: #f0f0f1 !important;
        color: #767676;
    }
    .avpvh-member-row-inactive a,
    .avpvh-member-row-visitor a {
        color: #7e8993;
    }
    .avpvh-multiselect { position: relative; display: block; width: 100%; }
    .avpvh-multiselect__toggle {
        width: 100%; text-align: left; background: #fff; border: 1px solid #8c8f94;
        border-radius: 3px; padding: 3px 8px; cursor: pointer;
    }
    .avpvh-multiselect__toggle::after { content: "\25BE"; float: right; }
    .avpvh-multiselect__panel {
        display: none; position: absolute; z-index: 10; top: 100%; left: 0;
        background: #fff; border: 1px solid #8c8f94; border-radius: 3px;
        padding: .5em .75em; margin-top: 2px; min-width: 100%;
        box-shadow: 0 2px 6px rgba(0,0,0,.15);
    }
    .avpvh-multiselect__panel.is-open { display: block; }
    .avpvh-multiselect__panel label { display: block; white-space: nowrap; padding: .2em 0; }

    /* Align every filter-row control (and the "Filteren"/"Wis" buttons) to
       the same height — mixing plain inputs, selects and custom buttons
       otherwise each bring their own default padding/line-height. Matched
       to 40px (not shrunk to WP core's usual ~30px) because core's own
       admin.css already renders text inputs/buttons at 40px here with
       higher specificity than a plain class selector can override without
       !important — easier to grow the two custom elements to match than
       fight core's cascade on the rest. */
    .avpvh-multiselect__toggle {
        height: 40px !important;
        box-sizing: border-box;
    }
    #avpvh-search {
        width: 280px;
        height: 40px !important;
        box-sizing: border-box;
        vertical-align: middle;
    }
</style>
<div class="wrap">
    <h1>AVP-PvH Leden</h1>
    <p>
        <a href="<?php echo esc_url(add_query_arg(['page' => 'avpvh-add-member'], admin_url('admin.php'))); ?>" class="button">Nieuw lid</a>
    </p>

    <form method="get" id="avpvh-filter-form">
        <input type="hidden" name="page" value="avpvh-members">

        <p style="margin-bottom:.25rem">
            <label for="avpvh-search" style="font-weight:600;margin-right:.4em">Zoeken:</label>
            <input type="search" id="avpvh-search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Naam of e-mail">
        </p>

        <p style="font-weight:600;margin-bottom:.25rem">Filter:</p>
        <table class="avpvh-column-filters" style="margin-bottom: .5rem;">
            <tr>
                <td>
                    <input type="text" name="f_first_name" value="<?php echo esc_attr($f_first); ?>" placeholder="Filter voornaam" style="width:100%">
                </td>
                <td>
                    <input type="text" name="f_suffix" value="<?php echo esc_attr($f_suffix); ?>" placeholder="Filter tussenvoegsel" style="width:100%">
                </td>
                <td>
                    <input type="text" name="f_last_name" value="<?php echo esc_attr($f_last); ?>" placeholder="Filter achternaam" style="width:100%">
                </td>
                <td>
                    <div class="avpvh-multiselect" data-default-label="Alle statussen">
                        <button type="button" class="avpvh-multiselect__toggle">Alle statussen</button>
                        <div class="avpvh-multiselect__panel">
                            <?php foreach (['active' => 'Actief', 'inactive' => 'Ex-lid', 'visitor' => 'Bezoeker'] as $value => $option_label) : ?>
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
                           placeholder="Lid sinds jaar" min="1900" max="<?php echo esc_attr($current_year); ?>" style="width:100%">
                </td>
                <td>
                    <div class="avpvh-multiselect" data-default-label="Alle contributies">
                        <button type="button" class="avpvh-multiselect__toggle">Alle contributies</button>
                        <div class="avpvh-multiselect__panel">
                            <?php foreach (['paid' => 'Betaald', 'pending' => 'Openstaand', 'waived' => 'Vrijgesteld', 'none' => 'Geen record'] as $value => $option_label) : ?>
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
                        <em class="description">Geen kenmerken</em>
                    <?php else : ?>
                        <div class="avpvh-multiselect" data-default-label="Alle kenmerken">
                            <button type="button" class="avpvh-multiselect__toggle">Alle kenmerken</button>
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
                    <button type="submit" class="button">Filteren</button>
                    <?php if ($has_filters) : ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=avpvh-members')); ?>" class="button">Wis</a>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <?php $table->display(); ?>
    </form>
</div>
<script>
(function () {
    var openPanels = function () {
        return document.querySelectorAll('.avpvh-multiselect__panel.is-open');
    };

    document.querySelectorAll('.avpvh-multiselect').forEach(function (ms) {
        var toggle = ms.querySelector('.avpvh-multiselect__toggle');
        var panel = ms.querySelector('.avpvh-multiselect__panel');
        var defaultLabel = ms.getAttribute('data-default-label');

        var updateLabel = function () {
            var checked = panel.querySelectorAll('input[type=checkbox]:checked');
            if (checked.length === 0) {
                toggle.textContent = defaultLabel;
            } else if (checked.length === 1) {
                toggle.textContent = checked[0].parentElement.textContent.trim();
            } else {
                toggle.textContent = checked.length + ' geselecteerd';
            }
        };

        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var wasOpen = panel.classList.contains('is-open');
            openPanels().forEach(function (p) { p.classList.remove('is-open'); });
            if (!wasOpen) {
                panel.classList.add('is-open');
            }
        });

        panel.addEventListener('change', updateLabel);
        panel.addEventListener('click', function (e) { e.stopPropagation(); });
        updateLabel();
    });

    document.addEventListener('click', function () {
        openPanels().forEach(function (p) { p.classList.remove('is-open'); });
    });
})();
</script>
