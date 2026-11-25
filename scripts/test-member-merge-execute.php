<?php
declare(strict_types=1);

/**
 * Runs AVPVH_Member_Merge::execute() against a recording $wpdb and stubbed
 * directory, for every way it can refuse, fail or succeed, and checks what it
 * writes and returns. All members and addresses below are made up.
 *
 * AVPVH_MERGE_TEST_DUMP=<file> writes every recorded run, to compare two
 * versions of the class (AVPVH_MERGE_TEST_CLASS=<copy>) exactly.
 *
 * Run:
 *   php scripts/test-member-merge-execute.php
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['log'] = [];
function note(string $what): void { $GLOBALS['log'][] = preg_replace('/\s+/', ' ', trim($what)); }

// --- WordPress stubs -------------------------------------------------------
function __($s, $d = '') { return $s; }
function is_email($e) { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL); }
class WP_Error {
    public function __construct(public string $code = '', public string $message = '') {}
    public function get_error_message() { return $this->message; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error($v) { return $v instanceof WP_Error; }
function wp_json_encode($v) { return json_encode($v); }
function current_user_can($cap) { return true; }
function get_userdata($id) { return false; }
function user_can($u, $cap) { return false; }

class Recording_WPDB {
    public string $prefix = 'wp_';
    public string $last_error = 'stub error';
    public array $members = [];
    public array $fail = [];
    public function prepare($sql, ...$args) {
        $args = is_array($args[0] ?? null) ? $args[0] : $args;
        return vsprintf(str_replace(['%s', '%d'], ["'%s'", '%d'], str_replace('%%', '%%%%', $sql)), $args);
    }
    private function fails(string $what): bool {
        foreach ($this->fail as $needle) {
            if (str_contains($what, $needle)) { return true; }
        }
        return false;
    }
    public function get_row($sql) {
        preg_match('/WHERE id = (\d+)/', $sql, $m);
        return isset($this->members[(int) $m[1]]) ? clone $this->members[(int) $m[1]] : null;
    }
    public function get_var($sql) {
        if (str_contains($sql, 'avm_member_name_aliases WHERE member_id') || str_contains($sql, 'label_id = ')) { return '0'; }
        return str_contains($sql, 'avm_member_identities') ? '2' : '1';
    }
    public function esc_like($s) { return $s; }
    public function get_col($sql) { return ['7']; }
    public function get_results($sql) {
        if (str_contains($sql, 'information_schema')) {
            return [(object) ['TABLE_NAME' => 'wp_avb_fee_items', 'COLUMN_NAME' => 'member_id'], (object) ['TABLE_NAME' => 'wp_avm_member_identities', 'COLUMN_NAME' => 'member_id']];
        }
        if (str_contains($sql, 'avm_activity_participation WHERE')) {
            return [(object) ['id' => 31, 'activity_id' => 5, 'nights' => 3, 'nawacht' => 1, 'diet' => 'vega', 'notes' => '']];
        }
        if (str_contains($sql, 'avm_relationships WHERE')) {
            return [(object) ['id' => 7, 'member_id' => 2, 'related_member_id' => 3, 'label_id' => 1]];
        }
        return [];
    }
    public function query($sql) { note("query $sql"); return $this->fails($sql) ? false : 1; }
    public function update($table, $data, $where, ...$f) {
        $line = "update $table " . json_encode($data) . ' ' . json_encode($where);
        note($line);
        return $this->fails($line) ? false : 1;
    }
    public function insert($table, $data, ...$f) { note("insert $table " . json_encode($data)); return 1; }
    public function delete($table, $where, ...$f) {
        $line = "delete $table " . json_encode($where);
        note($line);
        return $this->fails($line) ? 0 : 1;
    }
}

// --- Plugin stubs -----------------------------------------------------------
function avpvh_format_name($m) { return trim($m->first_name . ' ' . $m->suffix . ' ' . $m->last_name); }

class AVPVH_Roles {
    const ALL_ROLES = ['bestuur'];
    const IT_GROUP = 'it-beheer';
    const BLOGGER_GROUP = 'bloggers';
    public static function strip_bestuur_roles(int $id): bool { $GLOBALS['status_sync'][] = ['strip', $id]; return true; }
    public static function sync_status_group(int $id): true|WP_Error { $GLOBALS['status_sync'][] = ['sync', $id]; return true; }
}
class AVPVH_DB {
    public static array $emails = [1 => 'alfa@example.test', 2 => 'alfa.oud@example.test'];
    public static array $identity_owner = [];
    public static bool $identities_ok = true;
    public static function get_member($id) { return isset(self::$emails[$id]) ? (object) ['id' => $id, 'email' => self::$emails[$id]] : null; }
    public static function get_addresses($id) { return []; }
    public static function get_activities_for_member($id) { return [(object) ['id' => 5, 'name' => 'Kamp', 'year' => 2025]]; }
    public static function get_participation($keep, $activity) { return (object) ['id' => 21, 'nights' => null, 'nawacht' => 0, 'diet' => '', 'notes' => 'eerder']; }
    public static function get_fees_for_member($id) { return [(object) ['id' => 41, 'year' => 2025, 'status' => 'paid'], (object) ['id' => 42, 'year' => 2024, 'status' => 'pending']]; }
    public static function get_fee_for_year($id, $year) { return $year === 2025 ? (object) ['id' => 51, 'status' => 'pending'] : null; }
    public static function get_identity_by_email($email) {
        return isset(self::$identity_owner[$email]) ? (object) ['member_id' => self::$identity_owner[$email]] : null;
    }
    public static function log_member_change($id, $field, $old, $new) { note("log $id $field: $old -> $new"); }
    public static function get_member_identities($id) { return [(object) ['id' => 61, 'email' => 'alfa@example.test']]; }
    public static function set_primary_identity($id, $identity) { note("primary $id $identity"); return self::$identities_ok; }
    public static function ensure_identity($id, $provider, $email, $primary = false) { note("identity $id $email " . ($primary ? 'primary' : 'extra')); return self::$identities_ok; }
}
class AVPVH_Directory {
    public static array $errors = [];
    public static function get_user_groups($uid) { return ['pvh-leden']; }
    public static function only_pvh_groups($groups) { return $groups; }
    public static function update_user($uid, $data) {
        note("directory update $uid " . json_encode($data));
        return isset(self::$errors[array_key_first($data)]) ? new WP_Error('dir', 'directory down') : true;
    }
    public static function delete_user($uid) { note("directory delete $uid"); return isset(self::$errors['delete']) ? new WP_Error('dir', 'directory down') : true; }
    public static function forget_groups($uid) { note("forget $uid"); }
}
class AVPVH_Address_Cleanup {
    const FIELDS = ['street', 'house_number', 'house_number_addition', 'postal_code', 'city'];
    public static array $plan = [];
    public static function plan_history(array $rows) { return self::$plan; }
    public static function format(array $f) { return trim($f['street'] . ' ' . $f['house_number']); }
}
class AVPVH_Address_Normalizer {
    public static function clean(array $f) { return $f; }
}
class AVPVH_Address_Matcher {
    public static function same(array $fields, object $row) { return $fields['street'] === $row->street; }
}
class AVPVH_Name_Matcher {
    public static function normalize_person_name($first, $suffix, $last) { return strtolower(trim("$first $suffix $last")); }
}

require_once getenv('AVPVH_MERGE_TEST_CLASS') ?: dirname(__DIR__) . '/includes/class-member-merge.php';

function lid(int $id, string $first, array $extra = []): object {
    return (object) array_merge([
        'id' => (string) $id, 'first_name' => $first, 'suffix' => '', 'last_name' => 'Voorbeeld', 'wp_user_id' => null,
        'lldap_user_id' => "lid$id", 'passport_name' => '', 'initials' => '', 'birth_date' => null, 'birth_year' => null,
        'is_student' => '0', 'phone' => '', 'mobile' => '', 'emergency_contact' => '', 'emergency_member_id' => null,
        'emergency_relation' => '', 'emergency_phone' => '', 'emergency_address' => '', 'diet' => '', 'status' => 'active',
        'joined_year' => null, 'left_year' => null, 'directory_consent' => 'granted', 'directory_consent_at' => null,
        'share_email' => '1', 'share_phone' => '1', 'share_address' => '1', 'share_activity_history' => '1',
    ], $extra);
}
function address_row(int $id, int $member, string $street): object {
    return (object) ['id' => $id, 'member_id' => $member, 'street' => $street, 'house_number' => '1', 'house_number_addition' => '', 'postal_code' => '1234 AB', 'city' => 'Teststad', 'country' => 'Nederland'];
}
function setup(array $remove = [], array $fail = [], array $directory_errors = [], array $owner = [], bool $identities_ok = true): void {
    $GLOBALS['wpdb'] = new Recording_WPDB();
    $GLOBALS['wpdb']->members = [1 => lid(1, 'Alfa', ['phone' => '010 0000000']), 2 => lid(2, 'A.', ['mobile' => '06 00000000', 'birth_year' => '1980'] + $remove)];
    $GLOBALS['wpdb']->fail = $fail;
    AVPVH_Directory::$errors = $directory_errors;
    AVPVH_DB::$identity_owner = $owner;
    AVPVH_DB::$identities_ok = $identities_ok;
    AVPVH_Address_Cleanup::$plan = [[
        'id' => 'g1',
        'variants' => [['fields' => ['street' => 'Fictieve Laan', 'house_number' => '1', 'house_number_addition' => '', 'postal_code' => '1234 AB', 'city' => 'Teststad']]],
        'rows' => [address_row(81, 1, 'Fictieve Laan'), address_row(82, 2, 'Fictieve Laan')],
        'results' => [['ids' => [82, 81], 'from' => '2001-01-01', 'until' => null]],
    ], [
        'id' => 'g2',
        'variants' => [['fields' => ['street' => 'Oude Weg', 'house_number' => '1', 'house_number_addition' => '', 'postal_code' => '1234 AB', 'city' => 'Teststad']]],
        'rows' => [address_row(83, 2, 'Oude Weg')],
        'results' => [['ids' => [83], 'from' => '1990-01-01', 'until' => '2000-12-31']],
    ]];
}
function run(string $label, array $choices, ?string $fingerprint = null, int $keep = 1, int $remove = 2): array|WP_Error {
    $preview = AVPVH_Member_Merge::preview($keep, $remove);
    $GLOBALS['log'] = [];
    $result = AVPVH_Member_Merge::execute($keep, $remove, $choices, $fingerprint ?? (is_wp_error($preview) ? '' : $preview['fingerprint']));
    if (getenv('AVPVH_MERGE_TEST_DUMP')) {
        file_put_contents(getenv('AVPVH_MERGE_TEST_DUMP'), "== $label\n" . json_encode($result) . "\n" . implode("\n", $GLOBALS['log']) . "\n", FILE_APPEND);
    }
    return $result;
}
function check(string $label, bool $ok): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) { exit(1); }
}
$code = static fn($r): string => is_wp_error($r) ? $r->code : 'ok';
$has = static fn(string $needle): bool => (bool) array_filter($GLOBALS['log'], fn($l) => str_contains($l, $needle));
$writes = static fn(): array => array_values(array_filter($GLOBALS['log'], fn($l) => !str_starts_with($l, 'query SELECT')));

setup();
check('unknown member: refused, nothing written', $code(run('unknown', [], null, 1, 99)) === 'not_found' && $writes() === []);

setup(['wp_user_id' => '7']);
check('duplicate with a login: blocked', $code(run('blocked', [])) === 'blocked' && $writes() === []);

setup();
check('changed since the preview: stale', $code(run('stale', [], 'old')) === 'stale' && $writes() === []);

setup();
check('invalid custom e-mail: refused', $code(run('email invalid', ['email' => ['choice' => 'custom', 'custom' => 'geen-adres']])) === 'email_invalid');

setup([], [], [], ['alfa.oud@example.test' => 9]);
check('e-mail of another member: refused', $code(run('email taken', ['email' => ['choice' => 'remove']])) === 'email_taken' && $writes() === []);

setup();
check('other address spelling: refused', $code(run('address spelling', ['address_groups' => ['g1' => ['fields' => ['street' => 'Andere Straat']]]])) === 'address');

setup();
check('end date before start: refused', $code(run('address until', ['address_groups' => ['g2' => ['until' => '1980-01-01']]])) === 'address_until');

setup();
check('custom name without last name: refused', $code(run('name', ['fields' => ['name' => 'custom'], 'name_custom' => ['first_name' => 'Alfa']])) === 'name' && $writes() === []);

setup();
$r = run('defaults', []);
check('defaults: merged, no warnings', $code($r) === 'ok' && $r['warnings'] === [] && $r['notes'] === ['alfa.oud@example.test is toegevoegd als extra (onbevestigd) inlogadres.']);
check('defaults: committed, duplicate deleted, account removed', $has('query COMMIT') && $has('delete wp_avm_members {"id":2}') && $has('directory delete lid2'));
check('defaults: fields only the duplicate has are taken', $has('"mobile":"06 00000000"') && $has('"birth_year":"1980"') && !$has('"phone":""'));
check('defaults: participation filled, fee kept by the paid one, relationship moved', $has('{"nights":3,"nawacht":1,"diet":"vega"}') && $has('delete wp_avm_fees {"id":51}') && $has('"related_member_id":3'));
check('defaults: other plugins repointed, handled tables skipped', $has('UPDATE `wp_avb_fee_items`') && $has('log 1 samenvoeging'));
check('defaults: address rows folded onto the kept member', $has('update wp_avm_addresses') && $has('delete wp_avm_addresses {"id":82}'));
check('defaults: old name kept as alias', $has('"alias_type":"historical"') && $has('"normalized_key":"a. voorbeeld"'));

setup();
$r = run('custom name, other e-mail, drop address', [
    'fields' => ['name' => 'custom', 'mobile' => 'keep'], 'name_custom' => ['first_name' => ' Alfa ', 'suffix' => 'van', 'last_name' => 'Voorbeeld'],
    'email' => ['choice' => 'remove', 'add_other' => false],
    'address_groups' => ['g2' => ['drop' => '1'], 'g1' => ['until' => '2020-05-01']],
]);
check('custom: name written and account renamed', $code($r) === 'ok' && $has('"first_name":"Alfa","suffix":"van","last_name":"Voorbeeld"') && $has('directory update lid1 {"display_name":"Alfa van Voorbeeld"}'));
check('custom: e-mail changed after the account delete', $has('directory update lid1 {"mail":"alfa.oud@example.test"}') && $r['notes'] === ['Het e-mailadres is nu alfa.oud@example.test.']);
check('custom: dropped address deleted, end date set', $has('delete wp_avm_addresses {"id":83}') && $has('"valid_until":"2020-05-01"') && !$has('"mobile"'));

setup([], [], ['display_name' => 1, 'delete' => 1, 'mail' => 1]);
$r = run('directory down', ['fields' => ['name' => 'custom'], 'name_custom' => ['first_name' => 'Alfa', 'last_name' => 'Anders'], 'email' => ['choice' => 'remove']]);
check('directory down: merged with three warnings, no e-mail note', $code($r) === 'ok' && count($r['warnings']) === 3 && $r['notes'] === []);

setup([], [], [], [], false);
$r = run('identities full', ['email' => ['choice' => 'remove']]);
check('identity limit: new primary login refused, warns', $code($r) === 'ok' && count($r['warnings']) === 1 && $has('identity 1 alfa.oud@example.test primary'));
$r2 = run('identities full, extra', []);
check('identity limit: extra login warns', count($r2['warnings']) === 1 && $r2['notes'] === []);

setup([], ['UPDATE `wp_avb_fee_items`']);
$r = run('repoint fails', []);
check('a repoint fails: rolled back, account kept', $code($r) === 'failed' && $has('query ROLLBACK') && !$has('COMMIT') && !$has('directory'));

setup([], ['delete wp_avm_members']);
$r = run('delete fails', []);
check('member delete fails: rolled back', $code($r) === 'failed' && str_contains($r->message, 'verwijderen van het dubbele lid mislukt') && $has('query ROLLBACK'));

setup([], ['update wp_avm_members {"birth_year"']);
$r = run('update fails', []);
check('member update fails: rolled back with the step name', $code($r) === 'failed' && str_contains($r->message, 'lidgegevens bijwerken mislukt'));

setup(['status' => 'inactive']);
$GLOBALS['status_sync'] = [];
$r = run('merge inactivates member', ['fields' => ['status' => 'remove']]);
check('status change after merge strips elevated groups and syncs membership', $code($r) === 'ok' && $GLOBALS['status_sync'] === [['strip', 1], ['sync', 1]]);
echo "Member merge execute tests: OK\n";
