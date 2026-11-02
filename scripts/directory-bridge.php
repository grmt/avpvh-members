<?php
/**
 * Directory (OpenLDAP) access for the Python member scripts, through the
 * plugin's own AVPVH_Directory, so new accounts land in the plugin's cache
 * too. The scripts pipe this file into wp-cli (see _avpvh_import_common.py):
 *
 *   docker compose run --rm -T wpcli-pvh wp eval-file - <command> [args] < scripts/directory-bridge.php
 *
 * Commands (errors on stderr with exit status 1):
 *   exists <uid>                      prints "yes" or "no"
 *   create <uid> <mail> <displayName> creates the account, prints "ok"
 *   add-to-group <uid> <group>        adds it to a group (by name), prints "ok"
 */

defined('ABSPATH') || exit(1);

/** @var string[] $args set by wp eval-file */
$avpvh_bridge_args = isset($args) ? array_values((array) $args) : [];
$avpvh_bridge_command = (string) array_shift($avpvh_bridge_args);

/** @param true|\WP_Error $result */
function avpvh_bridge_done($result): void {
    if (is_wp_error($result)) {
        fwrite(STDERR, $result->get_error_message() . "\n");
        exit(1);
    }
    echo "ok\n";
}

switch ($avpvh_bridge_command) {
    case 'exists':
        [$uid] = $avpvh_bridge_args + [''];
        echo AVPVH_Directory::user_exists((string) $uid) ? "yes\n" : "no\n";
        break;
    case 'create':
        [$uid, $mail, $display_name] = $avpvh_bridge_args + ['', '', ''];
        avpvh_bridge_done(AVPVH_Directory::create_user((string) $uid, (string) $mail, (string) $display_name));
        break;
    case 'add-to-group':
        [$uid, $group] = $avpvh_bridge_args + ['', ''];
        avpvh_bridge_done(AVPVH_Directory::add_to_group((string) $uid, (string) $group));
        break;
    default:
        fwrite(STDERR, "usage: exists <uid> | create <uid> <mail> <displayName> | add-to-group <uid> <group>\n");
        exit(1);
}
