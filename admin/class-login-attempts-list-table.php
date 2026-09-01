<?php
defined('ABSPATH') || exit;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class AVPVH_Login_Attempts_List_Table extends WP_List_Table {

    private const METHOD_LABELS = [
        'proxy' => 'Wachtwoord (Authelia)',
        'google' => 'Google',
        'microsoft' => 'Microsoft',
        'password_reset' => 'Wachtwoord instellen',
        'email' => 'E-maillink',
    ];

    private const RESULT_LABELS = [
        'success' => '✓ Gelukt',
        'no_member' => '✗ Onbekend e-mailadres',
        'hibp_warned' => '⚠ Gelekt wachtwoord gekozen',
        'link_sent' => 'E-maillink verzonden',
    ];

    public function __construct() {
        parent::__construct(['singular' => 'loginpoging', 'plural' => 'loginpogingen', 'ajax' => false]);
    }

    public function get_columns(): array {
        return [
            'id' => 'ID',
            'attempted_at' => 'Tijdstip',
            'email' => 'E-mailadres',
            'method' => 'Methode',
            'result' => 'Resultaat',
            'ip' => 'IP-adres',
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
        echo 'Geen loginpogingen gevonden.';
    }

    public function prepare_items(): void {
        $columns = $this->get_columns();
        $hidden = get_hidden_columns(get_current_screen());
        $this->_column_headers = [$columns, $hidden, $this->get_sortable_columns(), $this->get_default_primary_column_name()];

        $per_page = $this->get_items_per_page('avpvh_login_attempts_per_page', 50);
        $query = AVPVH_DB::query_login_attempts([
            'search' => sanitize_text_field(wp_unslash($_GET['s'] ?? '')),
            'method' => sanitize_key(wp_unslash($_GET['method'] ?? '')),
            'result' => sanitize_key(wp_unslash($_GET['result'] ?? '')),
            'date_from' => $this->sanitize_date($_GET['date_from'] ?? ''),
            'date_to' => $this->sanitize_date($_GET['date_to'] ?? ''),
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
            'method' => esc_html(self::METHOD_LABELS[$item->method] ?? $item->method),
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
        $selected_method = sanitize_key(wp_unslash($_GET['method'] ?? ''));
        $selected_result = sanitize_key(wp_unslash($_GET['result'] ?? ''));
        ?>
        <div class="alignleft actions">
            <label class="screen-reader-text" for="filter-method">Filter op methode</label>
            <select name="method" id="filter-method">
                <option value="">Alle methoden</option>
                <?php foreach ($filters['methods'] as $method) : ?>
                    <option value="<?php echo esc_attr($method); ?>" <?php selected($selected_method, $method); ?>><?php echo esc_html(self::METHOD_LABELS[$method] ?? $method); ?></option>
                <?php endforeach; ?>
            </select>
            <label class="screen-reader-text" for="filter-result">Filter op resultaat</label>
            <select name="result" id="filter-result">
                <option value="">Alle resultaten</option>
                <?php foreach ($filters['results'] as $result) : ?>
                    <option value="<?php echo esc_attr($result); ?>" <?php selected($selected_result, $result); ?>><?php echo esc_html(self::RESULT_LABELS[$result] ?? $result); ?></option>
                <?php endforeach; ?>
            </select>
            <label for="filter-date-from">Van</label>
            <input type="date" name="date_from" id="filter-date-from" value="<?php echo esc_attr($this->sanitize_date($_GET['date_from'] ?? '')); ?>">
            <label for="filter-date-to">tot</label>
            <input type="date" name="date_to" id="filter-date-to" value="<?php echo esc_attr($this->sanitize_date($_GET['date_to'] ?? '')); ?>">
            <?php submit_button('Filteren', '', 'filter_action', false); ?>
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
            . esc_html(self::RESULT_LABELS[$result] ?? $result) . '</span>';
    }

    private function sanitize_date($value): string {
        $value = sanitize_text_field(wp_unslash((string) $value));
        return preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $value) ? $value : '';
    }
}
