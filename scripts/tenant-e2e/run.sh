#!/bin/bash
# End-to-end test of the Rechtspreker tenant layer against a real
# WordPress (block theme), with a fake Authelia auth-request endpoint
# (authz.php) that knows three fictitious accounts:
#   member   → vp4042-leden      vveadmin → rsp-vve-admins
#   outsider → boek (no access to this tenant)
# Needs Docker; uses port 8088 on 127.0.0.1 and removes everything after.
#
#   scripts/tenant-e2e/run.sh
set -uo pipefail
cd "$(dirname "$0")"
B=http://localhost:8088
W="docker compose exec -T cli wp"
# KEEP=1 leaves the environment running for debugging.
[[ -n "${KEEP:-}" ]] || trap 'docker compose down -v >/dev/null 2>&1' EXIT

docker compose up -d >/dev/null 2>&1
for _ in $(seq 1 60); do
    curl -s -o /dev/null "$B/" && $W db query 'SELECT 1' >/dev/null 2>&1 && break
    sleep 3
done
docker compose cp avpvh-tenant.php wp:/var/www/html/wp-content/avpvh-tenant.php >/dev/null 2>&1
docker compose exec -T wp sh -c 'chown www-data: /var/www/html/wp-content/avpvh-tenant.php
    mkdir -p /var/www/html/wp-content/uploads/private/delen
    printf DEELBESTAND-INHOUD > "/var/www/html/wp-content/uploads/private/delen/test bestand.zip"
    chown -R www-data: /var/www/html/wp-content/uploads'
$W core install --url=$B --title=VP-test --admin_user=noodbeheer --admin_password="$(head -c 18 /dev/urandom | base64)" \
    --admin_email=nood@example.invalid --skip-email >/dev/null
L=$($W post create --post_type=page --post_title=Leden --post_name=leden --post_status=publish --porcelain)
$W post create --post_type=page --post_title=Notulen --post_name=notulen --post_parent="$L" --post_status=publish \
    --post_content='GEHEIM-NOTULEN' --import_id=5 --porcelain >/dev/null
P=$($W post create --post_type=page --post_title='Over ons' --post_name=over-ons --post_status=publish \
    --post_content='PUBLIEKE-TEKST' --porcelain)
$W post create --post_type=wp_navigation --post_title=Hoofdmenu --post_status=publish --porcelain \
    --post_content="<!-- wp:navigation-link {\"label\":\"Over ons\",\"type\":\"page\",\"id\":$P,\"url\":\"/over-ons/\",\"kind\":\"post-type\"} /--><!-- wp:navigation-link {\"label\":\"Leden\",\"type\":\"page\",\"id\":$L,\"url\":\"/leden/\",\"kind\":\"post-type\"} /-->" >/dev/null
$W rewrite structure '/%postname%/' >/dev/null
# Rollout step 1: check the configuration with the plugin still inactive.
hc=$($W eval-file wp-content/plugins/avpvh-members/includes/tenant/healthcheck.php 2>&1); hc_exit=$?
$W plugin activate avpvh-members >/dev/null

