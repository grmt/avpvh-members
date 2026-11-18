<?php
defined('ABSPATH') || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- single-execution admin-page template, see admin/login-attempts.php
if (!AVPVH_Roles::can_view_email_log()) {
    wp_die(esc_html__('Geen toegang.', 'avpvh-members'));
}

$base_url = admin_url('admin.php?page=avpvh-email-log');
$status_labels = [
    'sent'   => __('Verzonden', 'avpvh-members'),
    'failed' => __('Mislukt', 'avpvh-members'),
];
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters and paging
$view_id = absint(wp_unslash($_GET['view'] ?? 0));
$search  = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
$status  = sanitize_key(wp_unslash($_GET['status'] ?? ''));
$paged   = max(1, absint(wp_unslash($_GET['paged'] ?? 1)));
// phpcs:enable
?>
<div class="wrap avpvh-email-log">
    <h1><?php esc_html_e('E-mail log', 'avpvh-members'); ?></h1>
    <p class="description"><?php esc_html_e('Alle mails die de website heeft verstuurd, alleen om te bekijken. Links die als sleutel werken (bestellingen, fotomappen, inloggen) zijn verborgen, en van mails over inloggen of een wachtwoord is de inhoud niet te zien.', 'avpvh-members'); ?></p>

<?php if (!AVPVH_Email_Log::available()) : ?>
    <div class="notice notice-warning"><p><?php esc_html_e('Er is geen e-mail log: FluentSMTP is niet geïnstalleerd of houdt geen log bij.', 'avpvh-members'); ?></p></div>
<?php elseif ($view_id > 0) :
    $mail = AVPVH_Email_Log::get($view_id);
    ?>
    <p><a href="<?php echo esc_url(wp_get_referer() ?: $base_url); ?>">&larr; <?php esc_html_e('Terug naar het log', 'avpvh-members'); ?></a></p>
    <?php if (!$mail) : ?>
        <div class="notice notice-error"><p><?php esc_html_e('Deze mail staat niet (meer) in het log.', 'avpvh-members'); ?></p></div>
    <?php else : ?>
        <table class="form-table">
            <tr><th scope="row"><?php esc_html_e('Onderwerp', 'avpvh-members'); ?></th><td><?php echo esc_html($mail->subject); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e('Aan', 'avpvh-members'); ?></th><td><?php echo esc_html(AVPVH_Email_Log::recipients($mail->to)); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e('Van', 'avpvh-members'); ?></th><td><?php echo esc_html($mail->from); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e('Status', 'avpvh-members'); ?></th><td><?php echo esc_html($status_labels[$mail->status] ?? $mail->status); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e('Datum', 'avpvh-members'); ?></th><td><?php echo esc_html($mail->created_at); ?></td></tr>
        </table>
        <h2><?php esc_html_e('Inhoud', 'avpvh-members'); ?></h2>
        <?php if (AVPVH_Email_Log::body_hidden($mail)) : ?>
            <div class="notice notice-info inline"><p><?php esc_html_e('Deze mail gaat over inloggen of een wachtwoord. De inhoud wordt hier niet getoond, omdat wie de link erin opent als de ontvanger kan inloggen.', 'avpvh-members'); ?></p></div>
        <?php else : ?>
            <?php // sandbox="": no scripts, forms or navigation of this page from inside the mail. ?>
            <iframe sandbox="" title="<?php esc_attr_e('Inhoud van de mail', 'avpvh-members'); ?>" style="width:100%;max-width:900px;height:70vh;border:1px solid #c3c4c7;background:#fff"
                srcdoc="<?php
                // Not esc_attr(): it leaves existing entities alone, which would turn the mail's own &lt; into markup.
                echo htmlspecialchars(AVPVH_Email_Log::redact((string) $mail->body), ENT_QUOTES, 'UTF-8', true); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully encoded for the attribute
                ?>"></iframe>
        <?php endif; ?>
    <?php endif; ?>
<?php else :
    $result = AVPVH_Email_Log::query($search, $status, $paged);
    $pages = (int) ceil($result['total'] / AVPVH_Email_Log::PER_PAGE);
    $filter_url = static fn(string $s): string => add_query_arg(array_filter(['status' => $s, 's' => $search]), $base_url);
    ?>
    <ul class="subsubsub">
        <li><a href="<?php echo esc_url($filter_url('')); ?>" class="<?php echo $status === '' ? 'current' : ''; ?>"><?php esc_html_e('Alle', 'avpvh-members'); ?></a> |</li>
        <li><a href="<?php echo esc_url($filter_url('sent')); ?>" class="<?php echo $status === 'sent' ? 'current' : ''; ?>"><?php echo esc_html($status_labels['sent']); ?></a> |</li>
        <li><a href="<?php echo esc_url($filter_url('failed')); ?>" class="<?php echo $status === 'failed' ? 'current' : ''; ?>"><?php echo esc_html($status_labels['failed']); ?></a></li>
    </ul>
    <form method="get" class="search-box">
        <input type="hidden" name="page" value="avpvh-email-log">
        <?php if ($status !== '') : ?><input type="hidden" name="status" value="<?php echo esc_attr($status); ?>"><?php endif; ?>
        <label class="screen-reader-text" for="avpvh-email-log-search"><?php esc_html_e('Zoek op onderwerp of ontvanger', 'avpvh-members'); ?></label>
        <input type="search" id="avpvh-email-log-search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Onderwerp of ontvanger', 'avpvh-members'); ?>">
        <button type="submit" class="button"><?php esc_html_e('Zoeken', 'avpvh-members'); ?></button>
    </form>
    <table class="wp-list-table widefat striped">
        <thead><tr>
            <th scope="col"><?php esc_html_e('Onderwerp', 'avpvh-members'); ?></th>
            <th scope="col"><?php esc_html_e('Aan', 'avpvh-members'); ?></th>
            <th scope="col"><?php esc_html_e('Status', 'avpvh-members'); ?></th>
            <th scope="col"><?php esc_html_e('Datum', 'avpvh-members'); ?></th>
        </tr></thead>
        <tbody>
        <?php if (!$result['rows']) : ?>
            <tr><td colspan="4"><?php esc_html_e('Geen mails gevonden.', 'avpvh-members'); ?></td></tr>
        <?php endif; ?>
        <?php foreach ($result['rows'] as $row) : ?>
            <tr>
                <td><a href="<?php echo esc_url(add_query_arg('view', (int) $row->id, $base_url)); ?>"><?php echo esc_html($row->subject); ?></a></td>
                <td><?php echo esc_html(AVPVH_Email_Log::recipients($row->to)); ?></td>
                <td><?php echo esc_html($status_labels[$row->status] ?? $row->status); ?></td>
                <td><?php echo esc_html($row->created_at); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($pages > 1) : ?>
        <div class="tablenav"><div class="tablenav-pages">
            <span class="displaying-num"><?php echo esc_html(sprintf(/* translators: %d: number of mails */ _n('%d mail', '%d mails', $result['total'], 'avpvh-members'), $result['total'])); ?></span>
            <?php echo wp_kses_post(paginate_links([
                'base'    => add_query_arg('paged', '%#%', $filter_url($status)),
                'format'  => '',
                'current' => $paged,
                'total'   => $pages,
            ])); ?>
        </div></div>
    <?php endif; ?>
<?php endif; ?>
</div>
