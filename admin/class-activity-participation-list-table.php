<?php
defined('ABSPATH') || exit;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class AVPVH_Activity_Participation_List_Table extends WP_List_Table {

    private int $activity_id;
    private bool $show_all_active_members;

    /**
     * $show_all_active_members: for an activity like "Contributie {year}"
     * nobody ever explicitly "signs up" — it applies automatically to
     * every active member (see AVBK_Fee_Generation::generate_contribution_fees())
     * — so avm_activity_participation is always empty for it. Without this,
     * selecting such an activity here would misleadingly say "no
     * participation found" instead of showing who it actually applies to.
     */
    public function __construct(int $activity_id, bool $show_all_active_members = false) {
        parent::__construct(['singular' => 'deelname', 'plural' => 'deelnames', 'ajax' => false]);
        $this->activity_id = $activity_id;
        $this->show_all_active_members = $show_all_active_members;
    }

    public function get_columns(): array {
        return [
            'name'       => 'Naam',
            'nights'     => 'Nachten',
            'nawacht'    => 'Nawacht',
            'diet'       => 'Dieet',
            'days'       => 'Dagen aanwezig',
            'notes'      => 'Notities',
            'actions'    => '',
        ];
    }

    protected function get_default_primary_column_name(): string {
        return 'name';
    }

    public function no_items(): void {
        echo $this->show_all_active_members ? 'Geen actieve leden gevonden.' : 'Geen deelname gevonden voor deze activiteit.';
    }

    public function prepare_items(): void {
        $this->_column_headers = [$this->get_columns(), [], []];
        if ($this->show_all_active_members) {
            // id = 0 marks these as synthetic (no real avm_activity_participation
            // row) — column_name()/column_days()/column_actions() branch on
            // that instead of trying to link to a participation record that
            // doesn't exist.
            $this->items = array_map(fn($m) => (object) [
                'id'            => 0,
                'member_id'     => $m->id,
                'nights'        => null,
                'nawacht'       => 0,
                'diet'          => '',
                'notes'         => '',
                'first_name'    => $m->first_name,
                'suffix'        => $m->suffix,
                'last_name'     => $m->last_name,
                'member_status' => $m->status,
            ], AVPVH_DB::get_members(['status' => 'active']));
        } else {
            $this->items = $this->activity_id ? AVPVH_DB::get_participation_for_activity($this->activity_id) : [];
        }
    }

    public function column_default($item, $column_name) {
        return match ($column_name) {
            'nights'  => $item->nights !== null ? esc_html((string) $item->nights) : '—',
            'nawacht' => $item->nawacht ? 'Ja' : '—',
            'diet'    => esc_html($item->diet ?: '—'),
            'notes'   => esc_html($item->notes ?: ''),
            default   => '',
        };
    }

    public function column_name($item): string {
        $name = trim($item->first_name . ' ' . ($item->suffix ? $item->suffix . ' ' : '') . $item->last_name);
        $muted = $item->member_status !== 'active' ? ' style="color:#a00"' : '';
        // No participation record to open (synthetic "applies to every
        // active member" row) — link to the member's own record instead.
        $url = $item->id
            ? add_query_arg(['page' => 'avpvh-activity-participation-detail', 'activity_id' => $this->activity_id, 'id' => $item->id], admin_url('admin.php'))
            : add_query_arg(['page' => 'avpvh-member-detail', 'id' => $item->member_id], admin_url('admin.php'));
        return '<a href="' . esc_url($url) . '"' . $muted . '><strong>' . esc_html($name) . '</strong></a>';
    }

    public function column_days($item): string {
        if (!$item->id) {
            return '—';
        }
        $days = AVPVH_DB::get_participation_days((int) $item->id);
        $present = array_filter($days, fn($status) => $status !== '');
        return esc_html((string) count($present));
    }

    public function column_actions($item): string {
        if (!$item->id) {
            return '';
        }
        $url = add_query_arg([
            'page' => 'avpvh-activity-participation-detail',
            'activity_id' => $this->activity_id,
            'id' => $item->id,
        ], admin_url('admin.php'));
        return '<a href="' . esc_url($url) . '" class="button button-small">Bewerken</a>';
    }
}
