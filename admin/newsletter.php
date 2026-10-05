<?php
defined('ABSPATH') || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- this is a single-execution admin-page template (included once per request via AVPVH_Admin::render_*()), not shared library code; its top-level variables are effectively function-local to this one include, not a real global-namespace collision risk
if (!AVPVH_Roles::can_send_newsletter()) wp_die(esc_html__('Geen toegang.', 'avpvh-members'));

$flags = AVPVH_DB::get_all_flags();
$flag_id = 0;
foreach ($flags as $flag) {
    if ($flag->slug === 'nieuwsbrief') {
        $flag_id = (int) $flag->id;
        break;
    }
}
$recipient_count = $flag_id ? count(AVPVH_DB::get_members(['status' => 'active', 'flag_id' => $flag_id])) : 0;

$sent = isset($_GET['newsletter_sent']) ? (int) $_GET['newsletter_sent'] : null;
$error = !empty($_GET['newsletter_error']);
$member_count_str = sprintf(
    _n('%s lid', '%s leden', $recipient_count, 'avpvh-members'),
    number_format_i18n($recipient_count)
);
$confirm_msg = sprintf(
    /* translators: %s: number of members (e.g. "5 leden") */
    __('E-mail versturen naar %s?', 'avpvh-members'),
    $member_count_str
);
?>
<div class="wrap">
    <h1><?php esc_html_e('Nieuwsbrief', 'avpvh-members'); ?></h1>
    <p class="description">
        <?php
        echo wp_kses(
            sprintf(
                /* translators: %s: recipient count formatted with bold tags */
                __('Stuurt direct (geen concept/inplannen) een losse e-mail naar elk actief lid dat “Ik wil e-mail ontvangen over activiteiten en de nieuwsbrief” heeft aangevinkt op hun profiel — nu <strong>%s</strong>. Elke e-mail wordt los verzonden (geen BCC), dus niemand ziet de rest van de lijst.', 'avpvh-members'),
                $member_count_str
            ),
            ['strong' => []]
        );
        ?>
    </p>

    <?php if ($sent !== null) : ?>
        <div class="notice notice-success is-dismissible"><p><?php
            printf(
                /* translators: %s: number of members */
                esc_html(_n('Verzonden aan %s lid.', 'Verzonden aan %s leden.', $sent, 'avpvh-members')),
                esc_html(number_format_i18n($sent))
            );
        ?></p></div>
    <?php elseif ($error) : ?>
        <div class="notice notice-error is-dismissible"><p><?php esc_html_e('Vul onderwerp en tekst in.', 'avpvh-members'); ?></p></div>
    <?php endif; ?>

    <?php if (!$flag_id) : ?>
        <p><em><?php esc_html_e('Kenmerk "nieuwsbrief" bestaat niet (zou automatisch aangemaakt moeten zijn) — controleer Instellingen → Kenmerken.', 'avpvh-members'); ?></em></p>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
        onsubmit="return confirm('<?php echo esc_js($confirm_msg); ?>');">
        <?php wp_nonce_field('avpvh_send_newsletter'); ?>
        <input type="hidden" name="action" value="avpvh_send_newsletter">
        <table class="form-table">
            <tr>
                <th><label for="subject"><?php esc_html_e('Onderwerp', 'avpvh-members'); ?></label></th>
                <td><input type="text" id="subject" name="subject" class="regular-text" required></td>
            </tr>
            <tr>
                <th><label for="body"><?php esc_html_e('Tekst', 'avpvh-members'); ?></label></th>
                <td><textarea id="body" name="body" rows="14" class="large-text" required></textarea></td>
            </tr>
        </table>
        <?php submit_button(__('Versturen', 'avpvh-members'), 'primary', 'submit', true, $flag_id ? [] : ['disabled' => 'disabled']); ?>
    </form>
</div>
