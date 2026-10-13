<?php
declare(strict_types=1);

/**
 * Regression test: writing rights come from the "bloggers" directory group,
 * not from the WP role stored on a member's WP user.
 *
 * Run:
 *   php scripts/test-blogger-capabilities.php
 */

define('ABSPATH', __DIR__ . '/');

class WP_User {
    public function __construct(public int $ID) {}
}

// WP user 7 = ordinary member 100, WP user 8 = blogger member 200,
// WP user 9 = not a member (e.g. a plain WP editor).
function avpvh_get_member_by_wp_user(int $user_id): ?object {
    return [7 => (object) ['id' => 100], 8 => (object) ['id' => 200]][$user_id] ?? null;
}

class AVPVH_Roles {
    public static function member_is_blogger(int $member_id): bool {
        return $member_id === 200;
    }
}

require_once dirname(__DIR__) . '/includes/class-access.php';

function check(bool $condition, string $label): void {
    echo ($condition ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$condition) {
        exit(1);
    }
}

$access = (new ReflectionClass(AVPVH_Access::class))->newInstanceWithoutConstructor();
$stored_editor_caps = [
    'read' => true, 'edit_posts' => true, 'delete_posts' => true,
    'publish_posts' => true, 'edit_pages' => true, 'upload_files' => true,
    'read_private_posts' => true,
];

$ordinary = $access->filter_member_capabilities($stored_editor_caps, [], [], new WP_User(7));
check(!empty($ordinary['read']), 'gewoon lid houdt read');
check(empty($ordinary['edit_posts']), 'gewoon lid kan geen berichten bewerken');
check(empty($ordinary['publish_posts']), 'gewoon lid kan niet publiceren');
check(empty($ordinary['edit_pages']), 'gewoon lid kan geen pagina\'s bewerken');
check(empty($ordinary['upload_files']), 'gewoon lid kan geen media uploaden');
check(empty($ordinary['read_private_posts']), 'gewoon lid kan geen privéberichten lezen');

$blogger = $access->filter_member_capabilities($stored_editor_caps, [], [], new WP_User(8));
check(!empty($blogger['edit_posts']), 'blogger kan eigen concepten bewerken');
check(!empty($blogger['delete_posts']), 'blogger kan eigen concepten verwijderen');
check(empty($blogger['publish_posts']), 'blogger kan niet publiceren');
check(empty($blogger['edit_pages']), 'blogger kan geen pagina\'s bewerken');
check(empty($blogger['upload_files']), 'blogger kan geen media uploaden');

$admin_caps = ['manage_options' => true, 'edit_pages' => true, 'publish_posts' => true];
check($access->filter_member_capabilities($admin_caps, [], [], new WP_User(7)) === $admin_caps, 'WP-beheerder houdt alle capabilities');
check($access->filter_member_capabilities($stored_editor_caps, [], [], new WP_User(9)) === $stored_editor_caps, 'niet-lid houdt opgeslagen capabilities');

echo "Blogger capability tests: OK\n";
