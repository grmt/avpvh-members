<?php
declare(strict_types=1);

/**
 * Renders [avpvh_ledenlijst] for a set of viewers against made-up members,
 * and checks what each row shows. All names and addresses below are fictional.
 *
 * AVPVH_LEDENLIJST_TEST_DUMP=<file> writes every rendered page, to compare two
 * versions of the class (AVPVH_LEDENLIJST_TEST_CLASS=<copy>) exactly.
 *
 * Run:
 *   php scripts/test-ledenlijst-render.php
 */

define('ABSPATH', __DIR__ . '/');

// --- WordPress stubs -------------------------------------------------------
$GLOBALS['logged_in'] = true;
$GLOBALS['current_user_id'] = 101;
$GLOBALS['is_admin'] = false;
function __($s, $d = '') { return $s; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return esc_html($s); }
function esc_url($s) { return esc_html($s); }
function esc_html__($s, $d = '') { return esc_html($s); }
function esc_html_e($s, $d = '') { echo esc_html($s); }
function esc_attr_e($s, $d = '') { echo esc_html($s); }
function wp_kses($s, $allowed) { return $s; }
function is_user_logged_in() { return $GLOBALS['logged_in']; }
function get_current_user_id() { return $GLOBALS['current_user_id']; }
function current_user_can($cap) { return $GLOBALS['is_admin']; }
function get_user_meta($id, $key, $single) { return $GLOBALS['user_meta'][$id][$key] ?? ''; }
function add_query_arg($key, $value, $url) { return $url . '?' . http_build_query([$key => $value]); }
function wp_nonce_url($url, $action) { return $url . '&_wpnonce=nonce-' . $action; }
function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }
function home_url($p = '') { return 'https://example.test' . $p; }
function add_action(...$a) { /* Not needed in this test. */ }
function add_shortcode(...$a) { /* Not needed in this test. */ }

// --- Plugin stubs -----------------------------------------------------------
function avpvh_format_name($m, $mode = 'display') {
    return $mode === 'list'
        ? trim($m->last_name . ', ' . $m->first_name . ' ' . $m->suffix)
        : trim($m->first_name . ' ' . $m->suffix . ' ' . $m->last_name);
}
function avpvh_get_member_by_wp_user($uid) {
    foreach (AVPVH_DB::$viewers as $m) { if ((int) $m->wp_user_id === $uid) { return $m; } }
    return null;
}

function lid(int $id, string $first, string $last, array $extra = []): object {
    return (object) array_merge([
        'id' => (string) $id, 'first_name' => $first, 'suffix' => '', 'last_name' => $last, 'status' => 'active',
        'wp_user_id' => null, 'lldap_user_id' => "lid$id", 'email' => "lid$id@example.test",
        'phone' => '', 'mobile' => '', 'street' => 'Fictieve Laan', 'house_number' => (string) $id, 'house_number_addition' => '',
        'postal_code' => '1234 AB', 'city' => 'Teststad', 'country' => 'Nederland',
        'share_email' => 1, 'share_phone' => 1, 'share_address' => 1,
    ], $extra);
}

class AVPVH_DB {
    public static array $viewers = [];
    public static array $leden = [];
    public static array $calls = [];
    public static function get_members_with_address($own_id, $all) {
        self::$calls[] = [$own_id, $all];
        return self::$leden;
    }
    public static function get_manageable_members($id) {
        return $id === 1 ? [(object) ['id' => '1'], (object) ['id' => '2']] : [(object) ['id' => (string) $id]];
    }
}
class AVPVH_Directory {
    public static function cached_all_group_memberships() {
        return ['lid1' => ['pvh-boek', 'bestuur'], 'lid3' => ['pvh-kamp'], 'lid4' => ['pvh-boek']];
    }
}
class AVPVH_Address_Normalizer {
    public static function format_street_line($m) { return trim($m->street . ' ' . $m->house_number . $m->house_number_addition); }
}

require_once getenv('AVPVH_LEDENLIJST_TEST_CLASS') ?: dirname(__DIR__) . '/includes/class-ledenlijst.php';

AVPVH_DB::$viewers = [
    lid(1, 'Alfa', 'Voorbeeld', ['wp_user_id' => 101]),
    lid(6, 'Fien', 'Oud', ['wp_user_id' => 106, 'status' => 'former']),
    lid(7, 'Gijs', 'Bestuur', ['wp_user_id' => 107]),
];
$all = [
    lid(1, 'Alfa', 'Voorbeeld', ['mobile' => '06-12 34 56 78', 'phone' => '010 / 000.00.00']),
    lid(2, 'Bram', 'Voorbeeld', ['suffix' => 'van', 'email' => 'bram@avpvh.local', 'mobile' => '+31 6 0000 0000', 'house_number_addition' => 'B']),
    lid(3, 'Cor', 'Elders', ['share_email' => 0, 'share_phone' => 0, 'city' => 'aardorp', 'country' => 'België', 'phone' => '010 1111111']),
    lid(4, 'Dina', 'Afgeschermd', ['share_address' => 0, 'city' => 'Verborgen', 'phone' => '020 2222222']),
    lid(5, 'Eva', 'Zonderstraat', ['street' => '', 'postal_code' => '', 'city' => '', 'country' => '', 'email' => null]),
];
AVPVH_DB::$leden = $all;
$GLOBALS['user_meta'] = [107 => ['avpvh_member_role' => 'Bestuur']];

