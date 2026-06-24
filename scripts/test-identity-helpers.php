<?php
declare(strict_types=1);

/**
 * Ad-hoc checks for the identity helper logic.
 *
 * Run:
 *   php scripts/test-identity-helpers.php
 */

function normalize_identity_email(string $email): string {
    return strtolower(trim($email));
}

function format_result(string $label, bool $ok): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
}

$cases = [
    ['label' => 'lowercase conversion', 'input' => 'Test@Example.com', 'expected' => 'test@example.com'],
    ['label' => 'trim spaces', 'input' => '  user@domain.nl  ', 'expected' => 'user@domain.nl'],
    ['label' => 'empty string', 'input' => '', 'expected' => ''],
];

foreach ($cases as $case) {
    $actual = normalize_identity_email($case['input']);
    format_result($case['label'], $actual === $case['expected']);
    if ($actual !== $case['expected']) {
        echo '  expected: ' . $case['expected'] . PHP_EOL;
        echo '  actual:   ' . $actual . PHP_EOL;
    }
}

echo PHP_EOL . 'Done.' . PHP_EOL;
