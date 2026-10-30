<?php
declare(strict_types=1);

/**
 * Checks how the split emergency contact is shown on Ledendetail and the
 * merge page (AVPVH_Member_Merge::field_display()).
 *
 * Run:
 *   php scripts/test-member-merge-emergency.php
 */

define('ABSPATH', __DIR__ . '/');

function __(string $text, string $domain = ''): string {
    return $text;
}

function avpvh_format_name(object $m): string {
    return trim("{$m->first_name} {$m->last_name}");
}
// Stand-in for the lookups emergency_display() and handle_save_profile() do.
class AVPVH_DB {
    public static array $members = [];
    public static array $addresses = [];
    public static array $ledenlijst = [];
    public static array $manageable = [];
    public static ?array $saved = null;
    public static function get_member(int $id): ?object {
        return self::$members[$id] ?? null;
    }
    public static function get_member_by_wp_user(int $wp_uid): ?object {
        foreach (self::$members as $m) {
            if ((int) ($m->wp_user_id ?? 0) === $wp_uid) {
                return $m;
            }
        }
        return null;
    }
    public static function current_address(int $id): ?object {
        return self::$addresses[$id] ?? null;
    }
    public static function get_members_with_address(int $viewer_id = 0, bool $is_admin = false): array {
        return self::$ledenlijst;
    }
    public static function get_manageable_members(int $member_id): array {
        return self::$manageable;
    }
    public static function update_member_with_audit(int $id, array $data, array $formats): void {
        self::$saved = $data;
    }
}
class AVPVH_Roles {
    public static bool $admin = false;
    public static function can_manage_members(): bool { return self::$admin; }
}
class AVPVH_Directory {
    public static function update_user(string $uid, array $data): bool { return true; }
}
function add_shortcode(string $t, callable $c): void {}
function add_action(string $h, callable $c): void {}
function check_ajax_referer(string $a, string $q): void {}
function is_user_logged_in(): bool { return true; }
function get_current_user_id(): int { return 101; }
function wp_get_current_user(): object { return (object) ['ID' => 101]; }
function wp_unslash(mixed $v): mixed { return $v; }
function absint(mixed $v): int { return abs((int) $v); }
function sanitize_text_field(string $v): string { return trim($v); }
function is_wp_error(mixed $v): bool { return false; }
class JsonExit extends Error {
    public function __construct(public bool $ok, public mixed $data) { parent::__construct(); }
}
function wp_send_json_error(mixed $d = null): never { throw new JsonExit(false, $d); }
function wp_send_json_success(mixed $d = null): never { throw new JsonExit(true, $d); }

require_once dirname(__DIR__) . '/includes/class-avpvh-address-normalizer.php';
require_once dirname(__DIR__) . '/includes/class-member-merge.php';
require_once dirname(__DIR__) . '/includes/class-member-profile-form.php';

$failures = 0;
function check(string $name, bool $ok): void {
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}
function contact(string $name, string $relation = '', string $phone = '', string $address = ''): object {
    return (object) ['emergency_contact' => $name, 'emergency_relation' => $relation, 'emergency_phone' => $phone, 'emergency_address' => $address];
}
$show = fn(object $m) => AVPVH_Member_Merge::field_display($m, 'emergency_contact');

check('all parts', $show(contact('Piet Voorbeeld', 'vader', '06 00000000', 'Voorbeeldstraat 1, Voorbeeldstad')) === 'Piet Voorbeeld (vader) · 06 00000000 · Voorbeeldstraat 1, Voorbeeldstad');
check('old free text only', $show(contact('Piet 06-00000000')) === 'Piet 06-00000000');
check('empty', $show(contact('')) === '');
check('no name, phone only', $show(contact('', '', '06 00000000')) === '06 00000000');
check('columns missing (old row) still works', $show((object) ['emergency_contact' => 'Piet']) === 'Piet');

AVPVH_DB::$members[7] = (object) ['id' => 7, 'first_name' => 'Jan', 'last_name' => 'Voorbeeld', 'phone' => '010 0000000', 'mobile' => '06 11111111'];
AVPVH_DB::$addresses[7] = (object) ['street' => 'Laan', 'house_number' => '2', 'postal_code' => '1234 AB', 'city' => 'Voorbeeldstad'];
$linked = contact('Oude naam', 'partner', '06 99999999', 'Oud adres');
$linked->emergency_member_id = '7';
check('linked member wins over typed fields', $show($linked) === 'Jan Voorbeeld [lid] (partner) · 06 11111111 · Laan 2, 1234 AB Voorbeeldstad');
AVPVH_DB::$members[7]->mobile = '';
unset(AVPVH_DB::$addresses[7]);
check('linked member: landline, no address', $show($linked) === 'Jan Voorbeeld [lid] (partner) · 010 0000000');
$linked->emergency_member_id = '8';
check('linked member gone: typed fields again', $show($linked) === 'Oude naam (partner) · 06 99999999 · Oud adres');

$form = new AVPVH_Member_Profile_Form();
AVPVH_DB::$members = [
    1 => (object) ['id' => 1, 'wp_user_id' => 101, 'lldap_user_id' => 'jan', 'first_name' => 'Jan', 'last_name' => 'Voorbeeld'],
    2 => (object) ['id' => 2, 'wp_user_id' => 102, 'lldap_user_id' => 'piet', 'first_name' => 'Piet', 'last_name' => 'Voorbeeld'],
    7 => (object) ['id' => 7, 'wp_user_id' => 107, 'lldap_user_id' => 'marie', 'first_name' => 'Marie', 'last_name' => 'Voorbeeld'],
];
AVPVH_DB::$ledenlijst = [AVPVH_DB::$members[1], AVPVH_DB::$members[7]];
AVPVH_DB::$manageable = [AVPVH_DB::$members[1], AVPVH_DB::$members[2]];
$save = function (array $post) use ($form): JsonExit {
    AVPVH_DB::$saved = null;
    $_POST = array_merge(['first_name' => 'Jan', 'last_name' => 'Voorbeeld'], $post);
    try {
        $form->handle_save_profile();
        throw new LogicException('Expected JSON exit');
    } catch (JsonExit $e) {
        return $e;
    }
};

check('own profile: name without phone blocked', !$save(['emergency_contact' => 'Piet'])->ok);
check('housemate profile: name without phone allowed', $save(['member_id' => 2, 'emergency_contact' => 'Piet'])->ok);
check('own profile: linked member needs no typed phone', $save(['emergency_member_id' => 7, 'emergency_contact' => 'Piet'])->ok && AVPVH_DB::$saved['emergency_member_id'] === '7');
check('self cannot be picked as emergency contact', !$save(['emergency_member_id' => 1])->ok);
check('member outside visible ledenlijst blocked', !$save(['emergency_member_id' => 2])->ok);
check('clearing linked member stores null', $save(['emergency_member_id' => ''])->ok && AVPVH_DB::$saved['emergency_member_id'] === null);

echo $failures ? "$failures FAILED\n" : "All passed\n";
exit($failures ? 1 : 0);
