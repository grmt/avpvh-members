<?php
defined('ABSPATH') || exit;

class AVPVH_Nav_Auth {

    const AUTHELIA_URL = 'https://auth.avphilipsvanhorne.nl';

    // Page IDs whose nav items must be hidden for guests.
    const MEMBERS_PAGE_IDS = [647, 36];

    public function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_action('wp_footer',          [$this, 'render_logout_route']);
        add_action('rest_api_init',      [$this, 'register_logout_route']);
    }

    public function enqueue(): void {
        $member = is_user_logged_in()
            ? avpvh_get_member_by_wp_user(get_current_user_id())
            : null;

        $is_active = $member && $member->status === 'active';

        wp_enqueue_script(
            'avpvh-nav-auth',
            plugin_dir_url(dirname(__FILE__)) . 'assets/nav-auth.js',
            [], '1.0', true
        );

        add_action('wp_footer', function () use ($is_active) {
            echo '<script type="application/json" id="avpvh-auth-config">'
                . wp_json_encode([
                    'isLoggedIn'     => is_user_logged_in(),
                    'isActiveMember' => $is_active,
                    'membersPageIds' => self::MEMBERS_PAGE_IDS,
                    'logoutUrl'      => rest_url('avpvh/v1/logout'),
                    'loginUrl'       => home_url('/avpvh-login/'),
                ])
                . '</script>';
        });
    }

    public function render_logout_route(): void {
        // nothing needed here — REST endpoint handles it
    }

    public function register_logout_route(): void {
        register_rest_route('avpvh/v1', '/logout', [
            'methods'             => 'GET',
            'callback'            => [$this, 'rest_logout'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function rest_logout(): void {
        wp_logout();
        wp_redirect(self::AUTHELIA_URL . '/logout?rd=https://www.avphilipsvanhorne.nl/');
        exit;
    }
}
