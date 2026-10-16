#!/bin/bash
# Integration test of AVPVH_Tenant_Directory against a throwaway OpenLDAP
# with fictitious accounts (seed.ldif). Needs Docker; removes everything
# afterwards (KEEP=1 leaves it running).
#
#   scripts/tenant-e2e/directory/run.sh
#
# AVPVH_TEST_LDAP_SILENT_URL overrides the server that never answers
# (default ldaps://192.0.2.1:1636) for the timeout check.
set -uo pipefail
cd "$(dirname "$0")"
[[ -n "${KEEP:-}" ]] || trap 'docker compose down -v >/dev/null 2>&1' EXIT
docker compose up -d >/dev/null 2>&1
docker compose exec -T php sh -c 'apt-get -qq update >/dev/null 2>&1 && apt-get -qq install -y libldap2-dev >/dev/null 2>&1 && docker-php-ext-install ldap >/dev/null 2>&1; printf lees-alleen-test > /tmp/reader; printf fout > /tmp/wrong'
for _ in $(seq 1 60); do
    docker compose exec -T openldap ldapsearch -x -H ldap://localhost:1389 -b dc=nl -s base dn >/dev/null 2>&1 && break
    sleep 2
done
docker compose exec -T -e AVPVH_TEST_LDAP_SILENT_URL="${AVPVH_TEST_LDAP_SILENT_URL:-}" php php scripts/test-tenant-directory.php
