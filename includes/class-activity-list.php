<?php
defined('ABSPATH') || exit;

/**
 * The website's lists of activities, made from the activity registration
 * (avm_activities) instead of hand-kept tables: only activities marked
 * "Tonen op de website" (show_on_site), of the given types.
 *
 *   [avpvh_activiteiten soort="Weekend" kolommen="datum,waar,wat" koppen="Datum,Waar,Activiteit"]
 *   [avpvh_activiteiten soort="Uitje,Wandeling,Feest,Anders" kolommen="datum,wat,waar,details" koppen="wanneer,wat,waar,details"]
 *   [avpvh_activiteiten wanneer="komend"]
 *   [avpvh_activiteiten tot="2006-07-01"]   (the werkgroep's activities, for the Voorgeschiedenis page)
 *
 * wanneer="geweest" (default) shows a table, newest first, of activities
 * that have started; wanneer="komend" shows the agenda: one heading per
 * activity that hasn't ended yet, soonest first. Columns: datum (start date,
 * or just the year), wat (description, else the name), waar (location, else
 * the kenmerk), details. vanaf/tot (YYYY-MM-DD) limit the list to activities
 * starting on or after / before that date.
 */
class AVPVH_Activity_List {
    private const COLUMNS = [
        'datum'   => 'Datum',
        'wat'     => 'Wat',
        'waar'    => 'Waar',
        'details' => 'Details',
    ];

    public function __construct() {
        add_shortcode('avpvh_activiteiten', [$this, 'render']);
    }

    /** @param array<string, string>|string $atts */
    public function render($atts): string {
        $atts = shortcode_atts([
            'soort'    => '',
            'wanneer'  => 'geweest',
            'kolommen' => 'datum,wat,waar,details',
            'koppen'   => '',
            'vanaf'    => '',
            'tot'      => '',
        ], $atts, 'avpvh_activiteiten');

        $upcoming   = 'komend' === $atts['wanneer'];
        $activities = self::activities(self::list_attribute($atts['soort']), $upcoming, self::date_attribute($atts['vanaf']), self::date_attribute($atts['tot']));

        if (!$activities) {
            return $upcoming ? '<p>Er staan nog geen activiteiten op de agenda.</p>' : '';
        }

        return $upcoming
            ? self::agenda($activities)
            : self::table($activities, self::list_attribute($atts['kolommen']), self::list_attribute($atts['koppen']));
    }

    /** A YYYY-MM-DD attribute, or '' if it isn't one. */
    private static function date_attribute(string $value): string {
        return 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) ? trim($value) : '';
    }

    /** @return array<string> */
    private static function list_attribute(string $value): array {
        return array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen'));
    }

    /**
     * Activities shown on the site, of the given type names (all when
     * empty): those that have started (newest first) or those that haven't
     * ended (soonest first), optionally only those starting from / before a
     * date.
     *
     * @param array<string> $types
     * @return array<object>
     */
    private static function activities(array $types, bool $upcoming, string $from = '', string $until = ''): array {
        global $wpdb;
        $today = current_time('Y-m-d');
        $where = ['a.show_on_site = 1'];
        $args  = [];

        if ($types) {
            $where[] = 't.name IN (' . implode(', ', array_fill(0, count($types), '%s')) . ')';
            $args    = $types;
        }

        // Without a start date, an activity counts as on 1 January of its year.
        $where[] = $upcoming
            ? 'COALESCE(a.end_date, a.start_date, MAKEDATE(a.year, 1)) >= %s'
            : 'COALESCE(a.start_date, MAKEDATE(a.year, 1)) <= %s';
        $args[]  = $today;

        if ('' !== $from) {
            $where[] = 'COALESCE(a.start_date, MAKEDATE(a.year, 1)) >= %s';
            $args[]  = $from;
        }

        if ('' !== $until) {
            $where[] = 'COALESCE(a.start_date, MAKEDATE(a.year, 1)) < %s';
            $args[]  = $until;
        }

        $order = $upcoming ? 'ASC' : 'DESC';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, t.name AS type_name
             FROM {$wpdb->prefix}avm_activities a
             LEFT JOIN {$wpdb->prefix}avm_activity_types t ON t.id = a.type_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY COALESCE(a.start_date, MAKEDATE(a.year, 1)) {$order}, a.id {$order}",
            $args
        )) ?: [];
    }

    /**
     * @param array<object> $activities
     * @param array<string> $columns
     * @param array<string> $headings
     */
    private static function table(array $activities, array $columns, array $headings): string {
        $columns = array_values(array_intersect($columns, array_keys(self::COLUMNS))) ?: array_keys(self::COLUMNS);
        $html    = '<figure class="wp-block-table avpvh-activity-list"><table><thead><tr>';

        foreach ($columns as $index => $column) {
            $html .= '<th>' . esc_html($headings[$index] ?? self::COLUMNS[$column]) . '</th>';
        }

        $html .= '</tr></thead><tbody>';

        foreach ($activities as $activity) {
            $html .= '<tr>';
            foreach ($columns as $column) {
                $html .= '<td>' . self::cell($activity, $column) . '</td>';
            }
            $html .= '</tr>';
        }

        return $html . '</tbody></table></figure>';
    }

    /** One table cell's HTML. */
    private static function cell(object $activity, string $column): string {
        switch ($column) {
            case 'datum':
                return esc_html($activity->start_date ?: (string) $activity->year);
            case 'wat':
                return self::rich($activity->description ?? '', $activity->name);
            case 'waar':
                return self::rich($activity->location ?? '', $activity->kenmerk);
            default:
                return self::rich($activity->details ?? '', '');
        }
    }

    /** Rich text as entered (links, bold, line breaks), else the plain fallback. */
    private static function rich(string $html, string $fallback): string {
        return '' !== trim($html) ? nl2br(wp_kses_post($html), false) : esc_html($fallback);
    }

    /**
     * The agenda: per activity its period and what it is, e.g.
     * "24 juli – 8 augustus 2026: Goeblange", with where and details below.
     *
     * @param array<object> $activities
     */
    private static function agenda(array $activities): string {
        $html = '<div class="avpvh-activity-agenda">';

        foreach ($activities as $activity) {
            $period = self::period($activity);
            $html  .= '<h3 class="wp-block-heading">' . ('' === $period ? '' : esc_html($period) . ': ')
                . self::rich($activity->description ?? '', $activity->name) . '</h3>';

            $more = array_filter([
                self::rich($activity->location ?? '', $activity->kenmerk),
                self::rich($activity->details ?? '', ''),
            ], 'strlen');

            if ($more) {
                $html .= '<p>' . implode('<br>', $more) . '</p>';
            }
        }

        return $html . '</div>';
    }

    /** "10 oktober 2026", "24 juli – 8 augustus 2026", "2027", or ''. */
    private static function period(object $activity): string {
        if (!$activity->start_date) {
            return (string) $activity->year;
        }

        $start = strtotime($activity->start_date . ' 12:00');
        $end   = $activity->end_date ? strtotime($activity->end_date . ' 12:00') : $start;

        if ($end <= $start) {
            return date_i18n('j F Y', $start);
        }

        $same_year = date('Y', $start) === date('Y', $end);

        return date_i18n($same_year ? 'j F' : 'j F Y', $start) . ' – ' . date_i18n('j F Y', $end);
    }
}
