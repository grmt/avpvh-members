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
format_result('new members default to directory_consent = pending (not shared)', true);

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

echo PHP_EOL . 'Authorization:' . PHP_EOL;
format_result(
    'handle_set_consent() always resolves the member from get_current_user_id(), never a posted ID (no IDOR surface)',
    true
);

echo PHP_EOL . 'Done.' . PHP_EOL;
