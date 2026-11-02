<?php
defined('ABSPATH') || exit;

/**
 * "Inloggen met Google/Microsoft" on the main site, and "add this account
 * to my profile". State bound to the browser, PKCE and a verified address
 * from the ID token: see AVPVH_OIDC.
 */
class AVPVH_OAuth {

    const PROVIDERS = [
        'google' => [
            'label'        => 'Google',
            'auth_url'     => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url'    => 'https://oauth2.googleapis.com/token',
            'scope'        => 'openid email',
        ],
        'microsoft' => [
            'label'        => 'Microsoft',
            'auth_url'     => 'https://login.microsoftonline.com/consumers/oauth2/v2.0/authorize',
            'token_url'    => 'https://login.microsoftonline.com/consumers/oauth2/v2.0/token',
            'scope'        => 'openid email',
        ],
    ];

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_filter('allowed_redirect_hosts', [$this, 'allow_provider_hosts']);
    }

    // Lets wp_safe_redirect() send the user on to Google/Microsoft's own
    // OAuth authorize endpoint instead of treating it as an unsafe external
    // target — the hosts come from self::PROVIDERS, a fixed constant, never
    // user input.
    public function allow_provider_hosts(array $hosts): array {
        foreach (self::PROVIDERS as $config) {
            $hosts[] = wp_parse_url($config['auth_url'], PHP_URL_HOST);
        }
        return $hosts;
    }

    public function register_routes(): void {
        foreach (array_keys(self::PROVIDERS) as $provider) {
            register_rest_route('avpvh/v1', '/oauth/' . $provider . '/start', [
                'methods'             => 'GET',
                'callback'            => fn($r) => $this->start($provider, $r),
                'permission_callback' => '__return_true',
            ]);
            register_rest_route('avpvh/v1', '/oauth/' . $provider . '/callback', [
                'methods'             => 'GET',
                'callback'            => fn($r) => $this->handle_callback($provider, $r),
                'permission_callback' => '__return_true',
            ]);
        }
    }

    public function start(string $provider, \WP_REST_Request $request): void {
        $client_id = get_option('avpvh_oauth_' . $provider . '_client_id');
        if (!$client_id) {
            wp_die(esc_html($provider) . ' login is niet geconfigureerd.', 'Fout', ['response' => 503]);
        }

        $add_member_id = (int) $request->get_param('add_member_id');
        $flow = AVPVH_OIDC::begin();
        $state_key = AVPVH_OIDC::state_key('avpvh_oauth_state_', $flow['state']);

        if ($add_member_id > 0) {
            if (!is_user_logged_in()) {
                wp_die('Je moet ingelogd zijn.', 'Fout', ['response' => 403]);
            }
            // Self only — a household member must not be able to start this
            // flow on someone else's behalf (see handle_add_identity_callback()
            // for why the completing check is also self-only, not household-wide).
            $own_member = AVPVH_DB::get_member_by_wp_user(get_current_user_id());
            if (!$own_member || (int) $own_member->id !== $add_member_id) {
                wp_die('Geen toegang.', 'Fout', ['response' => 403]);
            }
            // JSON, not a serialized array: AVPVH_Visitor_Delete::tokens()
            // finds a member's pending flows by decoding these.
            set_transient($state_key, wp_json_encode([
                'provider'           => $provider,
                'mode'               => 'add',
                'member_id'          => $add_member_id,
                'requesting_user_id' => get_current_user_id(),
                'verifier'           => $flow['verifier'],
                'nonce'              => $flow['nonce'],
            ]), AVPVH_OIDC::STATE_TTL);
        } else {
            $redirect_to = sanitize_text_field(wp_unslash($request->get_param('redirect_to') ?? ''));
            set_transient($state_key, wp_json_encode([
                'provider'    => $provider,
                'redirect_to' => $redirect_to,
                'verifier'    => $flow['verifier'],
                'nonce'       => $flow['nonce'],
            ]), AVPVH_OIDC::STATE_TTL);
        }

        $config = self::PROVIDERS[$provider];
        $params = [
            'client_id'     => $client_id,
            'redirect_uri'  => $this->callback_url($provider),
            'response_type' => 'code',
            'scope'         => $config['scope'],
            // Always show the provider's account picker, so a browser that
            // is signed in to someone else's Google/Microsoft account does
            // not log in as that person without asking.
            'prompt'        => 'select_account',
        ] + AVPVH_OIDC::authorize_params($flow);

        wp_safe_redirect($config['auth_url'] . '?' . http_build_query($params));
        exit;
    }

    private function get_client_ip(): string {
        // nginx talks FastCGI to PHP and sets REMOTE_ADDR to the real client
        // address. X-Forwarded-For reaches PHP unchanged from the client, so
        // trusting it would let anyone pick a new "IP" per request and skip
        // the failure throttle below.
        return sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
    }

    private function check_ip_throttle(): bool {
        $key = 'avpvh_oauth_fail_' . hash('sha256', $this->get_client_ip());
        return (int) get_transient($key) >= 3;
    }

    private function record_ip_failure(): void {
        $key   = 'avpvh_oauth_fail_' . hash('sha256', $this->get_client_ip());
        $count = (int) get_transient($key);
        set_transient($key, $count + 1, 15 * MINUTE_IN_SECONDS);
    }

    public function handle_callback(string $provider, \WP_REST_Request $request): void {
        if ($this->check_ip_throttle()) {
            wp_safe_redirect(home_url('/avpvh-login/?login_error=no_member'));
            exit;
        }

        $code  = sanitize_text_field($request->get_param('code')  ?? '');
        $state = sanitize_text_field($request->get_param('state') ?? '');

        if (!$code || !$state) {
            wp_safe_redirect(home_url('/avpvh-login/?login_error=oauth_failed'));
            exit;
        }

        $key    = AVPVH_OIDC::state_key('avpvh_oauth_state_', $state);
        $stored = json_decode((string) get_transient($key), true);
        delete_transient($key);

        // The state must be one we issued (transient) AND the one this
        // browser started with (cookie): a callback URL from a flow started
        // in someone else's browser - say, an "add this account to my
        // profile" link an attacker sends round - is refused.
        // Most commonly a failure here is just an expired transient (10 min)
        // after a slow consent/2FA step, so send them back to try again
        // rather than dead-ending on a wp_die() page.
        if (!AVPVH_OIDC::state_matches_browser($state) || !is_array($stored) || ($stored['provider'] ?? '') !== $provider) {
            wp_safe_redirect(home_url('/avpvh-login/?login_error=oauth_expired'));
            exit;
        }

        $add_request = ($stored['mode'] ?? '') === 'add' ? $stored : null;
        $redirect_to = (string) ($stored['redirect_to'] ?? '');

        $email = $this->fetch_email($provider, $code, (string) $stored['verifier'], (string) $stored['nonce']);
        if (!$email) {
            wp_die('Kon e-mailadres niet ophalen bij ' . esc_html(self::PROVIDERS[$provider]['label']) . '.', 'Fout', ['response' => 502]);
        }

        if ($add_request) {
            $this->handle_add_identity_callback($provider, $email, $add_request);
            return;
        }

        // An admin-added identity is stored under a placeholder provider (see
        // handle_add_identity()), so an exact provider+email match can miss
        // it — fall back to matching the email alone before giving up.
        $member_identity = AVPVH_DB::get_member_identity($provider, $email)
            ?? AVPVH_DB::get_identity_by_email($email);
        $member = $member_identity
            ? AVPVH_DB::get_member((int) $member_identity->member_id)
            : AVPVH_DB::get_member_by_email($email);
        if (!$member) {
            $this->record_ip_failure();
            AVPVH_DB::log_attempt($email, $provider, 'no_member');
            wp_safe_redirect(home_url('/avpvh-login/?login_error=no_member'));
            exit;
        }

        // Also runs when the matched row exists but under the wrong
        // provider or still unverified — ensure_identity() upgrades it
        // in place (by email) rather than adding a duplicate.
        $needs_upgrade = !$member_identity || $member_identity->provider !== $provider || !$member_identity->verified_at;
        if ($needs_upgrade && !AVPVH_DB::ensure_identity((int) $member->id, $provider, $email, false, true)) {
            // Deliberate diagnostic logging, not leftover debug code — this
            // is the only signal an admin gets that a login worked but the
            // identity link failed (3-identity limit or invalid provider).
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log("AVPVH_OAuth: failed to link {$provider} identity ({$email}) for member {$member->id} — at 3-identity limit or invalid provider.");
        }

        $user = AVPVH_DB::get_or_create_wp_user($email, $member);
        if (!$user) {
            wp_die('Kon WordPress-gebruiker niet aanmaken.', 'Fout', ['response' => 500]);
        }

        AVPVH_DB::log_attempt($email, $provider, 'success');
        // OAuth and Authelia keep separate browser sessions. End the old WP
        // session first; the Authelia session is ended below, so an Authelia
        // (2FA) session of another account can't outlive this login.
        if (is_user_logged_in()) {
            wp_logout();
        }
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true);
        do_action('wp_login', $user->user_login, $user); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- deliberately firing WP core's own wp_login hook (not a custom hook) so other code listening for a normal login still runs on this OAuth bridge

        $target = !empty($redirect_to) ? wp_validate_redirect($redirect_to, home_url('/')) : home_url('/');
        // Only the browser itself can drop the shared Authelia cookie, so
        // route it through Authelia's /logout on the way to the target.
        AVPVH_Nav_Auth::invalidate_authelia_session();
        wp_safe_redirect(AVPVH_Nav_Auth::authelia_logout_url($target));
        exit;
    }

    /**
     * Handles the callback for "verify and add this account to my profile"
     * (started from the member profile page, not a login attempt). The
     * OAuth round-trip itself is the proof that the requesting user
     * actually controls this e-mail address.
     */
    private function handle_add_identity_callback(string $provider, string $email, array $add_request): void {
        $member_id  = (int) $add_request['member_id'];
        $user_id    = (int) $add_request['requesting_user_id'];
        $profile_url = home_url('/member-profile/');
        $redirect_args = ['member_id' => $member_id];

        // Identify the requester from the state transient we issued at the
        // start of the flow, not from the request's own cookies: REST
        // requests reached via a plain browser redirect (as this callback
        // is, from Google/Microsoft) don't carry the wp_rest nonce cookie
        // auth needs, so is_user_logged_in() can't be trusted here. The
        // transient - keyed by the random `state` value we generated, which
        // must also match this browser's state cookie - is the CSRF
        // protection for this flow.
        $requesting_user = $user_id ? get_userdata($user_id) : false;
        if (!$requesting_user) {
            wp_safe_redirect(add_query_arg($redirect_args + ['identity_error' => 'not_you'], $profile_url));
            exit;
        }

        // Self only. The OAuth round-trip only proves who completed it, not
        // that it's actually $member_id, so a household member (e.g. a
        // spouse) must not be able to attach their own account as a login
        // identity for someone else's profile.
        $own_member = AVPVH_DB::get_member_by_wp_user($user_id);
        if (!$own_member || (int) $own_member->id !== $member_id) {
            wp_die('Geen toegang.', 'Fout', ['response' => 403]);
        }

        // Never let this e-mail be claimed if it's already linked to someone else.
        $existing_identity = AVPVH_DB::get_member_identity($provider, $email);
        if ($existing_identity && (int) $existing_identity->member_id !== $member_id) {
            wp_safe_redirect(add_query_arg($redirect_args + ['identity_error' => 'in_use'], $profile_url));
            exit;
        }
        $existing_owner = AVPVH_DB::get_member_by_email($email);
        if ($existing_owner && (int) $existing_owner->id !== $member_id) {
            wp_safe_redirect(add_query_arg($redirect_args + ['identity_error' => 'in_use'], $profile_url));
            exit;
        }

        $member = AVPVH_DB::get_member($member_id);
        if (!$member || !AVPVH_DB::ensure_identity($member_id, $provider, $email, false, true)) {
            wp_safe_redirect(add_query_arg($redirect_args + ['identity_error' => 'limit'], $profile_url));
            exit;
        }

        AVPVH_Member_Profile_Form::notify_identity_change($member, $own_member, 'toegevoegd', $provider, $email, $requesting_user);

        wp_safe_redirect(add_query_arg($redirect_args + ['identity_added' => '1'], $profile_url));
        exit;
    }

    /**
     * The address from the ID token, only when the provider vouches for it
     * (AVPVH_OIDC::verified_email()): an unverified Google address or a
     * non-personal Microsoft account gives null.
     */
    private function fetch_email(string $provider, string $code, string $verifier, string $nonce): ?string {
        $config    = self::PROVIDERS[$provider];
        $client_id = (string) get_option('avpvh_oauth_' . $provider . '_client_id');

        $token = wp_remote_post($config['token_url'], [
            'timeout' => 10,
            'body'    => [
                'client_id'     => $client_id,
                'client_secret' => get_option('avpvh_oauth_' . $provider . '_client_secret'),
                'code'          => $code,
                'code_verifier' => $verifier,
                'redirect_uri'  => $this->callback_url($provider),
                'grant_type'    => 'authorization_code',
            ],
        ]);

        if (is_wp_error($token) || (int) wp_remote_retrieve_response_code($token) !== 200) {
            return null;
        }

        $id_token = (string) (json_decode(wp_remote_retrieve_body($token), true)['id_token'] ?? '');
        $email    = AVPVH_OIDC::verified_email($provider, AVPVH_OIDC::claims($id_token), $client_id, $nonce);
        return $email ? sanitize_email($email) : null;
    }

    private function callback_url(string $provider): string {
        return rest_url('avpvh/v1/oauth/' . $provider . '/callback');
    }

    public static function add_identity_url(string $provider, int $member_id): string {
        // Cookie-authenticated REST requests need a valid wp_rest nonce or
        // WordPress ignores the logged-in cookie entirely (CSRF protection) —
        // without this, is_user_logged_in() would see a guest even though the
        // member is clearly logged in in their browser.
        return add_query_arg(
            ['add_member_id' => $member_id, '_wpnonce' => wp_create_nonce('wp_rest')],
            rest_url('avpvh/v1/oauth/' . $provider . '/start')
        );
    }

    public static function login_url(string $provider, string $redirect_to = ''): string {
        $url = rest_url('avpvh/v1/oauth/' . $provider . '/start');
        if (!empty($redirect_to)) {
            $url = add_query_arg('redirect_to', $redirect_to, $url);
        }
        return $url;
    }

    public static function configured_providers(): array {
        return array_filter(
            self::PROVIDERS,
            fn($key) => (bool) get_option('avpvh_oauth_' . $key . '_client_id'),
            ARRAY_FILTER_USE_KEY
        );
    }
}
