<?php
defined('ABSPATH') || exit;

/**
 * Self-service e-mail-link verification: lets a member add a plain e-mail
 * address (no Google/Microsoft account needed, e.g. an ISP address) as a
 * login identity, proven by clicking a one-time confirmation link sent to
 * that address. There used to be a matching "log in via e-mail link" mode
 * here too, but it was redundant with Authelia's own reset-password/step1
 * page (which also doubles as a passwordless login for anyone without a
 * password yet) and has been removed — that page now also prefills the
 * username from a ?username= param, see nginx-config's authelia-custom.js.
 */
class AVPVH_Email_Identity {

    const TOKEN_TTL_ADD = HOUR_IN_SECONDS;

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('admin_post_avpvh_start_email_identity', [$this, 'handle_start_add']);
    }

    public function register_routes(): void {
        register_rest_route('avpvh/v1', '/email-identity/confirm', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handle_confirm'],
            'permission_callback' => '__return_true',
        ]);
    }

    // -------------------------------------------------------------------
    // Add — self-service, triggered from the member profile page
    // -------------------------------------------------------------------

    /**
     * Self only — same reasoning as AVPVH_OAuth's add flow: whoever clicks
     * the confirmation link ends up owning the identity, so a household
     * member must not be able to start this for someone else and then
     * receive/complete it themselves.
     */
    public function handle_start_add(): void {
        check_admin_referer('avpvh_start_email_identity');

        if (!is_user_logged_in()) {
            wp_die('Je moet ingelogd zijn.', 'Fout', ['response' => 403]);
        }

        $own_member  = AVPVH_DB::get_member_by_wp_user(get_current_user_id());
        $member_id   = (int) wp_unslash($_POST['member_id'] ?? 0);
        $email       = AVPVH_DB::normalize_identity_email(sanitize_email(wp_unslash($_POST['email'] ?? '')));
        $profile_url = home_url('/member-profile/');

        if (!$own_member || !$member_id || $member_id !== (int) $own_member->id) {
            wp_die('Geen toegang.', 'Fout', ['response' => 403]);
        }

        if (!is_email($email)) {
            wp_safe_redirect(add_query_arg(['member_id' => $member_id, 'identity_error' => 'invalid_email'], $profile_url));
            exit;
        }

        if ($this->email_claimed_by_someone_else($email, $member_id)) {
            wp_safe_redirect(add_query_arg(['member_id' => $member_id, 'identity_error' => 'in_use'], $profile_url));
            exit;
        }

        if (AVPVH_DB::get_member_identity_count($member_id) >= 3 && !AVPVH_DB::get_member_identity('email', $email)) {
            wp_safe_redirect(add_query_arg(['member_id' => $member_id, 'identity_error' => 'limit'], $profile_url));
            exit;
        }

        $token = $this->store_token([
            'mode'      => 'add',
            'member_id' => $member_id,
            'email'     => $email,
        ], self::TOKEN_TTL_ADD);

        wp_mail(
            $email,
            'AV Philips van Horne — bevestig je e-mailadres',
            "Klik op deze link om {$email} te koppelen als inlog-e-mailadres bij AV Philips van Horne:\n\n"
                . $this->confirm_url($token) . "\n\n"
                . "Deze link is een uur geldig. Was jij dit niet? Dan hoef je niets te doen."
        );

        wp_safe_redirect(add_query_arg(['member_id' => $member_id, 'identity_email_sent' => '1'], $profile_url));
        exit;
    }

    // -------------------------------------------------------------------
    // Confirm endpoint (the clicked link from the add e-mail lands here)
    // -------------------------------------------------------------------

    public function handle_confirm(\WP_REST_Request $request): void {
        $token  = sanitize_text_field((string) $request->get_param('token'));
        $stored = $token ? get_transient('avpvh_email_identity_' . $token) : false;
        if ($stored) {
            delete_transient('avpvh_email_identity_' . $token); // single-use
        }

        $payload = $stored ? json_decode((string) $stored, true) : null;
        if (!is_array($payload) || ($payload['mode'] ?? '') !== 'add') {
            wp_die('Deze link is ongeldig of verlopen. Vraag een nieuwe aan.', 'Link verlopen', ['response' => 400]);
        }

        $this->confirm_add($payload);
    }

    private function confirm_add(array $payload): void {
        $member_id   = (int) ($payload['member_id'] ?? 0);
        $email       = (string) ($payload['email'] ?? '');
        $profile_url = home_url('/member-profile/');

        $member = $member_id ? AVPVH_DB::get_member($member_id) : null;
        if (!$member) {
            wp_die('Lid niet gevonden.', 'Fout', ['response' => 404]);
        }

        // Re-check at click time too — the address could have been claimed
        // by someone else in the window between request and click.
        if ($this->email_claimed_by_someone_else($email, $member_id)
            || !AVPVH_DB::ensure_identity($member_id, 'email', $email, false, true)) {
            wp_safe_redirect(add_query_arg(['member_id' => $member_id, 'identity_error' => 'in_use'], $profile_url));
            exit;
        }

        $actor_user = !empty($member->wp_user_id) ? get_userdata((int) $member->wp_user_id) : null;
        if ($actor_user) {
            AVPVH_Member_Profile_Form::notify_identity_change($member, $member, 'toegevoegd', 'email', $email, $actor_user);
        }

        wp_safe_redirect(add_query_arg(['member_id' => $member_id, 'identity_added' => '1'], $profile_url));
        exit;
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    private function email_claimed_by_someone_else(string $email, int $member_id): bool {
        $existing_identity = AVPVH_DB::get_member_identity('email', $email);
        if ($existing_identity && (int) $existing_identity->member_id !== $member_id) {
            return true;
        }
        $existing_owner = AVPVH_DB::get_member_by_email($email);
        return $existing_owner && (int) $existing_owner->id !== $member_id;
    }

    private function store_token(array $payload, int $ttl): string {
        $token = bin2hex(random_bytes(32));
        set_transient('avpvh_email_identity_' . $token, wp_json_encode($payload), $ttl);
        return $token;
    }

    private function confirm_url(string $token): string {
        return rest_url('avpvh/v1/email-identity/confirm') . '?token=' . $token;
    }
}
