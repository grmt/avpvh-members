<?php
defined('ABSPATH') || exit;

class AVPVH_Registrations_List {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu_page']);
    }

    public function add_menu_page(): void {
        add_submenu_page(
            'avpvh-members',
            'Registrations',
            'Registrations',
            'manage_options',
            'avpvh-registrations',
            [$this, 'render_page']
        );
    }

    public function render_page(): void {
        $year = isset($_GET['year']) ? (int) $_GET['year'] : 2026;
        $camp_id = isset($_GET['camp_id']) ? (int) $_GET['camp_id'] : 1;
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';

        $registrations = AVPVH_Registration_DB::get_registrations_for_camp($camp_id, $year);

        // Filter by sync status if specified
        if ($status) {
            $registrations = array_filter($registrations, fn($r) => $r->sync_status === $status);
        }

        // Get available camps for filter
        global $wpdb;
        $camps = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT id, name FROM {$wpdb->prefix}avm_camps WHERE year = %d ORDER BY name",
            $year
        ));

        ?>
        <div class="wrap">
            <h1>Registrations — <?php echo esc_html($year); ?></h1>

            <!-- Filters -->
            <div style="background: #f0f0f0; padding: 1rem; margin: 1rem 0; border-radius: 4px;">
                <form method="get" action="">
                    <input type="hidden" name="page" value="avpvh-registrations">

                    <label>Year:
                        <input type="number" name="year" value="<?php echo esc_attr($year); ?>" min="2020" max="2050">
                    </label>

                    <label>Camp:
                        <select name="camp_id">
                            <option value="">— All Camps —</option>
                            <?php foreach ($camps as $camp): ?>
                                <option value="<?php echo esc_attr($camp->id); ?>" <?php selected($camp->id, $camp_id); ?>>
                                    <?php echo esc_html($camp->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>Sync Status:
                        <select name="status">
                            <option value="">— All —</option>
                            <option value="synced" <?php selected($status, 'synced'); ?>>Synced</option>
                            <option value="pending_push" <?php selected($status, 'pending_push'); ?>>Pending Push</option>
                            <option value="pending_pull" <?php selected($status, 'pending_pull'); ?>>Pending Pull</option>
                            <option value="conflict" <?php selected($status, 'conflict'); ?>>Conflict</option>
                        </select>
                    </label>

                    <button type="submit" class="button">Filter</button>
                </form>
            </div>

            <!-- Registrations Table -->
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Email</th>
                        <th>First Name</th>
                        <th>Phone</th>
                        <th>Sync Status</th>
                        <th>Created</th>
                        <th>Last Sync</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($registrations)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 2rem;">
                                No registrations found.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($registrations as $reg): ?>
                            <tr>
                                <td><code><?php echo esc_html($reg->email); ?></code></td>
                                <td><?php echo esc_html($reg->first_name); ?></td>
                                <td><?php echo esc_html($reg->phone ?? '—'); ?></td>
                                <td>
                                    <?php $this->render_sync_badge($reg->sync_status); ?>
                                </td>
                                <td><?php echo esc_html(mysql2date('Y-m-d', $reg->created_at)); ?></td>
                                <td>
                                    <?php
                                    if ($reg->last_sync_timestamp) {
                                        echo esc_html(mysql2date('Y-m-d H:i', $reg->last_sync_timestamp));
                                    } else {
                                        echo '—';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=avpvh-registration-detail&id=' . $reg->id)); ?>" class="button button-small">Edit</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <p style="margin-top: 1rem; color: #666;">
                Total: <strong><?php echo count($registrations); ?></strong> registration<?php echo count($registrations) !== 1 ? 's' : ''; ?>
            </p>
        </div>
        <?php
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

new AVPVH_Registrations_List();
