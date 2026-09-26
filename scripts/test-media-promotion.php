<?php
declare(strict_types=1);

/**
 * Ad-hoc checks for AVPVH_Media_Protection promoting private uploads to
 * public/ when a public page is published: the DB and page content may only
 * point at public/ once the files are actually there.
 *
 * Uses minimal WordPress stubs and a temp directory; must not run as root
 * (the failure case relies on a read-only directory).
 *
 * Run:
 *   php scripts/test-media-promotion.php
 */

$root = sys_get_temp_dir() . '/avpvh-media-test-' . getmypid();
define('ABSPATH', $root . '/wp/');
@mkdir(ABSPATH . 'wp-admin/includes', 0777, true);
file_put_contents(ABSPATH . 'wp-admin/includes/file.php', '<?php');
ini_set('error_log', $root . '/error.log');

class WP_Post {
    public int $ID = 0;
    public string $post_type = 'page';
    public string $post_password = '';
    public string $post_content = '';
}

class wpdb {
    public string $posts = 'posts';
    public string $postmeta = 'postmeta';
    public array $post_content = [];
    public array $guid = [];

    public function prepare(string $query, ...$args): array {
        return [$query, $args];
    }

    public function get_var($q) {
        global $meta;
        if (is_array($q) && str_contains($q[0], '_wp_attached_file')) {
            foreach ($meta as $id => $m) {
                if (in_array($m['_wp_attached_file'] ?? null, $q[1], true)) {
                    return $id;
                }
            }
            return null;
        }
        if (is_string($q) && preg_match('/ID=(\d+)/', $q, $m)) {
            return $this->guid[(int) $m[1]] ?? '';
        }
        return null;
    }

    public function update(string $table, array $data, array $where): void {
        if (isset($data['post_content'])) {
            $this->post_content[$where['ID']] = $data['post_content'];
        }
        if (isset($data['guid'])) {
            $this->guid[$where['ID']] = $data['guid'];
        }
    }
}

class Fake_Filesystem {
    public function move(string $src, string $dest): bool {
        return @rename($src, $dest);
    }
}

$meta = [];
function add_filter(...$a): void {}
function add_action(...$a): void {}
function wp_upload_dir(): array {
    global $root;
    return ['basedir' => $root . '/wp-content/uploads'];
}
function wp_mkdir_p(string $dir): bool {
    return is_dir($dir) || @mkdir($dir, 0777, true);
}
function WP_Filesystem(): void {
    global $wp_filesystem;
    $wp_filesystem = new Fake_Filesystem();
}
function get_post_meta(int $id, string $key, bool $single) {
    global $meta;
    return $meta[$id][$key] ?? '';
}
function update_post_meta(int $id, string $key, $value): void {
    global $meta;
    $meta[$id][$key] = $value;
}
function wp_get_attachment_metadata(int $id) {
    global $meta;
    return $meta[$id]['_wp_attachment_metadata'] ?? false;
}
function wp_update_attachment_metadata(int $id, array $data): void {
    global $meta;
    $meta[$id]['_wp_attachment_metadata'] = $data;
}
function get_posts(array $args): array { return []; }
function get_page_by_path(string $path) { return null; }
function get_pages(array $args): array { return []; }
function wp_list_pluck(array $list, string $field): array { return []; }

require __DIR__ . '/../includes/class-media-protection.php';

$files = [
    'doc.jpg',
    'doc-scaled.jpg',
    'doc-150x150.jpg',
    'doc-1024x704.jpg',
];

/**
 * Sets up a private attachment and returns a page referencing its 1024 size.
 */
function setup_case(): WP_Post {
    global $root, $meta, $wpdb, $files;
    exec('chmod -R u+w ' . escapeshellarg($root . '/wp-content') . ' 2>/dev/null; rm -rf ' . escapeshellarg($root . '/wp-content'));
    $private = $root . '/wp-content-pvh/uploads/private/2026/09';
    exec('chmod -R u+w ' . escapeshellarg($root . '/wp-content-pvh') . ' 2>/dev/null; rm -rf ' . escapeshellarg($root . '/wp-content-pvh'));
    mkdir($private, 0777, true);
    mkdir($root . '/wp-content-pvh/uploads/public/2026', 0777, true);
    foreach ($files as $f) {
        file_put_contents("$private/$f", 'x');
    }
    $meta = [
        7 => [
            '_wp_attached_file'       => 'private/2026/09/doc-scaled.jpg',
            '_wp_attachment_metadata' => [
                'file'           => 'private/2026/09/doc-scaled.jpg',
                'original_image' => 'doc.jpg',
                'sizes'          => [
                    'thumbnail' => ['file' => 'doc-150x150.jpg'],
                    'large'     => ['file' => 'doc-1024x704.jpg'],
                ],
            ],
        ],
    ];
    $wpdb = new wpdb();
    $wpdb->guid[7] = 'https://example.org/wp-content/uploads/private/2026/09/doc.jpg';

    $post = new WP_Post();
    $post->ID = 5;
    $post->post_content = '<img src="https://example.org/wp-content/uploads/private/2026/09/doc-1024x704.jpg" class="wp-image-7"/>';
    return $post;
}

function check(string $label, bool $ok): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
}

$mp     = new AVPVH_Media_Protection();
$public = $root . '/wp-content-pvh/uploads/public/2026/09';

// 1. Happy path: content references a resized copy only.
$post = setup_case();
$mp->on_page_published('publish', 'draft', $post);
foreach ($files as $f) {
    check("success: $f moved to public/", is_file("$public/$f"));
}
check('success: attachment points at public/', $meta[7]['_wp_attached_file'] === 'public/2026/09/doc-scaled.jpg');
check('success: attachment metadata file points at public/', $meta[7]['_wp_attachment_metadata']['file'] === 'public/2026/09/doc-scaled.jpg');
check('success: guid points at public/', str_contains($wpdb->guid[7], '/uploads/public/'));
check('success: content rewritten to public/', str_contains($wpdb->post_content[5] ?? '', '/uploads/public/2026/09/doc-1024x704.jpg'));

// 2. public/2026 not writable: nothing may point at public/.
$post = setup_case();
chmod($root . '/wp-content-pvh/uploads/public/2026', 0555);
$mp->on_page_published('publish', 'draft', $post);
check('failure: files stay in private/', is_file($root . '/wp-content-pvh/uploads/private/2026/09/doc-1024x704.jpg'));
check('failure: attachment still points at private/', $meta[7]['_wp_attached_file'] === 'private/2026/09/doc-scaled.jpg');
check('failure: guid still points at private/', str_contains($wpdb->guid[7], '/uploads/private/'));
check('failure: content not rewritten', !isset($wpdb->post_content[5]));
check('failure: logged', str_contains((string) @file_get_contents($root . '/error.log'), 'cannot create'));

exec('chmod -R u+w ' . escapeshellarg($root) . '; rm -rf ' . escapeshellarg($root));
echo PHP_EOL . 'Done.' . PHP_EOL;
