<?php
defined('ABSPATH') || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- this is a single-execution admin-page template (included once per request via AVPVH_Admin::render_*()), not shared library code; its top-level variables are effectively function-local to this one include, not a real global-namespace collision risk
if (!current_user_can('manage_options')) wp_die(esc_html__('Geen toegang.', 'avpvh-members'));

$attempts = AVPVH_DB::get_login_attempts(500);

$method_label = [
    'proxy'          => __('Wachtwoord (Authelia)', 'avpvh-members'),
    'google'         => 'Google',
    'microsoft'      => 'Microsoft',
    'password_reset' => __('Wachtwoord instellen', 'avpvh-members'),
];
$result_label = [
    'success'     => __('✓ Gelukt', 'avpvh-members'),
    'no_member'   => __('✗ Onbekend e-mailadres', 'avpvh-members'),
    'hibp_warned' => __('⚠ Gelekt wachtwoord gekozen', 'avpvh-members'),
];
$result_class = ['success' => 'color:green', 'no_member' => 'color:#c00', 'hibp_warned' => 'color:#b8600a;font-weight:bold'];
?>
<div class="wrap">
    <h1><?php esc_html_e('Loginpogingen', 'avpvh-members'); ?></h1>
    <p><?php
        printf(
            /* translators: %s: number of login attempts */
            esc_html(_n('%s meest recente poging.', '%s meest recente pogingen.', count($attempts), 'avpvh-members')),
            esc_html(number_format_i18n(count($attempts)))
        );
    ?></p>
    <table class="wp-list-table widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Tijdstip', 'avpvh-members'); ?></th>
                <th><?php esc_html_e('E-mailadres', 'avpvh-members'); ?></th>
                <th><?php esc_html_e('Methode', 'avpvh-members'); ?></th>
                <th><?php esc_html_e('Resultaat', 'avpvh-members'); ?></th>
                <th><?php esc_html_e('IP-adres', 'avpvh-members'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$attempts) : ?>
            <tr><td colspan="5"><?php esc_html_e('Nog geen loginpogingen geregistreerd.', 'avpvh-members'); ?></td></tr>
        <?php else : foreach ($attempts as $a) : ?>
            <tr>
                <td><?php echo esc_html(wp_date('d-m-Y H:i:s', strtotime($a->attempted_at))); ?></td>
                <td><?php echo esc_html($a->email); ?></td>
                <td><?php echo esc_html($method_label[$a->method] ?? $a->method); ?></td>
                <td style="<?php echo esc_attr($result_class[$a->result] ?? ''); ?>">
                    <?php echo esc_html($result_label[$a->result] ?? $a->result); ?>
                </td>
                <td><code><?php echo esc_html($a->ip); ?></code></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
