<?php
/**
 * Asks Authelia, server to server, who the visitor is.
 *
 * The visitor's Authelia session cookie is replayed against Authelia's
 * auth-request endpoint for the identity probe URL (an Authelia one_factor
 * rule without group restriction). Authelia answers 200 with Remote-User,
 * Remote-Groups, Remote-Email and Remote-Name, or 401/403. Nothing is
 * taken from headers the client sent, so a spoofed Remote-User or
 * Remote-Groups header has no effect, and the site needs no LDAP access.
 */

defined('ABSPATH') || exit;

class AVPVH_Tenant_Identity {

    const OK        = 'ok';
    const ANONYMOUS = 'anonymous';
    const ERROR     = 'error';

    /**
     * @return array{status: string, uid?: string, groups?: string[], email?: string, name?: string}
     */
    public static function probe(AVPVH_Tenant_Config $config): array {
        $cookie_name = (string) $config->get('authelia_session_cookie');
        $session = sanitize_text_field(wp_unslash($_COOKIE[$cookie_name] ?? ''));
        if ($session === '') {
            return ['status' => self::ANONYMOUS];
        }

        $response = wp_remote_get((string) $config->get('authelia_authz_url'), [
            'timeout'     => 5,
            'redirection' => 0,
            'headers'     => [
                'X-Original-Method' => 'GET',
                'X-Original-URL'    => $config->identity_probe_url(),
                'X-Forwarded-For'   => sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')),
                'Cookie'            => $cookie_name . '=' . $session,
            ],
        ]);
        return self::interpret($response);
    }

    /** Turns an auth-request response (or WP_Error) into an identity. */
    public static function interpret(mixed $response): array {
        if (is_wp_error($response)) {
            return ['status' => self::ERROR];
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        // 302 is what Authelia answers when it would redirect to its portal.
        if (in_array($code, [401, 302, 403], true)) {
            return ['status' => self::ANONYMOUS];
        }
        if ($code !== 200) {
            return ['status' => self::ERROR];
        }

        $uid = strtolower(trim((string) wp_remote_retrieve_header($response, 'remote-user')));
        if (!preg_match('/^[a-z0-9][a-z0-9._@-]{0,99}$/', $uid)) {
            // 200 without a user means the probe URL is not protected by a
            // one_factor rule: a configuration error, never "logged in".
            return ['status' => self::ERROR];
        }
        $groups = array_values(array_filter(array_map(
            static fn(string $group): string => strtolower(trim($group)),
            explode(',', (string) wp_remote_retrieve_header($response, 'remote-groups'))
        )));
        $email = sanitize_email((string) wp_remote_retrieve_header($response, 'remote-email'));
        $name  = sanitize_text_field((string) wp_remote_retrieve_header($response, 'remote-name'));

        return [
            'status' => self::OK,
            'uid'    => $uid,
            'groups' => $groups,
            'email'  => $email,
            'name'   => $name,
        ];
    }

    /** A stable, non-identifying label for logs. */
    public static function log_subject(string $uid): string {
        return substr(hash('sha256', $uid), 0, 12);
    }
}
