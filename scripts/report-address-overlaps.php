<?php
/**
 * Read-only report of members with more than one current address — the
 * same condition the Adreshistorie warning in Ledendetail shows per member.
 * Prints member IDs and counts only, never address contents.
 *
 * Run inside WordPress with:
 *   wp eval-file scripts/report-address-overlaps.php
 *   AVPVH_ADDRESS_AS_OF=2026-01-01 wp eval-file scripts/report-address-overlaps.php
 *
 * equivalent_duplicates: all current rows are the same address according to
 * AVPVH_Address_Normalizer::match_key() (spelling, spacing and house-number notation only),
 * so the extra rows can be closed or removed. conflicting_addresses: the
 * current rows are really different addresses and need a manual decision.
 */

defined('ABSPATH') || exit;

global $wpdb;

$as_of = getenv('AVPVH_ADDRESS_AS_OF') ?: current_time('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $as_of)) {
    fwrite(STDERR, "AVPVH_ADDRESS_AS_OF moet JJJJ-MM-DD zijn.\n");
    exit(2);
}

$overlaps = $wpdb->get_results($wpdb->prepare(
    "SELECT member_id, COUNT(*) AS current_count
     FROM {$wpdb->prefix}avm_addresses
     WHERE (valid_from IS NULL OR valid_from <= %s)
       AND (valid_until IS NULL OR valid_until >= %s)
     GROUP BY member_id
     HAVING COUNT(*) > 1
     ORDER BY member_id",
    $as_of, $as_of
)) ?: [];

echo "Current-address overlap report as of {$as_of}\n";
echo count($overlaps) . " member(s) with overlapping current rows.\n";
foreach ($overlaps as $overlap) {
    $current = array_filter(
        AVPVH_DB::get_addresses((int) $overlap->member_id),
        static fn(object $address): bool =>
            (!$address->valid_from || $address->valid_from <= $as_of)
            && (!$address->valid_until || $address->valid_until >= $as_of)
    );
    $keys = array_unique(array_map(
        static fn(object $address): string => AVPVH_Address_Normalizer::match_key($address),
        $current
    ));
    $classification = count($keys) === 1 ? 'equivalent_duplicates' : 'conflicting_addresses';
    echo "member_id={$overlap->member_id}; current_rows={$overlap->current_count}; "
        . 'distinct_normalized=' . count($keys)
        . "; classification={$classification}\n";
}
