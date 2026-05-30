<?php
defined('ABSPATH') || exit;

class AVPVH_Registration_DB {

    /**
     * Get a registration by ID.
     */
    public static function get_registration(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avm_registrations WHERE id = %d",
            $id
        )) ?: null;
    }

    /**
     * Get registration by email, camp, and year.
     */
    public static function get_registration_by_email(string $email, int $camp_id, int $year): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avm_registrations WHERE email = %s AND camp_id = %d AND year = %d",
            $email, $camp_id, $year
        )) ?: null;
    }

    /**
     * Get all registrations for a camp year.
     */
    public static function get_registrations_for_camp(int $camp_id, int $year): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avm_registrations WHERE camp_id = %d AND year = %d ORDER BY created_at DESC",
            $camp_id, $year
        )) ?: [];
    }

    /**
     * Get registrations with pending sync.
     */
    public static function get_pending_sync_registrations(int $limit = 100): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}avm_registrations
             WHERE sync_status IN ('pending_push', 'pending_pull', 'conflict')
             ORDER BY updated_at ASC
             LIMIT $limit"
        ) ?: [];
    }

    /**
     * Create or update a registration.
     */
    public static function save_registration(
        string $email,
        int $camp_id,
        int $year,
        string $first_name = '',
        string $phone = '',
        ?string $food_allergies = null,
        ?string $notes = null,
        string $sync_status = 'pending_push',
        ?int $google_row_id = null
    ): int {
        global $wpdb;

        $existing = self::get_registration_by_email($email, $camp_id, $year);

        $data = [
            'email' => $email,
            'camp_id' => $camp_id,
            'year' => $year,
            'first_name' => $first_name,
            'phone' => $phone,
            'food_allergies' => $food_allergies,
            'notes' => $notes,
            'sync_status' => $sync_status,
            'google_row_id' => $google_row_id,
            'last_sync_timestamp' => current_time('mysql'),
        ];

        $format = ['%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s'];

        if ($existing) {
            $wpdb->update(
                "{$wpdb->prefix}avm_registrations",
                $data,
                ['id' => $existing->id],
                $format,
                ['%d']
            );
            return $existing->id;
        } else {
            $wpdb->insert("{$wpdb->prefix}avm_registrations", $data, $format);
            return $wpdb->insert_id;
        }
    }

    /**
     * Update registration sync status.
     */
    public static function update_sync_status(int $registration_id, string $status): void {
        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}avm_registrations",
            ['sync_status' => $status, 'last_sync_timestamp' => current_time('mysql')],
            ['id' => $registration_id],
            ['%s', '%s'],
            ['%d']
        );
    }

    /**
     * Get attendance for a registration on a specific date.
     */
    public static function get_attendance(int $registration_id, string $date): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avm_registration_attendance WHERE registration_id = %d AND date = %s",
            $registration_id, $date
        )) ?: null;
    }

    /**
     * Get all attendance for a registration.
     */
    public static function get_registration_attendance(int $registration_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avm_registration_attendance WHERE registration_id = %d ORDER BY date ASC",
            $registration_id
        )) ?: [];
    }

    /**
     * Save attendance for a registration on a date.
     */
    public static function save_attendance(
        int $registration_id,
        string $date,
        string $status = 'attending',
        bool $is_nawacht = false
    ): int {
        global $wpdb;

        $existing = self::get_attendance($registration_id, $date);

        $data = [
            'registration_id' => $registration_id,
            'date' => $date,
            'status' => $status,
            'is_nawacht' => $is_nawacht ? 1 : 0,
        ];

        $format = ['%d', '%s', '%s', '%d'];

        if ($existing) {
            $wpdb->update(
                "{$wpdb->prefix}avm_registration_attendance",
                $data,
                ['id' => $existing->id],
                $format,
                ['%d']
            );
            return $existing->id;
        } else {
            $wpdb->insert("{$wpdb->prefix}avm_registration_attendance", $data, $format);
            return $wpdb->insert_id;
        }
    }

    /**
     * Log a sync conflict.
     */
    public static function log_conflict(int $registration_id, string $field_name, ?string $wp_value, ?string $sheet_value): void {
        global $wpdb;
        $wpdb->insert(
            "{$wpdb->prefix}avm_sync_conflicts",
            [
                'registration_id' => $registration_id,
                'field_name' => $field_name,
                'wp_value' => $wp_value,
                'sheet_value' => $sheet_value,
            ],
            ['%d', '%s', '%s', '%s']
        );
    }

    /**
     * Get unresolved conflicts for a registration.
     */
    public static function get_conflicts(int $registration_id, bool $unresolved_only = true): array {
        global $wpdb;
        $where = "registration_id = %d";
        $params = [$registration_id];

        if ($unresolved_only) {
            $where .= " AND resolved = 0";
        }

        $sql = $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avm_sync_conflicts WHERE $where ORDER BY created_at DESC",
            $params
        );
        return $wpdb->get_results($sql) ?: [];
    }

    /**
     * Mark conflict as resolved.
     */
    public static function resolve_conflict(int $conflict_id): void {
        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}avm_sync_conflicts",
            ['resolved' => 1],
            ['id' => $conflict_id],
            ['%d'],
            ['%d']
        );
    }
}
