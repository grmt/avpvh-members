<?php
/**
 * Date legacy membership-administration addresses without changing their content.
 * Run inside WordPress. Dry-run/backup (keep the JSON outside the repository):
 *   wp eval 'require "scripts/backfill-imported-address-dates.php"; echo json_encode(AVPVH_Imported_Address_Dates::plan());' > /tmp/address-dates.local.json
 * Apply exactly that reviewed snapshot:
 *   wp eval 'require "scripts/backfill-imported-address-dates.php"; echo json_encode(AVPVH_Imported_Address_Dates::apply("/tmp/address-dates.local.json"));'
 * No plugin deployment or identity synchronization is needed.
 */

defined('ABSPATH') || exit;

final class AVPVH_Imported_Address_Dates
{
    private const START = '2025-01-01';

    public static function plan(bool $lock = false): array
    {
        global $wpdb;
        $addresses = $wpdb->prefix . 'avm_addresses';
        $members = $wpdb->prefix . 'avm_members';
        $rows = $wpdb->get_results(
            "SELECT a.id, a.member_id, a.valid_from, a.valid_until, m.status
             FROM $addresses a JOIN $members m ON m.id = a.member_id
             WHERE m.status IN ('active', 'inactive')
               AND (a.valid_from IS NULL OR a.valid_from = '0000-00-00')
             ORDER BY a.id" . ($lock ? ' FOR UPDATE' : ''),
            ARRAY_A
        );
        self::check_query();
        foreach ($rows as $row) {
            if ($row['valid_until'] !== null && $row['valid_until'] < self::START) {
                throw new RuntimeException('An undated address ends before the baseline; review its history first');
            }
        }
        // Include every dated update in the guard, also those of visitors.
        $newer = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, member_id, valid_from, valid_until FROM $addresses
                 WHERE valid_from > %s ORDER BY id",
                self::START
            ),
            ARRAY_A
        );
        self::check_query();
        $all_addresses = $wpdb->get_results("SELECT * FROM $addresses ORDER BY id", ARRAY_A);
        self::check_query();
        $eligible_ids = array_fill_keys(array_column($rows, 'id'), true);
        foreach ($all_addresses as &$address) {
            if (isset($eligible_ids[$address['id']])) {
                $address['valid_from'] = self::START;
            }
        }
        unset($address);
        return [
            'valid_from' => self::START,
            'rows' => $rows,
            'newer_sha256' => hash('sha256', json_encode($newer, JSON_THROW_ON_ERROR)),
            'addresses_sha256' => hash('sha256', json_encode($all_addresses, JSON_THROW_ON_ERROR)),
        ];
    }

    public static function apply(string $snapshot_path): array
    {
        global $wpdb;
        $saved = json_decode((string) file_get_contents($snapshot_path), true, 512, JSON_THROW_ON_ERROR);
        $addresses = $wpdb->prefix . 'avm_addresses';
        $members = $wpdb->prefix . 'avm_members';
        // Rollback is a requirement, not a best-effort fallback on MyISAM.
        foreach ([$addresses, $members] as $table) {
            $engine = $wpdb->get_var($wpdb->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $table
            ));
            self::check_query();
            if (strtolower((string) $engine) !== 'innodb') {
                throw new RuntimeException('Address backfill requires InnoDB tables');
            }
        }
        self::query('START TRANSACTION');
        try {
            $current = self::plan(true);
            if ($saved !== $current) {
                throw new RuntimeException('Address data changed since the snapshot; make a fresh backup first');
            }
            $updated = 0;
            foreach ($current['rows'] as $row) {
                $affected = $wpdb->query($wpdb->prepare(
                    "UPDATE $addresses SET valid_from = %s WHERE id = %d
                     AND (valid_from IS NULL OR valid_from = '0000-00-00')",
                    self::START,
                    $row['id']
                ));
                self::check_query();
                if ($affected !== 1) {
                    throw new RuntimeException('An address changed during the backfill');
                }
                $updated++;
            }
            $after = self::plan();
            if (
                $after['rows']
                || $after['newer_sha256'] !== $current['newer_sha256']
                || $after['addresses_sha256'] !== $current['addresses_sha256']
            ) {
                throw new RuntimeException('Address backfill verification failed');
            }
            self::query('COMMIT');
            return ['updated' => $updated, 'valid_from' => self::START, 'newer_updates_preserved' => true];
        } catch (Throwable $exception) {
            $wpdb->query('ROLLBACK');
            throw $exception;
        }
    }

    private static function query(string $sql): void
    {
        global $wpdb;
        if ($wpdb->query($sql) === false) {
            throw new RuntimeException('Address backfill database query failed');
        }
    }

    private static function check_query(): void
    {
        global $wpdb;
        if ($wpdb->last_error !== '') {
            throw new RuntimeException('Address backfill database query failed');
        }
    }
}
