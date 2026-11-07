<?php
declare(strict_types=1);

/**
 * Checks for AVPVH_Archive_Access: active members see every blog post, an
 * ex-member with the board's permission only the posts of their membership
 * years, everyone else none -- in listings, REST, oEmbed, previous/next
 * links, and in year_allowed() as avpvh-gallery uses it.
 *
 * Run:
 *   php scripts/test-archive-access.php
 */

define('ABSPATH', __DIR__ . '/');

$test_user   = 0;
$test_caps   = [];
$test_member = null;
$test_admin  = false;

function is_user_logged_in(): bool { return $GLOBALS['test_user'] > 0; }
function get_current_user_id(): int { return $GLOBALS['test_user']; }
function current_user_can(string $cap): bool { return in_array($cap, $GLOBALS['test_caps'], true); }
function avpvh_get_member_by_wp_user(int $id): ?object { return $GLOBALS['test_member']; }
function add_action(...$a): void {}
function add_filter(...$a): void {}
function is_admin(): bool { return $GLOBALS['test_admin']; }
function wp_doing_ajax(): bool { return false; }
function wp_doing_cron(): bool { return false; }
function __(string $s, string $d = ''): string { return $s; }

class WP_Post { public function __construct(public string $post_type, public string $post_date) {} }
class WP_Error { public function __construct(public string $code = '', public string $message = '', public array $data = []) {} }
class WP_REST_Request { public function __construct(private string $route) {} public function get_route(): string { return $this->route; } }
class WP_Query {
    public array $vars = [];
    public function __construct(public bool $main = false, public bool $singular = false) {}
    public function is_main_query(): bool { return $this->main; }
    public function is_singular(): bool { return $this->singular; }
    public function get(string $k) { return $this->vars[$k] ?? ''; }
    public function set(string $k, $v): void { $this->vars[$k] = $v; }
}
// Posts 1..4 from 2010, 2015, 2020, 2025.
$wpdb = new class {
    public string $posts = 'wp_posts';
    public function get_results(string $sql): array {
        return [(object) ['ID' => 1, 'y' => 2010], (object) ['ID' => 2, 'y' => 2015], (object) ['ID' => 3, 'y' => 2020], (object) ['ID' => 4, 'y' => 2025]];
    }
};

require_once dirname(__DIR__) . '/includes/class-archive-access.php';

$failures = 0;
function check(string $label, bool $ok): void {
    global $failures;
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    $failures += $ok ? 0 : 1;
}

function as_user(int $user, ?object $member, array $caps = []): void {
    $GLOBALS['test_user']   = $user;
    $GLOBALS['test_member'] = $member;
    $GLOBALS['test_caps']   = $caps;
    AVPVH_Archive_Access::reset();
}

function member(string $status, int $access = 0, ?int $from = null, ?int $to = null): object {
    return (object) ['status' => $status, 'archive_access' => $access, 'joined_year' => $from, 'left_year' => $to];
}

$a = new AVPVH_Archive_Access();
$hidden = fn(): array => AVPVH_Archive_Access::hidden_post_ids();

as_user(0, null);
check('a visitor sees no posts', $hidden() === [1, 2, 3, 4]);
check('a visitor: no year in the gallery', !AVPVH_Archive_Access::year_allowed(2015));

as_user(5, member('active'));
check('an active member sees every post', $hidden() === [] && AVPVH_Archive_Access::year_allowed(1990));

as_user(5, null, ['edit_pages']);
check('an editor sees every post', $hidden() === []);

as_user(5, null);
check('an account without a member record sees nothing', $hidden() === [1, 2, 3, 4]);

as_user(5, member('visitor', 1, 2010, 2020));
check('a visitor record sees nothing, even with the flag', $hidden() === [1, 2, 3, 4]);

as_user(5, member('inactive', 0, 2012, 2021));
check('an ex-member without permission sees nothing', $hidden() === [1, 2, 3, 4]);

as_user(5, member('inactive', 1, 2012, 2021));
check('an ex-member with permission sees their years only', $hidden() === [1, 4]);
check('gallery: a year inside the period', AVPVH_Archive_Access::year_allowed(2012) && AVPVH_Archive_Access::year_allowed(2021));
check('gallery: years outside, and an unknown year', !AVPVH_Archive_Access::year_allowed(2011) && !AVPVH_Archive_Access::year_allowed(2022) && !AVPVH_Archive_Access::year_allowed(0));
check('post_allowed follows the post year', AVPVH_Archive_Access::post_allowed(new WP_Post('post', '2015-06-01 10:00:00')) && !AVPVH_Archive_Access::post_allowed(new WP_Post('post', '2025-06-01 10:00:00')));
check('post_allowed leaves pages to AVPVH_Access', AVPVH_Archive_Access::post_allowed(new WP_Post('page', '2025-06-01 10:00:00')));

as_user(5, member('inactive', 1, 2012, null));
check('an ex-member without a left year sees nothing', $hidden() === [1, 2, 3, 4]);
as_user(5, member('inactive', 1, 2021, 2012));
check('a reversed period gives nothing', $hidden() === [1, 2, 3, 4]);

// Queries, REST, oEmbed, adjacent links for the ex-member with 2012-2021.
as_user(5, member('inactive', 1, 2012, 2021));
$q = new WP_Query();
$q->set('post__not_in', [9]);
$a->filter_query($q);
check('a listing leaves hidden posts out, keeping its own exclusions', $q->get('post__not_in') === [9, 1, 4]);
$q = new WP_Query();
$q->set('post__in', [1, 2, 3]);
$a->filter_query($q);
check('post__in loses the hidden posts', $q->get('post__in') === [2, 3]);
$q = new WP_Query();
$q->set('post__in', [4]);
$a->filter_query($q);
check('post__in with only hidden posts finds nothing', $q->get('post__in') === [0]);
$q = new WP_Query(true, true);
$a->filter_query($q);
check('the main query of a single post is left to the login redirect', $q->get('post__not_in') === '');
$GLOBALS['test_admin'] = true;
$q = new WP_Query();
$a->filter_query($q);
check('wp-admin is left alone', $q->get('post__not_in') === '');
$GLOBALS['test_admin'] = false;

check('REST collection args', $a->filter_rest_args([])['post__not_in'] === [1, 4]);
check('REST: a hidden post is a 404', $a->hide_post_from_rest(null, [], new WP_REST_Request('/wp/v2/posts/4')) instanceof WP_Error);
check('REST: also its revisions', $a->hide_post_from_rest(null, [], new WP_REST_Request('/wp/v2/posts/1/revisions')) instanceof WP_Error);
check('REST: a visible post stays', $a->hide_post_from_rest(null, [], new WP_REST_Request('/wp/v2/posts/2')) === null);
check('REST: pages are left alone', $a->hide_post_from_rest(null, [], new WP_REST_Request('/wp/v2/pages/4')) === null);
check('oEmbed hides a hidden post', $a->hide_post_from_oembed(4) === 0 && $a->hide_post_from_oembed(3) === 3);
check('previous/next links skip hidden posts', $a->filter_adjacent_where('WHERE x') === 'WHERE x AND p.ID NOT IN (1,4)');

as_user(5, member('active'));
check('an active member: queries untouched', $a->filter_rest_args([]) === [] && $a->filter_adjacent_where('WHERE x') === 'WHERE x');

echo $failures ? "\n$failures check(s) failed.\n" : "\nArchive access tests: OK\n";
exit($failures ? 1 : 0);
