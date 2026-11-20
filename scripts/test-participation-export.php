<?php
declare(strict_types=1);

/** Check attendance dates, statuses and formatting in the generated workbook. */
require_once dirname(__DIR__) . '/tests/fixtures/xlsx-export.php';

class AVPVH_DB {
    public static function get_participation_for_activity(int $id): array {
        $GLOBALS['activity_queries'][] = $id;
        return $GLOBALS['participations'];
    }
    public static function get_participation_days(int $id): array {
        $GLOBALS['day_queries'][] = $id;
        return $GLOBALS['days'][$id];
    }
}
require_once AVPVH_PLUGIN_DIR . 'includes/class-activity-participation-export.php';

$activity = (object) ['id' => 73, 'name' => 'Fictief testkamp', 'year' => 2026, 'start_date' => '2026-06-12', 'end_date' => '2026-06-15'];
$GLOBALS['participations'] = [
    (object) ['id' => 91, 'first_name' => 'Fictief', 'suffix' => 'van', 'last_name' => 'Voorbeeldlid', 'nights' => 3, 'nawacht' => 1, 'diet' => 'Testdieet', 'notes' => '=1+1 & <test>'],
    (object) ['id' => 92, 'first_name' => 'Testlid', 'suffix' => '', 'last_name' => 'Fictief', 'nights' => null, 'nawacht' => 0, 'diet' => null, 'notes' => null],
];
$GLOBALS['days'] = [91 => ['2026-06-12' => 'n', '2026-06-13' => 'on', '2026-06-14' => '?', '2026-06-15' => 'x'], 92 => []];
$GLOBALS['activity_queries'] = $GLOBALS['day_queries'] = [];
$parts = read_export(AVPVH_Activity_Participation_Export::build($activity));
$rows = $parts['sheet']->sheetData->row;
export_check('export loads the requested activity and each participation once', $GLOBALS['activity_queries'] === [73] && $GLOBALS['day_queries'] === [91, 92]);
export_check('workbook contains its title, fixed update date and spacer', export_values($rows[0]) === ['Fictief testkamp 2026', 'Laatst bijgewerkt: 15-06-2026'] && count($rows[1]->c) === 0);
export_check('date grid includes both endpoints across a weekend', export_values($rows[2]) === ['Naam', 'Nachten', 'Nawacht', 'Dieet', 'Fri 12-6', 'Sat 13-6', 'Sun 14-6', 'Mon 15-6', 'Notities']);
export_check('participant data and each attendance code survive the workbook round trip', export_values($rows[3]) === ['Fictief van Voorbeeldlid', '3', 'ja', 'Testdieet', 'n', 'on', '?', 'x', '=1+1 & <test>']);
export_check('missing optional values and days export as blank cells', export_values($rows[4]) === ['Testlid Fictief', '', '', '', '', '', '', '', '']);
export_check('notes that look like formulas remain literal text', (string) $rows[3]->c[8]['t'] === 'inlineStr' && !isset($rows[3]->c[8]->f));
foreach ([4 => 'FFD9D9D9', 5 => 'FFF2F2F2', 6 => 'FFF2F2F2', 7 => 'FFD9D9D9'] as $column => $color) {
    export_check('weekday and weekend header styles for column ' . $column, export_style($parts, $rows[2]->c[$column]) === ['color' => $color, 'bold' => true]);
}
foreach ([4 => 'FFC6EFCE', 5 => 'FFFFEB9C', 6 => 'FFE0E0E0', 7 => ''] as $column => $color) {
    export_check('attendance code has the expected fill for column ' . $column, export_style($parts, $rows[3]->c[$column]) === ['color' => $color, 'bold' => false]);
}
export_check('worksheet is named Deelname', (string) $parts['workbook']->sheets->sheet['name'] === 'Deelname');
$widths = [];
foreach ($parts['sheet']->cols->col as $column) { $widths[] = (int) $column['width']; }
export_check('column widths match the inclusive attendance grid', $widths === [22, 9, 9, 14, 6, 6, 6, 6, 30]);

foreach ([[null, null], ['2026-06-12', null], [null, '2026-06-15'], ['2026-06-15', '2026-06-12']] as [$start, $end]) {
    $activity->start_date = $start;
    $activity->end_date = $end;
    $parts = read_export(AVPVH_Activity_Participation_Export::build($activity));
    export_check('missing or reversed dates omit the attendance grid', export_values($parts['sheet']->sheetData->row[2]) === ['Naam', 'Nachten', 'Nawacht', 'Dieet', 'Notities'] && count($parts['sheet']->sheetData->row[3]->c) === 5);
}
$activity->start_date = $activity->end_date = '2026-06-12';
$GLOBALS['participations'] = [];
$parts = read_export(AVPVH_Activity_Participation_Export::build($activity));
export_check('an empty single-day activity retains its date heading', count($parts['sheet']->sheetData->row) === 3 && export_values($parts['sheet']->sheetData->row[2]) === ['Naam', 'Nachten', 'Nawacht', 'Dieet', 'Fri 12-6', 'Notities']);

echo "Participation export tests: OK\n";