function render(array $as, array $get = []): string {
    $GLOBALS['logged_in'] = $as['logged_in'] ?? true;
    $GLOBALS['current_user_id'] = $as['user'] ?? 101;
    $GLOBALS['is_admin'] = $as['admin'] ?? false;
    $_GET = $get;
    $html = (new AVPVH_Ledenlijst())->render();
    if (getenv('AVPVH_LEDENLIJST_TEST_DUMP')) {
        file_put_contents(getenv('AVPVH_LEDENLIJST_TEST_DUMP'), json_encode([$as, $get]) . "\n" . $html . "\n=====\n", FILE_APPEND);
    }
    return $html;
}
function check(string $label, bool $ok): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) { exit(1); }
}
function row(string $html, int $id): string {
    preg_match_all('~<tr data-city.*?</tr>~s', $html, $m);
    return $m[0][$id - 1] ?? '';
}
$has = static fn(string $html, string ...$needles): bool => array_reduce($needles, fn($c, $n) => $c && str_contains($html, $n), true);
$lacks = static fn(string $html, string ...$needles): bool => array_reduce($needles, fn($c, $n) => $c && !str_contains($html, $n), true);

$h = render(['logged_in' => false]);
check('logged out: asked to log in', $has($h, 'Je moet ingelogd zijn') && $lacks($h, '<table'));

$h = render(['user' => 500]);
check('no member for this account: active members only', $has($h, 'alleen beschikbaar voor actieve leden'));

$h = render(['user' => 106]);
check('former member: active members only', $has($h, 'alleen beschikbaar voor actieve leden'));

AVPVH_DB::$leden = [];
$h = render([]);
check('no members: says so', $has($h, 'Geen leden gevonden.'));
AVPVH_DB::$leden = $all;

AVPVH_DB::$calls = [];
$h = render([]);
check('member: own list, not everyone', AVPVH_DB::$calls === [[1, false]]);
check('member: intro, no saved notice', $has($h, 'privacyverklaring', 'https://example.test/member-profile/') && $lacks($h, 'Je voorkeuren zijn opgeslagen.'));
check('member: city filter has shared cities only, naturally sorted', $has($h, 'value="aardorp"> aardorp</label>', 'value="Teststad"') && $lacks($h, 'Verborgen'));
check('member: group filter from all rows, capitalised label', $has($h, 'value="bestuur"> Bestuur', 'value="pvh-boek"> Pvh-boek', 'value="pvh-kamp"'));
check('member: export link with nonce', $has($h, 'admin-post.php?action=avpvh_export_ledenlijst&amp;_wpnonce=nonce-avpvh_export_ledenlijst'));
check('member: household names are links, others not', $has(row($h, 1), 'member_id=1"') && $has(row($h, 2), 'member_id=2"') && $lacks(row($h, 3), 'member_id='));
check('row 1: phones as tel links, vCard cell and tel', $has(row($h, 1), 'href="tel:0612345678"', 'href="tel:0100000000"', 'data-vcard-cell="06-12 34 56 78"', 'data-vcard-tel="010 / 000.00.00"'));
check('row 1: groups, sort values', $has(row($h, 1), 'data-groups="pvh-boek,bestuur"', 'Pvh-boek, Bestuur', 'data-sort-value="voorbeeld, alfa"', 'data-sort-value="06-12 34 56 78"'));
check('row 2: placeholder e-mail hidden, international number kept', $has(row($h, 2), 'href="tel:+31600000000"', 'Fictieve Laan 2B') && $lacks(row($h, 2), 'avpvh.local', 'data-vcard-email'));
check('row 3: hidden e-mail and phone, foreign country shown', $lacks(row($h, 3), 'mailto:', 'tel:', 'data-vcard-tel') && $has(row($h, 3), '<br>België', 'data-vcard-adr="Fictieve Laan 3;aardorp;1234 AB;België"'));
check('row 4: address hidden everywhere', $has(row($h, 4), 'data-city=""', 'href="tel:0202222222"') && $lacks(row($h, 4), 'Verborgen', 'Fictieve Laan', '1234 AB', 'data-vcard-adr'));
check('row 5: no street, no postcode, no groups', $lacks(row($h, 5), 'Fictieve Laan', 'data-vcard-adr', 'mailto:lid5') && $has(row($h, 5), 'data-sort-value=""'));

$h = render([], ['consent_saved' => '1']);
check('saved notice', $has($h, 'Je voorkeuren zijn opgeslagen.'));

AVPVH_DB::$calls = [];
$h = render(['user' => 107]);
check('bestuur: full list, but only own name editable', AVPVH_DB::$calls === [[7, true]] && $lacks(row($h, 1), 'member_id='));

AVPVH_DB::$calls = [];
$h = render(['admin' => true]);
check('admin: full list, every name a link', AVPVH_DB::$calls === [[1, true]] && $has(row($h, 3), 'member_id=3"') && $has(row($h, 5), 'member_id=5"'));

echo "Ledenlijst render tests: OK\n";
