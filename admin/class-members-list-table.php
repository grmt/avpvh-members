<?php
defined('ABSPATH') || exit;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Members list as a native WP_List_Table, so column show/hide via Screen
 * Options is remembered per user automatically (WordPress's own mechanism —
 * no custom code needed for that part).
 */
class AVPVH_Members_List_Table extends WP_List_Table {

    private int $current_year;

    public function __construct() {
        parent::__construct([
            'singular' => 'member',
            'plural'   => 'members',
            'ajax'     => false,
        ]);
        $this->current_year = (int) date('Y');
    }

    public function get_columns(): array {
        return [
            'name'        => 'Naam',
            'first_name'  => 'Voornaam',
            'suffix'      => 'Tussenvoegsel',
            'last_name'   => 'Achternaam',
            'passport_name' => 'Paspoortnaam',
            'email'       => 'E-mailadressen',
            'status'      => 'Status',
            'joined_year' => 'Lid sinds',
            'fee_status'  => 'Contributie ' . $this->current_year,
            'activity_count' => 'Activiteiten',
            'flags'       => 'Kenmerken',
            'actions'     => '',
        ];
    }

    public function get_sortable_columns(): array {
        return [
            'last_name'   => ['suffix_last_name', false, 'Achternaam'],
            'first_name'  => ['first_name', false],
            'status'      => ['status', false],
            'joined_year' => ['joined_year', false],
            'fee_status'  => ['fee_status', false],
            'activity_count' => ['activity_count', false],
        ];
    }

    protected function get_default_primary_column_name(): string {
        return 'name';
    }

    public function no_items(): void {
        echo 'Geen leden gevonden.';
    }

    public function prepare_items(): void {
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns(), $this->get_default_primary_column_name()];

        $args = [
            'search'      => sanitize_text_field($_GET['s'] ?? ''),
            'first_name'  => sanitize_text_field($_GET['f_first_name'] ?? ''),
            'suffix'      => sanitize_text_field($_GET['f_suffix'] ?? ''),
            'last_name'   => sanitize_text_field($_GET['f_last_name'] ?? ''),
            'status'      => array_map('sanitize_key', (array) ($_GET['status'] ?? [])),
            'joined_year' => sanitize_text_field($_GET['joined_year'] ?? ''),
            'fee_status'  => array_map('sanitize_key', (array) ($_GET['fee_status'] ?? [])),
            'flag_id'     => array_map('intval', (array) ($_GET['flag_id'] ?? [])),
            'orderby'     => sanitize_key($_GET['orderby'] ?? 'last_name'),
            'order'       => sanitize_key($_GET['order'] ?? 'asc'),
        ];

        $this->items = AVPVH_DB::get_members($args);
    }

    public function column_default($item, $column_name) {
        return match ($column_name) {
            'first_name'   => esc_html($item->first_name),
            'suffix'       => esc_html($item->suffix ?: '—'),
            'last_name'    => esc_html($item->last_name),
            'passport_name' => esc_html($item->passport_name ?: '—'),
            'status'       => esc_html($item->status),
            'joined_year'  => esc_html($item->joined_year ?: '—'),
            default        => '',
        };
    }

    public function column_name($item): string {
        $detail_url = add_query_arg(['page' => 'avpvh-member-detail', 'id' => $item->id], admin_url('admin.php'));
        return sprintf(
            '<a href="%s"><strong>%s</strong></a>',
            esc_url($detail_url),
            esc_html(avpvh_format_name($item, 'list_suffix'))
        );
    }

    public function column_email($item): string {
        $identities = AVPVH_DB::get_member_identities((int) $item->id);
        if (!$identities) {
            $is_placeholder = str_ends_with(strtolower($item->email ?? ''), '@avpvh.local');
            $style = $is_placeholder ? ' style="color:#d63638"' : '';
            return '<div' . $style . '>' . esc_html($item->email) . '</div>';
        }
        $rows = [];
        foreach ($identities as $identity) {
            $is_placeholder = str_ends_with(strtolower($identity->email), '@avpvh.local');
            $style = $is_placeholder ? ' style="color:#d63638"' : '';
            $unverified = empty($identity->verified_at)
                ? ' <span style="color:#b32d2e;font-size:.85em;font-weight:600" title="Toegevoegd door een beheerder, niet zelf geverifieerd">(niet geverifieerd)</span>'
                : '';
            $rows[] = '<div' . $style . '>' . esc_html($identity->email) . $unverified . '</div>';
        }
        return implode('', $rows);
    }

    public function column_fee_status($item): string {
        $fee = AVPVH_DB::get_fee_for_year((int) $item->id, $this->current_year);
        return $fee ? esc_html($fee->status) : '—';
    }

    public function column_activity_count($item): string {
        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}avm_activity_participation WHERE member_id = %d",
            $item->id
        ));
        return esc_html((string) $count);
    }

    public function column_flags($item): string {
        $flags = AVPVH_DB::get_flags_for_member((int) $item->id);
        if (!$flags) {
            return '';
        }
        return esc_html(implode(', ', wp_list_pluck($flags, 'label')));
    }

    public function column_actions($item): string {
        $detail_url = add_query_arg(['page' => 'avpvh-member-detail', 'id' => $item->id], admin_url('admin.php'));
        return '<a href="' . esc_url($detail_url) . '" class="button button-small">Details</a>';
    }

    public function single_row($item): void {
        printf('<tr class="avpvh-member-row-%s">', esc_attr($item->status));
        $this->single_row_columns($item);
        echo '</tr>';
    }
}
