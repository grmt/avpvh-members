<?php
declare(strict_types=1);

/** Run the real directory-consent handler against in-memory WordPress/DB boundaries. */
require_once dirname(__DIR__) . '/tests/fixtures/consent.php';
require_once dirname(__DIR__) . '/includes/class-directory-consent.php';

$handler = new AVPVH_Directory_Consent();
consent_check('directory action calls the consent handler', $GLOBALS['hooks']['admin_post_avpvh_set_directory_consent'] === [$handler, 'handle_set_consent']);
consent_authorization_cases($handler, 'avpvh_directory_consent');

foreach ([
    ['granted', ['share_email' => '1', 'share_phone' => '1', 'share_address' => '1'], [1, 1, 1]],
    ['granted', ['share_email' => '1', 'share_address' => '1'], [1, 0, 1]],
    ['granted', [], [0, 0, 0]],
    ['declined', ['share_email' => '1', 'share_phone' => '1', 'share_address' => '1'], [0, 0, 0]],
    ['declined', [], [0, 0, 0]],
] as [$decision, $shares, $expected]) {
    reset_consent();
    $_POST = ['consent' => $decision] + $shares;
    $exit = run_consent($handler);
    consent_check($decision . ' saves only the selected sharing fields with an audit timestamp', $GLOBALS['writes'] === [[
        'id' => 10,
        'data' => [
            'directory_consent' => $decision,
            'directory_consent_at' => '2026-06-15 12:00:00',
            'share_email' => $expected[0], 'share_phone' => $expected[1], 'share_address' => $expected[2],
        ],
        'formats' => ['%s', '%s', '%d', '%d', '%d'],
    ]]);
    consent_check('saved consent returns to the originating page', $exit->kind === 'redirect' && $exit->body === 'https://club.example.invalid/profiel/?tab=privacy&consent_saved=1');
}

foreach ([null, '', 'pending', 'invalid'] as $decision) {
    reset_consent();
    $_POST = $decision === null ? [] : ['consent' => $decision];
    $exit = run_consent($handler);
    consent_check('missing or unsupported decisions cannot change consent', $exit->status === 400 && !$GLOBALS['writes']);
}

reset_consent();
$_POST['consent'] = 'GRANTED';
$GLOBALS['referer'] = false;
$exit = run_consent($handler);
consent_check('normalized decisions save and a missing referrer falls back to home', $GLOBALS['writes'][0]['data']['directory_consent'] === 'granted' && $exit->body === 'https://club.example.invalid/?consent_saved=1');

echo "Directory consent tests: OK\n";
