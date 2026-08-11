<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options')) {
    wp_die('Geen toegang.');
}

// A duplicate-name warning survives one redirect via a short-lived
// transient (set in AVPVH_Admin::handle_add_member()) rather than resending
// form values through the URL — keeps the confirm step a plain resubmit of
// exactly what was typed, not something a query string could tamper with.
$pending = null;
if (!empty($_GET['add_member_duplicate'])) {
    $pending = get_transient('avpvh_add_member_pending_' . get_current_user_id());
}
$matches = [];
if ($pending) {
    foreach ($pending['matches'] as $existing_id) {
        $existing = AVPVH_DB::get_member((int) $existing_id);
        if ($existing) {
            $matches[] = $existing;
        }
    }
}
?>
<div class="wrap">
    <h1>Nieuw lid</h1>
    <p class="description">
        Maakt een plaatsvervangend LLDAP-account aan (@avpvh.local, geen echte inlog — clubbeleid: leden onder de
        16 krijgen geen eigen login) en het bijbehorende ledenrecord.
    </p>

    <?php if (!empty($_GET['add_member_error'])) :
        $err = sanitize_key($_GET['add_member_error']); ?>
        <div class="notice notice-error">
            <p>
                <?php if ($err === 'onvolledig') : ?>
                    Voornaam en achternaam zijn verplicht.
                <?php elseif ($err === 'lldap') : ?>
                    Aanmaken van het LLDAP-account is mislukt: <?php echo esc_html(rawurldecode($_GET['add_member_error_message'] ?? '')); ?>
                <?php endif; ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if ($pending && $matches) : ?>
        <div class="notice notice-warning">
            <p>
                <strong>Er <?php echo count($matches) === 1 ? 'bestaat al een lid' : 'bestaan al leden'; ?> met deze naam:</strong>
            </p>
            <ul style="list-style: disc; margin-left: 1.5rem;">
                <?php foreach ($matches as $m) : ?>
                    <li>
                        <a href="<?php echo esc_url(add_query_arg(['page' => 'avpvh-member-detail', 'id' => $m->id], admin_url('admin.php'))); ?>" target="_blank">
                            <?php echo esc_html(avpvh_format_name($m, 'list_suffix')); ?>
                        </a>
                        (status: <?php echo esc_html($m->status); ?>)
                    </li>
                <?php endforeach; ?>
            </ul>
            <p>Gaat het om een andere, echte persoon met dezelfde naam? Dan kan je toch doorgaan.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('avpvh_add_member'); ?>
                <input type="hidden" name="action" value="avpvh_add_member">
                <input type="hidden" name="confirmed" value="1">
                <input type="hidden" name="first_name" value="<?php echo esc_attr($pending['first_name']); ?>">
                <input type="hidden" name="suffix" value="<?php echo esc_attr($pending['suffix']); ?>">
                <input type="hidden" name="last_name" value="<?php echo esc_attr($pending['last_name']); ?>">
                <input type="hidden" name="birth_date" value="<?php echo esc_attr($pending['birth_date'] ?? ''); ?>">
                <input type="hidden" name="status" value="<?php echo esc_attr($pending['status']); ?>">
                <?php submit_button('Ja, toch toevoegen als nieuw lid', 'secondary', 'submit', false); ?>
            </form>
        </div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="avpvh-fields-grid">
        <?php wp_nonce_field('avpvh_add_member'); ?>
        <input type="hidden" name="action" value="avpvh_add_member">

        <table class="form-table">
            <tr>
                <th><label for="first_name">Voornaam *</label></th>
                <td><input type="text" id="first_name" name="first_name" required
                           value="<?php echo esc_attr($pending['first_name'] ?? ''); ?>"></td>
            </tr>
            <tr>
                <th><label for="suffix">Tussenvoegsel</label></th>
                <td><input type="text" id="suffix" name="suffix"
                           value="<?php echo esc_attr($pending['suffix'] ?? ''); ?>"></td>
            </tr>
            <tr>
                <th><label for="last_name">Achternaam *</label></th>
                <td><input type="text" id="last_name" name="last_name" required
                           value="<?php echo esc_attr($pending['last_name'] ?? ''); ?>"></td>
            </tr>
            <tr>
                <th><label for="birth_date">Geboortedatum</label></th>
                <td><input type="date" id="birth_date" name="birth_date"
                           value="<?php echo esc_attr($pending['birth_date'] ?? ''); ?>"></td>
            </tr>
            <tr>
                <th><label for="status">Status</label></th>
                <td>
                    <select id="status" name="status">
                        <?php $current_status = $pending['status'] ?? 'inactive'; ?>
                        <option value="inactive" <?php selected($current_status, 'inactive'); ?>>Inactief</option>
                        <option value="visitor" <?php selected($current_status, 'visitor'); ?>>Bezoeker</option>
                        <option value="active" <?php selected($current_status, 'active'); ?>>Actief</option>
                    </select>
                </td>
            </tr>
        </table>

        <?php submit_button('Lid toevoegen'); ?>
    </form>
</div>
