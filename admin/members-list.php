<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options')) wp_die('Geen toegang.');

require_once AVPVH_PLUGIN_DIR . 'admin/class-members-list-table.php';

$table = new AVPVH_Members_List_Table();
$table->prepare_items();

$search      = sanitize_text_field($_GET['s'] ?? '');
$f_first     = sanitize_text_field($_GET['f_first_name'] ?? '');
$f_suffix    = sanitize_text_field($_GET['f_suffix'] ?? '');
$f_last      = sanitize_text_field($_GET['f_last_name'] ?? '');
$status      = sanitize_key($_GET['status'] ?? '');
$joined_year = sanitize_text_field($_GET['joined_year'] ?? '');
$fee_status  = sanitize_key($_GET['fee_status'] ?? '');
$current_year = (int) date('Y');
$has_filters = $search || $f_first || $f_suffix || $f_last || $status || $joined_year || $fee_status !== '';
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
</style>
<div class="wrap">
    <h1 class="wp-heading-inline">AVP-PvH Leden</h1>

    <form method="get" id="avpvh-filter-form">
        <input type="hidden" name="page" value="avpvh-members">
        <p class="search-box">
            <label class="screen-reader-text" for="avpvh-search">Zoeken</label>
            <input type="search" id="avpvh-search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Naam, doopnaam of e-mail">
        </p>

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
                    <select name="status" style="width:100%">
                        <option value="">Alle statussen</option>
                        <option value="active"   <?php selected($status, 'active'); ?>>Actief</option>
                        <option value="inactive" <?php selected($status, 'inactive'); ?>>Ex-lid</option>
                        <option value="visitor"  <?php selected($status, 'visitor'); ?>>Bezoeker</option>
                    </select>
                </td>
                <td>
                    <input type="number" name="joined_year" value="<?php echo esc_attr($joined_year); ?>"
                           placeholder="Lid sinds jaar" min="1900" max="<?php echo esc_attr($current_year); ?>" style="width:100%">
                </td>
                <td>
                    <select name="fee_status" style="width:100%">
                        <option value="">Alle contributies</option>
                        <option value="paid"    <?php selected($fee_status, 'paid'); ?>>Betaald</option>
                        <option value="pending" <?php selected($fee_status, 'pending'); ?>>Openstaand</option>
                        <option value="waived"  <?php selected($fee_status, 'waived'); ?>>Vrijgesteld</option>
                        <option value="none"    <?php selected($fee_status, 'none'); ?>>Geen record</option>
                    </select>
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
