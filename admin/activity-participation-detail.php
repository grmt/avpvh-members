<?php
defined('ABSPATH') || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- this is a single-execution admin-page template (included once per request via AVPVH_Admin::render_*()), not shared library code; its top-level variables are effectively function-local to this one include, not a real global-namespace collision risk
if (!AVPVH_Roles::can_manage_activities()) wp_die(esc_html__('Geen toegang.', 'avpvh-members'));

$participation_id = absint(wp_unslash($_GET['id'] ?? 0));
$activity_id = absint(wp_unslash($_GET['activity_id'] ?? 0));
$participation = $participation_id ? AVPVH_DB::get_participation_by_id($participation_id) : null;
if ($participation) {
    $activity_id = (int) $participation->activity_id;
}
// Reached cold from the sidebar link (no activity_id/id) — default to the
// most recent activity instead of a dead end, same default as the list page.
if (!$activity_id) {
    $current_activity = AVPVH_DB::get_current_camp_activity();
    $activity_id = $current_activity->id ?? 0;
}
$activity = $activity_id ? AVPVH_DB::get_activity($activity_id) : null;
if (!$activity) {
    wp_die(
        sprintf(
            /* translators: %s: URL to activities page */
            __('Geen activiteit gevonden. Maak eerst een activiteit aan via <a href="%s">Activiteiten</a>.', 'avpvh-members'),
            esc_url(add_query_arg(['page' => 'avpvh-activity-participation'], admin_url('admin.php')))
        )
    );
}

$member = $participation ? AVPVH_DB::get_member((int) $participation->member_id) : null;
$days   = $participation ? AVPVH_DB::get_participation_days((int) $participation->id) : [];
$updated = !empty($_GET['updated']);

$list_url = add_query_arg(['page' => 'avpvh-activity-participation', 'activity_id' => $activity_id], admin_url('admin.php'));

$date_range = [];
if ($activity->start_date && $activity->end_date) {
    $cursor = new DateTime($activity->start_date);
    $end = new DateTime($activity->end_date);
    while ($cursor <= $end) {
        $date_range[] = $cursor->format('Y-m-d');
        $cursor->modify('+1 day');
    }
}
?>
<div class="wrap">
    <h1><?php echo $participation ? esc_html__('Deelname bewerken', 'avpvh-members') : esc_html__('Nieuwe deelname', 'avpvh-members'); ?></h1>
    <p><a href="<?php echo esc_url($list_url); ?>">&larr; <?php esc_html_e('Terug naar overzicht', 'avpvh-members'); ?></a></p>

    <?php if ($updated) : ?>
        <div class="notice notice-success"><p><?php esc_html_e('Opgeslagen.', 'avpvh-members'); ?></p></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('avpvh_save_participation'); ?>
        <input type="hidden" name="action" value="avpvh_save_participation">
        <input type="hidden" name="activity_id" value="<?php echo esc_attr($activity_id); ?>">
        <?php if ($participation) : ?>
            <input type="hidden" name="participation_id" value="<?php echo esc_attr($participation->id); ?>">
        <?php endif; ?>

        <table class="form-table">
            <tr>
                <th><label for="member_id"><?php esc_html_e('Lid', 'avpvh-members'); ?></label></th>
                <td>
                    <?php if ($member) : ?>
                        <strong><?php echo esc_html(avpvh_format_name($member, 'list')); ?></strong>
                        <input type="hidden" name="member_id" value="<?php echo esc_attr($member->id); ?>">
                    <?php else : ?>
                        <select name="member_id" id="member_id" required style="min-width:300px">
                            <option value="">— <?php esc_html_e('Kies lid', 'avpvh-members'); ?> —</option>
                            <?php
                            // Not just 'active': a Congres-achtige activiteit
                            // registreert regelmatig ex-leden en bezoekers
                            // (partners, gastsprekers, ...) die hier anders
                            // niet te kiezen zouden zijn.
                            foreach (AVPVH_DB::get_members(['status' => ['active', 'inactive', 'visitor']]) as $m) : ?>
                                <option value="<?php echo esc_attr($m->id); ?>">
                                    <?php echo esc_html(avpvh_format_name($m, 'list')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><label for="nights"><?php esc_html_e('Nachten', 'avpvh-members'); ?></label></th>
                <td>
                    <?php if ($date_range) : ?>
                        <strong id="nights-computed"><?php echo esc_html((string) ($participation->nights ?? 0)); ?></strong>
                        <p class="description"><?php echo wp_kses(__('Berekend uit de dagen hieronder (aantal dagen met code <code>n</code>) &mdash; pas de dagen aan om dit te wijzigen.', 'avpvh-members'), ['code' => []]); ?></p>
                    <?php else : ?>
                        <input type="number" id="nights" name="nights" min="0" value="<?php echo esc_attr($participation->nights ?? ''); ?>">
                        <p class="description"><?php esc_html_e('Geen datumbereik ingesteld voor deze activiteit, dus geen dagen om uit te berekenen — hier handmatig invullen.', 'avpvh-members'); ?></p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><label for="nawacht"><?php esc_html_e('Nawacht', 'avpvh-members'); ?></label></th>
                <td><label><input type="checkbox" id="nawacht" name="nawacht" value="1" <?php checked(!empty($participation->nawacht)); ?>> <?php esc_html_e('Ja', 'avpvh-members'); ?></label></td>
            </tr>
            <tr>
                <th><label for="diet"><?php esc_html_e('Dieet', 'avpvh-members'); ?></label></th>
                <td><input type="text" id="diet" name="diet" class="regular-text" value="<?php echo esc_attr($participation->diet ?? ''); ?>"></td>
            </tr>
            <tr>
                <th><label for="notes"><?php esc_html_e('Notities', 'avpvh-members'); ?></label></th>
                <td><textarea id="notes" name="notes" class="large-text" rows="3"><?php echo esc_textarea($participation->notes ?? ''); ?></textarea></td>
            </tr>
        </table>

        <?php if ($date_range) : ?>
            <h2><?php esc_html_e('Dagen', 'avpvh-members'); ?></h2>
            <p class="description"><?php echo wp_kses(__('Vul per dag een code in (bijv. <code>n</code> = aanwezig, <code>on</code> = ochtend-nawacht, <code>?</code> = onzeker), of laat leeg.', 'avpvh-members'), ['code' => []]); ?></p>
            <table class="widefat striped" style="max-width:500px">
                <thead><tr><th><?php esc_html_e('Datum', 'avpvh-members'); ?></th><th><?php esc_html_e('Status', 'avpvh-members'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($date_range as $date) : ?>
                    <tr>
                        <td><?php echo esc_html(date_i18n('D j M', strtotime($date))); ?></td>
                        <td><input type="text" name="day[<?php echo esc_attr($date); ?>]" maxlength="10" size="5"
                                   value="<?php echo esc_attr($days[$date] ?? ''); ?>"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p class="description"><?php esc_html_e('Stel eerst een start- en einddatum in voor deze activiteit om dagen te kunnen registreren.', 'avpvh-members'); ?></p>
        <?php endif; ?>

        <p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e('Opslaan', 'avpvh-members'); ?></button></p>
    </form>
</div>

