<?php
defined('ABSPATH') || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- single-execution admin-page template (included once per request via AVPVH_Admin::render_merge_members())
if (!AVPVH_Roles::can_merge_members()) {
    wp_die(esc_html__('Geen toegang.', 'avpvh-members'));
}

$result = get_transient('avpvh_address_cleanup_result_' . get_current_user_id());
if ($result) {
    delete_transient('avpvh_address_cleanup_result_' . get_current_user_id());
}
$groups = AVPVH_Address_Cleanup::find_groups();
$conflicts = AVPVH_Address_Cleanup::find_open_conflicts();
$proposals = array_filter($conflicts, fn($c) => $c['keep'] !== null);
$detail_url = fn(int $id): string => add_query_arg(['page' => 'avpvh-member-detail', 'id' => $id], admin_url('admin.php'));
$tab_url = fn(string $tab): string => add_query_arg(array_filter(['page' => 'avpvh-merge-members', 'tab' => $tab]), admin_url('admin.php'));
$format_date = fn(?string $date, string $empty): string => $date ? wp_date('j M Y', strtotime($date)) : $empty;
?>
<div class="wrap">
    <h1><?php esc_html_e('Leden samenvoegen', 'avpvh-members'); ?></h1>
    <nav class="nav-tab-wrapper">
        <a href="<?php echo esc_url($tab_url('')); ?>" class="nav-tab"><?php esc_html_e('Dubbele leden', 'avpvh-members'); ?></a>
        <a href="<?php echo esc_url($tab_url('addresses')); ?>" class="nav-tab nav-tab-active"><?php esc_html_e('Adressen opschonen', 'avpvh-members'); ?></a>
    </nav>
    <p class="description">
        <?php esc_html_e('Adressen die volgens postcode en huisnummer hetzelfde zijn, maar verschillend geschreven ("…str" of "…straat", "40-I" of "40 1 hoog", leesfouten uit de import) of bij één lid dubbel staan. Kies per adres de juiste schrijfwijze; die wordt in alle regels gezet. Dubbele regels van één lid waarvan de periodes aansluiten of overlappen, kunnen tot één regel worden samengevoegd.', 'avpvh-members'); ?>
    </p>

    <?php if ($result) : ?>
        <?php if (!empty($result['error'])) : ?>
            <div class="notice notice-error"><p><?php echo esc_html($result['error']); ?></p></div>
        <?php elseif (!empty($result['open'])) : ?>
            <div class="notice notice-success"><p>
                <?php echo esc_html(sprintf(
                    /* translators: 1: members, 2: addresses closed */
                    __('Bij %1$d lid/leden %2$d oud(e) adres(sen) afgesloten.', 'avpvh-members'),
                    $result['members'], $result['closed']
                )); ?>
                <?php if ($result['skipped']) : ?>
                    <?php echo esc_html(sprintf(/* translators: %d: number of members */ __('%d lid/leden overgeslagen omdat hun adressen intussen gewijzigd waren.', 'avpvh-members'), $result['skipped'])); ?>
                <?php endif; ?>
            </p></div>
        <?php elseif (!empty($result['bulk'])) : ?>
            <div class="notice notice-success"><p>
                <?php echo esc_html(sprintf(
                    /* translators: 1: addresses, 2: rows removed */
                    __('%1$d adres(sen) opgeschoond, %2$d dubbele regel(s) samengevoegd.', 'avpvh-members'),
                    $result['groups'], $result['removed']
                )); ?>
                <?php if ($result['skipped']) : ?>
                    <?php echo esc_html(sprintf(/* translators: %d: number of addresses */ __('%d adres(sen) overgeslagen omdat ze intussen gewijzigd waren.', 'avpvh-members'), $result['skipped'])); ?>
                <?php endif; ?>
            </p></div>
        <?php else : ?>
            <div class="notice notice-success"><p>
                <?php echo esc_html(sprintf(
                    /* translators: 1: address, 2: rows respelled, 3: rows removed */
                    __('%1$s: %2$d regel(s) aangepast, %3$d dubbele regel(s) samengevoegd.', 'avpvh-members'),
                    $result['address'], $result['updated'], $result['removed']
                )); ?>
            </p></div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($conflicts) : ?>
        <h2 id="avpvh-open-addresses"><?php esc_html_e('Meer dan één doorlopend adres', 'avpvh-members'); ?></h2>
        <p class="description"><?php echo esc_html(sprintf(
            /* translators: %d: number of members */
            __('Er kan maar één adres doorlopen tot heden. %d leden hebben meerdere verschillende adressen zonder einddatum. Voorstel: het adres met de laatste begindatum blijft doorlopen, de andere eindigen de dag voordat dat adres begon.', 'avpvh-members'),
            count($conflicts)
        )); ?></p>
        <?php if ($proposals) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('avpvh_address_close_older'); ?>
                <input type="hidden" name="action" value="avpvh_address_close_older">
                <?php foreach ($proposals as $conflict) : ?>
                    <input type="hidden" name="fingerprints[<?php echo esc_attr((string) $conflict['member_id']); ?>]" value="<?php echo esc_attr($conflict['fingerprint']); ?>">
                <?php endforeach; ?>
                <p><button type="submit" class="button"><?php echo esc_html(sprintf(/* translators: %d: number of members */ __('Voorstel toepassen bij alle %d leden', 'avpvh-members'), count($proposals))); ?></button></p>
            </form>
        <?php endif; ?>
        <table class="widefat striped" style="margin-bottom:2em">
            <thead><tr>
                <th><?php esc_html_e('Lid', 'avpvh-members'); ?></th>
                <th><?php esc_html_e('Doorlopende adressen', 'avpvh-members'); ?></th>
                <th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($conflicts as $conflict) :
                $first = $conflict['rows'][0];
                ?>
                <tr>
                    <td><a href="<?php echo esc_url($detail_url((int) $conflict['member_id'])); ?>"><?php echo esc_html(avpvh_format_name($first)); ?></a></td>
                    <td>
                        <table class="avpvh-open-address-periods">
                            <thead><tr>
                                <th><?php esc_html_e('Adres', 'avpvh-members'); ?></th>
                                <th><?php esc_html_e('Van', 'avpvh-members'); ?></th>
                                <th><?php esc_html_e('Tot (na voorstel)', 'avpvh-members'); ?></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach (array_reverse($conflict['rows']) as $row) :
                                $id = (int) $row->id;
                                ?>
                                <tr>
                                    <td><?php echo esc_html(AVPVH_Address_Cleanup::format(AVPVH_Address_Cleanup::fields($row))); ?></td>
                                    <td><?php echo esc_html($format_date($row->valid_from, __('onbekend', 'avpvh-members'))); ?></td>
                                    <td>
                                        <?php if (isset($conflict['close'][$id])) : ?>
                                            <strong><?php echo esc_html($format_date($conflict['close'][$id], '')); ?></strong>
                                            <span class="avpvh-address-fit avpvh-address-fit--warning"><?php esc_html_e('nieuwe einddatum', 'avpvh-members'); ?></span>
                                        <?php else : ?>
                                            <?php esc_html_e('heden', 'avpvh-members'); ?>
                                            <?php if ($id === $conflict['keep']) : ?>
                                                <span class="avpvh-address-fit avpvh-address-fit--ok"><?php esc_html_e('blijft doorlopen', 'avpvh-members'); ?></span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </td>
                    <td>
                        <?php if ($conflict['keep'] === null) : ?>
                            <span class="description"><?php esc_html_e('Niet duidelijk welk adres het nieuwste is; zet de einddatum in Ledendetail.', 'avpvh-members'); ?></span>
                        <?php else : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php wp_nonce_field('avpvh_address_close_older'); ?>
                                <input type="hidden" name="action" value="avpvh_address_close_older">
                                <input type="hidden" name="fingerprints[<?php echo esc_attr((string) $conflict['member_id']); ?>]" value="<?php echo esc_attr($conflict['fingerprint']); ?>">
                                <button type="submit" class="button button-small"><?php esc_html_e('Afsluiten', 'avpvh-members'); ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <h2><?php esc_html_e('Zelfde adres, verschillend geschreven of dubbel', 'avpvh-members'); ?></h2>
    <?php endif; ?>

    <?php if (!$groups) : ?>
        <p><?php esc_html_e('Geen adressen om op te schonen.', 'avpvh-members'); ?></p>
    <?php else :
        $duplicates_only = array_filter($groups, fn($g) => count($g['variants']) === 1 && $g['clusters']);
        $duplicate_rows = array_sum(array_map(fn($g) => array_sum(array_map(fn($c) => count($c) - 1, $g['clusters'])), $duplicates_only));
        ?>
        <p><?php echo esc_html(sprintf(
            /* translators: 1: number of addresses, 2: of which with spelling differences */
            __('%1$d adressen om na te kijken, waarvan %2$d met verschillende schrijfwijzen.', 'avpvh-members'),
            count($groups), count($groups) - count($duplicates_only)
        )); ?></p>
        <?php if ($duplicates_only) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('avpvh_address_cleanup_bulk'); ?>
                <input type="hidden" name="action" value="avpvh_address_cleanup_bulk">
                <?php foreach ($duplicates_only as $group) : ?>
                    <input type="hidden" name="fingerprints[<?php echo esc_attr($group['key']); ?>]" value="<?php echo esc_attr($group['fingerprint']); ?>">
                <?php endforeach; ?>
                <p>
                    <button type="submit" class="button"><?php echo esc_html(sprintf(
                        /* translators: 1: rows, 2: addresses */
                        __('Alle %1$d dubbele regels samenvoegen bij %2$d adressen zonder spellingsverschil', 'avpvh-members'),
                        $duplicate_rows, count($duplicates_only)
                    )); ?></button>
                    <span class="description"><?php esc_html_e('Adressen met verschillende schrijfwijzen sla je hiermee over; die kies je hieronder per adres.', 'avpvh-members'); ?></span>
                </p>
            </form>
        <?php endif; ?>
    <?php endif; ?>

    <?php foreach ($groups as $group) :
        $form_id = 'avpvh-address-group-' . substr(md5($group['key']), 0, 10);
        $clustered = array_merge([], ...$group['clusters']);
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="avpvh-address-cleanup card" id="<?php echo esc_attr($form_id); ?>" style="max-width:none">
            <?php wp_nonce_field('avpvh_address_cleanup'); ?>
            <input type="hidden" name="action" value="avpvh_address_cleanup">
            <input type="hidden" name="group_key" value="<?php echo esc_attr($group['key']); ?>">
            <input type="hidden" name="fingerprint" value="<?php echo esc_attr($group['fingerprint']); ?>">

            <h2 class="title"><?php echo esc_html(AVPVH_Address_Cleanup::format($group['default'])); ?></h2>

            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e('Lid', 'avpvh-members'); ?></th>
                    <th><?php esc_html_e('Zoals opgeslagen', 'avpvh-members'); ?></th>
                    <th><?php esc_html_e('Periode', 'avpvh-members'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($group['rows'] as $row) : ?>
                    <tr>
                        <td><a href="<?php echo esc_url($detail_url((int) $row->member_id)); ?>"><?php echo esc_html(avpvh_format_name($row)); ?></a></td>
                        <td><?php echo esc_html(AVPVH_Address_Cleanup::format(AVPVH_Address_Cleanup::fields($row))); ?></td>
                        <td>
                            <?php echo esc_html($format_date($row->valid_from, __('onbekend', 'avpvh-members')) . ' – ' . $format_date($row->valid_until, __('heden', 'avpvh-members'))); ?>
                            <?php if (in_array((int) $row->id, $clustered, true)) : ?>
                                <span class="avpvh-address-fit avpvh-address-fit--warning"><?php esc_html_e('dubbel', 'avpvh-members'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if (count($group['variants']) > 1) : ?>
                <fieldset style="margin-top:1em">
                    <legend><strong><?php esc_html_e('Schrijfwijze', 'avpvh-members'); ?></strong></legend>
                    <?php foreach ($group['variants'] as $i => $variant) : ?>
                        <label style="display:block">
                            <input type="radio" name="variant" class="avpvh-address-variant" <?php checked($i, 0); ?>
                                data-fields="<?php echo esc_attr((string) wp_json_encode($variant['fields'])); ?>">
                            <?php echo esc_html(AVPVH_Address_Cleanup::format($variant['fields'])); ?>
                            <span class="description">(<?php echo esc_html(sprintf(/* translators: %d: number of rows */ _n('%d regel', '%d regels', $variant['count'], 'avpvh-members'), $variant['count'])); ?>)</span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
            <?php endif; ?>

            <p class="avpvh-address-cleanup-fields">
                <?php foreach ([
                    'street'       => [__('Straat', 'avpvh-members'), 24],
                    'house_number' => [__('Huisnummer', 'avpvh-members'), 6],
                    'house_number_addition' => [__('Toevoeging', 'avpvh-members'), 4],
                    'postal_code'  => [__('Postcode', 'avpvh-members'), 8],
                    'city'         => [__('Plaats', 'avpvh-members'), 16],
                ] as $field => [$label, $size]) : ?>
                    <label><?php echo esc_html($label); ?>
                        <input type="text" name="<?php echo esc_attr($field); ?>" size="<?php echo esc_attr((string) $size); ?>" value="<?php echo esc_attr($group['default'][$field]); ?>" required>
                    </label>
                <?php endforeach; ?>
            </p>

            <?php if ($group['clusters']) : ?>
                <p><label>
                    <input type="checkbox" name="merge_runs" value="1" checked>
                    <?php esc_html_e('Dubbele regels per lid samenvoegen tot één regel (vroegste begin, laatste einde)', 'avpvh-members'); ?>
                </label></p>
            <?php endif; ?>

            <p><button type="submit" class="button button-primary"><?php esc_html_e('Toepassen', 'avpvh-members'); ?></button></p>
        </form>
    <?php endforeach; ?>
</div>
