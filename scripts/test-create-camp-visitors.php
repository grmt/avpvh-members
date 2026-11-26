<?php
/**
 * Tests scripts/create-camp-visitors.php with stub AVPVH_DB / AVPVH_Admin;
 * no database, fictitious names only.
 * Run: php scripts/test-create-camp-visitors.php
 */

define('ABSPATH', __DIR__ . '/');
define('AVPVH_CAMP_VISITORS_NO_RUN', true);

class WP_Error {
    public function __construct(private string $message = '') {}
    public function get_error_message(): string { return $this->message; }
}
function is_wp_error($value): bool { return $value instanceof WP_Error; }

class AVPVH_DB {
    /** @var array<int, array{0: string, 1: string, 2: string}> */
    public static array $members = [10 => ['Bestaand', '', 'Voorbeeld']];
    public static function find_members_by_name_or_alias(string $first, string $suffix, string $last): array {
        $norm = static fn(string $name): string => strtolower(trim(preg_replace('/\s+/', ' ', $name)));
        $key = $norm("$first $suffix $last");
        $found = [];
        foreach (self::$members as $id => [$f, $s, $l]) {
            if ($norm("$f $s $l") === $key) {
                $found[] = (object) ['id' => $id];
            }
        }
        foreach (self::$aliases as [$id, $f, $s, $l]) {
            if ($norm("$f $s $l") === $key) {
                $found[] = (object) ['id' => $id];
            }
        }
        return $found;
    }
    /** @var array<int, array{0: int, 1: string, 2: string, 3: string}> */
    public static array $aliases = [];
    public static array $alias_calls = [];
    public static string $alias_error = '';
    public static function get_member(int $id): ?object {
        return isset(self::$members[$id]) ? (object) ['id' => $id] : null;
    }
    public static function save_name_alias(int $member_id, array $data): string {
        self::$alias_calls[] = [$member_id, $data];
        if (self::$alias_error === '') {
            self::$aliases[] = [$member_id, $data['first_name'], $data['suffix'], $data['last_name']];
        }
        return self::$alias_error;
    }
}

class AVPVH_Admin {
    public static array $calls = [];
    public static bool $fail = false;
    public static function create_person(string $first, string $suffix, string $last, ?string $birth_date, ?int $birth_year, string $status, array $flags): int|WP_Error {
        self::$calls[] = [$first, $suffix, $last, $birth_date, $birth_year, $status, $flags];
        if (self::$fail) {
            return new WP_Error('directory down');
        }
        $id = max(array_keys(AVPVH_DB::$members)) + 1;
        AVPVH_DB::$members[$id] = [$first, $suffix, $last];
        return $id;
    }
}

require __DIR__ . '/create-camp-visitors.php';

$failures = 0;
function check(bool $ok, string $label): void {
    global $failures;
    echo ($ok ? '[ok]   ' : '[FAIL] ') . $label . "\n";
    $failures += $ok ? 0 : 1;
}

$people = [
    ['first_name' => 'Fictief', 'suffix' => 'van', 'last_name' => 'Nieuw', 'create' => true, 'review' => false],
    ['first_name' => 'Bestaand', 'suffix' => '', 'last_name' => 'Voorbeeld', 'create' => true],
    ['first_name' => 'Overslaan', 'suffix' => '', 'last_name' => 'Persoon', 'create' => false],
    ['first_name' => 'Twijfel', 'suffix' => '', 'last_name' => 'Geval', 'create' => true, 'review' => true],
    ['first_name' => '', 'suffix' => '', 'last_name' => 'Zonder', 'create' => true],
    ['first_name' => 'Fictief', 'suffix' => 'van', 'last_name' => 'Nieuw', 'create' => true],
    'geen object',
];

// Dry-run: nothing created, everything reported.
$dry = avpvh_create_camp_visitors($people, false);
check(AVPVH_Admin::$calls === [], 'dry-run creates nobody');
check($dry['created'] === 2 && $dry['existing'] === 1 && $dry['skipped'] === 2 && $dry['errors'] === 2, 'dry-run counts');
check(str_contains($dry['lines'][1], 'member_id 10'), 'existing person reported by id');
check(str_contains($dry['lines'][3], 'review'), 'entry still under review refused');
check(!str_contains(implode("\n", $dry['lines']), 'Fictief'), 'names are not printed');

// Apply: one visitor created, the duplicate entry then matches it.
$applied = avpvh_create_camp_visitors($people, true);
check(count(AVPVH_Admin::$calls) === 1, 'apply creates the new person once');
check(AVPVH_Admin::$calls[0] === ['Fictief', 'van', 'Nieuw', null, null, 'visitor', []], 'created as visitor with name only');
check($applied['created'] === 1 && $applied['existing'] === 2, 'second entry for the same name is skipped as existing');
check(str_contains($applied['lines'][0], 'aangemaakt als Bezoeker: member_id 11'), 'new member id reported');

