<?php
defined('ABSPATH') || exit;

class AVPVH_Nav_Auth {

    const AUTHELIA_URL = 'https://auth.avphilipsvanhorne.nl';

    // Page IDs whose nav items must be hidden for guests.
    const MEMBERS_PAGE_IDS = [647, 36];

    // "Zoeken in documenten" — gated by Authelia (one_factor, group:boek) at
    // the nginx layer, independent of the WordPress login. A member who
    // isn't in that LLDAP group can never open it, so the link is hidden
    // rather than sending them into a second login they can't complete.
    const DOC_SEARCH_PAGE_ID = 1920;

    public function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_action('wp_footer',          [$this, 'render_logout_route']);
        add_action('rest_api_init',      [$this, 'register_logout_route']);
        add_filter('allowed_redirect_hosts', [$this, 'allow_authelia_host']);
    }

    // Lets wp_safe_redirect() send the user on to Authelia's own logout
    // endpoint instead of treating it as an unsafe external target —
    // self::AUTHELIA_URL is a fixed constant, never user input.
    public function allow_authelia_host(array $hosts): array {
        $hosts[] = wp_parse_url(self::AUTHELIA_URL, PHP_URL_HOST);
        return $hosts;
    }

    public function enqueue(): void {
        $member = is_user_logged_in()
            ? avpvh_get_member_by_wp_user(get_current_user_id())
            : null;
        $user   = wp_get_current_user();

        $is_active = $member && $member->status === 'active';
        $role_label = $this->role_label($user);
        $member_role_label = $this->member_role_label($user);
        $identity_label = $member
            ? avpvh_format_name($member)
            : ($user && $user->display_name ? $user->display_name : $user->user_login);
        $has_doc_search_access = $this->has_boek_access($member);

        wp_enqueue_script(
            'avpvh-nav-auth',
            plugin_dir_url(dirname(__FILE__)) . 'assets/nav-auth.js',
            [], avpvh_asset_version('assets/nav-auth.js'), ['strategy' => 'defer', 'in_footer' => true]
        );

        add_action('wp_footer', function () use ($is_active, $identity_label, $role_label, $member_role_label, $has_doc_search_access) {
            echo '<script type="application/json" id="avpvh-auth-config">'
                . wp_json_encode([
                    'isLoggedIn'         => is_user_logged_in(),
                    'isActiveMember'     => $is_active,
                    'userLabel'          => $identity_label,
                    'roleLabel'          => $role_label,
                    'memberRoleLabel'    => $member_role_label,
                    'membersPageIds'     => self::MEMBERS_PAGE_IDS,
                    'hasDocSearchAccess' => $has_doc_search_access,
                    'docSearchPageId'    => self::DOC_SEARCH_PAGE_ID,
                    'logoutUrl'          => rest_url('avpvh/v1/logout'),
                    'loginUrl'           => home_url('/avpvh-login/'),
                    'profileUrl'         => home_url('/member-profile/'),
                ])
                . '</script>';
        });
    }

    // Mirrors the Authelia rule `resources: ['^/leden/zoeken-in-documenten/?$'],
    // subject: 'group:boek'` in config/authelia-configuration.yml — LLDAP group
    // membership is the actual authorization boundary, checked live via
    // AVPVH_LLDAP (same call the profile page's "Groepen:" row uses), cached
    // briefly so this doesn't add an LLDAP round-trip to every page load.
    private function has_boek_access(?object $member): bool {
        if (!$member || empty($member->user_id)) {
            return false;
        }

        $cache_key = 'avpvh_lldap_groups_' . $member->user_id;
        $groups = get_transient($cache_key);

        if ($groups === false) {
            $result = AVPVH_LLDAP::get_user_groups($member->user_id);
            $groups = is_wp_error($result) ? [] : $result;
            set_transient($cache_key, $groups, is_wp_error($result) ? MINUTE_IN_SECONDS : 15 * MINUTE_IN_SECONDS);
        }

        foreach ($groups as $group) {
            if (strtolower($group['displayName'] ?? '') === 'boek') {
                return true;
            }
        }
        return false;
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

    private function role_label(\WP_User $user): string {
        if (empty($user->roles)) {
            return 'Gebruiker';
        }

        return match ($user->roles[0]) {
            'administrator' => 'Beheerder',
            'editor'         => 'Redacteur',
            'author'         => 'Auteur',
            'contributor'    => 'Medewerker',
            'subscriber'     => 'Lid',
            default          => ucfirst(str_replace('_', ' ', $user->roles[0])),
        };
    }

    private function member_role_label(\WP_User $user): string {
        $role = (string) get_user_meta($user->ID, 'avpvh_member_role', true);
        if ($role === '') {
            return '';
        }

        return match (strtolower($role)) {
            'bestuur'      => 'Bestuur',
            'feest'        => 'Feest',
            'boek'         => 'Boek',
            'fiscus'       => 'Fiscus',
            'secretariaat'  => 'Secretariaat',
            default        => ucfirst($role),
        };
    }

    public function rest_logout(): void {
        wp_logout();
        wp_safe_redirect(self::authelia_logout_url(home_url('/')));
        exit;
    }

    public static function authelia_logout_url(string $redirect_url): string {
        return add_query_arg('rd', $redirect_url, self::AUTHELIA_URL . '/logout');
    }
}
