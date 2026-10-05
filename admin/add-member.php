<?php
defined('ABSPATH') || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- this is a single-execution admin-page template (included once per request via AVPVH_Admin::render_*()), not shared library code; its top-level variables are effectively function-local to this one include, not a real global-namespace collision risk
if (!AVPVH_Roles::can_manage_members()) {
    wp_die(__('Geen toegang.', 'avpvh-members'));
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
    // id => matched_via ('' = official name, else the name alias it matched on)
    foreach ($pending['matches'] as $existing_id => $matched_via) {
        $existing = AVPVH_DB::get_member((int) $existing_id);
        if ($existing) {
            $existing->matched_via = (string) $matched_via;
            $matches[] = $existing;
        }
    }
}
?>
<div class="wrap">
    <h1><?php esc_html_e('Nieuwe persoon', 'avpvh-members'); ?></h1>
    <p class="description">
        <?php esc_html_e('Voegt een lid, ex-lid of bezoeker toe: een plaatsvervangend account (@avpvh.local, geen echte inlog — clubbeleid: leden onder de 16 krijgen geen eigen login) en het bijbehorende ledenrecord.', 'avpvh-members'); ?>
    </p>

    <?php if (!empty($_GET['add_member_error'])) :
        $err = sanitize_key(wp_unslash($_GET['add_member_error'])); ?>
        <div class="notice notice-error">
            <p>
                <?php if ($err === 'onvolledig') : ?>
                    <?php esc_html_e('Voornaam en achternaam zijn verplicht.', 'avpvh-members'); ?>
                <?php elseif ($err === 'soort') : ?>
                    <?php esc_html_e('Kies of het om een lid, ex-lid of bezoeker gaat.', 'avpvh-members'); ?>
                <?php elseif ($err === 'geboortedatum') : ?>
                    <?php esc_html_e('Ongeldige geboortedatum. Vul een volledige datum in (JJJJ-MM-DD) of alleen een geboortejaar (JJJJ).', 'avpvh-members'); ?>
                <?php elseif ($err === 'lldap') : ?>
                    <?php echo esc_html(sprintf(__('Aanmaken van het account is mislukt: %s', 'avpvh-members'), rawurldecode(wp_unslash($_GET['add_member_error_message'] ?? '')))); ?>
                <?php endif; ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if ($pending && $matches) : ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php echo esc_html(count($matches) === 1 ? __('Er bestaat al een lid met deze of een vergelijkbare naam:', 'avpvh-members') : __('Er bestaan al leden met deze of een vergelijkbare naam:', 'avpvh-members')); ?></strong>
            </p>
            <ul style="list-style: disc; margin-left: 1.5rem;">
                <?php foreach ($matches as $m) : ?>
                    <li>
                        <a href="<?php echo esc_url(add_query_arg(['page' => 'avpvh-member-detail', 'id' => $m->id], admin_url('admin.php'))); ?>" target="_blank">
                            <?php echo esc_html(avpvh_format_name($m, 'list_suffix')); ?>
                        </a>
                        (status: <?php echo esc_html(AVPVH_Roles::get_status_label($m->status)); ?><?php echo $m->matched_via !== '' ? esc_html(sprintf(__(', via naamvariant %s', 'avpvh-members'), $m->matched_via)) : ''; ?>)
                    </li>
                <?php endforeach; ?>
            </ul>
            <p><?php esc_html_e('Gaat het om een andere, echte persoon met dezelfde naam? Dan kan je toch doorgaan.', 'avpvh-members'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('avpvh_add_member'); ?>
                <input type="hidden" name="action" value="avpvh_add_member">
                <input type="hidden" name="confirmed" value="1">
                <input type="hidden" name="first_name" value="<?php echo esc_attr($pending['first_name']); ?>">
                <input type="hidden" name="suffix" value="<?php echo esc_attr($pending['suffix']); ?>">
                <input type="hidden" name="last_name" value="<?php echo esc_attr($pending['last_name']); ?>">
                <input type="hidden" name="birth_date" value="<?php echo esc_attr($pending['birth_date'] ?? ''); ?>">
                <input type="hidden" name="status" value="<?php echo esc_attr($pending['status']); ?>">
                <?php foreach ((array) ($pending['flag_ids'] ?? []) as $flag_id) : ?>
                    <input type="hidden" name="flag_ids[]" value="<?php echo esc_attr((int) $flag_id); ?>">
                <?php endforeach; ?>
                <?php submit_button(__('Ja, toch toevoegen als nieuwe persoon', 'avpvh-members'), 'secondary', 'submit', false); ?>
            </form>
        </div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="avpvh-fields-grid">
        <?php wp_nonce_field('avpvh_add_member'); ?>
        <input type="hidden" name="action" value="avpvh_add_member">

        <table class="form-table">
            <tr>
                <th><label for="first_name"><?php esc_html_e('Voornaam *', 'avpvh-members'); ?></label></th>
                <td><input type="text" id="first_name" name="first_name" required
                           value="<?php echo esc_attr($pending['first_name'] ?? ''); ?>"></td>
            </tr>
            <tr>
                <th><label for="suffix"><?php esc_html_e('Tussenvoegsel', 'avpvh-members'); ?></label></th>
                <td><input type="text" id="suffix" name="suffix"
                           value="<?php echo esc_attr($pending['suffix'] ?? ''); ?>"></td>
            </tr>
            <tr>
                <th><label for="last_name"><?php esc_html_e('Achternaam *', 'avpvh-members'); ?></label></th>
                <td><input type="text" id="last_name" name="last_name" required
                           value="<?php echo esc_attr($pending['last_name'] ?? ''); ?>"></td>
            </tr>
            <tr>
                <th><label for="birth_date"><?php esc_html_e('Geboortedatum', 'avpvh-members'); ?></label></th>
                <td>
                    <input type="text" id="birth_date" name="birth_date" inputmode="numeric"
                           pattern="\d{4}(-\d{2}-\d{2})?" placeholder="<?php echo esc_attr__('JJJJ-MM-DD of alleen JJJJ', 'avpvh-members'); ?>"
                           value="<?php echo esc_attr($pending['birth_date'] ?? ''); ?>">
                    <p class="description"><?php esc_html_e('Volledige datum (JJJJ-MM-DD), of alleen het geboortejaar als de exacte datum niet bekend is.', 'avpvh-members'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Soort *', 'avpvh-members'); ?></th>
                <td>
                    <fieldset>
                        <?php $current_status = $pending['status'] ?? ''; ?>
                        <?php foreach (AVPVH_Roles::get_status_labels() as $value => $label) : ?>
                            <label style="display:inline-block;margin-right:1.5rem">
                                <input type="radio" name="status" value="<?php echo esc_attr($value); ?>" required <?php checked($current_status, $value); ?>>
                                <?php echo esc_html($label); ?>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                    <p class="description"><?php esc_html_e('Lid komt in de groep leden, ex-lid in ex-leden, een bezoeker in geen van beide.', 'avpvh-members'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Kenmerken', 'avpvh-members'); ?></th>
                <td>
                    <?php $chosen_flags = array_map('intval', (array) ($pending['flag_ids'] ?? [])); ?>
                    <?php foreach (AVPVH_DB::get_all_flags() as $flag) : ?>
                        <label style="display:inline-block;margin-right:1.5rem">
                            <input type="checkbox" name="flag_ids[]" value="<?php echo esc_attr($flag->id); ?>" <?php checked(in_array((int) $flag->id, $chosen_flags, true)); ?>>
                            <?php echo esc_html($flag->label); ?>
                        </label>
                    <?php endforeach; ?>
                    <p class="description"><?php esc_html_e('Meerdere mogelijk. Een kenmerk als Overleden of Geroyeerd maakt iemand automatisch ex-lid.', 'avpvh-members'); ?></p>
                </td>
            </tr>
        </table>

        <?php submit_button(__('Persoon toevoegen', 'avpvh-members')); ?>
    </form>
</div>
