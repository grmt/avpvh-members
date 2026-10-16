#!/bin/bash
# End-to-end test of the Rechtspreker tenant layer against a real
# WordPress (block theme), with a fake Authelia auth-request endpoint
# (authz.php) that knows three fictitious accounts:
#   member   → vp4042-leden      vveadmin → rsp-vve-admins
#   outsider → boek (no access to this tenant)
# plus a fake Google/Microsoft (idp.php) and an OpenLDAP with the
# fictitious accounts of directory/seed.ldif for "Inloggen met Google".
# Needs Docker; uses ports 8088 and 8089 on 127.0.0.1 and removes everything after.
#
#   scripts/tenant-e2e/run.sh
set -uo pipefail
cd "$(dirname "$0")"
B=http://localhost:8088
W="docker compose exec -T cli wp"
# KEEP=1 leaves the environment running for debugging.
[[ -n "${KEEP:-}" ]] || trap 'docker compose down -v >/dev/null 2>&1' EXIT

docker compose up -d >/dev/null 2>&1
# The PHP ldap extension, in the web server and in WP-CLI (healthcheck).
docker compose exec -T wp sh -c 'apt-get -qq update && apt-get -qq install -y libldap2-dev && docker-php-ext-install ldap' >/dev/null 2>&1
docker compose exec -T -u 0 cli sh -c 'apk add -q --no-cache openldap-dev $PHPIZE_DEPS && docker-php-ext-install ldap' >/dev/null 2>&1
for c in wp cli; do
    docker compose exec -T -u 0 $c sh -c 'printf lees-alleen-test > /tmp/reader; printf test-secret-microsoft > /tmp/microsoft-secret; chmod 644 /tmp/reader /tmp/microsoft-secret'
done
docker compose restart wp >/dev/null 2>&1
for _ in $(seq 1 60); do
    curl -s -o /dev/null "$B/" && $W db query 'SELECT 1' >/dev/null 2>&1 && break
    sleep 3
done
docker compose cp avpvh-tenant.php wp:/var/www/html/wp-content/avpvh-tenant.php >/dev/null 2>&1
docker compose exec -T wp mkdir -p /var/www/html/wp-content/mu-plugins
docker compose cp fake-idp-endpoints.php wp:/var/www/html/wp-content/mu-plugins/fake-idp-endpoints.php >/dev/null 2>&1
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
hc_active=$($W eval-file wp-content/plugins/avpvh-members/includes/tenant/healthcheck.php 2>&1); hc_active_exit=$?

