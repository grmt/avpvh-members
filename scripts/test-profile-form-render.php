<?php
declare(strict_types=1);

/**
 * Renders [avpvh_member_profile] for a set of viewers and targets against
 * made-up members, and checks which parts each one sees. All names and
 * addresses below are fictional.
 *
 * AVPVH_PROFILE_TEST_DUMP=<file> writes every rendered page, to compare two
 * versions of the class (AVPVH_PROFILE_TEST_CLASS=<copy>) exactly.
 *
 * Run:
 *   php scripts/test-profile-form-render.php
 */

define('ABSPATH', __DIR__ . '/');

// --- WordPress stubs -------------------------------------------------------
$GLOBALS['logged_in'] = true;
$GLOBALS['current_user_id'] = 101;
$GLOBALS['can_manage'] = false;
class WP_User {
    public function __construct(public int $ID, public string $user_email, public array $roles = ['subscriber']) {}
}
function __($s, $d = '') { return $s; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return esc_html($s); }
function esc_url($s) { return esc_html($s); }
function esc_js($s) { return addslashes((string) $s); }
function esc_html__($s, $d = '') { return esc_html($s); }
function esc_attr__($s, $d = '') { return esc_html($s); }
function esc_html_e($s, $d = '') { echo esc_html($s); }
function esc_attr_e($s, $d = '') { echo esc_html($s); }
function is_user_logged_in() { return $GLOBALS['logged_in']; }
function get_current_user_id() { return $GLOBALS['current_user_id']; }
function wp_get_current_user() { return $GLOBALS['users'][$GLOBALS['current_user_id']] ?? new WP_User(0, ''); }
function get_userdata($id) { return $GLOBALS['users'][$id] ?? false; }
function get_user_meta($id, $key, $single) { return $GLOBALS['user_meta'][$id][$key] ?? ''; }
function absint($v) { return abs((int) $v); }
function wp_unslash($v) { return $v; }
function sanitize_key($k) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $k)); }
function add_query_arg($args, $value = null, $url = null) {
    if (!is_array($args)) { $args = [$args => $value]; $url = $url ?? 'https://example.test/member-profile/'; } else { $url = $value; }
    return $url . '?' . http_build_query($args);
}
function remove_query_arg($keys, $url) { return $url; }
function wp_get_referer() { return false; }
function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }
function home_url($p = '') { return 'https://example.test' . $p; }
function wp_list_pluck($list, $field) { return array_map(fn($o) => $o->$field, $list); }
function current_time($f) { return '2026-11-19'; }
function is_wp_error($v) { return false; }
function wp_nonce_field($action = -1, $name = '_wpnonce') { echo '<input type="hidden" name="' . $name . '" value="nonce-' . $action . '">'; }
function checked($v, $current = true) { echo ((string) (bool) $v === (string) (bool) $current) ? ' checked' : ''; }
function selected($a, $b) { echo ((string) $a === (string) $b) ? ' selected' : ''; }
function determine_locale() { return 'nl_NL'; }
function get_page_by_path($p) { return null; }
function get_permalink($p) { return ''; }
function mysql2date($f, $d) { return date($f, strtotime($d)); }
function wp_json_encode($v) { return json_encode($v); }
function add_action(...$a) { /* Not needed in this test. */ }
function add_shortcode(...$a) { /* Not needed in this test. */ }

// --- Plugin stubs -----------------------------------------------------------
function avpvh_format_name($m) { return trim($m->first_name . ' ' . ($m->suffix ?? '') . ' ' . $m->last_name); }
function avpvh_initials_mismatch($m) { return $m->id === 1 ? 'A.B.' : ''; }

function member(int $id, string $first, string $last, array $extra = []): object {
    return (object) array_merge([
        'id' => $id, 'first_name' => $first, 'suffix' => '', 'last_name' => $last, 'status' => 'active',
        'wp_user_id' => null, 'user_id' => '', 'email' => '', 'passport_name' => '', 'initials' => '',
        'birth_date' => null, 'birth_year' => null, 'is_student' => 0, 'diet' => '', 'phone' => '', 'mobile' => '',
        'emergency_member_id' => null, 'emergency_contact' => '', 'emergency_relation' => '', 'emergency_phone' => '',
        'emergency_address' => '', 'directory_consent' => 'granted', 'share_email' => 1, 'share_phone' => 0, 'share_address' => 1,
    ], $extra);
}

