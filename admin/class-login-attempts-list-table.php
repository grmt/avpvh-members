<?php
defined('ABSPATH') || exit;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class AVPVH_Login_Attempts_List_Table extends WP_List_Table {

    private static function method_labels(): array {
        return [
            'proxy'          => __('Wachtwoord (Authelia)', 'avpvh-members'),
            'google'         => 'Google',
            'microsoft'      => 'Microsoft',
            'password_reset' => __('Wachtwoord instellen', 'avpvh-members'),
            'email'          => __('E-maillink', 'avpvh-members'),
        ];
    }

    private static function result_labels(): array {
        return [
            'success'     => __('✓ Gelukt', 'avpvh-members'),
            'no_member'   => __('✗ Onbekend e-mailadres', 'avpvh-members'),
            'hibp_warned' => __('⚠ Gelekt wachtwoord gekozen', 'avpvh-members'),
            'link_sent'   => __('E-maillink verzonden', 'avpvh-members'),
        ];
    }

    private const MONTH_LABELS = [
        '01' => 'jan', '02' => 'feb', '03' => 'mrt', '04' => 'apr',
        '05' => 'mei', '06' => 'jun', '07' => 'jul', '08' => 'aug',
        '09' => 'sep', '10' => 'okt', '11' => 'nov', '12' => 'dec',
    ];

    public function __construct() {
        parent::__construct(['singular' => 'loginpoging', 'plural' => 'loginpogingen', 'ajax' => false]);
    }

    public function get_columns(): array {
        return [
            'id' => 'ID',
            'attempted_at' => __('Tijdstip', 'avpvh-members'),
            'email' => __('E-mailadres', 'avpvh-members'),
            'method' => __('Methode', 'avpvh-members'),
            'result' => __('Resultaat', 'avpvh-members'),
            'ip' => __('IP-adres', 'avpvh-members'),
        ];
    }

    public function get_sortable_columns(): array {
        return [
            'id' => ['id', false],
            'attempted_at' => ['attempted_at', true],
            'email' => ['email', false],
            'method' => ['method', false],
            'result' => ['result', false],
            'ip' => ['ip', false],
        ];
    }

    protected function get_default_primary_column_name(): string {
        return 'attempted_at';
    }

    public function no_items(): void {
        echo esc_html__('Geen loginpogingen gevonden.', 'avpvh-members');
    }

    public function prepare_items(): void {
        $columns = $this->get_columns();
        $hidden = get_hidden_columns(get_current_screen());
        $this->_column_headers = [$columns, $hidden, $this->get_sortable_columns(), $this->get_default_primary_column_name()];

        $per_page = $this->get_items_per_page('avpvh_login_attempts_per_page', 50);
        [$date_from, $date_to] = $this->get_date_filters();
        $query = AVPVH_DB::query_login_attempts([
            'search' => sanitize_text_field(wp_unslash($_GET['s'] ?? '')),
            'method' => $this->sanitize_filter_values($_GET['method'] ?? []),
            'result' => $this->sanitize_filter_values($_GET['result'] ?? []),
            'date_from' => $date_from,
            'date_to' => $date_to,
            'orderby' => sanitize_key(wp_unslash($_GET['orderby'] ?? 'attempted_at')),
            'order' => sanitize_key(wp_unslash($_GET['order'] ?? 'desc')),
            'per_page' => $per_page,
            'page' => $this->get_pagenum(),
        ]);
        $this->items = $query['items'];
        $this->set_pagination_args([
            'total_items' => $query['total'],
            'per_page' => $per_page,
            'total_pages' => (int) ceil($query['total'] / $per_page),
        ]);
    }

    public function column_default($item, $column_name) {
        return match ($column_name) {
            'id' => esc_html((string) $item->id),
            'attempted_at' => esc_html(wp_date('d-m-Y H:i:s', strtotime($item->attempted_at))),
            'email' => esc_html($item->email),
            'method' => esc_html(self::method_labels()[$item->method] ?? $item->method),
            'result' => $this->format_result($item->result),
            'ip' => '<code>' . esc_html($item->ip) . '</code>',
            default => '',
        };
    }

    protected function extra_tablenav($which): void {
        if ($which !== 'top') {
            return;
        }
        $filters = AVPVH_DB::get_login_attempt_filter_values();
        $selected_methods = $this->sanitize_filter_values($_GET['method'] ?? []);
        $selected_results = $this->sanitize_filter_values($_GET['result'] ?? []);
        $today = wp_date('Y-m-d');
        $latest_date_from = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
        [$date_from, $date_to] = $this->get_date_filters();
        $minimum_date_to = $date_from
            ? (new DateTimeImmutable($date_from))->modify('+1 day')->format('Y-m-d')
            : '';
        ?>
        <div class="alignleft actions avpvh-login-filters" data-avpvh-auto-filter
             data-msg-invalid="<?php esc_attr_e('Gebruik een geldige datum als dd-mmm-yyyy.', 'avpvh-members'); ?>"
             data-msg-from-future="<?php esc_attr_e('Van moet vóór vandaag liggen.', 'avpvh-members'); ?>"
             data-msg-to-future="<?php esc_attr_e('Tot kan niet later zijn dan vandaag.', 'avpvh-members'); ?>"
             data-msg-to-before-from="<?php esc_attr_e('Tot moet later zijn dan van.', 'avpvh-members'); ?>">
            <details class="avpvh-checkbox-filter<?php echo $selected_methods ? ' is-active' : ''; ?>" data-filter-key="method">
                <summary class="button">
                    <?php echo esc_html($this->filter_summary(__('Methode', 'avpvh-members'), $selected_methods, self::method_labels())); ?>
                </summary>
                <div class="avpvh-checkbox-filter__panel">
                <?php foreach ($filters['methods'] as $method) : ?>
                    <label>
                        <input type="checkbox" name="method[]" value="<?php echo esc_attr($method); ?>" data-auto-submit <?php checked(in_array($method, $selected_methods, true)); ?>>
                        <?php echo esc_html(self::method_labels()[$method] ?? $method); ?>
                    </label>
                <?php endforeach; ?>
                </div>
            </details>
            <details class="avpvh-checkbox-filter<?php echo $selected_results ? ' is-active' : ''; ?>" data-filter-key="result">
                <summary class="button">
                    <?php echo esc_html($this->filter_summary(__('Resultaat', 'avpvh-members'), $selected_results, self::result_labels())); ?>
                </summary>
                <div class="avpvh-checkbox-filter__panel">
                <?php foreach ($filters['results'] as $result) : ?>
                    <label>
                        <input type="checkbox" name="result[]" value="<?php echo esc_attr($result); ?>" data-auto-submit <?php checked(in_array($result, $selected_results, true)); ?>>
                        <?php echo esc_html(self::result_labels()[$result] ?? $result); ?>
                    </label>
                <?php endforeach; ?>
                </div>
            </details>
            <label for="filter-date-from"><?php esc_html_e('Van', 'avpvh-members'); ?></label>
            <input type="text" name="date_from" id="filter-date-from" value="<?php echo esc_attr($this->format_date_input($date_from)); ?>" placeholder="dd-mmm-yyyy" autocomplete="off" data-maximum-date="<?php echo esc_attr($latest_date_from); ?>" data-auto-submit>
            <label for="filter-date-to"><?php esc_html_e('tot', 'avpvh-members'); ?></label>
            <input type="text" name="date_to" id="filter-date-to" value="<?php echo esc_attr($this->format_date_input($date_to)); ?>" placeholder="dd-mmm-yyyy" autocomplete="off" data-minimum-date="<?php echo esc_attr($minimum_date_to); ?>" data-maximum-date="<?php echo esc_attr($today); ?>" data-auto-submit>
        </div>
        <?php
    }

    private function format_result(string $result): string {
        $styles = [
            'success' => 'color:green',
            'no_member' => 'color:#c00',
            'hibp_warned' => 'color:#b8600a;font-weight:bold',
        ];
        return '<span style="' . esc_attr($styles[$result] ?? '') . '">'
            . esc_html(self::result_labels()[$result] ?? $result) . '</span>';
    }

    private function sanitize_date($value): string {
        $value = sanitize_text_field(wp_unslash((string) $value));
        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/D', $value, $parts)
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return $value;
        }
        if (preg_match('/\A(\d{2})-(\d{2})-(\d{4})\z/D', $value, $parts)
            && checkdate((int) $parts[2], (int) $parts[1], (int) $parts[3])) {
            return $parts[3] . '-' . $parts[2] . '-' . $parts[1];
        }
        if (preg_match('/\A(\d{2})-([a-z]{3})-(\d{4})\z/Di', $value, $parts)) {
            $month = array_search(strtolower($parts[2]), self::MONTH_LABELS, true);
            if ($month && checkdate((int) $month, (int) $parts[1], (int) $parts[3])) {
                return $parts[3] . '-' . $month . '-' . $parts[1];
            }
        }
        return '';
    }

    private function format_date_input(string $value): string {
        if (!$value) {
            return '';
        }
        $month = self::MONTH_LABELS[substr($value, 5, 2)] ?? '';
        return substr($value, 8, 2) . '-' . $month . '-' . substr($value, 0, 4);
    }

    private function get_date_filters(): array {
        $today = wp_date('Y-m-d');
        $date_from = $this->sanitize_date($_GET['date_from'] ?? '');
        $date_to = $this->sanitize_date($_GET['date_to'] ?? '');

        if (!$date_to || $date_to > $today || ($date_from && $date_to <= $date_from)) {
            $date_to = $today;
        }
        if ($date_from && $date_from >= $date_to) {
            $date_from = '';
        }

        return [$date_from, $date_to];
    }

    private function sanitize_filter_values($values): array {
        $sanitized = [];
        foreach ((array) wp_unslash($values) as $value) {
            if (is_scalar($value)) {
                $sanitized[] = sanitize_key((string) $value);
            }
        }
        return array_values(array_filter(array_unique($sanitized), 'strlen'));
    }

    private function filter_summary(string $label, array $selected, array $labels): string {
        if (!$selected) {
            /* translators: %s: filter name, e.g. "Methode" */
            return sprintf(__('%s: alle', 'avpvh-members'), $label);
        }
        if (count($selected) === 1) {
            return $label . ': ' . ($labels[$selected[0]] ?? $selected[0]);
        }
        /* translators: 1: filter name, 2: number of selected values */
        return sprintf(__('%1$s: %2$d gekozen', 'avpvh-members'), $label, count($selected));
    }
}
