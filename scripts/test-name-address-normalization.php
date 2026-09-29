<?php
/** Standalone tests for the pure name/address normalization layer. */

define('ABSPATH', __DIR__ . '/');
require_once dirname(__DIR__) . '/includes/class-normalization.php';

function avpvh_test_equal(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "$label failed\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$accented = AVPVH_Normalization::normalize_person_name('José', '', 'Voorbeeld');
$plain = AVPVH_Normalization::normalize_person_name('Jose', '', 'Voorbeeld');
avpvh_test_equal($accented['normalized_key'], $plain['normalized_key'], 'accent-insensitive name');
avpvh_test_equal('José', $accented['first_name'], 'official accent preserved');

$separate = AVPVH_Normalization::normalize_person_name('Test', 'van der', 'Voorbeeld');
$combined = AVPVH_Normalization::normalize_person_name('Test', '', 'van der Voorbeeld');
$legacy = AVPVH_Normalization::normalize_person_name('Test', '', 'Voorbeeld, van der');
avpvh_test_equal($separate['normalized_key'], $combined['normalized_key'], 'combined suffix');
avpvh_test_equal($separate['normalized_key'], $legacy['normalized_key'], 'legacy comma suffix');

$slash_suffix = AVPVH_Normalization::normalize_person_name('Test', '', 'v/d Voorbeeld');
$compact_suffix = AVPVH_Normalization::normalize_person_name('Test', '', 'vd Voorbeeld');
avpvh_test_equal($separate['normalized_key'], $slash_suffix['normalized_key'], 'v/d suffix');
avpvh_test_equal($separate['normalized_key'], $compact_suffix['normalized_key'], 'vd suffix');

$punctuated = AVPVH_Normalization::normalize_person_name('Testvoornaam-Een', '', 'Test.Achternaam');
$spaced = AVPVH_Normalization::normalize_person_name('Testvoornaam Een', '', 'Test Achternaam');
avpvh_test_equal($punctuated['normalized_key'], $spaced['normalized_key'], 'punctuation normalization');

$spelling_a = AVPVH_Normalization::normalize_person_name('Variantvoornaam', 'de', 'Testspelling');
$spelling_b = AVPVH_Normalization::normalize_person_name('Variantvoornaam', 'de', 'Testspellink');
if ($spelling_a['normalized_key'] === $spelling_b['normalized_key']) {
    fwrite(STDERR, "Explicit spelling variants must not be inferred\n");
    exit(1);
}

$ambiguous = AVPVH_Normalization::classify_member_ids([12, 34, 12]);
avpvh_test_equal('ambiguous', $ambiguous['status'], 'ambiguous alias match');
avpvh_test_equal([12, 34], $ambiguous['member_ids'], 'deduplicated match IDs');

$city_aliases = [
    AVPVH_Normalization::fold('Nederland') . '|' . AVPVH_Normalization::fold('Den Bosch')
        => "'s-Hertogenbosch",
];
$street_scope = implode('|', [
    AVPVH_Normalization::fold('Nederland'),
    AVPVH_Normalization::fold('1234 AB'),
    AVPVH_Normalization::fold("'s-Hertogenbosch"),
    AVPVH_Normalization::fold('F. van Voorbeeldweg'),
]);
$street_aliases = [$street_scope => 'Frederika van Voorbeeldweg'];

$short = AVPVH_Normalization::normalize_address([
    'street' => 'F. van Voorbeeldweg',
    'house_number' => '12a',
    'postal_code' => '1234ab',
    'city' => 'Den Bosch',
    'country' => 'Nederland',
], $city_aliases, $street_aliases);
$canonical = AVPVH_Normalization::normalize_address([
    'street' => 'Frederika van Voorbeeldweg',
    'house_number' => '12a',
    'postal_code' => '1234 AB',
    'city' => "'s-Hertogenbosch",
    'country' => 'Nederland',
], $city_aliases, $street_aliases);
avpvh_test_equal($short['normalized_key'], $canonical['normalized_key'], 'scoped address aliases');
avpvh_test_equal('1234 AB', $short['postal_code'], 'postal-code display normalization');

echo "Name/address normalization tests: OK\n";
