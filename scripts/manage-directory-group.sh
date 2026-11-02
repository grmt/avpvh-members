#!/usr/bin/env bash
# Manage directory (OpenLDAP) group membership for a member, via the
# plugin's own AVPVH_Directory (same code path as Ledendetail and the
# "Zoeken in documenten" boek-group gate).
#
# Run ON the server (avpvh.nl), as grmt. Uses the wpcli-pvh compose service:
# a plain `docker exec` into wordpress-pvh can't reach the database (its DB
# password is a docker secret only the container's entrypoint reads).
#
# Usage:
#   ./scripts/manage-directory-group.sh list   <group>
#   ./scripts/manage-directory-group.sh add    <uid> <group>
#   ./scripts/manage-directory-group.sh remove <uid> <group>
#
# Examples:
#   ./scripts/manage-directory-group.sh list boek
#   ./scripts/manage-directory-group.sh remove test.member boek
#
# add and remove also clear the plugin's group caches, so WordPress shows
# the change straight away.

set -euo pipefail

COMPOSE_DIR="${AVPVH_COMPOSE_DIR:-/opt/docker/scripts}"

usage() {
    echo "Usage:"
    echo "  $0 list   <group>"
    echo "  $0 add    <uid> <group>"
    echo "  $0 remove <uid> <group>"
    exit 1
}

# Arguments travel as environment variables, never inside the PHP code.
wp_eval() {
    (cd "$COMPOSE_DIR" && docker compose run --rm --no-deps -T \
        -e AVPVH_UID="${uid:-}" -e AVPVH_GROUP="${group:-}" -e AVPVH_ACTION="${action:-}" \
        wpcli-pvh wp eval "$1")
}

[[ $# -ge 1 ]] || usage
action="$1"

case "$action" in
    list)
        [[ $# -eq 2 ]] || usage
        group="$2"
        wp_eval '
            $group = strtolower(getenv("AVPVH_GROUP"));
            $m = AVPVH_Directory::get_all_group_memberships();
            if (is_wp_error($m)) { fwrite(STDERR, $m->get_error_message() . "\n"); exit(1); }
            foreach ($m as $uid => $groups) {
                if (in_array($group, $groups, true)) {
                    $user = AVPVH_Directory::get_user($uid);
                    echo $uid . ($user && $user["display_name"] !== "" ? " (" . $user["display_name"] . ")" : "") . "\n";
                }
            }
        '
        ;;
    add|remove)
        [[ $# -eq 3 ]] || usage
        uid="$2"
        group="$3"
        wp_eval '
            $uid = getenv("AVPVH_UID");
            $group = getenv("AVPVH_GROUP");
            $result = getenv("AVPVH_ACTION") === "add"
                ? AVPVH_Directory::add_to_group($uid, $group)
                : AVPVH_Directory::remove_from_group($uid, $group);
            if (is_wp_error($result)) { fwrite(STDERR, "FAILED: " . $result->get_error_message() . "\n"); exit(1); }
            AVPVH_Directory::forget_groups($uid);
            echo getenv("AVPVH_ACTION") . " $uid $group: ok\n";
        '
        ;;
    *)
        usage
        ;;
esac
