<?php
defined('ABSPATH') || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- this is a single-execution admin-page template (included once per request via AVPVH_Admin::render_*()), not shared library code; its top-level variables are effectively function-local to this one include, not a real global-namespace collision risk
if (!current_user_can('manage_options')) wp_die('Geen toegang.');

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
?>
<div class="wrap">
    <h1>Nieuwsbrief</h1>
    <p class="description">
        Stuurt direct (geen concept/inplannen) een losse e-mail naar elk actief lid dat
        &ldquo;Ik wil e-mail ontvangen over activiteiten en de nieuwsbrief&rdquo; heeft
        aangevinkt op hun profiel — nu <strong><?php echo esc_html((string) $recipient_count); ?></strong> <?php echo $recipient_count === 1 ? 'lid' : 'leden'; ?>.
        Elke e-mail wordt los verzonden (geen BCC), dus niemand ziet de rest van de lijst.
    </p>

    <?php if ($sent !== null) : ?>
        <div class="notice notice-success is-dismissible"><p>Verzonden aan <?php echo esc_html((string) $sent); ?> <?php echo $sent === 1 ? 'lid' : 'leden'; ?>.</p></div>
    <?php elseif ($error) : ?>
        <div class="notice notice-error is-dismissible"><p>Vul onderwerp en tekst in.</p></div>
    <?php endif; ?>

    <?php if (!$flag_id) : ?>
        <p><em>Kenmerk "nieuwsbrief" bestaat niet (zou automatisch aangemaakt moeten zijn) — controleer Instellingen &rarr; Kenmerken.</em></p>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
        onsubmit="return confirm('E-mail versturen naar <?php echo esc_js((string) $recipient_count); ?> <?php echo esc_js($recipient_count === 1 ? 'lid' : 'leden'); ?>?');">
        <?php wp_nonce_field('avpvh_send_newsletter'); ?>
        <input type="hidden" name="action" value="avpvh_send_newsletter">
        <table class="form-table">
            <tr>
                <th><label for="subject">Onderwerp</label></th>
                <td><input type="text" id="subject" name="subject" class="regular-text" required></td>
            </tr>
            <tr>
                <th><label for="body">Tekst</label></th>
                <td><textarea id="body" name="body" rows="14" class="large-text" required></textarea></td>
            </tr>
        </table>
        <?php submit_button('Versturen', 'primary', 'submit', true, $flag_id ? [] : ['disabled' => 'disabled']); ?>
    </form>
</div>
