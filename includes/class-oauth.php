<?php
defined('ABSPATH') || exit;

class AVPVH_OAuth {

    const PROVIDERS = [
        'google' => [
            'label'        => 'Google',
            'auth_url'     => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url'    => 'https://oauth2.googleapis.com/token',
            'userinfo_url' => 'https://www.googleapis.com/oauth2/v3/userinfo',
            'scope'        => 'openid email',
        ],
        'microsoft' => [
            'label'        => 'Microsoft',
            'auth_url'     => 'https://login.microsoftonline.com/consumers/oauth2/v2.0/authorize',
            'token_url'    => 'https://login.microsoftonline.com/consumers/oauth2/v2.0/token',
            'userinfo_url' => 'https://graph.microsoft.com/v1.0/me',
            'scope'        => 'openid email User.Read',
        ],
    ];

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void {
        foreach (array_keys(self::PROVIDERS) as $provider) {
            register_rest_route('avpvh/v1', '/oauth/' . $provider . '/start', [
                'methods'             => 'GET',
                'callback'            => fn($r) => $this->start($provider),
                'permission_callback' => '__return_true',
            ]);
            register_rest_route('avpvh/v1', '/oauth/' . $provider . '/callback', [
                'methods'             => 'GET',
                'callback'            => fn($r) => $this->handle_callback($provider, $r),
                'permission_callback' => '__return_true',
            ]);
        }
    }

    public function start(string $provider): void {
        $client_id = get_option('avpvh_oauth_' . $provider . '_client_id');
        if (!$client_id) {
            wp_die($provider . ' login is niet geconfigureerd.', 'Fout', ['response' => 503]);
        }

        $state = wp_generate_password(32, false);
        set_transient('avpvh_oauth_state_' . $state, $provider, 600);

        $config = self::PROVIDERS[$provider];
        $params = [
            'client_id'     => $client_id,
            'redirect_uri'  => $this->callback_url($provider),
            'response_type' => 'code',
            'scope'         => $config['scope'],
            'state'         => $state,
        ];

        wp_redirect($config['auth_url'] . '?' . http_build_query($params));
        exit;
    }

    private function get_client_ip(): string {
        // Trust X-Forwarded-For only from our nginx proxy (runs on the same host).
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($forwarded) {
            return trim(explode(',', $forwarded)[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }

    private function check_ip_throttle(): bool {
        $key = 'avpvh_oauth_fail_' . md5($this->get_client_ip());
        return (int) get_transient($key) >= 3;
    }

    private function record_ip_failure(): void {
        $key   = 'avpvh_oauth_fail_' . md5($this->get_client_ip());
        $count = (int) get_transient($key);
        set_transient($key, $count + 1, 15 * MINUTE_IN_SECONDS);
    }

    public function handle_callback(string $provider, \WP_REST_Request $request): void {
        if ($this->check_ip_throttle()) {
            wp_redirect(home_url('/avpvh-login/?error=no_member'));
            exit;
        }

        $code  = sanitize_text_field($request->get_param('code')  ?? '');
        $state = sanitize_text_field($request->get_param('state') ?? '');

        if (!$code || !$state) {
            wp_die('Ongeldige OAuth callback.', 'Fout', ['response' => 400]);
        }

        $stored = get_transient('avpvh_oauth_state_' . $state);
        if ($stored !== $provider) {
            wp_die('Ongeldige OAuth state.', 'Fout', ['response' => 400]);
        }
        delete_transient('avpvh_oauth_state_' . $state);

        $email = $this->fetch_email($provider, $code);
        if (!$email) {
            wp_die('Kon e-mailadres niet ophalen bij ' . esc_html(self::PROVIDERS[$provider]['label']) . '.', 'Fout', ['response' => 502]);
        }

        $member_identity = AVPVH_DB::get_member_identity($provider, $email);
        $member = $member_identity
            ? AVPVH_DB::get_member((int) $member_identity->member_id)
            : AVPVH_DB::get_member_by_email($email);
        if (!$member) {
            $this->record_ip_failure();
            AVPVH_DB::log_attempt($email, $provider, 'no_member');
            wp_redirect(home_url('/avpvh-login/?error=no_member'));
            exit;
        }

        if (!$member_identity) {
            AVPVH_DB::ensure_identity((int) $member->id, $provider, $email);
        }

        $user = $this->get_or_create_wp_user($email, $member);
        if (!$user) {
            wp_die('Kon WordPress-gebruiker niet aanmaken.', 'Fout', ['response' => 500]);
        }

        AVPVH_DB::log_attempt($email, $provider, 'success');
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true);
        do_action('wp_login', $user->user_login, $user);

        wp_redirect(home_url('/'));
        exit;
    }

    private function fetch_email(string $provider, string $code): ?string {
        $config = self::PROVIDERS[$provider];

        $token = wp_remote_post($config['token_url'], [
            'body' => [
                'client_id'     => get_option('avpvh_oauth_' . $provider . '_client_id'),
                'client_secret' => get_option('avpvh_oauth_' . $provider . '_client_secret'),
                'code'          => $code,
                'redirect_uri'  => $this->callback_url($provider),
                'grant_type'    => 'authorization_code',
            ],
        ]);

        if (is_wp_error($token)) {
            return null;
        }

        $data         = json_decode(wp_remote_retrieve_body($token), true);
        $access_token = $data['access_token'] ?? null;
        if (!$access_token) {
            return null;
        }

        $userinfo = wp_remote_get($config['userinfo_url'], [
            'headers' => ['Authorization' => 'Bearer ' . $access_token],
        ]);

        if (is_wp_error($userinfo)) {
            return null;
        }

        $info = json_decode(wp_remote_retrieve_body($userinfo), true);

        // Google → 'email'; Microsoft → 'mail' or 'userPrincipalName'
        $email = $info['email'] ?? $info['mail'] ?? $info['userPrincipalName'] ?? null;
        return $email ? strtolower(sanitize_email($email)) : null;
    }

    private function get_or_create_wp_user(string $email, object $member): ?\WP_User {
        if ($member->wp_user_id) {
            $user = get_user_by('id', (int) $member->wp_user_id);
            if ($user) {
                return $user;
            }
        }

        $user = get_user_by('email', $email);
        if (!$user) {
            $uid = wp_create_user(
                sanitize_user(strstr($email, '@', true)),
                wp_generate_password(64),
                $email
            );
            if (is_wp_error($uid)) {
                return null;
            }
            wp_update_user([
                'ID'           => $uid,
                'display_name' => avpvh_format_name($member),
            ]);
            AVPVH_DB::set_wp_user_id((int) $member->id, $uid);
            $user = get_user_by('id', $uid);
        } else {
            AVPVH_DB::set_wp_user_id((int) $member->id, $user->ID);
        }

        return $user ?: null;
    }

    private function callback_url(string $provider): string {
        return rest_url('avpvh/v1/oauth/' . $provider . '/callback');
    }

    public static function login_url(string $provider): string {
        return rest_url('avpvh/v1/oauth/' . $provider . '/start');
    }

    public static function configured_providers(): array {
        return array_filter(
            self::PROVIDERS,
            fn($key) => (bool) get_option('avpvh_oauth_' . $key . '_client_id'),
            ARRAY_FILTER_USE_KEY
        );
    }
}
