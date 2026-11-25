<?php
declare(strict_types=1);

/** Run the browser-facing behavior checks without external dependencies. */
$process = proc_open(['node', dirname(__DIR__) . '/tests/ui-actions.cjs'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$output = stream_get_contents($pipes[1]);
$errors = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$status = proc_close($process);
echo $output;
if ($status !== 0 || $errors !== '') {
    echo '[FAIL] shared UI behavior checks' . PHP_EOL . $errors;
    exit(1);
}
