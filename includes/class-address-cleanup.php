<?php
defined('ABSPATH') || exit;

/**
 * "Adressen opschonen" on the Leden samenvoegen page: address rows that are
 * the same address (AVPVH_Address_Normalizer::match_key()) but spelled
 * differently ("...str" vs "...straat", "40-I" vs "40 1 hoog", import OCR
 * slips), or stored more than once for one member. The admin picks one
 * spelling per address; a member's rows for that address whose periods
 * touch or overlap can be folded into one row.
 */
class AVPVH_Address_Cleanup {

    const FIELDS = ['street', 'house_number', 'postal_code', 'city'];

    /**
     * Groups needing attention, keyed by match key; those with spelling
     * differences first, then most rows first. Each:
     * 'key', 'rows' (address rows plus member name columns), 'variants'
     * (list of ['fields' => [...], 'count' => n], most common first),
     * 'default' (fields of the first variant), 'clusters' (lists of row ids
     * per member that can be folded into one), 'fingerprint'.
     */
    public static function find_groups(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT a.*, m.first_name, m.suffix, m.last_name
             FROM {$wpdb->prefix}avm_addresses a
             JOIN {$wpdb->prefix}avm_members m ON m.id = a.member_id
             WHERE a.street <> ''
             ORDER BY a.id"
        ) ?: [];

        $by_key = [];
        foreach ($rows as $row) {
            $key = AVPVH_Address_Normalizer::match_key($row);
            if ($key !== '') {
                $by_key[$key][] = $row;
            }
        }

