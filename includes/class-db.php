<?php
defined('ABSPATH') || exit;

require_once __DIR__ . '/class-db-migrations.php';

class AVPVH_DB {

    /** The schema install() creates; maybe_upgrade() ends at this version. */
    const SCHEMA_VERSION = '2.34';

    /**
     * Table holding uid/email/display_name for JOINs: the plugin's cache of
     * OpenLDAP (user_id, email, lowercase_email, display_name). See
     * AVPVH_Directory_Cache.
     */
    public static function identity_table(): string {
        return AVPVH_Directory_Cache::table();
    }

    public static function install(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // pvh_avm_members: business data only — email lives in the directory (avm_directory_users)
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
            death_date DATE NULL,
            is_student TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            phone VARCHAR(30) NOT NULL DEFAULT '',
            mobile VARCHAR(30) NOT NULL DEFAULT '',
            emergency_contact VARCHAR(200) NOT NULL DEFAULT '',
            emergency_member_id INT UNSIGNED NULL,
            emergency_relation VARCHAR(100) NOT NULL DEFAULT '',
            emergency_phone VARCHAR(30) NOT NULL DEFAULT '',
            emergency_address VARCHAR(255) NOT NULL DEFAULT '',
            diet VARCHAR(255) NOT NULL DEFAULT '',
            status ENUM('active','inactive','visitor') NOT NULL DEFAULT 'active',
            joined_year YEAR NULL,
            left_year YEAR NULL,
            archive_access TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
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
            KEY wp_user_id (wp_user_id),
            KEY emergency_member_id (emergency_member_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$wpdb->prefix}avm_addresses (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id INT UNSIGNED NOT NULL,
            street VARCHAR(150) NOT NULL DEFAULT '',
            house_number VARCHAR(20) NOT NULL DEFAULT '',
            house_number_addition VARCHAR(20) NOT NULL DEFAULT '',
            postal_code VARCHAR(20) NOT NULL DEFAULT '',
            city VARCHAR(100) NOT NULL DEFAULT '',
            country VARCHAR(100) NOT NULL DEFAULT 'Nederland',
            valid_from DATE NULL,
            valid_until DATE NULL,
            member_entered TINYINT(1) NOT NULL DEFAULT 0,
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

        dbDelta("CREATE TABLE {$wpdb->prefix}avm_activities (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(150) NOT NULL DEFAULT '',
            type_id INT UNSIGNED NULL,
            year YEAR NOT NULL,
            kenmerk VARCHAR(150) NOT NULL DEFAULT '',
            gallery_taggable TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            start_date DATE NULL,
            end_date DATE NULL,
            location TEXT NULL,
            description TEXT NULL,
            details TEXT NULL,
            show_on_site TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
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
        // camp) — see AVPVH_Roles. The real role lives in OpenLDAP group
        // membership; this table only layers a time-boxed exception on top,
        // so the OpenLDAP group itself never needs to change for a handoff.
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

        // Verified alternate spellings/forms of a member's name — never a
        // second member record. See AVPVH_Name_Matcher::normalize_person_name()
        // for how normalized_key is derived; deliberately NOT unique across
        // members (the same alias existing on two different members is a
        // real ambiguity for a human to resolve, not a constraint violation
        // to silently prevent — see plan.md §1/§3).
        // normalized_key_hash = sha256(normalized_key): production also has a
        // UNIQUE (member_id, normalized_key_hash) from an earlier schema, so
        // every insert must fill it (see save_name_alias()).
        dbDelta("CREATE TABLE {$wpdb->prefix}avm_member_name_aliases (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id INT UNSIGNED NOT NULL,
            first_name VARCHAR(100) NOT NULL DEFAULT '',
            suffix VARCHAR(50) NOT NULL DEFAULT '',
            last_name VARCHAR(100) NOT NULL DEFAULT '',
            alias_type ENUM('maiden','married','nickname','spelling','abbreviation','historical') NOT NULL DEFAULT 'historical',
            normalized_key VARCHAR(160) NOT NULL DEFAULT '',
            normalized_key_hash CHAR(64) NOT NULL DEFAULT '',
            valid_from DATE NULL,
            valid_until DATE NULL,
            source VARCHAR(150) NOT NULL DEFAULT '',
            note VARCHAR(255) NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY member_alias (member_id, normalized_key),
            KEY normalized_key (normalized_key)
        ) $charset;");

        // Local copy of uid/email/display_name from OpenLDAP for SQL JOINs,
        // see AVPVH_Directory_Cache.
        dbDelta("CREATE TABLE {$wpdb->prefix}avm_directory_users (
            user_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
            email VARCHAR(255) NOT NULL DEFAULT '',
            lowercase_email VARCHAR(255) NOT NULL DEFAULT '',
            display_name VARCHAR(255) NOT NULL DEFAULT '',
            synced_at DATETIME NOT NULL,
            PRIMARY KEY (user_id),
            KEY lowercase_email (lowercase_email)
        ) $charset;");

        // Note: avm_registrations / avm_registration_attendance /
        // avm_sync_conflicts (a never-launched Google-Forms-based signup +
        // sync system, unlinked to real members) were removed in favour of
        // avm_camp_participation(_days) above — see the 2.1 migration below,
        // which drops them for existing installs.
    }

    /**
     * Activation: a site without the plugin's tables gets the current
     * schema and its seed rows at once, instead of every migration since
     * 1.2 running against a schema that already has their changes.
     */
    public static function activate(): void {
        if (self::is_fresh()) {
            self::install_fresh();
            return;
        }
        self::install();
    }

    private static function is_fresh(): bool {
        global $wpdb;
        return get_option('avpvh_db_version', '0') === '0'
            && !$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . 'avm_members'));
    }

    private static function install_fresh(): void {
        self::install();
        self::seed_activity_types();
        self::append_activity_types();
        self::seed_relationship_labels();
        self::seed_member_flags();
        update_option('avpvh_db_version', self::SCHEMA_VERSION);
    }

    /** The relationship labels and their inverses (idempotent). */
    private static function seed_relationship_labels(): void {
        global $wpdb;
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
    }

    /**
     * The original activity types (idempotent).
     *
     * @return array<string, int> name => id
     */
    private static function seed_activity_types(): array {
        global $wpdb;
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
        return $ids;
    }

