<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options')) wp_die('Geen toegang.');

$search      = sanitize_text_field($_GET['s'] ?? '');
$status      = sanitize_key($_GET['status'] ?? '');
$joined_year = sanitize_text_field($_GET['joined_year'] ?? '');
$fee_status  = sanitize_key($_GET['fee_status'] ?? '');
$orderby     = sanitize_key($_GET['orderby'] ?? 'last_name');
$order       = strtoupper(sanitize_key($_GET['order'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
$current_year = (int) date('Y');

$args = [];
if ($search)         $args['search']      = $search;
if ($status)         $args['status']      = $status;
if ($joined_year)    $args['joined_year'] = $joined_year;
if ($fee_status !== '') {
    $args['fee_status'] = $fee_status;
    $args['fee_year']   = $current_year;
}
$args['orderby'] = $orderby;
$args['order']   = $order;

$members = AVPVH_DB::get_members($args);

// Build a sort URL for a column — toggles direction if already sorted by that column.
$sort_url = function (string $col) use ($search, $status, $joined_year, $fee_status, $orderby, $order): string {
    $new_order = ($orderby === $col && $order === 'ASC') ? 'DESC' : 'ASC';
    return add_query_arg(array_filter([
        'page'        => 'avpvh-members',
        's'           => $search,
        'status'      => $status,
        'joined_year' => $joined_year,
        'fee_status'  => $fee_status,
        'orderby'     => $col,
        'order'       => $new_order,
    ], fn($v) => $v !== ''), admin_url('admin.php'));
};

$sort_indicator = function (string $col) use ($orderby, $order): string {
    if ($orderby !== $col) return '';
    return $order === 'ASC' ? ' ▲' : ' ▼';
};
?>
<div class="wrap">
    <h1 class="wp-heading-inline">AVP-PvH Leden</h1>

    <form method="get" id="avpvh-filter-form">
        <input type="hidden" name="page" value="avpvh-members">
        <input type="hidden" name="orderby" value="<?php echo esc_attr($orderby); ?>">
        <input type="hidden" name="order" value="<?php echo esc_attr($order); ?>">
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th><a href="<?php echo esc_url($sort_url('last_name')); ?>">Achternaam<?php echo $sort_indicator('last_name'); ?></a></th>
                    <th><a href="<?php echo esc_url($sort_url('first_name')); ?>">Voornaam<?php echo $sort_indicator('first_name'); ?></a></th>
                    <th>Doopnaam</th>
                    <th>E-mail</th>
                    <th><a href="<?php echo esc_url($sort_url('status')); ?>">Status<?php echo $sort_indicator('status'); ?></a></th>
                    <th><a href="<?php echo esc_url($sort_url('joined_year')); ?>">Lid sinds<?php echo $sort_indicator('joined_year'); ?></a></th>
                    <th><a href="<?php echo esc_url($sort_url('fee_status')); ?>">Contributie <?php echo esc_html($current_year); ?><?php echo $sort_indicator('fee_status'); ?></a></th>
                    <th><a href="<?php echo esc_url($sort_url('camp_count')); ?>">Kampen<?php echo $sort_indicator('camp_count'); ?></a></th>
                    <th></th>
                </tr>
                <tr class="avpvh-filter-row">
                    <th colspan="3">
                        <input type="search" name="s" value="<?php echo esc_attr($search); ?>"
                               placeholder="Naam of doopnaam" style="width:100%;box-sizing:border-box">
                    </th>
                    <th></th>
                    <th>
                        <select name="status" style="width:100%">
                            <option value="">Alle</option>
                            <option value="active"   <?php selected($status, 'active'); ?>>Actief</option>
                            <option value="inactive" <?php selected($status, 'inactive'); ?>>Ex-lid</option>
                            <option value="visitor"  <?php selected($status, 'visitor'); ?>>Bezoeker</option>
                        </select>
                    </th>
                    <th>
                        <input type="number" name="joined_year" value="<?php echo esc_attr($joined_year); ?>"
                               placeholder="Jaar" min="1900" max="<?php echo esc_attr($current_year); ?>"
                               style="width:100%;box-sizing:border-box">
                    </th>
                    <th>
                        <select name="fee_status" style="width:100%">
                            <option value="">Alle</option>
                            <option value="paid"    <?php selected($fee_status, 'paid'); ?>>Betaald</option>
                            <option value="pending" <?php selected($fee_status, 'pending'); ?>>Openstaand</option>
                            <option value="waived"  <?php selected($fee_status, 'waived'); ?>>Vrijgesteld</option>
                            <option value="none"    <?php selected($fee_status, 'none'); ?>>Geen record</option>
                        </select>
                    </th>
                    <th></th>
                    <th></th>
                    <th>
                        <button type="submit" class="button button-small">Filteren</button>
                        <?php if ($search || $status || $joined_year || $fee_status !== '') : ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=avpvh-members')); ?>"
                               class="button button-small">Wis</a>
                        <?php endif; ?>
                    </th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$members) : ?>
                <tr><td colspan="9">Geen leden gevonden.</td></tr>
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
                    <td><a href="<?php echo esc_url($detail_url); ?>"><?php echo esc_html($m->last_name); ?></a></td>
                    <td><?php echo esc_html($m->first_name); ?></td>
                    <td><?php echo esc_html($m->baptism_name ?: '—'); ?></td>
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
    </form>
    <p><?php echo esc_html(count($members)); ?> leden gevonden.</p>
</div>
