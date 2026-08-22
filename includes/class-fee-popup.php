<?php
defined('ABSPATH') || exit;

class AVPVH_Fee_Popup {

    public function __construct() {
        add_action('wp_login', [$this, 'check_fee_on_login'], 10, 2);
        add_action('wp_footer', [$this, 'maybe_render_popup']);
        add_action('wp_ajax_avpvh_dismiss_popup', [$this, 'ajax_dismiss']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function check_fee_on_login(string $user_login, \WP_User $user): void {
        $member = avpvh_get_member_by_wp_user($user->ID);
        if (!$member || $member->status !== 'active') {
            return;
        }
        $year = (int) current_time('Y');
        $fee = AVPVH_DB::get_fee_for_year((int) $member->id, $year);
        if (!$fee || $fee->status !== 'paid') {
            update_user_meta($user->ID, '_avpvh_show_fee_popup', $year);
        } else {
            delete_user_meta($user->ID, '_avpvh_show_fee_popup');
        }
    }

    public function maybe_render_popup(): void {
        if (!is_user_logged_in()) {
            return;
        }
        if (isset($_COOKIE['avpvh_fee_dismissed'])) {
            return;
        }
        $user_id = get_current_user_id();
        $popup_year = (int) get_user_meta($user_id, '_avpvh_show_fee_popup', true);
        if ($popup_year !== (int) current_time('Y')) {
            return;
        }
        ?>
        <div id="avpvh-fee-popup" class="avpvh-fee-popup-overlay" role="dialog" aria-modal="true" aria-labelledby="avpvh-fee-popup-title">
            <div class="avpvh-fee-popup-box">
                <h2 id="avpvh-fee-popup-title">Contributie <?php echo esc_html($popup_year); ?></h2>
                <p>Je contributie voor <?php echo esc_html($popup_year); ?> is nog niet ontvangen. Neem contact op met de penningmeester.</p>
                <button id="avpvh-fee-dismiss" class="button"><?php esc_html_e('Sluiten', 'avpvh-members'); ?></button>
            </div>
        </div>
        <?php
    }

    public function ajax_dismiss(): void {
        check_ajax_referer('avpvh_dismiss_popup', 'nonce');
        delete_user_meta(get_current_user_id(), '_avpvh_show_fee_popup');
        wp_send_json_success();
    }

    public function enqueue_assets(): void {
        if (!is_user_logged_in()) {
            return;
        }
        $user_id = get_current_user_id();
        $popup_year = (int) get_user_meta($user_id, '_avpvh_show_fee_popup', true);
        if ($popup_year !== (int) current_time('Y') || isset($_COOKIE['avpvh_fee_dismissed'])) {
            return;
        }
        $base = plugin_dir_url(dirname(__FILE__));
        wp_enqueue_style('avpvh-fee-popup', $base . 'assets/fee-popup.css', [], avpvh_asset_version('assets/fee-popup.css'));
        wp_enqueue_script('avpvh-fee-popup', $base . 'assets/fee-popup.js', ['jquery'], avpvh_asset_version('assets/fee-popup.js'), true);
        wp_localize_script('avpvh-fee-popup', 'avpvhPopup', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('avpvh_dismiss_popup'),
        ]);
    }
}