        $groups = [];
        foreach ($by_key as $key => $group_rows) {
            if (count($group_rows) < 2) {
                continue;
            }
            $variants = self::variants($group_rows);
            $clusters = self::clusters($group_rows);
            if (count($variants) < 2 && !$clusters) {
                continue;
            }
            $groups[$key] = [
                'key'         => $key,
                'rows'        => $group_rows,
                'variants'    => $variants,
                'default'     => $variants[0]['fields'],
                'clusters'    => $clusters,
                'fingerprint' => self::fingerprint($group_rows),
            ];
        }
        // Spelling choices (manual work) first, then the biggest groups.
        uasort($groups, fn($a, $b) => (count($b['variants']) > 1) <=> (count($a['variants']) > 1)
            ?: count($b['rows']) <=> count($a['rows'])
            ?: strcmp($a['key'], $b['key']));
        return $groups;
    }

    /** Address fields of a row, as stored. */
    public static function fields(object $row): array {
        $fields = [];
        foreach (self::FIELDS as $field) {
            $fields[$field] = (string) $row->$field;
        }
        return $fields;
    }

    public static function format(array $fields): string {
        return trim("{$fields['street']} {$fields['house_number']}, {$fields['postal_code']} {$fields['city']}", ' ,');
    }

    /**
     * Distinct spellings, most common first; on a tie the longer street and
     * city win (written out rather than abbreviated).
     */
    private static function variants(array $rows): array {
        $variants = [];
        foreach ($rows as $row) {
            $fields = self::fields($row);
            $id = implode("\0", $fields);
            $variants[$id] ??= ['fields' => $fields, 'count' => 0];
            $variants[$id]['count']++;
        }
        $variants = array_values($variants);
        usort($variants, fn($a, $b) => $b['count'] <=> $a['count']
            ?: mb_strlen($b['fields']['street']) <=> mb_strlen($a['fields']['street'])
            ?: mb_strlen($b['fields']['city']) <=> mb_strlen($a['fields']['city']));
        return $variants;
    }

    /**
     * Per member, runs of rows whose periods overlap or follow on directly
     * (inclusive dates, empty = open-ended); only runs of two or more. Rows
     * with a gap between them stay apart: that member moved away and back.
     */
    public static function clusters(array $rows): array {
        $by_member = [];
        foreach ($rows as $row) {
            $by_member[(int) $row->member_id][] = $row;
        }
        $clusters = [];
        foreach ($by_member as $member_rows) {
            foreach (self::runs($member_rows) as $run) {
                if (count($run) > 1) {
                    $clusters[] = array_map(fn($r) => (int) $r->id, $run);
                }
            }
        }
        return $clusters;
    }

    /** One member's rows split into runs of touching or overlapping periods. */
    private static function runs(array $member_rows): array {
        usort($member_rows, fn($a, $b) => strcmp((string) $a->valid_from, (string) $b->valid_from) ?: (int) $a->id <=> (int) $b->id);
        $runs = [];
        $run_until = null;
        foreach ($member_rows as $row) {
            $touches = $runs && ($run_until === null || $row->valid_from === null
                || $row->valid_from <= gmdate('Y-m-d', strtotime($run_until . ' +1 day')));
            if ($touches) {
                $runs[count($runs) - 1][] = $row;
                $run_until = ($run_until === null || $row->valid_until === null) ? null : max($run_until, $row->valid_until);
            } else {
                $runs[] = [$row];
                $run_until = $row->valid_until;
            }
        }
        return $runs;
    }

    private static function fingerprint(array $rows): string {
        $state = array_map(fn($r) => [(int) $r->id, (int) $r->member_id, self::fields($r), $r->valid_from, $r->valid_until], $rows);
        return hash('sha256', (string) wp_json_encode($state));
    }

    /**
     * Writes $fields into every row of group $key and, with $merge_runs,
     * folds each cluster into its lowest-id row (earliest start, latest end;
     * open-ended wins). $fields must still be the same address.
     * Returns ['updated' => rows respelled, 'removed' => rows folded away].
     */
    public static function apply(string $key, array $fields, bool $merge_runs, string $fingerprint): array|\WP_Error {
        global $wpdb;
        $table = "{$wpdb->prefix}avm_addresses";

        $group = self::find_groups()[$key] ?? null;
        $fields = self::validated_fields($group, $key, $fields, $fingerprint);
        if (is_wp_error($fields)) {
            return $fields;
        }

        $rows = [];
        foreach ($group['rows'] as $row) {
            $rows[(int) $row->id] = $row;
        }
        $new_display = self::format($fields);
        $updated = 0;
        $removed = 0;

        $wpdb->query('START TRANSACTION');
        try {
            foreach ($rows as $id => $row) {
                if (self::fields($row) === $fields) {
                    continue;
                }
                self::check($wpdb->update($table, $fields, ['id' => $id]));
                AVPVH_DB::log_member_change((int) $row->member_id, 'adres', self::format(self::fields($row)), $new_display);
                $updated++;
            }
            foreach ($merge_runs ? $group['clusters'] : [] as $ids) {
                $removed += self::fold_run($ids, $rows);
            }
            $wpdb->query('COMMIT');
        } catch (\Throwable $exception) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('failed', sprintf(/* translators: %s: error message */ __('Opschonen afgebroken, er is niets gewijzigd: %s', 'avpvh-members'), $exception->getMessage()));
        }

        return ['updated' => $updated, 'removed' => $removed];
    }

    /**
     * $fields cleaned and in FIELDS order, or why they can't be applied to
     * $group: it's gone, changed since the page showed $fingerprint, or
     * $fields are a different address.
     */
    private static function validated_fields(?array $group, string $key, array $fields, string $fingerprint): array|\WP_Error {
        if (!$group) {
            $error = new \WP_Error('gone', __('Deze adresgroep bestaat niet meer; misschien is hij al opgeschoond.', 'avpvh-members'));
        } elseif (!hash_equals($group['fingerprint'], $fingerprint)) {
            $error = new \WP_Error('stale', __('De adressen zijn gewijzigd sinds je de pagina opende. Bekijk ze opnieuw.', 'avpvh-members'));
        } else {
            $cleaned = AVPVH_Address_Normalizer::clean(array_intersect_key($fields, array_flip(self::FIELDS)) + ['country' => $group['rows'][0]->country]);
            $fields = [];
            foreach (self::FIELDS as $field) {
                $fields[$field] = (string) ($cleaned[$field] ?? '');
            }
            $error = AVPVH_Address_Normalizer::match_key($fields) === $key ? null
                : new \WP_Error('other_address', __('Dat is een ander adres (postcode, huisnummer of straat wijkt af). Hier kun je alleen de schrijfwijze kiezen.', 'avpvh-members'));
        }
        return $error ?? $fields;
    }

    /**
     * Folds the rows $ids into the lowest id (earliest start, latest end;
     * open-ended wins) and deletes the rest. Returns rows deleted.
     */
    private static function fold_run(array $ids, array $rows): int {
        global $wpdb;
        $table = "{$wpdb->prefix}avm_addresses";
        sort($ids);
        $run = array_map(fn($id) => $rows[$id], $ids);
        $froms = array_column($run, 'valid_from');
        $untils = array_column($run, 'valid_until');
        $from = in_array(null, $froms, true) ? null : min($froms);
        $until = in_array(null, $untils, true) ? null : max($untils);
        $keep_id = array_shift($ids);
        self::check($wpdb->update($table, ['valid_from' => $from, 'valid_until' => $until], ['id' => $keep_id]));
        foreach ($ids as $id) {
            self::check($wpdb->delete($table, ['id' => $id], ['%d']));
        }
        AVPVH_DB::log_member_change(
            (int) $rows[$keep_id]->member_id,
            'adres',
            sprintf(/* translators: %d: number of address rows */ __('%d regels voor hetzelfde adres', 'avpvh-members'), count($run)),
            sprintf(/* translators: 1: start date, 2: end date */ __('samengevoegd tot één regel (%1$s – %2$s)', 'avpvh-members'), $from ?? '?', $until ?? __('heden', 'avpvh-members'))
        );
        return count($ids);
    }

    private static function check(int|false $result): void {
        if ($result === false) {
            global $wpdb;
            throw new AVPVH_Address_Cleanup_Exception($wpdb->last_error ?: 'databasefout');
        }
    }
}

/** A failed write during Adressen opschonen; apply() rolls back on it. */
class AVPVH_Address_Cleanup_Exception extends \RuntimeException {
}