    /** The activity types added in 2.13, after the existing ones (idempotent). */
    private static function append_activity_types(): void {
        global $wpdb;
        foreach (['Contributie', 'Drank', 'Eten', 'Boek', 'T-shirt', 'Congres'] as $name) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}avm_activity_types WHERE name = %s", $name
            ));
            if (!$existing) {
                $max_sort = (int) $wpdb->get_var("SELECT COALESCE(MAX(sort_order), 0) FROM {$wpdb->prefix}avm_activity_types");
                $wpdb->insert("{$wpdb->prefix}avm_activity_types", ['name' => $name, 'sort_order' => $max_sort + 1]);
            }
        }
    }

    /** The initial member flag catalog (idempotent). */
    private static function seed_member_flags(): void {
        global $wpdb;
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
    }

    public static function maybe_upgrade(): void {
        if (self::is_fresh()) {
            self::install_fresh();
            return;
        }
        $version = get_option('avpvh_db_version', '0');
        foreach (self::upgrade_steps() as $target => $step) {
            if (version_compare($version, (string) $target, '<')) {
                $step();
                update_option('avpvh_db_version', (string) $target);
            }
        }
    }

    /**
     * Every schema version after 1.0 and what takes an existing site there,
     * in order. The changes themselves are in AVPVH_DB_Migrations.
     *
     * @return array<string, callable(): void>
     */
    private static function upgrade_steps(): array {
        $install = self::install(...);
        $run = static fn(string $version): \Closure => static fn() => AVPVH_DB_Migrations::run($version);
        $add_column = static fn(string $version): \Closure => static fn() => AVPVH_DB_Migrations::add_missing_column($version);
        $normalize_addresses = static fn() => self::normalize_stored_addresses();
        return [
            '1.2' => $install,
            '1.3' => $run('1.3'),
            '1.4' => $install,
            '1.5' => $run('1.5'),
            '1.6' => $install,
            '1.7' => $run('1.7'),
            '1.8' => $run('1.8'),
            '1.9' => $run('1.9'),
            '2.0' => $run('2.0'),
            '2.1' => $run('2.1'),
            '2.2' => $run('2.2'),
            '2.3' => AVPVH_DB_Migrations::create_participation_days(...),
            '2.4' => $run('2.4'),
            '2.5' => static fn() => self::migrate_to_relationships_table(),
            // Broadens avm_camps beyond just the annual dig — a "kamp" row's
            // real dates are also the natural period for a temporary voogd
            // assignment (see the relationships add-form's activity picker),
            // and covers weekends/outings too now, not just kampen. Types
            // live in their own table (avm_activity_types) rather than a
            // fixed ENUM, so club admins can rename or add types later.
            '2.6' => static fn() => self::migrate_to_activity_types_table(),
            '2.7' => AVPVH_DB_Migrations::fold_baptism_name(...),
            '2.8' => AVPVH_DB_Migrations::strip_passport_brackets(...),
            '2.9' => AVPVH_DB_Migrations::create_role_delegations(...),
            '2.10' => $add_column('2.10'),
            '2.11' => $add_column('2.11'),
            '2.12' => $add_column('2.12'),
            // "Everything you can be asked to contribute money for is an
            // activity" — contribution, drank, t-shirts etc. join camps as
            // named activity types, not just Kamp/Weekend/Uitje/Wandeling/
            // Feest/Anders. Skip-if-exists, same pattern as the original
            // seed in migrate_to_activity_types_table() below.
            '2.13' => static function () use ($add_column): void {
                self::append_activity_types();
                $add_column('2.13')();
            },
            '2.14' => AVPVH_DB_Migrations::rename_camp_tables(...),
            '2.15' => $add_column('2.15'),
            '2.16' => $run('2.16'),
            // install() alone can't seed rows safely on repeat activation —
            // dbDelta only handles structure — so run it here to create the
            // two new tables on already-active installs, then seed the
            // initial flag catalog. 'ere-lid' is the only one with
            // affects_fees=1 for now; see AVBK_Fee_Generation in
            // avpvh-bookkeeping for where that's read.
            '2.17' => static function (): void {
                self::install();
                self::seed_member_flags();
            },
            '2.18' => $add_column('2.18'),
            '2.19' => $add_column('2.19'),
            // 2.21: 2.19 is gallery_taggable above (live before it was
            // merged). install() alone can't create a new table on an
            // already-active site — dbDelta only handles structure, not
            // seeding — so run it here too, same pattern as 2.17 above.
            '2.21' => $install,
            // Directory cache table (avm_directory_users). Was 2.20 on
            // feature/openldap-directory, but production reached 2.21 first,
            // so a 2.20 here would be skipped there.
            '2.22' => $install,
            '2.23' => $run('2.23'),
            // Activities on the website (AVPVH_Activity_List): where, what
            // (rich text) and whether to list it at all.
            '2.24' => $install,
            // Emergency contact split up: emergency_contact keeps the name
            // (existing free text stays there), plus relation, phone and
            // address of that person.
            '2.25' => $install,
            // Emergency contact can be another member (emergency_member_id);
            // their name, phone and address are then used instead of the
            // typed-in fields.
            '2.26' => $install,
            // House number addition in its own column ("40-1 hoog" -> 40 /
            // 1), so it can be compared, sorted and sent on as the BAG and
            // PostNL expect.
            '2.27' => static function (): void {
                self::install();
                self::split_house_number_additions();
            },
            // Country names in one spelling, European postcodes without a
            // country prefix, Belgian/Luxembourg numbers out of the street.
            '2.28' => $normalize_addresses,
            // Same pass again: clean() now also takes a number with a floor
            // note ("Lei 19 (2e v.)") out of a Belgian street.
            '2.29' => $normalize_addresses,
            // Date of death (avm_members.death_date): set_death_date() ends
            // the member's addresses on it.
            '2.30' => $install,
            // Same pass again: clean() now also takes a Dutch house number
            // (one to three digits) out of the street when it is empty.
            '2.31' => $normalize_addresses,
            '2.32' => AVPVH_DB_Migrations::drop_lldap_settings(...),
            // avm_addresses.member_entered: an address a member entered
            // themselves gives no household rights until the board confirms
            // it. Existing rows stay 0 (confirmed).
            '2.33' => $install,
            // avm_members.archive_access: the board lets an ex-member see
            // the posts and photos of their membership years. Off for all.
            '2.34' => $install,
        ];
    }

    /**
     * 2.28: runs every address row through AVPVH_Address_Normalizer::clean()
     * and writes back what changed. clean() is idempotent, so a rerun only
     * catches rows saved some other way since.
     */
    private static function normalize_stored_addresses(): void {
        global $wpdb;
        $table = "{$wpdb->prefix}avm_addresses";
        $fields = ['street', 'house_number', 'house_number_addition', 'postal_code', 'city', 'country'];
        foreach ($wpdb->get_results("SELECT * FROM {$table}") ?: [] as $row) {
            $stored = array_intersect_key((array) $row, array_flip($fields));
            $cleaned = array_intersect_key(AVPVH_Address_Normalizer::clean($stored), $stored);
            $changed = array_diff_assoc(array_map('strval', $cleaned), array_map('strval', $stored));
            if ($changed) {
                $wpdb->update($table, $changed, ['id' => (int) $row->id]);
            }
        }
    }

    /**
     * 2.27: moves the addition out of every house_number into
     * house_number_addition (AVPVH_Address_Normalizer::split_house_number()).
     * Rows without leading digits ("ong.") keep their text in house_number.
     * Rows that already have an addition are left alone, so a rerun is safe.
     */
    private static function split_house_number_additions(): void {
        global $wpdb;
        $table = "{$wpdb->prefix}avm_addresses";
        $rows = $wpdb->get_results("SELECT id, house_number FROM {$table} WHERE house_number <> '' AND house_number_addition = ''") ?: [];
        foreach ($rows as $row) {
            [$number, $addition] = AVPVH_Address_Normalizer::split_house_number((string) $row->house_number);
            if ($number !== $row->house_number || $addition !== '') {
                $wpdb->update($table, ['house_number' => $number, 'house_number_addition' => $addition], ['id' => (int) $row->id]);
            }
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

        self::seed_relationship_labels();

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

        $ids = self::seed_activity_types();

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
        // require, not require_once: the file returns the list, and a second
        // require_once in the same request would return true instead.
        $blacklist = require AVPVH_PLUGIN_DIR . 'includes/disposable-domains.php'; // NOSONAR php:S2003
        if (in_array(strtolower($domain), $blacklist, true)) {
            return;
        }

        global $wpdb;
        // REMOTE_ADDR, not X-Forwarded-For: the latter comes from the client (see AVPVH_OAuth::get_client_ip()).
        $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
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

    public static function query_login_attempts(array $args = []): array {
        global $wpdb;
        $defaults = [
            'search' => '', 'method' => [], 'result' => [],
            'date_from' => '', 'date_to' => '',
            'orderby' => 'attempted_at', 'order' => 'desc',
            'per_page' => 50, 'page' => 1,
        ];
        $args = array_merge($defaults, $args);
        $where = [];
        $values = [];

        if ($args['search'] !== '') {
            $like = '%' . $wpdb->esc_like($args['search']) . '%';
            $where[] = '(email LIKE %s OR ip LIKE %s OR method LIKE %s OR result LIKE %s)';
            array_push($values, $like, $like, $like, $like);
        }
        $methods = array_values(array_filter((array) $args['method'], 'strlen'));
        if ($methods) {
            $where[] = 'method IN (' . implode(',', array_fill(0, count($methods), '%s')) . ')';
            array_push($values, ...$methods);
        }
        $results = array_values(array_filter((array) $args['result'], 'strlen'));
        if ($results) {
            $where[] = 'result IN (' . implode(',', array_fill(0, count($results), '%s')) . ')';
            array_push($values, ...$results);
        }
        if ($args['date_from'] !== '') {
            $where[] = 'attempted_at >= %s';
            $values[] = $args['date_from'] . ' 00:00:00';
        }
        if ($args['date_to'] !== '') {
            $where[] = 'attempted_at <= %s';
            $values[] = $args['date_to'] . ' 23:59:59';
        }

        $where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $allowed_orderby = ['id', 'attempted_at', 'email', 'method', 'result', 'ip'];
        $orderby = in_array($args['orderby'], $allowed_orderby, true) ? $args['orderby'] : 'attempted_at';
        $order = strtolower($args['order']) === 'asc' ? 'ASC' : 'DESC';
        $per_page = max(1, min(500, (int) $args['per_page']));
        $offset = (max(1, (int) $args['page']) - 1) * $per_page;

        // $where_sql holds only %s placeholders and $orderby is whitelisted
        // above; every value goes through prepare().
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $count_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}avm_login_attempts{$where_sql}";
        $total = (int) ($values
            ? $wpdb->get_var($wpdb->prepare($count_sql, $values))
            : $wpdb->get_var($count_sql));

        $query_values = array_merge($values, [$per_page, $offset]);
        $rows_sql = "SELECT * FROM {$wpdb->prefix}avm_login_attempts{$where_sql}
            ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $items = $wpdb->get_results($wpdb->prepare($rows_sql, $query_values)) ?: [];
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return ['items' => $items, 'total' => $total];
    }

    public static function get_login_attempt_filter_values(): array {
        global $wpdb;
        return [
            'methods' => $wpdb->get_col(
                "SELECT DISTINCT method FROM {$wpdb->prefix}avm_login_attempts ORDER BY method"
            ) ?: [],
            'results' => $wpdb->get_col(
                "SELECT DISTINCT result FROM {$wpdb->prefix}avm_login_attempts ORDER BY result"
            ) ?: [],
        ];
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
    // Member lookups — all JOIN with the directory cache for email
    // -------------------------------------------------------------------

    private static function member_select(): string {
        global $wpdb;
        $identity = self::identity_table();
        return "SELECT u.user_id, u.email, u.display_name,
                       m.id, m.lldap_user_id, m.wp_user_id,
                       m.first_name, m.suffix, m.last_name, m.passport_name, m.initials, m.birth_date, m.birth_year, m.death_date, m.is_student,
                       m.phone, m.mobile, m.emergency_contact, m.emergency_member_id, m.emergency_relation, m.emergency_phone, m.emergency_address, m.diet,
                       m.status, m.joined_year, m.left_year, m.archive_access,
                       m.directory_consent, m.directory_consent_at,
                       m.share_email, m.share_phone, m.share_address, m.share_activity_history,
                       m.created_at, m.updated_at
                FROM {$identity} u
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
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- member_select() is a fixed, hardcoded SELECT/JOIN with no interpolation; the %s value is properly prepared
        return $wpdb->get_row($wpdb->prepare(
            self::member_select() . " WHERE u.lowercase_email = LOWER(%s) LIMIT 1",
            $email
        )) ?: null;
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    /**
     * Lowercased e-mail => member id, for the $emails that belong to a
     * member the way a login finds them: a linked e-mail (Inlogadres)
     * first, else the directory account's own e-mail.
     */
    public static function get_member_ids_by_emails(array $emails): array {
        global $wpdb;
        $emails = array_values(array_unique(array_filter(array_map([self::class, 'normalize_identity_email'], $emails))));
        if (!$emails) {
            return [];
        }
        $in = implode(',', array_fill(0, count($emails), '%s'));
        $identity = self::identity_table();
        // Two queries, not a UNION: the directory table has another collation.
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $in is only %s placeholders and $identity a fixed table name; the values go through prepare()
        $rows = array_merge(
            $wpdb->get_results($wpdb->prepare(
                "SELECT LOWER(email) AS email, member_id FROM {$wpdb->prefix}avm_member_identities WHERE LOWER(email) IN ($in)",
                $emails
            )) ?: [],
            $wpdb->get_results($wpdb->prepare(
                "SELECT LOWER(u.lowercase_email) AS email, m.id AS member_id
                 FROM {$identity} u JOIN {$wpdb->prefix}avm_members m ON m.lldap_user_id = u.user_id
                 WHERE u.lowercase_email IN ($in)",
                $emails
            )) ?: []
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $map = [];
        foreach ($rows as $row) {
            $map[$row->email] ??= (int) $row->member_id;
        }
        return $map;
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

        // email is unique across the whole table, whatever the provider.
        // If another member already holds it, leave everything as it is —
        // inserting would fail, and clearing this member's primary flag
        // first (as this used to) left them with no primary at all.
        $owner = $wpdb->get_var($wpdb->prepare(
            "SELECT member_id FROM {$wpdb->prefix}avm_member_identities WHERE email = %s LIMIT 1",
            $email
        ));
        if ($owner !== null && (int) $owner !== $member_id) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operational log of a failed directory action, for the server log; not debug output
            error_log("AVPVH_DB: primary e-mail of member {$member_id} is already an identity of member {$owner}; left unchanged");
            return;
        }

        // Prefer the identity that already has this address (e.g. the same
        // address verified via Google), then any e-mail identity whose
        // address should follow the OpenLDAP one; only insert when neither exists.
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}avm_member_identities
             WHERE member_id = %d AND (email = %s OR provider = 'email')
             ORDER BY (email = %s) DESC LIMIT 1",
            $member_id, $email, $email
        ));

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}avm_member_identities SET is_primary = 0 WHERE member_id = %d",
            $member_id
        ));

        if ($existing) {
            // Authelia/OpenLDAP proxy auth is a real, trusted login — same as
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

    /**
     * Whether identity $identity_id may be removed from $identities (one
     * member's): an unverified one always, nobody can log in with it; a
     * verified one only while another verified one remains, so nobody
     * deletes a member down to zero working logins.
     */
    public static function can_delete_identity(array $identities, int $identity_id): bool {
        $verified = array_filter($identities, fn($i) => !empty($i->verified_at));
        $target = current(array_filter($identities, fn($i) => (int) $i->id === $identity_id));
        return $target !== false && (empty($target->verified_at) || count($verified) > 1);
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

    public static function get_members(array $args = []): array {
        global $wpdb;
        $where  = '1=1';
        $params = [];
        $join   = '';

        if (!empty($args['search'])) {
            $s      = '%' . $wpdb->esc_like($args['search']) . '%';
            // Also finds a member by a recorded name variant (maiden name,
            // old spelling, ...) — see the Naamvarianten section on Ledendetail.
            $where .= " AND (m.last_name LIKE %s OR m.first_name LIKE %s OR u.email LIKE %s
                OR EXISTS (SELECT 1 FROM {$wpdb->prefix}avm_member_name_aliases na
                           WHERE na.member_id = m.id
                             AND CONCAT_WS(' ', na.first_name, NULLIF(na.suffix, ''), na.last_name) LIKE %s))";
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
     * Whether an existing WordPress user matched by e-mail may safely be
     * auto-linked to $member_id during first login. Never auto-links a
     * privileged WP account (admin/editor/etc.) or a WP user already linked
     * to a different member.
     */
    public static function can_safely_link_wp_user(\WP_User $user, int $member_id): bool {
        if (user_can($user, 'manage_options') || user_can($user, 'edit_users') || array_diff((array) $user->roles, ['subscriber', 'contributor'])) {
            return false;
        }
        global $wpdb;
        $other = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}avm_members WHERE wp_user_id = %d AND id != %d LIMIT 1",
            (int) $user->ID, $member_id
        ));
        return $other === 0;
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
            $base_login = sanitize_user((string) ($member->lldap_user_id ?: strstr($email, '@', true)), true);
            if ($base_login === '') {
                $base_login = 'lid-' . (int) $member->id;
            }
            $login = $base_login;
            if (username_exists($login)) {
                $login = $base_login . '-' . (int) $member->id;
            }
            $uid = wp_create_user(
                $login,
                wp_generate_password(64),
                $email
            );
            if (is_wp_error($uid)) {
                return null;
            }
            wp_update_user([
                'ID'           => $uid,
                'display_name' => avpvh_format_name($member),
                'role'         => 'subscriber',
            ]);
            self::set_wp_user_id((int) $member->id, $uid);
            $user = get_user_by('id', $uid);
        } else {
            if (!self::can_safely_link_wp_user($user, (int) $member->id)) {
                error_log("AVPVH_DB: refused unsafe auto-link of WP user {$user->ID} to member {$member->id}."); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operational security refusal log
                return null;
            }
            self::set_wp_user_id((int) $member->id, $user->ID);
        }

        return $user ?: null;
    }

    /**
     * True when $member is under $threshold years old. When only birth_year
     * is stored (birth_date IS NULL), errs on the side of protecting minors
     * (e.g. born in current_year - 16 may still be 15 until their birthday).
     * Set $require_certain for granting rights: uncertainty never grants access.
     */
    public static function is_minor_member(object $member, int $threshold = 16, bool $require_certain = false): bool {
        if (!empty($member->birth_date)) {
            return self::age((string) $member->birth_date) < $threshold;
        }
        if (!empty($member->birth_year)) {
            $age_in_year = (int) current_time('Y') - (int) $member->birth_year;
            return $require_certain ? $age_in_year < $threshold : $age_in_year <= $threshold;
        }
        return false;
    }

    // -------------------------------------------------------------------
    // Address / activity / fee helpers — unchanged from original
    // -------------------------------------------------------------------

    public static function get_members_with_address(int $viewer_member_id = 0, bool $viewer_sees_minors = false): array {
        global $wpdb;
        $identity = self::identity_table();
        $today = current_time('Y-m-d');
        // $identity/$wpdb->prefix below are fixed, hardcoded identifiers
        // (identity_table() / WP's own table prefix), never user input; the two %s values
        // are properly prepared — phpcs:disable, block form since the flagged
        // interpolation is deep inside a multi-line string literal, too far from
        // any single line an inline phpcs:ignore comment could attach to.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT u.email,
                    m.id, m.lldap_user_id, m.first_name, m.suffix, m.last_name, m.phone, m.mobile, m.status,
                    m.birth_date, m.birth_year, m.share_email, m.share_phone, m.share_address,
                    a.street, a.house_number, a.house_number_addition, a.postal_code, a.city, a.country
             FROM {$identity} u
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
            $is_minor = self::is_minor_member($lid, 16);
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
     * Records $address as the member's address from today on. If it is the
     * same address (AVPVH_Address_Matcher::same()) as one already
     * current, that row is kept (spelling differences written into it) — the
     * profile form re-sends the address on every save, which used to add a
     * duplicate row each time. Otherwise the
     * current rows end yesterday and a new row starts today; a row that
     * itself only started today is a same-day correction and is overwritten
     * instead, so no row ever ends before it starts. Returns the row id.
     */
    public static function add_member_address(int $member_id, array $address, bool $member_entered = false): int {
        global $wpdb;
        $table   = "{$wpdb->prefix}avm_addresses";
        $today   = current_time('Y-m-d');
        $address = AVPVH_Address_Normalizer::clean($address);
        $fields  = array_intersect_key($address, array_flip(['street', 'house_number', 'house_number_addition', 'postal_code', 'city', 'country']));

        $open = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE member_id = %d AND (valid_from IS NULL OR valid_from <= %s)
               AND (valid_until IS NULL OR valid_until >= %s)
             ORDER BY valid_from DESC, id DESC",
            $member_id, $today, $today
        )) ?: [];
        foreach ($open as $row) {
            if (($address['street'] ?? '') !== '' && AVPVH_Address_Matcher::same($address, $row)) {
                // Same address; a spelling fix (city, capitals) is applied in place.
                if (array_diff_assoc($fields, array_intersect_key((array) $row, $fields))) {
                    $wpdb->update($table, $fields, ['id' => (int) $row->id]);
                }
                return (int) $row->id;
            }
        }

        $yesterday = wp_date('Y-m-d', strtotime($today . ' -1 day'));
        $corrected = 0;
        foreach ($open as $row) {
            if ($row->valid_from === $today && !$corrected) {
                $wpdb->update($table, $fields + ['valid_until' => null, 'member_entered' => (int) $member_entered], ['id' => (int) $row->id]);
                $corrected = (int) $row->id;
            } elseif ($row->valid_from === $today) {
                $wpdb->delete($table, ['id' => (int) $row->id], ['%d']);
            } else {
                $wpdb->update($table, ['valid_until' => $yesterday], ['id' => (int) $row->id], ['%s'], ['%d']);
            }
        }
        if ($corrected) {
            return $corrected;
        }

        $wpdb->insert($table, ['member_id' => $member_id] + $fields + ['valid_from' => $today, 'member_entered' => (int) $member_entered]);
        return (int) $wpdb->insert_id;
    }

    /**
     * The profile form only ever inserts a new address row (each save adds
     * one), so there was never a UI to fix a wrong/duplicate valid_from or
     * valid_until, or to remove a row entirely — needed to clean up e.g. a
     * duplicate created by a failed/retried submission. False when nothing
     * was saved, e.g. reopening a row while another one is still open.
     */
    public static function update_address(int $id, int $member_id, ?string $valid_from, ?string $valid_until): bool {
        global $wpdb;
        // Only one address per member may run on to the present: refuse to
        // reopen a closed row next to an open one. A row that is already
        // open (old data with two) can still get its dates corrected.
        $table = "{$wpdb->prefix}avm_addresses";
        $was_open = $wpdb->get_var($wpdb->prepare("SELECT valid_until IS NULL FROM {$table} WHERE id = %d AND member_id = %d", $id, $member_id));
        if ($valid_until === null && $was_open !== '1' && (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE member_id = %d AND id <> %d AND valid_until IS NULL",
            $member_id, $id
        ))) {
            return false;
        }
        return false !== $wpdb->update(
            "{$wpdb->prefix}avm_addresses",
            ['valid_from' => $valid_from, 'valid_until' => $valid_until],
            ['id' => $id, 'member_id' => $member_id],
            ['%s', '%s'], ['%d', '%d']
        );
    }

    /** The board confirms an address a member entered themselves. */
    public static function confirm_address(int $id, int $member_id): bool {
        global $wpdb;
        return (bool) $wpdb->update(
            "{$wpdb->prefix}avm_addresses",
            ['member_entered' => 0],
            ['id' => $id, 'member_id' => $member_id],
            ['%d'], ['%d', '%d']
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

    /**
     * Why an activity can't be deleted: one human-readable reason per kind of
     * data that still refers to it; empty means it is unused. Participation
     * is checked here; other plugins add their own references through the
     * avpvh_activity_delete_blockers filter (avpvh-bookkeeping: rates, fee
     * items, transactions, payment requests), so this plugin doesn't need to
     * know their tables.
     *
     * @return string[]
     */
    public static function get_activity_delete_blockers(int $activity_id): array {
        global $wpdb;
        $blockers = [];
        $participants = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}avm_activity_participation WHERE activity_id = %d",
            $activity_id
        ));
        if ($participants) {
            $blockers[] = sprintf(
                /* translators: %d: number of participants */
                _n('%d deelnemer', '%d deelnemers', $participants, 'avpvh-members'),
                $participants
            );
        }
        // avpvh-bookkeeping active but too old to answer the filter: its
        // rates/fee items/payments can't be checked, so don't allow it.
        if (defined('AVBK_PLUGIN_DIR') && !has_filter('avpvh_activity_delete_blockers')) {
            $blockers[] = __('de boekhouding kan nog niet worden gecontroleerd (avpvh-bookkeeping bijwerken)', 'avpvh-members');
        }
        return array_values(array_filter(array_map(
            'strval',
            (array) apply_filters('avpvh_activity_delete_blockers', $blockers, $activity_id)
        )));
    }

    /**
     * Deletes an activity only if nothing refers to it any more (see
     * get_activity_delete_blockers(), checked again here so a stale page
     * can't delete one that gained a participant in the meantime).
     *
     * @return string[] the blockers; empty means the activity was deleted
     */
    public static function delete_activity_if_unused(int $activity_id): array {
        global $wpdb;
        $activity = self::get_activity($activity_id);
        if (!$activity) {
            return [__('Activiteit niet gevonden.', 'avpvh-members')];
        }
        $blockers = self::get_activity_delete_blockers($activity_id);
        if ($blockers) {
            return $blockers;
        }
        $wpdb->delete("{$wpdb->prefix}avm_activities", ['id' => $activity_id], ['%d']);
        do_action('avpvh_activity_deleted', $activity_id, $activity);
        return [];
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

    /** Removes the relationship only when it belongs to $member_id (either side). */
    public static function remove_relationship(int $relationship_id, int $member_id): bool {
        global $wpdb;
        return (bool) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}avm_relationships WHERE id = %d AND (member_id = %d OR related_member_id = %d)",
            $relationship_id, $member_id, $member_id
        ));
    }

    /**
     * Directional parent check: true when $parent_id is recorded as parent
     * ('ouder van') of $child_id (or $child_id is 'kind van' $parent_id) in
     * a currently valid relationship.
     */
    public static function is_parent_of(int $parent_id, int $child_id): bool {
        global $wpdb;
        $today = current_time('Y-m-d');
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT r.id FROM {$wpdb->prefix}avm_relationships r
             JOIN {$wpdb->prefix}avm_relationship_labels l ON l.id = r.label_id
             WHERE ((r.member_id = %d AND r.related_member_id = %d AND l.code = 'ouder')
                 OR (r.member_id = %d AND r.related_member_id = %d AND l.code = 'kind'))
               AND (r.valid_from IS NULL OR r.valid_from <= %s)
               AND (r.valid_until IS NULL OR r.valid_until >= %s)
             LIMIT 1",
            $child_id, $parent_id, $parent_id, $child_id, $today, $today
        ));
        return (bool) $result;
    }

    // True if the two members have a (currently valid) 'ouder_kind' edge
    // in either direction — used for household display/minor visibility.
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
        return self::addresses_grant_household(self::current_address($member_id_1), self::current_address($member_id_2));
    }

    /**
     * Rights from a shared address: the same current address, and neither
     * row entered by a member themselves (member_entered) and not yet
     * confirmed by the board — otherwise anyone could copy someone else's
     * address into their own profile. has_same_address() stays the literal
     * check, for display.
     */
    public static function addresses_grant_household(?object $addr_1, ?object $addr_2): bool {
        if (!$addr_1 || !$addr_2 || !$addr_1->street || !$addr_2->street) {
            return false;
        }
        if (!empty($addr_1->member_entered) || !empty($addr_2->member_entered)) {
            return false;
        }
        return AVPVH_Address_Matcher::same($addr_1, $addr_2);
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

        return AVPVH_Address_Matcher::same($addr_1, $addr_2);
    }

    public static function age(string $birth_date): int {
        $birth = new \DateTime($birth_date);
        $today = new \DateTime(current_time('Y-m-d'));
        return (int) $today->diff($birth)->y;
    }

    /**
     * Members $member_id may manage (view/edit) via the self-service profile
     * form: always themselves, plus — when $member_id is an active member —
     * cohabiting active members (confirmed shared address) and their own
     * minor children (< 18) living elsewhere via an ouder/kind link.
     * Inactive ex-members and visitors can only manage their own record.
     */
    public static function get_manageable_members(int $member_id): array {
        global $wpdb;
        $actor = self::get_member($member_id);
        if (!$actor) {
            return [];
        }
        if ($actor->status !== 'active') {
            return [$actor];
        }
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- fully static query, no dynamic values at all
        $active = $wpdb->get_results(
            self::member_select() . " WHERE m.status = 'active' ORDER BY m.last_name, m.first_name"
        ) ?: [];
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $actor_addr = self::current_address($member_id);
        $manageable = [];
        foreach ($active as $m) {
            $target_id = (int) $m->id;
            if ($target_id === $member_id) {
                $manageable[] = $m;
                continue;
            }
            $shared_home = self::addresses_grant_household($actor_addr, self::current_address($target_id));
            $parent_of_minor = !$shared_home && self::is_parent_of($member_id, $target_id) && self::is_minor_member($m, 18, true);
            if ($shared_home || $parent_of_minor) {
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

    /**
     * The board's permission for an ex-member to see the posts and photos
     * of their membership years (AVPVH_Archive_Access), and those years
     * (joined_year, left_year; 0 leaves one unchanged). Every change is
     * logged.
     */
    public static function set_archive_access(int $member_id, bool $on, int $from = 0, int $to = 0): bool {
        global $wpdb;
        $member = self::get_member($member_id);
        if (!$member) {
            return false;
        }
        $changes = [];
        $wanted  = ['archive_access' => $on ? 1 : 0];
        if ($from > 0) {
            $wanted['joined_year'] = $from;
        }
        if ($to > 0) {
            $wanted['left_year'] = $to;
        }
        foreach ($wanted as $field => $value) {
            if ((int) ($member->$field ?? 0) !== $value) {
                $changes[$field] = $value;
            }
        }
        if (!$changes) {
            return true;
        }
        $done = false !== $wpdb->update(
            "{$wpdb->prefix}avm_members",
            $changes,
            ['id' => $member_id],
            array_fill(0, count($changes), '%d'),
            ['%d']
        );
        if ($done) {
            foreach ($changes as $field => $value) {
                self::log_member_change($member_id, $field, $member->$field === null ? null : (string) $member->$field, (string) $value);
            }
        }
        return $done;
    }

    /**
     * The first and last year with a paid or waived fee or an activity,
     * as a suggestion for joined_year/left_year; null without any.
     *
     * @return array{from:int,to:int}|null
     */
    public static function suggest_membership_years(int $member_id): ?array {
        global $wpdb;
        $p = $wpdb->prefix;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin page, one member
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT MIN(y) AS from_year, MAX(y) AS to_year FROM (
                SELECT year AS y FROM {$p}avm_fees WHERE member_id = %d AND status IN ('paid','waived')
                UNION ALL
                SELECT a.year FROM {$p}avm_activity_participation ap
                    JOIN {$p}avm_activities a ON a.id = ap.activity_id
                    WHERE ap.member_id = %d
            ) years",
            $member_id,
            $member_id
        ));
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if (!$row || !(int) $row->from_year) {
            return null;
        }
        return ['from' => (int) $row->from_year, 'to' => (int) $row->to_year];
    }

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

    /** Case-insensitive first+last name match — used by the "Nieuwe persoon" admin form to warn before creating what might be a duplicate. Doesn't consider suffix, since a treasurer typing e.g. "Jan Voorbeeld" should still be warned about "Jan van Voorbeeld". */
    public static function find_members_by_name(string $first_name, string $last_name): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, first_name, suffix, last_name, status FROM {$wpdb->prefix}avm_members
             WHERE LOWER(first_name) = LOWER(%s) AND LOWER(last_name) = LOWER(%s)",
            $first_name, $last_name
        )) ?: [];
    }

    /** Shared match key for person names — see AVPVH_Name_Matcher (plan.md §7). */
    public static function normalize_person_name(string $first_name, string $suffix, string $last_name): string {
        return AVPVH_Name_Matcher::normalize_person_name($first_name, $suffix, $last_name);
    }

    /**
     * Members whose official name or one of whose name aliases has the
     * same normalized key as the given name. Each row carries matched_via:
     * '' for the official name, otherwise the alias it matched on. Unlike
     * find_members_by_name() (kept as-is for avpvh-bookkeeping), this
     * tolerates tussenvoegsel stored in any of the legacy places.
     */
    public static function find_members_by_name_or_alias(string $first_name, string $suffix, string $last_name): array {
        $key = self::normalize_person_name($first_name, $suffix, $last_name);
        $matches = self::get_name_key_index()[$key] ?? [];
        return array_values($matches);
    }

    /**
     * normalized key => [member_id => member row + matched_via] over every
     * member's official name and every alias. The key can't be computed in
     * SQL, so this reads all members (a few hundred rows) once per call.
     * With $loose, keyed by AVPVH_Name_Matcher::loose_key() instead (merge
     * suggestions only); names whose loose key is empty are left out.
     */
    public static function get_name_key_index(bool $loose = false): array {
        global $wpdb;
        $index = [];
        $members = $wpdb->get_results(
            "SELECT id, first_name, suffix, last_name, status, birth_date, birth_year
             FROM {$wpdb->prefix}avm_members"
        ) ?: [];
        $by_id = [];
        foreach ($members as $member) {
            $by_id[(int) $member->id] = $member;
            $key = $loose
                ? AVPVH_Name_Matcher::loose_key($member->first_name, $member->last_name)
                : self::normalize_person_name($member->first_name, $member->suffix, $member->last_name);
            if ($key === '') {
                continue;
            }
            $index[$key][(int) $member->id] = (object) array_merge((array) $member, ['matched_via' => '']);
        }
        $aliases = $wpdb->get_results(
            "SELECT member_id, first_name, suffix, last_name, normalized_key
             FROM {$wpdb->prefix}avm_member_name_aliases"
        ) ?: [];
        foreach ($aliases as $alias) {
            $member = $by_id[(int) $alias->member_id] ?? null;
            $key = $loose ? AVPVH_Name_Matcher::loose_key($alias->first_name, $alias->last_name) : $alias->normalized_key;
            if (!$member || $key === '' || isset($index[$key][(int) $member->id])) {
                continue;
            }
            $index[$key][(int) $member->id] = (object) array_merge(
                (array) $member,
                ['matched_via' => avpvh_format_name($alias)]
            );
        }
        return $index;
    }

    public const NAME_ALIAS_TYPES = ['maiden', 'married', 'nickname', 'spelling', 'abbreviation', 'historical'];

    /** A member's recorded name variants, each with conflicts = ids of OTHER members sharing its key. */
    public static function get_name_aliases(int $member_id): array {
        global $wpdb;
        $aliases = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avm_member_name_aliases
             WHERE member_id = %d ORDER BY last_name, first_name, id",
            $member_id
        )) ?: [];
        if (!$aliases) {
            return [];
        }
        $index = self::get_name_key_index();
        foreach ($aliases as $alias) {
            $alias->conflicts = array_values(array_diff(
                array_keys($index[$alias->normalized_key] ?? []),
                [$member_id]
            ));
        }
        return $aliases;
    }

    /**
     * Adds a name variant to a member. Returns '' on success, otherwise an
     * error code: 'onvolledig' (first or last name missing), 'eigen-naam'
     * (matches the member's own official name already), 'dubbel' (the
     * member already has this variant) or 'fout' (database error). The key
     * is computed by AVPVH_Name_Matcher, the same as for merges and imports.
     */
    public static function save_name_alias(int $member_id, array $data): string {
        global $wpdb;
        $member = self::get_member($member_id);
        $first  = trim((string) ($data['first_name'] ?? ''));
        $suffix = trim((string) ($data['suffix'] ?? ''));
        $last   = trim((string) ($data['last_name'] ?? ''));
        if (!$member || $first === '' || $last === '') {
            return 'onvolledig';
        }
        $key = self::normalize_person_name($first, $suffix, $last);
        if ($key === self::normalize_person_name($member->first_name, $member->suffix, $member->last_name)) {
            return 'eigen-naam';
        }
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}avm_member_name_aliases WHERE member_id = %d AND normalized_key = %s",
            $member_id, $key
        ));
        if ($exists) {
            return 'dubbel';
        }
        $date = static fn(string $field): ?string =>
            preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data[$field] ?? '')) ? $data[$field] : null;
        $type = in_array($data['alias_type'] ?? '', self::NAME_ALIAS_TYPES, true) ? $data['alias_type'] : 'historical';

        $inserted = $wpdb->insert("{$wpdb->prefix}avm_member_name_aliases", [
            'member_id'           => $member_id,
            'first_name'          => mb_substr($first, 0, 100),
            'suffix'              => mb_substr($suffix, 0, 50),
            'last_name'           => mb_substr($last, 0, 100),
            'alias_type'          => $type,
            'normalized_key'      => $key,
            'normalized_key_hash' => hash('sha256', $key),
            'valid_from'          => $date('valid_from'),
            'valid_until'         => $date('valid_until'),
            'source'              => mb_substr(trim((string) ($data['source'] ?? '')), 0, 100),
            'note'                => mb_substr(trim((string) ($data['note'] ?? '')), 0, 255),
        ]);
        return $inserted ? '' : 'fout';
    }

    public static function delete_name_alias(int $alias_id, int $member_id): bool {
        global $wpdb;
        return (bool) $wpdb->delete(
            "{$wpdb->prefix}avm_member_name_aliases",
            ['id' => $alias_id, 'member_id' => $member_id],
            ['%d', '%d']
        );
    }

    /** New member with an already-created OpenLDAP account (see AVPVH_Admin::handle_add_member()) — mirrors the shape of the avpvh-ops-scripts one-off "create minor member" scripts, now available from the admin UI instead of a hand-run script. */
    public static function create_member(string $lldap_user_id, string $first_name, string $suffix, string $last_name, ?string $birth_date, string $status, ?int $birth_year = null): int {
        global $wpdb;
        $wpdb->insert(
            "{$wpdb->prefix}avm_members",
            [
                'lldap_user_id' => $lldap_user_id,
                'first_name'    => $first_name,
                'suffix'        => $suffix,
                'last_name'     => $last_name,
                'birth_date'    => $birth_date,
                'birth_year'    => $birth_year,
                'status'        => $status,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%d', '%s']
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
        if ($flag_ids && self::flag_ids_include_inactivating($flag_ids)) {
            $member = self::get_member($member_id);
            if ($member && $member->status !== 'inactive') {
                self::update_member_with_audit($member_id, ['status' => 'inactive'], ['%s']);
            }
        }
    }

    /** True if any of the given flag IDs has sets_inactive = 1. */
    public static function flag_ids_include_inactivating(array $flag_ids): bool {
        global $wpdb;
        $flag_ids = array_values(array_filter(array_map('intval', $flag_ids)));
        if (!$flag_ids) {
            return false;
        }
        $placeholders = implode(',', array_fill(0, count($flag_ids), '%d'));
        return (bool) $wpdb->get_var($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is a %d run built to match count($flag_ids); PHPCS can't verify the count statically but prepare() gets exactly one %d per array element
            "SELECT 1 FROM {$wpdb->prefix}avm_member_flags WHERE id IN ($placeholders) AND sets_inactive = 1 LIMIT 1",
            $flag_ids
        ));
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

    /**
     * Records a member's date of death (null clears it). With a date the
     * member gets the Overleden kenmerk, and so becomes ex-lid, and every
     * address that runs on past that day ends on it. Returns ['closed' =>
     * addresses ended, 'later' => addresses that only start after that day,
     * left for someone to look at].
     */
    public static function set_death_date(int $member_id, ?string $date): array {
        global $wpdb;
        $member = self::get_member($member_id);
        if (!$member) {
            return ['closed' => 0, 'later' => 0];
        }
        if (($member->death_date ?? null) !== $date) {
            $wpdb->update("{$wpdb->prefix}avm_members", ['death_date' => $date], ['id' => $member_id], ['%s'], ['%d']);
            self::log_member_change($member_id, 'death_date', $member->death_date ?? null, $date);
        }
        if ($date === null) {
            return ['closed' => 0, 'later' => 0];
        }

        $flag_ids = array_map(fn($flag) => (int) $flag->id, self::get_flags_for_member($member_id));
        $overleden = (int) $wpdb->get_var("SELECT id FROM {$wpdb->prefix}avm_member_flags WHERE slug = 'overleden'");
        if ($overleden && !in_array($overleden, $flag_ids, true)) {
            self::set_member_flags($member_id, array_merge($flag_ids, [$overleden]));
        }

        $table = "{$wpdb->prefix}avm_addresses";
        $closed = (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET valid_until = %s
             WHERE member_id = %d AND (valid_until IS NULL OR valid_until > %s) AND (valid_from IS NULL OR valid_from <= %s)",
            $date, $member_id, $date, $date
        ));
        $later = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE member_id = %d AND valid_from > %s",
            $member_id, $date
        ));
        if ($closed) {
            /* translators: 1: number of addresses, 2: date of death */
            self::log_member_change($member_id, 'adres', null, sprintf(__('%1$d adres(sen) afgesloten op overlijdensdatum %2$s', 'avpvh-members'), $closed, $date));
        }
        return ['closed' => $closed, 'later' => $later];
    }

    /** Whether the member currently has a kenmerk that forces inactive status (e.g. geroyeerd, overleden). */
    public static function member_has_inactivating_flag(int $member_id): bool {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}avm_member_flag_assignments fa
             JOIN {$wpdb->prefix}avm_member_flags f ON f.id = fa.flag_id
             WHERE fa.member_id = %d AND f.sets_inactive = 1 LIMIT 1",
            $member_id
        ));
    }

    public static function flag_exists(string $slug): bool {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}avm_member_flags WHERE slug = %s", $slug
        ));
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
