<?php
/**
 * Sharing a file from uploads/private/delen/ with a fixed list of e-mail
 * addresses, on a tenant site.
 *
 * /?delen=<id> shows a form; an allowed address (or a logged-in member of
 * content_groups) gets a download button whose link is valid for 24 hours.
 * nginx serves uploads/private/ only to members, so the file is streamed
 * through PHP. The shares, including the addresses, live in the tenant
 * file on the server, never in this repository:
 *
 *   'shares' => [
 *       'tekeningen' => [
 *           'title'  => 'Tekeningen (1 januari 2026)',
 *           'file'   => 'tekeningen.zip',
 *           'emails' => ['iemand@example.invalid'],
 *       ],
 *   ],
 */

defined('ABSPATH') || exit;

class AVPVH_Tenant_Shares {

    const QUERY_VAR    = 'delen';
    const NONCE_ACTION = 'avpvh_tenant_share';
    const MAX_TRIES    = 10;

    private AVPVH_Tenant_Config $config;
    private AVPVH_Tenant_Content $content;

    public function __construct(AVPVH_Tenant_Config $config, AVPVH_Tenant_Content $content) {
        $this->config  = $config;
        $this->content = $content;
        add_action('init', [$this, 'handle_request'], 20);
    }

    public function handle_request(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a share ID only selects what to show
        $id = sanitize_key(wp_unslash($_GET[self::QUERY_VAR] ?? ''));
        if ($id === '') {
            return;
        }
        $shares = $this->config->get('shares');
        if (!isset($shares[$id])) {
            status_header(404);
            exit(esc_html__('Niet gevonden.', 'avpvh-members'));
        }
        $share = $shares[$id];
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow');

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the token itself is the secret
        $token = preg_replace('/[^A-Za-z0-9]/', '', sanitize_text_field(wp_unslash($_GET['t'] ?? '')));
        if ($token !== '') {
            $this->stream($id, $share, $token);
        }

        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handle_form($id, $share);
        }

        $member = $this->member_subject();
        if ($member !== '') {
            $this->render_button($id, $share, $member);
        }
        $this->render($id, $share, '');
    }

    /**
     * A member gets the button without the form: logged in here, or only
     * logged in at Authelia (single sign-on from another site).
     */
    private function member_subject(): string {
        if ($this->content->current_user_may_read()) {
            return 'user:' . get_current_user_id();
        }
        if (is_user_logged_in()) {
            return '';
        }
        $identity = AVPVH_Tenant_Identity::probe($this->config);
        $is_member = $identity['status'] === AVPVH_Tenant_Identity::OK
            && array_intersect($identity['groups'], $this->config->content_groups());
        return $is_member ? 'uid:' . $identity['uid'] : '';
    }

    private function handle_form(string $id, array $share): void {
        $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? ''));
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION . '_' . $id)) {
            $this->render($id, $share, __('Het formulier is verlopen. Probeer het opnieuw.', 'avpvh-members'));
        }
        // A simple brake on guessing: at most MAX_TRIES wrong addresses per
        // ten minutes per IP address.
        $rate_key = 'avpvh_share_rate_' . substr(hash('sha256', sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''))), 0, 32);
        $tries = (int) get_transient($rate_key);
        if ($tries >= self::MAX_TRIES) {
            $this->render($id, $share, __('Te veel pogingen. Probeer het over tien minuten opnieuw.', 'avpvh-members'), false);
        }
        $email = strtolower(sanitize_email(wp_unslash($_POST['email'] ?? '')));
        if ($email === '' || !in_array($email, $share['emails'], true)) {
            set_transient($rate_key, $tries + 1, 10 * MINUTE_IN_SECONDS);
            $this->render($id, $share, __('Dit e-mailadres heeft geen toegang tot dit bestand.', 'avpvh-members'));
        }
        $this->render_button($id, $share, $email);
    }

    private function path(array $share): string {
        return wp_upload_dir()['basedir'] . '/private/delen/' . $share['file'];
    }

    private function stream(string $id, array $share, string $token): void {
        $who = get_transient('avpvh_share_' . $id . '_' . $token);
        if (!$who) {
            $this->render($id, $share, __('Deze downloadlink is verlopen. Vul hieronder opnieuw je e-mailadres in.', 'avpvh-members'));
        }
        $path = $this->path($share);
        if (!is_readable($path)) {
            status_header(404);
            exit(esc_html__('Bestand niet gevonden.', 'avpvh-members'));
        }
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- who downloaded, as a hash only
        error_log(sprintf('[avpvh-tenant] tenant=%s share=%s download by=%s', $this->config->tenant_id(), $id, substr(hash('sha256', (string) $who), 0, 12)));
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $share['file']) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a large private file
        exit;
    }

    private function render_button(string $id, array $share, string $who): void {
        $token = wp_generate_password(32, false, false);
        set_transient('avpvh_share_' . $id . '_' . $token, $who, DAY_IN_SECONDS);
        $link = add_query_arg([self::QUERY_VAR => $id, 't' => $token], home_url('/'));
        $size = is_readable($this->path($share)) ? (int) round(filesize($this->path($share)) / 1048576) : 0;
        $this->render($id, $share, '<p><a class="avpvh-share-download" href="' . esc_url($link) . '">'
            . esc_html(sprintf(
                /* translators: 1: file name, 2: size in MB */
                __('Download %1$s (%2$d MB)', 'avpvh-members'),
                $share['file'],
                $size
            )) . '</a></p>', false, true);
    }

    private function render(string $id, array $share, string $message, bool $form = true, bool $message_is_html = false): void {
        $action = add_query_arg(self::QUERY_VAR, $id, home_url('/'));
        echo '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow"><title>' . esc_html($share['title']) . '</title>'
            . '<style>body{font-family:system-ui,sans-serif;max-width:32rem;margin:3rem auto;padding:0 1rem;color:#222}'
            . 'input{font-size:1rem;padding:.5rem;width:100%;box-sizing:border-box;margin:.5rem 0}button{font-size:1rem;padding:.5rem 1rem}'
            . '.avpvh-share-download{display:inline-block;padding:.7rem 1.2rem;background:#2b6cb0;color:#fff;text-decoration:none;border-radius:4px}</style>'
            . '</head><body><h1 style="font-size:1.4rem">' . esc_html($share['title']) . '</h1>';
        if ($message !== '') {
            // Only render_button() passes HTML, built from escaped parts.
            echo $message_is_html ? wp_kses_post($message) : '<p>' . esc_html($message) . '</p>';
        }
        if ($form) {
            echo '<p>' . esc_html__('Vul je e-mailadres in om het bestand te downloaden.', 'avpvh-members') . '</p>'
                . '<form method="post" action="' . esc_url($action) . '">'
                . '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce(self::NONCE_ACTION . '_' . $id)) . '">'
                . '<input type="email" name="email" required placeholder="naam@voorbeeld.nl" aria-label="' . esc_attr__('E-mailadres', 'avpvh-members') . '">'
                . '<button type="submit">' . esc_html__('Naar download', 'avpvh-members') . '</button></form>';
        }
        echo '</body></html>';
        exit;
    }
}
