<?php
declare(strict_types=1);

/** Exercise profile writes, identity changes and notifications using fictional household records. */
define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);
final class Profile_Flow_Exit extends Error {
    public function __construct(public string $kind, public mixed $payload, public int $status = 200) {}
}
class WP_Error { public function get_error_message(): string { return 'Fixture directory unavailable'; } }
class WP_User {
    public function __construct(public int $ID, public string $user_email, public string $display_name = 'Fictieve Gebruiker', public string $user_login = 'fixture.user') {}
}
function __(string $text, string $domain = ''): string { return $text; }
function esc_html(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function esc_html__(string $text, string $domain = ''): string { return esc_html($text); }
function esc_url(string $url): string { return $url; }
function absint(mixed $value): int { return abs((int) $value); }
function wp_unslash(mixed $value): mixed { return $value; }
function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function is_user_logged_in(): bool { return $GLOBALS['actor'] > 0; }
function get_current_user_id(): int { return $GLOBALS['actor']; }
function wp_get_current_user(): WP_User { return $GLOBALS['users'][$GLOBALS['actor']] ?? new WP_User(0, ''); }
function get_userdata(int $id): WP_User|false { return $GLOBALS['users'][$id] ?? false; }
function current_user_can(string $cap): bool { return $GLOBALS['wp_admin']; }
function add_action(string $hook, mixed ...$args): void {}
function add_shortcode(string $name, mixed $callback): void {}
function home_url(string $path): string { return 'https://club.example.invalid' . $path; }
function wp_get_referer(): string|false { return false; }
function remove_query_arg(array $keys, string $url): string { return $url; }
function add_query_arg(array|string $args, mixed $value, ?string $url = null): string {
    return is_array($args) ? $value . '?' . http_build_query($args) : $url . '?' . http_build_query([$args => $value]);
}
function check_ajax_referer(string $action, string $field): void { $GLOBALS['nonce'] = $action; }
function check_admin_referer(string $action): void { $GLOBALS['nonce'] = $action; }
function wp_send_json_error(mixed $payload, ?int $status = null): void { throw new Profile_Flow_Exit('error', $payload, $status ?? 200); }
function wp_send_json_success(mixed $payload): void { throw new Profile_Flow_Exit('success', $payload); }
function wp_die(string $message, string $title = '', array $args = []): void { throw new Profile_Flow_Exit('die', $message, $args['response'] ?? 500); }
function wp_safe_redirect(string $url): void { throw new Profile_Flow_Exit('redirect', $url, 302); }
function update_user_meta(int $id, string $key, mixed $value): bool { $GLOBALS['meta'][$id][$key] = $value; return true; }
function current_time(string $format): string { return $format === 'Y' ? '2026' : '2026-11-20 12:00:00'; }
function avpvh_format_name(object $member): string { return trim($member->first_name . ' ' . $member->last_name); }
function get_transient(string $key): mixed { return $GLOBALS['transients'][$key] ?? false; }
function set_transient(string $key, mixed $value, int $ttl): bool { $GLOBALS['transients'][$key] = $value; return true; }
function delete_transient(string $key): bool { unset($GLOBALS['transients'][$key]); return true; }
function wp_mail(string $to, string $subject, string $body): bool { $GLOBALS['mail'][] = compact('to', 'subject', 'body'); return true; }

class AVPVH_Roles {
    public static function can_manage_members(): bool { return $GLOBALS['officer']; }
    public static function member_has_elevated_access(int $id): bool { return $id === 40; }
    public static function require_elevated_target_admin(int $id): void {
        if ($id === 40 && !current_user_can('manage_options')) { wp_die('Denied', '', ['response' => 403]); }
    }
    public static function require_address_household_admin(int $id, object $address): void { self::require_elevated_target_admin($id); }
}
class AVPVH_I18n {
    public static function get_supported_locales(): array { return ['nl_NL' => 'Nederlands', 'en_GB' => 'English']; }
    public static function remember_locale(string $locale): void { $GLOBALS['remembered_locale'] = $locale; }
}
class AVPVH_Nav_Auth { const AUTHELIA_URL = 'https://auth.example.invalid'; }
class AVPVH_Directory {
    public static function update_user(string $uid, array $fields): true|WP_Error {
        $GLOBALS['directory_writes'][] = compact('uid', 'fields');
        return $GLOBALS['directory_error'] ? new WP_Error() : true;
    }
}
class AVPVH_DB {
    public static function get_member_by_wp_user(int $id): ?object {
        foreach ($GLOBALS['members'] as $member) { if ($member->wp_user_id === $id) { return $member; } }
        return null;
    }
    public static function get_member(int $id): ?object { return $GLOBALS['members'][$id] ?? null; }
    public static function get_manageable_members(int $id): array { return array_intersect_key($GLOBALS['members'], array_flip([10, 20, 30, 40])); }
    public static function get_extended_household(int $id): array { return [$GLOBALS['members'][50]]; }
    public static function is_minor_member(object $member, int $age, bool $certain = false): bool { return $member->id === 20; }
    public static function get_members_with_address(int $id, bool $all): array { return [$GLOBALS['members'][30]]; }
    public static function update_member_with_audit(int $id, array $data, array $formats): void {
        if ($GLOBALS['write_error']) { throw new RuntimeException('Fixture write failed'); }
        $GLOBALS['writes'][] = compact('id', 'data', 'formats');
    }
    public static function add_member_address(int $id, array $address, bool $member_entered): void { $GLOBALS['addresses'][] = compact('id', 'address', 'member_entered'); }
    public static function get_member_identities(int $id): array { return $GLOBALS['identities'][$id] ?? []; }
    public static function can_delete_identity(array $identities, int $id): bool { return $GLOBALS['allow_delete']; }
    public static function delete_identity_by_id(int $member, int $identity): void { $GLOBALS['deleted'][] = compact('member', 'identity'); }
    public static function set_primary_identity(int $member, int $identity): void { $GLOBALS['primary'][] = compact('member', 'identity'); }
    public static function get_activity(int $id): ?object { return $id === 70 ? (object) ['start_date' => '2026-07-10', 'end_date' => '2026-07-12'] : null; }
    public static function get_relationship_labels(): array { return [(object) ['id' => 1]]; }
    public static function get_relationship(int $id): ?object { return $id === 8 ? (object) ['member_id' => 20, 'related_member_id' => 10] : null; }
    public static function relationship_exists(mixed ...$args): bool { return $GLOBALS['duplicate']; }
    public static function add_relationship(mixed ...$args): bool { $GLOBALS['relations'][] = $args; return true; }
    public static function remove_relationship(int $id, int $member): bool { $GLOBALS['removed_relations'][] = compact('id', 'member'); return true; }
}
require_once dirname(__DIR__) . '/tests/fixtures/access-session.php';
require_once dirname(__DIR__) . '/includes/class-member-profile-form.php';
require_once dirname(__DIR__) . '/includes/class-member-profile-page.php';

$results = [];
function check(string $label, bool $ok): void { $GLOBALS['results'][] = [$label, $ok]; }
function reset_profile(): void {
    $GLOBALS['actor'] = 101;
    $GLOBALS['officer'] = $GLOBALS['wp_admin'] = $GLOBALS['directory_error'] = $GLOBALS['write_error'] = $GLOBALS['duplicate'] = false;
    $GLOBALS['two_factor'] = $GLOBALS['allow_delete'] = true;
    $GLOBALS['members'] = $GLOBALS['users'] = $GLOBALS['identities'] = [];
    foreach ([10, 20, 30, 40, 50] as $id) {
        $GLOBALS['members'][$id] = (object) ['id' => $id, 'wp_user_id' => $id + 91, 'status' => 'active', 'lldap_user_id' => 'fixture.' . $id, 'first_name' => 'Fictief', 'last_name' => 'Voorbeeld' . $id, 'email' => 'lid' . $id . '@example.invalid', 'emergency_member_id' => 0];
        $GLOBALS['users'][$id + 91] = new WP_User($id + 91, 'lid' . $id . '@example.invalid');
        $GLOBALS['identities'][$id] = [
            (object) ['id' => 1, 'provider' => 'email', 'email' => 'first' . $id . '@example.invalid', 'verified_at' => '2026-01-01', 'is_primary' => 1],
            (object) ['id' => 2, 'provider' => 'google', 'email' => 'second' . $id . '@example.invalid', 'verified_at' => '2026-01-01', 'is_primary' => 0],
        ];
    }
    foreach (['writes', 'addresses', 'directory_writes', 'meta', 'mail', 'transients', 'deleted', 'primary', 'relations', 'removed_relations'] as $key) { $GLOBALS[$key] = []; }
    $GLOBALS['remembered_locale'] = null;
    $_POST = ['member_id' => 10, 'first_name' => '<b>Fictief</b>', 'last_name' => 'Voorbeeld', 'passport_name' => 'Fictief (Voorbeeld)', 'initials' => 'f v', 'phone' => '0100000000', 'street' => 'Teststraat', 'postal_code' => '1234 AB', 'user_locale' => 'en_GB'];
}
function profile_action(callable $callback): Profile_Flow_Exit {
    try { $callback(); } catch (Profile_Flow_Exit $exit) { return $exit; }
    throw new RuntimeException('Handler did not terminate.');
}
$form = new AVPVH_Member_Profile_Form();
$log = tempnam(sys_get_temp_dir(), 'avpvh_profile_test_');
$previous_log = ini_get('error_log');
ini_set('error_log', $log);
try {
    reset_profile();
    $_POST['birth_date'] = '2000-01-01';
    $exit = profile_action([$form, 'handle_save_profile']);
    check('own profile saves sanitized data to the correct member', $exit->kind === 'success' && $GLOBALS['writes'][0]['id'] === 10 && $GLOBALS['writes'][0]['data']['first_name'] === 'Fictief');
    check('passport and initials are normalized without accepting admin-only fields', $GLOBALS['writes'][0]['data']['passport_name'] === 'Fictief Voorbeeld' && $GLOBALS['writes'][0]['data']['initials'] === 'F.V.' && !array_key_exists('birth_date', $GLOBALS['writes'][0]['data']));
    check('self-entered address stays pending confirmation', $GLOBALS['addresses'][0]['id'] === 10 && $GLOBALS['addresses'][0]['member_entered']);
    check('profile name is synchronized through the directory boundary', $GLOBALS['directory_writes'] === [['uid' => 'fixture.10', 'fields' => ['display_name' => 'Fictief Voorbeeld']]]);
    check('own locale is stored and remembered', $GLOBALS['meta'][101]['locale'] === 'en_GB' && $GLOBALS['remembered_locale'] === 'en_GB');

    reset_profile();
    $GLOBALS['officer'] = true;
    $_POST += ['birth_date' => '2010', 'is_student' => 1];
    $_POST['member_id'] = 30;
    $exit = profile_action([$form, 'handle_save_profile']);
    check('2FA member administrator saves birth year and student status for the target', $exit->kind === 'success' && $GLOBALS['writes'][0]['id'] === 30 && $GLOBALS['writes'][0]['data']['birth_year'] === 2010 && $GLOBALS['writes'][0]['data']['birth_date'] === null && $GLOBALS['writes'][0]['data']['is_student'] === 1);
    check('target locale and confirmed address are written to the target only', $GLOBALS['meta'][121]['locale'] === 'en_GB' && $GLOBALS['remembered_locale'] === null && !$GLOBALS['addresses'][0]['member_entered']);

    foreach ([false, true] as $two_factor) {
        reset_profile();
        $GLOBALS['officer'] = true;
        $GLOBALS['two_factor'] = $two_factor;
        $exit = profile_action([$form, 'handle_save_profile']);
        check('officer self-profile address always needs confirmation, factor=' . (int) $two_factor, $exit->kind === 'success' && $GLOBALS['addresses'][0]['member_entered']);
    }
    reset_profile();
    $GLOBALS['officer'] = true;
    $GLOBALS['two_factor'] = false;
    $_POST['member_id'] = 30;
    $exit = profile_action([$form, 'handle_save_profile']);
    check('1FA officer household address cannot become board-confirmed', $exit->kind === 'success' && $GLOBALS['addresses'][0]['member_entered']);
    [$shown, $view] = AVPVH_Member_Profile_Page::resolve($GLOBALS['members'][10], 30);
    check('1FA officer sees the household view without admin fields', $shown->id === 30 && $view === 'household');
    [$shown, $view] = AVPVH_Member_Profile_Page::resolve($GLOBALS['members'][10], 10);
    check('1FA officer self-profile never selects the admin view', $shown->id === 10 && $view === 'own');

    foreach (['anonymous', 'missing_member', 'elevated_target', 'emergency_member', 'emergency_phone', 'storage_error'] as $case) {
        reset_profile();
        switch ($case) {
            case 'anonymous': $GLOBALS['actor'] = 0; break;
            case 'missing_member': $GLOBALS['members'] = []; unset($_POST['member_id']); break;
            case 'elevated_target': $_POST['member_id'] = 40; break;
            case 'emergency_member': $_POST['emergency_member_id'] = 999; break;
            case 'emergency_phone': $_POST['emergency_contact'] = 'Fictief contact'; break;
            case 'storage_error': $GLOBALS['write_error'] = true; break;
        }
        $exit = profile_action([$form, 'handle_save_profile']);
        check($case . ' profile submission leaves member data and addresses untouched', $exit->kind === 'error' && !$GLOBALS['writes'] && !$GLOBALS['addresses']);
    }
    reset_profile();
    $_POST['emergency_member_id'] = 30;
    $_POST['user_locale'] = 'unknown';
    $GLOBALS['directory_error'] = true;
    $exit = profile_action([$form, 'handle_save_profile']);
    check('a valid linked emergency contact is saved and unsupported locale ignored', $exit->kind === 'success' && $GLOBALS['writes'][0]['data']['emergency_member_id'] === '30' && !$GLOBALS['meta']);
    check('a directory synchronization failure is recorded without losing profile changes', count($GLOBALS['writes']) === 1 && str_contains(file_get_contents($log), 'Fixture directory unavailable'));

    foreach (['unverified', 'last_identity', 'adult_target', 'missing_target', 'anonymous'] as $case) {
        reset_profile();
        $_POST = ['member_id' => 10, 'identity_id' => 2];
        $handler = 'handle_make_primary_identity';
        switch ($case) {
            case 'unverified': $GLOBALS['identities'][10][1]->verified_at = null; break;
            case 'last_identity': $GLOBALS['allow_delete'] = false; $handler = 'handle_remove_identity'; break;
            case 'adult_target': $_POST['member_id'] = 30; break;
            case 'missing_target': $_POST['member_id'] = 999; break;
            case 'anonymous': $GLOBALS['actor'] = 0; break;
        }
        $exit = profile_action([$form, $handler]);
        check($case . ' identity change is rejected without an identity write', !$GLOBALS['primary'] && !$GLOBALS['deleted'] && !$GLOBALS['directory_writes'] && ($exit->kind === 'die' || str_contains((string) $exit->payload, 'identity_error=')));
    }
    reset_profile();
    $_POST = ['member_id' => 10, 'identity_id' => 2];
    $exit = profile_action([$form, 'handle_make_primary_identity']);
    check('verified primary identity updates the directory address and notifies the actor', $GLOBALS['primary'] === [['member' => 10, 'identity' => 2]] && $GLOBALS['directory_writes'][0]['fields'] === ['mail' => 'second10@example.invalid'] && count($GLOBALS['mail']) === 1 && str_contains($exit->payload, 'identity_primary=1'));
    reset_profile();
    $_POST = ['member_id' => 20, 'identity_id' => 2];
    profile_action([$form, 'handle_remove_identity']);
    check('guardian identity removal is bound to the child and notifies actor and target', $GLOBALS['deleted'] === [['member' => 20, 'identity' => 2]] && count($GLOBALS['mail']) === 3);
    reset_profile();
    $_POST = ['member_id' => 10, 'identity_id' => 999];
    profile_action([$form, 'handle_remove_identity']);
    check('unknown identity IDs never delete or notify', !$GLOBALS['deleted'] && !$GLOBALS['mail']);

    reset_profile();
    $_POST = ['member_id' => 50];
    $exit = profile_action([$form, 'handle_request_identity']);
    check('extended household can request verification without adding an identity', count($GLOBALS['mail']) === 1 && $GLOBALS['mail'][0]['to'] === 'lid50@example.invalid' && str_contains($GLOBALS['mail'][0]['body'], '/reset-password/step1?username=') && str_contains($exit->payload, 'identity_requested=1'));
    check('verification request records who should be notified', $GLOBALS['transients']['avpvh_identity_request_50'] === 101);
    AVPVH_Member_Profile_Form::notify_identity_change($GLOBALS['members'][50], $GLOBALS['members'][50], 'toegevoegd', 'email', 'new@example.invalid', $GLOBALS['users'][141]);
    check('completing verification consumes the request and notifies its requester', !isset($GLOBALS['transients']['avpvh_identity_request_50']) && count($GLOBALS['mail']) === 3 && $GLOBALS['mail'][2]['to'] === 'lid10@example.invalid');
    reset_profile();
    $_POST = ['member_id' => 10];
    check('requesting verification from yourself is rejected', profile_action([$form, 'handle_request_identity'])->status === 400 && !$GLOBALS['mail']);

    reset_profile();
    $_POST = ['member_id' => 20, 'related_member_id' => 10, 'label_id' => 1, 'activity_id' => 70, 'valid_from' => '2026-01-01'];
    check('ordinary member cannot add a relationship', profile_action([$form, 'handle_add_relationship'])->status === 403 && !$GLOBALS['relations']);
    $GLOBALS['officer'] = true;
    $exit = profile_action([$form, 'handle_add_relationship']);
    check('officer relationship uses the selected activity dates and records the actor', $GLOBALS['relations'][0] === [20, 10, 1, '2026-07-10', '2026-07-12', '', 101] && str_contains($exit->payload, 'relationship_added=1'));
    $GLOBALS['duplicate'] = true;
    $exit = profile_action([$form, 'handle_add_relationship']);
    check('duplicate relationship is not written again', count($GLOBALS['relations']) === 1 && str_contains($exit->payload, 'relationship_duplicate=1'));
    $_POST['relationship_id'] = 8;
    profile_action([$form, 'handle_remove_relationship']);
    check('relationship removal is scoped to the selected member', $GLOBALS['removed_relations'] === [['id' => 8, 'member' => 20]]);
    reset_profile();
    $GLOBALS['officer'] = true;
    $_POST = ['member_id' => 10, 'related_member_id' => 20, 'label_id' => 1];
    profile_action([$form, 'handle_add_relationship']);
    check('an undated relationship retains open validity dates', count($GLOBALS['relations']) === 1 && $GLOBALS['relations'][0][3] === null && $GLOBALS['relations'][0][4] === null);
    foreach (['self_link', 'missing_source', 'missing_target', 'missing_label', 'invalid_date', 'reversed_dates', 'elevated_source', 'elevated_target'] as $case) {
        reset_profile();
        $GLOBALS['officer'] = true;
        $_POST = ['member_id' => 10, 'related_member_id' => 20, 'label_id' => 1];
        switch ($case) {
            case 'self_link': $_POST['related_member_id'] = 10; break;
            case 'missing_source': $_POST['member_id'] = 999; break;
            case 'missing_target': $_POST['related_member_id'] = 999; break;
            case 'missing_label': $_POST['label_id'] = 999; break;
            case 'invalid_date': $_POST['valid_from'] = '2026-02-30'; break;
            case 'reversed_dates': $_POST += ['valid_from' => '2026-07-12', 'valid_until' => '2026-07-10']; break;
            case 'elevated_source': $_POST['member_id'] = 40; break;
            case 'elevated_target': $_POST['related_member_id'] = 40; break;
        }
        $exit = profile_action([$form, 'handle_add_relationship']);
        check($case . ' relationship is rejected without writes', $exit->kind === 'die' && !$GLOBALS['relations']);
    }
    reset_profile();
    $GLOBALS['officer'] = true;
    $_POST = ['member_id' => 50];
    profile_action([$form, 'handle_request_identity']);
    check('repeated verification request is rate limited', profile_action([$form, 'handle_request_identity'])->status === 429 && count($GLOBALS['mail']) === 1);
    reset_profile();
    $GLOBALS['actor'] = 999;
    $GLOBALS['officer'] = true;
    $_POST = ['member_id' => 30];
    $exit = profile_action([$form, 'handle_request_identity']);
    check('administrator without a member record can request verification safely', $exit->kind === 'redirect' && count($GLOBALS['mail']) === 1);
} finally {
    ini_set('error_log', $previous_log);
    unlink($log);
}
$failures = 0;
foreach ($results as [$label, $ok]) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    $failures += $ok ? 0 : 1;
}
exit($failures ? 1 : 0);
