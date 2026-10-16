<?php
/**
 * "Inloggen met Google" and "Inloggen met Microsoft" on a tenant site.
 *
 * Authorization code flow with PKCE, state (also bound to the browser by
 * a cookie, against login CSRF) and nonce. Only an e-mail address the
 * provider vouches for counts: Google's email_verified, or a Microsoft
 * personal account (the consumers tenant). That address must belong to
 * exactly one directory account (AVPVH_Tenant_Directory); its groups
 * decide access as with Authelia (AVPVH_Tenant_SSO::role_for_groups()),
 * into the same WordPress user (avpvh_directory_subject).
 *
 * This route only gives reading access: an account whose groups make it an
 * administrator is sent to the Authelia login, which has two-factor
 * authentication. AVPVH_Tenant_SSO rechecks such a session against the
 * directory.
 */

defined('ABSPATH') || exit;

class AVPVH_Tenant_OAuth {

    /** The Microsoft tenant of personal accounts (hotmail.com, outlook.com, live.com). */
    const MICROSOFT_CONSUMERS = '9188040d-6c67-4c5b-b112-36a304b66dad';

    const PROVIDERS = [
        'google' => [
            'label'     => 'Google',
            'auth_url'  => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'issuers'   => ['https://accounts.google.com', 'accounts.google.com'],
        ],
        'microsoft' => [
            'label'     => 'Microsoft',
            'auth_url'  => 'https://login.microsoftonline.com/consumers/oauth2/v2.0/authorize',
            'token_url' => 'https://login.microsoftonline.com/consumers/oauth2/v2.0/token',
            'issuers'   => ['https://login.microsoftonline.com/' . self::MICROSOFT_CONSUMERS . '/v2.0'],
        ],
    ];

    const STATE_COOKIE = 'avpvh_oauth_state';
    const STATE_TTL    = 600;

    private AVPVH_Tenant_Config $config;
    private AVPVH_Tenant_SSO $sso;

    public function __construct(AVPVH_Tenant_Config $config, AVPVH_Tenant_SSO $sso) {
        $this->config = $config;
        $this->sso    = $sso;
        add_action('init', [$this, 'render_chooser'], 3);
        add_action('rest_api_init', [$this, 'register_routes']);
        add_filter('allowed_redirect_hosts', [$this, 'allow_provider_hosts']);
    }

    /**
     * The configured providers with their endpoints. The filter exists for
     * the end-to-end test, which points them at a fake provider.
     *
     * @return array<string, array{label: string, auth_url: string, token_url: string, issuers: string[], client_id: string, client_secret_file: string, client_secret: string}>
     */
    public function providers(): array {
        $providers = [];
        foreach ($this->config->oauth_clients() as $name => $client) {
            $providers[$name] = $client + self::PROVIDERS[$name];
        }
        return apply_filters('avpvh_tenant_oauth_providers', $providers);
    }

    public function allow_provider_hosts(array $hosts): array {
        foreach ($this->providers() as $provider) {
            $hosts[] = wp_parse_url($provider['auth_url'], PHP_URL_HOST);
        }
        return $hosts;
    }

    public function register_routes(): void {
        foreach (array_keys($this->providers()) as $name) {
            register_rest_route('avpvh/v1', '/tenant-oauth/' . $name . '/start', [
                'methods'             => 'GET',
                'callback'            => fn(\WP_REST_Request $request) => $this->start($name, $request),
                'permission_callback' => '__return_true',
            ]);
            register_rest_route('avpvh/v1', '/tenant-oauth/' . $name . '/callback', [
                'methods'             => 'GET',
                'callback'            => fn(\WP_REST_Request $request) => $this->callback($name, $request),
                'permission_callback' => '__return_true',
            ]);
        }
    }

    public static function start_url(string $provider, string $redirect_to = ''): string {
        $url = rest_url('avpvh/v1/tenant-oauth/' . $provider . '/start');
        return $redirect_to !== '' ? add_query_arg('redirect_to', rawurlencode($redirect_to), $url) : $url;
    }

    private static function callback_url(string $provider): string {
        return rest_url('avpvh/v1/tenant-oauth/' . $provider . '/callback');
    }

