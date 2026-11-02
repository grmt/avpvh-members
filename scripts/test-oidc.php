<?php
declare(strict_types=1);

/**
 * Ad-hoc checks for AVPVH_OIDC, the OAuth/OIDC checks shared by the main
 * site and tenant sites: which ID tokens give an address, and the state
 * cookie that binds a login to the browser that started it.
 *
 * Run:
 *   php scripts/test-oidc.php
 */

define('ABSPATH', __DIR__ . '/');

function is_email(string $email): bool {
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}
function is_ssl(): bool {
    return true;
}
function sanitize_text_field(string $text): string {
    return trim($text);
}
function wp_unslash(string $text): string {
    return $text;
}

require_once dirname(__DIR__) . '/includes/class-avpvh-oidc.php';

function check(string $label, mixed $actual, mixed $expected): void {
    echo ($actual === $expected ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if ($actual !== $expected) {
        echo '  expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL;
        exit(1);
    }
}

$google = [
    'iss' => 'https://accounts.google.com', 'aud' => 'client-1', 'exp' => time() + 300,
    'nonce' => 'n1', 'email' => 'Lid@Example.org', 'email_verified' => true,
];
$verify = fn(string $p, ?array $c, string $nonce = 'n1') => AVPVH_OIDC::verified_email($p, $c, 'client-1', $nonce);

check('google, verified: lower-cased address', $verify('google', $google), 'lid@example.org');
check('google, email_verified false: refused', $verify('google', ['email_verified' => false] + $google), null);
check('google, email_verified missing: refused', $verify('google', array_diff_key($google, ['email_verified' => 1])), null);
check('google, email_verified "true" string: refused', $verify('google', ['email_verified' => 'true'] + $google), null);
check('other audience: refused', $verify('google', ['aud' => 'client-2'] + $google), null);
check('audience as list: accepted', $verify('google', ['aud' => ['client-2', 'client-1']] + $google), 'lid@example.org');
check('other issuer: refused', $verify('google', ['iss' => 'https://evil.example'] + $google), null);
check('expired: refused', $verify('google', ['exp' => time() - 1] + $google), null);
check('wrong nonce: refused', $verify('google', $google, 'n2'), null);
check('empty nonce on both sides: refused', $verify('google', ['nonce' => ''] + $google, ''), null);
check('no claims: refused', $verify('google', null), null);

$microsoft = [
    'iss' => 'https://login.microsoftonline.com/' . AVPVH_OIDC::MICROSOFT_CONSUMERS . '/v2.0', 'aud' => 'client-1',
    'exp' => time() + 300, 'nonce' => 'n1', 'email' => 'lid@example.org', 'tid' => AVPVH_OIDC::MICROSOFT_CONSUMERS,
];
check('microsoft personal account: accepted', $verify('microsoft', $microsoft), 'lid@example.org');
check('microsoft work/school tenant: refused', $verify('microsoft', ['tid' => '11111111-2222-3333-4444-555555555555'] + $microsoft), null);
check('microsoft, no email claim: refused', $verify('microsoft', array_diff_key($microsoft, ['email' => 1])), null);

$jwt = 'h.' . AVPVH_OIDC::base64url((string) json_encode($google)) . '.s';
check('claims read from a JWT payload', AVPVH_OIDC::claims($jwt)['email'] ?? null, 'Lid@Example.org');
check('not a JWT: no claims', AVPVH_OIDC::claims('abc'), null);

$flow = AVPVH_OIDC::begin();
$params = AVPVH_OIDC::authorize_params($flow);
check('PKCE challenge is S256 of the verifier', $params['code_challenge'], AVPVH_OIDC::base64url(hash('sha256', $flow['verifier'], true)));
check('state and nonce sent along', [$params['state'], $params['nonce']], [$flow['state'], $flow['nonce']]);

$_COOKIE[AVPVH_OIDC::STATE_COOKIE] = $flow['state'];
check('state from this browser: accepted', AVPVH_OIDC::state_matches_browser($flow['state']), true);
$_COOKIE[AVPVH_OIDC::STATE_COOKIE] = 'someone-else';
check('state started in another browser: refused', AVPVH_OIDC::state_matches_browser($flow['state']), false);
unset($_COOKIE[AVPVH_OIDC::STATE_COOKIE]);
check('no state cookie: refused', AVPVH_OIDC::state_matches_browser($flow['state']), false);
check('empty state: refused', AVPVH_OIDC::state_matches_browser(''), false);

echo "OIDC tests: OK\n";
