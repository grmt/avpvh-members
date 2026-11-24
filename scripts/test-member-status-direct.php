<?php
declare(strict_types=1);

/**
 * Opening includes/class-member-status.php directly (outside WordPress, no
 * ABSPATH) stops at once, before anything after it runs.
 *
 * Run:
 *   php scripts/test-member-status-direct.php
 */

ob_start();
register_shutdown_function(static function (): void {
    $output = (string) ob_get_clean();
    echo ($output === '' ? '[OK] ' : '[FAIL] ') . 'direct access stops before the code after it' . PHP_EOL;
});
require dirname(__DIR__) . '/includes/class-member-status.php';
echo 'still running';