class AVPVH_DB {
    public static array $members = [];
    public static function get_member($id) { return self::$members[$id] ?? null; }
    public static function get_member_by_wp_user($uid) {
        foreach (self::$members as $m) { if ((int) $m->wp_user_id === $uid) { return $m; } }
        return null;
    }
    public static function get_manageable_members($id) {
        return in_array($id, [1, 2, 3], true) ? [self::$members[1], self::$members[2], self::$members[3]] : [self::$members[$id]];
    }
    public static function has_same_address($a, $b) { return in_array($a, [1, 2], true) && in_array($b, [1, 2], true); }
    public static function get_extended_household($id) { return in_array($id, [1, 2, 3], true) ? [self::$members[2], self::$members[4]] : []; }
    public static function get_addresses($id) {
        return $id === 1 ? [
            (object) ['street' => 'Oude Straat', 'house_number' => '1', 'house_number_addition' => '', 'postal_code' => '1000 AA', 'city' => 'Voorbeeld', 'country' => 'Nederland', 'valid_from' => '2001-01-01', 'valid_until' => '2010-01-01'],
            (object) ['street' => 'Fictieve Laan', 'house_number' => '12', 'house_number_addition' => 'B', 'postal_code' => '1234 AB', 'city' => 'Teststad', 'country' => 'Nederland', 'valid_from' => '2010-01-02', 'valid_until' => null],
        ] : [];
    }
    public static function get_member_identities($id) {
        $n = $id === 5 ? 3 : ($id === 1 ? 2 : 0);
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = (object) ['id' => $id * 10 + $i, 'email' => "lid$id-$i@example.test", 'provider' => $i === 1 ? 'google' : 'email', 'verified_at' => $i === 2 ? null : '2026-01-01', 'is_primary' => $i === 1 ? 1 : 0];
        }
        return $out;
    }
    public static function can_delete_identity($ids, $id) { return count($ids) > 1 && $id % 10 !== 1; }
    public static function get_relationships($id) {
        return $id === 1 ? [(object) ['id' => 7, 'other_member' => self::$members[3], 'label' => 'ouder van', 'valid_from' => null, 'valid_until' => null]] : [];
    }
    public static function get_relationship_labels() { return [(object) ['id' => 1, 'label' => 'ouder van']]; }
    public static function get_members() { return array_values(self::$members); }
    public static function get_activities() { return [(object) ['id' => 9, 'type_name' => 'Kamp', 'name' => 'Zomerkamp', 'year' => 2026]]; }
    public static function member_has_flag($id, $flag) { return $id === 1; }
    public static function get_member_audit_log($id, $limit) {
        return $id === 1 ? [(object) ['changed_at' => '2026-11-01 10:00:00', 'field_name' => 'phone', 'user_login' => null, 'old_value' => null, 'new_value' => '0100000000']] : [];
    }
    public static function get_members_with_address($own_id, $all) { return [self::$members[1], self::$members[2], self::$members[3]]; }
}
class AVPVH_Roles {
    public static function can_manage_members() { return $GLOBALS['can_manage']; }
    public static function describe_member_roles($id) { return $id === 1 ? ['penningmeester'] : []; }
    public static function member_is_blogger($id) { return $id === 1; }
    public static function get_role_label($role) { return ucfirst($role); }
}
class AVPVH_Directory {
    public static function get_user_groups($uid) { return ['users', 'pvh-boek']; }
    public static function only_pvh_groups($groups) { return array_values(array_filter($groups, fn($g) => str_starts_with($g, 'pvh-'))); }
}
class AVPVH_Member_Merge {
    public static function real_email(string $e) { return str_ends_with($e, '@avpvh.local') ? '' : $e; }
}
class AVPVH_OAuth {
    public static function configured_providers() { return ['google' => ['label' => 'Google']]; }
    public static function add_identity_url($key, $id) { return "https://example.test/oauth/$key/$id"; }
}
class AVPVH_I18n {
    public static function get_supported_locales() { return ['nl_NL' => 'Nederlands', 'en_GB' => 'English']; }
}

require_once getenv('AVPVH_PROFILE_TEST_CLASS') ?: dirname(__DIR__) . '/includes/class-member-profile-form.php';
if (!getenv('AVPVH_PROFILE_TEST_CLASS')) {
    require_once dirname(__DIR__) . '/includes/class-member-profile-page.php';
    require_once dirname(__DIR__) . '/includes/class-member-profile-sections.php';
}

AVPVH_DB::$members = [
    1 => member(1, 'Anna', 'Voorbeeld', ['wp_user_id' => 101, 'user_id' => 'anna', 'email' => 'anna@example.test', 'emergency_member_id' => 3, 'birth_year' => 1980, 'is_student' => 1]),
    2 => member(2, 'Bram', 'Voorbeeld', ['suffix' => 'van', 'wp_user_id' => 102]),
    3 => member(3, 'Cor', 'Voorbeeld', ['birth_date' => '1950-02-03', 'email' => 'cor@avpvh.local', 'emergency_member_id' => 2, 'directory_consent' => 'declined']),
    4 => member(4, 'Dina', 'Elders', ['wp_user_id' => 104]),
    5 => member(5, 'Eva', 'Bezoeker', ['status' => 'visitor', 'wp_user_id' => 105]),
];
$GLOBALS['users'] = [
    101 => new WP_User(101, 'lid1-1@example.test'),
    102 => new WP_User(102, 'bram@example.test', ['editor']),
    105 => new WP_User(105, 'lid5-1@example.test', []),
    900 => new WP_User(900, 'beheer@example.test', ['administrator']),
];
$GLOBALS['user_meta'] = [101 => ['avpvh_member_role' => 'penningmeester', 'locale' => 'en_GB']];

