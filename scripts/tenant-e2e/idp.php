<?php
// Fake Google and Microsoft for the tenant e2e test, fictitious addresses
// only. /<provider>/authorize?…&account=<address>[&unverified=1] sends the
// browser straight back with a code; /<provider>/token checks the client
// secret, redirect URI and PKCE verifier and answers with an ID token
// (unsigned: the plugin takes it from the token endpoint over TLS).
$b64 = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$consumers = '9188040d-6c67-4c5b-b112-36a304b66dad';
// Only back to the test site's registered callback, as a real provider.
$callbacks = [
    'google'    => 'http://localhost:8088/wp-json/avpvh/v1/tenant-oauth/google/callback',
    'microsoft' => 'http://localhost:8088/wp-json/avpvh/v1/tenant-oauth/microsoft/callback',
];
if (preg_match('#^/(google|microsoft)/authorize$#', $path, $m)) {
    $redirect = $callbacks[$m[1]];
    if (($_GET['redirect_uri'] ?? '') !== $redirect) {
        http_response_code(400);
        exit;
    }
    $code = $b64(json_encode([
        'provider'  => $m[1],
        'email'     => $_GET['account'] ?? '',
        'verified'  => !isset($_GET['unverified']),
        'nonce'     => $_GET['nonce'] ?? '',
        'challenge' => $_GET['code_challenge'] ?? '',
        'client'    => $_GET['client_id'] ?? '',
        'redirect'  => $redirect,
    ]));
    header('Location: ' . $redirect . '?' . http_build_query(['code' => $code, 'state' => $_GET['state'] ?? '']), true, 302);
    exit;
}
if (preg_match('#^/(google|microsoft)/token$#', $path, $m)) {
    $c = json_decode((string) base64_decode(strtr($_POST['code'] ?? '', '-_', '+/')), true);
    if (!is_array($c) || $c['provider'] !== $m[1] || ($_POST['client_secret'] ?? '') !== 'test-secret-' . $m[1]
        || ($_POST['redirect_uri'] ?? '') !== $c['redirect']
        || $b64(hash('sha256', $_POST['code_verifier'] ?? '', true)) !== $c['challenge']) {
        http_response_code(400);
        echo '{"error":"invalid_grant"}';
        exit;
    }
    $claims = ['aud' => $c['client'], 'exp' => time() + 300, 'nonce' => $c['nonce'], 'email' => $c['email'], 'name' => 'Test'];
    if ($m[1] === 'google') {
        $claims += ['iss' => 'https://accounts.google.com', 'email_verified' => $c['verified']];
    } else {
        // An unverified Microsoft address: a work or school account.
        $tenant = $c['verified'] ? $consumers : 'f0000000-0000-0000-0000-000000000000';
        $claims += ['iss' => "https://login.microsoftonline.com/$consumers/v2.0", 'tid' => $tenant];
    }
    header('Content-Type: application/json');
    echo json_encode(['access_token' => 'test', 'id_token' => $b64('{"alg":"none"}') . '.' . $b64(json_encode($claims)) . '.']);
    exit;
}
http_response_code(404);
