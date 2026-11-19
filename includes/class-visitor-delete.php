<?php
defined('ABSPATH') || exit;

require_once __DIR__ . '/class-visitor-delete-exception.php';
require_once __DIR__ . '/class-visitor-delete-db.php';
require_once __DIR__ . '/class-visitor-delete-accounts.php';
require_once __DIR__ . '/class-visitor-delete-records.php';

/**
 * Admin-only removal of a disposable visitor, including accounts and dependants.
 * Unknown dependants and financial history fail closed. The confirmation signs
 * a snapshot, which is rebuilt under InnoDB row/range locks before any mutation.
 * Drive/LDAP cannot participate in SQL rollback: remove them first, retain the
 * local records on failure, and allow a new preview to resume the operation.
 *
 * The plan is built from AVPVH_Visitor_Delete_Accounts (who and which
 * accounts), AVPVH_Visitor_Delete_Records (which rows) and the helpers in
 * AVPVH_Visitor_Delete_Db.
 */
class AVPVH_Visitor_Delete {
    public static function preview(int $id): array|\WP_Error {
        if (!current_user_can('manage_options')) {
            return new \WP_Error('forbidden', __('Geen toegang.', 'avpvh-members'));
        }
        try {
            return self::snapshot($id);
        } catch (\Throwable $e) {
            return new \WP_Error('check_failed', __('De gekoppelde gegevens konden niet volledig worden gecontroleerd. Er is niets verwijderd.', 'avpvh-members'));
        }
    }

    /** $confirmation is the member ID deliberately typed on the preview page. */
    public static function execute(int $id, string $fingerprint, string $confirmation, bool $is_test): true|\WP_Error {
        global $wpdb;
        if (!current_user_can('manage_options')) {
            return new \WP_Error('forbidden', __('Geen toegang.', 'avpvh-members'));
        }
        if (!$is_test || (string) $id !== trim($confirmation)) {
            return new \WP_Error('unconfirmed', __('Bevestig dat dit een testaccount is en typ het bezoekersnummer.', 'avpvh-members'));
        }
        $external_started = false;
        try {
            AVPVH_Visitor_Delete_Db::check($wpdb->query('START TRANSACTION'));
            $plan = self::snapshot($id, true);
            if ($plan['blockers']) {
                throw new AVPVH_Visitor_Delete_Exception(implode(' ', $plan['blockers']));
            }
            if (!hash_equals($plan['fingerprint'], $fingerprint)) {
                throw new AVPVH_Visitor_Delete_Exception(__('De gegevens zijn gewijzigd. Bekijk het overzicht opnieuw voordat je verwijdert.', 'avpvh-members'));
            }

            // Mark as started before calling external services: even a failing
            // call may have changed remote state. No false "nothing changed".
            $external_started = true;
            foreach ($plan['folders'] as $folder) {
                self::remove_folder($folder['id'], $folder['parent']);
            }
            $result = AVPVH_Directory::delete_user((string) $plan['member']['lldap_user_id']);
            if (is_wp_error($result)) {
                throw new AVPVH_Visitor_Delete_Exception(__('Het OpenLDAP-account kon niet worden verwijderd.', 'avpvh-members'));
            }
            foreach ($plan['records'] as $table => $record) {
                // All identifiers and conditions were built from schema/fixed
                // names and prepared values; never accept them from POST.
                AVPVH_Visitor_Delete_Db::check($wpdb->query("DELETE FROM `$table` WHERE {$record['where']}")); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            }
            foreach ($plan['tokens'] as $key => $payload) {
                delete_transient($key);
            }
            foreach ($plan['users'] as $user) {
                if (!function_exists('wp_delete_user')) {
                    require_once ABSPATH . 'wp-admin/includes/user.php';
                }
                if (!wp_delete_user((int) $user['ID'])) {
                    throw new AVPVH_Visitor_Delete_Exception(__('Het WordPress-account kon niet worden verwijderd.', 'avpvh-members'));
                }
            }
            if ($wpdb->delete($wpdb->prefix . 'avm_members', ['id' => $id], ['%d']) !== 1) {
                throw new AVPVH_Visitor_Delete_Exception(__('Het bezoekersrecord kon niet worden verwijderd.', 'avpvh-members'));
            }
            AVPVH_Visitor_Delete_Db::check($wpdb->query('COMMIT'));
            AVPVH_Directory::forget_groups((string) $plan['member']['lldap_user_id']);
            return true;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            foreach ($plan['users'] ?? [] as $user) {
                clean_user_cache((int) $user['ID']);
            }
            $message = $external_started
                ? __('Verwijderen is niet afgerond. De lokale bezoekersgegevens zijn behouden, maar een Drive-map of het OpenLDAP-account kan al verwijderd zijn. Open het overzicht opnieuw om de resterende gegevens te verwijderen.', 'avpvh-members')
                : $e->getMessage();
            return new \WP_Error('delete_failed', $message);
        }
    }

