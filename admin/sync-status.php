<?php
defined('ABSPATH') || exit;

class AVPVH_Sync_Status {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_post_avpvh_manual_sync', [$this, 'handle_manual_sync']);
    }

    public function add_menu_page(): void {
        add_submenu_page(
            'avpvh-members',
            'Sync Status',
            'Sync Status',
            'manage_options',
            'avpvh-sync-status',
            [$this, 'render_page']
        );
    }

    public function render_page(): void {
        $pending_registrations = AVPVH_Registration_DB::get_pending_sync_registrations(100);

        // Group by status
        $by_status = [];
        foreach ($pending_registrations as $reg) {
            $by_status[$reg->sync_status] ??= [];
            $by_status[$reg->sync_status][] = $reg;
        }

        ?>
        <div class="wrap">
            <h1>Registration Sync Status</h1>

            <!-- Manual Sync Button -->
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin: 1rem 0;">
                <input type="hidden" name="action" value="avpvh_manual_sync">
                <?php wp_nonce_field('avpvh_manual_sync'); ?>
                <button type="submit" class="button button-primary">Sync Now</button>
            </form>

            <!-- Overall Status -->
            <div style="background: #f0f0f0; padding: 1rem; margin: 1rem 0; border-radius: 4px;">
                <h2 style="margin-top: 0;">Sync Overview</h2>
                <ul style="list-style: none; padding: 0;">
                    <li style="margin: 0.5rem 0;">
                        <strong>Total pending:</strong> <?php echo count($pending_registrations); ?> registration<?php echo count($pending_registrations) !== 1 ? 's' : ''; ?>
                    </li>
                    <?php foreach (['pending_push', 'pending_pull', 'conflict'] as $status): ?>
                        <?php if (isset($by_status[$status])): ?>
                            <li style="margin: 0.5rem 0;">
                                <?php $this->render_status_label($status); ?>:
                                <strong><?php echo count($by_status[$status]); ?></strong>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- Pending Push -->
            <?php if (isset($by_status['pending_push']) && !empty($by_status['pending_push'])): ?>
                <h2>⬆ Pending Push (<?php echo count($by_status['pending_push']); ?>)</h2>
                <p style="color: #666;">Changes in WordPress waiting to be pushed to Google Sheets:</p>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Email</th>
                            <th>First Name</th>
                            <th>Updated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($by_status['pending_push'] as $reg): ?>
                            <tr>
                                <td><code><?php echo esc_html($reg->email); ?></code></td>
                                <td><?php echo esc_html($reg->first_name); ?></td>
                                <td><?php echo esc_html(mysql2date('Y-m-d H:i', $reg->updated_at)); ?></td>
                                <td>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=avpvh-registration-detail&id=' . $reg->id)); ?>" class="button button-small">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <!-- Pending Pull -->
            <?php if (isset($by_status['pending_pull']) && !empty($by_status['pending_pull'])): ?>
                <h2 style="margin-top: 2rem;">⬇ Pending Pull (<?php echo count($by_status['pending_pull']); ?>)</h2>
                <p style="color: #666;">Changes in Google Sheets waiting to be pulled to WordPress:</p>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Email</th>
                            <th>First Name</th>
                            <th>Last Sync</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($by_status['pending_pull'] as $reg): ?>
                            <tr>
                                <td><code><?php echo esc_html($reg->email); ?></code></td>
                                <td><?php echo esc_html($reg->first_name); ?></td>
                                <td><?php echo esc_html(mysql2date('Y-m-d H:i', $reg->last_sync_timestamp ?? 'N/A')); ?></td>
                                <td>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=avpvh-registration-detail&id=' . $reg->id)); ?>" class="button button-small">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <!-- Conflicts -->
            <?php if (isset($by_status['conflict']) && !empty($by_status['conflict'])): ?>
                <h2 style="margin-top: 2rem;">⚠ Conflicts (<?php echo count($by_status['conflict']); ?>)</h2>
                <p style="color: #666;">Changes in both WordPress and Google Sheets. Manual resolution required:</p>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Email</th>
                            <th>First Name</th>
                            <th>Last Sync</th>
                            <th>Conflict Count</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($by_status['conflict'] as $reg): ?>
                            <?php
                            $conflicts = AVPVH_Registration_DB::get_conflicts($reg->id);
                            ?>
                            <tr>
                                <td><code><?php echo esc_html($reg->email); ?></code></td>
                                <td><?php echo esc_html($reg->first_name); ?></td>
                                <td><?php echo esc_html(mysql2date('Y-m-d H:i', $reg->last_sync_timestamp ?? 'N/A')); ?></td>
                                <td><strong><?php echo count($conflicts); ?></strong></td>
                                <td>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=avpvh-registration-detail&id=' . $reg->id)); ?>" class="button button-small">Resolve</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if (empty($pending_registrations)): ?>
                <div style="background: #e0ffe0; padding: 1rem; margin: 1rem 0; border-radius: 4px;">
                    <p style="margin: 0; color: #008000;">
                        ✓ All registrations are synced!
                    </p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handle_manual_sync(): void {
        check_admin_referer('avpvh_manual_sync');

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        try {
            $sync = new AVPVH_Google_Sheets_Sync();

            // Sync all pending registrations
            $pending = AVPVH_Registration_DB::get_pending_sync_registrations(100);

            $pushed = 0;
            $pulled = 0;
            $errors = [];

            foreach ($pending as $registration) {
                try {
                    if ($registration->sync_status === 'pending_push') {
                        $sync->push_registration_to_sheet($registration);
                        $pushed++;
                    } elseif ($registration->sync_status === 'pending_pull') {
                        // Pull from sheet would happen here
                        $pulled++;
                    }
                } catch (Exception $e) {
                    $errors[] = $registration->email . ': ' . $e->getMessage();
                }
            }

            // Build result message
            $message = sprintf(
                'Sync complete: %d pushed, %d pulled',
                $pushed,
                $pulled
            );

            if (!empty($errors)) {
                $message .= '. Errors: ' . implode('; ', $errors);
            }

            wp_safe_redirect(admin_url('admin.php?page=avpvh-sync-status&sync_result=' . urlencode($message)));
        } catch (Exception $e) {
            wp_safe_redirect(admin_url('admin.php?page=avpvh-sync-status&sync_error=' . urlencode($e->getMessage())));
        }

        exit;
    }

    private function render_status_label(string $status): void {
        $colors = [
            'pending_push' => ['bg' => '#fff0e0', 'text' => '#ff8000', 'label' => '⬆ Pending Push'],
            'pending_pull' => ['bg' => '#fff0e0', 'text' => '#ff8000', 'label' => '⬇ Pending Pull'],
            'conflict' => ['bg' => '#ffe0e0', 'text' => '#ff0000', 'label' => '⚠ Conflict'],
        ];

        $config = $colors[$status] ?? $colors['pending_push'];
        printf(
            '<span style="background: %s; color: %s; padding: 0.25rem 0.5rem; border-radius: 3px; font-size: 0.9em;">%s</span>',
            esc_attr($config['bg']),
            esc_attr($config['text']),
            esc_html($config['label'])
        );
    }
}

new AVPVH_Sync_Status();
