<?php
defined('ABSPATH') || exit;

/**
 * Self-service participation editing for the current camp.
 * Usage: [avpvh_activiteit_deelname]
 *
 * Same household-edit authorization model as AVPVH_Member_Profile_Form:
 * a member may edit their own participation, or that of anyone in their
 * household, via a ?member_id= query param.
 */
class AVPVH_Activity_Participation_Form {

    public function __construct() {
        add_shortcode('avpvh_activiteit_deelname', [$this, 'render_shortcode']);
        add_action('admin_post_avpvh_save_own_participation', [$this, 'handle_save']);
    }

    public function render_shortcode(): string {
        if (!is_user_logged_in()) {
            return '<p>' . esc_html__('Je moet ingelogd zijn om je deelname te wijzigen.', 'avpvh-members') . '</p>';
        }

        $own_member = AVPVH_DB::get_member_by_wp_user(get_current_user_id());
        if (!$own_member) {
            return '<p>' . esc_html__('Geen lidprofiel gevonden.', 'avpvh-members') . '</p>';
        }

        $activity = AVPVH_DB::get_current_camp_activity();
        if (!$activity) {
            return '<p>' . esc_html__('Er is nog geen activiteit beschikbaar om je voor in te schrijven.', 'avpvh-members') . '</p>';
        }

        $member = $this->get_target_member($own_member);
        $huisgenoten = AVPVH_DB::get_manageable_members((int) $own_member->id);

        $participation = AVPVH_DB::get_participation((int) $member->id, (int) $activity->id);
        $days = $participation ? AVPVH_DB::get_participation_days((int) $participation->id) : [];

        $date_range = [];
        if ($activity->start_date && $activity->end_date) {
            $cursor = new DateTime($activity->start_date);
            $end = new DateTime($activity->end_date);
            while ($cursor <= $end) {
                $date_range[] = $cursor->format('Y-m-d');
                $cursor->modify('+1 day');
            }
        }

        $updated = !empty($_GET['activiteit_updated']);

        ob_start();
        ?>
        <div class="avpvh-activiteit-deelname">
            <h2><?php echo esc_html($activity->name . ' ' . $activity->year); ?></h2>

            <?php if (count($huisgenoten) > 1) : ?>
                <p>
                    <label for="avpvh-activiteit-member-select"><?php esc_html_e('Voor wie:', 'avpvh-members'); ?></label>
                    <select id="avpvh-activiteit-member-select" onchange="location.href=this.value">
                        <?php foreach ($huisgenoten as $h) :
                            $url = add_query_arg('member_id', $h->id);
                        ?>
                            <option value="<?php echo esc_url($url); ?>" <?php selected((int) $h->id, (int) $member->id); ?>>
                                <?php echo esc_html(avpvh_format_name($h, 'list')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
            <?php endif; ?>

            <?php if ($updated) : ?>
                <p style="color:green"><?php esc_html_e('Je deelname is opgeslagen.', 'avpvh-members'); ?></p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('avpvh_save_own_participation'); ?>
                <input type="hidden" name="action" value="avpvh_save_own_participation">
                <input type="hidden" name="activity_id" value="<?php echo esc_attr($activity->id); ?>">
                <input type="hidden" name="member_id" value="<?php echo esc_attr($member->id); ?>">
                <input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr(remove_query_arg('activiteit_updated')); ?>">

                <p>
                    <label for="nights"><?php esc_html_e('Aantal nachten', 'avpvh-members'); ?></label><br>
                    <input type="number" id="nights" name="nights" min="0" value="<?php echo esc_attr($participation->nights ?? ''); ?>">
                </p>
                <p>
                    <label><input type="checkbox" name="nawacht" value="1" <?php checked(!empty($participation->nawacht)); ?>> <?php esc_html_e('Ik doe nawacht', 'avpvh-members'); ?></label>
                </p>
                <p>
                    <label for="diet"><?php esc_html_e('Dieetwensen', 'avpvh-members'); ?></label><br>
                    <input type="text" id="diet" name="diet" value="<?php echo esc_attr($participation->diet ?? ''); ?>">
                </p>
                <p>
                    <label for="notes"><?php esc_html_e('Opmerkingen', 'avpvh-members'); ?></label><br>
                    <textarea id="notes" name="notes" rows="3"><?php echo esc_textarea($participation->notes ?? ''); ?></textarea>
                </p>

                <?php if ($date_range) : ?>
                    <p><strong><?php esc_html_e('Welke dagen ben je aanwezig?', 'avpvh-members'); ?></strong></p>
                    <ul class="avpvh-activiteit-deelname-dagen">
                        <?php foreach ($date_range as $date) : ?>
                            <li>
                                <label>
                                    <input type="checkbox" name="day[<?php echo esc_attr($date); ?>]" value="n" <?php checked(($days[$date] ?? '') === 'n'); ?>>
                                    <?php echo esc_html(date_i18n('D j-n', strtotime($date))); ?>
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <p><button type="submit" class="button"><?php esc_html_e('Opslaan', 'avpvh-members'); ?></button></p>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    public function handle_save(): void {
        check_admin_referer('avpvh_save_own_participation');

        if (!is_user_logged_in()) {
            wp_die(esc_html__('Je moet ingelogd zijn.', 'avpvh-members'), esc_html__('Fout', 'avpvh-members'), ['response' => 403]);
        }

        $own_member  = AVPVH_DB::get_member_by_wp_user(get_current_user_id());
        $member_id   = absint(wp_unslash($_POST['member_id'] ?? 0));
        $activity_id = absint(wp_unslash($_POST['activity_id'] ?? 0));

        if (!$own_member || !$this->can_edit_member($own_member, $member_id)) {
            wp_die(esc_html__('Geen toegang.', 'avpvh-members'), esc_html__('Fout', 'avpvh-members'), ['response' => 403]);
        }

        $activity = AVPVH_DB::get_activity($activity_id);
        if (!$activity) {
            wp_die(esc_html__('Activiteit niet gevonden.', 'avpvh-members'), esc_html__('Fout', 'avpvh-members'), ['response' => 404]);
        }

        $fields = [
            'nights'  => isset($_POST['nights']) && $_POST['nights'] !== '' ? absint(wp_unslash($_POST['nights'])) : '',
            'nawacht' => !empty($_POST['nawacht']),
            'diet'    => sanitize_text_field(wp_unslash($_POST['diet'] ?? '')),
            'notes'   => sanitize_textarea_field(wp_unslash($_POST['notes'] ?? '')),
        ];
        $participation_id = AVPVH_DB::save_participation($member_id, $activity_id, $fields);

        $days = [];
        foreach ((array) wp_unslash($_POST['day'] ?? []) as $date => $status) {
            $date = sanitize_text_field($date);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $days[$date] = sanitize_text_field($status);
            }
        }
        AVPVH_DB::save_participation_days($participation_id, $days);

        $redirect = wp_get_referer() ?: home_url('/');
        wp_safe_redirect(add_query_arg('activiteit_updated', '1', $redirect));
        exit;
    }

    private function get_target_member(object $own_member): object {
        $member_id = absint(wp_unslash($_GET['member_id'] ?? 0));
        if ($member_id > 0 && $this->can_edit_member($own_member, $member_id)) {
            $member = AVPVH_DB::get_member($member_id);
            if ($member) {
                return $member;
            }
        }
        return $own_member;
    }

    private function can_edit_member(object $own_member, int $target_member_id): bool {
        if (AVPVH_Roles::can_manage_activities()) {
            return true;
        }
        foreach (AVPVH_DB::get_manageable_members((int) $own_member->id) as $m) {
            if ((int) $m->id === $target_member_id) {
                return true;
            }
        }
        return false;
    }
}
