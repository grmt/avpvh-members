<?php
defined('ABSPATH') || exit;

require_once AVPVH_PLUGIN_DIR . 'includes/class-xlsx-writer.php';

/**
 * Builds the ledenlijst .xlsx export. Mirrors exactly what the on-screen
 * table shows (same share_* consent gating, same columns) — never more,
 * so exporting can't leak data the viewer wasn't already shown.
 */
class AVPVH_Ledenlijst_Export {

    private const COLOR_HEADER = 'D9D9D9';

    public static function build(array $leden, array $group_map): string {
        $rows = [];

        $header = ['Naam', 'E-mail', 'Mobiel', 'Telefoon', 'Straat', 'Huisnummer', 'Postcode', 'Plaats', 'Land', 'Groepen'];
        $rows[] = array_map(fn($h) => ['v' => $h, 'bold' => true, 'color' => self::COLOR_HEADER], $header);

        foreach ($leden as $lid) {
            $groups = $group_map[$lid->lldap_user_id] ?? [];
            // A placeholder LLDAP address (no real e-mail on file) isn't a
            // real contact address — never export it, even with consent on.
            $show_email = $lid->share_email && !str_ends_with(strtolower($lid->email ?? ''), '@avpvh.local');
            $rows[] = [
                ['v' => avpvh_format_name($lid)],
                ['v' => $show_email ? $lid->email : ''],
                ['v' => $lid->share_phone ? $lid->mobile : ''],
                ['v' => $lid->share_phone ? $lid->phone : ''],
                ['v' => $lid->share_address ? $lid->street : ''],
                ['v' => $lid->share_address ? $lid->house_number : ''],
                ['v' => $lid->share_address ? $lid->postal_code : ''],
                ['v' => $lid->share_address ? $lid->city : ''],
                ['v' => $lid->share_address ? $lid->country : ''],
                ['v' => implode(', ', array_map('ucfirst', $groups))],
            ];
        }

        $col_widths = [24, 28, 16, 16, 22, 10, 10, 16, 14, 20];
        return AVPVH_Xlsx_Writer::build($rows, $col_widths);
    }
}
