<?php
defined('ABSPATH') || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- admin-page template
if (!current_user_can('manage_options')) {
    wp_die(esc_html__('Geen toegang.', 'avpvh-members'), '', ['response' => 403]);
}
$id = absint(wp_unslash($_GET['id'] ?? 0));
$result_key = 'avpvh_visitor_delete_result_' . get_current_user_id();
$error = get_transient($result_key);
delete_transient($result_key);
$plan = AVPVH_Visitor_Delete::preview($id);
?>
<div class="wrap">
    <h1><?php esc_html_e('Bezoeker definitief verwijderen', 'avpvh-members'); ?></h1>
    <?php if ($error) : ?>
        <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
    <?php endif; ?>
    <p><a href="<?php echo esc_url(add_query_arg(['page' => 'avpvh-members', 'status' => ['visitor']], admin_url('admin.php'))); ?>"><?php esc_html_e('Terug naar bezoekers', 'avpvh-members'); ?></a></p>
    <?php if (is_wp_error($plan)) : ?>
        <div class="notice notice-error"><p><?php echo esc_html($plan->get_error_message()); ?></p></div>
    <?php else : ?>
        <h2><?php echo esc_html(avpvh_format_name((object) $plan['member'])); ?> — #<?php echo esc_html((string) $id); ?></h2>
        <p><?php echo esc_html(implode(', ', $plan['emails'])); ?></p>
        <p><?php esc_html_e('Controleer hieronder wat verdwijnt. Gebruik dit alleen voor testbezoekers. Verwijderen kan niet ongedaan worden gemaakt.', 'avpvh-members'); ?></p>
        <table class="widefat striped" style="max-width:800px">
            <thead><tr><th><?php esc_html_e('Gegevens', 'avpvh-members'); ?></th><th><?php esc_html_e('Aantal', 'avpvh-members'); ?></th></tr></thead>
            <tbody>
                <tr><td><?php esc_html_e('Bezoekersprofiel', 'avpvh-members'); ?></td><td>1</td></tr>
                <tr><td><?php esc_html_e('OpenLDAP-account en directorycache', 'avpvh-members'); ?></td><td><?php echo $plan['account'] ? '1' : '0'; ?></td></tr>
                <tr><td><?php esc_html_e('WordPress-accounts en profielgegevens', 'avpvh-members'); ?></td><td><?php echo count($plan['users']); ?></td></tr>
                <?php foreach ($plan['records'] as $record) : if (!$record['rows']) { continue; } ?>
                    <tr><td><?php echo esc_html($record['label']); ?></td><td><?php echo count($record['rows']); ?></td></tr>
                <?php endforeach; ?>
                <tr><td><?php esc_html_e('Persoonlijke Drive-mappen inclusief inhoud', 'avpvh-members'); ?></td><td><?php echo count($plan['folders']); ?></td></tr>
                <tr><td><?php esc_html_e('Openstaande verificatielinks', 'avpvh-members'); ?></td><td><?php echo count($plan['tokens']); ?></td></tr>
            </tbody>
        </table>
        <?php if ($plan['blockers']) : ?>
            <div class="notice notice-error"><p><strong><?php esc_html_e('Verwijderen is geblokkeerd.', 'avpvh-members'); ?></strong></p><ul>
                <?php foreach ($plan['blockers'] as $blocker) : ?><li><?php echo esc_html($blocker); ?></li><?php endforeach; ?>
            </ul></div>
        <?php else : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="avpvh_delete_visitor">
                <input type="hidden" name="member_id" value="<?php echo esc_attr((string) $id); ?>">
                <input type="hidden" name="fingerprint" value="<?php echo esc_attr($plan['fingerprint']); ?>">
                <?php wp_nonce_field('avpvh_delete_visitor_' . $id); ?>
                <p><label><input type="checkbox" name="is_test" value="1" required> <?php esc_html_e('Dit is een testaccount. Ik wil het account en alle hierboven getoonde gegevens definitief verwijderen.', 'avpvh-members'); ?></label></p>
                <p><label for="avpvh-delete-confirmation"><?php printf(esc_html__('Typ bezoekersnummer %d om te bevestigen:', 'avpvh-members'), $id); ?></label>
                    <input id="avpvh-delete-confirmation" type="text" name="confirmation" inputmode="numeric" autocomplete="off" required pattern="<?php echo esc_attr((string) $id); ?>"></p>
                <p><button type="submit" class="button button-primary"><?php esc_html_e('Definitief verwijderen', 'avpvh-members'); ?></button></p>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
