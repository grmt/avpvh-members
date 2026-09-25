<?php
defined('ABSPATH') || exit;

/**
 * Routes uploads to uploads/public/ or uploads/private/.
 *
 * Default is private — only uploads attached to a public page
 * (non-member, non-password-protected) go to public/.
 * Nginx blocks everything outside uploads/public/ unconditionally.
 */
class AVPVH_Media_Protection {

    public function __construct() {
        add_filter('upload_dir',            [$this, 'maybe_use_private_dir']);
        add_action('transition_post_status', [$this, 'on_page_published'], 10, 3);
    }

    public function maybe_use_private_dir(array $dirs): array {
        // Classic editor uses 'post_id', Gutenberg REST API uses 'post'
        $post_id = absint($_REQUEST['post_id'] ?? $_REQUEST['post'] ?? 0);
        $post    = $post_id ? get_post($post_id) : null;

        $bucket = $this->determine_bucket($post);

        $dirs['subdir'] = '/' . $bucket . $dirs['subdir'];
        $dirs['path']   = $dirs['basedir'] . $dirs['subdir'];
        $dirs['url']    = $dirs['baseurl'] . $dirs['subdir'];

        return $dirs;
    }

    /**
     * When a public page is published, move any private uploads referenced
     * in its content to uploads/public/ and update all DB references.
     */
    public function on_page_published(string $new, string $old, \WP_Post $post): void {
        if ($post->post_type !== 'page' || $new !== 'publish') {
            return;
        }
        if ($this->determine_bucket($post) !== 'public') {
            return; // member page — keep private
        }

        // Find all private upload URLs in the content
        if (!preg_match_all(
            '#/wp-content/uploads/private/([^\s"<>)]+)#',
            $post->post_content,
            $matches
        )) {
            return;
        }

        global $wpdb;
        $upload_dir = wp_upload_dir();
        $base_dir   = str_replace('/wp-content/', '/wp-content-pvh/', $upload_dir['basedir']);

        // Only rewrite URLs whose files actually made it to public/ — a failed
        // move keeps its private URL (still served) and is retried next publish.
        $new_content = $post->post_content;
        foreach (array_unique($matches[1]) as $rel) {
            if ($this->promote_to_public($rel, $base_dir, $wpdb)) {
                $new_content = str_replace(
                    '/wp-content/uploads/private/' . $rel,
                    '/wp-content/uploads/public/' . $rel,
                    $new_content
                );
            }
        }

        if ($new_content !== $post->post_content) {
            $wpdb->update($wpdb->posts, ['post_content' => $new_content], ['ID' => $post->ID]);
        }
    }

    /**
     * Moves a file (and all its sizes) from uploads/private/ to uploads/public/
     * and updates the attachment metadata in the DB.
     *
     * Returns false if any file could not be moved; the DB is then left
     * pointing at private/, where the files still are.
     */
    private function promote_to_public(string $rel, string $base_dir, \wpdb $wpdb): bool {
        $src_dir  = $base_dir . '/private/' . dirname($rel);
        $dest_dir = $base_dir . '/public/'  . dirname($rel);
        $filename = basename($rel);

        // Find the attachment by its file path. Content often references a
        // resized copy (foo-1024x704.jpg), so also try the base name and the
        // big-image "-scaled" variant WordPress stores as the main file.
        $base       = preg_replace('/-\d+x\d+(?=\.[^.\/]+$)/', '', $rel);
        $candidates = array_unique([
            'private/' . $rel,
            'private/' . $base,
            'private/' . preg_replace('/(?=\.[^.\/]+$)/', '-scaled', $base, 1),
        ]);
        $att_id     = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_wp_attached_file'
               AND meta_value IN (" . implode(',', array_fill(0, count($candidates), '%s')) . ')',
            ...$candidates
        ));

        // Collect files to move: original + all sizes
        $to_move = [$filename];
        if ($att_id) {
            $meta = wp_get_attachment_metadata($att_id);
            if (!empty($meta['file'])) {
                $to_move[] = basename($meta['file']);
            }
            if (!empty($meta['original_image'])) {
                $to_move[] = $meta['original_image'];
            }
            if (!empty($meta['sizes'])) {
                foreach ($meta['sizes'] as $size) {
                    $to_move[] = $size['file'];
                }
            }
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        global $wp_filesystem;

        if (!wp_mkdir_p($dest_dir)) {
            error_log("AVPVH media protection: cannot create {$dest_dir}, keeping private/{$rel}");
            return false;
        }

        $failed = [];
        foreach (array_unique($to_move) as $file) {
            $src  = $src_dir  . '/' . $file;
            $dest = $dest_dir . '/' . $file;
            if (file_exists($src) && !file_exists($dest)) {
                $wp_filesystem->move($src, $dest);
            }
            if (file_exists($src) && !file_exists($dest)) {
                $failed[] = $file;
            }
        }

        if ($failed) {
            error_log('AVPVH media protection: could not move ' . implode(', ', $failed) . " to {$dest_dir}, keeping private/{$rel}");
            return false;
        }

        // Update DB
        if ($att_id) {
            $new_rel = preg_replace('#^private/#', 'public/', get_post_meta($att_id, '_wp_attached_file', true));
            update_post_meta($att_id, '_wp_attached_file', $new_rel);
            $meta = wp_get_attachment_metadata($att_id);
            if (!empty($meta)) {
                $meta['file'] = $new_rel;
                wp_update_attachment_metadata($att_id, $meta);
            }
            $wpdb->update(
                $wpdb->posts,
                ['guid' => str_replace('/private/', '/public/', $wpdb->get_var(
                    "SELECT guid FROM {$wpdb->posts} WHERE ID={$att_id}" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $att_id is (int)-cast at declaration above and never reassigned
                ))],
                ['ID' => $att_id]
            );
        }

        return true;
    }

    /**
     * Returns 'public' only for uploads attached to a non-member public page.
     * Everything else (posts, member pages, no context) → 'private'.
     */
    private function determine_bucket(?\WP_Post $post): string {
        if (!$post || $post->post_type !== 'page') {
            return 'private';
        }
        if ($post->post_password !== '') {
            return 'private';
        }
        if ($this->is_member_page($post->ID)) {
            return 'private';
        }
        return 'public';
    }

    /**
     * Returns true if the page is in the /leden/ hierarchy or is the login page.
     */
    private function is_member_page(int $page_id): bool {
        $roots = get_posts([
            'name'        => 'leden',
            'post_type'   => 'page',
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields'      => 'ids',
        ]);
        $login = get_page_by_path('avpvh-login');
        if ($login) {
            $roots[] = $login->ID;
        }

        $all = [];
        foreach ($roots as $root_id) {
            $children = get_pages(['child_of' => $root_id, 'post_status' => 'publish']);
            $all      = array_merge($all, wp_list_pluck($children, 'ID'), [$root_id]);
        }

        return in_array($page_id, $all, true);
    }
}
