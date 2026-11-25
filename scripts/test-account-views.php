<?php
declare(strict_types=1);

/** Account archive visibility for fictional readers and authors. */
define('ABSPATH', __DIR__ . '/');
function add_action(mixed ...$args): void {}
function add_filter(mixed ...$args): void {}
function is_author(): bool { return $GLOBALS['author_request']; }
function is_user_logged_in(): bool { return $GLOBALS['user'] > 0; }
function get_current_user_id(): int { return $GLOBALS['user']; }
function current_user_can(string $cap): bool { return $GLOBALS['writer']; }
function avpvh_get_member_by_wp_user(int $id): ?object { return $GLOBALS['member']; }
function status_header(int $status): void { $GLOBALS['status'] = $status; }
function nocache_headers(): void { $GLOBALS['uncached'] = true; }
function remove_action(string $hook, string $callback): void { $GLOBALS['removed'][] = [$hook, $callback]; }
require_once dirname(__DIR__) . '/includes/class-account-privacy.php';
$privacy = new AVPVH_Account_Privacy();
foreach (['active' => false, 'inactive' => true, 'visitor' => true, 'unlinked' => true, 'anonymous' => true, 'writer' => false, 'other_page' => false] as $case => $blocked) {
    $GLOBALS['user'] = $case === 'anonymous' ? 0 : 1;
    $GLOBALS['member'] = in_array($case, ['active', 'inactive', 'visitor'], true) ? (object) ['status' => $case] : null;
    $GLOBALS['writer'] = $case === 'writer';
    $GLOBALS['author_request'] = $case !== 'other_page';
    $GLOBALS['removed'] = [];
    $GLOBALS['status'] = 200;
    $GLOBALS['uncached'] = false;
    $wp_query = new class {
        public bool $is_404 = false;
        public function set_404(): void { $this->is_404 = true; }
    };
    $privacy->hide_author_archive();
    $ok = $wp_query->is_404 === $blocked && ($blocked
        ? $GLOBALS['status'] === 404 && $GLOBALS['uncached'] && $GLOBALS['removed'] === [['template_redirect', 'redirect_canonical']]
        : $GLOBALS['status'] === 200 && !$GLOBALS['removed']);
    echo ($ok ? '[OK] ' : '[FAIL] ') . $case . ' archive visibility' . PHP_EOL;
    if (!$ok) { exit(1); }
}