SJ=$(mktemp)
fail=0
ok() { if eval "$2"; then echo "ok   $1"; else echo "FAIL $1"; fail=1; fi; }
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
loc() { curl -s -o /dev/null -w '%{redirect_url}' "$@"; }
ok "healthcheck passes while the plugin is inactive" '[[ $hc_exit == 0 && "$hc" == *"Configuratie geldig"* && "$hc" == *": 401"* && "$hc" == *"'"'"'leden'"'"': $L"* ]]'
ok "healthcheck: the directory is reachable" '[[ "$hc" == *"Directory (alleen lezen): verbonden"* ]]'
ok "healthcheck: OAuth secrets found" '[[ "$hc" == *"Inloggen met google: secret in het tenantbestand"* && "$hc" == *"Inloggen met microsoft: secret-bestand leesbaar"* ]]'
ok "healthcheck still finds the protected page once active" '[[ $hc_active_exit == 0 && "$hc_active" == *"'"'"'leden'"'"': $L"* ]]'
# --- anonymous
home=$(curl -s $B/)
ok "home shows public page in nav" '[[ "$home" == *"Over ons"* ]]'
ok "home hides Leden from nav" '[[ "$home" != *">Leden<"* ]]'
ok "home offers Inloggen via the login choice" '[[ "$home" == *">Inloggen</a>"* && "$home" == *"/avpvh-login/?redirect_to="* ]]'
choice=$(curl -s "$B/avpvh-login/?redirect_to=$B/leden/notulen/")
ok "login choice offers Google, Microsoft and Authelia" '[[ "$choice" == *"Inloggen met Google"* && "$choice" == *"Inloggen met Microsoft"* && "$choice" == *"auth.example.invalid/?rd="* ]]'
ok "account link only in the header navigation" '[[ "$(grep -o avpvh-account-item <<<"$home" | wc -l)" == 1 ]]'
ok "protected child redirects visitors to the login choice" '[[ "$(loc $B/leden/notulen/)" == "$B/avpvh-login/?redirect_to="* ]]'
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
# --- Google / Microsoft (fake provider, directory accounts of seed.ldif)
# oauth <provider> <address> <cookie jar> [extra authorize args] [redirect_to]
# → where the site sends the browser after the callback
oauth() {
    local provider=$1 account=$2 jar=$3 extra=${4:-} target=${5:-$B/leden/notulen/} start cb
    start=$(loc -c "$jar" -b "$jar" "$B/wp-json/avpvh/v1/tenant-oauth/$provider/start?redirect_to=$target")
    cb=$(loc "$start&account=$account$extra")
    loc -c "$jar" -b "$jar" "$cb"
    return 0
}
OG=$(mktemp); rm -f $OG
start=$(loc -c $OG "$B/wp-json/avpvh/v1/tenant-oauth/google/start")
ok "start uses PKCE (S256), state and nonce" '[[ "$start" == http://localhost:8089/google/authorize?* && "$start" == *code_challenge_method=S256* && "$start" == *"&state="* && "$start" == *"&nonce="* ]]'
rm -f $OG
ok "Google member returns to the page" '[[ "$(oauth google lid.google@gmail.example.invalid $OG)" == "$B/leden/notulen/" ]]'
ok "Google member reads the protected page without Authelia" '[[ "$(curl -s -b $OG $B/leden/notulen/)" == *GEHEIM-NOTULEN* ]]'
ok "Google member is a subscriber, linked to the directory account" '[[ "$($W user list --field=roles --login=lid.google)" == subscriber && "$($W user meta get lid.google avpvh_directory_subject)" == rsp_vve_vp4042/lid.google ]]'
ok "Google member has no wp-admin" '[[ "$(loc -b $OG $B/wp-admin/)" == "$B/" ]]'
OM=$(mktemp); rm -f $OM
ok "Microsoft member (personal account) logs in" '[[ "$(oauth microsoft LID.MICROSOFT@hotmail.example.invalid $OM)" == "$B/leden/notulen/" && "$($W user list --field=roles --login=lid.microsoft)" == subscriber ]]'
O2=$(mktemp); rm -f $O2
oauth google lid.twee@gmail.example.invalid $O2 >/dev/null
ok "a second mail value finds the account; the user keeps the first address" '[[ "$($W user get lid.twee --field=user_email)" == lid.twee@provider.example.invalid ]]'
OX=$(mktemp); rm -f $OX
ok "unverified Google address is refused" '[[ "$(oauth google lid.google@gmail.example.invalid $OX "&unverified=1")" == "$B/avpvh-login/?fout=failed"* ]]'
ok "Microsoft work/school account is refused" '[[ "$(oauth microsoft lid.microsoft@hotmail.example.invalid $OX "&unverified=1")" == "$B/avpvh-login/?fout=failed"* ]]'
ok "unknown address: no access" '[[ "$(oauth google niemand@example.invalid $OX)" == "$B/avpvh-login/?fout=no_access"* ]]'
ok "address of two accounts: no access" '[[ "$(oauth google dubbel@example.invalid $OX)" == "$B/avpvh-login/?fout=no_access"* ]]'
ok "account without the member group: no access" '[[ "$(oauth google buiten@example.invalid $OX)" == "$B/avpvh-login/?fout=no_access"* ]]'
ok "administrator is sent to the Authelia login (2FA)" '[[ "$(oauth google beheer@example.invalid $OX)" == "$B/avpvh-login/?fout=admin"* ]]'
ok "refusals create no users and no session" '[[ "$($W user list --format=count)" == 6 && "$(code -b $OX $B/leden/notulen/)" == 302 ]]'
ok "the refusal is explained on the login choice" '[[ "$(curl -s "$B/avpvh-login/?fout=admin")" == *"Log in met gebruikersnaam en wachtwoord"* ]]'
start=$(loc -c $OX "$B/wp-json/avpvh/v1/tenant-oauth/google/start")
cb=$(loc "$start&account=lid.google@gmail.example.invalid")
ok "callback without the state cookie is refused (login CSRF)" '[[ "$(loc "$cb")" == "$B/avpvh-login/?fout=expired" ]]'
ok "a refused callback has spent the state" '[[ "$(loc -b $OX "$cb")" == "$B/avpvh-login/?fout=expired" ]]'
OC=$(mktemp); rm -f $OC
start=$(loc -c $OC "$B/wp-json/avpvh/v1/tenant-oauth/google/start")
cb=$(loc "$start&account=lid.microsoft@hotmail.example.invalid")
ok "callback with the cookie works once" '[[ "$(loc -b $OC -c $OC "$cb")" == "$B/" ]]'
ok "the same callback again is refused" '[[ "$(loc -b $OC "$cb")" == "$B/avpvh-login/?fout=expired" ]]'
OE=$(mktemp); rm -f $OE
ok "external redirect_to ends on the home page" '[[ "$(oauth google lid.google@gmail.example.invalid $OE "" https://evil.example.invalid/)" == "$B/" ]]'
# Revocation: out of the member group in the directory.
docker compose exec -T openldap ldapmodify -x -H ldap://localhost:1389 -D cn=admin,dc=nl -w beheer-test >/dev/null 2>&1 <<'LDIF'
dn: cn=vp4042-leden,ou=groups,ou=vp40-42,ou=vve,ou=rechtspreker,dc=nl
changetype: modify
delete: member
member: uid=lid.google,ou=people,ou=vp40-42,ou=vve,ou=rechtspreker,dc=nl
LDIF
ok "before the recheck the session still reads" '[[ "$(code -b $OG $B/leden/notulen/)" == 200 ]]'
$W user meta update lid.google avpvh_directory_checked 1 >/dev/null
ok "recheck against the directory ends the session" '[[ "$(code -b $OG $B/leden/notulen/)" == 302 ]]'
ok "and a new Google login is refused" '[[ "$(oauth google lid.google@gmail.example.invalid $OX)" == "$B/avpvh-login/?fout=no_access"* ]]'
$W user meta update lid.microsoft avpvh_directory_checked 1 >/dev/null
ok "recheck keeps a member that is still in the group" '[[ "$(code -b $OM $B/leden/notulen/)" == 200 ]]'
ok "the OAuth log has no e-mail addresses" '[[ "$(docker compose logs wp 2>&1 | grep "step=oauth" | grep -c "@")" == 0 && "$(docker compose logs wp 2>&1 | grep -c "step=oauth")" -gt 5 ]]'
ok "no PHP errors" '[[ "$(docker compose logs wp 2>&1 | grep -ciE "PHP (Fatal|Warning|Notice|Deprecated)")" == 0 ]]'
exit $fail
