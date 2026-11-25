<?php
/** WordPress session/redirect stand-ins; access decisions use the production class. */
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1): mixed { return parse_url($url, $component); }
}
if (!function_exists('wp_validate_redirect')) {
    function wp_validate_redirect(string $url, string $fallback): string {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        return ($scheme === null || in_array($scheme, ['http', 'https'], true)) ? $url : $fallback;
    }
}
function wp_get_session_token(): string { return 'fixture-session'; }
class WP_Session_Tokens {
    public static function get_instance(int $id): self { return new self(); }
    public function get(string $token): array {
        return ['avpvh_auth_method' => 'proxy', 'avpvh_2fa' => $GLOBALS['two_factor'] ?? true];
    }
}
require_once dirname(__DIR__, 2) . '/includes/class-access.php';
