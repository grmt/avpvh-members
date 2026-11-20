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
$avpvh_env_cmd = getenv('AVPVH_BRIDGE_CMD');
$avpvh_bridge_command = $avpvh_env_cmd !== false && $avpvh_env_cmd !== ''
    ? (string) $avpvh_env_cmd
    : (string) array_shift($avpvh_bridge_args);

/** @param true|\WP_Error $result */
function avpvh_bridge_done($result): void {
    if (is_wp_error($result)) {
        fwrite(STDERR, $result->get_error_message() . "\n");
        exit(1);
    }
    echo "ok\n";
}

$avpvh_bridge_param = static function (string $env_key, int $index) use ($avpvh_bridge_args): string {
    $env_val = getenv($env_key);
    if ($env_val !== false) {
        return (string) $env_val;
    }
    return (string) ($avpvh_bridge_args[$index] ?? '');
};

switch ($avpvh_bridge_command) {
    case 'exists':
        $uid = $avpvh_bridge_param('AVPVH_BRIDGE_UID', 0);
        echo AVPVH_Directory::user_exists($uid) ? "yes\n" : "no\n";
        break;
    case 'create':
        $uid = $avpvh_bridge_param('AVPVH_BRIDGE_UID', 0);
        $mail = $avpvh_bridge_param('AVPVH_BRIDGE_MAIL', 1);
        $display_name = $avpvh_bridge_param('AVPVH_BRIDGE_NAME', 2);
        avpvh_bridge_done(AVPVH_Directory::create_user($uid, $mail, $display_name));
        break;
    case 'add-to-group':
        $uid = $avpvh_bridge_param('AVPVH_BRIDGE_UID', 0);
        $group = $avpvh_bridge_param('AVPVH_BRIDGE_GROUP', 1);
        if (!AVPVH_Directory::is_pvh_group($group)) {
            fwrite(STDERR, "group not allowed: {$group}\n");
            exit(1);
        }
        $res = AVPVH_Directory::add_to_group($uid, $group);
        if (!is_wp_error($res)) {
            AVPVH_Directory::forget_groups($uid);
        }
        avpvh_bridge_done($res);
        break;
    default:
        fwrite(STDERR, "usage: exists <uid> | create <uid> <mail> <displayName> | add-to-group <uid> <group>\n");
        exit(1);
}
