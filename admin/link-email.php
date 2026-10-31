<?php
defined('ABSPATH') || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- single-execution admin-page template (included once per request via AVPVH_Admin::render_link_email()), its top-level variables are effectively function-local
if (!AVPVH_Roles::can_manage_members()) {
    wp_die(esc_html__('Geen toegang.', 'avpvh-members'));
}

require_once AVPVH_PLUGIN_DIR . 'admin/class-login-attempts-list-table.php';

// "E-mailadres koppelen": an e-mail from Loginpogingen that no member has
// yet. Suggests members whose name appears in the e-mail, offers a search,
// and links it as an Inlogadres (same handler as on Ledendetail, which
// then opens the profile).
$email = sanitize_email(wp_unslash($_GET['email'] ?? ''));
$search = sanitize_text_field(wp_unslash($_GET['q'] ?? ''));
$owner_id = $email ? (AVPVH_DB::get_member_ids_by_emails([$email])[AVPVH_DB::normalize_identity_email($email)] ?? 0) : 0;
$attempts = $email ? AVPVH_DB::query_login_attempts(['search' => $email, 'per_page' => 10]) : ['items' => [], 'total' => 0];
$attempts['items'] = array_values(array_filter($attempts['items'], fn($a) => strcasecmp((string) $a->email, $email) === 0));

// Name parts in the e-mail ("voornaam.achternaam42@..." -> voornaam,
// achternaam): members
// matching more parts first.
$candidates = [];
$hits = [];
$parts = $email ? preg_split('/[^\p{L}]+/u', strtolower(strstr($email, '@', true)), -1, PREG_SPLIT_NO_EMPTY) : [];
$terms = $search !== '' ? [$search] : array_filter($parts, fn($p) => mb_strlen($p) >= 3 && !in_array($p, ['van', 'der', 'den', 'info', 'mail'], true));
foreach (array_unique($terms) as $term) {
    foreach (AVPVH_DB::get_members(['search' => $term]) as $member) {
        $candidates[(int) $member->id] = $member;
        $hits[(int) $member->id] = ($hits[(int) $member->id] ?? 0) + 1;
    }
}
uksort($candidates, fn($a, $b) => $hits[$b] <=> $hits[$a] ?: strcmp(avpvh_format_name($candidates[$a]), avpvh_format_name($candidates[$b])));
$candidates = array_slice($candidates, 0, 25, true);
$status_labels = AVPVH_Roles::STATUS_LABELS;
?>
<div class="wrap">
    <h1><?php esc_html_e('E-mailadres koppelen', 'avpvh-members'); ?></h1>
    <p><a href="<?php echo esc_url(add_query_arg(['page' => 'avpvh-login-attempts'], admin_url('admin.php'))); ?>">&larr; <?php esc_html_e('Loginpogingen', 'avpvh-members'); ?></a></p>

    <?php if (!$email) : ?>
        <div class="notice notice-error"><p><?php esc_html_e('Geen geldig e-mailadres.', 'avpvh-members'); ?></p></div>
    <?php else : ?>
        <h2><code><?php echo esc_html($email); ?></code></h2>

        <?php if ($owner_id) : ?>
            <div class="notice notice-info"><p>
                <?php esc_html_e('Dit e-mailadres hoort al bij een lid.', 'avpvh-members'); ?>
                <a href="<?php echo esc_url(add_query_arg(['page' => 'avpvh-member-detail', 'id' => $owner_id], admin_url('admin.php'))); ?>"><?php esc_html_e('Naar het profiel', 'avpvh-members'); ?></a>
            </p></div>
        <?php endif; ?>

        <?php if ($attempts['items']) : ?>
            <table class="widefat striped" style="max-width:700px">
                <thead><tr>
                    <th><?php esc_html_e('Tijdstip', 'avpvh-members'); ?></th>
                    <th><?php esc_html_e('Methode', 'avpvh-members'); ?></th>
                    <th><?php esc_html_e('Resultaat', 'avpvh-members'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($attempts['items'] as $attempt) : ?>
                    <tr>
                        <td><?php echo esc_html(wp_date('d-m-Y H:i', strtotime($attempt->attempted_at))); ?></td>
                        <td><?php echo esc_html(AVPVH_Login_Attempts_List_Table::method_labels()[$attempt->method] ?? $attempt->method); ?></td>
                        <td><?php echo esc_html(AVPVH_Login_Attempts_List_Table::result_labels()[$attempt->result] ?? $attempt->result); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if (!$owner_id) : ?>
            <h2><?php echo esc_html($search !== '' ? __('Zoekresultaten', 'avpvh-members') : __('Mogelijk bedoelde leden', 'avpvh-members')); ?></h2>
            <form method="get" style="margin-bottom:1em">
                <input type="hidden" name="page" value="avpvh-link-email">
                <input type="hidden" name="email" value="<?php echo esc_attr($email); ?>">
                <label for="avpvh-link-email-q"><?php esc_html_e('Zoek een lid op naam', 'avpvh-members'); ?></label>
                <input type="search" id="avpvh-link-email-q" name="q" value="<?php echo esc_attr($search); ?>">
                <?php submit_button(__('Zoeken', 'avpvh-members'), 'secondary', '', false); ?>
            </form>

            <?php if (!$candidates) : ?>
                <p><?php echo esc_html($search !== '' ? __('Geen leden gevonden.', 'avpvh-members') : __('Geen lid gevonden met een naam uit dit e-mailadres. Zoek hierboven op naam.', 'avpvh-members')); ?></p>
            <?php else : ?>
                <table class="widefat striped" style="max-width:900px">
                    <thead><tr>
                        <th><?php esc_html_e('Naam', 'avpvh-members'); ?></th>
                        <th><?php esc_html_e('Status', 'avpvh-members'); ?></th>
                        <th><?php esc_html_e('Inlogadressen', 'avpvh-members'); ?></th>
                        <th><span class="screen-reader-text"><?php esc_html_e('Actie', 'avpvh-members'); ?></span></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($candidates as $member_id => $member) :
                        $identities = AVPVH_DB::get_member_identities($member_id); ?>
                        <tr>
                            <td><a href="<?php echo esc_url(add_query_arg(['page' => 'avpvh-member-detail', 'id' => $member_id], admin_url('admin.php'))); ?>"><?php echo esc_html(avpvh_format_name($member)); ?></a></td>
                            <td><?php echo esc_html($status_labels[$member->status] ?? $member->status); ?></td>
                            <td><?php echo esc_html(implode(', ', array_column($identities, 'email')) ?: '—'); ?></td>
                            <td>
                                <?php if (count($identities) >= 3) : ?>
                                    <span class="description"><?php esc_html_e('Al 3 e-mailadressen', 'avpvh-members'); ?></span>
                                <?php else : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <?php wp_nonce_field('avpvh_add_identity'); ?>
                                        <input type="hidden" name="action" value="avpvh_add_identity">
                                        <input type="hidden" name="member_id" value="<?php echo esc_attr($member_id); ?>">
                                        <input type="hidden" name="email" value="<?php echo esc_attr($email); ?>">
                                        <?php submit_button(__('Koppelen', 'avpvh-members'), 'primary small', '', false); ?>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description"><?php esc_html_e('Koppelen voegt het adres als niet-geverifieerd inlogadres toe en opent het profiel; het lid kan daarna met dit e-mailadres inloggen.', 'avpvh-members'); ?></p>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
