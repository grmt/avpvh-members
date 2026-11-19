<?php
defined('ABSPATH') || exit;

/**
 * The data side of a deletion plan: every database row linked to the visitor,
 * directly, through a fee item or as an indirect child, with every reason
 * these block the removal. See AVPVH_Visitor_Delete.
 */
class AVPVH_Visitor_Delete_Records {
    private const EMAIL_TABLES = ['avm_login_attempts', 'avb_congress_registrations', 'avb_book_orders', 'avb_orders', 'avb_photo_shares'];

    /** Children of a member's records that have no member_id of their own: [child, column, parent]. */
    private const INDIRECT_CHILDREN = [
        ['avm_activity_participation_days', 'participation_id', 'avm_activity_participation'],
        ['avb_order_items', 'order_id', 'avb_orders'],
        ['avb_dispute_events', 'dispute_id', 'avb_disputes'],
        ['avb_payment_requests', 'fee_item_id', 'avb_fee_items'],
        ['avb_transaction_allocations', 'fee_item_id', 'avb_fee_items'],
    ];

    /** Deleted before everything else, so foreign keys never block the removal. */
    private const DELETE_FIRST = ['avm_activity_participation_days', 'avb_order_items', 'avb_dispute_events', 'avb_payment_requests', 'avb_transaction_allocations', 'avb_disputes', 'avb_congress_registrations', 'avb_book_orders', 'avb_orders'];

    private const INT_TYPES = ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'];

    /** WordPress' own user and option tables must support the transaction too. */
    public static function core_engine_blockers(): array {
        global $wpdb;
        $blockers = [];
        $core_tables = AVPVH_Visitor_Delete_Db::strings('TABLE_NAME', [$wpdb->users, $wpdb->usermeta, $wpdb->options]);
        foreach (AVPVH_Visitor_Delete_Db::rows("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND $core_tables") as $table) {
            if ($table['ENGINE'] !== 'InnoDB') {
                $blockers[] = AVPVH_Visitor_Delete_Db::blocker('engine');
            }
        }
        return $blockers;
    }

    /** Rows in every table that point at the visitor by member ID, user ID or (for known tables) e-mail. */
    public static function member_records(int $id, array $user_ids, array $emails, array $schema, bool $lock, array &$blockers): array {
        global $wpdb;
        $records = [];
        foreach ($schema as $table => $columns) {
            if ($table === 'avm_members' || $table === 'avm_directory_users') {
                continue;
            }
            $clauses = self::member_clauses($table, $columns['columns'], $id, $user_ids, $emails);
            if (!$clauses) {
                continue;
            }
            $record = AVPVH_Visitor_Delete_Db::select($table, implode(' OR ', $clauses), $schema, $lock);
            if (!isset(AVPVH_Visitor_Delete_Db::table_labels()[$table]) && $record['rows']) {
                $blockers[] = sprintf(/* translators: %s: database table name */ __('Er zijn aanvullende gekoppelde gegevens in %s. Die moeten eerst apart worden beheerd.', 'avpvh-members'), $table);
            }
            $records[$wpdb->prefix . $table] = $record;
            foreach ($record['rows'] as $row) {
                array_push($blockers, ...self::member_row_blockers($table, $row, $id));
            }
        }
        return $records;
    }

    private static function member_clauses(string $table, array $columns, int $id, array $user_ids, array $emails): array {
        global $wpdb;
        $clauses = [];
        foreach ($columns as $column => $type) {
            if (!in_array($type, self::INT_TYPES, true)) {
                continue;
            }
            if ($column === 'member_id' || str_ends_with($column, '_member_id')) {
                $clauses[] = $wpdb->prepare("`$column` = %d", $id);
            } elseif (in_array($column, ['user_id', 'wp_user_id'], true) && $user_ids) {
                $clauses[] = AVPVH_Visitor_Delete_Db::ints($column, $user_ids);
            }
        }
        if (in_array($table, self::EMAIL_TABLES, true) && $emails && isset($columns['email'])) {
            $clauses[] = AVPVH_Visitor_Delete_Db::strings('email', $emails);
        }
        return $clauses;
    }

    private static function member_row_blockers(string $table, array $row, int $id): array {
        $blockers = [];
        // A relationship row is the other person's link to this visitor; it goes with the visitor.
        if ($table !== 'avm_relationships' && self::belongs_to_other($row, $id)) {
            $blockers[] = AVPVH_Visitor_Delete_Db::blocker('other_person');
        }
        if (self::is_booked_payment($table, $row)) {
            $blockers[] = AVPVH_Visitor_Delete_Db::blocker('payment');
        }
        if (self::is_fulfilled($row)) {
            $blockers[] = AVPVH_Visitor_Delete_Db::blocker('fulfilled');
        }
        return $blockers;
    }

    /** Rows in every table with a fee_item_id that points at one of the visitor's fee items. */
    public static function fee_records(int $id, array $fee_ids, array $records, array $schema, bool $lock, array &$blockers): array {
        global $wpdb;
        foreach ($schema as $table => $columns) {
            if (!isset($columns['columns']['fee_item_id'])) {
                continue;
            }
            $record = self::extend($records, $table, AVPVH_Visitor_Delete_Db::ints('fee_item_id', $fee_ids), $schema, $lock);
            $records[$wpdb->prefix . $table] = $record;
            foreach ($record['rows'] as $row) {
                array_push($blockers, ...self::fee_row_blockers($table, $row, $id));
            }
        }
        return $records;
    }

