<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options')) wp_die('Geen toegang.');

$search = sanitize_text_field($_GET['s'] ?? '');
$status = sanitize_key($_GET['status'] ?? '');

$args = [];
if ($search) $args['search'] = $search;
if ($status) $args['status'] = $status;
$members      = AVPVH_DB::get_members($args);
$current_year = (int) date('Y');
?>
<div class="wrap">
    <h1 class="wp-heading-inline">AVP-PvH Leden</h1>

    <form method="get">
        <input type="hidden" name="page" value="avpvh-members">
        <p class="search-box">
            <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Naam of e-mail">
            <select name="status">
                <option value="">Alle statussen</option>
                <option value="active"   <?php selected($status, 'active'); ?>>Actief</option>
                <option value="inactive" <?php selected($status, 'inactive'); ?>>Ex-lid</option>
                <option value="visitor"  <?php selected($status, 'visitor'); ?>>Bezoeker</option>
            </select>
            <button type="submit" class="button">Filteren</button>
        </p>
    </form>

    <table class="wp-list-table widefat striped">
        <thead>
            <tr>
                <th>Naam</th>
                <th>E-mail</th>
                <th>Status</th>
                <th>Lid sinds</th>
                <th>Contributie <?php echo esc_html($current_year); ?></th>
                <th>Kampen</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$members) : ?>
            <tr><td colspan="7">Geen leden gevonden.</td></tr>
        <?php else : ?>
            <?php foreach ($members as $m) :
                global $wpdb;
                $fee = AVPVH_DB::get_fee_for_year((int) $m->id, $current_year);
                $camp_count = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}avm_camp_participation WHERE member_id = %d",
                    $m->id
                ));
                $detail_url = add_query_arg(['page' => 'avpvh-member-detail', 'id' => $m->id], admin_url('admin.php'));
            ?>
            <tr>
                <td><a href="<?php echo esc_url($detail_url); ?>"><?php echo esc_html($m->last_name . ', ' . $m->first_name); ?></a></td>
                <td><?php echo esc_html($m->email); ?></td>
                <td><?php echo esc_html($m->status); ?></td>
                <td><?php echo esc_html($m->joined_year ?: '—'); ?></td>
                <td><?php echo $fee ? esc_html($fee->status) : '—'; ?></td>
                <td><?php echo esc_html($camp_count); ?></td>
                <td><a href="<?php echo esc_url($detail_url); ?>" class="button button-small">Details</a></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    <p><?php echo esc_html(count($members)); ?> leden gevonden.</p>
</div>
