<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options')) wp_die('Geen toegang.');

$participation_id = (int) wp_unslash($_GET['id'] ?? 0);
$activity_id = (int) wp_unslash($_GET['activity_id'] ?? 0);
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
    wp_die('Geen activiteit gevonden. Maak eerst een activiteit aan via <a href="' . esc_url(add_query_arg(['page' => 'avpvh-activity-participation'], admin_url('admin.php'))) . '">Activiteiten</a>.');
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
    <h1><?php echo $participation ? 'Deelname bewerken' : 'Nieuwe deelname'; ?></h1>
    <p><a href="<?php echo esc_url($list_url); ?>">&larr; Terug naar overzicht</a></p>

    <?php if ($updated) : ?>
        <div class="notice notice-success"><p>Opgeslagen.</p></div>
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
                <th><label for="member_id">Lid</label></th>
                <td>
                    <?php if ($member) : ?>
                        <strong><?php echo esc_html(avpvh_format_name($member, 'list')); ?></strong>
                        <input type="hidden" name="member_id" value="<?php echo esc_attr($member->id); ?>">
                    <?php else : ?>
                        <select name="member_id" id="member_id" required style="min-width:300px">
                            <option value="">— Kies lid —</option>
                            <?php foreach (AVPVH_DB::get_members(['status' => 'active']) as $m) : ?>
                                <option value="<?php echo esc_attr($m->id); ?>">
                                    <?php echo esc_html(avpvh_format_name($m, 'list')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><label for="nights">Nachten</label></th>
                <td>
                    <?php if ($date_range) : ?>
                        <strong id="nights-computed"><?php echo esc_html((string) ($participation->nights ?? 0)); ?></strong>
                        <p class="description">Berekend uit de dagen hieronder (aantal dagen met code <code>n</code>) &mdash; pas de dagen aan om dit te wijzigen.</p>
                    <?php else : ?>
                        <input type="number" id="nights" name="nights" min="0" value="<?php echo esc_attr($participation->nights ?? ''); ?>">
                        <p class="description">Geen datumbereik ingesteld voor deze activiteit, dus geen dagen om uit te berekenen &mdash; hier handmatig invullen.</p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><label for="nawacht">Nawacht</label></th>
                <td><label><input type="checkbox" id="nawacht" name="nawacht" value="1" <?php checked(!empty($participation->nawacht)); ?>> Ja</label></td>
            </tr>
            <tr>
                <th><label for="diet">Dieet</label></th>
                <td><input type="text" id="diet" name="diet" class="regular-text" value="<?php echo esc_attr($participation->diet ?? ''); ?>"></td>
            </tr>
            <tr>
                <th><label for="notes">Notities</label></th>
                <td><textarea id="notes" name="notes" class="large-text" rows="3"><?php echo esc_textarea($participation->notes ?? ''); ?></textarea></td>
            </tr>
        </table>

        <?php if ($date_range) : ?>
            <h2>Dagen</h2>
            <p class="description">Vul per dag een code in (bijv. <code>n</code> = aanwezig, <code>on</code> = ochtend-nawacht, <code>?</code> = onzeker), of laat leeg.</p>
            <table class="widefat striped" style="max-width:500px">
                <thead><tr><th>Datum</th><th>Status</th></tr></thead>
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
            <p class="description">Stel eerst een start- en einddatum in voor deze activiteit om dagen te kunnen registreren.</p>
        <?php endif; ?>

        <p class="submit"><button type="submit" class="button button-primary">Opslaan</button></p>
    </form>
</div>
<?php if ($date_range) : ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var display = document.getElementById('nights-computed');
    var dayInputs = document.querySelectorAll('input[name^="day["]');
    if (!display || !dayInputs.length) return;
    function recompute() {
        var count = 0;
        dayInputs.forEach(function (input) {
            if (input.value.trim() === 'n') count++;
        });
        display.textContent = count;
    }
    dayInputs.forEach(function (input) { input.addEventListener('input', recompute); });
});
</script>
<?php endif; ?>
