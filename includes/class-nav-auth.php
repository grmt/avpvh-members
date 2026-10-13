<?php
defined('ABSPATH') || exit;

class AVPVH_Nav_Auth {

    const AUTHELIA_URL = 'https://auth.avphilipsvanhorne.nl';
    const AUTHELIA_SESSION_COOKIE = 'avpvh_session';
    // Short-lived, read once by nav-auth.js to show "Uitgelogd als ...".
    const SIGNED_OUT_COOKIE = 'avpvh_signed_out';

    // Page IDs whose nav items require active membership.
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
        $is_visitor = $member && $member->status === 'visitor';
        $member_status_label = match ($member->status ?? '') {
            'active'   => __('Lid', 'avpvh-members'),
            'inactive' => __('Oud lid', 'avpvh-members'),
            'visitor'  => __('Bezoeker', 'avpvh-members'),
            default    => __('Geen lid', 'avpvh-members'),
        };
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

        add_action('wp_footer', function () use ($is_active, $is_visitor, $member_status_label, $identity_label, $role_label, $member_role_label, $has_doc_search_access) {
            echo '<script type="application/json" id="avpvh-auth-config">'
                . wp_json_encode([
                    'isLoggedIn'         => is_user_logged_in(),
                    'isActiveMember'     => $is_active,
                    'memberStatusLabel'  => $member_status_label,
                    'userLabel'          => $identity_label,
                    'roleLabel'          => $role_label,
                    'memberRoleLabel'    => $member_role_label,
                    'membersPageIds'     => self::MEMBERS_PAGE_IDS,
                    'hasDocSearchAccess' => $has_doc_search_access,
                    'docSearchPageId'    => self::DOC_SEARCH_PAGE_ID,
                    'logoutUrl'          => rest_url('avpvh/v1/logout'),
                    'signedOutCookie'    => self::SIGNED_OUT_COOKIE,
                    'loginUrl'           => home_url('/avpvh-login/'),
                    'profileUrl'         => self::profile_url(),
                    'paymentsUrl'        => shortcode_exists('avpvh_bk_balance') ? self::profile_url() . '#bijdrage' : '',
                    'membershipApplicationUrl' => $is_visitor ? self::profile_url() . '#lidmaatschap' : '',
                    'labels'             => [
                        'member'      => __('Lid', 'avpvh-members'),
                        'nonMember'   => __('Geen lid', 'avpvh-members'),
                        'myProfile'   => __('Mijn profiel', 'avpvh-members'),
                        'myPayments'  => __('Mijn betalingen', 'avpvh-members'),
                        'applyMembership' => __('Lidmaatschap aanvragen', 'avpvh-members'),
                        'logout'      => __('Uitloggen', 'avpvh-members'),
                        'login'       => __('Inloggen', 'avpvh-members'),
                        'loggedIn'    => __('Ingelogd', 'avpvh-members'),
                        'account'     => __('Account', 'avpvh-members'),
                        'accountMenu' => __('Account submenu', 'avpvh-members'),
                        /* translators: %s: e-mail address of the account that was signed out */
                        'signedOutAs' => __('Uitgelogd als %s.', 'avpvh-members'),
                        /* translators: %s: e-mail address of the account whose Authelia (2FA) session was ended */
                        'signedOutSso' => __('Ook de beveiligde sessie (2FA) van %s is beëindigd.', 'avpvh-members'),
                        'close'       => __('Sluiten', 'avpvh-members'),
                    ],
                ])
                . '</script>';
        });
    }

    public static function profile_url(): string {
        $page = get_page_by_path('leden/beheer/member-profile') ?? get_page_by_path('member-profile');
        return $page ? get_permalink($page) : home_url('/member-profile/');
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

        return in_array('boek', AVPVH_Directory::cached_user_groups($member->user_id), true);
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
            return __('Gebruiker', 'avpvh-members');
        }

        return match ($user->roles[0]) {
            'administrator' => __('Beheerder', 'avpvh-members'),
            'editor'        => __('Redacteur', 'avpvh-members'),
            'author'        => __('Auteur', 'avpvh-members'),
            'contributor'   => __('Medewerker', 'avpvh-members'),
            'subscriber'    => __('Lid', 'avpvh-members'),
            default         => ucfirst(str_replace('_', ' ', $user->roles[0])),
        };
    }

    private function member_role_label(\WP_User $user): string {
        $role = (string) get_user_meta($user->ID, 'avpvh_member_role', true);
        if ($role === '') {
            return '';
        }

        return match (strtolower($role)) {
            'bestuur'      => __('Bestuur', 'avpvh-members'),
            'feest'        => __('Feest', 'avpvh-members'),
            'boek'         => __('Boek', 'avpvh-members'),
            'fiscus'       => __('Fiscus', 'avpvh-members'),
            'secretariaat' => __('Secretariaat', 'avpvh-members'),
            default        => ucfirst($role),
        };
    }

    public function rest_logout(): void {
        self::remember_signed_out_identities();
        wp_logout();
        self::invalidate_authelia_session();
        wp_safe_redirect(self::authelia_logout_url(home_url('/')));
        exit;
    }

    /**
     * Call before ending the sessions: notes which account the WP session
     * and which the Authelia session belonged to (they can differ), so the
     * next page can show who was signed out. The addresses travel in a
     * 5-minute cookie rather than in a redirect URL, which would put them
     * in server logs.
     */
    public static function remember_signed_out_identities(): void {
        $member = is_user_logged_in() ? avpvh_get_member_by_wp_user(get_current_user_id()) : null;
        $identities = array_filter([
            'wp'  => $member && !empty($member->email) ? sanitize_email((string) $member->email) : '',
            'sso' => self::authelia_session_identity_email(),
        ]);
        if (!$identities || headers_sent()) {
            return;
        }
        setcookie(self::SIGNED_OUT_COOKIE, (string) wp_json_encode($identities), [
            'expires'  => time() + 5 * MINUTE_IN_SECONDS,
            'path'     => COOKIEPATH ?: '/',
            'domain'   => COOKIE_DOMAIN ?: '',
            'secure'   => is_ssl(),
            'httponly' => false, // nav-auth.js reads and then deletes it
            'samesite' => 'Lax',
        ]);
    }

    /** E-mail of the member the current Authelia browser session belongs to, or ''. */
    public static function authelia_session_identity_email(): string {
        $session = self::authelia_session_cookie();
        if ($session === '') {
            return '';
        }
        $response = wp_remote_get(self::AUTHELIA_URL . '/api/state', [
            'timeout'     => 5,
            'redirection' => 0,
            'headers'     => [
                'Accept' => 'application/json',
                'Cookie' => self::AUTHELIA_SESSION_COOKIE . '=' . $session,
            ],
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return '';
        }
        $state = json_decode(wp_remote_retrieve_body($response), true);
        // Authelia wraps the payload as {"status":"OK","data":{"username":...}}.
        $username = sanitize_text_field((string) ($state['data']['username'] ?? $state['username'] ?? ''));
        if ($username === '') {
            return '';
        }
        $member = AVPVH_DB::get_member_by_lldap_uid($username);
        return $member && !empty($member->email) ? sanitize_email((string) $member->email) : '';
    }

    private static function authelia_session_cookie(): string {
        $session = wp_unslash($_COOKIE[self::AUTHELIA_SESSION_COOKIE] ?? '');
        // RFC cookie-octets may contain characters such as '%' and '|'. Only
        // reject bytes that could terminate or inject a Cookie header.
        if ($session === '' || preg_match('/[\x00-\x20\x22\x2c\x3b\x5c\x7f]/', $session)) {
            return '';
        }
        return $session;
    }

    public static function authelia_logout_url(string $redirect_url): string {
        return add_query_arg('rd', rawurlencode($redirect_url), self::AUTHELIA_URL . '/logout');
    }

    /**
     * Destroy the shared SSO session at Authelia as a server-side guarantee.
     * The browser still has to visit /logout afterwards to drop the
     * now-invalid cookie itself.
     */
    public static function invalidate_authelia_session(): bool {
        $session = self::authelia_session_cookie();
        if ($session === '') {
            return false;
        }

        $response = wp_remote_post(self::AUTHELIA_URL . '/api/logout', [
            'timeout'     => 5,
            'redirection' => 0,
            'headers'     => [
                'Content-Type' => 'application/json',
                'Cookie'       => self::AUTHELIA_SESSION_COOKIE . '=' . $session,
            ],
            'body'        => wp_json_encode(['targetURL' => home_url('/')]),
        ]);
        $ok = !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
        if (!$ok) {
            // No cookie value or member data is logged.
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('AVPVH_Nav_Auth: server-side Authelia logout failed.');
        }
        return $ok;
    }
}
