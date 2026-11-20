<?php
defined('ABSPATH') || exit;

/**
 * The schema changes AVPVH_DB::maybe_upgrade() applies to existing sites,
 * one entry per database version. AVPVH_DB::upgrade_steps() lists the order;
 * steps that only rerun install() or a data pass live there.
 */
class AVPVH_DB_Migrations {

    /** Runs the fixed statements of $version (see statements()). */
    public static function run(string $version): void {
        global $wpdb;
        foreach (self::statements()[$version] as $sql) {
            $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- fixed schema statements with the table prefix only
        }
    }

    /** Adds the column of $version (see missing_columns()) unless it is there already. */
    public static function add_missing_column(string $version): void {
        global $wpdb;
        [$table, $column, $statements] = self::missing_columns()[$version];
        if (!$wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}{$table} LIKE '{$column}'")) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed names from missing_columns()
            foreach ($statements as $sql) {
                $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- fixed schema statements with the table prefix only
            }
        }
    }

    /** @return array<string, list<string>> version => statements, run as they are */
    private static function statements(): array {
        global $wpdb;
        $p = $wpdb->prefix;
        return [
            '1.3' => ["ALTER TABLE {$p}avm_login_attempts
                MODIFY method ENUM('proxy','google','microsoft','password_reset') NOT NULL,
                MODIFY result ENUM('success','no_member','hibp_warned') NOT NULL"],
            '1.5' => ["ALTER TABLE {$p}avm_members
                ADD COLUMN suffix VARCHAR(50) NOT NULL DEFAULT '' AFTER first_name"],
            '1.7' => ["ALTER TABLE {$p}avm_members
                ADD COLUMN directory_consent ENUM('pending','granted','declined') NOT NULL DEFAULT 'pending' AFTER emergency_contact,
                ADD COLUMN directory_consent_at TIMESTAMP NULL AFTER directory_consent,
                ADD COLUMN share_email TINYINT(1) UNSIGNED NOT NULL DEFAULT 1 AFTER directory_consent_at,
                ADD COLUMN share_phone TINYINT(1) UNSIGNED NOT NULL DEFAULT 1 AFTER share_email,
                ADD COLUMN share_address TINYINT(1) UNSIGNED NOT NULL DEFAULT 1 AFTER share_phone,
                ADD COLUMN share_camp_history TINYINT(1) UNSIGNED NOT NULL DEFAULT 1 AFTER share_address"],
            // The published privacy statement already establishes that the member
            // directory is shared with all members — flip from opt-in to opt-out.
            // One-time backfill: undecided members become shared; real declines stand.
            '1.8' => [
                "UPDATE {$p}avm_members
                SET directory_consent = 'granted' WHERE directory_consent = 'pending'",
                "ALTER TABLE {$p}avm_members
                MODIFY directory_consent ENUM('pending','granted','declined') NOT NULL DEFAULT 'granted'",
            ],
            '1.9' => ["ALTER TABLE {$p}avm_members
                ADD COLUMN passport_name VARCHAR(200) NOT NULL DEFAULT '' AFTER baptism_name,
                ADD COLUMN family_relation_member_id INT UNSIGNED NULL AFTER emergency_contact,
                ADD COLUMN diet VARCHAR(255) NOT NULL DEFAULT '' AFTER family_relation_member_id,
                ADD KEY family_relation_member_id (family_relation_member_id)"],
            // Members may now have up to 3 identities in total regardless of
            // provider (e.g. two Google-verified addresses), rather than
            // exactly one per provider — drop the per-provider slot limit,
            // and make plain e-mail address uniqueness global instead of
            // scoped to provider (an address can never belong to more than
            // one member, whichever method verified it). The original schema
            // also had a separate plain KEY named "email" — must go too, or
            // adding the new UNIQUE KEY of the same name collides with it.
            '2.0' => ["ALTER TABLE {$p}avm_member_identities
                DROP INDEX member_provider,
                DROP INDEX provider_email,
                DROP INDEX email,
                ADD UNIQUE KEY email (email(191))"],
            // The Google-Sheets-sync registration system (avm_registrations /
            // avm_registration_attendance / avm_sync_conflicts) was never
            // launched (sync was never configured, 0 rows) and is superseded
            // by avm_camp_participation(_days), which is properly linked to
            // real members. Drop the old tables so the data model only
            // exists once.
            '2.1' => [
                "DROP TABLE IF EXISTS {$p}avm_registration_attendance",
                "DROP TABLE IF EXISTS {$p}avm_sync_conflicts",
                "DROP TABLE IF EXISTS {$p}avm_registrations",
            ],
            // Needed to render/edit day-by-day attendance for a camp (the
            // "Kampdeelname" screen) without hardcoding the date range.
            '2.2' => ["ALTER TABLE {$p}avm_camps
                ADD COLUMN start_date DATE NULL AFTER location,
                ADD COLUMN end_date DATE NULL AFTER start_date"],
            // VARCHAR(50) was too narrow for real free-text diet notes
            // (some run well past 50 characters) — widen to match the
            // free-text notes column next to it.
            '2.4' => ["ALTER TABLE {$p}avm_camp_participation MODIFY diet TEXT NULL"],
            // The e-mail-link add/login flow (class-email-identity.php) logs
            // attempts with method='email' and, while a link is outstanding,
            // result='link_sent' — neither existed in these ENUMs yet, so
            // every such log_attempt() call was failing silently before this.
            '2.16' => ["ALTER TABLE {$p}avm_login_attempts
                MODIFY method ENUM('proxy','google','microsoft','password_reset','email') NOT NULL,
                MODIFY result ENUM('success','no_member','hibp_warned','link_sent') NOT NULL"],
            // This column joins avm_members.lldap_user_id, whose collation is
            // deliberately utf8mb4_unicode_ci. The cache originally inherited
            // the WordPress database default (utf8mb4_unicode_520_ci on the
            // live site), which makes MariaDB reject every equality join as an
            // illegal mix of collations once OpenLDAP becomes active.
            '2.23' => ["ALTER TABLE {$p}avm_directory_users
                MODIFY user_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL"],
        ];
    }

    /** @return array<string, array{string, string, list<string>}> version => [table, column, statements when it is missing] */
    private static function missing_columns(): array {
        global $wpdb;
        $p = $wpdb->prefix;
        return [
            // Scholier/student is a status the contribution rate depends on
            // (avpvh-bookkeeping) but that can't be derived from age alone.
            '2.10' => ['avm_members', 'is_student', ["ALTER TABLE {$p}avm_members
                    ADD COLUMN is_student TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER birth_date"]],
            // Bank-account holder names are routinely printed as initials +
            // surname ("A B C Jansen", "D E F Bakker") — a separate field
            // from passport_name (a full legal name, mostly still unfilled)
            // gives avpvh-bookkeeping's matcher a direct, reliable target,
            // and can be auto-backfilled from confirmed bank transactions.
            '2.11' => ['avm_members', 'initials', ["ALTER TABLE {$p}avm_members
                    ADD COLUMN initials VARCHAR(20) NOT NULL DEFAULT '' AFTER passport_name"]],
            // Some members' exact birth date will genuinely never be known
            // (old records, lost paperwork) but the year often is — a
            // real, if imprecise, age beats avpvh-bookkeeping's
            // no-date-at-all "assume adult" fallback. Only used when
            // birth_date itself is empty (see the profile form's sanitizer).
            '2.12' => ['avm_members', 'birth_year', ["ALTER TABLE {$p}avm_members
                    ADD COLUMN birth_year SMALLINT UNSIGNED NULL AFTER birth_date"]],
            // location -> kenmerk: not every activity has a physical
            // location (contribution, a drank-tab) — kenmerk is a generic
            // "whatever identifies this activity" field, still free text.
            '2.13' => ['avm_camps', 'kenmerk', ["ALTER TABLE {$p}avm_camps CHANGE location kenmerk VARCHAR(150) NOT NULL DEFAULT ''"]],
            // Distinguishes an identity actually proven by its owner (OAuth
            // round-trip, or the e-mail-link flow) from one an admin typed
            // in directly via the "Nieuw e-mailadres koppelen" form with no
            // proof at all. There's no way to tell which mechanism created
            // an existing row after the fact, so existing rows are backfilled
            // as verified — the admin-direct-add path was always the rare
            // exception, and most rows on the live site came from a real
            // OAuth login/add. New admin-direct additions from this point on
            // are the only ones that land unverified (see class-admin.php).
            '2.15' => ['avm_member_identities', 'verified_at', [
                "ALTER TABLE {$p}avm_member_identities
                    ADD COLUMN verified_at TIMESTAMP NULL DEFAULT NULL AFTER is_primary",
                "UPDATE {$p}avm_member_identities SET verified_at = created_at",
            ]],
            // A flag like 'geroyeerd' (expelled) should force the member's
            // status to 'inactive' the moment it's assigned — see
            // set_member_flags(). Generalized as a per-flag catalog column
            // (same shape as affects_fees) rather than hardcoding the
            // 'geroyeerd' slug in application logic, so any future flag can
            // opt into the same behavior without a code change.
            '2.18' => ['avm_member_flags', 'sets_inactive', ["ALTER TABLE {$p}avm_member_flags
                    ADD COLUMN sets_inactive TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER affects_fees"]],
            // Lets an admin mark an activity as eligible to supply
            // participant-based tag suggestions in the gallery plugin
            // (matched there by activity name + year against a Drive
            // folder name) — an explicit opt-in so non-camp activities
            // like "Contributie" or "t-shirt" never accidentally match.
            '2.19' => ['avm_activities', 'gallery_taggable', ["ALTER TABLE {$p}avm_activities
                    ADD COLUMN gallery_taggable TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER kenmerk"]],
        ];
    }

    /**
     * 2.3: avm_activity_participation_days was added to install() alongside
     * avm_activity_participation, but install() only runs on activation —
     * already-active sites never got it. dbDelta is safe to call standalone
     * for one table. (Table was originally named
     * avm_camp_participation_days; a fresh install created after the 2.14
     * rename never hits this branch with the old name since install()
     * already creates the current name.)
     */
    public static function create_participation_days(): void {
        global $wpdb;
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
    }

    /**
     * 2.7: Doopnaam (baptism_name) was a separate field from paspoortnaam
     * but never actually used for anything distinct — fold any existing
     * data into passport_name and drop the column.
     */
    public static function fold_baptism_name(): void {
        global $wpdb;
        if ($wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}avm_members LIKE 'baptism_name'")) {
            $wpdb->query("UPDATE {$wpdb->prefix}avm_members
                    SET passport_name = baptism_name
                    WHERE baptism_name != '' AND passport_name = ''");
            $wpdb->query("ALTER TABLE {$wpdb->prefix}avm_members DROP COLUMN baptism_name");
        }
    }

    /**
     * 2.8: Passport name must read exactly as printed in the passport —
     * strip parenthetical nicknames/notes left over from the old doopnaam
     * data folded in during 2.7 (e.g. "Roepnaam (Volledige Namen)" ->
     * "Roepnaam Volledige Namen").
     */
    public static function strip_passport_brackets(): void {
        global $wpdb;
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
    }

    /**
     * 2.9: avm_role_delegations was added to install() alongside the rest of
     * the schema, but install() only runs on activation — already-active
     * sites never got it. dbDelta is safe to call standalone for one table.
     */
    public static function create_role_delegations(): void {
        global $wpdb;
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
    }

    /**
     * 2.14: The "camp" naming had been stale since 2.6 (avm_camps holds every
     * activity type — Kamp, Contributie, Congres, ... — "camp" was never
     * accurate for most rows). Renames only: RENAME TABLE / CHANGE COLUMN
     * preserve all existing data, nothing is recreated. avpvh-bookkeeping (a
     * separate plugin that reads avm_activities and listens for the
     * participation-saved hook) is updated and deployed together with this.
     */
    public static function rename_camp_tables(): void {
        global $wpdb;
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
    }

    /** 2.32: LLDAP is gone: drop its connection settings (incl. password). */
    public static function drop_lldap_settings(): void {
        delete_option('avpvh_lldap_url');
        delete_option('avpvh_lldap_user');
        delete_option('avpvh_lldap_password');
    }
}
