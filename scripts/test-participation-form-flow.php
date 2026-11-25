<?php
declare(strict_types=1);

/** Render and submit the production participation form against fictional members and camps. */
define('ABSPATH', __DIR__ . '/');
final class Participation_Exit extends Error {
    public function __construct(public int $status, public string $body) {}
}
function __(string $text, string $domain = ''): string { return $text; }
function esc_html(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function esc_html__(string $text, string $domain = ''): string { return esc_html($text); }
function esc_html_e(string $text, string $domain = ''): void { echo esc_html($text); }
function esc_attr(mixed $text): string { return esc_html((string) $text); }
function esc_url(string $url): string { return esc_attr($url); }
function esc_textarea(string $text): string { return esc_html($text); }
function absint(mixed $value): int { return abs((int) $value); }
function wp_unslash(mixed $value): mixed { return $value; }
function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function sanitize_textarea_field(string $value): string { return trim(strip_tags($value)); }
function add_action(string $hook, mixed ...$args): void {}
function add_shortcode(string $name, mixed $callback): void {}
function is_user_logged_in(): bool { return $GLOBALS['actor'] > 0; }
function get_current_user_id(): int { return $GLOBALS['actor']; }
function home_url(string $path): string { return 'https://club.example.invalid' . $path; }
function admin_url(string $path): string { return home_url('/wp-admin/' . $path); }
function add_query_arg(string $key, mixed $value, ?string $url = null): string { return ($url ?? home_url('/deelname/')) . '?' . http_build_query([$key => $value]); }
function remove_query_arg(string $key): string { return home_url('/deelname/'); }
function wp_get_referer(): string|false { return $GLOBALS['referer']; }
function wp_nonce_field(string $action): void { echo '<input name="_wpnonce" value="fixture-nonce">'; }
function check_admin_referer(string $action): void { $GLOBALS['nonce'] = $action; }
function checked(bool $value): void { if ($value) { echo ' checked'; } }
function selected(int $value, int $current): void { if ($value === $current) { echo ' selected'; } }
function date_i18n(string $format, int $timestamp): string { return date($format, $timestamp); }
function avpvh_format_name(object $member, string $format = ''): string { return $member->name; }
function wp_die(string $message, string $title = '', array $args = []): void { throw new Participation_Exit($args['response'] ?? 500, $message); }
function wp_safe_redirect(string $url): void { throw new Participation_Exit(302, $url); }
class AVPVH_Roles {
    public static function can_manage_activities(): bool { return $GLOBALS['officer']; }
}
class AVPVH_DB {
    public static function get_member_by_wp_user(int $id): ?object { return $GLOBALS['members'][$id] ?? null; }
    public static function get_member(int $id): ?object { return $GLOBALS['members'][$id] ?? null; }
    public static function get_current_camp_activity(): ?object { return $GLOBALS['camp']; }
    public static function get_manageable_members(int $id): array { return array_intersect_key($GLOBALS['members'], array_flip($GLOBALS['manageable'])); }
    public static function get_participation(int $member, int $activity): ?object { return $GLOBALS['participation']; }
    public static function get_participation_days(int $id): array { return $GLOBALS['existing_days']; }
    public static function save_participation(int $member, int $activity, array $fields): int {
        $GLOBALS['saved'][] = compact('member', 'activity', 'fields');
        return 700;
    }
    public static function save_participation_days(int $id, array $days): void { $GLOBALS['saved_days'][] = compact('id', 'days'); }
}
require_once dirname(__DIR__) . '/tests/fixtures/access-session.php';
require_once dirname(__DIR__) . '/includes/class-activity-participation-form.php';

$results = [];
function check(string $label, bool $ok): void { $GLOBALS['results'][] = [$label, $ok]; }
function reset_participation(): void {
    $GLOBALS['actor'] = 10;
    $GLOBALS['officer'] = false;
    $GLOBALS['two_factor'] = true;
    $GLOBALS['members'] = [
        10 => (object) ['id' => 10, 'status' => 'active', 'name' => 'Fictief Ouder'],
        20 => (object) ['id' => 20, 'status' => 'active', 'name' => 'Fictief Kind'],
        30 => (object) ['id' => 30, 'status' => 'active', 'name' => 'Fictief Ander'],
    ];
    $GLOBALS['manageable'] = [10, 20];
    $GLOBALS['camp'] = (object) ['id' => 50, 'name' => 'Voorbeeldkamp <test>', 'year' => 2026, 'start_date' => '2026-07-10', 'end_date' => '2026-07-12'];
    $GLOBALS['participation'] = (object) ['id' => 700, 'nights' => 2, 'nawacht' => true, 'diet' => '<test-dieet>', 'notes' => '<test-notitie>'];
    $GLOBALS['existing_days'] = ['2026-07-10' => 'n'];
    $GLOBALS['saved'] = $GLOBALS['saved_days'] = [];
    $GLOBALS['referer'] = home_url('/deelname/');
    $_GET = [];
    $_POST = ['member_id' => 10, 'activity_id' => 50, 'nights' => '999', 'nawacht' => 1, 'diet' => '<b>vegetarisch</b>', 'notes' => '<b>notitie</b>', 'day' => [
        '2026-07-09' => 'n', '2026-07-10' => 'n', '2026-07-11' => 'x', '2026-07-12' => 'n', '2026-07-13' => 'n', 'invalid' => 'n',
    ]];
}
function submit_participation(AVPVH_Activity_Participation_Form $form): Participation_Exit {
    try { $form->handle_save(); } catch (Participation_Exit $exit) { return $exit; }
    throw new RuntimeException('Submission did not terminate.');
}

$form = new AVPVH_Activity_Participation_Form();
reset_participation();
$html = $form->render_shortcode();
check('form renders the camp, household selector and stored attendance', str_contains($html, 'Voorbeeldkamp &lt;test&gt; 2026') && str_contains($html, 'Fictief Kind') && str_contains($html, 'value="n"  checked'));
check('stored text is escaped in form fields', str_contains($html, '&lt;test-dieet&gt;') && str_contains($html, '&lt;test-notitie&gt;') && !str_contains($html, '<test-dieet>'));
check('all three camp dates appear in the form', substr_count($html, 'name="day[') === 3);
$_GET = ['member_id' => 20, 'activiteit_updated' => 1];
$html = $form->render_shortcode();
check('household target and saved notice render correctly', str_contains($html, 'name="member_id" value="20"') && str_contains($html, 'Je deelname is opgeslagen.'));
$_GET = ['member_id' => 30];
check('unrelated profile request falls back to the own participation', str_contains($form->render_shortcode(), 'name="member_id" value="10"'));
$GLOBALS['officer'] = true;
check('activity officer can view another member participation', str_contains($form->render_shortcode(), 'name="member_id" value="30"'));
$_GET = ['member_id' => 999];
check('missing target cannot break form rendering', str_contains($form->render_shortcode(), 'name="member_id" value="10"'));
$GLOBALS['camp']->start_date = $GLOBALS['camp']->end_date = null;
$GLOBALS['participation'] = null;
check('a camp without dates still renders editable details', !str_contains($form->render_shortcode(), 'name="day[') && str_contains($form->render_shortcode(), 'name="notes"'));

foreach (['anonymous', 'no_member', 'inactive', 'no_camp'] as $case) {
    reset_participation();
    switch ($case) {
        case 'anonymous': $GLOBALS['actor'] = 0; break;
        case 'no_member': $GLOBALS['members'] = []; break;
        case 'inactive': $GLOBALS['members'][10]->status = 'inactive'; break;
        case 'no_camp': $GLOBALS['camp'] = null; break;
    }
    check($case . ' view contains no submit form', !str_contains($form->render_shortcode(), '<form'));
}

reset_participation();
$exit = submit_participation($form);
check('submission verifies its nonce and returns to the form', $GLOBALS['nonce'] === 'avpvh_save_own_participation' && $exit->status === 302 && str_contains($exit->body, 'activiteit_updated=1'));
check('saved attendance is bound to the submitted member and current camp', $GLOBALS['saved'][0]['member'] === 10 && $GLOBALS['saved'][0]['activity'] === 50);
check('nights are capped and free text is sanitized before saving', $GLOBALS['saved'][0]['fields'] === ['nights' => 3, 'nawacht' => true, 'diet' => 'vegetarisch', 'notes' => 'notitie']);
check('only valid in-camp attendance values are saved', $GLOBALS['saved_days'] === [['id' => 700, 'days' => ['2026-07-10' => 'n', '2026-07-12' => 'n']]]);

foreach (['anonymous', 'no_member', 'inactive', 'visitor', 'unrelated', 'old_camp', 'no_camp'] as $case) {
    reset_participation();
    switch ($case) {
        case 'anonymous': $GLOBALS['actor'] = 0; break;
        case 'no_member': $GLOBALS['members'] = []; break;
        case 'inactive': case 'visitor': $GLOBALS['members'][10]->status = $case; break;
        case 'unrelated': $_POST['member_id'] = 30; break;
        case 'old_camp': $_POST['activity_id'] = 49; break;
        case 'no_camp': $GLOBALS['camp'] = null; break;
    }
    $exit = submit_participation($form);
    check($case . ' submission is rejected before any write', $exit->status === 403 && !$GLOBALS['saved'] && !$GLOBALS['saved_days']);
}
reset_participation();
$_POST['member_id'] = 20;
$_POST['nights'] = '';
$_POST['day'] = [];
submit_participation($form);
check('household submission can clear previous attendance and nights', $GLOBALS['saved'][0]['member'] === 20 && $GLOBALS['saved'][0]['fields']['nights'] === '' && $GLOBALS['saved_days'][0]['days'] === []);
reset_participation();
$GLOBALS['officer'] = true;
$GLOBALS['members'][10]->status = 'inactive';
$_POST['member_id'] = 30;
submit_participation($form);
check('activity-management permission is checked independently of membership', $GLOBALS['saved'][0]['member'] === 30);
reset_participation();
$GLOBALS['camp']->start_date = $GLOBALS['camp']->end_date = null;
$GLOBALS['referer'] = false;
$exit = submit_participation($form);
check('undated camp uses the fallback limit and never stores arbitrary dates', $GLOBALS['saved'][0]['fields']['nights'] === 31 && $GLOBALS['saved_days'][0]['days'] === [] && str_starts_with($exit->body, home_url('/')));
reset_participation();
$GLOBALS['camp']->start_date = '2026-01-01';
$GLOBALS['camp']->end_date = '2026-12-31';
$_POST['day'] = ['2026-03-03' => 'n', '2026-03-04' => 'n'];
submit_participation($form);
check('oversized date ranges have a bounded accepted window', $GLOBALS['saved'][0]['fields']['nights'] === 62 && $GLOBALS['saved_days'][0]['days'] === ['2026-03-03' => 'n']);

foreach ([0, 999, 30] as $target) {
    reset_participation();
    $GLOBALS['officer'] = true;
    $GLOBALS['two_factor'] = false;
    $_POST['member_id'] = $target;
    $exit = submit_participation($form);
    check('1FA activity officer cannot write unrelated or missing target ' . $target, $exit->status === 403 && !$GLOBALS['saved']);
}
foreach ([0, 999] as $target) {
    reset_participation();
    $GLOBALS['officer'] = true;
    $_POST['member_id'] = $target;
    check('2FA activity officer cannot create orphan participation ' . $target, submit_participation($form)->status === 403 && !$GLOBALS['saved']);
}
reset_participation();
$GLOBALS['officer'] = true;
$GLOBALS['two_factor'] = false;
$_GET['member_id'] = 30;
$html = $form->render_shortcode();
check('1FA activity officer cannot select an unrelated profile for reading', str_contains($html, 'name="member_id" value="10"'));
$_POST['member_id'] = 20;
check('1FA activity officer retains household self-service', submit_participation($form)->status === 302 && $GLOBALS['saved'][0]['member'] === 20);

$failures = 0;
foreach ($results as [$label, $ok]) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    $failures += $ok ? 0 : 1;
}
exit($failures ? 1 : 0);
