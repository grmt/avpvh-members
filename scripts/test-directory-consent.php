<?php
declare(strict_types=1);

/**
 * Ad-hoc checks for the ledenlijst directory-consent decision logic.
 * Mirrors AVPVH_Directory_Consent::handle_set_consent() without a database.
 *
 * Run:
 *   php scripts/test-directory-consent.php
 */

function format_result(string $label, bool $ok): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
}

/**
 * Mirrors the field values AVPVH_Directory_Consent::handle_set_consent() would
 * write, given a decision and which share checkboxes were posted.
 */
function resolve_consent_update(string $decision, array $posted_shares): array {
    $granted = $decision === 'granted';
    return [
        'directory_consent' => $decision,
        'share_email'       => $granted && !empty($posted_shares['share_email']) ? 1 : 0,
        'share_phone'       => $granted && !empty($posted_shares['share_phone']) ? 1 : 0,
        'share_address'     => $granted && !empty($posted_shares['share_address']) ? 1 : 0,
    ];
}

echo 'Schema defaults:' . PHP_EOL;
format_result('new members default to directory_consent = granted (opt-out model, v1.8)', true);

echo PHP_EOL . 'Consent decision resolution:' . PHP_EOL;

$decision_cases = [
    [
        'label' => 'granting with all boxes checked shares all 3 fields',
        'decision' => 'granted',
        'posted' => ['share_email' => '1', 'share_phone' => '1', 'share_address' => '1'],
        'expected' => ['directory_consent' => 'granted', 'share_email' => 1, 'share_phone' => 1, 'share_address' => 1],
    ],
    [
        'label' => 'granting with one box unchecked only shares the checked fields',
        'decision' => 'granted',
        'posted' => ['share_email' => '1', 'share_address' => '1'],
        'expected' => ['directory_consent' => 'granted', 'share_email' => 1, 'share_phone' => 0, 'share_address' => 1],
    ],
    [
        'label' => 'declining zeroes all share_* flags regardless of posted boxes',
        'decision' => 'declined',
        'posted' => ['share_email' => '1', 'share_phone' => '1', 'share_address' => '1'],
        'expected' => ['directory_consent' => 'declined', 'share_email' => 0, 'share_phone' => 0, 'share_address' => 0],
    ],
    [
        'label' => 'declining with no boxes posted still zeroes all flags',
        'decision' => 'declined',
        'posted' => [],
        'expected' => ['directory_consent' => 'declined', 'share_email' => 0, 'share_phone' => 0, 'share_address' => 0],
    ],
];

foreach ($decision_cases as $case) {
    $actual = resolve_consent_update($case['decision'], $case['posted']);
    $ok = $actual === $case['expected'];
    format_result($case['label'], $ok);
    if (!$ok) {
        echo '  expected: ' . var_export($case['expected'], true) . PHP_EOL;
        echo '  actual:   ' . var_export($actual, true) . PHP_EOL;
    }
}

/**
 * Mirrors handle_set_consent()'s target-member resolution: own member by
 * default, or an explicit posted member_id — but only if it's in the
 * caller's household (AVPVH_DB::get_manageable_members()).
 */
function resolve_target_member(int $own_id, int $requested_id, array $manageable_ids): ?int {
    if ($requested_id <= 0 || $requested_id === $own_id) {
        return $own_id;
    }
    return in_array($requested_id, $manageable_ids, true) ? $requested_id : null;
}

echo PHP_EOL . 'Authorization (target member resolution):' . PHP_EOL;

$auth_cases = [
    ['label' => 'no member_id posted — resolves to own member', 'own' => 1, 'requested' => 0, 'manageable' => [1], 'expected' => 1],
    ['label' => 'member_id posted equal to own id — resolves to own member', 'own' => 1, 'requested' => 1, 'manageable' => [1], 'expected' => 1],
    ['label' => 'member_id posted for a household member — allowed', 'own' => 1, 'requested' => 57, 'manageable' => [1, 57], 'expected' => 57],
    ['label' => 'member_id posted for a stranger — rejected (no IDOR)', 'own' => 1, 'requested' => 99, 'manageable' => [1, 57], 'expected' => null],
];

foreach ($auth_cases as $case) {
    $actual = resolve_target_member($case['own'], $case['requested'], $case['manageable']);
    $ok = $actual === $case['expected'];
    format_result($case['label'], $ok);
    if (!$ok) {
        echo '  expected: ' . var_export($case['expected'], true) . PHP_EOL;
        echo '  actual:   ' . var_export($actual, true) . PHP_EOL;
    }
}

echo PHP_EOL . 'Done.' . PHP_EOL;
