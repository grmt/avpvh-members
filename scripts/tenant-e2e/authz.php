<?php
// Fake Authelia auth-request endpoint with fictitious identities.
$ids = [
    'member'   => ['test.lid', 'vp4042-leden', 'lid@example.invalid', 'Test Lid'],
    'vveadmin' => ['test.vve', 'rsp-vve-admins', 'vve@example.invalid', 'Test VvE-beheerder'],
    'outsider' => ['test.buiten', 'boek', 'buiten@example.invalid', 'Test Buitenstaander'],
];
$token = $_COOKIE['test_session'] ?? '';
file_put_contents('php://stderr', 'authz ' . ($_SERVER['HTTP_X_ORIGINAL_URL'] ?? '-') . ' token=' . $token . "\n");
if (($_SERVER['HTTP_X_ORIGINAL_URL'] ?? '') !== 'https://localhost/avpvh-sso/' || !isset($ids[$token])) {
    http_response_code(401);
    exit;
}
[$user, $groups, $mail, $name] = $ids[$token];
header("Remote-User: $user");
header("Remote-Groups: $groups");
header("Remote-Email: $mail");
header("Remote-Name: $name");
