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
// Stand-in for the two lookups emergency_display() does for a linked member.
class AVPVH_DB {
    public static array $members = [];
    public static array $addresses = [];
    public static function get_member(int $id): ?object {
        return self::$members[$id] ?? null;
    }
    public static function current_address(int $id): ?object {
        return self::$addresses[$id] ?? null;
    }
}

require_once dirname(__DIR__) . '/includes/class-member-merge.php';

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

echo $failures ? "$failures FAILED\n" : "All passed\n";
exit($failures ? 1 : 0);
