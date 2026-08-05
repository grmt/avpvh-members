<?php
declare(strict_types=1);

/**
 * Ad-hoc checks for the household/minor visibility decision logic added for
 * the ledenlijst opt-out + minors-protection revision.
 *
 * Mirrors AVPVH_DB::is_same_household() and the per-row decision in
 * AVPVH_DB::get_members_with_address() without a database.
 *
 * Run:
 *   php scripts/test-household-visibility.php
 */

function format_result(string $label, bool $ok): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
}

/** Mirrors AVPVH_DB::is_same_household(). */
function is_same_household(
    int $id1, int $id2,
    bool $same_family,
    ?array $addr1, ?array $addr2
): bool {
    if ($id1 === $id2) {
        return true;
    }
    if ($same_family) {
        return true;
    }
    if (!$addr1 || !$addr2 || !$addr1['street'] || !$addr2['street']) {
        return false;
    }
    return strcasecmp(trim($addr1['street']), trim($addr2['street'])) === 0
        && strcasecmp((string) trim($addr1['house_number']), (string) trim($addr2['house_number'])) === 0
        && strcasecmp(trim($addr1['postal_code']), trim($addr2['postal_code'])) === 0;
}

echo 'is_same_household():' . PHP_EOL;

$household_cases = [
    ['label' => 'same member id', 'id1' => 1, 'id2' => 1, 'family' => false, 'a1' => null, 'a2' => null, 'expected' => true],
    ['label' => 'family link, no address data', 'id1' => 1, 'id2' => 2, 'family' => true, 'a1' => null, 'a2' => null, 'expected' => true],
    [
        'label' => 'matching address (case/space insensitive)',
        'id1' => 1, 'id2' => 3, 'family' => false,
        'a1' => ['street' => 'Fleskensstraat', 'house_number' => '62', 'postal_code' => '5666 TC'],
        'a2' => ['street' => ' fleskensstraat ', 'house_number' => '62', 'postal_code' => '5666 tc'],
        'expected' => true,
    ],
    [
        'label' => 'different house number, same street/postal',
        'id1' => 1, 'id2' => 4, 'family' => false,
        'a1' => ['street' => 'Meeldijk', 'house_number' => '12', 'postal_code' => '4328 NG'],
        'a2' => ['street' => 'Meeldijk', 'house_number' => '14', 'postal_code' => '4328 NG'],
        'expected' => false,
    ],
    [
        'label' => 'no family link, one side has no address on file',
        'id1' => 1, 'id2' => 5, 'family' => false,
        'a1' => ['street' => 'Meeldijk', 'house_number' => '12', 'postal_code' => '4328 NG'],
        'a2' => null,
        'expected' => false,
    ],
    [
        'label' => 'unrelated members, different addresses',
        'id1' => 1, 'id2' => 6, 'family' => false,
        'a1' => ['street' => 'Meeldijk', 'house_number' => '12', 'postal_code' => '4328 NG'],
        'a2' => ['street' => 'Nieuwstraat', 'house_number' => '49', 'postal_code' => '3990'],
        'expected' => false,
    ],
];

foreach ($household_cases as $case) {
    $actual = is_same_household($case['id1'], $case['id2'], $case['family'], $case['a1'], $case['a2']);
    format_result($case['label'], $actual === $case['expected']);
    if ($actual !== $case['expected']) {
        echo '  expected: ' . var_export($case['expected'], true) . PHP_EOL;
        echo '  actual:   ' . var_export($actual, true) . PHP_EOL;
    }
}

/**
 * Mirrors the per-row decision in AVPVH_DB::get_members_with_address():
 * is this row visible to the viewer, and with which fields?
 * Returns null if not visible, else ['share_email'=>.., 'share_phone'=>.., 'share_address'=>..].
 */
function visibility_decision(
    ?int $age, bool $viewer_sees_minors, bool $viewer_is_household,
    bool $share_email, bool $share_phone, bool $share_address
): ?array {
    $is_minor = $age !== null && $age < 16;
    if ($is_minor) {
        if (!$viewer_sees_minors && !$viewer_is_household) {
            return null;
        }
        // Bestuur/household bypasses the member's own opt-out flags.
        return ['share_email' => true, 'share_phone' => true, 'share_address' => true];
    }
    return ['share_email' => $share_email, 'share_phone' => $share_phone, 'share_address' => $share_address];
}

echo PHP_EOL . 'Minor/bestuur/household visibility decisions:' . PHP_EOL;

$visibility_cases = [
    [
        'label' => 'adult, all fields opted out — still visible, redaction respected',
        'age' => 30, 'bestuur' => false, 'household' => false,
        'share' => [false, false, false],
        'expected' => ['share_email' => false, 'share_phone' => false, 'share_address' => false],
    ],
    [
        'label' => 'unknown birth date treated as adult — visible',
        'age' => null, 'bestuur' => false, 'household' => false,
        'share' => [true, true, true],
        'expected' => ['share_email' => true, 'share_phone' => true, 'share_address' => true],
    ],
    [
        'label' => 'minor, viewer is neither bestuur nor household — hidden',
        'age' => 3, 'bestuur' => false, 'household' => false,
        'share' => [true, true, true],
        'expected' => null,
    ],
    [
        'label' => 'minor, viewer is bestuur — visible, full info regardless of own opt-out',
        'age' => 3, 'bestuur' => true, 'household' => false,
        'share' => [false, false, false],
        'expected' => ['share_email' => true, 'share_phone' => true, 'share_address' => true],
    ],
    [
        'label' => 'minor, viewer is household — visible, full info regardless of own opt-out',
        'age' => 3, 'bestuur' => false, 'household' => true,
        'share' => [false, false, false],
        'expected' => ['share_email' => true, 'share_phone' => true, 'share_address' => true],
    ],
    [
        'label' => 'exactly 15 years old — still a minor, hidden from a stranger',
        'age' => 15, 'bestuur' => false, 'household' => false,
        'share' => [true, true, true],
        'expected' => null,
    ],
    [
        'label' => 'exactly 16 years old — adult, visible',
        'age' => 16, 'bestuur' => false, 'household' => false,
        'share' => [true, true, true],
        'expected' => ['share_email' => true, 'share_phone' => true, 'share_address' => true],
    ],
];

foreach ($visibility_cases as $case) {
    [$se, $sp, $sa] = $case['share'];
    $actual = visibility_decision($case['age'], $case['bestuur'], $case['household'], $se, $sp, $sa);
    $ok = $actual === $case['expected'];
    format_result($case['label'], $ok);
    if (!$ok) {
        echo '  expected: ' . var_export($case['expected'], true) . PHP_EOL;
        echo '  actual:   ' . var_export($actual, true) . PHP_EOL;
    }
}

echo PHP_EOL . 'Done.' . PHP_EOL;
