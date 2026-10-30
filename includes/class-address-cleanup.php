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

    const FIELDS = ['street', 'house_number', 'house_number_addition', 'postal_code', 'city'];

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
            "SELECT a.*, m.first_name, m.suffix, m.last_name, m.status
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

    /**
     * The spelling choices for one address: the stored variants (most
     * common first, see variants()) with the official spelling of its
     * register first, marked by source, when the register knows the
     * address and it is recognizably this one; failing that, a Dutch
     * postcode's single street confirms the street's spelling
     * ('street_official'). Each option: fields, count, official (source or
     * ''), street_official.
     */
    public static function spelling_options(array $variants, array $rows): array {
        $official = null;
        foreach ($rows as $row) {
            $found = AVPVH_Address_Lookup::lookup($row);
            if ($found && self::probably_same_address((object) $found['fields'], $row)) {
                $official = $found;
                break;
            }
        }
        // No whole address in the BAG (a number that no longer exists, say):
        // the street of a Dutch postcode still confirms the street's spelling.
        $street = null;
        foreach ($official ? [] : $rows as $row) {
            $found = in_array(AVPVH_Address_Normalizer::canonical_country((string) $row->country), ['', 'Nederland'], true)
                ? AVPVH_PDOK::street_of_postcode((string) $row->postal_code) : null;
            $compact = fn(string $v): string => str_replace(' ', '', AVPVH_Address_Normalizer::fold($v));
            similar_text($compact((string) $row->street), $compact($found['street'] ?? ''), $percent);
            if ($found && $percent >= 75) {
                $street = $found;
                break;
            }
        }
        $options = array_map(fn($v) => $v + [
            'official' => $official && $v['fields'] === $official['fields'] ? $official['source'] : '',
            'street_official' => $street && $v['fields']['street'] === $street['street'],
        ], $variants);
        if ($official && !array_filter($options, fn($o) => $o['official'] !== '')) {
            $options[] = ['fields' => $official['fields'], 'count' => 0, 'official' => $official['source'], 'street_official' => false];
        }
        if ($street && !array_filter($options, fn($o) => $o['street_official'])) {
            $options[] = ['fields' => ['street' => $street['street'], 'city' => $street['city']] + $variants[0]['fields'], 'count' => 0, 'official' => '', 'street_official' => true];
        }
        $rank = fn(array $o): int => $o['official'] !== '' ? 2 : (int) $o['street_official'];
        usort($options, fn($a, $b) => $rank($b) <=> $rank($a));
        return $options;
    }

    /**
     * "ex-lid: adres mogelijk verouderd" for an address of an ex-member that
     * still runs on to the present: mostly the last address an old
     * ledenlijst gave, kept as it is because when it ended isn't known.
     * '' for anything else.
     */
    public static function outdated_badge(object $address, string $status): string {
        if ($address->valid_until !== null || $status !== 'inactive') {
            return '';
        }
        return '<span class="avpvh-address-fit avpvh-address-fit--warning" title="' . esc_attr__('Ex-lid: het laatst bekende adres, waarschijnlijk uit een oude ledenlijst. Wanneer het eindigde is niet bekend.', 'avpvh-members') . '">'
            . esc_html__('ex-lid: adres mogelijk verouderd', 'avpvh-members') . '</span>';
    }

    public static function option_label(array $option): string {
        $label = self::format($option['fields']);
        if ($option['official'] !== '') {
            /* translators: %s: register name */
            return $label . ' — ' . sprintf(__('officieel (%s)', 'avpvh-members'), $option['official']);
        }
        return $option['street_official'] ? $label . ' — ' . __('straatnaam officieel (BAG); huisnummer niet in de BAG', 'avpvh-members') : $label;
    }

    /**
     * One person's address history planned from $rows (of one member, or
     * of two records being merged): grouped per address (match_key(), or
     * recognizably the same address with a typo), each group's periods that
     * touch or overlap folded into one (earliest start, latest end; a gap
     * keeps them apart: moved away and back), and only the newest start
     * left running on — the others end the day before it. Returns groups:
     * ['id' => short stable id, 'rows', 'variants', 'results' => [['ids',
     * 'from', 'until', 'closed' => ended by the one-open rule]]].
     */
    public static function plan_history(array $rows): array {
        $groups = [];
        foreach ($rows as $row) {
            $home = null;
            foreach ($groups as $i => $group) {
                foreach ($group as $other) {
                    $key = AVPVH_Address_Normalizer::match_key($row);
                    if (($key !== '' && $key === AVPVH_Address_Normalizer::match_key($other)) || self::probably_same_address($row, $other)) {
                        $home = $i;
                        break 2;
                    }
                }
            }
            if ($home === null) {
                $groups[] = [$row];
            } else {
                $groups[$home][] = $row;
            }
        }

        $plan = [];
        foreach ($groups as $group_rows) {
            $results = [];
            foreach (self::runs($group_rows) as $run) {
                $froms = array_column($run, 'valid_from');
                $untils = array_column($run, 'valid_until');
                $results[] = [
                    'ids'    => array_map(fn($r) => (int) $r->id, $run),
                    'from'   => in_array(null, $froms, true) ? null : min($froms),
                    'until'  => in_array(null, $untils, true) ? null : max($untils),
                    'closed' => false,
                ];
            }
            $ids = array_map(fn($r) => (int) $r->id, $group_rows);
            sort($ids);
            $plan[] = [
                'id'       => substr(hash('sha256', implode(',', $ids)), 0, 10),
                'rows'     => $group_rows,
                'variants' => self::variants($group_rows),
                'results'  => $results,
            ];
        }

        // Only the newest start stays open; without one clear newest start
        // (no date, or a tie) nothing is closed.
        $open = [];
        foreach ($plan as $g => $group) {
            foreach ($group['results'] as $r => $result) {
                if ($result['until'] === null) {
                    $open[] = [$g, $r, $result['from']];
                }
            }
        }
        $starts = array_filter(array_column($open, 2));
        $newest = $starts ? max($starts) : null;
        if (count($open) > 1 && $newest !== null && count(array_keys(array_column($open, 2), $newest, true)) === 1) {
            foreach ($open as [$g, $r, $from]) {
                if ($from !== $newest) {
                    $plan[$g]['results'][$r]['until'] = gmdate('Y-m-d', strtotime($newest . ' -1 day'));
                    $plan[$g]['results'][$r]['closed'] = true;
                }
            }
        }
        return $plan;
    }

    /**
     * Members with more than one address running on to the present (no end
     * date) that are different addresses: only one may. Keyed by member id:
     * 'rows', 'keep' (id of the row with the latest start date, or null when
     * there's no single latest one), 'close' (row id => end date: the day
     * before 'keep' started), 'fingerprint'. Several open rows of the same
     * address are left to the duplicate folding above.
     */
    public static function find_open_conflicts(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT a.*, m.first_name, m.suffix, m.last_name, m.status
             FROM {$wpdb->prefix}avm_addresses a
             JOIN {$wpdb->prefix}avm_members m ON m.id = a.member_id
             WHERE a.valid_until IS NULL
             ORDER BY a.member_id, a.id"
        ) ?: [];
        $by_member = [];
        foreach ($rows as $row) {
            $by_member[(int) $row->member_id][] = $row;
        }
        $conflicts = [];
        foreach ($by_member as $member_id => $member_rows) {
            $key = fn(object $r): string => AVPVH_Address_Normalizer::match_key($r) ?: 'id:' . (int) $r->id;
            if (count(array_unique(array_map($key, $member_rows))) < 2) {
                continue;
            }
            usort($member_rows, fn($a, $b) => strcmp((string) $b->valid_from, (string) $a->valid_from) ?: (int) $b->id <=> (int) $a->id);
            $newest = $member_rows[0];
            $others = array_filter($member_rows, fn($r) => $key($r) !== $key($newest));
            // A typo in street or postcode isn't a move: offer to merge.
            $same_place = !array_filter($others, fn($r) => !self::probably_same_address($r, $newest));
            $tie = $same_place || (string) $newest->valid_from === '' || array_filter($others, fn($r) => $r->valid_from === $newest->valid_from);
            $until = $tie ? null : gmdate('Y-m-d', strtotime($newest->valid_from . ' -1 day'));
            $conflicts[$member_id] = [
                'member_id'   => $member_id,
                'rows'        => $member_rows,
                'same_place'  => $same_place,
                'variants'    => $same_place ? self::variants($member_rows) : [],
                'keep'        => $tie ? null : (int) $newest->id,
                'close'       => $tie ? [] : array_fill_keys(array_map(fn($r) => (int) $r->id, $others), $until),
                'fingerprint' => self::fingerprint($member_rows),
            ];
        }
        return $conflicts;
    }

    /**
     * Two rows that differ only by a typo: same house number, and the same
     * Dutch postcode (that pins the address down), or the same city or
     * postcode or a postcode one character off, with street names mostly alike or that
     * postcode slip ("J. van Lindenweg 3, 1234 AC" vs "Johan van Lindeweg
     * 3, 1234 AB", same town).
     */
    public static function probably_same_address(object $left, object $right): bool {
        $number = fn(object $a): string => AVPVH_Address_Normalizer::normalize_house_number((string) $a->house_number, (string) ($a->house_number_addition ?? ''));
        if ($number($left) === '' || $number($left) !== $number($right)) {
            return false;
        }
        $compact = fn(string $v): string => str_replace(' ', '', AVPVH_Address_Normalizer::fold($v));
        [$left_pc, $right_pc] = [$compact((string) $left->postal_code), $compact((string) $right->postal_code)];
        if (preg_match('/^\d{4}[a-z]{2}$/', $left_pc) && $left_pc === $right_pc) {
            return true;
        }
        $postcode_slip = strlen($left_pc) === 6 && strlen($right_pc) === 6 && levenshtein($left_pc, $right_pc) === 1;
        $same_city = $compact((string) $left->city) !== '' && $compact((string) $left->city) === $compact((string) $right->city);
        $same_postcode = $left_pc !== '' && $left_pc === $right_pc;
        similar_text($compact((string) $left->street), $compact((string) $right->street), $street_percent);
        return ($same_city || $same_postcode || $postcode_slip) && ($street_percent >= 75 || $postcode_slip);
    }

    /**
     * Merges $member_id's open rows that probably_same_address() matched
     * into one: written as $fields, from the earliest start, still open.
     * $fields must keep the same house number.
     */
    public static function merge_same_place(int $member_id, array $fields, string $fingerprint): array|\WP_Error {
        global $wpdb;
        $conflict = self::find_open_conflicts()[$member_id] ?? null;
        if (!$conflict || !$conflict['same_place'] || !hash_equals($conflict['fingerprint'], $fingerprint)) {
            return new \WP_Error('stale', __('De adressen van dit lid zijn gewijzigd sinds je de pagina opende. Bekijk ze opnieuw.', 'avpvh-members'));
        }
        $rows = $conflict['rows'];
        $cleaned = AVPVH_Address_Normalizer::clean(array_intersect_key($fields, array_flip(self::FIELDS)) + ['country' => $rows[0]->country]);
        $fields = [];
        foreach (self::FIELDS as $field) {
            $fields[$field] = (string) ($cleaned[$field] ?? '');
        }
        $number = AVPVH_Address_Normalizer::normalize_house_number($fields['house_number'], $fields['house_number_addition']);
        if ($fields['street'] === '' || $number !== AVPVH_Address_Normalizer::normalize_house_number((string) $rows[0]->house_number, (string) $rows[0]->house_number_addition)) {
            return new \WP_Error('other_address', __('Dat is een ander huisnummer. Hier kun je alleen de schrijfwijze kiezen.', 'avpvh-members'));
        }
        $froms = array_column($rows, 'valid_from');
        $from = in_array(null, $froms, true) ? null : min($froms);
        $ids = array_map(fn($r) => (int) $r->id, $rows);
        sort($ids);
        $keep_id = array_shift($ids);
        $wpdb->query('START TRANSACTION');
        try {
            self::check($wpdb->update("{$wpdb->prefix}avm_addresses", $fields + ['valid_from' => $from, 'valid_until' => null], ['id' => $keep_id]));
            foreach ($ids as $id) {
                self::check($wpdb->delete("{$wpdb->prefix}avm_addresses", ['id' => $id], ['%d']));
            }
            AVPVH_DB::log_member_change(
                $member_id,
                'adres',
                implode(' / ', array_unique(array_map(fn($r) => self::format(self::fields($r)), $rows))),
                self::format($fields) . sprintf(/* translators: %s: start date */ __(' (sinds %s)', 'avpvh-members'), $from ?? '?')
            );
            $wpdb->query('COMMIT');
        } catch (\Throwable $exception) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('failed', sprintf(/* translators: %s: error message */ __('Samenvoegen afgebroken, er is niets gewijzigd: %s', 'avpvh-members'), $exception->getMessage()));
        }
        return ['merged' => count($rows)];
    }

    /** Ends $member_id's older open addresses as find_open_conflicts() proposes. */
    public static function close_older(int $member_id, string $fingerprint): array|\WP_Error {
        global $wpdb;
        $conflict = self::find_open_conflicts()[$member_id] ?? null;
        if (!$conflict || !hash_equals($conflict['fingerprint'], $fingerprint)) {
            return new \WP_Error('stale', __('De adressen van dit lid zijn gewijzigd sinds je de pagina opende. Bekijk ze opnieuw.', 'avpvh-members'));
        }
        if ($conflict['keep'] === null) {
            return new \WP_Error('manual', __('Geen voorstel om af te sluiten voor dit lid.', 'avpvh-members'));
        }
        $rows = array_column($conflict['rows'], null, 'id');
        $wpdb->query('START TRANSACTION');
        try {
            foreach ($conflict['close'] as $id => $until) {
                self::check($wpdb->update("{$wpdb->prefix}avm_addresses", ['valid_until' => $until], ['id' => $id]));
                AVPVH_DB::log_member_change($member_id, 'adres', self::format(self::fields($rows[$id])) . ' (doorlopend)', sprintf(/* translators: %s: end date */ __('afgesloten op %s', 'avpvh-members'), $until));
            }
            $wpdb->query('COMMIT');
        } catch (\Throwable $exception) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('failed', sprintf(/* translators: %s: error message */ __('Afsluiten afgebroken, er is niets gewijzigd: %s', 'avpvh-members'), $exception->getMessage()));
        }
        return ['closed' => count($conflict['close'])];
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
        return trim(AVPVH_Address_Normalizer::format_street_line($fields) . ", {$fields['postal_code']} {$fields['city']}", ' ,');
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
            // The same address, or a typo fixed in it (a postcode one
            // character off, as the official spelling may correct).
            $same = AVPVH_Address_Normalizer::match_key($fields) === $key
                || self::probably_same_address((object) $fields, $group['rows'][0]);
            $error = $same ? null
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
