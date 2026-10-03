<?php
/**
 * Read-only comparison of LLDAP and OpenLDAP (PLAN-openldap.md, phase 2):
 * accounts, e-mail, display name and PvH group membership.
 *
 * Prints only counts and avm_members IDs — never uids, names or e-mail
 * addresses, so the output is safe to paste anywhere. Accounts without a
 * member record are only counted.
 *
 * Run inside WordPress (needs the ldap extension and the OpenLDAP secret):
 *   wp eval-file scripts/compare-directories.php
 */

defined('ABSPATH') || exit;

global $wpdb;

function avpvh_cmp_load(AVPVH_Directory_Backend $backend, string $label): array {
    AVPVH_Directory::set_backend($backend);
    $users = AVPVH_Directory::list_users();
    $groups = AVPVH_Directory::get_all_group_memberships();
    AVPVH_Directory::set_backend(null);
    if (is_wp_error($users) || is_wp_error($groups)) {
        $error = is_wp_error($users) ? $users : $groups;
        throw new RuntimeException("$label: " . $error->get_error_message());
    }
    $by_uid = [];
    foreach ($users as $user) {
        $by_uid[strtolower($user['uid'])] = $user;
    }
    $memberships = [];
    foreach ($groups as $uid => $names) {
        $memberships[strtolower((string) $uid)] = AVPVH_Directory::only_pvh_groups(array_map('strtolower', $names));
    }
    return [$by_uid, $memberships];
}

[$lldap_users, $lldap_groups] = avpvh_cmp_load(new AVPVH_Directory_LLDAP(), 'LLDAP');
[$ldap_users, $ldap_groups] = avpvh_cmp_load(new AVPVH_Directory_OpenLDAP(), 'OpenLDAP');

$member_ids = [];
foreach ($wpdb->get_results("SELECT id, lldap_user_id FROM {$wpdb->prefix}avm_members") as $row) {
    $member_ids[strtolower($row->lldap_user_id)] = (int) $row->id;
}

$describe = function (array $uids) use ($member_ids): string {
    $ids = [];
    $without = 0;
    foreach ($uids as $uid) {
        if (isset($member_ids[$uid])) {
            $ids[] = $member_ids[$uid];
        } else {
            $without++;
        }
    }
    sort($ids);
    $parts = [];
    if ($ids) {
        $parts[] = 'members #' . implode(', #', $ids);
    }
    if ($without) {
        $parts[] = "$without without member record";
    }
    return count($uids) . ($parts ? ' (' . implode('; ', $parts) . ')' : '');
};

echo 'Accounts: LLDAP ' . count($lldap_users) . ', OpenLDAP ' . count($ldap_users) . "\n";
echo 'Only in LLDAP: ' . $describe(array_keys(array_diff_key($lldap_users, $ldap_users))) . "\n";
echo 'Only in OpenLDAP: ' . $describe(array_keys(array_diff_key($ldap_users, $lldap_users))) . "\n";

$mail_diff = [];
$name_diff = [];
foreach (array_intersect_key($lldap_users, $ldap_users) as $uid => $user) {
    if (strcasecmp(trim((string) $user['mail']), trim((string) $ldap_users[$uid]['mail'])) !== 0) {
        $mail_diff[] = $uid;
    }
    if (trim((string) $user['display_name']) !== trim((string) $ldap_users[$uid]['display_name'])) {
        $name_diff[] = $uid;
    }
}
echo 'Different e-mail: ' . $describe($mail_diff) . "\n";
echo 'Different display name: ' . $describe($name_diff) . "\n";

$members_without_account = array_diff_key($member_ids, $ldap_users);
echo 'Member records without OpenLDAP account: ' . $describe(array_keys($members_without_account)) . "\n";

echo "\nGroup membership (PvH groups):\n";
$per_group = [];
foreach ([[$lldap_groups, 'lldap'], [$ldap_groups, 'openldap']] as [$memberships, $side]) {
    foreach ($memberships as $uid => $names) {
        foreach ($names as $name) {
            $per_group[$name][$side][$uid] = true;
        }
    }
}
ksort($per_group);
$all_equal = true;
foreach ($per_group as $name => $sides) {
    $l = array_keys($sides['lldap'] ?? []);
    $o = array_keys($sides['openldap'] ?? []);
    $only_l = array_diff($l, $o);
    $only_o = array_diff($o, $l);
    $line = sprintf('  %-15s LLDAP %3d, OpenLDAP %3d', $name, count($l), count($o));
    if ($only_l || $only_o) {
        $all_equal = false;
        $line .= ' | only LLDAP: ' . $describe($only_l) . ' | only OpenLDAP: ' . $describe($only_o);
    }
    echo $line . "\n";
}
echo $all_equal ? "All PvH groups identical.\n" : "Groups differ (see above).\n";
