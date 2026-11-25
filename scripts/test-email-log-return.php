<?php
declare(strict_types=1);

/** Render the actual detail template with hostile and legitimate referers. */
define('ABSPATH', __DIR__ . '/');
function __(string $value, string $domain = ''): string { return $value; }
function esc_html_e(string $value, string $domain = ''): void { echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function esc_url(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function home_url(string $path): string { return 'https://fixture.example.invalid' . $path; }
function admin_url(string $path): string { return home_url('/wp-admin/' . $path); }
function absint(mixed $value): int { return abs((int) $value); }
function wp_unslash(mixed $value): mixed { return $value; }
function sanitize_text_field(string $value): string { return $value; }
function sanitize_key(string $value): string { return $value; }
function wp_get_referer(): string|false { return $GLOBALS['referer']; }
class AVPVH_Roles {
    public static function can_view_email_log(): bool { return true; }
}
class AVPVH_Email_Log {
    public static function available(): bool { return true; }
    public static function get(int $id): ?object { return null; }
}
require_once dirname(__DIR__) . '/tests/fixtures/access-session.php';

$base = admin_url('admin.php?page=avpvh-email-log');
$failures = 0;
foreach ([
    false => $base,
    'https://evil.example.invalid/?page=avpvh-email-log' => $base,
    'https://accounts.google.com/?page=avpvh-email-log' => $base,
    home_url('/other/?page=avpvh-email-log') => $base,
    admin_url('admin.php?page=another-page') => $base,
    $base . '&paged=2' => $base . '&paged=2',
] as $referer => $expected) {
    $GLOBALS['referer'] = $referer === 0 ? false : (string) $referer;
    $_GET = ['view' => '123'];
    ob_start();
    include dirname(__DIR__) . '/admin/email-log.php';
    $html = ob_get_clean();
    $ok = str_contains($html, 'href="' . esc_url($expected) . '"');
    echo ($ok ? '[OK] ' : '[FAIL] ') . 'email-log return target ' . ($referer ?: '(absent)') . PHP_EOL;
    $failures += $ok ? 0 : 1;
}
exit($failures ? 1 : 0);