function render(array $as, array $get = []): string {
    $GLOBALS['logged_in'] = $as['logged_in'] ?? true;
    $GLOBALS['current_user_id'] = $as['user'] ?? 101;
    $GLOBALS['can_manage'] = $as['admin'] ?? false;
    $_GET = $get;
    $html = (new AVPVH_Member_Profile_Form())->render_shortcode();
    if (getenv('AVPVH_PROFILE_TEST_DUMP')) {
        file_put_contents(getenv('AVPVH_PROFILE_TEST_DUMP'), json_encode([$as, $get]) . "\n" . $html . "\n=====\n", FILE_APPEND);
    }
    return $html;
}
function check(string $label, bool $ok): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) { exit(1); }
}
$has = static fn(string $html, string ...$needles): bool => array_reduce($needles, fn($c, $n) => $c && str_contains($html, $n), true);
$lacks = static fn(string $html, string ...$needles): bool => array_reduce($needles, fn($c, $n) => $c && !str_contains($html, $n), true);

$h = render(['logged_in' => false]);
check('logged out: asked to log in', $has($h, 'Log in om je profiel te bewerken.'));

$h = render(['user' => 500]);
check('no member for this account: not found', $has($h, 'Ledenprofiel niet gevonden.'));

$h = render(['user' => 900, 'admin' => true], ['member_id' => '77']);
check('admin, unknown member: not found', $has($h, 'Ledenprofiel niet gevonden.'));

$h = render([]);
check('own profile: summary card with badges, groups, roles, household', $has($h, 'avpvh-summary-card', 'Penningmeester', 'Actief lid', 'pvh-boek', 'Rollen:', 'Huisgenoten:', 'Familie (elders wonend):', 'Ook verbonden'));
check('own profile: current address, not the old one', $has($h, 'Fictieve Laan') && $lacks($h, 'Oude Straat'));
check('own profile: self-service identities, consent, newsletter, blogging, audit', $has($h, 'Inlog-e-mailadressen', 'Zichtbaarheid in ledenlijst', 'Nieuwsbrief', 'Bloggen', 'Wijzigingsgeschiedenis'));
check('own profile: no admin-only fields or notes', $lacks($h, 'name="birth_date"', 'Beheerder bewerkt', 'avpvh-admin-emails', 'Je bewerkt het profiel van'));
check('own profile: initials warning, emergency member chosen, locale from user meta', $has($h, 'A.B.', 'value="3"  selected', 'value="en_GB"  selected'));
check('own profile: no membership request for an active member', $lacks($h, 'Lidmaatschap aanvragen'));

$h = render([], ['identity_error' => 'limit', 'consent_saved' => '1']);
check('own profile: notices', $has($h, 'maximaal drie', 'Je voorkeuren zijn opgeslagen.'));

$h = render([], ['member_id' => '2']);
check('household edit: note, request button, consent, no blogging', $has($h, 'Je bewerkt het profiel van', 'Vraag Bram om een e-mailadres', 'Zichtbaarheid in ledenlijst') && $lacks($h, 'Bloggen'));

$h = render([], ['member_id' => '3']);
check('family elsewhere: editable', $has($h, 'Je bewerkt het profiel van', 'id="avpvh-profile-form"'));

$h = render([], ['member_id' => '4']);
check('extended family: request-only page', $has($h, 'Je kunt hier een verzoek sturen aan', 'Inlog-e-mailadres verifiëren') && $lacks($h, 'avpvh-profile-form"', 'avpvh-summary-card'));

$h = render([], ['member_id' => '5']);
check('unrelated member: own profile instead', $has($h, 'Anna') && $lacks($h, 'Je bewerkt het profiel van'));

$h = render(['user' => 900, 'admin' => true], ['member_id' => '3']);
check('admin edit: note, member e-mail "geen", birth date, admin e-mail block, relationship form', $has($h, 'Beheerder bewerkt', 'E-mail lid:', 'geen', 'name="birth_date"', 'value="1950-02-03"', 'avpvh-admin-emails', 'Relatie toevoegen'));
check('admin edit: no self-service sections', $lacks($h, 'Zichtbaarheid in ledenlijst', 'Nieuwsbrief', 'Bloggen', 'Lidmaatschap aanvragen'));

$h = render(['user' => 900, 'admin' => true], ['member_id' => '1']);
check('admin edit: birth year when no date', $has($h, 'value="1980"', 'anna@example.test'));

$h = render(['user' => 105]);
check('visitor: membership request, Bezoeker badge, three identities so no add', $has($h, 'Lidmaatschap aanvragen', 'Bezoeker', 'Gebruiker') && $lacks($h, 'Voeg een e-mailadres toe en verifieer het'));

echo "Profile form render tests: OK\n";
