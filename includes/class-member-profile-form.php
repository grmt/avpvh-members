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

        $member = $this->get_target_member();

        if (!$member) {
            return '<p style="color: red;">Member profile not found.</p>';
        }

        $is_admin_edit = current_user_can('manage_options') && !empty($_GET['member_id']);

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

            <!-- Audit Trail -->
            <?php $this->render_audit_trail($member); ?>
        </div>
        <?php

        return ob_get_clean();
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
        if (current_user_can('manage_options') && !empty($_GET['member_id'])) {
            $member_id = (int) $_GET['member_id'];
            if ($member_id > 0) {
                return AVPVH_DB::get_member($member_id);
            }
        }

        $wp_user = wp_get_current_user();
        return AVPVH_DB::get_member_by_wp_user($wp_user->ID);
    }

    private function get_target_member_for_save(): ?object {
        if (current_user_can('manage_options')) {
            $member_id = (int) ($_POST['member_id'] ?? 0);
            if ($member_id > 0) {
                return AVPVH_DB::get_member($member_id);
            }
        }

        $wp_user = wp_get_current_user();
        return AVPVH_DB::get_member_by_wp_user($wp_user->ID);
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
