<?php
/**
 * Standalone test for AVPVH_Directory_OpenLDAP against a throwaway OpenLDAP
 * with the live layout (dc=nl / ou=avpvh, ACL + cn=avpvh-admin + cn=vacant
 * created by docker-scripts openldap-avpvh-admin.sh). Never point this at the
 * live directory: it creates, changes and deletes test accounts.
 *
 * Expects fictional seed data: people anna.voorbeeld, bert.test, carla.proef;
 * groups leden, bestuur, voorzitter, secretaris, penningmeester, boek, each
 * with anna.voorbeeld as only member.
 *
 * Run (PHP with the ldap extension, network access to the test server):
 *   AVPVH_LDAP_URL=ldap://openldap:1389 AVPVH_LDAP_PASSWORD_FILE=/secret \
 *     php scripts/test-directory-openldap.php
 */

define('ABSPATH', __DIR__ . '/');
define('AVPVH_LDAP_URL', getenv('AVPVH_LDAP_URL') ?: 'ldap://openldap:1389');
define('AVPVH_LDAP_PASSWORD_FILE', getenv('AVPVH_LDAP_PASSWORD_FILE') ?: '/secret');

// Minimal WordPress stand-ins — just enough for includes/class-directory.php.
if (!class_exists('WP_Error')) {
    class WP_Error {
        public function __construct(public string $code = '', public string $message = '', public $data = null) {}
        public function get_error_message(): string { return $this->message; }
        public function get_error_data() { return $this->data; }
    }
}
function is_wp_error($thing): bool { return $thing instanceof WP_Error; }
function apply_filters(string $hook, $value) { return $value; }

require __DIR__ . '/../includes/class-directory.php';

$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok || $detail === '' ? '' : " — {$detail}") . "\n";
    if (!$ok) {
        $failures++;
    }
}
function err($r): string { return is_wp_error($r) ? $r->get_error_message() : var_export($r, true); }
/** uids holding $group in a get_all_group_memberships() result ([] on error). */
function holders($map, string $group): array {
    return is_array($map) ? array_keys(array_filter($map, static fn($g) => in_array($group, $g, true))) : [];
}

$d = new AVPVH_Directory_OpenLDAP();

echo "== connection & reading\n";
check('test_connection', ($r = $d->test_connection()) === true, err($r));
$groups = $d->list_groups();
check('list_groups has the 6 seed groups', !is_wp_error($groups) && !array_diff(['leden', 'bestuur', 'voorzitter', 'secretaris', 'penningmeester', 'boek'], $groups), err($groups));
$anna = $d->get_user('anna.voorbeeld');
check('get_user existing', $anna !== null && $anna['mail'] === 'anna.voorbeeld@example.invalid' && $anna['display_name'] === 'Test anna', var_export($anna, true));
check('get_user is case-insensitive', $d->get_user('Anna.Voorbeeld') !== null);
check('get_user missing → null', $d->get_user('bestaat.niet') === null);
$ag = $d->get_user_groups('anna.voorbeeld');
check('get_user_groups anna = all 6', !is_wp_error($ag) && count($ag) === 6, err($ag));
check('get_user_groups bert = none', $d->get_user_groups('bert.test') === []);

echo "== accounts\n";
check('create_user', ($r = $d->create_user('dora.nieuw', 'dora@example.invalid', 'Dora van Nieuw')) === true, err($r));
$dora = $d->get_user('dora.nieuw');
check('created account readable', $dora !== null && $dora['display_name'] === 'Dora van Nieuw' && $dora['mail'] === 'dora@example.invalid', var_export($dora, true));
check('create duplicate → error', is_wp_error($d->create_user('dora.nieuw', 'x@example.invalid', 'X')));
check('create without mail', ($r = $d->create_user('eva.zondermail', '', 'Eva Zondermail')) === true, err($r));
check('update display_name + mail', ($r = $d->update_user('dora.nieuw', ['display_name' => 'Dora Nieuw-Ander', 'mail' => 'dora2@example.invalid'])) === true, err($r));
$dora = $d->get_user('dora.nieuw');
check('update visible', $dora && $dora['display_name'] === 'Dora Nieuw-Ander' && $dora['mail'] === 'dora2@example.invalid', var_export($dora, true));
check('clear mail', ($r = $d->update_user('dora.nieuw', ['mail' => ''])) === true && ($d->get_user('dora.nieuw')['mail'] ?? 'x') === '', err($r));
check('clear mail when already empty', ($r = $d->update_user('eva.zondermail', ['mail' => ''])) === true, err($r));

echo "== groups\n";
check('add bert to bestuur', ($r = $d->add_to_group('bert.test', 'bestuur')) === true, err($r));
check('add again is a no-op', ($r = $d->add_to_group('bert.test', 'bestuur')) === true, err($r));
$bg = $d->get_user_groups('bert.test');
check('bert now in bestuur', is_array($bg) && in_array('bestuur', $bg, true), err($bg));
check('add to missing group → error', is_wp_error($d->add_to_group('bert.test', 'bestaat-niet')));
check('remove non-member is a no-op', ($r = $d->remove_from_group('carla.proef', 'bestuur')) === true, err($r));

echo "== vacant bestuursfunctie (groupOfNames placeholder)\n";
check('remove last member of voorzitter', ($r = $d->remove_from_group('anna.voorbeeld', 'voorzitter')) === true, err($r));
$map = $d->get_all_group_memberships();
$holders = holders($map, 'voorzitter');
check('voorzitter has no holder (placeholder ignored)', is_array($map) && $holders === [], err($map));
check('placeholder never appears as a uid', is_array($map) && !isset($map['vacant']));
check('appoint bert as voorzitter', ($r = $d->add_to_group('bert.test', 'voorzitter')) === true, err($r));
$map = $d->get_all_group_memberships();
$holders = holders($map, 'voorzitter');
check('voorzitter = bert only', $holders === ['bert.test'], var_export($holders, true));
check('vacate again via bert', ($r = $d->remove_from_group('bert.test', 'voorzitter')) === true, err($r));
check('and re-fill with anna', ($r = $d->add_to_group('anna.voorbeeld', 'voorzitter')) === true, err($r));

echo "== delete\n";
$d->add_to_group('dora.nieuw', 'leden');
$d->add_to_group('dora.nieuw', 'boek');
check('delete_user (in 2 groups)', ($r = $d->delete_user('dora.nieuw')) === true, err($r));
check('deleted account gone', $d->get_user('dora.nieuw') === null);
$map = $d->get_all_group_memberships();
check('deleted account in no group', is_array($map) && !isset($map['dora.nieuw']));
check('delete missing account is a no-op', ($r = $d->delete_user('dora.nieuw')) === true, err($r));
$d->delete_user('eva.zondermail');
$d->remove_from_group('bert.test', 'bestuur');

echo "== listing (paging past the 500 size limit)\n";
$users = $d->list_users();
$n = is_wp_error($users) ? -1 : count($users);
check('list_users returns every account (' . $n . ')', $n === (int) (getenv('EXPECTED_USERS') ?: 3), is_wp_error($users) ? err($users) : 'got ' . $n);
check('list_users entries are complete', !is_wp_error($users) && !array_filter($users, static fn($u) => $u['uid'] === ''));

echo "== facade helpers\n";
check('only_pvh_groups filters', AVPVH_Directory::only_pvh_groups(['leden', 'vp4042-leden', 'lldap_admin', 'boek']) === ['leden', 'boek']);

echo $failures ? "\n{$failures} FAILED\n" : "\nall directory tests passed\n";
exit($failures ? 1 : 0);
