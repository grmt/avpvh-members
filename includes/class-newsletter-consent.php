<?php
defined('ABSPATH') || exit;

class AVPVH_Newsletter_Consent {

    public function __construct() {
        add_action('admin_post_avpvh_set_newsletter_consent', [$this, 'handle_set_consent']);
    }

    public function handle_set_consent(): void {
        check_admin_referer('avpvh_set_newsletter_consent');

        if (!is_user_logged_in()) {
            wp_die(esc_html__('Je moet ingelogd zijn.', 'avpvh-members'), esc_html__('Fout', 'avpvh-members'), ['response' => 403]);
        }

        $own_member = AVPVH_DB::get_member_by_wp_user(get_current_user_id());
        if (!$own_member) {
            wp_die(esc_html__('Ledenprofiel niet gevonden.', 'avpvh-members'), esc_html__('Fout', 'avpvh-members'), ['response' => 404]);
        }

        $member = $own_member;
        $requested_id = absint(wp_unslash($_POST['member_id'] ?? 0));
        if ($requested_id > 0 && $requested_id !== (int) $own_member->id) {
            $manageable = AVPVH_DB::get_manageable_members((int) $own_member->id);
            $member = null;
            foreach ($manageable as $m) {
                if ((int) $m->id === $requested_id) {
                    $member = $m;
                    break;
                }
            }
            // Adults manage their own newsletter consent; a household member
            // may only change it on behalf of a minor (< 16).
            if (!$member || !AVPVH_DB::is_minor_member($member, 16, true)) {
                wp_die(esc_html__('Geen toegang tot dit ledenprofiel.', 'avpvh-members'), esc_html__('Fout', 'avpvh-members'), ['response' => 403]);
            }
        }

        AVPVH_DB::set_member_flag_by_slug((int) $member->id, 'nieuwsbrief', !empty($_POST['newsletter']));

        wp_safe_redirect(add_query_arg('newsletter_saved', '1', AVPVH_Access::safe_same_site_redirect(wp_get_referer() ?: home_url('/'))));
        exit;
    }
}
