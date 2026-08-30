<?php
/** Read-only report of members with more than one current address. */

defined('ABSPATH') || exit;

$as_of = getenv('AVPVH_ADDRESS_AS_OF') ?: current_time('Y-m-d');
$overlaps = AVPVH_DB::get_current_address_overlaps($as_of);

echo "Current-address overlap report as of {$as_of}\n";
echo count($overlaps) . " member(s) with overlapping current rows.\n";
foreach ($overlaps as $overlap) {
    echo "member_id={$overlap->member_id}; current_rows={$overlap->current_count}\n";
}
