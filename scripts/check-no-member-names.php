<?php
/**
 * Read-only privacy check: report committed-looking source files that contain
 * an official member name or stored alias. Matched names are never printed.
 * Run with wp eval-file; optionally set AVPVH_PRIVACY_SCAN_ROOT.
 */

defined('ABSPATH') || exit;

global $wpdb;

$root = getenv('AVPVH_PRIVACY_SCAN_ROOT') ?: dirname(__DIR__);
$include_single_parts = getenv('AVPVH_PRIVACY_INCLUDE_SINGLE_PARTS') === '1';
$scan_git_history = getenv('AVPVH_PRIVACY_SCAN_GIT_HISTORY') === '1';
$root = realpath($root);
if (!$root || !is_dir($root)) {
    fwrite(STDERR, "Scanmap bestaat niet.\n");
    exit(2);
}

$names = [];
$members = $wpdb->get_results(
    "SELECT first_name, suffix, last_name, passport_name
     FROM {$wpdb->prefix}avm_members"
) ?: [];
foreach ($members as $member) {
    if ($include_single_parts) {
        $names[] = trim((string) $member->first_name);
        $names[] = trim((string) $member->last_name);
    }
    $parts = array_values(array_filter([
        trim((string) $member->first_name),
        trim((string) $member->suffix),
        trim((string) $member->last_name),
    ]));
    $without_suffix = array_values(array_filter([
        trim((string) $member->first_name),
        trim((string) $member->last_name),
    ]));
    $names[] = implode(' ', $parts);
    $names[] = implode(' ', $without_suffix);
    $names[] = trim((string) $member->last_name) . ', ' . trim((string) $member->first_name);
    $names[] = trim((string) $member->passport_name);
}

$alias_table = "{$wpdb->prefix}avm_member_name_aliases";
if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $alias_table))) {
    $aliases = $wpdb->get_results(
        "SELECT first_name, suffix, last_name FROM {$alias_table}"
    ) ?: [];
    foreach ($aliases as $alias) {
        if ($include_single_parts) {
            $names[] = trim((string) $alias->first_name);
            $names[] = trim((string) $alias->last_name);
        }
        $names[] = implode(' ', array_values(array_filter([
            trim((string) $alias->first_name),
            trim((string) $alias->suffix),
            trim((string) $alias->last_name),
        ])));
    }
}

$names = array_values(array_unique(array_filter(
    array_map(static fn(string $name): string => trim(preg_replace('/\s+/u', ' ', $name)), $names),
    static fn(string $name): bool => mb_strlen($name, 'UTF-8') >= 5
        && ($include_single_parts || str_contains($name, ' '))
)));

$extensions = ['php', 'py', 'sh', 'md', 'yml', 'yaml', 'json', 'js', 'css'];
$skip_directories = ['.git', '.venv', 'vendor', 'node_modules', '.claude'];
$hits = [];
$scan_contents = static function (string $label, string $contents) use ($names, &$hits): void {
    foreach ($names as $name) {
        $name_parts = preg_split('/\s+/u', $name);
        $pattern = '/(?<![\p{L}\p{N}])'
            . implode('[\\s._-]+', array_map(static fn(string $part): string => preg_quote($part, '/'), $name_parts))
            . '(?![\p{L}\p{N}])/iu';
        if (!preg_match($pattern, $contents, $match, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        $offset = $match[0][1];
        $line = substr_count(substr($contents, 0, $offset), "\n") + 1;
        $hits[] = $label . ':' . $line . '; name_hash=' . substr(hash('sha256', $name), 0, 12);
    }
};
$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $file) use ($skip_directories): bool {
            return !$file->isDir() || !in_array($file->getFilename(), $skip_directories, true);
        }
    )
);

foreach ($iterator as $file) {
    if (!$file->isFile() || !in_array(strtolower($file->getExtension()), $extensions, true)) {
        continue;
    }
    if (str_contains($file->getFilename(), '.local.')) {
        continue;
    }
    $contents = file_get_contents($file->getPathname());
    if ($contents === false) {
        continue;
    }
    $relative = ltrim(substr($file->getPathname(), strlen($root)), DIRECTORY_SEPARATOR);
    $scan_contents($relative, $contents);
}

if ($scan_git_history) {
    $descriptor_spec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open(
        ['git', '-C', $root, 'log', '--all', '--format=%H%n%s%n%b%n---'],
        $descriptor_spec,
        $pipes
    );
    if (!is_resource($process)) {
        fwrite(STDERR, "Git-historycontrole kon niet starten.\n");
        exit(2);
    }
    fclose($pipes[0]);
    $history = stream_get_contents($pipes[1]);
    $git_error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $git_status = proc_close($process);
    if ($git_status !== 0) {
        fwrite(STDERR, "Git-historycontrole mislukte: " . trim($git_error) . "\n");
        exit(2);
    }
    $scan_contents('git-history', $history);
}

if ($hits) {
    echo "PRIVACY CHECK FAILED: " . count($hits) . " match(es).\n";
    foreach (array_unique($hits) as $hit) {
        echo $hit . "\n";
    }
    exit(1);
}

echo "Privacy check OK: no official member names or aliases found.\n";
