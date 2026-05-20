<?php
defined('ABSPATH') || exit;

class AVPVH_DB {

    private static function lldap(): string {
        return AVPVH_LLDAP_DB;
    }

    public static function install(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // pvh_avm_members: business data only — email lives in lldap.users
        dbDelta("CREATE TABLE {$wpdb->prefix}avm_members (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            lldap_user_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
            wp_user_id INT UNSIGNED NULL,
            first_name VARCHAR(100) NOT NULL DEFAULT '',
            last_name VARCHAR(100) NOT NULL DEFAULT '',
            birth_date DATE NULL,
            phone VARCHAR(30) NOT NULL DEFAULT '',
            mobile VARCHAR(30) NOT NULL DEFAULT '',
            emergency_contact VARCHAR(200) NOT NULL DEFAULT '',
            status ENUM('active','inactive','visitor') NOT NULL DEFAULT 'active',
            joined_year YEAR NULL,
            left_year YEAR NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY lldap_user_id (lldap_user_id),
            KEY wp_user_id (wp_user_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$wpdb->prefix}avm_addresses (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id INT UNSIGNED NOT NULL,
            street VARCHAR(150) NOT NULL DEFAULT '',
            house_number VARCHAR(20) NOT NULL DEFAULT '',
            postal_code VARCHAR(20) NOT NULL DEFAULT '',
            city VARCHAR(100) NOT NULL DEFAULT '',
            country VARCHAR(100) NOT NULL DEFAULT 'Nederland',
            valid_from DATE NULL,
            valid_until DATE NULL,
            PRIMARY KEY (id),
            KEY member_id (member_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$wpdb->prefix}avm_camps (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(150) NOT NULL DEFAULT '',
            year YEAR NOT NULL,
            location VARCHAR(150) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            UNIQUE KEY name_year (name, year)
        ) $charset;");

        dbDelta("CREATE TABLE {$wpdb->prefix}avm_camp_participation (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id INT UNSIGNED NOT NULL,
            camp_id INT UNSIGNED NOT NULL,
            nights TINYINT UNSIGNED NULL,
            nawacht TINYINT UNSIGNED NOT NULL DEFAULT 0,
            diet VARCHAR(50) NULL,
            notes TEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY member_camp (member_id, camp_id),
            KEY camp_id (camp_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$wpdb->prefix}avm_fees (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id INT UNSIGNED NOT NULL,
            year SMALLINT UNSIGNED NOT NULL,
            amount_due DECIMAL(8,2) NULL,
            amount_paid DECIMAL(8,2) NULL,
            paid_date DATE NULL,
            status ENUM('paid','pending','waived') NOT NULL DEFAULT 'pending',
            PRIMARY KEY (id),
            UNIQUE KEY member_year (member_id, year),
            KEY year (year)
        ) $charset;");
    }

    // -------------------------------------------------------------------
    // Member lookups — all JOIN with lldap.users for email
    // -------------------------------------------------------------------

    private static function member_select(): string {
        global $wpdb;
        $lldap = self::lldap();
        return "SELECT u.user_id, u.email, u.display_name,
                       m.id, m.lldap_user_id, m.wp_user_id,
                       m.first_name, m.last_name, m.birth_date,
                       m.phone, m.mobile, m.emergency_contact,
                       m.status, m.joined_year, m.left_year,
                       m.created_at, m.updated_at
                FROM {$lldap}.users u
                JOIN {$wpdb->prefix}avm_members m ON m.lldap_user_id = u.user_id";
    }

    public static function get_member_by_lldap_uid(string $uid): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            self::member_select() . " WHERE u.user_id = %s LIMIT 1",
            $uid
        )) ?: null;
    }

    public static function get_member_by_email(string $email): ?object {
        global $wpdb;
        $lldap = self::lldap();
        return $wpdb->get_row($wpdb->prepare(
            self::member_select() . " WHERE u.lowercase_email = LOWER(%s) LIMIT 1",
            $email
        )) ?: null;
    }

    public static function get_member_by_wp_user(int $user_id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            self::member_select() . " WHERE m.wp_user_id = %d LIMIT 1",
            $user_id
        )) ?: null;
    }

    public static function get_member(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            self::member_select() . " WHERE m.id = %d",
            $id
        )) ?: null;
    }

    public static function get_members(array $args = []): array {
        global $wpdb;
        $lldap  = self::lldap();
        $where  = '1=1';
        $params = [];

        if (!empty($args['search'])) {
            $s      = '%' . $wpdb->esc_like($args['search']) . '%';
            $where .= ' AND (m.last_name LIKE %s OR m.first_name LIKE %s OR u.email LIKE %s)';
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
        }
        if (!empty($args['status'])) {
            $where .= ' AND m.status = %s';
            $params[] = $args['status'];
        }

        $sql = self::member_select() . " WHERE $where ORDER BY m.last_name, m.first_name";
        if ($params) {
            $sql = $wpdb->prepare($sql, $params);
        }
        return $wpdb->get_results($sql) ?: [];
    }

    public static function set_wp_user_id(int $member_id, int $wp_user_id): void {
        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}avm_members",
            ['wp_user_id' => $wp_user_id],
            ['id' => $member_id],
            ['%d'], ['%d']
        );
    }

    // -------------------------------------------------------------------
    // Address / camp / fee helpers — unchanged from original
    // -------------------------------------------------------------------

    public static function get_members_with_address(): array {
        global $wpdb;
        $lldap = self::lldap();
        $today = current_time('Y-m-d');
        return $wpdb->get_results($wpdb->prepare(
            "SELECT u.email,
                    m.id, m.first_name, m.last_name, m.phone, m.mobile, m.status,
                    a.street, a.house_number, a.postal_code, a.city, a.country
             FROM {$lldap}.users u
             JOIN {$wpdb->prefix}avm_members m ON m.lldap_user_id = u.user_id
             LEFT JOIN {$wpdb->prefix}avm_addresses a ON a.member_id = m.id
               AND (a.valid_from IS NULL OR a.valid_from <= %s)
               AND (a.valid_until IS NULL OR a.valid_until >= %s)
             WHERE m.status = 'active'
             ORDER BY m.last_name, m.first_name",
            $today, $today
        )) ?: [];
    }

    public static function get_addresses(int $member_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avm_addresses WHERE member_id = %d ORDER BY valid_from DESC",
            $member_id
        )) ?: [];
    }

    public static function get_camps_for_member(int $member_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT c.*, p.nights, p.nawacht, p.diet, p.notes
             FROM {$wpdb->prefix}avm_camp_participation p
             JOIN {$wpdb->prefix}avm_camps c ON c.id = p.camp_id
             WHERE p.member_id = %d
             ORDER BY c.year DESC",
            $member_id
        )) ?: [];
    }

    public static function get_fees_for_member(int $member_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avm_fees WHERE member_id = %d ORDER BY year DESC",
            $member_id
        )) ?: [];
    }

    public static function get_fee_for_year(int $member_id, int $year): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avm_fees WHERE member_id = %d AND year = %d",
            $member_id, $year
        )) ?: null;
    }

    public static function mark_fee_paid(int $fee_id): void {
        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}avm_fees",
            ['status' => 'paid', 'paid_date' => current_time('Y-m-d')],
            ['id' => $fee_id],
            ['%s', '%s'], ['%d']
        );
    }
}

function avpvh_get_member_by_wp_user(int $user_id): ?object {
    return AVPVH_DB::get_member_by_wp_user($user_id);
}
