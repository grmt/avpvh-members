<?php
defined('ABSPATH') || exit;

/**
 * Schema, SQL and message helpers for the visitor deletion classes. Every
 * identifier comes from the schema or fixed names, never from a request.
 */
class AVPVH_Visitor_Delete_Db {
    /** The member-linked tables this page knows, with their translated labels. */
    public static function table_labels(): array {
        return [
            'avm_addresses' => __('Adressen', 'avpvh-members'),
            'avm_activity_participation' => __('Activiteitdeelnames', 'avpvh-members'),
            'avm_activity_participation_days' => __('Deelnamedagen', 'avpvh-members'),
            'avm_fees' => __('Contributies', 'avpvh-members'),
            'avm_member_identities' => __('Inlogadressen', 'avpvh-members'),
            'avm_member_flag_assignments' => __('Kenmerken', 'avpvh-members'),
            'avm_relationships' => __('Relaties', 'avpvh-members'),
            'avm_member_audit_log' => __('Profielwijzigingen', 'avpvh-members'),
            'avm_member_name_aliases' => __('Naamvarianten', 'avpvh-members'),
            'avm_login_attempts' => __('Loginpogingen', 'avpvh-members'),
            'avb_member_student_years' => __('Studentjaren', 'avpvh-members'),
            'avb_fee_items' => __('Openstaande betaalposten', 'avpvh-members'),
            'avb_known_ibans' => __('Gekoppelde rekeningnummers', 'avpvh-members'),
            'avb_sheet_participation_meta' => __('Inschrijfgegevens', 'avpvh-members'),
            'avb_payment_requests' => __('Betaalverzoeken', 'avpvh-members'),
            'avb_disputes' => __('Vragen over betalingen', 'avpvh-members'),
            'avb_dispute_events' => __('Berichten over betalingen', 'avpvh-members'),
            'avb_congress_registrations' => __('Congresinschrijvingen', 'avpvh-members'),
            'avb_book_orders' => __('Boekbestellingen', 'avpvh-members'),
            'avb_orders' => __('Bestellingen', 'avpvh-members'),
            'avb_order_items' => __('Bestelregels', 'avpvh-members'),
            'avb_photo_shares' => __('Persoonlijke uploadshares', 'avpvh-members'),
            'agallery_photo_shares' => __('Gedeelde fotoselecties', 'avpvh-members'),
        ];
    }

    /** Unknown tables show their name. */
    private static function table_label(string $table): string {
        return self::table_labels()[$table] ?? $table;
    }

    /** The blocker messages used in more than one place. */
    public static function blocker(string $reason): string {
        return match ($reason) {
            'shared_email' => __('Een inlogadres is ook aan een andere persoon gekoppeld.', 'avpvh-members'),
            'other_person' => __('Gekoppelde gegevens horen ook bij een andere persoon. Verwijderen is geblokkeerd.', 'avpvh-members'),
            'payment' => __('Er zijn geboekte betalingen gekoppeld. Deze bezoeker kan niet definitief worden verwijderd.', 'avpvh-members'),
            'fulfilled' => __('Er zijn al afgehandelde bestellingen gekoppeld. Verwijderen is geblokkeerd.', 'avpvh-members'),
            'engine' => __('De gekoppelde gegevens ondersteunen geen veilige database-transactie.', 'avpvh-members'),
        };
    }

    public static function schema(): array {
        global $wpdb;
        $schema = [];
        $sql = $wpdb->prepare("SELECT c.TABLE_NAME, c.COLUMN_NAME, c.DATA_TYPE, t.ENGINE FROM information_schema.COLUMNS c JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME LIKE %s ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION", $wpdb->esc_like($wpdb->prefix) . '%');
        foreach (self::rows($sql) as $column) {
            $table = substr($column['TABLE_NAME'], strlen($wpdb->prefix));
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $column['TABLE_NAME']) || !preg_match('/^[a-zA-Z0-9_]+$/', $column['COLUMN_NAME'])) {
                throw new \RuntimeException('invalid identifier');
            }
            // WordPress core user tables are handled via its account API.
            if (in_array($column['TABLE_NAME'], [$wpdb->users, $wpdb->usermeta, $wpdb->comments], true)) {
                continue;
            }
            $schema[$table]['columns'][$column['COLUMN_NAME']] = $column['DATA_TYPE'];
            $schema[$table]['engine'] = $column['ENGINE'];
        }
        return $schema;
    }

    public static function select(string $table, string $where, array $schema, bool $lock): array {
        global $wpdb;
        if (!isset($schema[$table])) {
            return ['rows' => [], 'where' => $where, 'engine' => 'InnoDB', 'label' => self::table_label($table)];
        }
        return ['rows' => self::rows("SELECT * FROM `{$wpdb->prefix}$table` WHERE $where" . ($lock ? ' FOR UPDATE' : '')),
            'where' => $where, 'engine' => $schema[$table]['engine'], 'label' => self::table_label($table)];
    }

    public static function rows(string $sql): array {
        global $wpdb;
        $rows = $wpdb->get_results($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- built in select() from fixed table names and prepared conditions
        if ($wpdb->last_error !== '' || !is_array($rows)) {
            throw new \RuntimeException(__('De databasecontrole is mislukt.', 'avpvh-members')); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the message is escaped where it is shown
        }
        usort($rows, static fn($a, $b) => strcmp(wp_json_encode($a), wp_json_encode($b)));
        return $rows;
    }

    public static function ints(string $column, array $ids): string {
        return $ids ? "`$column` IN (" . implode(',', array_map('intval', $ids)) . ')' : '0 = 1';
    }

    public static function strings(string $column, array $values): string {
        global $wpdb;
        return $wpdb->prepare("`$column` IN (" . implode(',', array_fill(0, count($values), '%s')) . ')', ...$values);
    }

    public static function check($result): void {
        if ($result === false) {
            throw new \RuntimeException(__('De databasewijziging is mislukt.', 'avpvh-members')); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the message is escaped where it is shown
        }
    }
}
