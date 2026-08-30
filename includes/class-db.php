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
            share_activity_history TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
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
            KEY member_id (member_id),
            KEY member_current (member_id, valid_until, valid_from)
        ) $charset;");

        dbDelta("CREATE TABLE {$wpdb->prefix}avm_member_name_aliases (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id INT UNSIGNED NOT NULL,
            first_name VARCHAR(100) NOT NULL DEFAULT '',
            suffix VARCHAR(50) NOT NULL DEFAULT '',
            last_name VARCHAR(100) NOT NULL DEFAULT '',
            alias_type ENUM('maiden','married','nickname','spelling','abbreviation','historical') NOT NULL,
            valid_from DATE NULL,
            valid_until DATE NULL,
            source VARCHAR(100) NOT NULL DEFAULT '',
            note TEXT NULL,
            normalized_key VARCHAR(255) NOT NULL,
            normalized_key_hash CHAR(64) NOT NULL,
            created_by INT UNSIGNED NULL,
            updated_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY member_normalized (member_id, normalized_key_hash),
            KEY normalized_key_hash (normalized_key_hash),
            KEY alias_type (alias_type)
        ) $charset;");

        dbDelta("CREATE TABLE {$wpdb->prefix}avm_city_aliases (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            country VARCHAR(100) NOT NULL DEFAULT 'Nederland',
            alias_name VARCHAR(100) NOT NULL,
            canonical_name VARCHAR(100) NOT NULL,
            normalized_alias_hash CHAR(64) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY country_alias (country, normalized_alias_hash)
        ) $charset;");

        dbDelta("CREATE TABLE {$wpdb->prefix}avm_street_aliases (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            country VARCHAR(100) NOT NULL DEFAULT 'Nederland',
            postal_code VARCHAR(20) NOT NULL DEFAULT '',
            city VARCHAR(100) NOT NULL DEFAULT '',
            alias_street VARCHAR(150) NOT NULL,
            canonical_street VARCHAR(150) NOT NULL,
            normalized_scope_hash CHAR(64) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY scoped_alias (normalized_scope_hash)
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

        dbDelta("CREATE TABLE {$wpdb->prefix}avm_activities (
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

        dbDelta("CREATE TABLE {$wpdb->prefix}avm_activity_participation (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id INT UNSIGNED NOT NULL,
            activity_id INT UNSIGNED NOT NULL,
            nights TINYINT UNSIGNED NULL,
            nawacht TINYINT UNSIGNED NOT NULL DEFAULT 0,
            diet TEXT NULL,
            notes TEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY member_activity (member_id, activity_id),
            KEY activity_id (activity_id)
        ) $charset;");

        // Day-by-day attendance per participation record — one row per date
        // a member is (or might be) present. Replaces the old, unlinked
        // avm_registration_attendance (which tracked a raw e-mail, not a
        // real member) with the same idea properly tied to a member.
        dbDelta("CREATE TABLE {$wpdb->prefix}avm_activity_participation_days (
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
            method ENUM('proxy','google','microsoft','password_reset','email') NOT NULL,
            result ENUM('success','no_member','hibp_warned','link_sent') NOT NULL,
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
            verified_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY email (email(191)),
            KEY member_id (member_id)
        ) $charset;");

        // Member flags — a general-purpose, admin-extendable tag catalog
        // (ere-lid, archeoloog, belangstellende, nieuwsbrief, donateur, ...
        // and whatever ad-hoc categories come up later, e.g. "belangrijk
        // voor opgraving X"). Deliberately not a fixed ENUM on avm_members,
        // same reasoning as avm_activity_types above. affects_fees is read
        // by avpvh-bookkeeping's contribution generation — a member with
        // any affects_fees flag (ere-lid) never gets a contribution fee
        // item created in the first place. Seeded with the initial 5 flags
        // in the 2.17 migration below (install() alone can't seed rows
        // safely on repeat activation).
        dbDelta("CREATE TABLE {$wpdb->prefix}avm_member_flags (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(50) NOT NULL,
            label VARCHAR(100) NOT NULL,
            affects_fees TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            sets_inactive TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug)
        ) $charset;");

        dbDelta("CREATE TABLE {$wpdb->prefix}avm_member_flag_assignments (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id INT UNSIGNED NOT NULL,
            flag_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY member_flag (member_id, flag_id),
            KEY flag_id (flag_id)
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
            // avm_activity_participation_days was added to install()
            // alongside avm_activity_participation, but install() only runs
            // on activation — already-active sites never got it. dbDelta is
            // safe to call standalone for one table. (Table was originally
            // named avm_camp_participation_days; a fresh install created
            // after the 2.14 rename never hits this branch with the old
            // name since install() already creates the current name.)
            $charset = $wpdb->get_charset_collate();
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta("CREATE TABLE {$wpdb->prefix}avm_activity_participation_days (
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
            // doopnaam data folded in during 2.7 (e.g. "Roepnaam (Volledige
            // Namen)" -> "Roepnaam Volledige Namen").
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
            // surname ("S J M Jansen", "P M H Bakker") — a separate field
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
        if (version_compare($version, '2.14', '<')) {
            // The "camp" naming had been stale since 2.6 (avm_camps holds
            // every activity type — Kamp, Contributie, Congres, ... —
            // "camp" was never accurate for most rows). Renames only:
            // RENAME TABLE / CHANGE COLUMN preserve all existing data,
            // nothing is recreated. avpvh-bookkeeping (a separate plugin
            // that reads avm_activities and listens for the participation-
            // saved hook) is updated and deployed together with this.
            if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}avm_camps'")) {
                $wpdb->query("RENAME TABLE {$wpdb->prefix}avm_camps TO {$wpdb->prefix}avm_activities");
            }
            if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}avm_camp_participation'")) {
                $wpdb->query("ALTER TABLE {$wpdb->prefix}avm_camp_participation
                    CHANGE COLUMN camp_id activity_id INT UNSIGNED NOT NULL");
                $wpdb->query("ALTER TABLE {$wpdb->prefix}avm_camp_participation
                    RENAME INDEX member_camp TO member_activity,
                    RENAME INDEX camp_id TO activity_id");
                $wpdb->query("RENAME TABLE {$wpdb->prefix}avm_camp_participation TO {$wpdb->prefix}avm_activity_participation");
            }
            if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}avm_camp_participation_days'")) {
                $wpdb->query("RENAME TABLE {$wpdb->prefix}avm_camp_participation_days TO {$wpdb->prefix}avm_activity_participation_days");
            }
            if ($wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}avm_members LIKE 'share_camp_history'")) {
                $wpdb->query("ALTER TABLE {$wpdb->prefix}avm_members
                    CHANGE COLUMN share_camp_history share_activity_history TINYINT(1) UNSIGNED NOT NULL DEFAULT 1");
            }
            update_option('avpvh_db_version', '2.14');
        }

        if (version_compare($version, '2.15', '<')) {
            // Distinguishes an identity actually proven by its owner (OAuth
            // round-trip, or the e-mail-link flow) from one an admin typed
            // in directly via the "Nieuw e-mailadres koppelen" form with no
            // proof at all. There's no way to tell which mechanism created
            // an existing row after the fact, so existing rows are backfilled
            // as verified — the admin-direct-add path was always the rare
            // exception, and most rows on the live site came from a real
            // OAuth login/add. New admin-direct additions from this point on
            // are the only ones that land unverified (see class-admin.php).
            if (!$wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}avm_member_identities LIKE 'verified_at'")) {
                $wpdb->query("ALTER TABLE {$wpdb->prefix}avm_member_identities
                    ADD COLUMN verified_at TIMESTAMP NULL DEFAULT NULL AFTER is_primary");
                $wpdb->query("UPDATE {$wpdb->prefix}avm_member_identities SET verified_at = created_at");
            }
            update_option('avpvh_db_version', '2.15');
        }

        if (version_compare($version, '2.16', '<')) {
            // The e-mail-link add/login flow (class-email-identity.php) logs
            // attempts with method='email' and, while a link is outstanding,
            // result='link_sent' — neither existed in these ENUMs yet, so
            // every such log_attempt() call was failing silently before this.
            $wpdb->query("ALTER TABLE {$wpdb->prefix}avm_login_attempts
                MODIFY method ENUM('proxy','google','microsoft','password_reset','email') NOT NULL,
                MODIFY result ENUM('success','no_member','hibp_warned','link_sent') NOT NULL");
            update_option('avpvh_db_version', '2.16');
        }

        if (version_compare($version, '2.17', '<')) {
            // install() alone can't seed rows safely on repeat activation —
            // dbDelta only handles structure — so run it here to create the
            // two new tables on already-active installs, then seed the
            // initial flag catalog. 'ere-lid' is the only one with
            // affects_fees=1 for now; see AVBK_Fee_Generation in
            // avpvh-bookkeeping for where that's read.
            self::install();
            $flags = [
                ['ere-lid',        'Ere-lid',        1],
                ['archeoloog',     'Archeoloog',     0],
                ['belangstellende','Belangstellende',0],
                ['nieuwsbrief',    'Nieuwsbrief',    0],
                ['donateur',       'Donateur',       0],
            ];
            foreach ($flags as $i => [$slug, $label, $affects_fees]) {
                $wpdb->query($wpdb->prepare(
                    "INSERT IGNORE INTO {$wpdb->prefix}avm_member_flags (slug, label, affects_fees, sort_order) VALUES (%s, %s, %d, %d)",
                    $slug, $label, $affects_fees, $i
                ));
            }
            update_option('avpvh_db_version', '2.17');
        }

        if (version_compare($version, '2.18', '<')) {
            // A flag like 'geroyeerd' (expelled) should force the member's
            // status to 'inactive' the moment it's assigned — see
            // set_member_flags(). Generalized as a per-flag catalog column
            // (same shape as affects_fees) rather than hardcoding the
            // 'geroyeerd' slug in application logic, so any future flag can
            // opt into the same behavior without a code change.
            if (!$wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}avm_member_flags LIKE 'sets_inactive'")) {
                $wpdb->query("ALTER TABLE {$wpdb->prefix}avm_member_flags
                    ADD COLUMN sets_inactive TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER affects_fees");
            }
            update_option('avpvh_db_version', '2.18');
        }

        if (version_compare($version, '2.19', '<')) {
            // Central member-name aliases and scoped address aliases. dbDelta
            // also adds the current-address lookup index to avm_addresses.
            self::install();
            $city_alias_key = AVPVH_Normalization::fold('Nederland')
                . '|' . AVPVH_Normalization::fold('Den Bosch');
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->prefix}avm_city_aliases
                    (country, alias_name, canonical_name, normalized_alias_hash)
                 VALUES (%s, %s, %s, %s)",
                'Nederland',
                'Den Bosch',
                "'s-Hertogenbosch",
                hash('sha256', $city_alias_key)
            ));
            update_option('avpvh_db_version', '2.19');
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

        // A handful of rows from the old untyped family_relation_member_id
        // column needed manually confirming with the club (surname-
        // guessing alone got some wrong) and were backfilled by a one-time
        // migration here. That migration already ran against the live
        // site — $old_tables_exist above is permanently false now that the
        // old tables are dropped below — so it's been removed entirely
        // rather than left in place as dead code encoding real members'
        // relationship facts by ID.

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
        $forwarded = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        $ip = $forwarded ? trim(explode(',', $forwarded)[0]) : sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
        $wpdb->insert(
            "{$wpdb->prefix}avm_login_attempts",
            ['email' => $email, 'method' => $method, 'result' => $result, 'ip' => $ip],
            ['%s', '%s', '%s', '%s']
        );
    }

    public static function get_login_attempts(int $limit = 200): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avm_login_attempts ORDER BY attempted_at DESC LIMIT %d", $limit
        )) ?: [];
    }

    /**
     * First/most recent *successful* login logged for one specific address
     * (avm_login_attempts.email — proxy/Google/Microsoft/e-mail-link all
     * log under the address that was actually used, so this is per
     * identity, not per member). Null fields mean no successful login was
     * ever logged for that address — e.g. it was only ever admin-added, or
     * added but never actually used to log in yet.
     */
    public static function get_login_stats_for_email(string $email): object {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT MIN(attempted_at) AS first_login, MAX(attempted_at) AS last_login
             FROM {$wpdb->prefix}avm_login_attempts
             WHERE email = %s AND result = 'success'",
            $email
        ));
        return $row ?: (object) ['first_login' => null, 'last_login' => null];
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
                       m.share_email, m.share_phone, m.share_address, m.share_activity_history,
                       m.created_at, m.updated_at
                FROM {$lldap}.users u
                JOIN {$wpdb->prefix}avm_members m ON m.lldap_user_id = u.user_id";
    }

    public static function get_member_by_lldap_uid(string $uid): ?object {
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- member_select() is a fixed, hardcoded SELECT/JOIN with no interpolation; the %s value is properly prepared
        return $wpdb->get_row($wpdb->prepare(
            self::member_select() . " WHERE u.user_id = %s LIMIT 1",
            $uid
        )) ?: null;
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    public static function get_member_by_email(string $email): ?object {
        global $wpdb;
        $lldap = self::lldap();
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- member_select() is a fixed, hardcoded SELECT/JOIN with no interpolation; the %s value is properly prepared
        return $wpdb->get_row($wpdb->prepare(
            self::member_select() . " WHERE u.lowercase_email = LOWER(%s) LIMIT 1",
            $email
        )) ?: null;
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
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

    // Provider-agnostic lookup — an identity added by an admin (see
    // handle_add_identity()) is stored with a placeholder provider ('email')
    // since the admin has no way to know which one the member will actually
    // verify with. get_member_identity() alone would then miss it once the
    // member does verify via Google/Microsoft, wrongly reporting "no_member".
    public static function get_identity_by_email(string $email): ?object {
        global $wpdb;
        $email = self::normalize_identity_email($email);
        if (!$email) {
            return null;
        }

        return $wpdb->get_row($wpdb->prepare(
            "SELECT i.*, m.id AS member_id, m.lldap_user_id, m.wp_user_id
             FROM {$wpdb->prefix}avm_member_identities i
             JOIN {$wpdb->prefix}avm_members m ON m.id = i.member_id
             WHERE LOWER(i.email) = LOWER(%s)
             LIMIT 1",
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
            // Authelia/LLDAP proxy auth is a real, trusted login — same as
            // upgrading unverified → verified in ensure_identity(), never downgrade.
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}avm_member_identities
                 SET email = %s, is_primary = 1, verified_at = COALESCE(verified_at, %s)
                 WHERE id = %d",
                $email, current_time('mysql'), (int) $existing->id
            ));
            return;
        }

        $wpdb->insert(
            "{$wpdb->prefix}avm_member_identities",
            [
                'member_id'   => $member_id,
                'provider'    => 'email',
                'email'       => $email,
                'is_primary'  => 1,
                'verified_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%d', '%s']
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
     *
     * $verified must only be true when the caller actually proved ownership
     * (a completed OAuth round-trip, or a clicked e-mail confirmation link)
     * — never for the admin's direct "Nieuw e-mailadres koppelen" form,
     * which is why it defaults to false and that call site doesn't pass it.
     */
    public static function ensure_identity(int $member_id, string $provider, string $email, bool $primary = false, bool $verified = false): bool {
        global $wpdb;
        $provider = sanitize_key($provider);
        $email    = self::normalize_identity_email($email);
        if (!in_array($provider, ['email', 'google', 'microsoft'], true) || !$email) {
            return false;
        }

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, member_id, verified_at FROM {$wpdb->prefix}avm_member_identities WHERE email = %s",
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
            $data   = ['provider' => $provider, 'is_primary' => $primary ? 1 : 0];
            $format = ['%s', '%d'];
            // Upgrade unverified → verified when proof shows up later; never downgrade.
            if ($verified && !$existing->verified_at) {
                $data['verified_at'] = current_time('mysql');
                $format[] = '%s';
            }
            return (bool) $wpdb->update(
                "{$wpdb->prefix}avm_member_identities",
                $data,
                ['id' => (int) $existing->id],
                $format,
                ['%d']
            );
        }

        return (bool) $wpdb->insert(
            "{$wpdb->prefix}avm_member_identities",
            [
                'member_id'   => $member_id,
                'provider'    => $provider,
                'email'       => $email,
                'is_primary'  => $primary ? 1 : 0,
                'verified_at' => $verified ? current_time('mysql') : null,
            ],
            ['%d', '%s', '%s', '%d', '%s']
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
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- member_select() is a fixed, hardcoded SELECT/JOIN with no interpolation; the %d value is properly prepared
        return $wpdb->get_row($wpdb->prepare(
            self::member_select() . " WHERE m.wp_user_id = %d LIMIT 1",
            $user_id
        )) ?: null;
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    public static function get_member(int $id): ?object {
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- member_select() is a fixed, hardcoded SELECT/JOIN with no interpolation; the %d value is properly prepared
        return $wpdb->get_row($wpdb->prepare(
            self::member_select() . " WHERE m.id = %d",
            $id
        )) ?: null;
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    // -------------------------------------------------------------------
    // Central name aliases and normalization
    // -------------------------------------------------------------------

    public static function normalize_person_name(
        string $first_name,
        string $suffix,
        string $last_name
    ): array {
        return AVPVH_Normalization::normalize_person_name($first_name, $suffix, $last_name);
    }

    public static function get_member_name_variants(int $member_id): array {
        global $wpdb;
        $member = self::get_member($member_id);
        if (!$member) {
            return [];
        }

        $official = self::normalize_person_name(
            (string) $member->first_name,
            (string) $member->suffix,
            (string) $member->last_name
        );
        $variants = [(object) ($official + [
            'id' => 0,
            'member_id' => $member_id,
            'alias_type' => 'official',
            'source' => '',
            'note' => '',
            'valid_from' => null,
            'valid_until' => null,
            'match_reason' => 'officiële naam',
        ])];

        $aliases = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avm_member_name_aliases
             WHERE member_id = %d ORDER BY last_name, first_name, id",
            $member_id
        )) ?: [];
        foreach ($aliases as $alias) {
            $alias->match_reason = 'naamalias (' . $alias->alias_type . ')';
            $variants[] = $alias;
        }
        return $variants;
    }

    public static function get_name_alias(int $alias_id, int $member_id = 0): ?object {
        global $wpdb;
        $sql = "SELECT * FROM {$wpdb->prefix}avm_member_name_aliases WHERE id = %d";
        $params = [$alias_id];
        if ($member_id > 0) {
            $sql .= ' AND member_id = %d';
            $params[] = $member_id;
        }
        return $wpdb->get_row($wpdb->prepare($sql, $params)) ?: null;
    }

    public static function save_member_name_alias(int $member_id, array $data, int $alias_id = 0): int {
        global $wpdb;
        if (!self::get_member($member_id)) {
            return 0;
        }

        $allowed_types = ['maiden', 'married', 'nickname', 'spelling', 'abbreviation', 'historical'];
        $alias_type = in_array($data['alias_type'] ?? '', $allowed_types, true)
            ? $data['alias_type']
            : 'spelling';
        $normalized = self::normalize_person_name(
            (string) ($data['first_name'] ?? ''),
            (string) ($data['suffix'] ?? ''),
            (string) ($data['last_name'] ?? '')
        );
        if ($normalized['first_key'] === '' || $normalized['last_key'] === '') {
            return 0;
        }

        $row = [
            'member_id' => $member_id,
            'first_name' => trim((string) ($data['first_name'] ?? '')),
            'suffix' => trim((string) ($data['suffix'] ?? '')),
            'last_name' => trim((string) ($data['last_name'] ?? '')),
            'alias_type' => $alias_type,
            'valid_from' => ($data['valid_from'] ?? '') ?: null,
            'valid_until' => ($data['valid_until'] ?? '') ?: null,
            'source' => trim((string) ($data['source'] ?? '')),
            'note' => trim((string) ($data['note'] ?? '')),
            'normalized_key' => $normalized['normalized_key'],
            'normalized_key_hash' => hash('sha256', $normalized['normalized_key']),
            'updated_by' => get_current_user_id() ?: null,
        ];

        if ($alias_id > 0) {
            $existing = self::get_name_alias($alias_id, $member_id);
            if (!$existing) {
                return 0;
            }
            $updated = $wpdb->update(
                "{$wpdb->prefix}avm_member_name_aliases",
                $row,
                ['id' => $alias_id, 'member_id' => $member_id]
            );
            return $updated === false ? 0 : $alias_id;
        }

        $row['created_by'] = get_current_user_id() ?: null;
        $inserted = $wpdb->insert("{$wpdb->prefix}avm_member_name_aliases", $row);
        return $inserted ? (int) $wpdb->insert_id : 0;
    }

    public static function delete_member_name_alias(int $alias_id, int $member_id): bool {
        global $wpdb;
        return $wpdb->delete(
            "{$wpdb->prefix}avm_member_name_aliases",
            ['id' => $alias_id, 'member_id' => $member_id],
            ['%d', '%d']
        ) === 1;
    }

    public static function get_alias_conflicts(int $alias_id): array {
        $alias = self::get_name_alias($alias_id);
        if (!$alias) {
            return [];
        }
        $matches = self::find_members_by_name_or_alias(
            (string) $alias->first_name,
            (string) $alias->suffix,
            (string) $alias->last_name
        );
        return array_values(array_filter(
            array_unique(array_map(static fn(object $match): int => (int) $match->id, $matches)),
            static fn(int $member_id): bool => $member_id !== (int) $alias->member_id
        ));
    }

    public static function find_members_by_name_or_alias(
        string $first_name,
        string $suffix,
        string $last_name
    ): array {
        global $wpdb;
        $normalized = self::normalize_person_name($first_name, $suffix, $last_name);
        if ($normalized['first_key'] === '' || $normalized['last_key'] === '') {
            return [];
        }

        $matches = [];
        $members = $wpdb->get_results(
            "SELECT id, first_name, suffix, last_name, status FROM {$wpdb->prefix}avm_members"
        ) ?: [];
        foreach ($members as $member) {
            $official = self::normalize_person_name(
                (string) $member->first_name,
                (string) $member->suffix,
                (string) $member->last_name
            );
            if ($official['normalized_key'] === $normalized['normalized_key']) {
                $member->match_type = 'official';
                $member->match_reason = 'officiële naam';
                $matches[(int) $member->id] = $member;
            }
        }

        $aliases = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, m.status FROM {$wpdb->prefix}avm_member_name_aliases a
             JOIN {$wpdb->prefix}avm_members m ON m.id = a.member_id
             WHERE a.normalized_key_hash = %s AND a.normalized_key = %s",
            hash('sha256', $normalized['normalized_key']),
            $normalized['normalized_key']
        )) ?: [];
        foreach ($aliases as $alias) {
            $member_id = (int) $alias->member_id;
            if (isset($matches[$member_id])) {
                continue;
            }
            $alias->id = $member_id;
            $alias->match_type = 'alias';
            $alias->match_reason = 'naamalias (' . $alias->alias_type . ')';
            $matches[$member_id] = $alias;
        }

        return array_values($matches);
    }

    // -------------------------------------------------------------------
    // Central address normalization and history rules
    // -------------------------------------------------------------------

    public static function normalize_address(array $address): array {
        global $wpdb;
        $city_aliases = [];
        foreach ($wpdb->get_results("SELECT * FROM {$wpdb->prefix}avm_city_aliases") ?: [] as $alias) {
            $key = AVPVH_Normalization::fold($alias->country)
                . '|' . AVPVH_Normalization::fold($alias->alias_name);
            $city_aliases[$key] = $alias->canonical_name;
        }
        $street_aliases = [];
        foreach ($wpdb->get_results("SELECT * FROM {$wpdb->prefix}avm_street_aliases") ?: [] as $alias) {
            $key = implode('|', [
                AVPVH_Normalization::fold($alias->country),
                AVPVH_Normalization::fold(AVPVH_Normalization::normalize_postal_code($alias->postal_code)),
                AVPVH_Normalization::fold($alias->city),
                AVPVH_Normalization::fold($alias->alias_street),
            ]);
            $street_aliases[$key] = $alias->canonical_street;
        }
        return AVPVH_Normalization::normalize_address($address, $city_aliases, $street_aliases);
    }

    public static function add_member_address(
        int $member_id,
        array $address,
        ?string $valid_from = null
    ): int {
        global $wpdb;
        $valid_from = $valid_from ?: current_time('Y-m-d');
        $normalized = self::normalize_address($address);
        $current = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avm_addresses
             WHERE member_id = %d
               AND (valid_from IS NULL OR valid_from <= %s)
               AND (valid_until IS NULL OR valid_until >= %s)
             ORDER BY valid_from DESC, id DESC",
            $member_id,
            $valid_from,
            $valid_from
        )) ?: [];

        foreach ($current as $existing) {
            if (self::normalize_address((array) $existing)['normalized_key'] === $normalized['normalized_key']) {
                return (int) $existing->id;
            }
        }

        $previous_day = wp_date('Y-m-d', strtotime($valid_from . ' -1 day'));
        foreach ($current as $existing) {
            $wpdb->update(
                "{$wpdb->prefix}avm_addresses",
                ['valid_until' => $previous_day],
                ['id' => $existing->id, 'member_id' => $member_id],
                ['%s'],
                ['%d', '%d']
            );
        }

        $inserted = $wpdb->insert(
            "{$wpdb->prefix}avm_addresses",
            [
                'member_id' => $member_id,
                'street' => trim((string) ($address['street'] ?? '')),
                'house_number' => trim((string) ($address['house_number'] ?? '')),
                'postal_code' => trim((string) ($address['postal_code'] ?? '')),
                'city' => trim((string) ($address['city'] ?? '')),
                'country' => trim((string) ($address['country'] ?? 'Nederland')) ?: 'Nederland',
                'valid_from' => $valid_from,
                'valid_until' => null,
            ]
        );
        return $inserted ? (int) $wpdb->insert_id : 0;
    }

    public static function get_current_address_overlaps(?string $as_of = null): array {
        global $wpdb;
        $today = $as_of ?: current_time('Y-m-d');
        return $wpdb->get_results($wpdb->prepare(
            "SELECT member_id, COUNT(*) AS current_count
             FROM {$wpdb->prefix}avm_addresses
             WHERE (valid_from IS NULL OR valid_from <= %s)
               AND (valid_until IS NULL OR valid_until >= %s)
             GROUP BY member_id HAVING COUNT(*) > 1 ORDER BY member_id",
            $today,
            $today
        )) ?: [];
    }

    public static function get_members(array $args = []): array {
        global $wpdb;
        $lldap  = self::lldap();
        $where  = '1=1';
        $params = [];
        $join   = '';

        if (!empty($args['search'])) {
            $s      = '%' . $wpdb->esc_like($args['search']) . '%';
            $where .= " AND (
                m.last_name LIKE %s OR m.first_name LIKE %s OR u.email LIKE %s
                OR EXISTS (
                    SELECT 1 FROM {$wpdb->prefix}avm_member_name_aliases na
                    WHERE na.member_id = m.id
                      AND (na.first_name LIKE %s OR na.suffix LIKE %s OR na.last_name LIKE %s
                           OR CONCAT_WS(' ', na.first_name, na.suffix, na.last_name) LIKE %s)
                )
            )";
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
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
        // (array) on a plain string (e.g. the many existing get_members(['status'
        // => 'active']) call sites elsewhere) wraps it to a 1-item array, so this
        // stays backward compatible with a single value as well as a checkbox list.
        $statuses = array_values(array_intersect((array) ($args['status'] ?? []), ['active', 'inactive', 'visitor']));
        if ($statuses) {
            $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
            $where .= " AND m.status IN ($placeholders)";
            array_push($params, ...$statuses);
        }
        if (!empty($args['joined_year'])) {
            $where .= ' AND m.joined_year = %d';
            $params[] = (int) $args['joined_year'];
        }
        // Any-of match — a member showing up under any one of the checked
        // flags is enough, same OR semantics as the fee_status checkboxes below.
        $flag_ids = array_values(array_filter(array_map('intval', (array) ($args['flag_id'] ?? []))));
        if ($flag_ids) {
            $placeholders = implode(',', array_fill(0, count($flag_ids), '%d'));
            $where .= " AND EXISTS (SELECT 1 FROM {$wpdb->prefix}avm_member_flag_assignments fa WHERE fa.member_id = m.id AND fa.flag_id IN ($placeholders))";
            array_push($params, ...$flag_ids);
        }
        $fee_year = !empty($args['fee_year']) ? (int) $args['fee_year'] : (int) current_time('Y');

        // Also any-of — e.g. "pending" + "none" together to find everyone
        // who doesn't have a paid/waived record yet.
        $fee_statuses = array_values(array_intersect(
            (array) ($args['fee_status'] ?? []),
            ['paid', 'pending', 'waived', 'none']
        ));
        if ($fee_statuses) {
            $join .= " LEFT JOIN {$wpdb->prefix}avm_fees f ON f.member_id = m.id AND f.year = $fee_year";
            $conditions = [];
            $specific = array_diff($fee_statuses, ['none']);
            if (in_array('none', $fee_statuses, true)) {
                $conditions[] = 'f.id IS NULL';
            }
            if ($specific) {
                $placeholders = implode(',', array_fill(0, count($specific), '%s'));
                $conditions[] = "f.status IN ($placeholders)";
                array_push($params, ...array_values($specific));
            }
            $where .= ' AND (' . implode(' OR ', $conditions) . ')';
        }

        $allowed_order = ['last_name', 'suffix_last_name', 'first_name', 'status', 'joined_year', 'fee_status', 'activity_count'];
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
            'activity_count' => "(SELECT COUNT(*) FROM {$wpdb->prefix}avm_activity_participation WHERE member_id = m.id) $order, m.last_name ASC",
            default       => "m.last_name ASC, m.first_name ASC",
        };

        // $where/$join above are built from hardcoded column names and %s/%d
        // placeholders (real values go through $params -> prepare() below);
        // $order_sql is one of a fixed set of literal strings from the match()
        // above, gated by the $allowed_order whitelist a few lines up. Block
        // form since this sniff flags the assignment and the final
        // get_results() call separately.
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $sql = self::member_select() . $join . " WHERE $where ORDER BY $order_sql";
        if ($params) {
            $sql = $wpdb->prepare($sql, $params);
        }
        return $wpdb->get_results($sql) ?: [];
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
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

    /**
     * Finds or provisions the WP_User a member logs in as — shared by every
     * login path (Google/Microsoft OAuth, e-mail-link). WP users here exist
     * solely so WordPress can maintain a session; members never set a WP
     * password directly.
     */
    public static function get_or_create_wp_user(string $email, object $member): ?\WP_User {
        if ($member->wp_user_id) {
            $user = get_user_by('id', (int) $member->wp_user_id);
            if ($user) {
                return $user;
            }
        }

        $user = get_user_by('email', $email);
        if (!$user) {
            $uid = wp_create_user(
                sanitize_user(strstr($email, '@', true)),
                wp_generate_password(64),
                $email
            );
            if (is_wp_error($uid)) {
                return null;
            }
            wp_update_user([
                'ID'           => $uid,
                'display_name' => avpvh_format_name($member),
            ]);
            self::set_wp_user_id((int) $member->id, $uid);
            $user = get_user_by('id', $uid);
        } else {
            self::set_wp_user_id((int) $member->id, $user->ID);
        }

        return $user ?: null;
    }

    // -------------------------------------------------------------------
    // Address / activity / fee helpers — unchanged from original
    // -------------------------------------------------------------------

    public static function get_members_with_address(int $viewer_member_id = 0, bool $viewer_sees_minors = false): array {
        global $wpdb;
        $lldap = self::lldap();
        $today = current_time('Y-m-d');
        // $lldap/$wpdb->prefix below are fixed, hardcoded identifiers (AVPVH_LLDAP_DB
        // constant / WP's own table prefix), never user input; the two %s values
        // are properly prepared — phpcs:disable, block form since the flagged
        // interpolation is deep inside a multi-line string literal, too far from
        // any single line an inline phpcs:ignore comment could attach to.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
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
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

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

    public static function get_activities_for_member(int $member_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT c.*, p.nights, p.nawacht, p.diet, p.notes
             FROM {$wpdb->prefix}avm_activity_participation p
             JOIN {$wpdb->prefix}avm_activities c ON c.id = p.activity_id
             WHERE p.member_id = %d
             ORDER BY c.year DESC",
            $member_id
        )) ?: [];
    }

    // -------------------------------------------------------------------
    // Activity participation ("Activiteitdeelname")
    // -------------------------------------------------------------------

    public static function get_activities(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT c.*, t.name AS type_name
             FROM {$wpdb->prefix}avm_activities c
             LEFT JOIN {$wpdb->prefix}avm_activity_types t ON t.id = c.type_id
             ORDER BY c.year DESC, c.name ASC"
        ) ?: [];
    }

    public static function get_activity(int $activity_id): ?object {
        global $wpdb;
        $activity = $wpdb->get_row($wpdb->prepare(
            "SELECT c.*, t.name AS type_name
             FROM {$wpdb->prefix}avm_activities c
             LEFT JOIN {$wpdb->prefix}avm_activity_types t ON t.id = c.type_id
             WHERE c.id = %d",
            $activity_id
        ));
        return $activity ?: null;
    }

    // -------------------------------------------------------------------
    // Activity types — admin-editable list backing avm_activities.type_id
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
    public static function get_current_activity(?int $type_id = null): ?object {
        global $wpdb;
        if ($type_id) {
            $activity = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}avm_activities WHERE type_id = %d ORDER BY year DESC, id DESC LIMIT 1",
                $type_id
            ));
        } else {
            $activity = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}avm_activities ORDER BY year DESC, id DESC LIMIT 1");
        }
        return $activity ?: null;
    }

    /**
     * The most recent actual camp — unlike get_current_activity()
     * (unfiltered "most recent activity", which can now just as easily
     * resolve to "Contributie {year}" since that lives in the same table),
     * this always means an actual Kamp-type activity. Needed wherever "the
     * current camp" is specifically about participation/attendance, which
     * doesn't apply to contribution or other non-participation activities.
     */
    public static function get_current_camp_activity(): ?object {
        $kamp_type = current(array_filter(
            self::get_activity_types(),
            fn($t) => $t->name === 'Kamp'
        ));
        return $kamp_type ? self::get_current_activity((int) $kamp_type->id) : null;
    }

    /** Read-only lookup by name+year — unlike get_or_create_activity(), never creates one (e.g. avpvh-bookkeeping checking whether a "Contributie {year}" activity has been set up yet, without silently conjuring an empty one). */
    public static function get_activity_by_name_year(string $name, int $year): ?object {
        global $wpdb;
        $activity = $wpdb->get_row($wpdb->prepare(
            "SELECT c.*, t.name AS type_name
             FROM {$wpdb->prefix}avm_activities c
             LEFT JOIN {$wpdb->prefix}avm_activity_types t ON t.id = c.type_id
             WHERE c.name = %s AND c.year = %d",
            $name, $year
        ));
        return $activity ?: null;
    }

    public static function get_or_create_activity(string $name, int $year, string $kenmerk = '', ?string $start_date = null, ?string $end_date = null, int $type_id = 0): int {
        global $wpdb;
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}avm_activities WHERE name = %s AND year = %d", $name, $year
        ));
        if ($id) {
            return (int) $id;
        }
        $wpdb->insert("{$wpdb->prefix}avm_activities", [
            'name' => $name, 'year' => $year, 'kenmerk' => $kenmerk,
            'start_date' => $start_date, 'end_date' => $end_date,
            'type_id' => $type_id ?: null,
        ]);
        return (int) $wpdb->insert_id;
    }

    /** All participation rows for one activity, joined with member name/status. */
    public static function get_participation_for_activity(int $activity_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, m.first_name, m.suffix, m.last_name, m.status AS member_status
             FROM {$wpdb->prefix}avm_activity_participation p
             JOIN {$wpdb->prefix}avm_members m ON m.id = p.member_id
             WHERE p.activity_id = %d
             ORDER BY m.last_name ASC, m.first_name ASC",
            $activity_id
        )) ?: [];
    }

    public static function get_participation(int $member_id, int $activity_id): ?object {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avm_activity_participation WHERE member_id = %d AND activity_id = %d",
            $member_id, $activity_id
        ));
        return $row ?: null;
    }

    public static function get_participation_by_id(int $participation_id): ?object {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avm_activity_participation WHERE id = %d", $participation_id
        ));
        return $row ?: null;
    }

    /** Day statuses for one participation record, keyed by 'Y-m-d'. */
    public static function get_participation_days(int $participation_id): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT date, status FROM {$wpdb->prefix}avm_activity_participation_days WHERE participation_id = %d",
            $participation_id
        )) ?: [];
        $days = [];
        foreach ($rows as $row) {
            $days[$row->date] = $row->status;
        }
        return $days;
    }

    /**
     * Create or update a member's participation record for an activity.
     * Returns the participation id.
     */
    public static function save_participation(int $member_id, int $activity_id, array $fields): int {
        global $wpdb;
        $data = [
            'nights'  => $fields['nights'] !== '' && $fields['nights'] !== null ? (int) $fields['nights'] : null,
            'nawacht' => !empty($fields['nawacht']) ? 1 : 0,
            'diet'    => $fields['diet'] ?? '',
            'notes'   => $fields['notes'] ?? '',
        ];
        $existing = self::get_participation($member_id, $activity_id);
        if ($existing) {
            $wpdb->update("{$wpdb->prefix}avm_activity_participation", $data, ['id' => $existing->id]);
            do_action('avpvh_activity_participation_saved', $member_id, $activity_id, (int) $existing->id);
            return (int) $existing->id;
        }
        $wpdb->insert("{$wpdb->prefix}avm_activity_participation", [
            'member_id' => $member_id, 'activity_id' => $activity_id,
        ] + $data);
        $participation_id = (int) $wpdb->insert_id;
        do_action('avpvh_activity_participation_saved', $member_id, $activity_id, $participation_id);
        return $participation_id;
    }

    /** Replace all day rows for a participation record with $days (date => status). */
    public static function save_participation_days(int $participation_id, array $days): void {
        global $wpdb;
        $table = "{$wpdb->prefix}avm_activity_participation_days";
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
        $wpdb->delete("{$wpdb->prefix}avm_activity_participation_days", ['participation_id' => $participation_id]);
        $wpdb->delete("{$wpdb->prefix}avm_activity_participation", ['id' => $participation_id]);
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
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is a %d run built to match count($other_ids); PHPCS can't verify the count statically but prepare() gets exactly one %d per array element
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

    /**
     * True if this exact relationship — or its mirror image from the other
     * person's side, via the label's inverse_id ("A is kind van B" and
     * "B is ouder van A" are the same fact, just stored from opposite
     * ends) — is already recorded with the same validity period.
     * A DB-level UNIQUE constraint can't express the mirror case, and
     * wouldn't even catch the plain duplicate here: MySQL treats
     * NULL <> NULL, so two open-ended (valid_from/valid_until both NULL)
     * rows for the same pair+label already sail through one. Date range is
     * part of the match on purpose — a genuinely new, differently-dated
     * instance of the same two people/label (e.g. temporary voogdij for
     * one kamp, then again for a later one) is not a duplicate.
     */
    public static function relationship_exists(
        int $member_id, int $related_member_id, int $label_id,
        ?string $valid_from, ?string $valid_until
    ): bool {
        global $wpdb;
        $inverse_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT inverse_id FROM {$wpdb->prefix}avm_relationship_labels WHERE id = %d", $label_id
        ));

        // $sql below is built up via %d/%s placeholders, with matching $params —
        // structural fragments (the OR clause, IS NULL vs = %s) are chosen from
        // fixed literal strings, never user input. Block form since this sniff
        // flags both the assignment and the final prepare() call separately.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $sql = "SELECT COUNT(*) FROM {$wpdb->prefix}avm_relationships
                WHERE ((member_id = %d AND related_member_id = %d AND label_id = %d)";
        $params = [$member_id, $related_member_id, $label_id];
        if ($inverse_id) {
            $sql .= " OR (member_id = %d AND related_member_id = %d AND label_id = %d)";
            array_push($params, $related_member_id, $member_id, $inverse_id);
        }
        $sql .= ') AND ' . ($valid_from ? 'valid_from = %s' : 'valid_from IS NULL');
        if ($valid_from) {
            $params[] = $valid_from;
        }
        $sql .= ' AND ' . ($valid_until ? 'valid_until = %s' : 'valid_until IS NULL');
        if ($valid_until) {
            $params[] = $valid_until;
        }

        return (int) $wpdb->get_var($wpdb->prepare($sql, $params)) > 0;
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    /** Returns false both on a genuine insert failure and when this relationship (or its mirror) already exists — see relationship_exists(). */
    public static function add_relationship(
        int $member_id, int $related_member_id, int $label_id,
        ?string $valid_from = null, ?string $valid_until = null,
        string $note = '', int $created_by = 0
    ): bool {
        global $wpdb;
        if (self::relationship_exists($member_id, $related_member_id, $label_id, $valid_from, $valid_until)) {
            return false;
        }
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
        return self::has_same_address($member_id_1, $member_id_2);
    }

    /**
     * Literal same-address check, split out of is_same_household() so
     * callers can tell "family" and "actually lives there" apart — e.g. the
     * profile summary card's "Huisgenoten:" vs "Familie (elders wonend):"
     * grouping, once a child has moved out but is still manageable via the
     * family branch above.
     */
    public static function has_same_address(int $member_id_1, int $member_id_2): bool {
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
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- fully static query, no dynamic values at all
        $active = $wpdb->get_results(
            self::member_select() . " WHERE m.status = 'active' ORDER BY m.last_name, m.first_name"
        ) ?: [];
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $manageable = [];
        foreach ($active as $m) {
            if (self::is_same_household($member_id, (int) $m->id)) {
                $manageable[] = $m;
            }
        }
        return $manageable;
    }

    /**
     * Wider than get_manageable_members() — also includes each household
     * member's own partner ("vriend(in)/partner/man/vrouw van", the
     * 'partner' relationship category), even when that partner isn't
     * $member_id's own direct family or housemate (e.g. a child's
     * girlfriend, who lives elsewhere, and may not even be a full member —
     * often just a visitor). A partner isn't a blood/adoptive relative, so
     * this started out suggestion-only (avpvh-bookkeeping's review queue),
     * but is now also used for real access control in avpvh-bookkeeping's
     * balance shortcode (viewing/paying a household member's partner's
     * open items from the profile page) — an explicit, deliberate choice:
     * someone able to manage their own household's finances can reasonably
     * manage a housemate's partner's too, since that partner often has no
     * one else to do it for them. Excludes inactive (former) members, same
     * as get_manageable_members() effectively does, but does include
     * visitors.
     */
    public static function get_extended_household(int $member_id): array {
        $household = self::get_manageable_members($member_id);
        $extended = [];
        foreach ($household as $m) {
            $extended[(int) $m->id] = $m;
        }

        $today = current_time('Y-m-d');
        foreach ($household as $m) {
            foreach (self::get_relationships((int) $m->id) as $rel) {
                if ($rel->category !== 'partner') {
                    continue;
                }
                if ($rel->valid_from && $rel->valid_from > $today) {
                    continue;
                }
                if ($rel->valid_until && $rel->valid_until < $today) {
                    continue;
                }
                $other_id = (int) $rel->other_id;
                if (isset($extended[$other_id])) {
                    continue;
                }
                $partner = self::get_member($other_id);
                if ($partner && $partner->status !== 'inactive') {
                    $extended[$other_id] = $partner;
                }
            }
        }
        return array_values($extended);
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

    /** Official-name or alias match used by the "Nieuw lid" duplicate warning. */
    public static function find_members_by_name(string $first_name, string $last_name): array {
        return self::find_members_by_name_or_alias($first_name, '', $last_name);
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

    // -------------------------------------------------------------------
    // Member flags — extendable tag catalog (ere-lid, archeoloog, ...)
    // -------------------------------------------------------------------

    public static function get_all_flags(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}avm_member_flags ORDER BY sort_order, label"
        ) ?: [];
    }

    public static function get_flags_for_member(int $member_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT f.* FROM {$wpdb->prefix}avm_member_flags f
             JOIN {$wpdb->prefix}avm_member_flag_assignments a ON a.flag_id = f.id
             WHERE a.member_id = %d
             ORDER BY f.sort_order, f.label",
            $member_id
        )) ?: [];
    }

    public static function member_has_flag(int $member_id, string $slug): bool {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}avm_member_flag_assignments a
             JOIN {$wpdb->prefix}avm_member_flags f ON f.id = a.flag_id
             WHERE a.member_id = %d AND f.slug = %s LIMIT 1",
            $member_id, $slug
        ));
    }

    /** True if $member_id has any flag with affects_fees=1 (e.g. ere-lid) — read by avpvh-bookkeeping to skip contribution generation. */
    public static function member_is_fee_exempt(int $member_id): bool {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}avm_member_flag_assignments a
             JOIN {$wpdb->prefix}avm_member_flags f ON f.id = a.flag_id
             WHERE a.member_id = %d AND f.affects_fees = 1 LIMIT 1",
            $member_id
        ));
    }

    /** Replace-all save for a checkbox-group flags form. */
    public static function set_member_flags(int $member_id, array $flag_ids): void {
        global $wpdb;
        $flag_ids = array_values(array_unique(array_map('intval', $flag_ids)));

        $wpdb->delete("{$wpdb->prefix}avm_member_flag_assignments", ['member_id' => $member_id], ['%d']);
        foreach ($flag_ids as $flag_id) {
            if ($flag_id > 0) {
                $wpdb->insert(
                    "{$wpdb->prefix}avm_member_flag_assignments",
                    ['member_id' => $member_id, 'flag_id' => $flag_id],
                    ['%d', '%d']
                );
            }
        }

        // A flag like 'geroyeerd' forces status to 'inactive' the moment
        // it's assigned (sets_inactive=1 on the flag itself — see the 2.18
        // migration). Never the reverse: removing such a flag doesn't
        // restore 'active', since that's a real membership decision an
        // admin should make deliberately, not a side effect of unchecking
        // a box.
        if ($flag_ids) {
            $placeholders = implode(',', array_fill(0, count($flag_ids), '%d'));
            $forces_inactive = (bool) $wpdb->get_var($wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is a %d run built to match count($flag_ids); PHPCS can't verify the count statically but prepare() gets exactly one %d per array element
                "SELECT 1 FROM {$wpdb->prefix}avm_member_flags WHERE id IN ($placeholders) AND sets_inactive = 1 LIMIT 1",
                $flag_ids
            ));
            if ($forces_inactive) {
                $member = self::get_member($member_id);
                if ($member && $member->status !== 'inactive') {
                    self::update_member_with_audit($member_id, ['status' => 'inactive'], ['%s']);
                }
            }
        }
    }

    /** Toggles a single flag (by slug) for a member — used by the self-service nieuwsbrief checkbox, which shouldn't touch anyone else's flags. */
    public static function set_member_flag_by_slug(int $member_id, string $slug, bool $on): void {
        global $wpdb;
        $flag_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}avm_member_flags WHERE slug = %s", $slug
        ));
        if (!$flag_id) {
            return;
        }
        if ($on) {
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->prefix}avm_member_flag_assignments (member_id, flag_id) VALUES (%d, %d)",
                $member_id, $flag_id
            ));
        } else {
            $wpdb->delete(
                "{$wpdb->prefix}avm_member_flag_assignments",
                ['member_id' => $member_id, 'flag_id' => $flag_id],
                ['%d', '%d']
            );
        }
    }

    /** Adds a new flag to the catalog (admin UI, "extendable" per the club's own ad-hoc categories). Returns the new flag id, or 0 on failure (e.g. duplicate slug). */
    public static function create_flag(string $slug, string $label, bool $affects_fees = false, bool $sets_inactive = false): int {
        global $wpdb;
        $slug = sanitize_title($slug);
        if (!$slug || !$label) {
            return 0;
        }
        $max_sort = (int) $wpdb->get_var("SELECT MAX(sort_order) FROM {$wpdb->prefix}avm_member_flags");
        $inserted = $wpdb->insert(
            "{$wpdb->prefix}avm_member_flags",
            [
                'slug'          => $slug,
                'label'         => $label,
                'affects_fees'  => $affects_fees ? 1 : 0,
                'sets_inactive' => $sets_inactive ? 1 : 0,
                'sort_order'    => $max_sort + 1,
            ],
            ['%s', '%s', '%d', '%d', '%d']
        );
        return $inserted ? (int) $wpdb->insert_id : 0;
    }

    public static function delete_flag(int $flag_id): void {
        global $wpdb;
        $wpdb->delete("{$wpdb->prefix}avm_member_flag_assignments", ['flag_id' => $flag_id], ['%d']);
        $wpdb->delete("{$wpdb->prefix}avm_member_flags", ['id' => $flag_id], ['%d']);
    }
}

function avpvh_get_member_by_wp_user(int $user_id): ?object {
    return AVPVH_DB::get_member_by_wp_user($user_id);
}

/**
 * Format a member's full name.
 *
 * 'full'         → "Marie van der Berg"
 * 'list'         → "Berg, Marie"          (sort/display by last name)
 * 'list_suffix'  → "van der Berg, Marie"  (sort/display by suffix + last name)
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
