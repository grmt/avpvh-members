<?php
declare(strict_types=1);

/** Check privacy gating in actual .xlsx output using entirely fictional records. */
require_once dirname(__DIR__) . '/tests/fixtures/xlsx-export.php';
require_once AVPVH_PLUGIN_DIR . 'includes/class-db.php';
require_once AVPVH_PLUGIN_DIR . 'includes/class-avpvh-address-normalizer.php';
require_once AVPVH_PLUGIN_DIR . 'includes/class-ledenlijst-export.php';

$member = (object) [
    'lldap_user_id' => 'export-fixture',
    'first_name' => 'Fictief', 'suffix' => 'van', 'last_name' => 'Voorbeeldlid',
    'email' => 'fixture@example.invalid', 'mobile' => '+31 6 0000 0001', 'phone' => '+31 20 000 0001',
    'street' => 'Fictieve Teststraat', 'house_number' => '12', 'house_number_addition' => 'A',
    'postal_code' => '0000 XX', 'city' => 'Voorbeeldplaats', 'country' => 'Nederland',
];
$group_map = ['export-fixture' => ['testgroep', 'voorbeeldgroep'], 'unrelated-fixture' => ['verborgen']];
for ($flags = 0; $flags < 8; $flags++) {
    $member->share_email = $flags & 1;
    $member->share_phone = $flags & 2;
    $member->share_address = $flags & 4;
    $parts = read_export(AVPVH_Ledenlijst_Export::build([$member], $group_map));
    $rows = $parts['sheet']->sheetData->row;
    export_check('directory export has the expected columns and one member', count($rows) === 2 && export_values($rows[0]) === [
        'Naam', 'E-mail', 'Mobiel', 'Telefoon', 'Straat', 'Huisnummer', 'Postcode', 'Plaats', 'Land', 'Groepen',
    ]);
    export_check('sharing flags ' . $flags . ' govern every contact field in the workbook', export_values($rows[1]) === [
        'Fictief van Voorbeeldlid',
        $flags & 1 ? 'fixture@example.invalid' : '',
        $flags & 2 ? '+31 6 0000 0001' : '',
        $flags & 2 ? '+31 20 000 0001' : '',
        $flags & 4 ? 'Fictieve Teststraat' : '',
        $flags & 4 ? '12A' : '',
        $flags & 4 ? '0000 XX' : '',
        $flags & 4 ? 'Voorbeeldplaats' : '',
        $flags & 4 ? 'Nederland' : '',
        'Testgroep, Voorbeeldgroep',
    ]);
    foreach ($rows[0]->c as $cell) {
        export_check('column heading is bold with a grey background', export_style($parts, $cell) === ['color' => 'FFD9D9D9', 'bold' => true]);
    }
    export_check('all directory columns have widths', count($parts['sheet']->cols->col) === 10);
}

$member->share_email = 1;
foreach (['placeholder@avpvh.local', 'PLACEHOLDER@AVPVH.LOCAL', null] as $email) {
    $member->email = $email;
    $parts = read_export(AVPVH_Ledenlijst_Export::build([$member], []));
    $values = export_values($parts['sheet']->sheetData->row[1]);
    export_check('placeholder and missing email addresses are never exported', $values[1] === '');
    export_check('a member without groups has a blank group cell', $values[9] === '');
}
$parts = read_export(AVPVH_Ledenlijst_Export::build([], []));
export_check('empty directory still produces a workbook with column headings', count($parts['sheet']->sheetData->row) === 1);

echo "Ledenlijst export tests: OK\n";
