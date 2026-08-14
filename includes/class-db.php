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
            passport_name VARCHAR(200) NOT NULL DEFAULT '',
            initials VARCHAR(20) NOT NULL DEFAULT '',
            birth_date DATE NULL,
            birth_year SMALLINT UNSIGNED NULL,
            is_student TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            phone VARCHAR(30) NOT NULL DEFAULT '',
            mobile VARCHAR(30) NOT NULL DEFAULT '',
            emergency_contact VARCHAR(200) NOT NULL DEFAULT '',
            diet VARCHAR(255) NOT NULL DEFAULT '',
            status ENUM('active','inactive','visitor') NOT NULL DEFAULT 'active',
            joined_year YEAR NULL,
            left_year YEAR NULL,
            directory_consent ENUM('pending','granted','declined') NOT NULL DEFAULT 'granted',
            directory_consent_at TIMESTAMP NULL,
            share_email TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            share_phone TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            share_address TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            share_camp_history TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
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

        // Activity types (Kamp/Weekend/Uitje/...) — club admins can rename or
        // add types via the Kampdeelname screen, so this is a real editable
        // table rather than a fixed ENUM. Seeded with the original 6 default
        // types in the 2.6 migration below (install() alone can't seed rows
        // safely on repeat activation — dbDelta only handles structure).
        dbDelta("CREATE TABLE {$wpdb->prefix}avm_activity_types (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(50) NOT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY name (name)
        ) $charset;");

        dbDelta("CREATE TABLE {$wpdb->prefix}avm_camps (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(150) NOT NULL DEFAULT '',
            type_id INT UNSIGNED NULL,
            year YEAR NOT NULL,
            kenmerk VARCHAR(150) NOT NULL DEFAULT '',
            start_date DATE NULL,
            end_date DATE NULL,
            PRIMARY KEY (id),
            UNIQUE KEY name_year (name, year),
            KEY type_id (type_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$wpdb->prefix}avm_camp_participation (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id INT UNSIGNED NOT NULL,
            camp_id INT UNSIGNED NOT NULL,
            nights TINYINT UNSIGNED NULL,
            nawacht TINYINT UNSIGNED NOT NULL DEFAULT 0,
            diet TEXT NULL,
            notes TEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY member_camp (member_id, camp_id),
            KEY camp_id (camp_id)
        ) $charset;");

        // Day-by-day attendance per participation record — one row per date
        // a member is (or might be) present. Replaces the old, unlinked
        // avm_registration_attendance (which tracked a raw e-mail, not a
        // real member) with the same idea properly tied to a member.
        dbDelta("CREATE TABLE {$wpdb->prefix}avm_camp_participation_days (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            participation_id INT UNSIGNED NOT NULL,
            date DATE NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            UNIQUE KEY participation_date (participation_id, date)
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

        dbDelta("CREATE TABLE {$wpdb->prefix}avm_member_identities (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id INT UNSIGNED NOT NULL,
            provider ENUM('email','google','microsoft') NOT NULL DEFAULT 'email',
            email VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
            is_primary TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY email (email(191)),
            KEY member_id (member_id)
        ) $charset;");

        // Relationships — see the "Relationships" section below for the
        // full design note. label_id points at avm_relationship_labels,
        // seeded in the 2.5 migration (install() alone can't seed rows
        // safely on repeat activation — dbDelta only handles structure).
        dbDelta("CREATE TABLE {$wpdb->prefix}avm_relationship_labels (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(20) NOT NULL,
            label VARCHAR(50) NOT NULL,
            category VARCHAR(20) NOT NULL,
            inverse_id INT UNSIGNED NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY code (code)
        ) $charset;");

        dbDelta("CREATE TABLE {$wpdb->prefix}avm_relationships (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id INT UNSIGNED NOT NULL,
            related_member_id INT UNSIGNED NOT NULL,
            label_id INT UNSIGNED NOT NULL,
            valid_from DATE NULL,
            valid_until DATE NULL,
            note VARCHAR(255) NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by INT UNSIGNED NULL,
            PRIMARY KEY (id),
            KEY member_id (member_id),
            KEY related_member_id (related_member_id),
            KEY label_id (label_id)
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

        // Temporary role handoffs (e.g. penningmeester/voorzitter during
        // camp) — see AVPVH_Roles. The real role lives in LLDAP group
        // membership; this table only layers a time-boxed exception on top,
        // so the LLDAP group itself never needs to change for a handoff.
        dbDelta("CREATE TABLE {$wpdb->prefix}avm_role_delegations (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            role VARCHAR(30) NOT NULL,
            delegated_to_member_id INT UNSIGNED NOT NULL,
            delegated_by_member_id INT UNSIGNED NOT NULL,
            starts_at DATETIME NOT NULL,
            ends_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY role_active (role, delegated_to_member_id, ends_at)
        ) $charset;");

        // Note: avm_registrations / avm_registration_attendance /
        // avm_sync_conflicts (a never-launched Google-Forms-based signup +
        // sync system, unlinked to real members) were removed in favour of
        // avm_camp_participation(_days) above — see the 2.1 migration below,
        // which drops them for existing installs.
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
        if (version_compare($version, '1.6', '<')) {
            self::install();
            update_option('avpvh_db_version', '1.6');
        }
        if (version_compare($version, '1.7', '<')) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}avm_members
                ADD COLUMN directory_consent ENUM('pending','granted','declined') NOT NULL DEFAULT 'pending' AFTER emergency_contact,
                ADD COLUMN directory_consent_at TIMESTAMP NULL AFTER directory_consent,
                ADD COLUMN share_email TINYINT(1) UNSIGNED NOT NULL DEFAULT 1 AFTER directory_consent_at,
                ADD COLUMN share_phone TINYINT(1) UNSIGNED NOT NULL DEFAULT 1 AFTER share_email,
                ADD COLUMN share_address TINYINT(1) UNSIGNED NOT NULL DEFAULT 1 AFTER share_phone,
                ADD COLUMN share_camp_history TINYINT(1) UNSIGNED NOT NULL DEFAULT 1 AFTER share_address");
            update_option('avpvh_db_version', '1.7');
        }
        if (version_compare($version, '1.8', '<')) {
            // The published privacy statement already establishes that the member
            // directory is shared with all members — flip from opt-in to opt-out.
            // One-time backfill: undecided members become shared; real declines stand.
            $wpdb->query("UPDATE {$wpdb->prefix}avm_members
                SET directory_consent = 'granted' WHERE directory_consent = 'pending'");
            $wpdb->query("ALTER TABLE {$wpdb->prefix}avm_members
                MODIFY directory_consent ENUM('pending','granted','declined') NOT NULL DEFAULT 'granted'");
            update_option('avpvh_db_version', '1.8');
        }
        if (version_compare($version, '1.9', '<')) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}avm_members
                ADD COLUMN passport_name VARCHAR(200) NOT NULL DEFAULT '' AFTER baptism_name,
                ADD COLUMN family_relation_member_id INT UNSIGNED NULL AFTER emergency_contact,
                ADD COLUMN diet VARCHAR(255) NOT NULL DEFAULT '' AFTER family_relation_member_id,
                ADD KEY family_relation_member_id (family_relation_member_id)");
            update_option('avpvh_db_version', '1.9');
        }
        if (version_compare($version, '2.0', '<')) {
            // Members may now have up to 3 identities in total regardless of
            // provider (e.g. two Google-verified addresses), rather than
            // exactly one per provider — drop the per-provider slot limit,
            // and make plain e-mail address uniqueness global instead of
            // scoped to provider (an address can never belong to more than
            // one member, whichever method verified it). The original schema
            // also had a separate plain KEY named "email" — must go too, or
            // adding the new UNIQUE KEY of the same name collides with it.
            $wpdb->query("ALTER TABLE {$wpdb->prefix}avm_member_identities
                DROP INDEX member_provider,
                DROP INDEX provider_email,
                DROP INDEX email,
                ADD UNIQUE KEY email (email(191))");
            update_option('avpvh_db_version', '2.0');
        }
        if (version_compare($version, '2.1', '<')) {
            // The Google-Sheets-sync registration system (avm_registrations /
            // avm_registration_attendance / avm_sync_conflicts) was never
            // launched (sync was never configured, 0 rows) and is superseded
            // by avm_camp_participation(_days), which is properly linked to
            // real members. Drop the old tables so the data model only
            // exists once.
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}avm_registration_attendance");
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}avm_sync_conflicts");
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}avm_registrations");
            update_option('avpvh_db_version', '2.1');
        }
        if (version_compare($version, '2.2', '<')) {
            // Needed to render/edit day-by-day attendance for a camp (the
            // "Kampdeelname" screen) without hardcoding the date range.
            $wpdb->query("ALTER TABLE {$wpdb->prefix}avm_camps
                ADD COLUMN start_date DATE NULL AFTER location,
                ADD COLUMN end_date DATE NULL AFTER start_date");
            update_option('avpvh_db_version', '2.2');
        }
        if (version_compare($version, '2.3', '<')) {
            // avm_camp_participation_days was added to install() alongside
            // avm_camp_participation, but install() only runs on activation
            // — already-active sites never got it. dbDelta is safe to call
            // standalone for one table.
            $charset = $wpdb->get_charset_collate();
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta("CREATE TABLE {$wpdb->prefix}avm_camp_participation_days (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                participation_id INT UNSIGNED NOT NULL,
                date DATE NOT NULL,
                status VARCHAR(10) NOT NULL DEFAULT '',
                PRIMARY KEY (id),
                UNIQUE KEY participation_date (participation_id, date)
            ) $charset;");
            update_option('avpvh_db_version', '2.3');
        }
        if (version_compare($version, '2.4', '<')) {
            // VARCHAR(50) was too narrow for real free-text diet notes
            // (some run well past 50 characters) — widen to match the
            // free-text notes column next to it.
            $wpdb->query("ALTER TABLE {$wpdb->prefix}avm_camp_participation MODIFY diet TEXT NULL");
            update_option('avpvh_db_version', '2.4');
        }
        if (version_compare($version, '2.5', '<')) {
            self::migrate_to_relationships_table();
            update_option('avpvh_db_version', '2.5');
        }
        if (version_compare($version, '2.6', '<')) {
            // Broadens avm_camps beyond just the annual dig — a "kamp" row's
            // real dates are also the natural period for a temporary voogd
            // assignment (see the relationships add-form's activity picker),
            // and covers weekends/outings too now, not just kampen. Types
            // live in their own table (avm_activity_types) rather than a
            // fixed ENUM, so club admins can rename or add types later.
            self::migrate_to_activity_types_table();
            update_option('avpvh_db_version', '2.6');
        }
        if (version_compare($version, '2.7', '<')) {
            // Doopnaam (baptism_name) was a separate field from paspoortnaam
            // but never actually used for anything distinct — fold any
            // existing data into passport_name and drop the column.
            $column_exists = $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}avm_members LIKE 'baptism_name'");
            if ($column_exists) {
                $wpdb->query("UPDATE {$wpdb->prefix}avm_members
                    SET passport_name = baptism_name
                    WHERE baptism_name != '' AND passport_name = ''");
                $wpdb->query("ALTER TABLE {$wpdb->prefix}avm_members DROP COLUMN baptism_name");
            }
            update_option('avpvh_db_version', '2.7');
        }
        if (version_compare($version, '2.8', '<')) {
            // Passport name must read exactly as printed in the passport —
            // strip parenthetical nicknames/notes left over from the old
            // doopnaam data folded in during 2.7 (e.g. "Frank (Franciscus
            // Maria Henricus)" -> "Frank Franciscus Maria Henricus").
            $rows = $wpdb->get_results(
                "SELECT id, passport_name FROM {$wpdb->prefix}avm_members
                 WHERE passport_name LIKE '%(%' OR passport_name LIKE '%)%'
                    OR passport_name LIKE '%[%' OR passport_name LIKE '%]%'
                    OR passport_name LIKE '%{%' OR passport_name LIKE '%}%'"
            );
            foreach ($rows as $row) {
                $cleaned = trim(preg_replace('/\s+/', ' ', preg_replace('/[()\[\]{}]/', '', $row->passport_name)));
                $wpdb->update(
                    "{$wpdb->prefix}avm_members",
                    ['passport_name' => $cleaned],
                    ['id' => $row->id],
                    ['%s'], ['%d']
                );
            }
            update_option('avpvh_db_version', '2.8');
        }
        if (version_compare($version, '2.9', '<')) {
            // avm_role_delegations was added to install() alongside the rest
            // of the schema, but install() only runs on activation —
            // already-active sites never got it. dbDelta is safe to call
            // standalone for one table.
            $charset = $wpdb->get_charset_collate();
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta("CREATE TABLE {$wpdb->prefix}avm_role_delegations (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                role VARCHAR(30) NOT NULL,
                delegated_to_member_id INT UNSIGNED NOT NULL,
                delegated_by_member_id INT UNSIGNED NOT NULL,
                starts_at DATETIME NOT NULL,
                ends_at DATETIME NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY role_active (role, delegated_to_member_id, ends_at)
            ) $charset;");
            update_option('avpvh_db_version', '2.9');
        }
        if (version_compare($version, '2.10', '<')) {
            // Scholier/student is a status the contribution rate depends on
            // (avpvh-bookkeeping) but that can't be derived from age alone.
            $column_exists = $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}avm_members LIKE 'is_student'");
            if (!$column_exists) {
                $wpdb->query("ALTER TABLE {$wpdb->prefix}avm_members
                    ADD COLUMN is_student TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER birth_date");
            }
            update_option('avpvh_db_version', '2.10');
        }
        if (version_compare($version, '2.11', '<')) {
            // Bank-account holder names are routinely printed as initials +
            // surname ("A B C Voorbeeld", "D E F Voorbeeld") — a separate field
            // from passport_name (a full legal name, mostly still unfilled)
            // gives avpvh-bookkeeping's matcher a direct, reliable target,
            // and can be auto-backfilled from confirmed bank transactions.
            $column_exists = $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}avm_members LIKE 'initials'");
            if (!$column_exists) {
                $wpdb->query("ALTER TABLE {$wpdb->prefix}avm_members
                    ADD COLUMN initials VARCHAR(20) NOT NULL DEFAULT '' AFTER passport_name");
            }
            update_option('avpvh_db_version', '2.11');
        }
        if (version_compare($version, '2.12', '<')) {
            // Some members' exact birth date will genuinely never be known
            // (old records, lost paperwork) but the year often is — a
            // real, if imprecise, age beats avpvh-bookkeeping's
            // no-date-at-all "assume adult" fallback. Only used when
            // birth_date itself is empty (see the profile form's sanitizer).
            $column_exists = $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}avm_members LIKE 'birth_year'");
            if (!$column_exists) {
                $wpdb->query("ALTER TABLE {$wpdb->prefix}avm_members
                    ADD COLUMN birth_year SMALLINT UNSIGNED NULL AFTER birth_date");
            }
            update_option('avpvh_db_version', '2.12');
        }
        if (version_compare($version, '2.13', '<')) {
            // "Everything you can be asked to contribute money for is an
            // activity" — contribution, drank, t-shirts etc. join camps as
            // named activity types, not just Kamp/Weekend/Uitje/Wandeling/
            // Feest/Anders. Skip-if-exists, same pattern as the original
            // seed in migrate_to_activity_types_table() below.
            foreach (['Contributie', 'Drank', 'Eten', 'Boek', 'T-shirt', 'Congres'] as $name) {
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}avm_activity_types WHERE name = %s", $name
                ));
                if (!$existing) {
                    $max_sort = (int) $wpdb->get_var("SELECT COALESCE(MAX(sort_order), 0) FROM {$wpdb->prefix}avm_activity_types");
                    $wpdb->insert("{$wpdb->prefix}avm_activity_types", ['name' => $name, 'sort_order' => $max_sort + 1]);
                }
            }

            // location -> kenmerk: not every activity has a physical
            // location (contribution, a drank-tab) — kenmerk is a generic
            // "whatever identifies this activity" field, still free text.
            $column_exists = $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}avm_camps LIKE 'kenmerk'");
            if (!$column_exists) {
                $wpdb->query("ALTER TABLE {$wpdb->prefix}avm_camps CHANGE location kenmerk VARCHAR(150) NOT NULL DEFAULT ''");
            }
            update_option('avpvh_db_version', '2.13');
        }
    }

    // One-time migration (2026-07-25): replaces the three separate, mostly-
    // unused relationship mechanisms (avm_families/avm_family_members,
    // avm_partners, and the untyped family_relation_member_id column) with
    // the single avm_relationships table. Real data existed in all three on
    // the live site at migration time, so this carries it forward rather
    // than just dropping it — see class-member-profile-form.php's git log
    // for the manual confirmations (surname-guessing got two of the five
    // untyped links wrong) this migration's hardcoded mapping is based on.
    private static function migrate_to_relationships_table(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE {$wpdb->prefix}avm_relationship_labels (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(20) NOT NULL,
            label VARCHAR(50) NOT NULL,
            category VARCHAR(20) NOT NULL,
            inverse_id INT UNSIGNED NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY code (code)
        ) $charset;");

        dbDelta("CREATE TABLE {$wpdb->prefix}avm_relationships (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id INT UNSIGNED NOT NULL,
            related_member_id INT UNSIGNED NOT NULL,
            label_id INT UNSIGNED NOT NULL,
            valid_from DATE NULL,
            valid_until DATE NULL,
            note VARCHAR(255) NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by INT UNSIGNED NULL,
            PRIMARY KEY (id),
            KEY member_id (member_id),
            KEY related_member_id (related_member_id),
            KEY label_id (label_id)
        ) $charset;");

        // Seed labels (idempotent — safe to re-run).
        $labels = [
            // code         label             category
            ['ouder',      'ouder van',      'ouder_kind'],
            ['kind',       'kind van',       'ouder_kind'],
            ['partner',    'partner van',    'partner'],
            ['vriend',     'vriend van',     'partner'],
            ['vriendin',   'vriendin van',   'partner'],
            ['man',        'man van',        'partner'],
            ['vrouw',      'vrouw van',      'partner'],
            ['huisgenoot', 'huisgenoot van', 'huisgenoot'],
            ['voogd',      'voogd van',      'voogd'],
            ['pupil',      'pupil van',      'voogd'],
        ];
        $ids = [];
        foreach ($labels as [$code, $label, $category]) {
            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}avm_relationship_labels WHERE code = %s", $code
            ));
            if ($existing_id) {
                $ids[$code] = (int) $existing_id;
                continue;
            }
            $wpdb->insert(
                "{$wpdb->prefix}avm_relationship_labels",
                ['code' => $code, 'label' => $label, 'category' => $category],
                ['%s', '%s', '%s']
            );
            $ids[$code] = (int) $wpdb->insert_id;
        }
        $inverse_pairs = [
            'ouder' => 'kind', 'kind' => 'ouder',
            'partner' => 'partner',
            'vriend' => 'vriendin', 'vriendin' => 'vriend',
            'man' => 'vrouw', 'vrouw' => 'man',
            'huisgenoot' => 'huisgenoot',
            'voogd' => 'pupil', 'pupil' => 'voogd',
        ];
        foreach ($inverse_pairs as $code => $inverse_code) {
            $wpdb->update(
                "{$wpdb->prefix}avm_relationship_labels",
                ['inverse_id' => $ids[$inverse_code]],
                ['id' => $ids[$code]],
                ['%d'], ['%d']
            );
        }

        // Only migrate real data if the old tables still exist (a fresh
        // install created after this version never had them).
        $old_tables_exist = (bool) $wpdb->get_var(
            "SHOW TABLES LIKE '{$wpdb->prefix}avm_family_members'"
        );
        if ($old_tables_exist) {
            // avm_family_members: each family's 'parent'-tagged rows become
            // a parent->child edge to each of that family's 'child'-tagged
            // rows.
            $family_ids = $wpdb->get_col("SELECT DISTINCT family_id FROM {$wpdb->prefix}avm_family_members");
            foreach ($family_ids as $family_id) {
                $members = $wpdb->get_results($wpdb->prepare(
                    "SELECT member_id, relationship FROM {$wpdb->prefix}avm_family_members WHERE family_id = %d",
                    $family_id
                ));
                $parents  = array_filter($members, fn($m) => $m->relationship === 'parent');
                $children = array_filter($members, fn($m) => $m->relationship === 'child');
                foreach ($parents as $parent) {
                    foreach ($children as $child) {
                        self::add_relationship((int) $child->member_id, (int) $parent->member_id, $ids['ouder']);
                    }
                }
            }

            // avm_partners had no gender/label info at all — migrate as the
            // generic 'partner van', not a guessed man/vrouw/vriend(in).
            $partners = $wpdb->get_results("SELECT member_id_1, member_id_2 FROM {$wpdb->prefix}avm_partners");
            foreach ($partners as $p) {
                self::add_relationship((int) $p->member_id_1, (int) $p->member_id_2, $ids['partner']);
            }
        }

        // The 5 rows in the old untyped family_relation_member_id column,
        // manually confirmed with the club 2026-07-25 (surname-guessing
        // alone got Sanne de Vries and Fenna/Dirk's actual parents wrong):
        //   Anna Bakker        -> vriendin van Peter Jansen
        //   Sanne de Vries   -> vrouw van Jan de Vries (=Sanne Willems)
        //   Marieke Peters    -> ouder van Tom Peters
        //   Willem & Anke    -> ouder van Lisa Smit AND Mark Smit (siblings
        //                        via shared parents, not stored directly)
        //   Willem Smit          -> man van Anke Bosman
        // Willem Smit (not a club member) was created separately as a
        // 'visitor' before this migration ran. Kees Mulder/Piet Mulder'
        // relationship was never confirmed — deliberately left unmigrated;
        // add it manually via the profile page once known.
        $manual_map = [
            // subject_id => [related_id, label_code]
            32  => [128, 'vriendin'],  // Peter Jansen      -> Anna Bakker
            51  => [130, 'vrouw'],     // Jan de Vries -> Sanne de Vries
            132 => [80,  'ouder'],     // Tom Peters     -> Marieke Peters
            127 => [134, 'ouder'],     // Lisa Smit          -> Willem Smit
            118 => [134, 'ouder'],     // Mark Smit           -> Willem Smit
        ];
        $manual_map_extra = [
            127 => 54, // Lisa Smit -> Anke Bosman
            118 => 54, // Mark Smit  -> Anke Bosman
        ];
        foreach ($manual_map as $subject_id => [$related_id, $label_code]) {
            // Only insert if both members actually exist (harmless no-op
            // if this migration re-runs against a DB that never had them,
            // e.g. a fresh dev/staging copy).
            $both_exist = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}avm_members WHERE id IN (%d, %d)",
                $subject_id, $related_id
            ));
            if ((int) $both_exist === 2) {
                self::add_relationship($subject_id, $related_id, $ids[$label_code]);
            }
        }
        foreach ($manual_map_extra as $subject_id => $related_id) {
            $both_exist = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}avm_members WHERE id IN (%d, %d)",
                $subject_id, $related_id
            ));
            if ((int) $both_exist === 2) {
                self::add_relationship($subject_id, $related_id, $ids['ouder']);
            }
        }
        // Mariska is Henk's partner too (was only captured as Garmt/Germie
        // in the old avm_partners table; Willem/Anke never had a row).
        $henk = $wpdb->get_var("SELECT id FROM {$wpdb->prefix}avm_members WHERE lldap_user_id = 'willem.smit'");
        if ($henk) {
            self::add_relationship(54, (int) $henk, $ids['man']);
        }

        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}avm_family_members");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}avm_families");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}avm_partners");

        $column_exists = $wpdb->get_var(
            "SHOW COLUMNS FROM {$wpdb->prefix}avm_members LIKE 'family_relation_member_id'"
        );
        if ($column_exists) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}avm_members
                DROP KEY family_relation_member_id,
                DROP COLUMN family_relation_member_id");
        }
    }

    // One-time migration (2026-07-25): introduces avm_activity_types as a
    // real, admin-editable table (rename/add types via the Kampdeelname
    // screen) and points avm_camps at it via type_id, instead of a fixed
    // ENUM of hardcoded Dutch values.
    private static function migrate_to_activity_types_table(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE {$wpdb->prefix}avm_activity_types (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(50) NOT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY name (name)
        ) $charset;");

        $default_types = ['Kamp', 'Weekend', 'Uitje', 'Wandeling', 'Feest', 'Anders'];
        $ids = [];
        foreach ($default_types as $i => $name) {
            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}avm_activity_types WHERE name = %s", $name
            ));
            if ($existing_id) {
                $ids[$name] = (int) $existing_id;
                continue;
            }
            $wpdb->insert(
                "{$wpdb->prefix}avm_activity_types",
                ['name' => $name, 'sort_order' => $i],
                ['%s', '%d']
            );
            $ids[$name] = (int) $wpdb->insert_id;
        }

        $column_exists = $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}avm_camps LIKE 'type_id'");
        if (!$column_exists) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}avm_camps
                ADD COLUMN type_id INT UNSIGNED NULL AFTER name,
                ADD KEY type_id (type_id)");
        }
        // Existing camps predate the type field entirely — they were all
        // the annual dig, so 'Kamp' is the correct default backfill.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}avm_camps SET type_id = %d WHERE type_id IS NULL",
            $ids['Kamp']
        ));
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
                       m.first_name, m.suffix, m.last_name, m.passport_name, m.initials, m.birth_date, m.birth_year, m.is_student,
                       m.phone, m.mobile, m.emergency_contact, m.diet,
                       m.status, m.joined_year, m.left_year,
                       m.directory_consent, m.directory_consent_at,
                       m.share_email, m.share_phone, m.share_address, m.share_camp_history,
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

    public static function normalize_identity_email(string $email): string {
        return strtolower(sanitize_email($email));
    }

    public static function get_member_identity(string $provider, string $email): ?object {
        global $wpdb;
        $provider = sanitize_key($provider);
        $email    = self::normalize_identity_email($email);
        if (!in_array($provider, ['email', 'google', 'microsoft'], true) || !$email) {
            return null;
        }

        $lldap = self::lldap();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT i.*, m.id AS member_id, m.lldap_user_id, m.wp_user_id
             FROM {$wpdb->prefix}avm_member_identities i
             JOIN {$wpdb->prefix}avm_members m ON m.id = i.member_id
             WHERE i.provider = %s AND LOWER(i.email) = LOWER(%s)
             LIMIT 1",
            $provider,
            $email
        )) ?: null;
    }

    public static function get_member_identities(int $member_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avm_member_identities WHERE member_id = %d ORDER BY is_primary DESC, provider ASC, email ASC",
            $member_id
        )) ?: [];
    }

    public static function get_member_identity_count(int $member_id): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}avm_member_identities WHERE member_id = %d",
            $member_id
        ));
    }

    public static function sync_primary_email_identity(int $member_id, string $email): void {
        global $wpdb;
        $email = self::normalize_identity_email($email);
        if (!$email) {
            return;
        }

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}avm_member_identities WHERE member_id = %d AND provider = 'email' LIMIT 1",
            $member_id
        ));

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}avm_member_identities SET is_primary = 0 WHERE member_id = %d",
            $member_id
        ));

        if ($existing) {
            $wpdb->update(
                "{$wpdb->prefix}avm_member_identities",
                ['email' => $email, 'is_primary' => 1],
                ['id' => (int) $existing->id],
                ['%s', '%d'],
                ['%d']
            );
            return;
        }

        $wpdb->insert(
            "{$wpdb->prefix}avm_member_identities",
            [
                'member_id'   => $member_id,
                'provider'    => 'email',
                'email'       => $email,
                'is_primary'  => 1,
            ],
            ['%d', '%s', '%s', '%d']
        );
    }

    /**
     * Adds (or re-verifies) an identity for a member. Members may have up to
     * 3 identities in total regardless of provider — e.g. two Google-verified
     * addresses is fine — so the limit is a total count, not one per
     * provider. An address that's already on file for this exact member just
     * gets its provider/primary flag updated (e.g. a plain "email" entry
     * later confirmed via Google); the email column's global uniqueness
     * constraint prevents it ever being claimed by a different member.
     */
    public static function ensure_identity(int $member_id, string $provider, string $email, bool $primary = false): bool {
        global $wpdb;
        $provider = sanitize_key($provider);
        $email    = self::normalize_identity_email($email);
        if (!in_array($provider, ['email', 'google', 'microsoft'], true) || !$email) {
            return false;
        }

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, member_id FROM {$wpdb->prefix}avm_member_identities WHERE email = %s",
            $email
        ));
        if ($existing && (int) $existing->member_id !== $member_id) {
            return false;
        }

        if (!$existing && self::get_member_identity_count($member_id) >= 3) {
            return false;
        }

        if ($primary) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}avm_member_identities SET is_primary = 0 WHERE member_id = %d",
                $member_id
            ));
        }

        if ($existing) {
            return (bool) $wpdb->update(
                "{$wpdb->prefix}avm_member_identities",
                ['provider' => $provider, 'is_primary' => $primary ? 1 : 0],
                ['id' => (int) $existing->id],
                ['%s', '%d'],
                ['%d']
            );
        }

        return (bool) $wpdb->insert(
            "{$wpdb->prefix}avm_member_identities",
            [
                'member_id'  => $member_id,
                'provider'   => $provider,
                'email'      => $email,
                'is_primary' => $primary ? 1 : 0,
            ],
            ['%d', '%s', '%s', '%d']
        );
    }

    public static function delete_identity_by_id(int $member_id, int $identity_id): bool {
        global $wpdb;

        return false !== $wpdb->delete(
            "{$wpdb->prefix}avm_member_identities",
            ['id' => $identity_id, 'member_id' => $member_id],
            ['%d', '%d']
        );
    }

    public static function set_primary_identity(int $member_id, int $identity_id): bool {
        global $wpdb;

        $updated = $wpdb->update(
            "{$wpdb->prefix}avm_member_identities",
            ['is_primary' => 0],
            ['member_id' => $member_id],
            ['%d'],
            ['%d']
        );

        $updated2 = $wpdb->update(
            "{$wpdb->prefix}avm_member_identities",
            ['is_primary' => 1],
            ['id' => $identity_id, 'member_id' => $member_id],
            ['%d'],
            ['%d', '%d']
        );

        return $updated !== false && $updated2 !== false;
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
            $where .= ' AND (m.last_name LIKE %s OR m.first_name LIKE %s OR u.email LIKE %s)';
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
        }
        // Per-column filters (name parts), in addition to the general search above.
        foreach (['first_name' => 'first_name', 'suffix' => 'suffix', 'last_name' => 'last_name'] as $arg_key => $column) {
            if (!empty($args[$arg_key])) {
                $where .= " AND m.$column LIKE %s";
                $params[] = '%' . $wpdb->esc_like($args[$arg_key]) . '%';
            }
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

    public static function get_members_with_address(int $viewer_member_id = 0, bool $viewer_sees_minors = false): array {
        global $wpdb;
        $lldap = self::lldap();
        $today = current_time('Y-m-d');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT u.email,
                    m.id, m.lldap_user_id, m.first_name, m.suffix, m.last_name, m.phone, m.mobile, m.status,
                    m.birth_date, m.share_email, m.share_phone, m.share_address,
                    a.street, a.house_number, a.postal_code, a.city, a.country
             FROM {$lldap}.users u
             JOIN {$wpdb->prefix}avm_members m ON m.lldap_user_id = u.user_id
             LEFT JOIN {$wpdb->prefix}avm_addresses a ON a.id = (
                 SELECT a2.id FROM {$wpdb->prefix}avm_addresses a2
                 WHERE a2.member_id = m.id
                   AND (a2.valid_from IS NULL OR a2.valid_from <= %s)
                   AND (a2.valid_until IS NULL OR a2.valid_until >= %s)
                 ORDER BY a2.valid_from DESC, a2.id DESC
                 LIMIT 1
             )
             WHERE m.status = 'active' AND m.directory_consent = 'granted'
             ORDER BY m.last_name, m.first_name",
            $today, $today
        )) ?: [];

        $leden = [];
        foreach ($rows as $lid) {
            $is_minor = $lid->birth_date && self::age($lid->birth_date) < 16;
            if ($is_minor) {
                $visible = $viewer_sees_minors
                    || ($viewer_member_id && self::is_same_household($viewer_member_id, (int) $lid->id));
                if (!$visible) {
                    continue;
                }
                // Bestuur/household sees a minor's full info regardless of their own opt-out flags.
                $lid->share_email   = 1;
                $lid->share_phone   = 1;
                $lid->share_address = 1;
            }
            $leden[] = $lid;
        }
        return $leden;
    }

    public static function get_addresses(int $member_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avm_addresses WHERE member_id = %d ORDER BY valid_from DESC",
            $member_id
        )) ?: [];
    }

    /**
     * The profile form only ever inserts a new address row (each save adds
     * one), so there was never a UI to fix a wrong/duplicate valid_from or
     * valid_until, or to remove a row entirely — needed to clean up e.g. a
     * duplicate created by a failed/retried submission.
     */
    public static function update_address(int $id, int $member_id, ?string $valid_from, ?string $valid_until): void {
        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}avm_addresses",
            ['valid_from' => $valid_from, 'valid_until' => $valid_until],
            ['id' => $id, 'member_id' => $member_id],
            ['%s', '%s'], ['%d', '%d']
        );
    }

    public static function delete_address(int $id, int $member_id): void {
        global $wpdb;
        $wpdb->delete("{$wpdb->prefix}avm_addresses", ['id' => $id, 'member_id' => $member_id], ['%d', '%d']);
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

    // -------------------------------------------------------------------
    // Camp participation ("Kampdeelname")
    // -------------------------------------------------------------------

    public static function get_camps(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT c.*, t.name AS type_name
             FROM {$wpdb->prefix}avm_camps c
             LEFT JOIN {$wpdb->prefix}avm_activity_types t ON t.id = c.type_id
             ORDER BY c.year DESC, c.name ASC"
        ) ?: [];
    }

    public static function get_camp(int $camp_id): ?object {
        global $wpdb;
        $camp = $wpdb->get_row($wpdb->prepare(
            "SELECT c.*, t.name AS type_name
             FROM {$wpdb->prefix}avm_camps c
             LEFT JOIN {$wpdb->prefix}avm_activity_types t ON t.id = c.type_id
             WHERE c.id = %d",
            $camp_id
        ));
        return $camp ?: null;
    }

    // -------------------------------------------------------------------
    // Activity types — admin-editable list backing avm_camps.type_id
    // -------------------------------------------------------------------

    public static function get_activity_types(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}avm_activity_types ORDER BY sort_order, name"
        ) ?: [];
    }

    public static function add_activity_type(string $name) {
        global $wpdb;
        $name = trim($name);
        if ($name === '') {
            return false;
        }
        $max_sort = (int) $wpdb->get_var("SELECT COALESCE(MAX(sort_order), 0) FROM {$wpdb->prefix}avm_activity_types");
        $result = $wpdb->insert(
            "{$wpdb->prefix}avm_activity_types",
            ['name' => $name, 'sort_order' => $max_sort + 1],
            ['%s', '%d']
        );
        return $result !== false ? (int) $wpdb->insert_id : false;
    }

    public static function rename_activity_type(int $id, string $name): bool {
        global $wpdb;
        $name = trim($name);
        if ($name === '') {
            return false;
        }
        return $wpdb->update(
            "{$wpdb->prefix}avm_activity_types",
            ['name' => $name],
            ['id' => $id],
            ['%s'], ['%d']
        ) !== false;
    }

    /**
     * Most recent activity by year — used as the default for new screens,
     * and (with $type_id) to find e.g. "the current congress activity"
     * without a separate setting pointing at it.
     */
    public static function get_current_camp(?int $type_id = null): ?object {
        global $wpdb;
        if ($type_id) {
            $camp = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}avm_camps WHERE type_id = %d ORDER BY year DESC, id DESC LIMIT 1",
                $type_id
            ));
        } else {
            $camp = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}avm_camps ORDER BY year DESC, id DESC LIMIT 1");
        }
        return $camp ?: null;
    }

    /**
     * The most recent actual camp — unlike get_current_camp() (unfiltered
     * "most recent activity", which can now just as easily resolve to
     * "Contributie {year}" since that lives in the same table), this
     * always means an actual Kamp-type activity. Needed wherever "the
     * current camp" is specifically about participation/attendance, which
     * doesn't apply to contribution or other non-participation activities.
     */
    public static function get_current_camp_activity(): ?object {
        $kamp_type = current(array_filter(
            self::get_activity_types(),
            fn($t) => $t->name === 'Kamp'
        ));
        return $kamp_type ? self::get_current_camp((int) $kamp_type->id) : null;
    }

    /** Read-only lookup by name+year — unlike get_or_create_camp(), never creates one (e.g. avpvh-bookkeeping checking whether a "Contributie {year}" activity has been set up yet, without silently conjuring an empty one). */
    public static function get_camp_by_name_year(string $name, int $year): ?object {
        global $wpdb;
        $camp = $wpdb->get_row($wpdb->prepare(
            "SELECT c.*, t.name AS type_name
             FROM {$wpdb->prefix}avm_camps c
             LEFT JOIN {$wpdb->prefix}avm_activity_types t ON t.id = c.type_id
             WHERE c.name = %s AND c.year = %d",
            $name, $year
        ));
        return $camp ?: null;
    }

    public static function get_or_create_camp(string $name, int $year, string $kenmerk = '', ?string $start_date = null, ?string $end_date = null, int $type_id = 0): int {
        global $wpdb;
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}avm_camps WHERE name = %s AND year = %d", $name, $year
        ));
        if ($id) {
            return (int) $id;
        }
        $wpdb->insert("{$wpdb->prefix}avm_camps", [
            'name' => $name, 'year' => $year, 'kenmerk' => $kenmerk,
            'start_date' => $start_date, 'end_date' => $end_date,
            'type_id' => $type_id ?: null,
        ]);
        return (int) $wpdb->insert_id;
    }

    /** All participation rows for one camp, joined with member name/status. */
    public static function get_participation_for_camp(int $camp_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, m.first_name, m.suffix, m.last_name, m.status AS member_status
             FROM {$wpdb->prefix}avm_camp_participation p
             JOIN {$wpdb->prefix}avm_members m ON m.id = p.member_id
             WHERE p.camp_id = %d
             ORDER BY m.last_name ASC, m.first_name ASC",
            $camp_id
        )) ?: [];
    }

    public static function get_participation(int $member_id, int $camp_id): ?object {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avm_camp_participation WHERE member_id = %d AND camp_id = %d",
            $member_id, $camp_id
        ));
        return $row ?: null;
    }

    public static function get_participation_by_id(int $participation_id): ?object {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avm_camp_participation WHERE id = %d", $participation_id
        ));
        return $row ?: null;
    }

    /** Day statuses for one participation record, keyed by 'Y-m-d'. */
    public static function get_participation_days(int $participation_id): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT date, status FROM {$wpdb->prefix}avm_camp_participation_days WHERE participation_id = %d",
            $participation_id
        )) ?: [];
        $days = [];
        foreach ($rows as $row) {
            $days[$row->date] = $row->status;
        }
        return $days;
    }

    /**
     * Create or update a member's participation record for a camp. Returns
     * the participation id.
     */
    public static function save_participation(int $member_id, int $camp_id, array $fields): int {
        global $wpdb;
        $data = [
            'nights'  => $fields['nights'] !== '' && $fields['nights'] !== null ? (int) $fields['nights'] : null,
            'nawacht' => !empty($fields['nawacht']) ? 1 : 0,
            'diet'    => $fields['diet'] ?? '',
            'notes'   => $fields['notes'] ?? '',
        ];
        $existing = self::get_participation($member_id, $camp_id);
        if ($existing) {
            $wpdb->update("{$wpdb->prefix}avm_camp_participation", $data, ['id' => $existing->id]);
            do_action('avpvh_camp_participation_saved', $member_id, $camp_id, (int) $existing->id);
            return (int) $existing->id;
        }
        $wpdb->insert("{$wpdb->prefix}avm_camp_participation", [
            'member_id' => $member_id, 'camp_id' => $camp_id,
        ] + $data);
        $participation_id = (int) $wpdb->insert_id;
        do_action('avpvh_camp_participation_saved', $member_id, $camp_id, $participation_id);
        return $participation_id;
    }

    /** Replace all day rows for a participation record with $days (date => status). */
    public static function save_participation_days(int $participation_id, array $days): void {
        global $wpdb;
        $table = "{$wpdb->prefix}avm_camp_participation_days";
        $wpdb->delete($table, ['participation_id' => $participation_id]);
        foreach ($days as $date => $status) {
            if ($status === '' || $status === null) {
                continue;
            }
            $wpdb->insert($table, [
                'participation_id' => $participation_id,
                'date'             => $date,
                'status'           => $status,
            ]);
        }
    }

    public static function delete_participation(int $participation_id): void {
        global $wpdb;
        $wpdb->delete("{$wpdb->prefix}avm_camp_participation_days", ['participation_id' => $participation_id]);
        $wpdb->delete("{$wpdb->prefix}avm_camp_participation", ['id' => $participation_id]);
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
    // Relationships — a single directed-edge table for every kind of
    // person-to-person relation (parent/child, partner variants, voogd),
    // replacing the old, separate, mostly-unused avm_families/
    // avm_family_members/avm_partners mechanisms and the untyped
    // family_relation_member_id column (migrated in the 2.5 upgrade).
    //
    // A row (member_id, related_member_id, label_id) reads as:
    // "related_member_id is [label] of member_id". Labels come in
    // inverse pairs (ouder<->kind, vriend<->vriendin, man<->vrouw) so a
    // relationship only ever needs ONE row — whichever side's profile
    // you're viewing, get_relationships() picks the direct or inverse
    // label automatically. Self-inverse labels (partner, huisgenoot,
    // voogd/pupil is the one exception with a real inverse) read the
    // same from both sides.
    // -------------------------------------------------------------------

    public static function get_relationship_labels(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}avm_relationship_labels ORDER BY sort_order, label"
        ) ?: [];
    }

    // Combined, human-facing view of every relationship touching this
    // member. Each row's label is resolved so it reads as a full sentence
    // "{other_member} is {label} {this member}" (see render_relationships()
    // in class-member-profile-form.php) — a row's stored label describes
    // related_member_id's role toward member_id, so on member_id's own
    // page (as_subject) that's already the right direction; on
    // related_member_id's own page (as_object) the sentence needs the
    // INVERSE label instead.
    public static function get_relationships(int $member_id): array {
        global $wpdb;

        $as_subject = $wpdb->get_results($wpdb->prepare(
            "SELECT r.id, r.related_member_id AS other_id, l.label, l.category,
                    r.valid_from, r.valid_until, r.note
             FROM {$wpdb->prefix}avm_relationships r
             JOIN {$wpdb->prefix}avm_relationship_labels l ON l.id = r.label_id
             WHERE r.member_id = %d",
            $member_id
        )) ?: [];

        $as_object = $wpdb->get_results($wpdb->prepare(
            "SELECT r.id, r.member_id AS other_id,
                    COALESCE(inv.label, l.label) AS label, l.category,
                    r.valid_from, r.valid_until, r.note
             FROM {$wpdb->prefix}avm_relationships r
             JOIN {$wpdb->prefix}avm_relationship_labels l ON l.id = r.label_id
             LEFT JOIN {$wpdb->prefix}avm_relationship_labels inv ON inv.id = l.inverse_id
             WHERE r.related_member_id = %d",
            $member_id
        )) ?: [];

        $rows = array_merge($as_subject, $as_object);
        if (!$rows) {
            return [];
        }

        $other_ids = array_unique(array_map(fn($r) => (int) $r->other_id, $rows));
        $placeholders = implode(',', array_fill(0, count($other_ids), '%d'));
        $members = $wpdb->get_results($wpdb->prepare(
            "SELECT id, first_name, suffix, last_name FROM {$wpdb->prefix}avm_members WHERE id IN ($placeholders)",
            $other_ids
        ));
        $names = [];
        foreach ($members as $m) {
            $names[(int) $m->id] = $m;
        }

        foreach ($rows as $row) {
            $row->other_member = $names[(int) $row->other_id] ?? null;
        }

        return $rows;
    }

    public static function add_relationship(
        int $member_id, int $related_member_id, int $label_id,
        ?string $valid_from = null, ?string $valid_until = null,
        string $note = '', int $created_by = 0
    ): bool {
        global $wpdb;
        $result = $wpdb->insert(
            "{$wpdb->prefix}avm_relationships",
            [
                'member_id'         => $member_id,
                'related_member_id' => $related_member_id,
                'label_id'          => $label_id,
                'valid_from'        => $valid_from ?: null,
                'valid_until'       => $valid_until ?: null,
                'note'              => $note,
                'created_by'        => $created_by ?: null,
            ],
            ['%d', '%d', '%d', '%s', '%s', '%s', '%d']
        );
        return $result !== false;
    }

    public static function remove_relationship(int $relationship_id): void {
        global $wpdb;
        $wpdb->delete("{$wpdb->prefix}avm_relationships", ['id' => $relationship_id], ['%d']);
    }

    // True if the two members have a (currently valid) 'ouder_kind' edge
    // in either direction — used to grant profile-edit permission to a
    // non-cohabiting parent (e.g. after a divorce).
    public static function is_family_member(int $member_id_1, int $member_id_2): bool {
        global $wpdb;
        $today = current_time('Y-m-d');
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT r.id FROM {$wpdb->prefix}avm_relationships r
             JOIN {$wpdb->prefix}avm_relationship_labels l ON l.id = r.label_id
             WHERE l.category = 'ouder_kind'
               AND ((r.member_id = %d AND r.related_member_id = %d)
                 OR (r.member_id = %d AND r.related_member_id = %d))
               AND (r.valid_from IS NULL OR r.valid_from <= %s)
               AND (r.valid_until IS NULL OR r.valid_until >= %s)
             LIMIT 1",
            $member_id_1, $member_id_2, $member_id_2, $member_id_1, $today, $today
        ));
        return (bool) $result;
    }

    // -------------------------------------------------------------------
    // Household — family link or matching current address
    // -------------------------------------------------------------------

    public static function current_address(int $member_id): ?object {
        global $wpdb;
        $today = current_time('Y-m-d');
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avm_addresses WHERE member_id = %d
             AND (valid_from IS NULL OR valid_from <= %s)
             AND (valid_until IS NULL OR valid_until >= %s)
             ORDER BY valid_from DESC LIMIT 1",
            $member_id, $today, $today
        )) ?: null;
    }

    public static function is_same_household(int $member_id_1, int $member_id_2): bool {
        if ($member_id_1 === $member_id_2) {
            return true;
        }
        if (self::is_family_member($member_id_1, $member_id_2)) {
            return true;
        }

        $addr_1 = self::current_address($member_id_1);
        $addr_2 = self::current_address($member_id_2);
        if (!$addr_1 || !$addr_2 || !$addr_1->street || !$addr_2->street) {
            return false;
        }

        return strcasecmp(trim($addr_1->street), trim($addr_2->street)) === 0
            && strcasecmp(trim((string) $addr_1->house_number), trim((string) $addr_2->house_number)) === 0
            && strcasecmp(trim($addr_1->postal_code), trim($addr_2->postal_code)) === 0;
    }

    public static function age(string $birth_date): int {
        $birth = new \DateTime($birth_date);
        $today = new \DateTime(current_time('Y-m-d'));
        return (int) $today->diff($birth)->y;
    }

    /**
     * Members $member_id may manage (view/edit) via the self-service profile
     * form: themselves plus everyone in their household. Includes $member_id.
     */
    public static function get_manageable_members(int $member_id): array {
        global $wpdb;
        $active = $wpdb->get_results(
            self::member_select() . " WHERE m.status = 'active' ORDER BY m.last_name, m.first_name"
        ) ?: [];

        $manageable = [];
        foreach ($active as $m) {
            if (self::is_same_household($member_id, (int) $m->id)) {
                $manageable[] = $m;
            }
        }
        return $manageable;
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

    /** Used by avpvh-bookkeeping to auto-backfill initials it recognized in a confirmed bank transaction — goes through the same audit-logged update path as a manual profile edit, so the change is traceable. */
    public static function update_member_initials(int $member_id, string $initials): void {
        self::update_member_with_audit($member_id, ['initials' => $initials], ['%s']);
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

    /** Case-insensitive first+last name match — used by the "Nieuw lid" admin form to warn before creating what might be a duplicate. Doesn't consider suffix, since a treasurer typing e.g. "Jules Horen" should still be warned about "Jules van Horen". */
    public static function find_members_by_name(string $first_name, string $last_name): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, first_name, suffix, last_name, status FROM {$wpdb->prefix}avm_members
             WHERE LOWER(first_name) = LOWER(%s) AND LOWER(last_name) = LOWER(%s)",
            $first_name, $last_name
        )) ?: [];
    }

    /** New member with an already-created LLDAP account (see AVPVH_Admin::handle_add_member()) — mirrors the shape of the avpvh-ops-scripts one-off "create minor member" scripts, now available from the admin UI instead of a hand-run script. */
    public static function create_member(string $lldap_user_id, string $first_name, string $suffix, string $last_name, ?string $birth_date, string $status): int {
        global $wpdb;
        $wpdb->insert(
            "{$wpdb->prefix}avm_members",
            [
                'lldap_user_id' => $lldap_user_id,
                'first_name'    => $first_name,
                'suffix'        => $suffix,
                'last_name'     => $last_name,
                'birth_date'    => $birth_date,
                'status'        => $status,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );
        return (int) $wpdb->insert_id;
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

    // Legacy rows store tussenvoegsel glued into last_name as "Achternaam, suffix"
    // instead of the separate suffix column — split it for display.
    if ($suffix === '' && str_contains($last, ',')) {
        [$last, $suffix] = array_map('trim', explode(',', $last, 2));
    }

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

/** First letter of each given name in a full name string, canonical "J.F.M." form (matches AVPVH_Member_Profile_Form::normalize_initials()'s output, so a stored `initials` value can be compared against this directly). Empty in, empty out. */
function avpvh_derive_initials(string $full_name): string {
    $words = preg_split('/\s+/', trim($full_name), -1, PREG_SPLIT_NO_EMPTY);
    if (!$words) {
        return '';
    }
    $letters = array_map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)), $words);
    return implode('.', $letters) . '.';
}

/**
 * A member's stored `initials` should agree with what their passport_name
 * implies — a real mismatch usually means one of the two was mistyped, or
 * the two were sourced from different documents. Returns null when there's
 * nothing to compare (either field empty) or when they already agree.
 */
function avpvh_initials_mismatch(object $member): ?string {
    $stored = trim($member->initials ?? '');
    $passport = trim($member->passport_name ?? '');
    if ($stored === '' || $passport === '') {
        return null;
    }
    $derived = avpvh_derive_initials($passport);
    $normalize = fn($s) => strtoupper(str_replace('.', '', $s));
    if ($normalize($stored) === $normalize($derived)) {
        return null;
    }
    return $derived;
}
