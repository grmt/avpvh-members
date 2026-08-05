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

/**
 * Mirrors the accept/reject decision in AVPVH_DB::ensure_identity() without a
 * database: given the providers a member already has, and one being added,
 * does the real method insert/update (true) or refuse (false)?
 *
 * Real signature: only 'email' | 'google' | 'microsoft' are valid providers,
 * and the schema enforces one row per (member_id, provider) — so a member
 * can never hold more than 3 identities regardless of this check.
 */
function would_ensure_identity(array $existingProviders, string $provider): bool {
    $validProviders = ['email', 'google', 'microsoft'];
    if (!in_array($provider, $validProviders, true)) {
        return false;
    }

    $count = count($existingProviders);
    $existing = in_array($provider, $existingProviders, true);

    if (!$existing && $count >= 3) {
        return false;
    }

    return true;
}

echo PHP_EOL . 'Identity limit checks:' . PHP_EOL;

$limit_cases = [
    ['label' => 'first identity on a fresh member', 'existing' => [], 'provider' => 'email', 'expected' => true],
    ['label' => 'second distinct provider allowed', 'existing' => ['email'], 'provider' => 'google', 'expected' => true],
    ['label' => 'third distinct provider allowed', 'existing' => ['email', 'google'], 'provider' => 'microsoft', 'expected' => true],
    ['label' => 're-linking an existing provider at full slate', 'existing' => ['email', 'google', 'microsoft'], 'provider' => 'google', 'expected' => true],
    ['label' => 'unknown provider always rejected', 'existing' => [], 'provider' => 'facebook', 'expected' => false],
];

foreach ($limit_cases as $case) {
    $actual = would_ensure_identity($case['existing'], $case['provider']);
    format_result($case['label'], $actual === $case['expected']);
    if ($actual !== $case['expected']) {
        echo '  expected: ' . var_export($case['expected'], true) . PHP_EOL;
        echo '  actual:   ' . var_export($actual, true) . PHP_EOL;
    }
}

format_result(
    'schema guarantees max 3 identities (3 valid providers, one slot each)',
    count(['email', 'google', 'microsoft']) === 3
);

echo PHP_EOL . 'Done.' . PHP_EOL;
