<?php
defined('ABSPATH') || exit;

require_once AVPVH_PLUGIN_DIR . 'includes/class-xlsx-writer.php';

/**
 * Builds the "totaal inschrijvingen"-style .xlsx export from the live
 * avm_camp_participation(_days) data, in the same visual style as the
 * original hand-maintained sheet (bold header, colored attendance cells) —
 * regenerated fresh from the database rather than edited by hand, so the
 * "last updated" stamp is always today's date.
 */
class AVPVH_Kampdeelname_Export {

    private const COLOR_HEADER  = 'D9D9D9';
    private const COLOR_WEEKEND = 'F2F2F2';
    private const COLOR_PRESENT = 'C6EFCE'; // 'n'
    private const COLOR_NAWACHT = 'FFEB9C'; // 'on'
    private const COLOR_MAYBE   = 'E0E0E0'; // '?'

    public static function build(object $camp): string {
        $participations = AVPVH_DB::get_participation_for_camp((int) $camp->id);

        $date_range = [];
        if ($camp->start_date && $camp->end_date) {
            $cursor = new DateTime($camp->start_date);
            $end = new DateTime($camp->end_date);
            while ($cursor <= $end) {
                $date_range[] = $cursor->format('Y-m-d');
                $cursor->modify('+1 day');
            }
        }

        $rows = [];

        $rows[] = [
            ['v' => $camp->name . ' ' . $camp->year, 'bold' => true],
            ['v' => 'Laatst bijgewerkt: ' . date_i18n('j-m-Y')],
        ];
        $rows[] = [];

        $header = [
            ['v' => 'Naam', 'bold' => true, 'color' => self::COLOR_HEADER],
            ['v' => 'Nachten', 'bold' => true, 'color' => self::COLOR_HEADER],
            ['v' => 'Nawacht', 'bold' => true, 'color' => self::COLOR_HEADER],
            ['v' => 'Dieet', 'bold' => true, 'color' => self::COLOR_HEADER],
        ];
        foreach ($date_range as $date) {
            $is_weekend = in_array((int) date('N', strtotime($date)), [6, 7], true);
            $header[] = [
                'v' => date_i18n('D j-n', strtotime($date)),
                'bold' => true,
                'color' => $is_weekend ? self::COLOR_WEEKEND : self::COLOR_HEADER,
            ];
        }
        $header[] = ['v' => 'Notities', 'bold' => true, 'color' => self::COLOR_HEADER];
        $rows[] = $header;

        foreach ($participations as $p) {
            $name = trim($p->first_name . ' ' . ($p->suffix ? $p->suffix . ' ' : '') . $p->last_name);
            $days = AVPVH_DB::get_participation_days((int) $p->id);

            $row = [
                ['v' => $name],
                ['v' => $p->nights ?? ''],
                ['v' => $p->nawacht ? 'ja' : ''],
                ['v' => $p->diet ?? ''],
            ];
            foreach ($date_range as $date) {
                $status = $days[$date] ?? '';
                $color = match ($status) {
                    'n'     => self::COLOR_PRESENT,
                    'on'    => self::COLOR_NAWACHT,
                    '?'     => self::COLOR_MAYBE,
                    default => null,
                };
                $cell = ['v' => $status];
                if ($color) {
                    $cell['color'] = $color;
                }
                $row[] = $cell;
            }
            $row[] = ['v' => $p->notes ?? ''];
            $rows[] = $row;
        }

        $col_widths = array_merge([22, 9, 9, 14], array_fill(0, count($date_range), 6), [30]);
        return AVPVH_Xlsx_Writer::build($rows, $col_widths);
    }
}
