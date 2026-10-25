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

echo $failures ? "$failures FAILED\n" : "All passed\n";
exit($failures ? 1 : 0);
