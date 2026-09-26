<?php
defined('ABSPATH') || exit;

/**
 * Issues a short-lived, signed cookie that OpenResty can validate before
 * serving private WordPress uploads. This bridges WordPress-only OAuth
 * sessions to the web server without exposing files or proxying them via PHP.
 */
class AVPVH_Media_Token {

    private const COOKIE_NAME = 'avpvh_media_token';
    private const ISSUER = 'avpvh-members';
    private const AUDIENCE = 'private-media';
    private const LIFETIME = 8 * HOUR_IN_SECONDS;
    private const SECRET_FILE = '/run/secrets/avpvh_jwt_secret';

    public function __construct() {
        // Covers wp_signon(), Google/Microsoft OAuth, and proxy auto-login:
        // all three ultimately call wp_set_auth_cookie().
        add_action('set_logged_in_cookie', [$this, 'on_logged_in_cookie'], 10, 6);
        add_action('clear_auth_cookie', [$this, 'clear_cookie']);
        add_action('init', [$this, 'enforce_cookie'], 20);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_session_watchdog']);
        add_action('wp_ajax_avpvh_media_session_status', [$this, 'ajax_session_status']);
        add_action('wp_ajax_nopriv_avpvh_media_session_status', [$this, 'ajax_session_status']);
    }

    public function on_logged_in_cookie(
        string $logged_in_cookie,
        int $expire,
        int $expiration,
        int $user_id,
        string $scheme,
        string $token
    ): void {
        unset($logged_in_cookie, $expiration, $scheme, $token);
        $this->issue_cookie($user_id, $expire === 0);
    }

    /**
     * Makes the JWT a hard session boundary. It is deliberately not renewed:
     * after eight hours the WordPress session is logged out as well and the
     * member must authenticate again.
     */
    public function enforce_cookie(): void {
        if (!is_user_logged_in()) {
            return;
        }

        $user_id = get_current_user_id();
        if (!$this->user_may_access_private_media($user_id)) {
            wp_logout();
            return;
        }

        $current = isset($_COOKIE[self::COOKIE_NAME])
            ? sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE_NAME]))
            : '';

        if ($this->token_expiration($current, $user_id) === null) {
            wp_logout();
        }
    }

    /**
     * A rendered page cannot notice an HttpOnly cookie expiring by itself.
     * The watchdog checks on page load, focus/pageshow, and while visible so
     * a sleeping tab closes as soon as it becomes active again.
     */
    public function enqueue_session_watchdog(): void {
        if (!is_user_logged_in()) {
            return;
        }

        wp_enqueue_script(
            'avpvh-media-session',
            plugin_dir_url(dirname(__FILE__)) . 'assets/media-session.js',
            [],
            avpvh_asset_version('assets/media-session.js'),
            ['strategy' => 'defer', 'in_footer' => true]
        );
    }

    public function ajax_session_status(): void {
        nocache_headers();

        if (!is_user_logged_in()) {
            wp_send_json_error(['code' => 'session_expired'], 401);
        }

        $jwt = isset($_COOKIE[self::COOKIE_NAME])
            ? sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE_NAME]))
            : '';
        $expires = $this->token_expiration($jwt, get_current_user_id());
        if ($expires === null) {
            wp_logout();
            wp_send_json_error(['code' => 'session_expired'], 401);
        }

        wp_send_json_success(['expires' => $expires]);
    }

    public function clear_cookie(): void {
        setcookie(self::COOKIE_NAME, '', [
            'expires'  => time() - YEAR_IN_SECONDS,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[self::COOKIE_NAME]);
    }

    /**
     * Builds an HS256 JWT compatible with lua/resty.openssl.hmac.
     */
    public static function build_token(int $user_id, int $issued_at, int $expires): ?string {
        $secret = self::secret();
        if ($secret === null || $user_id <= 0 || $expires <= $issued_at) {
            return null;
        }

        $header = self::base64url_encode((string) wp_json_encode([
            'alg' => 'HS256',
            'typ' => 'JWT',
        ]));
        $payload = self::base64url_encode((string) wp_json_encode([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'sub' => $user_id,
            'iat' => $issued_at,
            'exp' => $expires,
        ]));
        $signing_input = $header . '.' . $payload;
        $signature = hash_hmac('sha256', $signing_input, $secret, true);

        return $signing_input . '.' . self::base64url_encode($signature);
    }

    private function issue_cookie(int $user_id, bool $session_cookie): void {
        if (!$this->user_may_access_private_media($user_id)) {
            $this->clear_cookie();
            return;
        }

        $now = time();
        $expires = $now + self::LIFETIME;
        $jwt = self::build_token($user_id, $now, $expires);
        if ($jwt === null) {
            return;
        }

        setcookie(self::COOKIE_NAME, $jwt, [
            'expires'  => $session_cookie ? 0 : $expires,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE_NAME] = $jwt;
    }

    private function user_may_access_private_media(int $user_id): bool {
        $user = get_userdata($user_id);
        if ($user instanceof WP_User && user_can($user, 'manage_options')) {
            return true;
        }

        $member = avpvh_get_member_by_wp_user($user_id);
        return $member && $member->status === 'active';
    }

    private function token_expiration(string $jwt, int $user_id): ?int {
        $secret = self::secret();
        if ($secret === null || $jwt === '') {
            return null;
        }

        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }

        [$header_part, $payload_part, $signature_part] = $parts;
        $provided_signature = self::base64url_decode($signature_part);
        if ($provided_signature === null) {
            return null;
        }

        $expected_signature = hash_hmac('sha256', $header_part . '.' . $payload_part, $secret, true);
        if (!hash_equals($expected_signature, $provided_signature)) {
            return null;
        }

        $header_json = self::base64url_decode($header_part);
        $header = $header_json === null ? null : json_decode($header_json, true);
        $payload_json = self::base64url_decode($payload_part);
        $payload = $payload_json === null ? null : json_decode($payload_json, true);
        if (!is_array($header) || !is_array($payload)) {
            return null;
        }

        $issued_at = (int) ($payload['iat'] ?? 0);
        $expires = (int) ($payload['exp'] ?? 0);
        $now = time();

        $valid = ($header['alg'] ?? '') === 'HS256'
            && ($header['typ'] ?? '') === 'JWT'
            && ($payload['iss'] ?? '') === self::ISSUER
            && ($payload['aud'] ?? '') === self::AUDIENCE
            && (int) ($payload['sub'] ?? 0) === $user_id
            && $issued_at <= $now + 60
            && $expires > $now
            && $expires > $issued_at
            && $expires - $issued_at <= self::LIFETIME + 60;

        return $valid ? $expires : null;
    }

    private static function secret(): ?string {
        if (defined('AVPVH_JWT_SECRET') && is_string(AVPVH_JWT_SECRET)) {
            $secret = trim(AVPVH_JWT_SECRET);
            return strlen($secret) >= 32 ? $secret : null;
        }

        $path = defined('AVPVH_JWT_SECRET_FILE')
            ? AVPVH_JWT_SECRET_FILE
            : self::SECRET_FILE;
        $secret = is_readable($path) ? trim((string) file_get_contents($path)) : '';

        return strlen($secret) >= 32 ? $secret : null;
    }

    private static function base64url_encode(string $value): string {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64url_decode(string $value): ?string {
        if ($value === '' || preg_match('/[^A-Za-z0-9_-]/', $value)) {
            return null;
        }

        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
