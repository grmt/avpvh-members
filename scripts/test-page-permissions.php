<?php
declare(strict_types=1);

define('ABSPATH', __DIR__);

$saved_options = [];

function get_option(string $key, mixed $default = false): mixed {
    global $saved_options;
    return $saved_options[$key] ?? $default;
}

function update_option(string $key, mixed $value, bool $autoload = true): bool {
    global $saved_options;
    $saved_options[$key] = $value;
    return true;
}

function sanitize_key(string $key): string {
    return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', $key));
}

function wp_unslash(mixed $value): mixed {
    return $value;
}

function apply_filters(string $hook, mixed $value): mixed {
    return $value;
}

require_once dirname(__DIR__) . '/includes/class-roles.php';

function assert_page_permission(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
    echo "[OK] {$message}\n";
}

$pages = AVPVH_Roles::get_assignable_pages();
$defaults = AVPVH_Roles::get_default_page_permissions();

assert_page_permission(!isset($pages['authentication']), 'Authenticatie is niet toewijsbaar');
assert_page_permission(isset($pages['login_attempts']), 'Loginpogingen zijn toewijsbaar');
assert_page_permission(!isset($pages['identities']), 'Inlogidentiteiten zijn niet toewijsbaar');
assert_page_permission(!isset($pages['roles']), 'Rolbeheer is niet toewijsbaar');
assert_page_permission(in_array('secretaris', $defaults['members'], true), 'Secretaris heeft standaard ledenbeheer');
assert_page_permission(in_array(AVPVH_Roles::IT_ROLE, $defaults['login_attempts'], true), 'IT-beheerder heeft standaard loginpogingen');
assert_page_permission(!in_array('penningmeester', $defaults['plugin_settings'], true), 'Penningmeester heeft standaard geen plugininstellingen');

AVPVH_Roles::save_page_permissions([
    'members' => ['bestuur', 'invalid role'],
    'plugin_settings' => ['penningmeester'],
    'authentication' => ['penningmeester'],
]);
$saved = get_option('avpvh_role_page_permissions');

assert_page_permission($saved['members'] === ['bestuur'], 'Onbekende rollen worden verwijderd');
assert_page_permission($saved['plugin_settings'] === ['penningmeester'], 'Geldige toewijzing wordt opgeslagen');
assert_page_permission(!isset($saved['authentication']), 'Niet-toewijsbare pagina wordt niet opgeslagen');
assert_page_permission($saved['activities'] === [], 'Niet-aangevinkte pagina wordt ingetrokken');

echo "\nAlle paginarechtentests geslaagd.\n";
