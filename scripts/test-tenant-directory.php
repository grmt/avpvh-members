<?php
declare(strict_types=1);

/**
 * Integration test of AVPVH_Tenant_Directory against the throwaway
 * OpenLDAP of scripts/tenant-e2e/directory (fictitious accounts in
 * seed.ldif). Run through that directory's run.sh; it needs the PHP ldap
 * extension and the test server.
 */
define('ABSPATH', __DIR__ . '/');

require_once __DIR__ . '/../includes/tenant/class-tenant-directory.php';

$failures = 0;
function check(string $label, bool $ok, mixed $detail = null): void {
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok || $detail === null ? '' : ' — ' . json_encode($detail)) . "\n";
    if (!$ok) {
        $failures++;
    }
}

$settings = [
    'url'                => 'ldap://openldap:1389',
    'bind_dn'            => 'cn=tenant-reader,dc=nl',
    'bind_password_file' => '/tmp/reader',
    'base_dn'            => 'dc=nl',
    'timeout'            => 5,
];
$d = new AVPVH_Tenant_Directory($settings);

check('the read-only account can bind', $d->reachable());

$r = $d->find_by_email('Lid.Google@Gmail.Example.invalid');
check('member found by e-mail, case-insensitive', $r['status'] === 'ok' && $r['uid'] === 'lid.google' && $r['groups'] === ['vp4042-leden'], $r);
check('display name is returned', ($r['name'] ?? '') === 'Lid Google', $r);
check('the e-mail is returned lowercased', ($r['email'] ?? '') === 'lid.google@gmail.example.invalid', $r);

$r = $d->find_by_email('lid.microsoft@hotmail.example.invalid');
check('member without displayName falls back to cn', $r['status'] === 'ok' && $r['name'] === 'Lid Microsoft', $r);

$r = $d->find_by_email('lid.twee@gmail.example.invalid');
check('a second mail value finds the account too', $r['status'] === 'ok' && $r['uid'] === 'lid.twee', $r);

$r = $d->find_by_email('avp.lid@example.invalid');
check('an account in another OU is found with its tenant groups', $r['status'] === 'ok' && $r['uid'] === 'avp.lid' && $r['groups'] === ['vp4042-leden'], $r);

$r = $d->find_by_email('haakjes@example.invalid');
check('groups are found for a DN with parentheses (escaped)', $r['status'] === 'ok' && $r['groups'] === ['vp4042-leden'], $r);

$r = $d->find_by_email('beheer@example.invalid');
check('an admin gets the admin group', $r['status'] === 'ok' && $r['groups'] === ['rsp-vve-vp4042-admins'], $r);

$r = $d->find_by_email('buiten@example.invalid');
check('an account without groups has none', $r['status'] === 'ok' && $r['groups'] === [], $r);

check('two accounts with one address are ambiguous', $d->find_by_email('dubbel@example.invalid')['status'] === 'ambiguous');
check('an unknown address is none', $d->find_by_email('niemand@example.invalid')['status'] === 'none');
check('no address is none', $d->find_by_email('')['status'] === 'none');
check('a wildcard is matched literally', $d->find_by_email('*@example.invalid')['status'] === 'none');
check('filter syntax in the address gets nowhere', $d->find_by_email('lid.google@gmail.example.invalid)(uid=*')['status'] === 'none');

$r = $d->find_by_uid('Lid.Google');
check('find by uid, case-insensitive', $r['status'] === 'ok' && $r['uid'] === 'lid.google' && $r['groups'] === ['vp4042-leden'], $r);
check('find by uid: unknown is none', $d->find_by_uid('niemand')['status'] === 'none');
check('find by uid: wildcard is not a uid', $d->find_by_uid('*')['status'] === 'none');
check('find by uid: account without mail still works', $d->find_by_uid('zonder.mail')['status'] === 'ok');

$wrong = new AVPVH_Tenant_Directory(['bind_password_file' => '/tmp/wrong'] + $settings);
check('wrong password: not reachable', !$wrong->reachable());
check('wrong password: lookups answer error', $wrong->find_by_email('lid.google@gmail.example.invalid')['status'] === 'error');

$missing = new AVPVH_Tenant_Directory(['bind_password_file' => '/tmp/bestaat-niet'] + $settings);
check('missing password file: error, no anonymous bind', $missing->find_by_email('lid.google@gmail.example.invalid')['status'] === 'error');

$start = microtime(true);
$down = new AVPVH_Tenant_Directory(['url' => 'ldap://10.255.255.1:1389', 'timeout' => 2] + $settings);
$status = $down->find_by_email('lid.google@gmail.example.invalid')['status'];
check('unreachable server: error within the timeout', $status === 'error' && microtime(true) - $start < 6, ['status' => $status, 'seconds' => round(microtime(true) - $start, 1)]);

echo $failures ? "\n$failures failure(s)\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
