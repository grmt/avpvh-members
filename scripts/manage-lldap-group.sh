#!/usr/bin/env bash
# Manage LLDAP group membership for a member, via the plugin's own
# AVPVH_LLDAP class (same code path the "Zoeken in documenten" boek-group
# gate and the profile page's "Groepen:" display use).
#
# Run ON the server (avpvh.nl), as grmt. Does NOT docker exec into the live
# wordpress-pvh container -- that fails with "Error establishing a database
# connection" because the DB password is a docker secret only the
# container's own entrypoint reads at startup, not inherited by a fresh
# `docker exec` shell. Uses the dedicated wpcli-pvh compose service instead,
# which has the right DB credentials baked in.
#
# Usage:
#   ./scripts/manage-lldap-group.sh list   <group>
#   ./scripts/manage-lldap-group.sh add    <lldap_uid> <group>
#   ./scripts/manage-lldap-group.sh remove <lldap_uid> <group>
#
# Examples:
#   ./scripts/manage-lldap-group.sh list boek
#   ./scripts/manage-lldap-group.sh remove grmt boek
#
# After a remove/add, the change is live in LLDAP immediately, but WordPress
# caches group membership in transients for up to 15 minutes
# (avpvh_lldap_groups_<uid> per-user, avpvh_all_group_memberships for the
# ledenlijst view) -- clear-caches below does that for you.

set -euo pipefail

COMPOSE_DIR=/opt/docker/scripts

usage() {
    echo "Usage:"
    echo "  $0 list   <group>"
    echo "  $0 add    <lldap_uid> <group>"
    echo "  $0 remove <lldap_uid> <group>"
    echo "  $0 clear-cache <lldap_uid>"
    exit 1
}

wp_eval() {
    (cd "$COMPOSE_DIR" && docker compose -f docker-compose.yml run --rm --no-deps wpcli-pvh wp eval "$1")
}

[[ $# -ge 1 ]] || usage

case "$1" in
    list)
        [[ $# -eq 2 ]] || usage
        group="$2"
        wp_eval '
            $m = AVPVH_LLDAP::get_all_group_memberships();
            if (is_wp_error($m)) { fwrite(STDERR, $m->get_error_message()."\n"); exit(1); }
            foreach ($m as $uid => $groups) {
                if (in_array("'"$group"'", $groups, true)) {
                    $name = AVPVH_LLDAP::get_user_display_name($uid);
                    echo $uid . ($name ? " ($name)" : "") . "\n";
                }
            }
        '
        ;;
    add|remove)
        [[ $# -eq 3 ]] || usage
        uid="$2"
        group="$3"
        method=$([[ "$1" == "add" ]] && echo add_to_group || echo remove_from_group)
        wp_eval '
            $groups = AVPVH_LLDAP::list_groups();
            if (is_wp_error($groups)) { fwrite(STDERR, $groups->get_error_message()."\n"); exit(1); }
            $group_id = null;
            foreach ($groups as $g) { if (strtolower($g["displayName"]) === strtolower("'"$group"'")) { $group_id = (int)$g["id"]; break; } }
            if (!$group_id) { fwrite(STDERR, "group \"'"$group"'\" not found\n"); exit(1); }

            $result = AVPVH_LLDAP::'"$method"'("'"$uid"'", $group_id);
            if (is_wp_error($result)) { fwrite(STDERR, "FAILED: ".$result->get_error_message()."\n"); exit(1); }
            echo "'"$1"' '"$uid"' '"$group"' (group id $group_id): " . json_encode($result) . "\n";
        '
        echo "Note: run '\''$0 clear-cache $uid'\'' so WordPress reflects this immediately (otherwise up to 15 min cache delay)."
        ;;
    clear-cache)
        [[ $# -eq 2 ]] || usage
        uid="$2"
        wp_eval '
            $r1 = delete_transient("avpvh_lldap_groups_'"$uid"'");
            $r2 = delete_transient("avpvh_all_group_memberships");
            echo "per-user cache cleared: " . ($r1 ? "yes" : "no (already absent)") . "\n";
            echo "ledenlijst cache cleared: " . ($r2 ? "yes" : "no (already absent)") . "\n";
        '
        ;;
    *)
        usage
        ;;
esac
