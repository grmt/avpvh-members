<?php
/**
 * Migrates existing uploads to uploads/public/ or uploads/private/.
 *
 * Principle: private by default.
 * A file goes to public/ only if it is explicitly referenced in a
 * public page (non-member, non-password-protected, post_type=page).
 * Everything else — blog posts, member pages, orphans — goes to private/.
 *
 * Run via:
 *   docker exec -e WORDPRESS_DB_PASSWORD=... scripts-wordpress-pvh-1 \
 *     php /var/www/html/wp-content-pvh/plugins/avpvh-members/bin/migrate-uploads.php [--dry-run]
 */

define('ABSPATH', '/var/www/html/');
require_once ABSPATH . 'wp-load.php';

global $wpdb;

$dry_run  = in_array('--dry-run', $argv ?? [], true);
$base_url = wp_upload_dir()['baseurl'];
// Nginx rewrites /wp-content/ → /wp-content-pvh/ on the filesystem.
$base_dir = str_replace('/wp-content/', '/wp-content-pvh/', wp_upload_dir()['basedir']);

printf("Base dir : %s\n", $base_dir);
printf("Base URL : %s\n", $base_url);
printf("Mode     : %s\n\n", $dry_run ? 'DRY RUN' : 'LIVE');

// ── Build set of public file paths ───────────────────────────────────────────
// Collect all IDs to exclude (leden hierarchy + login page)
echo "Publieke referenties ophalen…\n";