// Re-running is safe.
$again = avpvh_create_camp_visitors($people, true);
check(count(AVPVH_Admin::$calls) === 1 && $again['created'] === 0, 're-run creates nobody');

// A directory failure is an error, not a crash.
AVPVH_Admin::$fail = true;
$failed = avpvh_create_camp_visitors([['first_name' => 'Ander', 'suffix' => '', 'last_name' => 'Fout', 'create' => true]], true);
check($failed['errors'] === 1 && str_contains($failed['lines'][0], 'directory down'), 'create failure reported');

// A name variant of an existing member instead of a new person.
$variants = [
    ['first_name' => 'Bestaan', 'suffix' => '', 'last_name' => 'Voorbeeld', 'create' => false, 'alias_of' => 10],
    ['first_name' => 'Spook', 'suffix' => '', 'last_name' => 'Persoon', 'create' => true, 'alias_of' => 99],
    ['first_name' => 'Twijfel', 'suffix' => '', 'last_name' => 'Variant', 'alias_of' => 10, 'review' => true],
];
$v_dry = avpvh_create_camp_visitors($variants, false);
check(AVPVH_DB::$alias_calls === [] && $v_dry['aliased'] === 1 && $v_dry['errors'] === 2, 'dry-run records no variant');
check(str_contains($v_dry['lines'][0], 'naamvariant van member_id 10'), 'variant reported with the member id');
check(str_contains($v_dry['lines'][1], 'member_id 99 bestaat niet'), 'unknown alias_of refused');
$people_before = count(AVPVH_Admin::$calls);
$v_applied = avpvh_create_camp_visitors($variants, true);
check(AVPVH_DB::$alias_calls === [[10, ['first_name' => 'Bestaan', 'suffix' => '', 'last_name' => 'Voorbeeld', 'alias_type' => 'spelling', 'source' => 'kampimport']]], 'variant saved as spelling from the camp import');
check(count(AVPVH_Admin::$calls) === $people_before && $v_applied['aliased'] === 1, 'a variant creates no person');
$v_again = avpvh_create_camp_visitors([$variants[0]], true);
check($v_again['existing'] === 1 && count(AVPVH_DB::$alias_calls) === 1, 're-run sees the recorded variant');
AVPVH_DB::$alias_error = 'fout';
$v_failed = avpvh_create_camp_visitors([['first_name' => 'Ander', 'suffix' => '', 'last_name' => 'Variant', 'alias_of' => 10]], true);
check($v_failed['errors'] === 1 && str_contains($v_failed['lines'][0], '(fout)'), 'variant failure reported');

// Loading the file.
$dir = sys_get_temp_dir() . '/avpvh-camp-visitors-' . getmypid();
@mkdir($dir);
check(str_starts_with((string) avpvh_camp_visitors_load("$dir/missing.json"), 'Bestand ontbreekt'), 'missing file reported');
file_put_contents("$dir/bad.json", '{"people": "x"}');
check(str_starts_with((string) avpvh_camp_visitors_load("$dir/bad.json"), 'Ongeldig'), 'file without people array refused');
file_put_contents("$dir/status.json", '{"status": "active", "people": []}');
check(str_contains((string) avpvh_camp_visitors_load("$dir/status.json"), 'visitor'), 'other status refused');
file_put_contents("$dir/ok.json", json_encode(['status' => 'visitor', 'people' => [['first_name' => 'Een']]]));
check(avpvh_camp_visitors_load("$dir/ok.json") === [['first_name' => 'Een']], 'valid file returns the people');
array_map('unlink', glob("$dir/*.json"));
rmdir($dir);

// Report and exit status.
ob_start();
$status = avpvh_camp_visitors_report($dry, false);
$out = (string) ob_get_clean();
check($status === 1 && str_contains($out, 'DRY-RUN') && str_contains($out, 'Aan te maken: 2;') && str_contains($out, 'niets gewijzigd'), 'dry-run report');
ob_start();
$status = avpvh_camp_visitors_report($again, true);
$out = (string) ob_get_clean();
check($status === 1 && str_contains($out, 'BEZOEKERS AANMAKEN') && str_contains($out, 'naamvarianten vastgelegd: 0;') && !str_contains($out, 'niets gewijzigd'), 'apply report');
ob_start();
$status = avpvh_camp_visitors_report(['created' => 0, 'existing' => 1, 'skipped' => 0, 'errors' => 0, 'lines' => []], true);
ob_end_clean();
check($status === 0, 'exit 0 without errors');

echo $failures ? "FAIL: {$failures}\n" : "All tests passed.\n";
exit($failures ? 1 : 0);
