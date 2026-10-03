<?php
defined('ABSPATH') || exit;

/**
 * Local copy of the identity fields the plugin needs in SQL (uid, e-mail,
 * display name), filled from OpenLDAP. OpenLDAP keeps its data in LMDB, so it
 * can't be JOINed like lldap.users was; this table can, with the same column
 * names, so AVPVH_DB's queries only swap the table (AVPVH_DB::identity_table()).
 *
 * OpenLDAP stays the source: this table is only ever written from directory
 * data — write-through after every plugin write (AVPVH_Directory), a full sync
 * every 15 minutes via WP-cron, and a refresh of the one account at login.
 * Only used with the OpenLDAP backend.
 */
final class AVPVH_Directory_Cache {

    public const CRON_HOOK = 'avpvh_directory_sync';

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'avm_directory_users';
    }

    public static function init(): void {
        add_filter('cron_schedules', [self::class, 'add_schedule']);
        add_action(self::CRON_HOOK, [self::class, 'full_sync']);
        add_action('init', [self::class, 'ensure_scheduled']);
    }

    public static function add_schedule(array $schedules): array {
        $schedules['avpvh_15min'] = ['interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'Elke 15 minuten (AV-PvH directory)'];
        return $schedules;
    }

    public static function ensure_scheduled(): void {
        $scheduled = wp_next_scheduled(self::CRON_HOOK);
        if (AVPVH_Directory::is_openldap() && !$scheduled) {
            wp_schedule_event(time() + 60, 'avpvh_15min', self::CRON_HOOK);
        } elseif (!AVPVH_Directory::is_openldap() && $scheduled) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }
    }

    /** @param array{uid: string, mail: string, display_name: string} $user */
    public static function upsert(array $user): void {
        global $wpdb;
        $uid = strtolower(trim((string) $user['uid']));
        if ($uid === '') {
            return;
        }
        $mail = trim((string) $user['mail']);
        $wpdb->replace(self::table(), [
            'user_id'         => $uid,
            'email'           => $mail,
            'lowercase_email' => strtolower($mail),
            'display_name'    => (string) $user['display_name'],
            'synced_at'       => current_time('mysql'),
        ], ['%s', '%s', '%s', '%s', '%s']);
    }

    public static function delete(string $uid): void {
        global $wpdb;
        $wpdb->delete(self::table(), ['user_id' => strtolower($uid)], ['%s']);
    }

    /** Re-read one account from the directory (gone there → gone here). */
    public static function refresh_user(string $uid): void {
        if (!AVPVH_Directory::is_openldap()) {
            return;
        }
        $user = AVPVH_Directory::get_user($uid);
        if ($user) {
            self::upsert($user);
        } elseif (AVPVH_Directory::test_connection() === true) {
            // Only delete when the directory itself answered; a connection
            // problem must never empty the member list.
            self::delete($uid);
        }
    }

    /**
     * Mirror every account under ou=people. Refuses to apply an empty or
     * failed listing, so an outage can't wipe the member list.
     * @return array{ok: bool, upserted?: int, deleted?: int, error?: string}
     */
    public static function full_sync(): array {
        global $wpdb;
        if (!AVPVH_Directory::is_openldap()) {
            return ['ok' => false, 'error' => 'Directory-backend is niet OpenLDAP.'];
        }
        $users = AVPVH_Directory::list_users();
        if (is_wp_error($users)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operational log of a failed directory action, for the server log; not debug output
            error_log('AVPVH_Directory_Cache: sync failed: ' . $users->get_error_message());
            return ['ok' => false, 'error' => $users->get_error_message()];
        }
        if (!$users) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operational log of a failed directory action, for the server log; not debug output
            error_log('AVPVH_Directory_Cache: directory returned no accounts; cache left unchanged');
            return ['ok' => false, 'error' => 'De directory gaf geen accounts terug; cache ongewijzigd.'];
        }

        $seen = [];
        foreach ($users as $user) {
            self::upsert($user);
            $seen[] = strtolower($user['uid']);
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- fixed table name ($wpdb->prefix + constant), no user input, nothing to prepare
        $existing = $wpdb->get_col('SELECT user_id FROM ' . self::table());
        $gone = array_diff($existing, $seen);
        foreach ($gone as $uid) {
            self::delete($uid);
        }
        update_option('avpvh_directory_synced_at', current_time('mysql'), false);
        return ['ok' => true, 'upserted' => count($seen), 'deleted' => count($gone)];
    }
}