function get_excluded_ids(): array {
    global $wpdb;
    $roots = $wpdb->get_col("
        SELECT ID FROM {$wpdb->posts}
        WHERE post_name IN ('leden','avpvh-login') AND post_type='page'
    ");
    $all = array_map('intval', $roots);
    $queue = $all;
    while (!empty($queue)) {
        $in       = implode(',', $queue);
        $children = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_parent IN ($in) AND post_type='page'");
        $children = array_map('intval', $children);
        $queue    = array_diff($children, $all);
        $all      = array_merge($all, $children);
    }
    return $all;
}

$excluded    = get_excluded_ids();
$not_in      = $excluded ? 'AND ID NOT IN (' . implode(',', $excluded) . ')' : '';

$public_contents = $wpdb->get_col("
    SELECT post_content FROM {$wpdb->posts}
    WHERE post_status   = 'publish'
      AND post_password = ''
      AND post_type     = 'page'
      {$not_in}
");

// Extract every /wp-content/uploads/… path referenced in public pages
$public_paths = [];  // relative to base_dir, e.g. "2024/04/photo.jpg"
foreach ($public_contents as $content) {
    if (preg_match_all('#/wp-content/uploads/(?!public/|private/)([^\s"<>)]+)#', $content, $m)) {
        foreach ($m[1] as $path) {
            $public_paths[trim($path, '/')] = true;
        }
    }
}
printf("  %d unieke bestandspaden in publieke pagina's.\n\n", count($public_paths));

// ── Helper: determine bucket for an attachment ────────────────────────────────
// Checks original path AND all generated thumbnail sizes against public_paths.
// public_paths contains what Gutenberg actually stored in post_content (often
// a sized variant like photo-1024x768.jpg, not the original photo.jpg).
function bucket_for_attachment(string $rel, ?array $meta, array $public_paths): string {
    if (isset($public_paths[$rel])) return 'public';

    $dir = dirname($rel);
    if (!empty($meta['sizes'])) {
        foreach ($meta['sizes'] as $size) {
            $size_rel = ($dir !== '.') ? $dir . '/' . $size['file'] : $size['file'];
            if (isset($public_paths[$size_rel])) return 'public';
        }
    }
    return 'private';
}

// For non-attachment files: direct match or strip size suffix to find original
function bucket_for(string $rel, array $public_paths): string {
    if (isset($public_paths[$rel])) return 'public';
    $without_size = preg_replace('/-\d+x\d+(\.[^.]+)$/', '$1', basename($rel));
    $dir          = dirname($rel);
    $base_nosize  = ($dir !== '.') ? $dir . '/' . $without_size : $without_size;
    if (isset($public_paths[$base_nosize])) return 'public';
    return 'private';
}

// ── Helper: move file ─────────────────────────────────────────────────────────
function move_file(string $src, string $dest, bool $dry_run): bool {
    if (!file_exists($src)) {
        printf("    [WARN] niet gevonden: %s\n", basename($src));
        return true; // not an error
    }
    if (!$dry_run) {
        wp_mkdir_p(dirname($dest));
        if (!rename($src, $dest)) {
            printf("    [ERR] verplaatsen mislukt: %s\n", basename($src));
            return false;
        }
    }
    return true;
}

// ── Migrate WordPress attachments ─────────────────────────────────────────────
echo "Attachments migreren…\n";

$attachments = $wpdb->get_results("
    SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment'
");

$counts = ['public' => 0, 'private' => 0, 'skipped' => 0, 'errors' => 0];

foreach ($attachments as $att) {
    $rel_path = get_post_meta($att->ID, '_wp_attached_file', true);
    if (!$rel_path) { $counts['skipped']++; continue; }
    if (str_starts_with($rel_path, 'public/') || str_starts_with($rel_path, 'private/')) {
        $counts['skipped']++; continue;
    }

    $bucket  = bucket_for_attachment($rel_path, $meta, $public_paths);
    $new_rel = $bucket . '/' . $rel_path;
    $old_dir = $base_dir . '/' . dirname($rel_path);
    $new_dir = $base_dir . '/' . dirname($new_rel);

    printf("  [%s] #%d %s\n", strtoupper($bucket), $att->ID, $rel_path);

    // Collect all files: original + all sizes
    $meta    = wp_get_attachment_metadata($att->ID);
    $to_move = [basename($rel_path)];
    if (!empty($meta['sizes'])) {
        foreach ($meta['sizes'] as $size) $to_move[] = $size['file'];
    }

    $ok = true;
    foreach (array_unique($to_move) as $file) {
        $ok = move_file($old_dir . '/' . $file, $new_dir . '/' . $file, $dry_run) && $ok;
    }

    if (!$ok) { $counts['errors']++; continue; }

    if (!$dry_run) {
        update_post_meta($att->ID, '_wp_attached_file', $new_rel);
        if (!empty($meta)) {
            $meta['file'] = $new_rel;
            wp_update_attachment_metadata($att->ID, $meta);
        }
        $new_guid = str_replace($base_url . '/' . $rel_path, $base_url . '/' . $new_rel, $att->ID);
        $wpdb->update($wpdb->posts,
            ['guid' => str_replace('/wp-content/uploads/' . $rel_path, '/wp-content/uploads/' . $new_rel,
                $wpdb->get_var("SELECT guid FROM {$wpdb->posts} WHERE ID={$att->ID}"))],
            ['ID' => $att->ID]
        );
    }
    $counts[$bucket]++;
}

// ── Migrate files without DB entry ────────────────────────────────────────────
echo "\nBestanden zonder DB-entry migreren…\n";

$known = array_flip($wpdb->get_col("
    SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_wp_attached_file'
"));
$known_basenames = [];
foreach ($wpdb->get_col("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_wp_attachment_metadata'") as $s) {
    $d = @unserialize($s);
    if (!empty($d['sizes'])) foreach ($d['sizes'] as $sz) $known_basenames[$sz['file']] = true;
}

$orphan_counts = ['public' => 0, 'private' => 0];
$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base_dir, RecursiveDirectoryIterator::SKIP_DOTS));
foreach ($iter as $file) {
    if (!$file->isFile()) continue;
    $abs = $file->getPathname();
    $rel = ltrim(str_replace($base_dir . '/', '', $abs), '/');

    if (str_starts_with($rel, 'public/') || str_starts_with($rel, 'private/')) continue;
    if (isset($known[$rel]) || isset($known_basenames[basename($rel)])) continue;

    $bucket   = bucket_for($rel, $public_paths);
    $dest_abs = $base_dir . '/'. $bucket . '/' . $rel;
    printf("  [%s] %s\n", strtoupper($bucket), $rel);
    move_file($abs, $dest_abs, $dry_run);
    $orphan_counts[$bucket]++;
}

// ── Update URL references in post_content and postmeta ───────────────────────
echo "\nURL-verwijzingen bijwerken…\n";

$pattern = '#(/wp-content/uploads/)(?!public/|private/)([^\s"<>)]+)#';
$replace = fn($m) => $m[1] . (isset($public_paths[$m[2]]) ? 'public/' : 'private/') . $m[2];

$url_updates = $meta_updates = 0;

foreach ($wpdb->get_results("
    SELECT ID, post_content FROM {$wpdb->posts}
    WHERE post_content LIKE '%/wp-content/uploads/%'
      AND post_type != 'attachment'
      AND post_status IN ('publish','draft','private')
") as $post) {
    $new = preg_replace_callback($pattern, $replace, $post->post_content, -1, $n);
    if ($n && $new !== $post->post_content) {
        printf("  post #%d: %d verwijzing(en)\n", $post->ID, $n);
        if (!$dry_run) $wpdb->update($wpdb->posts, ['post_content' => $new], ['ID' => $post->ID]);
        $url_updates += $n;
    }
}

foreach ($wpdb->get_results("
    SELECT meta_id, meta_value FROM {$wpdb->postmeta}
    WHERE meta_value LIKE '%/wp-content/uploads/%'
      AND meta_key NOT IN ('_wp_attached_file','_wp_attachment_metadata')
") as $row) {
    $new = preg_replace_callback($pattern, $replace, $row->meta_value, -1, $n);
    if ($n && $new !== $row->meta_value) {
        printf("  meta #%d: %d verwijzing(en)\n", $row->meta_id, $n);
        if (!$dry_run) $wpdb->update($wpdb->postmeta, ['meta_value' => $new], ['meta_id' => $row->meta_id]);
        $meta_updates += $n;
    }
}

// ── Summary ───────────────────────────────────────────────────────────────────
echo "\n=== Klaar ===\n";
printf("  Attachments → public   : %d\n", $counts['public']);
printf("  Attachments → private  : %d\n", $counts['private']);
printf("  Overige → public       : %d\n", $orphan_counts['public']);
printf("  Overige → private      : %d\n", $orphan_counts['private']);
printf("  Overgeslagen           : %d\n", $counts['skipped']);
printf("  Fouten                 : %d\n", $counts['errors']);
printf("  URL-updates content    : %d\n", $url_updates);
printf("  URL-updates meta       : %d\n", $meta_updates);
if ($dry_run) echo "\n(Dry run — geen wijzigingen. Laat --dry-run weg om te migreren.)\n";
