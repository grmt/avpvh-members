<?php
defined('ABSPATH') || exit;

class AVPVH_Registration_Detail {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_post_avpvh_save_registration_detail', [$this, 'handle_save']);
    }

    public function add_menu_page(): void {
        // Hidden menu page (not in menu, accessed via parent page)
        add_submenu_page(
            null,
            'Edit Registration',
            'Edit Registration',
            'manage_options',
            'avpvh-registration-detail',
            [$this, 'render_page']
        );
    }

    public function render_page(): void {
        if (!isset($_GET['id'])) {
            wp_die('Registration ID not specified.');
        }

        $registration_id = (int) $_GET['id'];
        $registration = AVPVH_Registration_DB::get_registration($registration_id);

        if (!$registration) {
            wp_die('Registration not found.');
        }

        $attendance = AVPVH_Registration_DB::get_registration_attendance($registration_id);
        $conflicts = AVPVH_Registration_DB::get_conflicts($registration_id);

        ?>
        <div class="wrap">
            <h1>Edit Registration</h1>

            <div style="background: #f9f9f9; padding: 1rem; margin: 1rem 0; border: 1px solid #ddd; border-radius: 4px;">
                <p>
                    <strong>Email:</strong> <code><?php echo esc_html($registration->email); ?></code>
                </p>
                <p>
                    <strong>Sync Status:</strong>
                    <?php $this->render_sync_badge($registration->sync_status); ?>
                </p>
                <?php if ($registration->last_sync_timestamp): ?>
                    <p>
                        <strong>Last Sync:</strong> <?php echo esc_html(mysql2date('Y-m-d H:i:s', $registration->last_sync_timestamp)); ?>
                    </p>
                <?php endif; ?>
            </div>

            <?php if (!empty($conflicts)): ?>
                <div style="background: #ffe0e0; padding: 1rem; margin: 1rem 0; border: 1px solid #ff0000; border-radius: 4px;">
                    <h2 style="margin-top: 0; color: #ff0000;">⚠ Sync Conflicts</h2>
                    <p>There are <?php echo count($conflicts); ?> unresolved conflict<?php echo count($conflicts) !== 1 ? 's' : ''; ?>:</p>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th>Field</th>
                                <th>WordPress Value</th>
                                <th>Google Sheets Value</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($conflicts as $conflict): ?>
                                <tr>
                                    <td><code><?php echo esc_html($conflict->field_name); ?></code></td>
                                    <td><code><?php echo esc_html($conflict->wp_value ?? '(empty)'); ?></code></td>
                                    <td><code><?php echo esc_html($conflict->sheet_value ?? '(empty)'); ?></code></td>
                                    <td>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display: inline;">
                                            <input type="hidden" name="action" value="avpvh_resolve_conflict">
                                            <input type="hidden" name="conflict_id" value="<?php echo esc_attr($conflict->id); ?>">
                                            <input type="hidden" name="registration_id" value="<?php echo esc_attr($registration_id); ?>">
                                            <?php wp_nonce_field('avpvh_resolve_conflict'); ?>
                                            <button type="submit" class="button button-small">Resolve</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="avpvh_save_registration_detail">
                <input type="hidden" name="registration_id" value="<?php echo esc_attr($registration_id); ?>">
                <?php wp_nonce_field('avpvh_save_registration_detail'); ?>

                <!-- Basic Information -->
                <h2>Registration Information</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="first_name">First Name</label></th>
                        <td>
                            <input type="text" id="first_name" name="first_name"
                                value="<?php echo esc_attr($registration->first_name); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="phone">Phone</label></th>
                        <td>
                            <input type="tel" id="phone" name="phone"
                                value="<?php echo esc_attr($registration->phone ?? ''); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="food_allergies">Food Allergies</label></th>
                        <td>
                            <textarea id="food_allergies" name="food_allergies" rows="3" class="large-text"><?php echo esc_textarea($registration->food_allergies ?? ''); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="notes">Notes</label></th>
                        <td>
                            <textarea id="notes" name="notes" rows="3" class="large-text"><?php echo esc_textarea($registration->notes ?? ''); ?></textarea>
                        </td>
                    </tr>
                </table>

                <!-- Attendance -->
                <h2>Attendance</h2>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $start_date = new DateTime('2026-07-24');
                        $end_date = new DateTime('2026-08-08');
                        $interval = new DateInterval('P1D');
                        $period = new DatePeriod($start_date, $interval, $end_date->add($interval));

                        foreach ($period as $date):
                            $date_str = $date->format('Y-m-d');
                            $att = array_filter($attendance, fn($a) => $a->date === $date_str)[0] ?? null;
                            $status = $att ? $att->status : 'attending';
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html($date->format('D M d, Y')); ?></strong></td>
                                <td>
                                    <select name="attendance[<?php echo esc_attr($date_str); ?>]">
                                        <option value="attending" <?php selected($status, 'attending'); ?>>Attending</option>
                                        <option value="not_attending" <?php selected($status, 'not_attending'); ?>>Not Attending</option>
                                        <option value="maybe" <?php selected($status, 'maybe'); ?>>Maybe</option>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p style="margin-top: 1rem;">
                    <button type="submit" class="button button-primary">Save Changes</button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=avpvh-registrations')); ?>" class="button">Cancel</a>
                </p>
            </form>
        </div>
        <?php
    }

    public function handle_save(): void {
        if (!isset($_POST['registration_id'])) {
            wp_die('Registration ID not specified.');
        }

        check_admin_referer('avpvh_save_registration_detail');

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        $registration_id = (int) $_POST['registration_id'];
        $registration = AVPVH_Registration_DB::get_registration($registration_id);

        if (!$registration) {
            wp_die('Registration not found.');
        }

        $first_name = sanitize_text_field($_POST['first_name'] ?? '');
        $phone = sanitize_text_field($_POST['phone'] ?? '');
        $food_allergies = sanitize_textarea_field($_POST['food_allergies'] ?? '');
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');

        if (empty($first_name)) {
            wp_die('First name is required.');
        }

        // Update registration
        AVPVH_Registration_DB::save_registration(
            $registration->email,
            $registration->camp_id,
            $registration->year,
            $first_name,
            $phone,
            $food_allergies ?: null,
            $notes ?: null,
            'pending_push'
        );

        // Update attendance
        if (!empty($_POST['attendance'])) {
            foreach ($_POST['attendance'] as $date => $status) {
                $date = sanitize_text_field($date);
                $status = sanitize_text_field($status);

                if (in_array($status, ['attending', 'not_attending', 'maybe'], true)) {
                    AVPVH_Registration_DB::save_attendance($registration_id, $date, $status);
                }
            }
        }

        wp_safe_redirect(admin_url('admin.php?page=avpvh-registrations&updated=1'));
        exit;
    }

    private function render_sync_badge(string $status): void {
        $colors = [
            'synced' => ['bg' => '#e0ffe0', 'text' => '#008000', 'label' => '✓ Synced'],
            'pending_push' => ['bg' => '#fff0e0', 'text' => '#ff8000', 'label' => '⬆ Pending Push'],
            'pending_pull' => ['bg' => '#fff0e0', 'text' => '#ff8000', 'label' => '⬇ Pending Pull'],
            'conflict' => ['bg' => '#ffe0e0', 'text' => '#ff0000', 'label' => '⚠ Conflict'],
        ];

        $config = $colors[$status] ?? $colors['synced'];
        printf(
            '<span style="background: %s; color: %s; padding: 0.25rem 0.5rem; border-radius: 3px; font-size: 0.9em;">%s</span>',
            esc_attr($config['bg']),
            esc_attr($config['text']),
            esc_html($config['label'])
        );
    }
}

new AVPVH_Registration_Detail();
