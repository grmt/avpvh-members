<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options')) wp_die('Geen toegang.');

$attempts = AVPVH_DB::get_login_attempts(500);

$method_label = ['proxy' => 'Wachtwoord (Authelia)', 'google' => 'Google', 'microsoft' => 'Microsoft', 'password_reset' => 'Wachtwoord instellen'];
$result_label = ['success' => '✓ Gelukt', 'no_member' => '✗ Onbekend e-mailadres', 'hibp_warned' => '⚠ Gelekt wachtwoord gekozen'];
$result_class = ['success' => 'color:green', 'no_member' => 'color:#c00', 'hibp_warned' => 'color:#b8600a;font-weight:bold'];
?>
<div class="wrap">
    <h1>Loginpogingen</h1>
    <p><?php echo esc_html(count($attempts)); ?> meest recente pogingen.</p>
    <table class="wp-list-table widefat striped">
        <thead>
            <tr>
                <th>Tijdstip</th>
                <th>E-mailadres</th>
                <th>Methode</th>
                <th>Resultaat</th>
                <th>IP-adres</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$attempts) : ?>
            <tr><td colspan="5">Nog geen loginpogingen geregistreerd.</td></tr>
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
