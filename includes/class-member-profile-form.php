<?php
defined('ABSPATH') || exit;

class AVPVH_Member_Profile_Form {

    public function __construct() {
        add_shortcode('avpvh_member_profile', [$this, 'render_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_avpvh_save_member_profile', [$this, 'handle_save_profile']);
        add_action('admin_post_avpvh_remove_identity', [$this, 'handle_remove_identity']);
    }

    /**
     * Render member profile form shortcode.
     * Usage: [avpvh_member_profile]
     */
    public function render_shortcode(): string {
        if (!is_user_logged_in()) {
            return '<p style="color: red;">Please log in to edit your profile.</p>';
        }

        $own_member = AVPVH_DB::get_member_by_wp_user(get_current_user_id());
        $member = $this->get_target_member();

        if (!$member) {
            return '<p style="color: red;">Member profile not found.</p>';
        }

        $is_admin_edit = current_user_can('manage_options') && !empty($_GET['member_id']);
        $is_household_edit = !$is_admin_edit && $own_member && (int) $member->id !== (int) $own_member->id;
        $huisgenoten = $own_member ? AVPVH_DB::get_manageable_members((int) $own_member->id) : [];
        // The family-relation picker must list $member's own household, which
        // differs from $huisgenoten (the current user's household) when an
        // admin edits someone else's profile.
        $member_household = AVPVH_DB::get_manageable_members((int) $member->id);

        // Get current address
        $addresses = AVPVH_DB::get_addresses($member->id);
        $current_address = null;
        $today = current_time('Y-m-d');

        foreach ($addresses as $addr) {
            if (
                (!$addr->valid_from || $addr->valid_from <= $today) &&
                (!$addr->valid_until || $addr->valid_until >= $today)
            ) {
                $current_address = $addr;
                break;
            }
        }

        ob_start();
        ?>
        <div class="avpvh-member-profile-form">
            <h2><?php echo $is_admin_edit ? 'Edit Member Profile' : 'Edit Your Profile'; ?></h2>
            <?php if ($is_admin_edit) : ?>
                <p style="color: #666;">Administrator editing: <strong><?php echo esc_html(avpvh_format_name($member)); ?></strong></p>
            <?php elseif ($is_household_edit) : ?>
                <p style="color: #666;">Je bewerkt het profiel van: <strong><?php echo esc_html(avpvh_format_name($member)); ?></strong></p>
            <?php endif; ?>

            <?php if (count($huisgenoten) > 1) : ?>
                <div class="avpvh-huisgenoten">
                    <span>Huisgenoten:</span>
                    <?php foreach ($huisgenoten as $hg) : ?>
                        <?php if ((int) $hg->id === (int) $member->id) : ?>
                            <strong><?php echo esc_html(avpvh_format_name($hg)); ?></strong>
                        <?php else : ?>
                            <a href="<?php echo esc_url(add_query_arg('member_id', $hg->id)); ?>"><?php echo esc_html(avpvh_format_name($hg)); ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if (is_user_logged_in()) : ?>
                <?php $user = wp_get_current_user(); ?>
                <div class="avpvh-auth-status" style="margin: 0 0 1rem;">
                    <span class="avpvh-auth-status__badge">
                        <?php
                        $member_role = (string) get_user_meta($user->ID, 'avpvh_member_role', true);
                        $member_role_label = $this->member_role_label($member_role);
                        $parts = [
                            avpvh_format_name($member),
                            $this->role_label($user),
                        ];
                        if ($member_role_label !== '') {
                            $parts[] = $member_role_label;
                        }
                        $parts[] = ($member->status === 'active' ? 'Actief lid' : ($member->status === 'inactive' ? 'Oud lid' : 'Bezoeker'));
                        echo esc_html(
                            implode(' · ', $parts)
                        );
                        ?>
                    </span>
                </div>
            <?php endif; ?>

            <form id="avpvh-profile-form" method="post">
                <?php wp_nonce_field('avpvh_member_profile', 'avpvh_nonce'); ?>
                <input type="hidden" name="member_id" value="<?php echo esc_attr($member->id); ?>">

                <fieldset>
                    <legend>Persoonlijke gegevens</legend>

                    <div class="form-group">
                        <label for="first_name">Voornaam *</label>
                        <input type="text" id="first_name" name="first_name"
                            value="<?php echo esc_attr($member->first_name); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="suffix">Tussenvoegsel</label>
                        <input type="text" id="suffix" name="suffix"
                            value="<?php echo esc_attr($member->suffix ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="last_name">Achternaam *</label>
                        <input type="text" id="last_name" name="last_name"
                            value="<?php echo esc_attr($member->last_name); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="passport_name">Paspoortnaam (indien afwijkend)</label>
                        <input type="text" id="passport_name" name="passport_name"
                            value="<?php echo esc_attr($member->passport_name ?? ''); ?>">
                    </div>

                    <?php if ($is_admin_edit) : ?>
                        <div class="form-group">
                            <label for="baptism_name">Doopnaam (indien afwijkend)</label>
                            <input type="text" id="baptism_name" name="baptism_name"
                                value="<?php echo esc_attr($member->baptism_name); ?>">
                        </div>

                        <div class="form-group">
                            <label for="birth_date">Geboortedatum</label>
                            <input type="date" id="birth_date" name="birth_date"
                                value="<?php echo esc_attr($member->birth_date); ?>">
                        </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="family_relation_member_id">Familierelatie</label>
                        <select id="family_relation_member_id" name="family_relation_member_id">
                            <option value="">— Geen —</option>
                            <?php foreach ($member_household as $hg) : ?>
                                <?php if ((int) $hg->id === (int) $member->id) continue; ?>
                                <option value="<?php echo esc_attr($hg->id); ?>" <?php selected((int) ($member->family_relation_member_id ?? 0), (int) $hg->id); ?>>
                                    <?php echo esc_html(avpvh_format_name($hg)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p style="font-size: 0.9em; color: #666;">Bijvoorbeeld: kind van, partner van — kies uit je huisgenoten.</p>
                    </div>

                    <div class="form-group">
                        <label for="diet">Eetgewoontes / allergieën</label>
                        <input type="text" id="diet" name="diet"
                            value="<?php echo esc_attr($member->diet ?? ''); ?>"
                            placeholder="bv. vegetarisch, notenallergie">
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Contact Information</legend>

                    <div class="form-group">
                        <label for="phone">Phone (Home)</label>
                        <input type="tel" id="phone" name="phone"
                            value="<?php echo esc_attr($member->phone); ?>">
                    </div>

                    <div class="form-group">
                        <label for="mobile">Mobile (Cell)</label>
                        <input type="tel" id="mobile" name="mobile"
                            value="<?php echo esc_attr($member->mobile); ?>">
                    </div>

                    <div class="form-group">
                        <label for="emergency_contact">Emergency Contact Name</label>
                        <input type="text" id="emergency_contact" name="emergency_contact"
                            value="<?php echo esc_attr($member->emergency_contact); ?>">
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Address</legend>

                    <div class="form-group">
                        <label for="street">Street</label>
                        <input type="text" id="street" name="street"
                            value="<?php echo esc_attr($current_address->street ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="house_number">House Number</label>
                        <input type="text" id="house_number" name="house_number"
                            value="<?php echo esc_attr($current_address->house_number ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="postal_code">Postal Code</label>
                        <input type="text" id="postal_code" name="postal_code"
                            value="<?php echo esc_attr($current_address->postal_code ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="city">City</label>
                        <input type="text" id="city" name="city"
                            value="<?php echo esc_attr($current_address->city ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="country">Country</label>
                        <input type="text" id="country" name="country"
                            value="<?php echo esc_attr($current_address->country ?? 'Nederland'); ?>">
                    </div>
                </fieldset>

                <button type="submit" class="button button-primary">Update Profile</button>
            </form>

            <?php if (!$is_admin_edit) : ?>
                <?php $this->render_identities($member); ?>
                <?php $this->render_directory_consent($member); ?>
            <?php endif; ?>

            <!-- Audit Trail -->
            <?php $this->render_audit_trail($member); ?>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Self-service e-mail/identity management: shows the member's up-to-3
     * login identities (one per provider: email/google/microsoft), lets them
     * verify-and-add a Google/Microsoft account, and remove one (with a
     * warning if it's the address they're currently logged in with).
     */
    private function render_identities($member): void {
        $identities = AVPVH_DB::get_member_identities((int) $member->id);
        $by_provider = [];
        foreach ($identities as $identity) {
            $by_provider[$identity->provider] = $identity;
        }

        $current_user = wp_get_current_user();
        $providers = [
            'email'     => 'E-mailadres',
            'google'    => 'Google',
            'microsoft' => 'Microsoft',
        ];
        ?>
        <fieldset class="avpvh-identities">
            <legend>Inlog-e-mailadressen</legend>
            <p>
                Je kunt tot drie e-mailadressen koppelen om mee in te loggen: één gewoon
                e-mailadres, één Google-account en één Microsoft-account. Om een adres toe
                te voegen moet je er daadwerkelijk mee inloggen, zodat we weten dat het
                echt van jou is.
            </p>

            <?php if (!empty($_GET['identity_added'])) : ?>
                <p class="avpvh-identity-notice">E-mailadres toegevoegd.</p>
            <?php elseif (!empty($_GET['identity_error'])) : ?>
                <p class="avpvh-identity-notice avpvh-identity-notice--error">
                    <?php
                    $errors = [
                        'in_use'    => 'Dat e-mailadres is al aan een ander lid gekoppeld.',
                        'not_you'   => 'Er ging iets mis met de verificatie — probeer het opnieuw.',
                        'limit'     => 'Dit adres kon niet worden toegevoegd.',
                    ];
                    echo esc_html($errors[$_GET['identity_error']] ?? 'Er ging iets mis.');
                    ?>
                </p>
            <?php elseif (!empty($_GET['identity_removed'])) : ?>
                <p class="avpvh-identity-notice">E-mailadres verwijderd.</p>
            <?php endif; ?>

            <table class="avpvh-identities-table">
                <?php foreach ($providers as $key => $label) : ?>
                    <tr>
                        <td><?php echo esc_html($label); ?></td>
                        <?php if (isset($by_provider[$key])) :
                            $identity = $by_provider[$key];
                            $is_current_login = strcasecmp($identity->email, $current_user->user_email) === 0;
                        ?>
                            <td><?php echo esc_html($identity->email); ?></td>
                            <td>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                    onsubmit="return confirm('<?php echo $is_current_login
                                        ? esc_js('Let op: dit is het adres waarmee je nu bent ingelogd. Als je het verwijdert, kun je daar niet meer mee inloggen. Doorgaan?')
                                        : esc_js('Dit e-mailadres verwijderen?'); ?>');">
                                    <?php wp_nonce_field('avpvh_remove_identity'); ?>
                                    <input type="hidden" name="action" value="avpvh_remove_identity">
                                    <input type="hidden" name="member_id" value="<?php echo esc_attr($member->id); ?>">
                                    <input type="hidden" name="provider" value="<?php echo esc_attr($key); ?>">
                                    <button type="submit" class="button">Verwijderen</button>
                                </form>
                            </td>
                        <?php else : ?>
                            <td><em>niet gekoppeld</em></td>
                            <td>
                                <?php if ($key === 'email') : ?>
                                    —
                                <?php else :
                                    $configured = AVPVH_OAuth::configured_providers();
                                    if (isset($configured[$key])) :
                                ?>
                                    <a class="button" href="<?php echo esc_url(AVPVH_OAuth::add_identity_url($key, (int) $member->id)); ?>">
                                        Verifieer en voeg toe
                                    </a>
                                <?php endif; endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </table>
        </fieldset>
        <?php
    }

    /**
     * Granular ledenlijst visibility controls (AVG/GDPR consent).
     */
    private function render_directory_consent($member): void {
        ?>
        <fieldset class="avpvh-directory-consent">
            <legend>Zichtbaarheid in ledenlijst</legend>

            <?php if (!empty($_GET['consent_saved'])) : ?>
                <p>Je voorkeuren zijn opgeslagen.</p>
            <?php endif; ?>

            <?php if ($member->directory_consent === 'granted') : ?>
                <p>
                    Zoals beschreven in de privacyverklaring zijn je gegevens alleen
                    zichtbaar voor andere actieve leden, maar alleen als ze ingelogd zijn.
                    Hieronder kan je door het vinkje te verwijderen aangeven welke gegevens
                    je ook voor hen wil verbergen.
                </p>
            <?php else : ?>
                <p>Je deelt momenteel geen gegevens met andere leden in de ledenlijst.</p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('avpvh_directory_consent'); ?>
                <input type="hidden" name="action" value="avpvh_set_directory_consent">
                <input type="hidden" name="member_id" value="<?php echo esc_attr($member->id); ?>">

                <div class="form-group">
                    <label>
                        <input type="checkbox" name="share_email" value="1" <?php checked($member->share_email); ?>>
                        E-mailadres tonen in de ledenlijst
                    </label>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="share_phone" value="1" <?php checked($member->share_phone); ?>>
                        Telefoonnummer tonen in de ledenlijst
                    </label>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="share_address" value="1" <?php checked($member->share_address); ?>>
                        Adres tonen in de ledenlijst
                    </label>
                </div>

                <button type="submit" name="consent" value="granted" class="button button-primary">Voorkeuren opslaan</button>
                <?php if ($member->directory_consent === 'granted') : ?>
                    <button type="submit" name="consent" value="declined" class="button">Toestemming volledig intrekken</button>
                <?php endif; ?>
            </form>
        </fieldset>
        <?php
    }

    /**
     * Show audit trail of changes.
     */
    private function render_audit_trail($member): void {
        $audit_log = AVPVH_DB::get_member_audit_log($member->id, 50);

        if (empty($audit_log)) {
            echo '<p style="color: #666; margin-top: 2rem;">No changes recorded yet.</p>';
            return;
        }

        ?>
        <hr style="margin: 2rem 0;">

        <fieldset>
            <legend>Change History</legend>
            <p style="font-size: 0.9em; color: #666;">All changes to your profile are tracked below:</p>

            <table style="width: 100%; border-collapse: collapse; margin-top: 1rem;">
                <thead>
                    <tr style="background: #f0f0f0;">
                        <th style="padding: 0.75rem; border: 1px solid #ddd; text-align: left;">Date</th>
                        <th style="padding: 0.75rem; border: 1px solid #ddd; text-align: left;">Field</th>
                        <th style="padding: 0.75rem; border: 1px solid #ddd; text-align: left;">Changed By</th>
                        <th style="padding: 0.75rem; border: 1px solid #ddd; text-align: left;">Old Value</th>
                        <th style="padding: 0.75rem; border: 1px solid #ddd; text-align: left;">New Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($audit_log as $entry): ?>
                        <tr>
                            <td style="padding: 0.75rem; border: 1px solid #ddd;">
                                <?php echo esc_html(mysql2date('Y-m-d H:i', $entry->changed_at)); ?>
                            </td>
                            <td style="padding: 0.75rem; border: 1px solid #ddd;">
                                <code><?php echo esc_html($entry->field_name); ?></code>
                            </td>
                            <td style="padding: 0.75rem; border: 1px solid #ddd;">
                                <?php echo esc_html($entry->user_login ?? 'System'); ?>
                            </td>
                            <td style="padding: 0.75rem; border: 1px solid #ddd; background: #ffe0e0;">
                                <code style="font-size: 0.85em;"><?php echo esc_html($entry->old_value ?? '(empty)'); ?></code>
                            </td>
                            <td style="padding: 0.75rem; border: 1px solid #ddd; background: #e0ffe0;">
                                <code style="font-size: 0.85em;"><?php echo esc_html($entry->new_value ?? '(empty)'); ?></code>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </fieldset>
        <?php
    }

    /**
     * Handle form submission via AJAX.
     */
    public function handle_save_profile(): void {
        check_ajax_referer('avpvh_member_profile', 'avpvh_nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error('Not authenticated');
        }

        $member = $this->get_target_member_for_save();

        if (!$member) {
            wp_send_json_error('Member profile not found');
        }

        $is_admin_edit = current_user_can('manage_options') && !empty($_POST['member_id']);
        $member_data = $this->sanitize_member_data($_POST, $is_admin_edit, (int) $member->id);

        try {
            // Update member with audit trail
            if ($member_data) {
                $formats = array_fill(0, count($member_data), '%s');
                AVPVH_DB::update_member_with_audit(
                    $member->id,
                    $member_data,
                    $formats
                );
            }

            // Update address if provided
            if (!empty($_POST['street']) || !empty($_POST['postal_code'])) {
                $address_data = [
                    'street' => sanitize_text_field($_POST['street'] ?? ''),
                    'house_number' => sanitize_text_field($_POST['house_number'] ?? ''),
                    'postal_code' => sanitize_text_field($_POST['postal_code'] ?? ''),
                    'city' => sanitize_text_field($_POST['city'] ?? ''),
                    'country' => sanitize_text_field($_POST['country'] ?? 'Nederland'),
                    'valid_from' => current_time('Y-m-d'),
                ];

                global $wpdb;
                $wpdb->insert(
                    "{$wpdb->prefix}avm_addresses",
                    ['member_id' => $member->id] + $address_data,
                    ['%d'] + array_fill(0, count($address_data), '%s')
                );
            }

            wp_send_json_success('Profile updated successfully!');
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    private function get_target_member(): ?object {
        $wp_user = wp_get_current_user();
        $own_member = AVPVH_DB::get_member_by_wp_user($wp_user->ID);

        $member_id = (int) ($_GET['member_id'] ?? 0);
        if ($member_id > 0 && $this->can_edit_member($own_member, $member_id)) {
            return AVPVH_DB::get_member($member_id);
        }

        return $own_member;
    }

    private function get_target_member_for_save(): ?object {
        $wp_user = wp_get_current_user();
        $own_member = AVPVH_DB::get_member_by_wp_user($wp_user->ID);

        $member_id = (int) ($_POST['member_id'] ?? 0);
        if ($member_id > 0 && $this->can_edit_member($own_member, $member_id)) {
            return AVPVH_DB::get_member($member_id);
        }

        return $own_member;
    }

    /**
     * Admins can edit any member; everyone else can edit their own profile or
     * a household member's (same family link or current address).
     */
    private function can_edit_member(?object $own_member, int $target_member_id): bool {
        if (current_user_can('manage_options')) {
            return true;
        }
        if (!$own_member) {
            return false;
        }
        foreach (AVPVH_DB::get_manageable_members((int) $own_member->id) as $m) {
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
            wp_die('Je moet ingelogd zijn.', 'Fout', ['response' => 403]);
        }

        $member_id = (int) ($_POST['member_id'] ?? 0);
        $provider  = sanitize_key($_POST['provider'] ?? '');
        $own_member = AVPVH_DB::get_member_by_wp_user(get_current_user_id());

        if (!$member_id || !$this->can_edit_member($own_member, $member_id)) {
            wp_die('Geen toegang.', 'Fout', ['response' => 403]);
        }

        $member = AVPVH_DB::get_member($member_id);
        if (!$member) {
            wp_die('Lid niet gevonden.', 'Fout', ['response' => 404]);
        }

        $identities = AVPVH_DB::get_member_identities($member_id);
        if (count($identities) <= 1) {
            wp_safe_redirect(add_query_arg(['member_id' => $member_id, 'identity_error' => 'limit'], wp_get_referer() ?: home_url('/member-profile/')));
            exit;
        }

        $removed_email = null;
        foreach ($identities as $identity) {
            if ($identity->provider === $provider) {
                $removed_email = $identity->email;
                break;
            }
        }

        if ($removed_email) {
            AVPVH_DB::delete_identity($member_id, $provider);
            self::notify_identity_change($member, $own_member, 'verwijderd', $provider, $removed_email);
        }

        wp_safe_redirect(add_query_arg(['member_id' => $member_id, 'identity_removed' => '1'], wp_get_referer() ?: home_url('/member-profile/')));
        exit;
    }

    /**
     * E-mails everyone who should know an identity changed: the person who
     * made the change, and — only when they edited someone else's profile —
     * the affected member as well, at their remaining identities.
     */
    public static function notify_identity_change(object $member, ?object $actor_member, string $action, string $provider, string $email): void {
        $provider_labels = ['email' => 'e-mailadres', 'google' => 'Google-account', 'microsoft' => 'Microsoft-account'];
        $provider_label = $provider_labels[$provider] ?? $provider;
        $member_name = avpvh_format_name($member);

        $actor_user = wp_get_current_user();
        $is_self = $actor_member && (int) $actor_member->id === (int) $member->id;

        $subject = 'AV Philips van Horne — inloggegevens gewijzigd';
        $body_actor = "Er is zojuist een {$provider_label} ({$email}) {$action} bij het profiel van {$member_name}.\n\n"
            . "Was jij dit niet? Neem dan contact op met het bestuur.";
        wp_mail($actor_user->user_email, $subject, $body_actor);

        if (!$is_self) {
            $body_member = "Er is zojuist een {$provider_label} ({$email}) {$action} bij jouw profiel door "
                . esc_html($actor_user->display_name ?: $actor_user->user_login) . ".\n\n"
                . "Was jij dit niet? Neem dan contact op met het bestuur.";
            foreach (AVPVH_DB::get_member_identities((int) $member->id) as $identity) {
                wp_mail($identity->email, $subject, $body_member);
            }
        }
    }

    /**
     * Fields every member (or a household member editing on their behalf)
     * may update themselves; admin-only fields (baptism name, birth date)
     * are added on top for the admin edit screen.
     */
    private function sanitize_member_data(array $data, bool $is_admin, int $member_id): array {
        $fields = [
            'first_name' => sanitize_text_field($data['first_name'] ?? ''),
            'suffix' => sanitize_text_field($data['suffix'] ?? ''),
            'last_name' => sanitize_text_field($data['last_name'] ?? ''),
            'passport_name' => sanitize_text_field($data['passport_name'] ?? ''),
            'phone' => sanitize_text_field($data['phone'] ?? ''),
            'mobile' => sanitize_text_field($data['mobile'] ?? ''),
            'emergency_contact' => sanitize_text_field($data['emergency_contact'] ?? ''),
            'diet' => sanitize_text_field($data['diet'] ?? ''),
            'family_relation_member_id' => $this->sanitize_family_relation($data['family_relation_member_id'] ?? '', $member_id),
        ];

        if ($is_admin) {
            $fields['baptism_name'] = sanitize_text_field($data['baptism_name'] ?? '');
            $fields['birth_date'] = sanitize_text_field($data['birth_date'] ?? '') ?: null;
        }

        return $fields;
    }

    /**
     * A family relation must point to someone in the same household as the
     * member being edited — never an arbitrary member id.
     */
    private function sanitize_family_relation(string $raw, int $member_id): ?string {
        $relation_id = (int) $raw;
        if ($relation_id <= 0) {
            return null;
        }
        foreach (AVPVH_DB::get_manageable_members($member_id) as $m) {
            if ((int) $m->id === $relation_id) {
                return (string) $relation_id;
            }
        }
        return null;
    }

    private function role_label(\WP_User $user): string {
        if (empty($user->roles)) {
            return 'Gebruiker';
        }

        return match ($user->roles[0]) {
            'administrator' => 'Beheerder',
            'editor'         => 'Redacteur',
            'author'         => 'Auteur',
            'contributor'    => 'Medewerker',
            'subscriber'     => 'Lid',
            default          => ucfirst(str_replace('_', ' ', $user->roles[0])),
        };
    }

    private function member_role_label(string $role): string {
        if ($role === '') {
            return '';
        }

        return match (strtolower($role)) {
            'bestuur'      => 'Bestuur',
            'feest'        => 'Feest',
            'boek'         => 'Boek',
            'fiscus'       => 'Fiscus',
            'secretariaat'  => 'Secretariaat',
            default        => ucfirst($role),
        };
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

            wp_localize_script('avpvh-registration', 'avpvhRegistration', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
            ]);
        }
    }
}

new AVPVH_Member_Profile_Form();
