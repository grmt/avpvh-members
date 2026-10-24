<?php
declare(strict_types=1);

/**
 * Checks AVPVH_Member_Merge::resolve_email() / real_email(): which address the
 * kept member ends up with and which one is kept as an extra login.
 *
 * Run:
 *   php scripts/test-member-merge-email.php
 */

define('ABSPATH', __DIR__ . '/');

function __(string $text, string $domain = ''): string {
    return $text;
}
function is_email(string $email): bool {
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}
class WP_Error {
    public function __construct(public string $code = '', public string $message = '') {}
}
function is_wp_error(mixed $thing): bool {
    return $thing instanceof WP_Error;
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

$both = ['keep' => 'old@example.invalid', 'remove' => 'new@example.invalid', 'default' => 'keep'];

check('placeholder is not a real address', AVPVH_Member_Merge::real_email('u123@AVPVH.local') === '');
check('real address passes', AVPVH_Member_Merge::real_email(' a@example.invalid ') === 'a@example.invalid');

check('default keeps address, duplicate becomes extra login',
    AVPVH_Member_Merge::resolve_email($both, []) === ['primary' => 'old@example.invalid', 'extra' => 'new@example.invalid']);
check('keep without extra',
    AVPVH_Member_Merge::resolve_email($both, ['choice' => 'keep', 'add_other' => false]) === ['primary' => 'old@example.invalid', 'extra' => '']);
check('take duplicate address: no extra login of the same address',
    AVPVH_Member_Merge::resolve_email($both, ['choice' => 'remove', 'add_other' => true]) === ['primary' => 'new@example.invalid', 'extra' => '']);
check('corrected address, typo dropped',
    AVPVH_Member_Merge::resolve_email($both, ['choice' => 'custom', 'custom' => ' Fixed@Example.invalid ', 'add_other' => false]) === ['primary' => 'fixed@example.invalid', 'extra' => '']);
check('empty corrected address is refused',
    is_wp_error(AVPVH_Member_Merge::resolve_email($both, ['choice' => 'custom', 'custom' => ''])));
check('invalid corrected address is refused',
    is_wp_error(AVPVH_Member_Merge::resolve_email($both, ['choice' => 'custom', 'custom' => 'not-an-address'])));
check('placeholder as corrected address is refused',
    is_wp_error(AVPVH_Member_Merge::resolve_email($both, ['choice' => 'custom', 'custom' => 'u1@avpvh.local'])));

$keep_empty = ['keep' => '', 'remove' => 'new@example.invalid', 'default' => 'remove'];
check('kept member without address takes the duplicate one by default',
    AVPVH_Member_Merge::resolve_email($keep_empty, []) === ['primary' => 'new@example.invalid', 'extra' => '']);
$none = ['keep' => '', 'remove' => '', 'default' => 'keep'];
check('neither has an address: nothing changes',
    AVPVH_Member_Merge::resolve_email($none, ['choice' => 'remove']) === ['primary' => '', 'extra' => '']);
check('same address in other case: no extra login',
    AVPVH_Member_Merge::resolve_email(['keep' => 'a@example.invalid', 'remove' => 'A@example.invalid', 'default' => 'keep'], [])['extra'] === '');

echo $failures ? "$failures FAILED\n" : "All passed\n";
exit($failures ? 1 : 0);
