<?php
declare(strict_types=1);

/**
 * Ad-hoc checks for member role label mapping.
 *
 * Run:
 *   php scripts/test-role-labels.php
 */

function member_role_label(string $role): string {
    return match (strtolower($role)) {
        'bestuur' => 'Bestuur',
        'feest' => 'Feest',
        'boek' => 'Boek',
        'fiscus' => 'Fiscus',
        'secretariaat' => 'Secretariaat',
        default => ucfirst($role),
    };
}

$cases = [
    ['input' => 'bestuur', 'expected' => 'Bestuur'],
    ['input' => 'feest', 'expected' => 'Feest'],
    ['input' => 'boek', 'expected' => 'Boek'],
    ['input' => 'fiscus', 'expected' => 'Fiscus'],
    ['input' => 'secretariaat', 'expected' => 'Secretariaat'],
    ['input' => 'onbekend', 'expected' => 'Onbekend'],
];

foreach ($cases as $case) {
    $actual = member_role_label($case['input']);
    $ok = $actual === $case['expected'];
    echo ($ok ? '[OK] ' : '[FAIL] ') . $case['input'] . ' => ' . $actual . PHP_EOL;
}

echo PHP_EOL . 'Done.' . PHP_EOL;