    private static function base64url(string $bytes): string {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public function start(string $name, \WP_REST_Request $request): void {
        $provider = $this->providers()[$name];
        $state    = self::base64url(random_bytes(32));
        $verifier = self::base64url(random_bytes(48));
        $nonce    = self::base64url(random_bytes(24));
        set_transient('avpvh_toauth_' . hash('sha256', $state), [
            'provider'    => $name,
            'verifier'    => $verifier,
            'nonce'       => $nonce,
            'redirect_to' => $this->sso->safe_target(sanitize_text_field(wp_unslash((string) $request->get_param('redirect_to')))),
        ], self::STATE_TTL);
        // SameSite=Lax: sent along on the provider's top-level redirect back.
        setcookie(self::STATE_COOKIE, $state, [
            'expires'  => time() + self::STATE_TTL,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $params = [
            'client_id'             => $provider['client_id'],
            'redirect_uri'          => self::callback_url($name),
            'response_type'         => 'code',
            'scope'                 => 'openid email profile',
            'state'                 => $state,
            'nonce'                 => $nonce,
            'code_challenge'        => self::base64url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            // Ask which account, so a browser signed in to someone else's
            // account does not log in as that person unasked.
            'prompt'                => 'select_account',
        ];
        wp_safe_redirect($provider['auth_url'] . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
        exit;
    }

    public function callback(string $name, \WP_REST_Request $request): void {
        $state  = sanitize_text_field((string) $request->get_param('state'));
        $cookie = sanitize_text_field(wp_unslash($_COOKIE[self::STATE_COOKIE] ?? ''));
        $key    = 'avpvh_toauth_' . hash('sha256', $state);
        $stored = $state !== '' ? get_transient($key) : false;
        delete_transient($key);
        setcookie(self::STATE_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);

        if (!is_array($stored) || ($stored['provider'] ?? '') !== $name || !hash_equals($state, $cookie)) {
            $this->fail('expired', $name);
        }
        $target = (string) $stored['redirect_to'];
        $code   = sanitize_text_field((string) $request->get_param('code'));
        $email  = $code !== '' ? $this->verified_email($name, $code, (string) $stored['verifier'], (string) $stored['nonce']) : null;
        if ($email === null) {
            $this->fail('failed', $name, '', '', $target);
        }

        $directory = AVPVH_Tenant_Directory::for_config($this->config);
        $identity  = $directory ? $directory->find_by_email($email) : ['status' => AVPVH_Tenant_Directory::ERROR];
        if ($identity['status'] === AVPVH_Tenant_Directory::ERROR) {
            $this->fail('unavailable', $name, '', '', $target);
        }
        // Unknown and ambiguous look the same to the visitor.
        $role = $identity['status'] === AVPVH_Tenant_Directory::OK
            ? AVPVH_Tenant_SSO::role_for_groups($identity['groups'], $this->config)
            : null;
        if ($role === null) {
            $this->fail('no_access', $name, $identity['uid'] ?? '', $identity['status'], $target);
        }
        if ($role !== 'subscriber') {
            $this->fail('admin', $name, $identity['uid'], '', $target);
        }

        $user_id = $this->sso->provision($identity, $role);
        if (is_wp_error($user_id)) {
            $this->fail('link', $name, $identity['uid'], $user_id->get_error_code(), $target);
        }
        // A role given by hand (say, editor) is not for this route either.
        if (user_can($user_id, 'edit_posts')) {
            $this->fail('admin', $name, $identity['uid'], '', $target);
        }
        $this->sso->log_in($user_id, $name);
        $this->log($name, $identity['uid'], 'subscriber');
        wp_safe_redirect($target);
        exit;
    }

    /**
     * Exchanges the code and returns the address from the ID token, or
     * null when the provider does not vouch for it.
     *
     * The ID token comes straight from the provider's token endpoint over
     * TLS, in exchange for our client secret and the PKCE verifier, so its
     * signature need not be checked (OpenID Connect Core 3.1.3.7); issuer,
     * audience, expiry and nonce are.
     */
    private function verified_email(string $name, string $code, string $verifier, string $nonce): ?string {
        $provider = $this->providers()[$name];
        $secret   = $provider['client_secret'];
        if ($provider['client_secret_file'] !== '') {
            $secret = is_readable($provider['client_secret_file'])
                ? trim((string) file_get_contents($provider['client_secret_file'])) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local Docker secret
                : '';
        }
        if ($secret === '') {
            return null;
        }
        $response = wp_remote_post($provider['token_url'], [
            'timeout' => 10,
            'body'    => [
                'client_id'     => $provider['client_id'],
                'client_secret' => $secret,
                'code'          => $code,
                'code_verifier' => $verifier,
                'redirect_uri'  => self::callback_url($name),
                'grant_type'    => 'authorization_code',
            ],
        ]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $token  = (string) (json_decode(wp_remote_retrieve_body($response), true)['id_token'] ?? '');
        $claims = self::claims($token);
        if (!$claims
            || !in_array($claims['iss'] ?? '', $provider['issuers'], true)
            || !in_array($provider['client_id'], (array) ($claims['aud'] ?? []), true)
            || (int) ($claims['exp'] ?? 0) < time()
            || !hash_equals($nonce, (string) ($claims['nonce'] ?? ''))) {
            return null;
        }
        $verified = $name === 'google'
            ? ($claims['email_verified'] ?? false) === true
            : ($claims['tid'] ?? '') === self::MICROSOFT_CONSUMERS;
        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        return $verified && is_email($email) ? $email : null;
    }

    /** The payload of a JWT, or null. */
    private static function claims(string $jwt): ?array {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $claims = $json !== false ? json_decode($json, true) : null;
        return is_array($claims) ? $claims : null;
    }

    /** Back to the login choice, with a message; logged without the address. */
    private function fail(string $reason, string $provider, string $uid = '', string $detail = '', string $target = ''): void {
        $this->log($provider, $uid, $detail !== '' ? $reason . '_' . $detail : $reason);
        $args = ['fout' => $reason];
        if ($target !== '') {
            $args['redirect_to'] = rawurlencode($target);
        }
        wp_safe_redirect(add_query_arg($args, home_url(AVPVH_Tenant_SSO::CHOOSER_PATH)));
        exit;
    }

    private function log(string $provider, string $uid, string $decision): void {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- login decisions, without e-mail addresses
        error_log(sprintf(
            '[avpvh-tenant] tenant=%s step=oauth-%s subject=%s decision=%s',
            $this->config->tenant_id(),
            $provider,
            $uid !== '' ? AVPVH_Tenant_Identity::log_subject($uid) : '-',
            sanitize_key($decision)
        ));
    }

    /** The login page: Google, Microsoft, or username and password. */
    public function render_chooser(): void {
        $path = wp_parse_url(sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '')), PHP_URL_PATH);
        if (!is_string($path) || trim($path, '/') !== trim(AVPVH_Tenant_SSO::CHOOSER_PATH, '/')) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- a page view; both values are validated
        $target = $this->sso->safe_target(sanitize_text_field(wp_unslash($_GET['redirect_to'] ?? '')));
        $reason = sanitize_key(wp_unslash($_GET['fout'] ?? ''));
        // phpcs:enable
        if (is_user_logged_in() && $reason === '') {
            wp_safe_redirect($target);
            exit;
        }
        $messages = [
            'expired'     => __('Het inloggen duurde te lang of is in een ander venster begonnen. Probeer het opnieuw.', 'avpvh-members'),
            'failed'      => __('Inloggen is niet gelukt: de provider bevestigde geen e-mailadres. Probeer het opnieuw.', 'avpvh-members'),
            'unavailable' => __('Inloggen is niet gelukt. Probeer het later opnieuw.', 'avpvh-members'),
            'no_access'   => __('Met dit e-mailadres is geen account met toegang tot deze site bekend. Gebruik het adres dat bij de beheerder bekend is, of neem contact op met de beheerder.', 'avpvh-members'),
            'admin'       => __('Dit account is van een beheerder. Log in met gebruikersnaam en wachtwoord, met de tweede stap.', 'avpvh-members'),
            'link'        => __('Je account kon niet aan deze site worden gekoppeld. Neem contact op met de beheerder.', 'avpvh-members'),
        ];
        $buttons = '';
        foreach ($this->providers() as $name => $provider) {
            $buttons .= '<a class="avpvh-login-button" href="' . esc_url(self::start_url($name, $target)) . '">'
                . esc_html(sprintf(
                    /* translators: %s: Google or Microsoft */
                    __('Inloggen met %s', 'avpvh-members'),
                    $provider['label']
                )) . '</a>';
        }
        $buttons .= '<a class="avpvh-login-button avpvh-login-plain" href="' . esc_url($this->sso->authelia_login_url($target)) . '">'
            . esc_html__('Inloggen met gebruikersnaam en wachtwoord', 'avpvh-members') . '</a>';

        nocache_headers();
        status_header(isset($messages[$reason]) ? 400 : 200);
        header('Content-Type: text/html; charset=utf-8');
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- every part is escaped above or below
        echo '<!doctype html><html lang="' . esc_attr(get_bloginfo('language')) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
            . '<title>' . esc_html__('Inloggen', 'avpvh-members') . ' – ' . esc_html(get_bloginfo('name')) . '</title>'
            . '<style>body{font-family:system-ui,sans-serif;background:#f4f4f4;color:#1e1e1e;margin:0;padding:16px}'
            . 'main{max-width:360px;margin:10vh auto;background:#fff;padding:24px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.15)}'
            . 'h1{font-size:1.4em;margin:0 0 16px}.avpvh-login-button{display:block;text-align:center;padding:12px;margin:8px 0;border:1px solid #1e1e1e;border-radius:4px;color:#1e1e1e;text-decoration:none;font-weight:600}'
            . '.avpvh-login-button:hover,.avpvh-login-button:focus{background:#1e1e1e;color:#fff}.avpvh-login-plain{font-weight:400}'
            . '.avpvh-login-error{background:#fcf0f1;border-left:4px solid #d63638;padding:8px 12px;margin:0 0 16px}'
            . '@media (prefers-color-scheme:dark){body{background:#1e1e1e;color:#f0f0f0}main{background:#2c2c2c}'
            . '.avpvh-login-button{border-color:#f0f0f0;color:#f0f0f0}.avpvh-login-button:hover,.avpvh-login-button:focus{background:#f0f0f0;color:#1e1e1e}'
            . '.avpvh-login-error{background:#3a2224}}</style></head><body><main>'
            . '<h1>' . esc_html(get_bloginfo('name')) . '</h1>'
            . (isset($messages[$reason]) ? '<p class="avpvh-login-error" role="alert">' . esc_html($messages[$reason]) . '</p>' : '')
            . $buttons
            . '<p><a href="' . esc_url(home_url('/')) . '">' . esc_html__('Naar de homepage', 'avpvh-members') . '</a></p>'
            . '</main></body></html>';
        // phpcs:enable
        exit;
    }
}
