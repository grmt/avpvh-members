<?php
defined('ABSPATH') || exit;

class AVPVH_Member_Profile_Form {

    /** Query args that carry a one-off notice; stripped before redirecting back with a new one. */
    const NOTICE_ARGS = ['identity_added', 'identity_error', 'identity_removed', 'identity_primary', 'identity_requested', 'identity_email_sent', 'email_updated', 'email_error'];

    public function __construct() {
        add_shortcode('avpvh_member_profile', [$this, 'render_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_avpvh_save_member_profile', [$this, 'handle_save_profile']);
        add_action('admin_post_avpvh_remove_identity', [$this, 'handle_remove_identity']);
        add_action('admin_post_avpvh_make_primary_identity', [$this, 'handle_make_primary_identity']);
        add_action('admin_post_avpvh_request_identity', [$this, 'handle_request_identity']);
        add_action('admin_post_avpvh_add_relationship', [$this, 'handle_add_relationship']);
        add_action('admin_post_avpvh_remove_relationship', [$this, 'handle_remove_relationship']);
    }

    /**
     * Where a profile form action returns to: the page it came from, else the
     * profile page, without the previous action's notice.
     */
    public static function back_url(): string {
        return remove_query_arg(self::NOTICE_ARGS, wp_get_referer() ?: home_url('/member-profile/'));
    }

    /**
     * Render member profile form shortcode.
     * Usage: [avpvh_member_profile]
     */
    public function render_shortcode(): string {
        if (!is_user_logged_in()) {
            return '<p style="color: red;">' . esc_html__('Log in om je profiel te bewerken.', 'avpvh-members') . '</p>';
        }

        $own_member = AVPVH_DB::get_member_by_wp_user(get_current_user_id());
        [$member, $view] = AVPVH_Member_Profile_Page::resolve($own_member, absint(wp_unslash($_GET['member_id'] ?? 0)));
        if (!$member) {
            return '<p style="color: red;">' . esc_html__('Ledenprofiel niet gevonden.', 'avpvh-members') . '</p>';
        }

        ob_start();
        AVPVH_Member_Profile_Page::render($member, $own_member, $view);
        return ob_get_clean();
    }

    /**
     * Handle form submission via AJAX.
     */
    public function handle_save_profile(): void {
        check_ajax_referer('avpvh_member_profile', 'avpvh_nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(__('Je bent niet ingelogd.', 'avpvh-members'));
        }

        $member = $this->get_target_member_for_save();

        if (!$member) {
            wp_send_json_error(__('Ledenprofiel niet gevonden.', 'avpvh-members'));
        }

        $is_admin_edit = AVPVH_Roles::can_manage_members() && !empty($_POST['member_id']);
        $member_data = $this->sanitize_member_data($_POST, $is_admin_edit);

        $emergency_member_id = absint(wp_unslash($_POST['emergency_member_id'] ?? 0));
        if ($emergency_member_id && !isset(self::emergency_candidates($member, AVPVH_DB::get_member_by_wp_user(get_current_user_id()))[$emergency_member_id])) {
            wp_send_json_error(__('Dit lid kan niet als noodcontact gekozen worden.', 'avpvh-members'));
        }
        // As a string, like the stored value, so the audit log only notes real changes.
        $member_data['emergency_member_id'] = $emergency_member_id ? (string) $emergency_member_id : null;

        // A name alone is no use in an emergency (a linked member brings
        // their own number). Only enforced on the member's own profile: an
        // admin or housemate editing someone else usually doesn't know the
        // number and shouldn't be blocked by it.
        $is_own_profile = (int) ($member->wp_user_id ?? 0) === get_current_user_id();
        if ($is_own_profile && !$emergency_member_id && $member_data['emergency_contact'] !== '' && $member_data['emergency_phone'] === '') {
            wp_send_json_error(__('Vul ook het telefoonnummer van je noodcontact in.', 'avpvh-members'));
        }

        try {
            // Save language preference if provided
            if (isset($_POST['user_locale'])) {
                $chosen_locale = sanitize_text_field(wp_unslash($_POST['user_locale']));
                if (array_key_exists($chosen_locale, AVPVH_I18n::get_supported_locales())) {
                    $target_wp_uid = !empty($member->wp_user_id) ? (int) $member->wp_user_id : get_current_user_id();
                    update_user_meta($target_wp_uid, 'locale', $chosen_locale);
                    if ($target_wp_uid === get_current_user_id()) {
                        AVPVH_I18n::remember_locale($chosen_locale);
                    }
                }
            }

            // Update member with audit trail
            if ($member_data) {
                $formats = array_fill(0, count($member_data), '%s');
                AVPVH_DB::update_member_with_audit(
                    $member->id,
                    $member_data,
                    $formats
                );

                // sanitize_member_data() always includes first_name/suffix/
                // last_name (unconditionally, whether or not they actually
                // changed), so there's no cheap way to tell if the name
                // specifically changed — just always re-sync displayName.
                // It's the only place in the plugin that ever pushes a name
                // to OpenLDAP, so without this, a name edit here would silently
                // drift from OpenLDAP until someone remembered the manual
                // sync button on Ledendetail.
                $updated_member = (object) array_merge((array) $member, $member_data);
                $result = AVPVH_Directory::update_user($member->lldap_user_id, ['display_name' => avpvh_format_name($updated_member)]);
                if (is_wp_error($result)) {
                    error_log("AVPVH_Member_Profile_Form: failed to sync displayName to OpenLDAP for member {$member->id}: " . $result->get_error_message()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operational failure log
                }
            }

            // Update address if provided
            if (!empty($_POST['street']) || !empty($_POST['postal_code'])) {
                $address_data = [
                    'street' => sanitize_text_field(wp_unslash($_POST['street'] ?? '')),
                    'house_number' => sanitize_text_field(wp_unslash($_POST['house_number'] ?? '')),
                    'house_number_addition' => sanitize_text_field(wp_unslash($_POST['house_number_addition'] ?? '')),
                    'postal_code' => sanitize_text_field(wp_unslash($_POST['postal_code'] ?? '')),
                    'city' => sanitize_text_field(wp_unslash($_POST['city'] ?? '')),
                    'country' => sanitize_text_field(wp_unslash($_POST['country'] ?? 'Nederland')),
                ];
                // The form always re-sends the address; an unchanged one
                // must not add a new history row (see add_member_address()).
                // A new address from a member gives no household rights
                // until the board confirms it (AVPVH_DB::addresses_grant_household()).
                AVPVH_DB::add_member_address((int) $member->id, $address_data, !AVPVH_Roles::can_manage_members());
            }

            wp_send_json_success(__('Profiel succesvol bijgewerkt!', 'avpvh-members'));
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Members that can be picked as $member's emergency contact, id => name:
     * the ledenlijst as the editor sees it (AVPVH_DB::get_members_with_address(),
     * so opted-out members and other people's minors are left out), minus
     * $member. The current link always stays selectable, even if that
     * member has since left the ledenlijst.
     */
    /** Members someone can pick as emergency contact: everyone with an address the viewer may see, plus the current pick. */
    public static function emergency_candidates(object $member, ?object $own_member): array {
        $candidates = [];
        foreach (AVPVH_DB::get_members_with_address((int) ($own_member->id ?? 0), AVPVH_Roles::can_manage_members()) as $m) {
            if ((int) $m->id !== (int) $member->id) {
                $candidates[(int) $m->id] = avpvh_format_name($m);
            }
        }
        $current_id = (int) ($member->emergency_member_id ?? 0);
        if ($current_id && !isset($candidates[$current_id]) && ($current = AVPVH_DB::get_member($current_id))) {
            $candidates[$current_id] = avpvh_format_name($current);
        }
        return $candidates;
    }

    private function get_target_member_for_save(): ?object {
        $wp_user = wp_get_current_user();
        $own_member = AVPVH_DB::get_member_by_wp_user($wp_user->ID);

        $member_id = absint(wp_unslash($_POST['member_id'] ?? 0));
        if ($member_id > 0 && self::can_edit_member($own_member, $member_id)) {
            return AVPVH_DB::get_member($member_id);
        }

        return $own_member;
    }

    /**
     * Member admins (AVPVH_Roles::can_manage_members()) can edit any member;
     * everyone else can edit their own profile or a household member's
     * (same family link or current address).
     */
    public static function can_edit_member(?object $own_member, int $target_member_id): bool {
        if (AVPVH_Roles::can_manage_members()) {
            return true;
        }
        $household = $own_member ? AVPVH_DB::get_manageable_members((int) $own_member->id) : [];
        return in_array($target_member_id, array_map('intval', wp_list_pluck($household, 'id')), true);
    }

    /**
     * Wider than can_edit_member() — also reaches a housemate's own partner
     * (AVPVH_DB::get_extended_household(), e.g. a child's girlfriend who
     * lives elsewhere), but only for requesting identity verification, not
     * for editing anything else. Deliberately kept separate from
     * can_edit_member() rather than widening it, since that would also open
     * up name/address/relationship editing for that wider circle.
     */
    public static function can_request_identity_only(?object $own_member, int $target_member_id): bool {
        if (!$own_member) {
            return false;
        }
        foreach (AVPVH_DB::get_extended_household((int) $own_member->id) as $m) {
            if ((int) $m->id === $target_member_id) {
                return true;
            }
        }
        return false;
    }

    /**
     * Remove one of the member's login identities (self-service). Keeps at
     * least one identity, and e-mails the change to the person who made it
     * plus — if they edited a household member's profile rather than their
     * own — to the affected member too.
     */
    public function handle_remove_identity(): void {
        check_admin_referer('avpvh_remove_identity');

        if (!is_user_logged_in()) {
            wp_die(esc_html__('Je moet ingelogd zijn.', 'avpvh-members'), esc_html__('Fout', 'avpvh-members'), ['response' => 403]);
        }

        $member_id   = absint(wp_unslash($_POST['member_id'] ?? 0));
        $identity_id = absint(wp_unslash($_POST['identity_id'] ?? 0));
        $own_member = AVPVH_DB::get_member_by_wp_user(get_current_user_id());

        if (!$member_id || !self::can_edit_member($own_member, $member_id)) {
            wp_die(esc_html__('Geen toegang.', 'avpvh-members'), esc_html__('Fout', 'avpvh-members'), ['response' => 403]);
        }

        $member = AVPVH_DB::get_member($member_id);
        if (!$member) {
            wp_die(esc_html__('Lid niet gevonden.', 'avpvh-members'), esc_html__('Fout', 'avpvh-members'), ['response' => 404]);
        }

        $identities = AVPVH_DB::get_member_identities($member_id);
        // Never the last verified login; an unverified one can always go.
        if (!AVPVH_DB::can_delete_identity($identities, $identity_id)) {
            wp_safe_redirect(add_query_arg(['member_id' => $member_id, 'identity_error' => 'last_identity'], self::back_url()));
            exit;
        }

        $removed_email = null;
        $removed_provider = null;
        foreach ($identities as $identity) {
            if ((int) $identity->id === $identity_id) {
                $removed_email = $identity->email;
                $removed_provider = $identity->provider;
                break;
            }
        }

        if ($removed_email) {
            AVPVH_DB::delete_identity_by_id($member_id, $identity_id);
            self::notify_identity_change($member, $own_member, 'verwijderd', $removed_provider, $removed_email);
        }

        wp_safe_redirect(add_query_arg(['member_id' => $member_id, 'identity_removed' => '1'], self::back_url()));
        exit;
    }

    /**
     * Designate one of the member's identities as primary — self-service
     * equivalent of the admin's "Maak primair" button in Ledendetail. Also
     * pushes that address to the OpenLDAP account's own contact e-mail
     * (member-detail.php's "E-mail" field, used for correspondence, separate
     * from Inlogadressen/login), replacing whatever was there before — e.g.
     * an old address nobody uses for either purpose anymore.
     */
    public function handle_make_primary_identity(): void {
        check_admin_referer('avpvh_make_primary_identity');

        if (!is_user_logged_in()) {
            wp_die(esc_html__('Je moet ingelogd zijn.', 'avpvh-members'), esc_html__('Fout', 'avpvh-members'), ['response' => 403]);
        }

        $member_id   = absint(wp_unslash($_POST['member_id'] ?? 0));
        $identity_id = absint(wp_unslash($_POST['identity_id'] ?? 0));
        $own_member  = AVPVH_DB::get_member_by_wp_user(get_current_user_id());

        if (!$member_id || !self::can_edit_member($own_member, $member_id)) {
            wp_die(esc_html__('Geen toegang.', 'avpvh-members'), esc_html__('Fout', 'avpvh-members'), ['response' => 403]);
        }

        $member = AVPVH_DB::get_member($member_id);
        if (!$member) {
            wp_die(esc_html__('Lid niet gevonden.', 'avpvh-members'), esc_html__('Fout', 'avpvh-members'), ['response' => 404]);
        }

        $target = null;
        foreach (AVPVH_DB::get_member_identities($member_id) as $identity) {
            if ((int) $identity->id === $identity_id) {
                $target = $identity;
                break;
            }
        }

        if ($target) {
            AVPVH_DB::set_primary_identity($member_id, $identity_id);
            $result = AVPVH_Directory::update_user($member->lldap_user_id, ['mail' => $target->email]);
            if (is_wp_error($result)) {
                error_log("AVPVH_Member_Profile_Form: failed to sync primary identity to OpenLDAP for member {$member_id}: " . $result->get_error_message()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operational failure log
            }
            self::notify_identity_change($member, $own_member, 'als primair adres ingesteld', $target->provider, $target->email);
        }

        wp_safe_redirect(add_query_arg(['member_id' => $member_id, 'identity_primary' => '1'], self::back_url()));
        exit;
    }

    /**
     * A household member (e.g. a spouse) asks another household member to
     * verify-and-add a new login e-mail. This only sends a request e-mail —
     * it never starts the OAuth flow itself, since only the target member
     * can complete that (see class-oauth.php). The link in the e-mail just
     * points at their own profile, where the real "Verifieer en voeg toe"
     * buttons render fresh for their own session once they're logged in.
     */
    public function handle_request_identity(): void {
        check_admin_referer('avpvh_request_identity');

        if (!is_user_logged_in()) {
            wp_die(esc_html__('Je moet ingelogd zijn.', 'avpvh-members'), esc_html__('Fout', 'avpvh-members'), ['response' => 403]);
        }

        $member_id  = absint(wp_unslash($_POST['member_id'] ?? 0));
        $own_member = AVPVH_DB::get_member_by_wp_user(get_current_user_id());

        $has_access = $member_id && (
            self::can_edit_member($own_member, $member_id)
            || self::can_request_identity_only($own_member, $member_id)
        );
        if (!$has_access) {
            wp_die(esc_html__('Geen toegang.', 'avpvh-members'), esc_html__('Fout', 'avpvh-members'), ['response' => 403]);
        }

        $member = AVPVH_DB::get_member($member_id);
        if (!$member) {
            wp_die(esc_html__('Lid niet gevonden.', 'avpvh-members'), esc_html__('Fout', 'avpvh-members'), ['response' => 404]);
        }

        if ((int) $member->id === (int) $own_member->id) {
            wp_die(esc_html__('Je kunt geen verzoek naar jezelf sturen.', 'avpvh-members'), esc_html__('Fout', 'avpvh-members'), ['response' => 400]);
        }

        $requester_user = wp_get_current_user();
        $requester_name = $requester_user->display_name ?: $requester_user->user_login;
        $subject = __('AV Philips van Horne — vraag om e-mailadres te verifiëren', 'avpvh-members');
        // Links straight into Authelia's own reset-password flow with the
        // username field prefilled (see nginx-config: custom/authelia-custom.js
        // prefillResetUsername() + njs/authelia-nonce.js) — the member only
        // has to confirm the flow themselves, not first go find where to
        // start it. Authelia's users_filter matches on username OR mail, so
        // prefilling with the e-mail address works even though OpenLDAP's
        // actual username differs from it.
        $reset_url = add_query_arg('username', rawurlencode($member->email), AVPVH_Nav_Auth::AUTHELIA_URL . '/reset-password/step1');
        $body = sprintf(
            /* translators: 1: requester name, 2: e-mail address, 3: requester name, 4: link */ __("%1\$s heeft dit e-mailadres (%2\$s) toegevoegd aan jouw profiel bij AV Philips van Horne en vraagt of je het wilt verifiëren als inlogmethode.\n\nAlleen jij kunt dit zelf afronden — %3\$s kan dit niet namens jou doen. Klik hieronder om het adres te verifiëren en een wachtwoord in te stellen:\n%4\$s\n\nWas dit geen terecht verzoek? Dan hoef je niets te doen.", 'avpvh-members'),
            esc_html($requester_name),
            $member->email,
            esc_html($requester_name),
            $reset_url
        );
        wp_mail($member->email, $subject, $body);
        set_transient(self::identity_request_transient_key($member_id), get_current_user_id(), 3 * DAY_IN_SECONDS);

        wp_safe_redirect(add_query_arg(['member_id' => $member_id, 'identity_requested' => '1'], self::back_url()));
        exit;
    }

    public function handle_add_relationship(): void {
        check_admin_referer('avpvh_add_relationship');

        if (!is_user_logged_in()) {
            wp_die(esc_html__('Je moet ingelogd zijn.', 'avpvh-members'), esc_html__('Fout', 'avpvh-members'), ['response' => 403]);
        }

        $member_id         = absint(wp_unslash($_POST['member_id'] ?? 0));
        $related_member_id = absint(wp_unslash($_POST['related_member_id'] ?? 0));
        $label_id           = absint(wp_unslash($_POST['label_id'] ?? 0));
        $valid_from         = sanitize_text_field(wp_unslash($_POST['valid_from'] ?? '')) ?: null;
        $valid_until        = sanitize_text_field(wp_unslash($_POST['valid_until'] ?? '')) ?: null;

        // Picking an activity ("een kamp of weekend") is an alternative to
        // typing dates by hand — its real start/end date becomes the
        // relationship's validity period (e.g. temporary voogdij for the
        // activity's duration), overriding whatever was typed manually.
        $activity_id = absint(wp_unslash($_POST['activity_id'] ?? 0));
        if ($activity_id) {
            $activity = AVPVH_DB::get_activity($activity_id);
            if ($activity) {
                $valid_from  = $activity->start_date ?: $valid_from;
                $valid_until = $activity->end_date ?: $valid_until;
            }
        }

        // Only the board: a relationship can give rights over another member.
        if (!$member_id || !AVPVH_Roles::can_manage_members()) {
            wp_die(esc_html__('Geen toegang.', 'avpvh-members'), esc_html__('Fout', 'avpvh-members'), ['response' => 403]);
        }

        if (!$related_member_id || !$label_id || !AVPVH_DB::get_member($related_member_id)) {
            wp_die(esc_html__('Ongeldige relatie.', 'avpvh-members'), esc_html__('Fout', 'avpvh-members'), ['response' => 400]);
        }

        $duplicate = AVPVH_DB::relationship_exists($member_id, $related_member_id, $label_id, $valid_from, $valid_until);
        $saved = !$duplicate && AVPVH_DB::add_relationship(
            $member_id, $related_member_id, $label_id,
            $valid_from, $valid_until, '', get_current_user_id()
        );

        $status = $saved ? 'relationship_added' : ($duplicate ? 'relationship_duplicate' : 'relationship_error');
        wp_safe_redirect(add_query_arg(
            ['member_id' => $member_id, $status => '1'],
            self::back_url()
        ));
        exit;
    }

    public function handle_remove_relationship(): void {
        check_admin_referer('avpvh_remove_relationship');

        if (!is_user_logged_in()) {
            wp_die(esc_html__('Je moet ingelogd zijn.', 'avpvh-members'), esc_html__('Fout', 'avpvh-members'), ['response' => 403]);
        }

        $member_id        = absint(wp_unslash($_POST['member_id'] ?? 0));
        $relationship_id  = absint(wp_unslash($_POST['relationship_id'] ?? 0));

        if (!$member_id || !AVPVH_Roles::can_manage_members()) {
            wp_die(esc_html__('Geen toegang.', 'avpvh-members'), esc_html__('Fout', 'avpvh-members'), ['response' => 403]);
        }

        $removed = $relationship_id && AVPVH_DB::remove_relationship($relationship_id, $member_id);

        wp_safe_redirect(add_query_arg(['member_id' => $member_id, $removed ? 'relationship_removed' : 'relationship_error' => '1'], self::back_url()));
        exit;
    }

    /**
     * E-mails everyone who should know an identity changed: the person who
     * made the change, and — only when they edited someone else's profile —
     * the affected member as well, at their remaining identities. For a
     * successful *add* specifically, also notifies whoever requested it via
     * handle_request_identity() (if anyone did, within the request's 3-day
     * window) — adding is self-only (see class-oauth.php), so that's the
     * only way a requester learns it actually happened.
     */
    public static function notify_identity_change(object $member, ?object $actor_member, string $action, string $provider, string $email, ?\WP_User $actor_user = null): void {
        $provider_labels = [
            'email'     => __('e-mailadres', 'avpvh-members'),
            'google'    => __('Google-account', 'avpvh-members'),
            'microsoft' => __('Microsoft-account', 'avpvh-members'),
        ];
        $provider_label = $provider_labels[$provider] ?? $provider;
        $member_name = avpvh_format_name($member);

        $actor_user = $actor_user ?: wp_get_current_user();
        $is_self = $actor_member && (int) $actor_member->id === (int) $member->id;

        $subject = __('AV Philips van Horne — inloggegevens gewijzigd', 'avpvh-members');
        $body_actor = sprintf(
            /* translators: 1: login method, 2: e-mail address, 3: toegevoegd/verwijderd, 4: member name */ __("Er is zojuist een %1\$s (%2\$s) %3\$s bij het profiel van %4\$s.\n\nWas jij dit niet? Neem dan contact op met het bestuur.", 'avpvh-members'),
            $provider_label,
            $email,
            $action,
            $member_name
        );
        wp_mail($actor_user->user_email, $subject, $body_actor);

        if (!$is_self) {
            $body_member = sprintf(
                /* translators: 1: login method, 2: e-mail address, 3: toegevoegd/verwijderd, 4: name of who did it */ __("Er is zojuist een %1\$s (%2\$s) %3\$s bij jouw profiel door %4\$s.\n\nWas jij dit niet? Neem dan contact op met het bestuur.", 'avpvh-members'),
                $provider_label,
                $email,
                $action,
                esc_html($actor_user->display_name ?: $actor_user->user_login)
            );
            foreach (AVPVH_DB::get_member_identities((int) $member->id) as $identity) {
                wp_mail($identity->email, $subject, $body_member);
            }
        }

        if ($action === 'toegevoegd') {
            $requester_id = get_transient(self::identity_request_transient_key((int) $member->id));
            if ($requester_id && (int) $requester_id !== (int) $actor_user->ID) {
                delete_transient(self::identity_request_transient_key((int) $member->id));
                $requester_user = get_userdata((int) $requester_id);
                if ($requester_user) {
                    $body_requester = sprintf(
                        /* translators: 1: login method, 2: e-mail address, 3: member name */ __("Je verzoek is opgevolgd: er is zojuist een %1\$s (%2\$s) toegevoegd aan het profiel van %3\$s.", 'avpvh-members'),
                        $provider_label,
                        $email,
                        $member_name
                    );
                    wp_mail($requester_user->user_email, $subject, $body_requester);
                }
            }
        }
    }

    private static function identity_request_transient_key(int $member_id): string {
        return 'avpvh_identity_request_' . $member_id;
    }

    /**
     * Fields every member (or a household member editing on their behalf)
     * may update themselves; admin-only fields (baptism name, birth date)
     * are added on top for the admin edit screen.
     */
    // Passport name must read exactly as printed in the passport — no
    // parenthetical nicknames/notes (a leftover habit from the old,
    // free-text doopnaam field this was merged from).
    private static function strip_brackets(string $value): string {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[()\[\]{}]/', '', $value)));
    }

    /** Free-typed "s j m", "SJM", "S.J.M." all become the one canonical "S.J.M." — so stored initials compare reliably against a passport name's derived initials, and against bank-export text (avpvh-bookkeeping normalizes both the same way before comparing). */
    private static function normalize_initials(string $raw): string {
        $letters = preg_replace('/[^A-Za-z]/', '', $raw);
        if ($letters === '') {
            return '';
        }
        return implode('.', str_split(mb_strtoupper($letters))) . '.';
    }

    private function sanitize_member_data(array $data, bool $is_admin): array {
        $fields = [
            'first_name' => sanitize_text_field($data['first_name'] ?? ''),
            'suffix' => sanitize_text_field($data['suffix'] ?? ''),
            'last_name' => sanitize_text_field($data['last_name'] ?? ''),
            'passport_name' => self::strip_brackets(sanitize_text_field($data['passport_name'] ?? '')),
            'initials' => self::normalize_initials(sanitize_text_field($data['initials'] ?? '')),
            'phone' => sanitize_text_field($data['phone'] ?? ''),
            'mobile' => sanitize_text_field($data['mobile'] ?? ''),
            'emergency_contact' => sanitize_text_field($data['emergency_contact'] ?? ''),
            'emergency_relation' => sanitize_text_field($data['emergency_relation'] ?? ''),
            'emergency_phone' => sanitize_text_field($data['emergency_phone'] ?? ''),
            'emergency_address' => sanitize_text_field($data['emergency_address'] ?? ''),
            'diet' => sanitize_text_field($data['diet'] ?? ''),
        ];

        if ($is_admin) {
            [$fields['birth_date'], $fields['birth_year']] = self::parse_birth_date(sanitize_text_field($data['birth_date'] ?? ''));
            $fields['is_student'] = !empty($data['is_student']) ? 1 : 0;
        }

        return $fields;
    }

    /**
     * The Geboortedatum field accepts either a full "JJJJ-MM-DD" or, when
     * the exact date is genuinely unknown, just a year — real but
     * imprecise beats avpvh-bookkeeping's "no birth date at all, assume
     * adult" fallback. Returns [birth_date, birth_year], always exactly
     * one of the two non-null (or both null for an empty/invalid input) —
     * the two columns are mutually exclusive, never both set at once.
     * Shared with the "Nieuwe persoon" form (AVPVH_Admin::handle_add_member()).
     */
    public static function parse_birth_date(string $raw): array {
        $raw = trim($raw);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? [$raw, null] : [null, null];
        }
        if (preg_match('/^(\d{4})$/', $raw, $m)) {
            $year = (int) $m[1];
            if ($year >= 1900 && $year <= (int) current_time('Y')) {
                return [null, $year];
            }
        }
        return [null, null];
    }

    /**
     * Enqueue form assets.
     */
    public function enqueue_assets(): void {
        if (is_singular() && has_shortcode(get_post()->post_content ?? '', 'avpvh_member_profile')) {
            wp_enqueue_style(
                'avpvh-registration',
                plugin_dir_url(dirname(__FILE__)) . 'assets/registration.css',
                [],
                avpvh_asset_version('assets/registration.css')
            );

            wp_enqueue_script(
                'avpvh-registration',
                plugin_dir_url(dirname(__FILE__)) . 'assets/registration.js',
                ['jquery'],
                avpvh_asset_version('assets/registration.js'),
                true
            );

            $reg_config = wp_json_encode([
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'strings' => [
                    'saving'  => __('Bezig met opslaan...', 'avpvh-members'),
                    'success' => __('Profiel succesvol bijgewerkt!', 'avpvh-members'),
                    'error'   => __('Er is een fout opgetreden.', 'avpvh-members'),
                    'failed'  => __('Opslaan van het profiel is mislukt. Probeer het opnieuw.', 'avpvh-members'),
                ],
            ]);
            add_action('wp_footer', function () use ($reg_config) {
                wp_print_inline_script_tag($reg_config, ['type' => 'application/json', 'id' => 'avpvh-registration-config']);
            });
            AVPVH_Address_Entry_Check::enqueue();
        }
    }
}
