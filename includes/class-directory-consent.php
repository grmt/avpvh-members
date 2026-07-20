<?php
defined('ABSPATH') || exit;

class AVPVH_Directory_Consent {

    public function __construct() {
        add_action('admin_post_avpvh_set_directory_consent', [$this, 'handle_set_consent']);
    }

    public function handle_set_consent(): void {
        check_admin_referer('avpvh_directory_consent');

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

        $decision = sanitize_key($_POST['consent'] ?? '');
        if (!in_array($decision, ['granted', 'declined'], true)) {
            wp_die('Ongeldige keuze.', 'Fout', ['response' => 400]);
        }

        $granted = $decision === 'granted';

        AVPVH_DB::update_member_with_audit(
            (int) $member->id,
            [
                'directory_consent'    => $decision,
                'directory_consent_at' => current_time('mysql'),
                'share_email'          => $granted && !empty($_POST['share_email']) ? 1 : 0,
                'share_phone'          => $granted && !empty($_POST['share_phone']) ? 1 : 0,
                'share_address'        => $granted && !empty($_POST['share_address']) ? 1 : 0,
            ],
            ['%s', '%s', '%d', '%d', '%d']
        );

        wp_safe_redirect(add_query_arg('consent_saved', '1', wp_get_referer() ?: home_url('/')));
        exit;
    }
}
