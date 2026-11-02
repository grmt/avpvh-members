<?php
/**
 * The checks shared by "Inloggen met Google/Microsoft" on the main site
 * (AVPVH_OAuth) and on tenant sites (AVPVH_Tenant_OAuth):
 *
 * - state, also bound to the browser by an HttpOnly cookie, so a callback
 *   URL started in someone else's browser is refused (login CSRF);
 * - PKCE, so an intercepted code is useless without our verifier;
 * - the address from the ID token, only when the provider vouches for it:
 *   Google's email_verified, or a Microsoft personal account (the consumers
 *   tenant), with issuer, audience, expiry and nonce checked.
 *
 * The ID token comes straight from the provider's token endpoint over TLS,
 * in exchange for our client secret and the PKCE verifier, so its signature
 * need not be checked (OpenID Connect Core 3.1.3.7).
 */

defined('ABSPATH') || exit;

class AVPVH_OIDC {

    /** The Microsoft tenant of personal accounts (hotmail.com, outlook.com, live.com). */
    const MICROSOFT_CONSUMERS = '9188040d-6c67-4c5b-b112-36a304b66dad';

    const ISSUERS = [
        'google'    => ['https://accounts.google.com', 'accounts.google.com'],
        'microsoft' => ['https://login.microsoftonline.com/' . self::MICROSOFT_CONSUMERS . '/v2.0'],
    ];

    const STATE_COOKIE = 'avpvh_oauth_state';
    const STATE_TTL    = 600;

    public static function base64url(string $bytes): string {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * A fresh state, PKCE verifier and nonce; the state also goes into the
     * browser's cookie. Keep verifier and nonce server-side (with the state)
     * and send authorize_params() to the provider.
     *
     * @return array{state: string, verifier: string, nonce: string}
     */
    public static function begin(): array {
        $flow = [
            'state'    => self::base64url(random_bytes(32)),
            'verifier' => self::base64url(random_bytes(48)),
            'nonce'    => self::base64url(random_bytes(24)),
        ];
        // SameSite=Lax: sent along on the provider's top-level redirect back.
        self::set_cookie($flow['state'], time() + self::STATE_TTL);
        return $flow;
    }

    /** @param array{state: string, verifier: string, nonce: string} $flow */
    public static function authorize_params(array $flow): array {
        return [
            'state'                 => $flow['state'],
            'nonce'                 => $flow['nonce'],
            'code_challenge'        => self::base64url(hash('sha256', $flow['verifier'], true)),
            'code_challenge_method' => 'S256',
        ];
    }

    /**
     * Whether the state that came back is the one this browser started
     * with. Clears the cookie either way: a state is used once.
     */
    public static function state_matches_browser(string $state): bool {
        $cookie = sanitize_text_field(wp_unslash($_COOKIE[self::STATE_COOKIE] ?? ''));
        self::set_cookie('', time() - 3600);
        return $state !== '' && $cookie !== '' && hash_equals($cookie, $state);
    }

    /** Transient key for a state: hashed, so the raw state isn't stored. */
    public static function state_key(string $prefix, string $state): string {
        return $prefix . hash('sha256', $state);
    }

    /** The payload of a JWT, or null. */
    public static function claims(string $jwt): ?array {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        $json   = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $claims = $json !== false ? json_decode($json, true) : null;
        return is_array($claims) ? $claims : null;
    }

    /**
     * The lower-cased address in an ID token the provider vouches for, or
     * null.
     *
     * @param array<string>|null $issuers default: self::ISSUERS[$provider]
     */
    public static function verified_email(string $provider, ?array $claims, string $client_id, string $nonce, ?array $issuers = null): ?string {
        $issuers ??= self::ISSUERS[$provider] ?? [];
        if (!$claims
            || !in_array($claims['iss'] ?? '', $issuers, true)
            || !in_array($client_id, (array) ($claims['aud'] ?? []), true)
            || (int) ($claims['exp'] ?? 0) < time()
            || $nonce === ''
            || !hash_equals($nonce, (string) ($claims['nonce'] ?? ''))) {
            return null;
        }
        $verified = $provider === 'google'
            ? ($claims['email_verified'] ?? false) === true
            : ($claims['tid'] ?? '') === self::MICROSOFT_CONSUMERS;
        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        return $verified && is_email($email) ? $email : null;
    }

    private static function set_cookie(string $value, int $expires): void {
        if (headers_sent()) {
            return;
        }
        setcookie(self::STATE_COOKIE, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
