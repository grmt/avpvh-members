<?php
declare(strict_types=1);

/** Account provisioning and membership flags through the production database facade. */
define('ABSPATH', __DIR__ . '/');
class WP_Error {}
class WP_User {
    public function __construct(public int $ID, public string $user_login, public string $user_email, public array $roles = ['subscriber']) {}
}
function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function user_can(WP_User $user, string $cap): bool { return in_array('administrator', $user->roles, true); }
function get_user_by(string $field, mixed $value): WP_User|false {
    foreach ($GLOBALS['users'] as $user) {
        if (($field === 'id' && $user->ID === (int) $value) || ($field === 'email' && $user->user_email === $value)) { return $user; }
    }
    return false;
}
function sanitize_user(string $value, bool $strict = false): string { return preg_replace('/[^A-Za-z0-9._-]/', '', $value); }
function username_exists(string $login): int|false {
    foreach ($GLOBALS['users'] as $user) { if ($user->user_login === $login) { return $user->ID; } }
    return false;
}
function wp_generate_password(int $length): string { return str_repeat('x', $length); }
function wp_create_user(string $login, string $password, string $email): int|WP_Error {
    $GLOBALS['created'][] = compact('login', 'password', 'email');
    if ($GLOBALS['create_error'] || username_exists($login)) { return new WP_Error(); }
    $GLOBALS['users'][100] = new WP_User(100, $login, $email);
    return 100;
}
function wp_update_user(array $data): int {
    $GLOBALS['user_updates'][] = $data;
    $GLOBALS['users'][$data['ID']]->roles = [$data['role']];
    return $data['ID'];
}
function get_current_user_id(): int { return 501; }
function do_action(string $hook, mixed ...$args): void {}
class AVPVH_Directory_Cache { public static function table(): string { return 'wp_avm_directory_users'; } }
$wpdb = new class {
    public string $prefix = 'wp_';
    public array $updates = [];
    public array $assignments = [];
    public array $audit = [];
    public function prepare(string $sql, mixed ...$params): array {
        return [$sql, count($params) === 1 && is_array($params[0]) ? $params[0] : $params];
    }
    public function get_var(array $query): mixed {
        [$sql, $params] = $query;
        if (str_contains($sql, 'sets_inactive = 1')) { return in_array(7, $params, true) ? 1 : null; }
        foreach ($GLOBALS['members'] as $member) {
            if ($member->wp_user_id === $params[0] && $member->id !== $params[1]) { return $member->id; }
        }
        return null;
    }
    public function get_row(array $query): ?object { return isset($GLOBALS['members'][$query[1][0]]) ? clone $GLOBALS['members'][$query[1][0]] : null; }
    public function update(string $table, array $data, array $where, array $format, array $where_format): int {
        $this->updates[] = compact('table', 'data', 'where');
        foreach ($data as $key => $value) { $GLOBALS['members'][$where['id']]->$key = $value; }
        return 1;
    }
    public function delete(string $table, array $where, array $format): int { $this->assignments = []; return 1; }
    public function insert(string $table, array $data, array $format): int {
        if (str_ends_with($table, 'avm_member_audit_log')) { $this->audit[] = $data; }
        else { $this->assignments[] = $data; }
        return 1;
    }
};
require_once dirname(__DIR__) . '/includes/class-db.php';
$checks = [];
function check(string $label, bool $ok): void { $GLOBALS['checks'][] = [$label, $ok]; }
function reset_account(): object {
    $GLOBALS['users'] = $GLOBALS['created'] = $GLOBALS['user_updates'] = [];
    $GLOBALS['create_error'] = false;
    $member = (object) ['id' => 10, 'wp_user_id' => 0, 'lldap_user_id' => 'fixture.member', 'first_name' => 'Fictief', 'last_name' => 'Voorbeeld', 'status' => 'active'];
    $GLOBALS['members'] = [10 => $member];
    $GLOBALS['wpdb']->updates = $GLOBALS['wpdb']->audit = $GLOBALS['wpdb']->assignments = [];
    return clone $member;
}
$log = tempnam(sys_get_temp_dir(), 'avpvh_account_test_');
$previous_log = ini_get('error_log');
ini_set('error_log', $log);
try {
    $member = reset_account();
    $user = AVPVH_DB::get_or_create_wp_user('fixture@example.invalid', $member);
    check('new member account uses the directory UID and a generated password', $user->user_login === 'fixture.member' && strlen($GLOBALS['created'][0]['password']) === 64);
    check('provisioning explicitly assigns subscriber and links the member', $GLOBALS['user_updates'][0]['role'] === 'subscriber' && $GLOBALS['members'][10]->wp_user_id === $user->ID);
    $GLOBALS['users'][100]->roles = ['administrator'];
    $linked = clone $GLOBALS['members'][10];
    check('an explicitly linked existing account is reused', AVPVH_DB::get_or_create_wp_user('fixture@example.invalid', $linked) === $user && count($GLOBALS['created']) === 1);

    foreach (['subscriber', 'contributor', 'administrator', 'editor'] as $role) {
        $member = reset_account();
        $existing = new WP_User(90, 'fixture.existing', 'fixture@example.invalid', [$role]);
        $GLOBALS['users'][90] = $existing;
        $user = AVPVH_DB::get_or_create_wp_user('fixture@example.invalid', $member);
        $safe = in_array($role, ['subscriber', 'contributor'], true);
        check($role . ' email match follows account-linking permissions', $safe ? $user === $existing && $GLOBALS['members'][10]->wp_user_id === 90 : $user === null && !$wpdb->updates);
    }
    $member = reset_account();
    $GLOBALS['users'][90] = new WP_User(90, 'fixture.other', 'fixture@example.invalid');
    $GLOBALS['members'][20] = (object) ['id' => 20, 'wp_user_id' => 90];
    check('a subscriber already linked to another member is not taken over', AVPVH_DB::get_or_create_wp_user('fixture@example.invalid', $member) === null && !$wpdb->updates);

    foreach (['uid_collision', 'empty_uid', 'creation_error', 'stale_link'] as $case) {
        $member = reset_account();
        switch ($case) {
            case 'uid_collision': $GLOBALS['users'][90] = new WP_User(90, 'fixture.member', 'other@example.invalid'); break;
            case 'empty_uid': $member->lldap_user_id = '!!!'; break;
            case 'creation_error': $GLOBALS['create_error'] = true; break;
            case 'stale_link': $member->wp_user_id = 999; break;
        }
        $user = AVPVH_DB::get_or_create_wp_user('fixture@example.invalid', $member);
        $expected = match ($case) {
            'uid_collision' => $user?->user_login === 'fixture.member-10',
            'empty_uid' => $user?->user_login === 'lid-10',
            'creation_error' => $user === null && !$wpdb->updates,
            'stale_link' => $user?->ID === 100,
        };
        check($case . ' is handled without linking a wrong account', $expected);
    }
    reset_account();
    check('empty and invalid flag lists do not imply inactivation', !AVPVH_DB::flag_ids_include_inactivating([]) && !AVPVH_DB::flag_ids_include_inactivating([0, 'invalid']));
    check('flag classification accepts IDs and rejects ordinary flags', AVPVH_DB::flag_ids_include_inactivating(['7', 6]) && !AVPVH_DB::flag_ids_include_inactivating([6]));
    AVPVH_DB::set_member_flags(10, [7, 7, 0]);
    check('inactivating flag is assigned once and changes membership status', $wpdb->assignments === [['member_id' => 10, 'flag_id' => 7]] && $GLOBALS['members'][10]->status === 'inactive');
    check('membership status transition records the actor and previous value', $wpdb->audit === [['member_id' => 10, 'changed_by' => 501, 'field_name' => 'status', 'old_value' => 'active', 'new_value' => 'inactive']]);
    AVPVH_DB::set_member_flags(10, [6]);
    check('removing an inactivating flag never silently reactivates membership', $GLOBALS['members'][10]->status === 'inactive' && count($wpdb->audit) === 1);
    AVPVH_DB::set_member_flags(10, [7]);
    check('already inactive member is not given a redundant status update', count($wpdb->audit) === 1);
} finally {
    ini_set('error_log', $previous_log);
    unlink($log);
}
$failures = 0;
foreach ($checks as [$label, $ok]) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    $failures += $ok ? 0 : 1;
}
exit($failures ? 1 : 0);
