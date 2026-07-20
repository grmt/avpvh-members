<?php
defined('ABSPATH') || exit;

class AVPVH_Member_Profile_Form {

    public function __construct() {
        add_shortcode('avpvh_member_profile', [$this, 'render_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_avpvh_save_member_profile', [$this, 'handle_save_profile']);
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

                <?php if ($is_admin_edit) : ?>
                    <fieldset>
                        <legend>Personal Information</legend>

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
                            <label for="baptism_name">Baptism Name (if different)</label>
                            <input type="text" id="baptism_name" name="baptism_name"
                                value="<?php echo esc_attr($member->baptism_name); ?>">
                        </div>

                        <div class="form-group">
                            <label for="birth_date">Birth Date</label>
                            <input type="date" id="birth_date" name="birth_date"
                                value="<?php echo esc_attr($member->birth_date); ?>">
                        </div>
                    </fieldset>
                <?php endif; ?>

                <fieldset>
                    <legend>Contact Information</legend>

                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email"
                            value="<?php echo esc_attr($member->email); ?>" readonly>
                        <p style="font-size: 0.9em; color: #666;">Email is managed in LLDAP (not editable here)</p>
                    </div>

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

                <?php if ($is_admin_edit) : ?>
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
                <?php else : ?>
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
                <?php endif; ?>

                <button type="submit" class="button button-primary">Update Profile</button>
            </form>

            <?php if (!$is_admin_edit) : ?>
                <?php $this->render_directory_consent($member); ?>
            <?php endif; ?>

            <!-- Audit Trail -->
            <?php $this->render_audit_trail($member); ?>
        </div>
        <?php

        return ob_get_clean();
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
                    Je gegevens zijn zichtbaar voor andere ingelogde actieve leden in de
                    ledenlijst, zoals beschreven in de privacyverklaring. Hieronder kun je
                    losse gegevens afschermen, of je gegevens volledig verbergen.
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
        $member_data = $this->sanitize_member_data($_POST, $is_admin_edit);

        try {
            // Update member with audit trail
            if ($member_data) {
                $formats = $is_admin_edit
                    ? ['%s', '%s', '%s', '%s', '%s', '%s', '%s']
                    : ['%s', '%s', '%s'];
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
     * Limit which fields a member may update themselves.
     */
    private function sanitize_member_data(array $data, bool $is_admin): array {
        $contact = [
            'phone' => sanitize_text_field($data['phone'] ?? ''),
            'mobile' => sanitize_text_field($data['mobile'] ?? ''),
            'emergency_contact' => sanitize_text_field($data['emergency_contact'] ?? ''),
        ];

        if ($is_admin) {
            return [
                'first_name' => sanitize_text_field($data['first_name'] ?? ''),
                'suffix' => sanitize_text_field($data['suffix'] ?? ''),
                'last_name' => sanitize_text_field($data['last_name'] ?? ''),
                'baptism_name' => sanitize_text_field($data['baptism_name'] ?? ''),
                'birth_date' => sanitize_text_field($data['birth_date'] ?? '') ?: null,
            ] + $contact;
        }

        // Self-service may only edit contact details.
        return $contact;
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
                plugin_dir_url(AVPVH_PLUGIN_DIR) . 'assets/registration.css',
                [],
                '1.0'
            );

            wp_enqueue_script(
                'avpvh-registration',
                plugin_dir_url(AVPVH_PLUGIN_DIR) . 'assets/registration.js',
                ['jquery'],
                '1.0',
                true
            );

            wp_localize_script('avpvh-registration', 'avpvhRegistration', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
            ]);
        }
    }
}

new AVPVH_Member_Profile_Form();
