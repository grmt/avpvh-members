#!/usr/bin/env bash
# Manage a temporary OAuth test user in LLDAP + WordPress member DB.
#
# Usage:
#   test-user.sh add  <user_id> <email> <first_name> <last_name>
#   test-user.sh remove <user_id>
#
# Example:
#   test-user.sh add garmt.noname garmt.noname@gmail.com Garmt "Noname (test)"
#   test-user.sh remove garmt.noname

set -euo pipefail

CONTAINER="scripts-mysql-1"

db() {
    local database="$1"; shift
    local root_pw
    root_pw=$(docker exec "$CONTAINER" cat /run/secrets/mysql_root_password)
    docker exec "$CONTAINER" mariadb -u root -p"${root_pw}" "$database" -e "$@" 2>/dev/null
}

cmd="${1:-}"

case "$cmd" in
    add)
        user_id="${2:?user_id required}"
        email="${3:?email required}"
        first_name="${4:?first_name required}"
        last_name="${5:?last_name required}"

        db lldap "
            INSERT INTO users (user_id, email, lowercase_email, display_name, creation_date, modified_date, password_modified_date, uuid)
            VALUES ('${user_id}', '${email}', LOWER('${email}'), '${first_name} ${last_name}', NOW(), NOW(), NOW(), UUID())
            ON DUPLICATE KEY UPDATE email=email;
        "
        db wpdb "
            INSERT INTO pvh_avm_members (lldap_user_id, first_name, last_name, status)
            VALUES ('${user_id}', '${first_name}', '${last_name}', 'active')
            ON DUPLICATE KEY UPDATE first_name=first_name;
        "
        echo "Test user '${user_id}' (${email}) added."
        ;;

    remove)
        user_id="${2:?user_id required}"

        # Remove WP user if linked
        wp_user_id=$(db wpdb "SELECT wp_user_id FROM pvh_avm_members WHERE lldap_user_id='${user_id}';" | tail -1)
        db wpdb "DELETE FROM pvh_avm_members WHERE lldap_user_id='${user_id}';"
        db lldap "DELETE FROM users WHERE user_id='${user_id}';"

        if [[ -n "$wp_user_id" && "$wp_user_id" != "wp_user_id" && "$wp_user_id" != "NULL" ]]; then
            echo "Note: WordPress user ID ${wp_user_id} was linked — remove manually via WP admin if needed."
        fi

        echo "Test user '${user_id}' removed."
        ;;

    *)
        echo "Usage: $0 add <user_id> <email> <first_name> <last_name>"
        echo "       $0 remove <user_id>"
        exit 1
        ;;
esac
