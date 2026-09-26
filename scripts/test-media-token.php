<?php
declare(strict_types=1);

/**
 * Ad-hoc checks for AVPVH_Media_Token signing/verification.
 *
 * Run:
 *   php scripts/test-media-token.php            # PHP round-trip checks
 *   php scripts/test-media-token.php --emit     # print test tokens (one per
 *       line: name token) to verify against the OpenResty Lua module
 */

define('ABSPATH', __DIR__);
define('HOUR_IN_SECONDS', 3600);
define('YEAR_IN_SECONDS', 31536000);
function add_action(...$a): void {}
function wp_json_encode($data) { return json_encode($data); }

putenv('AVPVH_JWT_SECRET=' . str_repeat('0123456789abcdef', 4));
require __DIR__ . '/../includes/class-media-token.php';

$now    = time();
$valid  = AVPVH_Media_Token::sign(['sub' => 42, 'iat' => $now, 'exp' => $now + 3600]);
$expired = AVPVH_Media_Token::sign(['sub' => 42, 'iat' => $now - 7200, 'exp' => $now - 3600]);
[$h, $p, $s] = explode('.', $valid);
$forged_payload = rtrim(strtr(base64_encode(json_encode(['sub' => 1, 'iat' => $now, 'exp' => $now + 3600])), '+/', '-_'), '=');
$tampered = "$h.$forged_payload.$s";
putenv('AVPVH_JWT_SECRET=' . str_repeat('fedcba9876543210', 4));
$other_secret = AVPVH_Media_Token::sign(['sub' => 42, 'iat' => $now, 'exp' => $now + 3600]);
putenv('AVPVH_JWT_SECRET=' . str_repeat('0123456789abcdef', 4));

$cases = [
    'valid'        => [$valid, true],
    'expired'      => [$expired, false],
    'tampered'     => [$tampered, false],
    'other_secret' => [$other_secret, false],
    'garbage'      => ['not.a.token', false],
];

if (in_array('--emit', $argv, true)) {
    foreach ($cases as $name => [$token, $ok]) {
        echo "$name " . ($ok ? 'ok' : 'nil') . " $token\n";
    }
    exit;
}

foreach ($cases as $name => [$token, $ok]) {
    $claims = AVPVH_Media_Token::verify($token);
    $pass   = ($claims !== null) === $ok && (!$ok || $claims['sub'] === 42);
    echo ($pass ? '[OK] ' : '[FAIL] ') . $name . PHP_EOL;
}
putenv('AVPVH_JWT_SECRET=short');
echo (AVPVH_Media_Token::sign(['sub' => 1, 'iat' => 1, 'exp' => 2]) === null ? '[OK] ' : '[FAIL] ') . 'short secret refused' . PHP_EOL;

echo PHP_EOL . 'Done.' . PHP_EOL;
