<?php
declare(strict_types=1);

/**
 * AVPVH_Fees: own records vs. a bookkeeping plugin's filters, status
 * mapping, settled years, and AVPVH_Fees_Admin::parse_amount(). Members
 * and amounts below are made up.
 *
 * Run:
 *   php scripts/test-fees.php
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['filters'] = [];
function __($s, $d = '') { return $s; }
function add_filter(string $hook, callable $cb): void { $GLOBALS['filters'][$hook] = $cb; }
function apply_filters(string $hook, $value, ...$args) {
    return isset($GLOBALS['filters'][$hook]) ? ($GLOBALS['filters'][$hook])($value, ...$args) : $value;
}
function add_action(...$args): void {}

final class AVPVH_DB {
    public static array $fees = [];
    public static function get_fees_for_year(array $ids, int $year): array {
        return array_values(array_filter(self::$fees, static fn($f) => $f->year === $year && in_array($f->member_id, $ids, true)));
    }
    public static function get_fees_for_member(int $id): array {
        $rows = array_values(array_filter(self::$fees, static fn($f) => $f->member_id === $id));
        usort($rows, static fn($a, $b) => $b->year <=> $a->year);
        return $rows;
    }
}

require __DIR__ . '/../includes/class-fees.php';
require __DIR__ . '/../includes/class-fees-admin.php';

function check(string $what, bool $ok): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $what . PHP_EOL;
}
function fee(int $id, int $member, int $year, string $status, ?float $due, ?float $paid = null, ?string $date = null): object {
    return (object) ['id' => $id, 'member_id' => $member, 'year' => $year, 'status' => $status, 'amount_due' => $due, 'amount_paid' => $paid, 'paid_date' => $date];
}

// --- own records -----------------------------------------------------------
AVPVH_DB::$fees = [
    fee(1, 10, 2026, 'paid', 25.0, 25.0, '2026-02-01'),
    fee(2, 11, 2026, 'pending', 25.0),
    fee(3, 12, 2026, 'pending', 25.0, 10.0),
    fee(4, 13, 2026, 'waived', 0.0),
    fee(5, 10, 2025, 'paid', 20.0, 20.0, '2025-03-01'),
    fee(6, 10, 2024, 'pending', 20.0),
];
check('no manager without filter', AVPVH_Fees::manager() === null && !AVPVH_Fees::is_external());
$own = AVPVH_Fees::statuses_for_year([10, 11, 12, 13, 14], 2026);
check('own: paid', $own[10]['status'] === 'paid');
check('own: pending', $own[11]['status'] === 'pending');
check('own: pending with money in it is partial', $own[12]['status'] === 'partial');
check('own: waived', $own[13]['status'] === 'waived');
check('own: member without record left out', !isset($own[14]));
check('own: none label is explicit, not a dash', AVPVH_Fees::label($own[14]['status'] ?? AVPVH_Fees::NONE) === 'Niet aangemaakt');
$history = AVPVH_Fees::for_member(10);
check('own: history newest first with ids', array_column($history, 'year') === [2026, 2025, 2024] && $history[0]['id'] === 1);
check('own: settled years skip pending', AVPVH_Fees::settled_years(10) === [2025, 2026]);
check('empty id list gives nothing', AVPVH_Fees::statuses_for_year([], 2026) === []);

// --- bookkeeping takes over ------------------------------------------------
add_filter('avpvh_mbrs_fee_manager', static fn($v, int $member_id) => ['label' => 'Boekhouding', 'url' => 'https://example.test/m/' . $member_id]);
add_filter('avpvh_mbrs_fee_statuses', static fn($v, array $ids, int $year) => [
    11 => ['year' => $year, 'status' => 'paid', 'amount_due' => '25.00', 'amount_paid' => '25.00', 'paid_date' => '2026-03-03'],
    14 => ['year' => $year, 'status' => 'bogus'],
]);
add_filter('avpvh_mbrs_member_fees', static fn($v, int $member_id) => [
    (object) ['year' => 2026, 'status' => 'partial', 'amount_due' => 25, 'amount_paid' => 5],
    ['year' => 2025, 'status' => 'waived', 'amount_due' => 0],
]);
check('manager from filter', AVPVH_Fees::is_external() && AVPVH_Fees::manager(11)['url'] === 'https://example.test/m/11');
$ext = AVPVH_Fees::statuses_for_year([10, 11, 14], 2026);
check('external replaces own records', !isset($ext[10]) && $ext[11]['status'] === 'paid' && $ext[11]['amount_paid'] === 25.0);
check('unknown status becomes none', $ext[14]['status'] === 'none' && $ext[14]['amount_due'] === null);
$ext_history = AVPVH_Fees::for_member(10);
check('external history normalized', $ext_history[0]['status'] === 'partial' && $ext_history[0]['id'] === 0 && $ext_history[1]['paid_date'] === null);
check('external settled years', AVPVH_Fees::settled_years(10) === [2025]);

// --- amounts ---------------------------------------------------------------
check('amount with comma', AVPVH_Fees_Admin::parse_amount('12,50') === 12.5);
check('amount with dot', AVPVH_Fees_Admin::parse_amount('12.5') === 12.5);
check('amount with euro and thousands', AVPVH_Fees_Admin::parse_amount('€ 1.012,50') === 1012.5);
check('empty amount is null', AVPVH_Fees_Admin::parse_amount('  ') === null);
check('text amount is null', AVPVH_Fees_Admin::parse_amount('twaalf') === null);
check('negative amount is null', AVPVH_Fees_Admin::parse_amount('-5') === null);
check('format amount', AVPVH_Fees::format_amount(1012.5) === '€ 1.012,50' && AVPVH_Fees::format_amount(null) === '—');

echo PHP_EOL . 'Done.' . PHP_EOL;