    private static function fee_row_blockers(string $table, array $row, int $id): array {
        $blockers = [];
        if ($table === 'avb_transaction_allocations') {
            $blockers[] = AVPVH_Visitor_Delete_Db::blocker('payment');
        } elseif (!isset(AVPVH_Visitor_Delete_Db::table_labels()[$table]) || self::belongs_to_other($row, $id)) {
            $blockers[] = AVPVH_Visitor_Delete_Db::blocker('other_person');
        }
        if (self::is_fulfilled($row)) {
            $blockers[] = AVPVH_Visitor_Delete_Db::blocker('fulfilled');
        }
        return $blockers;
    }

    /**
     * Indirect children have no member_id of their own. Select them even
     * when empty to acquire insertion range locks on the parent IDs.
     */
    public static function child_records(array $records, array $schema, bool $lock, array &$blockers): array {
        global $wpdb;
        foreach (self::INDIRECT_CHILDREN as [$child, $column, $parent]) {
            if (!isset($schema[$child])) {
                if ($child === 'avb_transaction_allocations' && isset($schema[$parent])) {
                    throw new \RuntimeException('payment schema incomplete');
                }
                continue;
            }
            $ids = array_column($records[$wpdb->prefix . $parent]['rows'] ?? [], 'id');
            $record = self::extend($records, $child, AVPVH_Visitor_Delete_Db::ints($column, $ids), $schema, $lock);
            $records[$wpdb->prefix . $child] = $record;
            if ($child === 'avb_transaction_allocations' && $record['rows']) {
                $blockers[] = AVPVH_Visitor_Delete_Db::blocker('payment');
            }
        }
        return $records;
    }

    /** $table's record selected again with $where added to what was already selected. */
    private static function extend(array $records, string $table, string $where, array $schema, bool $lock): array {
        global $wpdb;
        $existing = $records[$wpdb->prefix . $table]['where'] ?? '';
        return AVPVH_Visitor_Delete_Db::select($table, $existing ? "($existing) OR $where" : $where, $schema, $lock);
    }

    public static function transaction_blockers(int $id, array $fee_ids, array $schema, string $suffix): array {
        global $wpdb;
        if (!isset($schema['avb_transactions'])) {
            return [];
        }
        $blockers = [];
        foreach (AVPVH_Visitor_Delete_Db::rows("SELECT id, suggested_member_ids, draft_data FROM {$wpdb->prefix}avb_transactions WHERE suggested_member_ids <> '' OR draft_data IS NOT NULL$suffix") as $transaction) {
            if (self::transaction_mentions($transaction, $id, $fee_ids)) {
                $blockers[] = __('Een banktransactie verwijst naar deze bezoeker. Laat de penningmeester die koppeling eerst controleren.', 'avpvh-members');
            }
        }
        return $blockers;
    }

    private static function transaction_mentions(array $transaction, int $id, array $fee_ids): bool {
        $suggested = array_map('trim', explode(',', $transaction['suggested_member_ids']));
        return in_array((string) $id, $suggested, true)
            || self::mentions_member(json_decode($transaction['draft_data'] ?? 'null', true), $id, $fee_ids);
    }

    public static function engine_blockers(array $records, array $schema): array {
        $blockers = [];
        foreach ($records as $record) {
            if ($record['engine'] !== 'InnoDB') {
                $blockers[] = AVPVH_Visitor_Delete_Db::blocker('engine');
            }
        }
        if ($schema['avm_members']['engine'] !== 'InnoDB') {
            $blockers[] = AVPVH_Visitor_Delete_Db::blocker('engine');
        }
        return $blockers;
    }

    /** Children first, including installations with foreign keys. */
    public static function children_first(array $records): array {
        global $wpdb;
        $children = [];
        foreach (self::DELETE_FIRST as $child) {
            $table = $wpdb->prefix . $child;
            if (isset($records[$table])) {
                $children[$table] = $records[$table];
                unset($records[$table]);
            }
        }
        return $children + $records;
    }

    private static function belongs_to_other(array $row, int $id): bool {
        return isset($row['member_id']) && (int) $row['member_id'] > 0 && (int) $row['member_id'] !== $id;
    }

    private static function is_booked_payment(string $table, array $row): bool {
        if ($table === 'avb_transaction_allocations') {
            return true;
        }
        return $table === 'avm_fees' && ((float) $row['amount_paid'] !== 0.0 || !empty($row['paid_date']) || $row['status'] === 'paid');
    }

    private static function is_fulfilled(array $row): bool {
        return isset($row['distribution_status']) && $row['distribution_status'] !== 'pending';
    }

    private static function mentions_member($value, int $id, array $fee_ids): bool {
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $key => $entry) {
            if (is_scalar($entry) && (($key === 'member_id' || str_ends_with((string) $key, '_member_id')) && (int) $entry === $id
                || ($key === 'fee_item_id' && in_array((int) $entry, array_map('intval', $fee_ids), true)))) {
                return true;
            }
            if (is_array($entry) && self::mentions_member($entry, $id, $fee_ids)) {
                return true;
            }
        }
        return false;
    }
}
