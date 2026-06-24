<?php
declare(strict_types=1);

/**
 * Ad-hoc checks for identity limit logic.
 *
 * Run:
 *   php scripts/test-identity-limit.php
 */

function can_add_identity(int $current_count, int $max = 3): bool {
    return $current_count < $max;
}

$cases = [
    ['count' => 0, 'expected' => true],
    ['count' => 1, 'expected' => true],
    ['count' => 2, 'expected' => true],
    ['count' => 3, 'expected' => false],
    ['count' => 4, 'expected' => false],
];

foreach ($cases as $case) {
    $actual = can_add_identity($case['count']);
    $ok = $actual === $case['expected'];
    echo ($ok ? '[OK] ' : '[FAIL] ') . 'count=' . $case['count'] . ' => ' . ($actual ? 'allow' : 'deny') . PHP_EOL;
}

echo PHP_EOL . 'Done.' . PHP_EOL;
