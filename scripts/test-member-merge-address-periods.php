<?php
declare(strict_types=1);

/**
 * Ad-hoc checks for address-period overlap warnings on the member merge page.
 *
 * Run:
 *   php scripts/test-member-merge-address-periods.php
 */

define('ABSPATH', __DIR__ . '/');

function __(string $text, string $domain = ''): string {
    return $text;
}

require_once dirname(__DIR__) . '/includes/class-member-merge.php';

function address_period(?string $from, ?string $until): object {
    return (object) ['valid_from' => $from, 'valid_until' => $until];
}

function check_overlap(string $label, object $left, object $right, bool $expected): void {
    $actual = AVPVH_Member_Merge::address_periods_overlap($left, $right);
    echo ($actual === $expected ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if ($actual !== $expected) {
        exit(1);
    }
}

check_overlap(
    'separate historical periods do not overlap',
    address_period('1990-01-01', '1995-12-31'),
    address_period('1996-01-01', '2000-12-31'),
    false
);
check_overlap(
    'a shared boundary date counts as overlap',
    address_period('1990-01-01', '1995-12-31'),
    address_period('1995-12-31', '2000-12-31'),
    true
);
check_overlap(
    'an open current period overlaps a later period',
    address_period('2020-01-01', null),
    address_period('2024-01-01', null),
    true
);
check_overlap(
    'an unknown start is treated as open toward the past',
    address_period(null, '1995-12-31'),
    address_period('1995-01-01', '2000-12-31'),
    true
);

echo 'Done.' . PHP_EOL;
