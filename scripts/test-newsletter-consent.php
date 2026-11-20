<?php
declare(strict_types=1);

/** Exercise newsletter subscription changes through the production handler. */
require_once dirname(__DIR__) . '/tests/fixtures/consent.php';
require_once dirname(__DIR__) . '/includes/class-newsletter-consent.php';

$handler = new AVPVH_Newsletter_Consent();
consent_check('newsletter action calls the consent handler', $GLOBALS['hooks']['admin_post_avpvh_set_newsletter_consent'] === [$handler, 'handle_set_consent']);
consent_authorization_cases($handler, 'avpvh_set_newsletter_consent');

foreach ([null, '', '0', '1'] as $checkbox) {
    reset_consent();
    $_POST = $checkbox === null ? [] : ['newsletter' => $checkbox];
    $exit = run_consent($handler);
    consent_check('newsletter checkbox ' . ($checkbox ?? 'omitted') . ' updates only the newsletter flag', $GLOBALS['writes'] === [['id' => 10, 'slug' => 'nieuwsbrief', 'enabled' => $checkbox === '1']]);
    consent_check('subscription saves return to the originating page', $exit->body === 'https://club.example.invalid/profiel/?tab=privacy&newsletter_saved=1');
}
reset_consent();
$GLOBALS['referer'] = false;
$exit = run_consent($handler);
consent_check('missing referrer returns to home', $exit->body === 'https://club.example.invalid/?newsletter_saved=1');

echo "Newsletter consent tests: OK\n";
