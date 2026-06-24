#!/usr/bin/env bash
# Backfill primary email identities for existing members from LLDAP.
#
# Usage:
#   ./scripts/backfill-member-identities.sh

set -euo pipefail

CONTAINER="scripts-mysql-1"

db() {
    local database="$1"; shift
    local root_pw
    root_pw=$(docker exec "$CONTAINER" cat /run/secrets/mysql_root_password)
    docker exec "$CONTAINER" mariadb -u root -p"${root_pw}" "$database" -e "$@" 2>/dev/null
}

db wpdb "
    INSERT INTO pvh_avm_member_identities (member_id, provider, email, is_primary)
    SELECT m.id, 'email', u.email, 1
    FROM pvh_avm_members m
    JOIN lldap.users u ON u.user_id = m.lldap_user_id
    WHERE u.email <> ''
    ON DUPLICATE KEY UPDATE
        email = VALUES(email),
        is_primary = 1;
"

echo "Backfilled primary identities from LLDAP."