    /**
     * Everything the removal of visitor $id touches, and every reason not to.
     * With $lock, all rows read are locked (FOR UPDATE) inside the caller's
     * transaction. The fingerprint signs the whole plan.
     */
    private static function snapshot(int $id, bool $lock = false): array {
        global $wpdb;
        $suffix = AVPVH_Visitor_Delete_Db::lock($lock);
        $member = AVPVH_Visitor_Delete_Db::rows($wpdb->prepare("SELECT * FROM {$wpdb->prefix}avm_members WHERE id = %d$suffix", $id))[0] ?? null;
        if (!$member) {
            throw new AVPVH_Visitor_Delete_Exception(__('Deze bezoeker bestaat niet meer.', 'avpvh-members')); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the message is escaped where it is shown
        }
        $blockers = AVPVH_Visitor_Delete_Accounts::member_blockers($member);
        $schema = AVPVH_Visitor_Delete_Db::schema();
        array_push($blockers, ...AVPVH_Visitor_Delete_Records::core_engine_blockers());
        if (!isset($schema['avm_member_identities'])) {
            throw new AVPVH_Visitor_Delete_Exception('identity schema incomplete');
        }
        [$account, $groups] = AVPVH_Visitor_Delete_Accounts::directory_account((string) $member['lldap_user_id'], $blockers);
        $emails = AVPVH_Visitor_Delete_Accounts::emails($id, $member, $account, $schema, $lock);
        array_push($blockers, ...AVPVH_Visitor_Delete_Accounts::shared_email_blockers($id, $member, $emails, $schema, $suffix));
        $users = AVPVH_Visitor_Delete_Accounts::users($id, $member, $emails, $suffix, $blockers);
        $user_ids = array_column($users, 'ID');
        $records = AVPVH_Visitor_Delete_Records::member_records($id, $user_ids, $emails, $schema, $lock, $blockers);
        // Fee references can belong to a different person or an unfamiliar
        // extension. Include them before selecting order/dispute children.
        $fee_ids = array_column($records[$wpdb->prefix . 'avb_fee_items']['rows'] ?? [], 'id');
        $records = AVPVH_Visitor_Delete_Records::fee_records($id, $fee_ids, $records, $schema, $lock, $blockers);
        $records = AVPVH_Visitor_Delete_Records::child_records($records, $schema, $lock, $blockers);
        array_push($blockers, ...AVPVH_Visitor_Delete_Records::transaction_blockers($id, $fee_ids, $schema, $suffix));
        array_push($blockers, ...AVPVH_Visitor_Delete_Records::engine_blockers($records, $schema));
        $records = AVPVH_Visitor_Delete_Records::children_first($records);
        $folders = AVPVH_Visitor_Delete_Accounts::folders($records, $users, $blockers, $lock, $schema);
        $tokens = AVPVH_Visitor_Delete_Accounts::tokens($id, $user_ids, $lock);
        $blockers = array_values(array_unique($blockers));
        $plan = compact('member', 'account', 'groups', 'emails', 'users', 'records', 'folders', 'tokens', 'blockers');
        $plan['fingerprint'] = hash_hmac('sha256', wp_json_encode($plan), wp_salt('nonce'));
        return $plan;
    }

    /** Delete only a recorded child folder, never its root or arbitrary files. */
    private static function remove_folder(string $id, string $parent): void {
        $drive = \Avpvh\Frontend\Share_Drive::drive();
        try {
            $folder = $drive->files->get($id, ['fields' => 'id,mimeType,parents', 'supportsAllDrives' => true]);
        } catch (\Throwable $e) {
            if ((int) $e->getCode() === 404) {
                return; // Idempotent retry after a partially completed removal.
            }
            throw new AVPVH_Visitor_Delete_Exception('Drive lookup failed');
        }
        if ($id === $parent || $folder->getMimeType() !== 'application/vnd.google-apps.folder'
            || !in_array($parent, $folder->getParents() ?? [], true)) {
            throw new AVPVH_Visitor_Delete_Exception('Drive folder is not a personal child folder');
        }
        $drive->files->delete($id, ['supportsAllDrives' => true]);
    }
}
