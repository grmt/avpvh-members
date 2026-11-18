<?php
declare(strict_types=1);

/**
 * Ad-hoc checks for AVPVH_Email_Log: which links are hidden, which bodies
 * are not shown at all, and reading FluentSMTP's `to` column. All addresses
 * and tokens below are made up.
 *
 * Run:
 *   php scripts/test-email-log.php
 */

define('ABSPATH', __DIR__ . '/');

function is_serialized($data): bool { return is_string($data) && preg_match('/^(a|s|i|b|d|O|N):/', $data) === 1; }

require_once dirname(__DIR__) . '/includes/class-email-log.php';

function check(string $label, mixed $actual, mixed $expected): void {
    echo ($actual === $expected ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if ($actual !== $expected) {
        echo '  expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL;
        exit(1);
    }
}

$secret = [AVPVH_Email_Log::class, 'is_secret_url'];

// Links that act as a key.
check('order confirmation token', $secret('https://example.test/boek/?book_token=abc123'), true);
check('t-shirt token', $secret('https://example.test/tshirt/?tshirt_token=abc123'), true);
check('photo share confirmation', $secret('https://example.test/foto-delen/?confirm_photo_share=abc'), true);
check('admin-post share with token, &amp; encoded', $secret('https://example.test/wp-admin/admin-post.php?action=x&amp;share=1&amp;token=abc'), true);
check('WordPress login key', $secret('https://example.test/wp-login.php?action=rp&rm_token=a&rm_key=b'), true);
check('personal Google Drive folder', $secret('https://drive.google.com/drive/folders/AbCdEfGhIjKlMnOpQrSt'), true);

// Links that don't.
check('profile page with member_id', $secret('https://example.test/leden/beheer/member-profile/?member_id=45'), false);
check('login page with redirect_to', $secret('https://example.test/avpvh-login/?redirect_to=%2Fleden%2F'), false);
check('payment request', $secret('https://www.ing.nl/payreq/m/?trxid=12345'), false);
check('plain page', $secret('https://example.test/leden/informatiefjes/'), false);

$html = '<p>Bevestig: <a href="https://example.test/boek/?book_token=abc123">https://example.test/boek/?book_token=abc123</a>'
    . ' of bekijk <a href="https://example.test/leden/">je profiel</a>.</p>';
check(
    'redact replaces the secret link in href and text, keeps the rest',
    AVPVH_Email_Log::redact($html),
    '<p>Bevestig: <a href="[link verborgen]">[link verborgen]</a> of bekijk <a href="https://example.test/leden/">je profiel</a>.</p>'
);
check('redact on plain text', AVPVH_Email_Log::redact("Map: https://drive.google.com/drive/folders/Xyz\nKlaar."), "Map: [link verborgen]\nKlaar.");

$hidden = static fn(string $subject): bool => AVPVH_Email_Log::body_hidden((object) ['subject' => $subject]);
check('password mail: body hidden', $hidden('AV Philips van Horne — Wachtwoord aanmaken'), true);
check('login details changed: body hidden', $hidden('AV Philips van Horne — inloggegevens gewijzigd'), true);
check('payment reminder: body shown', $hidden('Openstaande betaling — Drankrekening'), false);
check('credit settled: body shown', $hidden('[AV-PvH] Tegoed verrekend'), false);

check(
    'recipients from the serialized column',
    AVPVH_Email_Log::recipients(serialize([['email' => 'a@example.test'], ['name' => 'B', 'email' => 'b@example.test']])),
    'a@example.test, b@example.test'
);
check('recipients: plain text stays as is', AVPVH_Email_Log::recipients('c@example.test'), 'c@example.test');
check('recipients: an object in the column is not instantiated', AVPVH_Email_Log::recipients('O:8:"stdClass":0:{}'), 'O:8:"stdClass":0:{}');
check('recipients: null', AVPVH_Email_Log::recipients(null), '');

echo "E-mail log tests: OK\n";
