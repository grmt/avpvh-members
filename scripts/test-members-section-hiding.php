<?php
declare(strict_types=1);

/**
 * Run: php scripts/test-members-section-hiding.php
 *
 * The members section (/leden/ and below) must stay hidden outside the
 * front-end page itself: REST items and collections, search and oEmbed.
 * All page data is fictional.
 */
define('ABSPATH', __DIR__ . '/');

class WP_Post {}
class WP_User {}
class WP_Error {
    public function __construct(public string $code = '', public string $message = '', public array $data = []) {}
}
class WP_REST_Request {
    public function __construct(private string $route) {}
    public function get_route(): string { return $this->route; }
}
class WP_Query {
    public array $vars = [];
    public function __construct(private bool $search = false, private bool $feed = false) {}
    public function is_search(): bool { return $this->search; }
    public function is_feed(): bool { return $this->feed; }
    public function get(string $key) { return $this->vars[$key] ?? ''; }
    public function set(string $key, $value): void { $this->vars[$key] = $value; }
}

// Fictional site: /leden/ is 10 with children 11 and 12; 20 is public.
const LEDEN_ROOT = 10;
$logged_in = false;
$member_status = 'active';
$can_edit_pages = false;
$admin = false;

function add_action(...$args): void {}
function add_filter(...$args): void {}
function __(string $text, string $domain = ''): string { return $text; }
function is_admin(): bool { return $GLOBALS['admin']; }
function wp_doing_ajax(): bool { return false; }
function is_user_logged_in(): bool { return $GLOBALS['logged_in']; }
function get_current_user_id(): int { return is_user_logged_in() ? 7 : 0; }
function current_user_can(string $cap): bool { return $cap === 'edit_pages' && $GLOBALS['can_edit_pages']; }
function avpvh_get_member_by_wp_user(int $id): ?object {
    return is_user_logged_in() ? (object) ['status' => $GLOBALS['member_status']] : null;
}
function get_posts(array $args): array {
    return ($args['name'] ?? '') === 'leden' ? [LEDEN_ROOT] : [];
}
function get_pages(array $args): array {
    return ($args['child_of'] ?? 0) === LEDEN_ROOT ? [(object) ['ID' => 11], (object) ['ID' => 12]] : [];
}
function wp_list_pluck(array $list, string $field): array {
    return array_map(static fn($item) => $item->$field, $list);
}

require __DIR__ . '/../includes/class-access.php';

$failures = 0;
function check(string $label, bool $ok): void {
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
}
function as_visitor(string $who): void {
    $GLOBALS['logged_in']      = $who !== 'anonymous';
    $GLOBALS['member_status']  = $who === 'member' ? 'active' : 'inactive';
    $GLOBALS['can_edit_pages'] = $who === 'editor';
}

$access = new AVPVH_Access();

foreach (['anonymous', 'ex-member'] as $who) {
    as_visitor($who);
    $rest = $access->hide_members_page_from_rest(null, [], new WP_REST_Request('/wp/v2/pages/11'));
    check("$who: REST page in /leden/ answers 404", $rest instanceof WP_Error && $rest->data['status'] === 404);
    $rest = $access->hide_members_page_from_rest(null, [], new WP_REST_Request('/wp/v2/pages/' . LEDEN_ROOT . '/revisions'));
    check("$who: REST sub-route of /leden/ answers 404", $rest instanceof WP_Error);
    check("$who: REST public page passes", $access->hide_members_page_from_rest(null, [], new WP_REST_Request('/wp/v2/pages/20')) === null);
    check("$who: REST /wp/v2/pages/110 is not /leden/'s 11", $access->hide_members_page_from_rest(null, [], new WP_REST_Request('/wp/v2/pages/110')) === null);

    $search = new WP_Query(true);
    $search->set('post__not_in', [99]);
    $access->exclude_members_pages_from_queries($search);
    check("$who: search leaves /leden/ out", $search->get('post__not_in') === [99, LEDEN_ROOT, 11, 12]);
    check("$who: search leaves password-protected posts out", $search->get('has_password') === false);

    $feed = new WP_Query(false, true);
    $access->exclude_members_pages_from_queries($feed);
    check("$who: feed leaves /leden/ out", $feed->get('post__not_in') === [LEDEN_ROOT, 11, 12]);
    check("$who: feed keeps password-protected posts", !array_key_exists('has_password', $feed->vars));

    // The plugin's own get_posts() lookups of the members section must
    // keep finding it, or the front-end gate opens.
    $lookup = new WP_Query();
    $access->exclude_members_pages_from_queries($lookup);
    check("$who: other queries are left alone", $lookup->vars === []);

    check("$who: REST collection leaves /leden/ out", $access->exclude_members_pages_from_rest_query(['post__not_in' => [99]])['post__not_in'] === [99, LEDEN_ROOT, 11, 12]);

    check("$who: oEmbed of /leden/ page finds nothing", $access->hide_members_page_from_oembed(12) === 0);
    check("$who: oEmbed of public page passes", $access->hide_members_page_from_oembed(20) === 20);
}

foreach (['member', 'editor'] as $who) {
    as_visitor($who);
    check("$who: REST page in /leden/ passes", $access->hide_members_page_from_rest(null, [], new WP_REST_Request('/wp/v2/pages/11')) === null);
    $search = new WP_Query(true);
    $access->exclude_members_pages_from_queries($search);
    check("$who: search is unchanged", $search->vars === []);
    check("$who: REST collection is unchanged", $access->exclude_members_pages_from_rest_query(['per_page' => 10]) === ['per_page' => 10]);
    check("$who: oEmbed of /leden/ page passes", $access->hide_members_page_from_oembed(12) === 12);
}

as_visitor('anonymous');
$GLOBALS['admin'] = true;
$admin_list = new WP_Query(true);
$access->exclude_members_pages_from_queries($admin_list);
check('wp-admin screens are left alone', $admin_list->vars === []);

echo $failures ? "\n$failures failure(s)\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
