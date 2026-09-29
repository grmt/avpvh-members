<?php
/** Read-only report of members with more than one current address. */

defined('ABSPATH') || exit;

$as_of = getenv('AVPVH_ADDRESS_AS_OF') ?: current_time('Y-m-d');
$overlaps = AVPVH_DB::get_current_address_overlaps($as_of);

echo "Current-address overlap report as of {$as_of}\n";
echo count($overlaps) . " member(s) with overlapping current rows.\n";
foreach ($overlaps as $overlap) {
    $current = array_values(array_filter(
        AVPVH_DB::get_addresses((int) $overlap->member_id),
        static fn(object $address): bool =>
            (!$address->valid_from || $address->valid_from <= $as_of)
            && (!$address->valid_until || $address->valid_until >= $as_of)
    ));
    $normalized_keys = array_unique(array_map(
        static fn(object $address): string =>
            AVPVH_DB::normalize_address((array) $address)['normalized_key'],
        $current
    ));
    $classification = count($normalized_keys) === 1
        ? 'equivalent_duplicates'
        : 'conflicting_addresses';
    echo "member_id={$overlap->member_id}; current_rows={$overlap->current_count}; "
        . "distinct_normalized=" . count($normalized_keys)
        . "; classification={$classification}\n";
}