SJ=$(mktemp)
fail=0
ok() { if eval "$2"; then echo "ok   $1"; else echo "FAIL $1"; fail=1; fi; }
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
loc() { curl -s -o /dev/null -w '%{redirect_url}' "$@"; }
ok "healthcheck passes while the plugin is inactive" '[[ $hc_exit == 0 && "$hc" == *"Configuratie geldig"* && "$hc" == *": 401"* ]]'
# --- anonymous
home=$(curl -s $B/)
ok "home shows public page in nav" '[[ "$home" == *"Over ons"* ]]'
ok "home hides Leden from nav" '[[ "$home" != *">Leden<"* ]]'
ok "home offers Inloggen via Authelia" '[[ "$home" == *">Inloggen</a>"* && "$home" == *"auth.example.invalid/?rd="* ]]'
ok "account link only in the header navigation" '[[ "$(grep -o avpvh-account-item <<<"$home" | wc -l)" == 1 ]]'
ok "protected child redirects visitors to Authelia" '[[ "$(loc $B/leden/notulen/)" == https://auth.example.invalid/?rd=* ]]'
ok "protected content never in body" '[[ "$(curl -s $B/leden/notulen/)" != *GEHEIM* ]]'
ok "spoofed Remote-User/Groups headers do not help" '[[ "$(code -H "Remote-User: test.vve" -H "Remote-Groups: vp4042-leden" $B/leden/notulen/)" == 302 ]]'
ok "search hides protected pages" '[[ "$(curl -s "$B/?s=GEHEIM")" != *GEHEIM-NOTULEN* && "$(curl -s "$B/?s=notulen")" != *"/leden/notulen/"* && "$(curl -s "$B/?s=PUBLIEKE")" == *"/over-ons/"* ]]'
ok "REST list hides protected pages" '[[ "$(curl -s "$B/wp-json/wp/v2/pages?per_page=50")" != *GEHEIM* ]]'
ok "REST item of protected page is 404" '[[ "$(code $B/wp-json/wp/v2/pages/5)" == 404 ]]'
ok "REST search hides protected pages" '[[ "$(curl -s "$B/wp-json/wp/v2/search?search=notulen")" != *notulen* ]]'
ok "sitemap leaves protected pages out" '[[ "$(curl -s $B/wp-sitemap-posts-page-1.xml)" != *leden* ]]'
ok "page id URL also guarded" '[[ "$(code "$B/?page_id=5")" =~ 30[12] && "$(curl -sL --max-redirs 1 "$B/?page_id=5" 2>/dev/null)" != *GEHEIM* ]]'
# --- outsider
ok "identity without tenant group gets 403" '[[ "$(code -b test_session=outsider "$B/avpvh-sso/")" == 403 ]]'
# --- member
J=$(mktemp); rm -f $J
r=$(loc -c $J -b test_session=member "$B/avpvh-sso/?redirect_to=$B/leden/notulen/")
ok "member login returns to the page" '[[ "$r" == "$B/leden/notulen/" ]]'
ok "member reads protected page" '[[ "$(curl -s -b $J -b test_session=member $B/leden/notulen/)" == *GEHEIM-NOTULEN* ]]'
mh=$(curl -s -b $J -b test_session=member $B/)
ok "member sees Leden and Uitloggen in nav" '[[ "$mh" == *">Leden<"* && "$mh" == *"avpvh_tenant_logout="* ]]'
ok "member has no wp-admin" '[[ "$(loc -b $J -b test_session=member $B/wp-admin/)" == "$B/" ]]'
ok "member role is subscriber" '[[ "$($W user list --field=roles --login=test.lid)" == subscriber ]]'
nonce_url=$(echo "$mh" | grep -o "http://localhost:8088/?avpvh_tenant_logout=[a-z0-9]*" | head -1)
ok "logout link found" '[[ -n "$nonce_url" ]]'
ok "logout ends Authelia session too" '[[ "$(loc -b $J -b test_session=member "$nonce_url")" == https://auth.example.invalid/logout?rd=* ]]'
ok "after logout the page is closed" '[[ "$(code -b $J $B/leden/notulen/)" == 302 ]]'
# --- external redirect refused
ok "external redirect_to is refused" '[[ "$(loc -b test_session=member "$B/avpvh-sso/?redirect_to=https://evil.example.invalid/")" == "$B/" ]]'
# --- VvE admin
A=$(mktemp); rm -f $A
loc -c $A -b test_session=vveadmin "$B/avpvh-sso/" >/dev/null
ok "VvE admin is administrator here" '[[ "$($W user list --field=roles --login=test.vve)" == administrator ]]'
ok "VvE admin reaches wp-admin" '[[ "$(code -b $A -b test_session=vveadmin $B/wp-admin/)" == 200 ]]'
ok "admin group alone does not open members-only page" '[[ "$(code -b $A -b test_session=vveadmin $B/leden/notulen/)" == 403 ]]'
# --- revocation: Authelia now names someone else
$W user meta update test.vve avpvh_directory_checked 1 >/dev/null
ok "other Authelia user ends the session at recheck" '[[ "$(code -b $A -b test_session=member $B/wp-admin/)" == 302 ]] && [[ "$(code -b $A $B/wp-admin/)" == 302 ]]'
# --- Authelia session gone
loc -c $A -b test_session=vveadmin "$B/avpvh-sso/" >/dev/null
$W user meta update test.vve avpvh_directory_checked 1 >/dev/null
ok "ended Authelia session ends the WP session" '[[ "$(code -b $A $B/wp-admin/)" == 302 ]]'
ok "only one user per subject" '[[ "$($W user list --format=count)" == 3 ]]'
ok "no avm tables were created" '[[ "$($W db query "SHOW TABLES" --skip-column-names | grep -c vp4042_)" -gt 0 && "$($W db query "SHOW TABLES LIKE \"%avm%\"" --skip-column-names | wc -l)" == 0 ]]'
ok "local emergency login page still works" '[[ "$(code $B/wp-login.php)" == 200 ]]'
# --- shares (/?delen=)
ok "unknown share is 404" '[[ "$(code "$B/?delen=bestaatniet")" == 404 ]]'
form=$(curl -s -c $SJ "$B/?delen=testbestand")
ok "share shows the form to visitors" '[[ "$form" == *"name=\"email\""* && "$form" != *">Download "* ]]'
nonce=$(grep -o 'name="_wpnonce" value="[a-z0-9]*"' <<<"$form" | sed 's/.*value="//; s/"//')
ok "wrong address gets no button" '[[ "$(curl -s -b $SJ --data-urlencode "email=ander@example.invalid" -d "_wpnonce=$nonce" "$B/?delen=testbestand")" == *"geen toegang"* ]]'
ok "missing nonce is refused" '[[ "$(curl -s --data-urlencode "email=gast@example.invalid" "$B/?delen=testbestand")" == *"verlopen"* ]]'
page=$(curl -s -b $SJ --data-urlencode "email=GAST@example.invalid" -d "_wpnonce=$nonce" "$B/?delen=testbestand")
link=$(grep -o 'href="[^"]*delen=testbestand[^"]*t=[A-Za-z0-9]*"' <<<"$page" | sed 's/href="//; s/"$//; s/&#038;/\&/g; s/&amp;/\&/g')
ok "allowed address (any case) gets a download link" '[[ -n "$link" ]]'
ok "download link streams the file" '[[ "$(curl -s "$link")" == DEELBESTAND-INHOUD ]]'
ok "made-up token is refused" '[[ "$(curl -s "$B/?delen=testbestand&t=verzonnen")" == *"verlopen"* ]]'
ok "member logged in only at Authelia gets the button" '[[ "$(curl -s -b test_session=member "$B/?delen=testbestand")" == *"t="* ]]'
ok "Authelia user outside the member group gets the form" '[[ "$(curl -s -b test_session=vveadmin "$B/?delen=testbestand")" == *"name=\"email\""* ]]'
for _ in $(seq 1 10); do curl -s -o /dev/null -b $SJ --data-urlencode "email=raden@example.invalid" -d "_wpnonce=$nonce" "$B/?delen=testbestand"; done
ok "guessing is braked after 10 tries" '[[ "$(curl -s -b $SJ --data-urlencode "email=gast@example.invalid" -d "_wpnonce=$nonce" "$B/?delen=testbestand")" == *"Te veel pogingen"* ]]'
ok "no PHP errors" '[[ "$(docker compose logs wp 2>&1 | grep -ciE "PHP (Fatal|Warning|Notice|Deprecated)")" == 0 ]]'
exit $fail
