<?php
defined('ABSPATH') || exit;

class AVPVH_Registration_Form {

    public function __construct() {
        add_shortcode('avpvh_registration', [$this, 'render_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_avpvh_save_registration', [$this, 'handle_save_registration']);
    }

    /**
     * Render registration form shortcode.
     * Usage: [avpvh_registration camp_id="1" year="2026"]
     */
    public function render_shortcode(array $atts = []): string {
        $atts = shortcode_atts([
            'camp_id' => 1,
            'year' => date('Y'),
        ], $atts, 'avpvh_registration');

        $camp_id = (int) $atts['camp_id'];
        $year = (int) $atts['year'];

        // Check authentication
        if (!is_user_logged_in()) {
            return '<p style="color: red;">Please log in to register for this excavation.</p>';
        }

        // Get current user's member profile
        $wp_user = wp_get_current_user();
        $current_member = AVPVH_DB::get_member_by_wp_user($wp_user->ID);

        if (!$current_member) {
            return '<p style="color: red;">Member profile not found.</p>';
        }

        // Get accessible family members (self + family + partner + partner's family)
        $accessible_members = $this->get_accessible_members($current_member);

        // Determine which member to edit (default to self, or from GET parameter)
        $member_to_edit_id = isset($_GET['member_id']) ? (int) $_GET['member_id'] : $current_member->id;

        // Verify permission
        $accessible_ids = array_map(fn($m) => $m->id, $accessible_members);
        if (!in_array($member_to_edit_id, $accessible_ids, true)) {
            return '<p style="color: red;">You do not have permission to edit this registration.</p>';
        }

        $member = AVPVH_DB::get_member($member_to_edit_id);
        if (!$member) {
            return '<p style="color: red;">Member not found.</p>';
        }

        // Get existing registration for this member
        $registration = AVPVH_Registration_DB::get_registration_by_email($member->email, $camp_id, $year);

        ob_start();
        ?>
        <div class="avpvh-registration-form">
            <h2>Registration for <?php echo esc_html(get_bloginfo('name')); ?> — <?php echo esc_html($year); ?></h2>

            <!-- Family Member Selector -->
            <?php if (count($accessible_members) > 1): ?>
                <div style="background: #f0f0f0; padding: 1rem; margin: 1rem 0; border-radius: 4px;">
                    <label for="member_selector"><strong>Register for:</strong></label>
                    <select id="member_selector" onchange="location.href='?member_id=' + this.value;">
                        <?php foreach ($accessible_members as $am): ?>
                            <option value="<?php echo esc_attr($am->id); ?>" <?php selected($am->id, $member_to_edit_id); ?>>
                                <?php echo esc_html($am->name); ?>
                                <?php if ($am->id !== $current_member->id): ?>
                                    (<?php echo esc_html($am->relationship ?? 'family'); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <p style="color: #666;">Editing registration for: <strong><?php echo esc_html(avpvh_format_name($member)); ?></strong></p>

            <form id="avpvh-registration-form" method="post">
                <?php wp_nonce_field('avpvh_registration', 'avpvh_nonce'); ?>
                <input type="hidden" name="camp_id" value="<?php echo esc_attr($camp_id); ?>">
                <input type="hidden" name="year" value="<?php echo esc_attr($year); ?>">
                <input type="hidden" name="member_email" value="<?php echo esc_attr($member->email); ?>">

                <!-- Personal Information -->
                <fieldset>
                    <legend>Your Information</legend>

                    <div class="form-group">
                        <label for="first_name">First Name *</label>
                        <input type="text" id="first_name" name="first_name"
                            value="<?php echo esc_attr($registration->first_name ?? $member->first_name); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="last_name">Last Name</label>
                        <input type="text" id="last_name" value="<?php echo esc_attr($member->last_name); ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" value="<?php echo esc_attr($member->email); ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label for="phone">Phone</label>
                        <input type="tel" id="phone" name="phone"
                            value="<?php echo esc_attr($registration->phone ?? $member->phone); ?>">
                    </div>
                </fieldset>

                <!-- Dietary Information -->
                <fieldset>
                    <legend>Dietary Restrictions</legend>
                    <div class="form-group">
                        <label for="food_allergies">Food Allergies or Restrictions</label>
                        <textarea id="food_allergies" name="food_allergies" rows="3"
                            placeholder="e.g., vegetarian, gluten-free, peanut allergy"><?php echo esc_textarea($registration->food_allergies ?? ''); ?></textarea>
                    </div>
                </fieldset>

                <!-- Attendance -->
                <fieldset>
                    <legend>Attendance (July 24 - August 8, 2026)</legend>
                    <p style="font-size: 0.9em; color: #666;">Select which nights you'll be attending:</p>
                    <?php $this->render_attendance_calendar($registration); ?>
                </fieldset>

                <!-- Other Notes -->
                <fieldset>
                    <legend>Additional Information</legend>
                    <div class="form-group">
                        <label for="notes">Any special remarks (e.g., dog, caravan, accessibility needs)</label>
                        <textarea id="notes" name="notes" rows="4"><?php echo esc_textarea($registration->notes ?? ''); ?></textarea>
                    </div>
                </fieldset>

                <button type="submit" class="button button-primary">
                    <?php echo $registration ? 'Update Registration' : 'Register'; ?>
                </button>

                <?php if ($registration && $registration->sync_status === 'synced'): ?>
                    <p style="color: green; margin-top: 1rem;">✓ This registration has been synced.</p>
                <?php elseif ($registration && $registration->sync_status === 'conflict'): ?>
                    <p style="color: orange; margin-top: 1rem;">⚠ There are conflicting changes. Please contact the organizers.</p>
                <?php endif; ?>
            </form>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Get all members the current user can edit: self + family + partner + partner's family.
     */
    private function get_accessible_members($current_member): array {
        $accessible = [];

        // Add self
        $accessible[] = (object) [
            'id' => $current_member->id,
            'name' => avpvh_format_name($current_member),
            'relationship' => 'self',
        ];

        // Add family members (if in a family)
        $family_id = AVPVH_DB::get_family_for_member($current_member->id);
        if ($family_id) {
            $family_members = AVPVH_DB::get_family_members($family_id);
            foreach ($family_members as $fm) {
                if ($fm->id !== $current_member->id) {
                    $accessible[] = (object) [
                        'id' => $fm->id,
                        'name' => avpvh_format_name($fm),
                        'relationship' => $fm->relationship ?? 'family',
                    ];
                }
            }
        }

        // Add partner (if has one)
        $partner = AVPVH_DB::get_partner($current_member->id);
        if ($partner) {
            // Check if partner already in family
            $partner_in_list = array_filter($accessible, fn($m) => $m->id === $partner->id);
            if (empty($partner_in_list)) {
                $accessible[] = (object) [
                    'id' => $partner->id,
                    'name' => avpvh_format_name($partner),
                    'relationship' => 'partner',
                ];
            }

            // Add partner's family members (if partner is in a family)
            $partner_family_id = AVPVH_DB::get_family_for_member($partner->id);
            if ($partner_family_id) {
                $partner_family_members = AVPVH_DB::get_family_members($partner_family_id);
                foreach ($partner_family_members as $fm) {
                    $in_list = array_filter($accessible, fn($m) => $m->id === $fm->id);
                    if (empty($in_list)) {
                        $accessible[] = (object) [
                            'id' => $fm->id,
                            'name' => avpvh_format_name($fm),
                            'relationship' => $fm->relationship ?? 'family',
                        ];
                    }
                }
            }
        }

        return $accessible;
    }

    /**
     * Render attendance calendar.
     */
    private function render_attendance_calendar(?object $registration): void {
        $start_date = new DateTime('2026-07-24');
        $end_date = new DateTime('2026-08-08');
        $interval = new DateInterval('P1D');
        $period = new DatePeriod($start_date, $interval, $end_date->add($interval));

        ?>
        <div class="attendance-calendar">
            <?php foreach ($period as $date): ?>
                <?php
                $date_str = $date->format('Y-m-d');
                $date_display = $date->format('D M d');
                $status = 'attending';

                if ($registration) {
                    $attendance = AVPVH_Registration_DB::get_attendance($registration->id, $date_str);
                    if ($attendance) {
                        $status = $attendance->status;
                    }
                }
                ?>

                <div class="attendance-day">
                    <label><?php echo esc_html($date_display); ?></label>
                    <select name="attendance[<?php echo esc_attr($date_str); ?>]">
                        <option value="attending" <?php selected($status, 'attending'); ?>>Attending</option>
                        <option value="not_attending" <?php selected($status, 'not_attending'); ?>>Not Attending</option>
                        <option value="maybe" <?php selected($status, 'maybe'); ?>>Maybe</option>
                    </select>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Handle form submission via AJAX.
     */
    public function handle_save_registration(): void {
        check_ajax_referer('avpvh_registration', 'avpvh_nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error('Not authenticated');
        }

        $wp_user = wp_get_current_user();
        $current_member = AVPVH_DB::get_member_by_wp_user($wp_user->ID);

        if (!$current_member) {
            wp_send_json_error('Member profile not found');
        }

        // Get member email from form (the person being registered)
        $member_email = sanitize_email($_POST['member_email'] ?? '');
        if (empty($member_email)) {
            wp_send_json_error('No member specified');
        }

        $member_to_edit = AVPVH_DB::get_member_by_email($member_email);
        if (!$member_to_edit) {
            wp_send_json_error('Member not found');
        }

        // Verify permission: user can only edit accessible family members
        $accessible_members = $this->get_accessible_members($current_member);
        $accessible_ids = array_map(fn($m) => $m->id, $accessible_members);
        if (!in_array($member_to_edit->id, $accessible_ids, true)) {
            wp_send_json_error('You do not have permission to edit this registration');
        }

        $camp_id = (int) ($_POST['camp_id'] ?? 1);
        $year = (int) ($_POST['year'] ?? date('Y'));
        $first_name = sanitize_text_field($_POST['first_name'] ?? '');
        $phone = sanitize_text_field($_POST['phone'] ?? '');
        $food_allergies = sanitize_textarea_field($_POST['food_allergies'] ?? '');
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');

        if (empty($first_name)) {
            wp_send_json_error('First name is required');
        }

        try {
            // Save registration for the selected member
            $registration_id = AVPVH_Registration_DB::save_registration(
                $member_email,
                $camp_id,
                $year,
                $first_name,
                $phone,
                $food_allergies ?: null,
                $notes ?: null,
                'pending_push'
            );

            // Save attendance
            if (!empty($_POST['attendance'])) {
                foreach ($_POST['attendance'] as $date => $status) {
                    $date = sanitize_text_field($date);
                    $status = sanitize_text_field($status);

                    if (in_array($status, ['attending', 'not_attending', 'maybe'], true)) {
                        AVPVH_Registration_DB::save_attendance(
                            $registration_id,
                            $date,
                            $status
                        );
                    }
                }
            }

            // Attempt to sync to Google Sheets
            try {
                $sync = new AVPVH_Google_Sheets_Sync();
                $sync->sync_to_sheet($camp_id, $year);
                AVPVH_Registration_DB::update_sync_status($registration_id, 'synced');
            } catch (Exception $e) {
                error_log('Google Sheets sync failed: ' . $e->getMessage());
            }

            wp_send_json_success([
                'message' => 'Registration saved successfully!',
                'registration_id' => $registration_id,
            ]);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Enqueue form assets.
     */
    public function enqueue_assets(): void {
        if (is_singular() && has_shortcode(get_post()->post_content ?? '', 'avpvh_registration')) {
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

new AVPVH_Registration_Form();
