<?php
defined('ABSPATH') || exit;

/**
 * Issues a signed media token (HS256 JWT) to logged-in members so OpenResty
 * can serve uploads/private/ without Authelia.
 *
 * Authelia only has a session for members who logged in with their
 * Authelia password; Google/Microsoft logins never get one, so private
 * images were broken for them. OpenResty validates this cookie instead
 * (see validate in /etc/nginx/lua/avpvh_media_token.lua), with the same
 * secret shared via the avpvh_jwt_secret Docker secret — the container
 * entrypoint exports it as AVPVH_JWT_SECRET.
 *
 * The token is issued on every login (all methods end in
 * wp_set_auth_cookie()), renewed on page views once half its lifetime has
 * passed (so sessions that predate this class also get one), and cleared on
 * logout.
 */
class AVPVH_Media_Token {

    const COOKIE = 'avpvh_media_token';

    // Short lifetime so a token doesn't outlive the WP session by much: the
    // idle timeout (AVPVH_Access) logs out after 24h without activity, and
    // any page view renews the token well before this runs out.
    const TTL = 8 * HOUR_IN_SECONDS;

    public function __construct() {
        add_action('set_logged_in_cookie', [$this, 'on_logged_in_cookie'], 10, 4);
        add_action('clear_auth_cookie',    [$this, 'clear']);
        // After AVPVH_Access::enforce_session_idle_timeout() (priority 1), so
        // an idle-expired session is logged out before it could be renewed.
        add_action('init',                 [$this, 'maybe_renew'], 20);
    }

    public function on_logged_in_cookie(string $cookie, int $expire, int $expiration, int $user_id): void {
        $this->issue($user_id);
    }

    public function maybe_renew(): void {
        if (!is_user_logged_in() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
            return;
        }

        $user_id = get_current_user_id();
        $claims  = self::verify(sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE] ?? '')));

        if (
            $claims === null
            || (int) $claims['sub'] !== $user_id
            || $claims['exp'] - time() < self::TTL / 2
        ) {
            $this->issue($user_id);
        }
    }

    public function clear(): void {
        $this->set_cookie('', time() - YEAR_IN_SECONDS);
        unset($_COOKIE[self::COOKIE]);
    }

    private function issue(int $user_id): void {
        $now   = time();
        $token = self::sign(['sub' => $user_id, 'iat' => $now, 'exp' => $now + self::TTL]);
        if ($token === null) {
            return;
        }
        $this->set_cookie($token, $now + self::TTL);
        $_COOKIE[self::COOKIE] = $token;
    }

    private function set_cookie(string $value, int $expires): void {
        if (headers_sent()) {
            return;
        }
        setcookie(self::COOKIE, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function secret(): ?string {
        $secret = trim((string) getenv('AVPVH_JWT_SECRET'));
        return strlen($secret) >= 32 ? $secret : null;
    }

    /**
     * @param array{sub:int,iat:int,exp:int} $claims
     */
    public static function sign(array $claims): ?string {
        $secret = self::secret();
        if ($secret === null) {
            return null;
        }
        $unsigned = self::b64url((string) wp_json_encode(['alg' => 'HS256', 'typ' => 'JWT']))
            . '.' . self::b64url((string) wp_json_encode($claims));
        return $unsigned . '.' . self::b64url(hash_hmac('sha256', $unsigned, $secret, true));
    }

    /**
     * Returns the claims of a validly signed, unexpired token, else null.
     *
     * @return array{sub:int,exp:int}|null
     */
    public static function verify(string $token): ?array {
        $secret = self::secret();
        $parts  = explode('.', $token);
        if ($secret === null || count($parts) !== 3) {
            return null;
        }
        [$header, $payload, $sig] = $parts;

        $expected = self::b64url(hash_hmac('sha256', "$header.$payload", $secret, true));
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);
        if (!is_array($claims) || !isset($claims['sub'], $claims['exp']) || (int) $claims['exp'] <= time()) {
            return null;
        }
        return ['sub' => (int) $claims['sub'], 'exp' => (int) $claims['exp']];
    }

    private static function b64url(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
