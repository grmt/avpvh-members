<?php
declare(strict_types=1);

/**
 * Checks for the Paginarechten matrix (AVPVH_Roles page permissions).
 *
 * Run:
 *   php scripts/test-page-permissions.php
 */

define('ABSPATH', __DIR__ . '/');

$test_option = [];
$test_is_admin = false;
$test_groups = [];        // directory groups of the current user
$test_delegations = [];   // delegated roles of the current user

function __(string $text): string { return $text; }
function sanitize_key(string $key): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)); }
function get_option(string $name, mixed $default = false): mixed { return $GLOBALS['test_option'] ?: $default; }
function update_option(string $name, mixed $value, mixed $autoload = null): bool { $GLOBALS['test_option'] = $value; return true; }
function current_user_can(string $cap): bool { return $cap === 'manage_options' && $GLOBALS['test_is_admin']; }
function is_user_logged_in(): bool { return true; }
function get_current_user_id(): int { return 1; }
function avpvh_get_member_by_wp_user(int $id): object { return (object) ['id' => 10]; }
function current_time(string $type): string { return '2026-01-01 12:00:00'; }

// Delegations: the "role" argument of prepare() decides the answer.
$wpdb = new class {
    public string $prefix = 'wp_';
    public function prepare(string $sql, mixed ...$args): string { return (string) $args[0]; }
    public function get_var(string $role): int { return in_array($role, $GLOBALS['test_delegations'], true) ? 1 : 0; }
};

class AVPVH_DB {
    public static function get_member(int $id): object { return (object) ['id' => $id, 'user_id' => 'test.user']; }
}
class AVPVH_Directory {
    public static function cached_user_groups(string $uid): array { return $GLOBALS['test_groups']; }
}

require_once dirname(__DIR__) . '/includes/class-roles.php';

function check(string $label, bool $ok): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) {
        exit(1);
    }
}

function as_user(array $groups, bool $admin = false, array $delegations = []): void {
    $GLOBALS['test_groups'] = $groups;
    $GLOBALS['test_is_admin'] = $admin;
    $GLOBALS['test_delegations'] = $delegations;
}

// Defaults = the rules from before the matrix, plus IT everywhere.
as_user(['secretaris']);
check('secretaris: Ledenbeheer', AVPVH_Roles::can_manage_members());
check('secretaris: Activiteiten', AVPVH_Roles::can_manage_activities());
check('secretaris: Nieuwsbrief', AVPVH_Roles::can_send_newsletter());
check('secretaris: geen Loginpogingen', !AVPVH_Roles::can_view_login_attempts());
check('secretaris: geen Paginarechten', !AVPVH_Roles::can_manage_page_permissions());

as_user(['penningmeester']);
check('penningmeester: Ledenbeheer', AVPVH_Roles::can_manage_members());
check('penningmeester: geen Activiteiten', !AVPVH_Roles::can_manage_activities());

as_user(['it-beheer']);
check('IT-beheerder: alle pagina\'s', AVPVH_Roles::can_manage_members() && AVPVH_Roles::can_manage_activities()
    && AVPVH_Roles::can_send_newsletter() && AVPVH_Roles::can_view_login_attempts());
check('IT-beheerder: Paginarechten', AVPVH_Roles::can_manage_page_permissions());
check('IT-beheerder: geen Rollen & delegatie', !AVPVH_Roles::can_manage_roles());
check('IT-beheerder: mag wp-admin in', AVPVH_Roles::current_user_needs_wp_admin());

as_user(['voorzitter']);
check('voorzitter: Paginarechten', AVPVH_Roles::can_manage_page_permissions());
check('voorzitter: standaard geen Ledenbeheer', !AVPVH_Roles::can_manage_members());

as_user(['penningmeester'], false, ['secretaris']);
check('penningmeester met delegatie secretaris: Activiteiten', AVPVH_Roles::can_manage_activities());

as_user(['leden'], false, ['it_beheerder']);
check('IT-beheer is niet te delegeren', !AVPVH_Roles::can_manage_members() && !AVPVH_Roles::can_manage_page_permissions());

as_user(['leden']);
check('gewoon lid: geen wp-admin', !AVPVH_Roles::current_user_needs_wp_admin());
check('gewoon lid: niets', !AVPVH_Roles::can_manage_members() && !AVPVH_Roles::can_view_login_attempts()
    && !AVPVH_Roles::can_manage_page_permissions());

// Saving overrides the defaults; unknown roles and pages are dropped.
AVPVH_Roles::save_page_permissions([
    'members'        => ['voorzitter', 'hacker'],
    'login_attempts' => ['bestuur'],
    'bogus_page'     => ['secretaris'],
]);
check('onbekende rol/pagina niet opgeslagen', $test_option === [
    'members' => ['voorzitter'], 'activities' => [], 'newsletter' => [], 'login_attempts' => ['bestuur'],
]);
as_user(['voorzitter']);
check('voorzitter: Ledenbeheer na opslaan', AVPVH_Roles::can_manage_members());
check('voorzitter (impliciet bestuur): Loginpogingen', AVPVH_Roles::can_view_login_attempts());
as_user(['secretaris']);
check('secretaris: Ledenbeheer kwijt na opslaan', !AVPVH_Roles::can_manage_members());
as_user(['it-beheer']);
check('IT-beheerder: Paginarechten blijft (geen lockout)', AVPVH_Roles::can_manage_page_permissions());

as_user([], true);
check('WP-beheerder: altijd alles', AVPVH_Roles::can_manage_members() && AVPVH_Roles::can_manage_activities()
    && AVPVH_Roles::can_view_login_attempts() && AVPVH_Roles::can_manage_page_permissions());

echo "Page permission tests: OK\n";
