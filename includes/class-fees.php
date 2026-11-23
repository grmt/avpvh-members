<?php
defined('ABSPATH') || exit;

/**
 * Contribution status per member and year, from one source at a time.
 *
 * On its own, this plugin keeps a simple administration in avm_fees: the
 * admin creates a year's records for every active member and marks them
 * paid or waived by hand. A bookkeeping plugin can take over by answering
 * three filters; from then on its status is the only one shown and the
 * manual controls give way to a link to it, so two administrations never
 * disagree:
 *
 *  - avpvh_mbrs_fee_manager (null, int $member_id): ['label' => string,
 *    'url' => string] — who keeps the contribution, and where to manage
 *    it (for $member_id, or in general for 0).
 *  - avpvh_mbrs_fee_statuses (null, int[] $member_ids, int $year):
 *    member_id => row, one row per member that has a status that year.
 *  - avpvh_mbrs_member_fees (null, int $member_id): rows, newest year first.
 *
 * A row is ['year' => int, 'status' => one of self::STATUSES' keys,
 * 'amount_due' => ?float, 'amount_paid' => ?float, 'paid_date' => ?string
 * (Y-m-d)].
 */
class AVPVH_Fees {

    public const PAID    = 'paid';
    public const PARTIAL = 'partial';
    public const PENDING = 'pending';
    public const WAIVED  = 'waived';
    public const NONE    = 'none';

    /** Statuses avm_fees itself can store (partial is derived from amount_paid). */
    public const STORED = [self::PAID, self::PENDING, self::WAIVED];

    /** @return array<string,string> status => label */
    public static function labels(): array {
        return [
            self::PAID    => __('Betaald', 'avpvh-members'),
            self::PARTIAL => __('Deels betaald', 'avpvh-members'),
            self::PENDING => __('Openstaand', 'avpvh-members'),
            self::WAIVED  => __('Vrijgesteld', 'avpvh-members'),
            self::NONE    => __('Niet aangemaakt', 'avpvh-members'),
        ];
    }

    public static function label(string $status): string {
        return self::labels()[$status] ?? $status;
    }

    /**
     * Who keeps the contribution instead of this plugin, or null when this
     * plugin does.
     *
     * @return array{label:string,url:string}|null
     */
    public static function manager(int $member_id = 0): ?array {
        $manager = apply_filters('avpvh_mbrs_fee_manager', null, $member_id);
        if (!is_array($manager) || empty($manager['label'])) {
            return null;
        }
        return ['label' => (string) $manager['label'], 'url' => (string) ($manager['url'] ?? '')];
    }

    public static function is_external(): bool {
        return self::manager() !== null;
    }

    /**
     * Status of each member for $year; members without one are left out
     * (callers treat them as NONE).
     *
     * @param int[] $member_ids
     * @return array<int,array<string,mixed>>
     */
    public static function statuses_for_year(array $member_ids, int $year): array {
        $member_ids = array_values(array_unique(array_filter(array_map('intval', $member_ids))));
        if (!$member_ids) {
            return [];
        }
        if (self::is_external()) {
            $rows = apply_filters('avpvh_mbrs_fee_statuses', null, $member_ids, $year);
            return is_array($rows) ? array_map([self::class, 'normalize'], $rows) : [];
        }
        $statuses = [];
        foreach (AVPVH_DB::get_fees_for_year($member_ids, $year) as $fee) {
            $statuses[(int) $fee->member_id] = self::from_record($fee);
        }
        return $statuses;
    }

    /** @return array<int,array<string,mixed>> newest year first */
    public static function for_member(int $member_id): array {
        if (self::is_external()) {
            $rows = apply_filters('avpvh_mbrs_member_fees', null, $member_id);
            return is_array($rows) ? array_values(array_map([self::class, 'normalize'], $rows)) : [];
        }
        return array_map([self::class, 'from_record'], AVPVH_DB::get_fees_for_member($member_id));
    }

    /** Years the member paid or was exempt, ascending — evidence of membership. */
    public static function settled_years(int $member_id): array {
        $years = [];
        foreach (self::for_member($member_id) as $row) {
            if (in_array($row['status'], [self::PAID, self::WAIVED], true)) {
                $years[] = (int) $row['year'];
            }
        }
        sort($years);
        return array_values(array_unique($years));
    }

    /** An avm_fees row as a status row; a pending record with some money in it is partial. */
    public static function from_record(object $fee): array {
        $paid = $fee->amount_paid !== null ? (float) $fee->amount_paid : null;
        $status = (string) $fee->status;
        if ($status === self::PENDING && $paid !== null && $paid > 0.005) {
            $status = self::PARTIAL;
        }
        return [
            'id'          => (int) ($fee->id ?? 0),
            'year'        => (int) $fee->year,
            'status'      => $status,
            'amount_due'  => $fee->amount_due !== null ? (float) $fee->amount_due : null,
            'amount_paid' => $paid,
            'paid_date'   => $fee->paid_date ?: null,
        ];
    }

    /** Fills in a provider's row so templates can rely on every key. */
    public static function normalize(mixed $row): array {
        $row = (array) $row;
        $status = (string) ($row['status'] ?? self::NONE);
        return [
            'id'          => 0,
            'year'        => (int) ($row['year'] ?? 0),
            'status'      => isset(self::labels()[$status]) ? $status : self::NONE,
            'amount_due'  => isset($row['amount_due']) ? (float) $row['amount_due'] : null,
            'amount_paid' => isset($row['amount_paid']) ? (float) $row['amount_paid'] : null,
            'paid_date'   => !empty($row['paid_date']) ? (string) $row['paid_date'] : null,
        ];
    }

    public static function format_amount(?float $amount): string {
        return $amount === null ? '—' : '€ ' . number_format($amount, 2, ',', '.');
    }
}
