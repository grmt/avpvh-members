#!/bin/bash
# End-to-end test of the members section on the AVP-PvH site (no tenant
# file): /leden/ and below must stay hidden from REST items and
# collections, search and oEmbed for visitors, while editors still see
# them and the plugin's own lookups (the front-end gate) keep working.
# Reuses the tenant e2e containers; needs Docker and port 8088.
#
#   scripts/tenant-e2e/members-section.sh
cd "$(dirname "$0")"
set -uo pipefail
B=http://localhost:8088
W="docker compose exec -T cli wp"
trap 'docker compose down -v >/dev/null 2>&1' EXIT
docker compose up -d >/dev/null 2>&1
for _ in $(seq 1 60); do curl -s -o /dev/null "$B/" && $W db query 'SELECT 1' >/dev/null 2>&1 && break; sleep 3; done
$W core install --url=$B --title=AVP-test --admin_user=beheer --admin_password=x --admin_email=b@example.invalid --skip-email >/dev/null
$W config set WP_ENVIRONMENT_TYPE local >/dev/null
$W rewrite structure '/%postname%/' >/dev/null
$W plugin activate avpvh-members >/dev/null 2>&1
L=$($W post create --post_type=page --post_title=Leden --post_name=leden --post_status=publish --porcelain)
N=$($W post create --post_type=page --post_title=Notulen --post_name=notulen --post_parent="$L" --post_status=publish --post_content='GEHEIM-NOTULEN' --porcelain)
$W post create --post_type=page --post_title='Over ons' --post_name=over-ons --post_status=publish --post_content='PUBLIEKE-TEKST' --porcelain >/dev/null
# The application password lets the test act as an editor over REST.
APP=$($W user application-password create beheer e2e --porcelain)
fail=0
ok() {
    local label=$1 check=$2
    if eval "$check"; then echo "ok   $label"; else echo "FAIL $label"; fail=1; fi
    return 0
}
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; return 0; }
ok "front end /leden/notulen/ redirects" '[[ "$(code $B/leden/notulen/)" =~ 30[12] ]]'
ok "REST item 404" '[[ "$(code $B/wp-json/wp/v2/pages/$N)" == 404 ]]'
ok "REST item of root 404" '[[ "$(code $B/wp-json/wp/v2/pages/$L)" == 404 ]]'
ok "REST list hides" '[[ "$(curl -s "$B/wp-json/wp/v2/pages?per_page=50")" != *notulen* ]]'
ok "REST list by parent empty" '[[ "$(curl -s "$B/wp-json/wp/v2/pages?parent=$L")" == "[]" ]]'
ok "REST list shows public" '[[ "$(curl -s "$B/wp-json/wp/v2/pages?per_page=50")" == *over-ons* ]]'
ok "REST search hides" '[[ "$(curl -s "$B/wp-json/wp/v2/search?search=notulen")" == "[]" ]]'
# Only the result titles count: the test theme's page list shows every page.
hits() {
    local term=$1
    curl -s "$B/?s=$term" | grep -o 'wp-block-post-title[^>]*><a href="[^"]*"'
    return 0
}
ok "site search hides" '[[ "$(hits notulen)" != *"/leden/"* && "$(curl -s "$B/?s=GEHEIM")" != *GEHEIM-NOTULEN* ]]'
ok "site search shows public" '[[ "$(hits PUBLIEKE)" == *"/over-ons/"* ]]'
ok "oEmbed hides" '[[ "$(code "$B/wp-json/oembed/1.0/embed?url=$B/leden/notulen/")" == 404 ]]'
ok "oEmbed public works" '[[ "$(code "$B/wp-json/oembed/1.0/embed?url=$B/over-ons/")" == 200 ]]'
ok "editor reads REST item" '[[ "$(curl -s -u "beheer:$APP" "$B/wp-json/wp/v2/pages/$N?context=edit")" == *GEHEIM-NOTULEN* ]]'
ok "editor REST list includes" '[[ "$(curl -s -u "beheer:$APP" "$B/wp-json/wp/v2/pages?parent=$L")" == *notulen* ]]'
ok "wp-cli still lists leden pages" '[[ "$($W post list --post_type=page --field=post_name | tr "\n" " ")" == *notulen* ]]'
ok "no PHP errors on requests" '[[ -z "$(docker compose logs wp 2>&1 | grep -iE "fatal|php warning|php notice")" ]]'
exit $fail
