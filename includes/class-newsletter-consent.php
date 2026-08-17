<?php
defined('ABSPATH') || exit;

class AVPVH_Newsletter_Consent {

    public function __construct() {
        add_action('admin_post_avpvh_set_newsletter_consent', [$this, 'handle_set_consent']);
    }

    public function handle_set_consent(): void {
        check_admin_referer('avpvh_set_newsletter_consent');

        if (!is_user_logged_in()) {
            wp_die('Je moet ingelogd zijn.', 'Fout', ['response' => 403]);
        }

        $own_member = AVPVH_DB::get_member_by_wp_user(get_current_user_id());
        if (!$own_member) {
            wp_die('Ledenprofiel niet gevonden.', 'Fout', ['response' => 404]);
        }

        $member = $own_member;
        $requested_id = (int) ($_POST['member_id'] ?? 0);
        if ($requested_id > 0 && $requested_id !== (int) $own_member->id) {
            $manageable = AVPVH_DB::get_manageable_members((int) $own_member->id);
            $member = null;
            foreach ($manageable as $m) {
                if ((int) $m->id === $requested_id) {
                    $member = $m;
                    break;
                }
            }
            if (!$member) {
                wp_die('Geen toegang tot dit ledenprofiel.', 'Fout', ['response' => 403]);
            }
        }

        AVPVH_DB::set_member_flag_by_slug((int) $member->id, 'nieuwsbrief', !empty($_POST['newsletter']));

        wp_safe_redirect(add_query_arg('newsletter_saved', '1', wp_get_referer() ?: home_url('/')));
        exit;
    }
}
