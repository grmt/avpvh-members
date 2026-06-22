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
            suffix VARCHAR(50) NOT NULL DEFAULT '',
            last_name VARCHAR(100) NOT NULL DEFAULT '',
            baptism_name VARCHAR(200) NOT NULL DEFAULT '',
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

        dbDelta("CREATE TABLE {$wpdb->prefix}avm_login_attempts (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            email VARCHAR(255) NOT NULL DEFAULT '',
            method ENUM('proxy','google','microsoft','password_reset') NOT NULL,
            result ENUM('success','no_member','hibp_warned') NOT NULL,
            ip VARCHAR(45) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY attempted_at (attempted_at),
            KEY email (email(100))
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

        // Family relationships
        dbDelta("CREATE TABLE {$wpdb->prefix}avm_families (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(200) NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset;");

        dbDelta("CREATE TABLE {$wpdb->prefix}avm_family_members (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            family_id INT UNSIGNED NOT NULL,
            member_id INT UNSIGNED NOT NULL,
            relationship VARCHAR(50) NOT NULL DEFAULT 'member',
            PRIMARY KEY (id),
            UNIQUE KEY family_member (family_id, member_id),
            KEY family_id (family_id),
            KEY member_id (member_id)
        ) $charset;");

        // Partner relationships (one-to-one, mutual)
        dbDelta("CREATE TABLE {$wpdb->prefix}avm_partners (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id_1 INT UNSIGNED NOT NULL,
            member_id_2 INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY partner_pair (member_id_1, member_id_2),
            KEY member_id_1 (member_id_1),
            KEY member_id_2 (member_id_2)
        ) $charset;");

        // Audit trail for member data changes
        dbDelta("CREATE TABLE {$wpdb->prefix}avm_member_audit_log (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id INT UNSIGNED NOT NULL,
            changed_by INT UNSIGNED NOT NULL,
            field_name VARCHAR(100) NOT NULL,
            old_value TEXT NULL,
            new_value TEXT NULL,
            changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY member_id (member_id),
            KEY changed_by (changed_by),
            KEY changed_at (changed_at)
        ) $charset;");

        // Registration for excavation campaigns (single person per email)
        dbDelta("CREATE TABLE {$wpdb->prefix}avm_registrations (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
            camp_id INT UNSIGNED NOT NULL,
            year YEAR NOT NULL,
            first_name VARCHAR(100) NOT NULL DEFAULT '',
            phone VARCHAR(30) NOT NULL DEFAULT '',
            food_allergies TEXT NULL,
            notes TEXT NULL,
            sync_status ENUM('synced','pending_push','pending_pull','conflict') NOT NULL DEFAULT 'pending_push',
            google_row_id INT UNSIGNED NULL,
            last_sync_timestamp TIMESTAMP NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY email_camp_year (email, camp_id, year),
            KEY camp_id (camp_id),
            KEY sync_status (sync_status),
            KEY email (email(100))
        ) $charset;");

        dbDelta("CREATE TABLE {$wpdb->prefix}avm_registration_attendance (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            registration_id INT UNSIGNED NOT NULL,
            date DATE NOT NULL,
            status ENUM('attending','not_attending','maybe') NOT NULL DEFAULT 'attending',
            is_nawacht TINYINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY registration_date (registration_id, date),
            KEY registration_id (registration_id),
            KEY date (date)
        ) $charset;");

        dbDelta("CREATE TABLE {$wpdb->prefix}avm_sync_conflicts (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            registration_id INT UNSIGNED NOT NULL,
            field_name VARCHAR(100) NOT NULL,
            wp_value TEXT NULL,
            sheet_value TEXT NULL,
            resolved TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY registration_id (registration_id),
            KEY resolved (resolved)
        ) $charset;");
    }

    public static function maybe_upgrade(): void {
        global $wpdb;
        $version = get_option('avpvh_db_version', '0');
        if (version_compare($version, '1.2', '<')) {
            self::install();
            update_option('avpvh_db_version', '1.2');
        }
        if (version_compare($version, '1.3', '<')) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}avm_login_attempts
                MODIFY method ENUM('proxy','google','microsoft','password_reset') NOT NULL,
                MODIFY result ENUM('success','no_member','hibp_warned') NOT NULL");
            update_option('avpvh_db_version', '1.3');
        }
        if (version_compare($version, '1.4', '<')) {
            self::install();
            update_option('avpvh_db_version', '1.4');
        }
        if (version_compare($version, '1.5', '<')) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}avm_members
                ADD COLUMN suffix VARCHAR(50) NOT NULL DEFAULT '' AFTER first_name");
            update_option('avpvh_db_version', '1.5');
        }
    }

    public static function log_attempt(string $email, string $method, string $result): void {
        // Skip syntactically invalid addresses.
        if (!is_email($email)) {
            return;
        }

        // Skip addresses whose domain has no MX or A record, or is on the disposable blacklist.
        $domain = substr(strrchr($email, '@'), 1);
        if ($domain && !checkdnsrr($domain, 'MX') && !checkdnsrr($domain, 'A')) {
            return;
        }
        $blacklist = require AVPVH_PLUGIN_DIR . 'includes/disposable-domains.php';
        if (in_array(strtolower($domain), $blacklist, true)) {
            return;
        }

        global $wpdb;
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        $ip = $forwarded ? trim(explode(',', $forwarded)[0]) : ($_SERVER['REMOTE_ADDR'] ?? '');
        $wpdb->insert(
            "{$wpdb->prefix}avm_login_attempts",
            ['email' => $email, 'method' => $method, 'result' => $result, 'ip' => $ip],
            ['%s', '%s', '%s', '%s']
        );
    }

    public static function get_login_attempts(int $limit = 200): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}avm_login_attempts ORDER BY attempted_at DESC LIMIT $limit"
        ) ?: [];
    }

    // -------------------------------------------------------------------
    // Member lookups — all JOIN with lldap.users for email
    // -------------------------------------------------------------------

    private static function member_select(): string {
        global $wpdb;
        $lldap = self::lldap();
        return "SELECT u.user_id, u.email, u.display_name,
                       m.id, m.lldap_user_id, m.wp_user_id,
                       m.first_name, m.suffix, m.last_name, m.baptism_name, m.birth_date,
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
        $join   = '';

        if (!empty($args['search'])) {
            $s      = '%' . $wpdb->esc_like($args['search']) . '%';
            $where .= ' AND (m.last_name LIKE %s OR m.first_name LIKE %s OR m.baptism_name LIKE %s OR u.email LIKE %s)';
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
        }
        if (!empty($args['status'])) {
            $where .= ' AND m.status = %s';
            $params[] = $args['status'];
        }
        if (!empty($args['joined_year'])) {
            $where .= ' AND m.joined_year = %d';
            $params[] = (int) $args['joined_year'];
        }
        $fee_year = !empty($args['fee_year']) ? (int) $args['fee_year'] : (int) date('Y');

        if (isset($args['fee_status']) && $args['fee_status'] !== '') {
            if ($args['fee_status'] === 'none') {
                $join  .= " LEFT JOIN {$wpdb->prefix}avm_fees f ON f.member_id = m.id AND f.year = $fee_year";
                $where .= ' AND f.id IS NULL';
            } else {
                $join  .= " JOIN {$wpdb->prefix}avm_fees f ON f.member_id = m.id AND f.year = $fee_year";
                $where .= ' AND f.status = %s';
                $params[] = $args['fee_status'];
            }
        }

        $allowed_order = ['last_name', 'suffix_last_name', 'first_name', 'status', 'joined_year', 'fee_status', 'camp_count'];
        $orderby = in_array($args['orderby'] ?? '', $allowed_order, true) ? $args['orderby'] : 'last_name';
        $order   = strtoupper($args['order'] ?? '') === 'DESC' ? 'DESC' : 'ASC';

        // Extra join/column for fee_status sort (only if not already joined for filtering)
        if ($orderby === 'fee_status' && !str_contains($join, 'avm_fees')) {
            $join .= " LEFT JOIN {$wpdb->prefix}avm_fees f ON f.member_id = m.id AND f.year = $fee_year";
        }

        $order_sql = match ($orderby) {
            'last_name'        => "m.last_name $order, m.first_name $order",
            'suffix_last_name' => "m.suffix $order, m.last_name $order, m.first_name $order",
            'first_name'       => "m.first_name $order, m.last_name $order",
            'status'      => "m.status $order, m.last_name ASC",
            'joined_year' => "m.joined_year $order, m.last_name ASC",
            'fee_status'  => "f.status $order, m.last_name ASC",
            'camp_count'  => "(SELECT COUNT(*) FROM {$wpdb->prefix}avm_camp_participation WHERE member_id = m.id) $order, m.last_name ASC",
            default       => "m.last_name ASC, m.first_name ASC",
        };

        $sql = self::member_select() . $join . " WHERE $where ORDER BY $order_sql";
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
                    m.id, m.first_name, m.suffix, m.last_name, m.phone, m.mobile, m.status,
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

    // -------------------------------------------------------------------
    // Family relationships
    // -------------------------------------------------------------------

    public static function get_family(int $family_id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avm_families WHERE id = %d",
            $family_id
        )) ?: null;
    }

    public static function get_family_for_member(int $member_id): ?int {
        global $wpdb;
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT family_id FROM {$wpdb->prefix}avm_family_members WHERE member_id = %d LIMIT 1",
            $member_id
        ));
        return $result ? (int) $result : null;
    }

    public static function get_family_members(int $family_id): array {
        global $wpdb;
        $lldap = self::lldap();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT m.id, m.first_name, m.last_name, m.email, fm.relationship
             FROM {$wpdb->prefix}avm_family_members fm
             JOIN {$wpdb->prefix}avm_members m ON m.id = fm.member_id
             WHERE fm.family_id = %d
             ORDER BY m.last_name, m.first_name",
            $family_id
        )) ?: [];
    }

    public static function create_family(string $name = ''): int {
        global $wpdb;
        $wpdb->insert(
            "{$wpdb->prefix}avm_families",
            ['name' => $name],
            ['%s']
        );
        return $wpdb->insert_id;
    }

    public static function add_member_to_family(int $family_id, int $member_id, string $relationship = 'member'): void {
        global $wpdb;
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}avm_family_members WHERE family_id = %d AND member_id = %d",
            $family_id, $member_id
        ));

        if ($existing) {
            $wpdb->update(
                "{$wpdb->prefix}avm_family_members",
                ['relationship' => $relationship],
                ['family_id' => $family_id, 'member_id' => $member_id],
                ['%s'],
                ['%d', '%d']
            );
        } else {
            $wpdb->insert(
                "{$wpdb->prefix}avm_family_members",
                ['family_id' => $family_id, 'member_id' => $member_id, 'relationship' => $relationship],
                ['%d', '%d', '%s']
            );
        }
    }

    public static function is_family_member(int $member_id_1, int $member_id_2): bool {
        $family_1 = self::get_family_for_member($member_id_1);
        $family_2 = self::get_family_for_member($member_id_2);

        return $family_1 && $family_1 === $family_2;
    }

    // -------------------------------------------------------------------
    // Partner relationships (couple/spouse links)
    // -------------------------------------------------------------------

    public static function get_partner(int $member_id): ?object {
        global $wpdb;
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT m.* FROM {$wpdb->prefix}avm_members m
             JOIN {$wpdb->prefix}avm_partners p ON (
                 (p.member_id_1 = %d AND m.id = p.member_id_2) OR
                 (p.member_id_2 = %d AND m.id = p.member_id_1)
             )
             LIMIT 1",
            $member_id, $member_id
        )) ?: null;
        return $result;
    }

    public static function are_partners(int $member_id_1, int $member_id_2): bool {
        global $wpdb;
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}avm_partners
             WHERE (member_id_1 = %d AND member_id_2 = %d)
                OR (member_id_1 = %d AND member_id_2 = %d)
             LIMIT 1",
            $member_id_1, $member_id_2, $member_id_2, $member_id_1
        ));
        return (bool) $result;
    }

    public static function link_partners(int $member_id_1, int $member_id_2): void {
        global $wpdb;

        // Ensure member_id_1 < member_id_2 for consistent ordering
        if ($member_id_1 > $member_id_2) {
            [$member_id_1, $member_id_2] = [$member_id_2, $member_id_1];
        }

        // Check if already linked
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}avm_partners WHERE member_id_1 = %d AND member_id_2 = %d",
            $member_id_1, $member_id_2
        ));

        if (!$existing) {
            $wpdb->insert(
                "{$wpdb->prefix}avm_partners",
                ['member_id_1' => $member_id_1, 'member_id_2' => $member_id_2],
                ['%d', '%d']
            );
        }
    }

    public static function unlink_partners(int $member_id_1, int $member_id_2): void {
        global $wpdb;

        // Ensure member_id_1 < member_id_2
        if ($member_id_1 > $member_id_2) {
            [$member_id_1, $member_id_2] = [$member_id_2, $member_id_1];
        }

        $wpdb->delete(
            "{$wpdb->prefix}avm_partners",
            ['member_id_1' => $member_id_1, 'member_id_2' => $member_id_2],
            ['%d', '%d']
        );
    }

    // -------------------------------------------------------------------
    // Audit trail for member data changes
    // -------------------------------------------------------------------

    public static function log_member_change(int $member_id, string $field_name, ?string $old_value, ?string $new_value): void {
        global $wpdb;
        $changed_by = get_current_user_id();

        $wpdb->insert(
            "{$wpdb->prefix}avm_member_audit_log",
            [
                'member_id' => $member_id,
                'changed_by' => $changed_by,
                'field_name' => $field_name,
                'old_value' => $old_value,
                'new_value' => $new_value,
            ],
            ['%d', '%d', '%s', '%s', '%s']
        );
    }

    public static function get_member_audit_log(int $member_id, int $limit = 100): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT l.*, u.user_login
             FROM {$wpdb->prefix}avm_member_audit_log l
             LEFT JOIN {$wpdb->prefix}users u ON u.ID = l.changed_by
             WHERE l.member_id = %d
             ORDER BY l.changed_at DESC
             LIMIT %d",
            $member_id, $limit
        )) ?: [];
    }

    public static function update_member_with_audit(
        int $member_id,
        array $data,
        array $format
    ): void {
        global $wpdb;

        // Get current values for comparison
        $current = self::get_member($member_id);

        // Update member
        $wpdb->update(
            "{$wpdb->prefix}avm_members",
            $data,
            ['id' => $member_id],
            $format,
            ['%d']
        );

        // Log changes
        foreach ($data as $field => $new_value) {
            $old_value = $current->$field ?? null;
            if ($old_value !== $new_value) {
                self::log_member_change($member_id, $field, (string) $old_value, (string) $new_value);
            }
        }
    }
}

function avpvh_get_member_by_wp_user(int $user_id): ?object {
    return AVPVH_DB::get_member_by_wp_user($user_id);
}

/**
 * Format a member's full name.
 *
 * 'full'         → "Germie van den Berg"
 * 'list'         → "Berg, Germie"          (sort/display by last name)
 * 'list_suffix'  → "van den Berg, Germie"  (sort/display by suffix + last name)
 */
function avpvh_format_name(object $member, string $format = 'full'): string {
    $first  = $member->first_name;
    $suffix = $member->suffix ?? '';
    $last   = $member->last_name;

    return match ($format) {
        'list'        => $suffix
                            ? "$last, $first ($suffix)"
                            : "$last, $first",
        'list_suffix' => $suffix
                            ? "$suffix $last, $first"
                            : "$last, $first",
        default       => $suffix
                            ? "$first $suffix $last"
                            : "$first $last",
    };
}
