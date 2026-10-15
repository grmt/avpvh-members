#!/bin/bash
# End-to-end test: a member saves their own profile with a language choice
# (AVP-PvH site, no tenant file). Until 1.0.66 this was a fatal error
# (AVPVH_I18n::locale_to_lang() never existed), and the other fields were
# lost. Fictitious member; reuses the tenant e2e containers; needs Docker
# and port 8088.
#
#   scripts/tenant-e2e/profile-save.sh
cd "$(dirname "$0")"
B=http://localhost:8088
W="docker compose exec -T cli wp"
trap 'docker compose down -v >/dev/null 2>&1' EXIT
docker compose up -d >/dev/null 2>&1
for _ in $(seq 1 60); do curl -s -o /dev/null "$B/" && $W db query 'SELECT 1' >/dev/null 2>&1 && break; sleep 3; done
$W core install --url=$B --title=t --admin_user=beheer --admin_password=x --admin_email=b@example.invalid --skip-email >/dev/null 2>&1
$W plugin activate avpvh-members >/dev/null 2>&1
# The plugin needs the ldap extension; the directory itself stays unreachable.
docker compose exec -T -u 0 cli sh -c 'apk add --no-cache openldap-dev >/dev/null 2>&1 && docker-php-ext-install ldap >/dev/null 2>&1'
U=$($W user create test-lid lid@example.invalid --role=subscriber --porcelain 2>/dev/null)
$W eval "\$id = AVPVH_DB::create_member('test-lid', 'Test', '', 'Lid', null, 'active'); AVPVH_DB::set_wp_user_id(\$id, $U); global \$wpdb; \$wpdb->insert(AVPVH_Directory_Cache::table(), ['user_id' => 'test-lid', 'email' => 'lid@example.invalid', 'lowercase_email' => 'lid@example.invalid', 'display_name' => 'Test Lid', 'synced_at' => current_time('mysql')], ['%s', '%s', '%s', '%s', '%s']);" >/dev/null 2>&1
response=$($W eval '
// The init hooks of the plugin reset the user WP-CLI set (no proxy header).
wp_set_current_user(get_user_by("login", "test-lid")->ID);
$_POST = $_REQUEST = ["action" => "avpvh_save_member_profile", "avpvh_nonce" => wp_create_nonce("avpvh_member_profile"), "user_locale" => "de_DE", "phone" => "0123456789"];
add_filter("wp_doing_ajax", "__return_true");
add_filter("wp_die_ajax_handler", fn() => function ($m) { exit; });
do_action("wp_ajax_avpvh_save_member_profile");
' --user="$U" 2>&1)
locale=$($W user meta get "$U" locale 2>/dev/null)
phone=$($W db query "SELECT phone FROM vp4042_avm_members WHERE wp_user_id=$U" --skip-column-names 2>/dev/null)
fail=0
check() {
    local label=$1 ok=$2
    if [[ "$ok" == 1 ]]; then echo "ok   $label"; else echo "FAIL $label"; fail=1; fi
    return 0
}
check "save answers success" "$([[ "$response" == *'"success":true'* ]] && echo 1)"
check "language preference saved" "$([[ "$locale" == de_DE ]] && echo 1)"
check "other fields saved too" "$([[ "$phone" == 0123456789 ]] && echo 1)"
exit $fail
