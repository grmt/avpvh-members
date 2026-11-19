<?php
defined('ABSPATH') || exit;

/**
 * The visitor's identity side of a deletion plan: the member row, directory
 * account, e-mail addresses, WordPress accounts, Drive folders and pending
 * tokens, with every reason these block the removal. See AVPVH_Visitor_Delete.
 */
class AVPVH_Visitor_Delete_Accounts {
    public static function member_blockers(array $member): array {
        $blockers = [];
        if ($member['status'] !== 'visitor') {
            $blockers[] = __('Alleen bezoekers kunnen definitief worden verwijderd.', 'avpvh-members');
        }
        if (is_multisite()) {
            $blockers[] = __('Gedeelde accounts in een WordPress-netwerk moeten apart worden beheerd.', 'avpvh-members');
        }
        return $blockers;
    }

    /** @return array{0: mixed, 1: list<string>} the directory account and its sorted groups */
    public static function directory_account(string $uid, array &$blockers): array {
        $account = AVPVH_Directory::get_user($uid);
        $groups = AVPVH_Directory::get_user_groups($uid);
        if (is_wp_error($groups)) {
            throw new AVPVH_Visitor_Delete_Exception('directory unavailable');
        }
        sort($groups);
        if (array_diff($groups, ['users', 'pvh-users'])) {
            $blockers[] = __('Dit account heeft groepsrechten. Verwijder die eerst voordat je het als testbezoeker verwijdert.', 'avpvh-members');
        }
        return [$account, $groups];
    }

    /** Every address the visitor can log in or be mailed with, lowercase and sorted. */
    public static function emails(int $id, array $member, $account, array $schema, bool $lock): array {
        global $wpdb;
        $identities = AVPVH_Visitor_Delete_Db::select('avm_member_identities', $wpdb->prepare('member_id = %d', $id), $schema, $lock);
        $emails = array_map(static fn(array $identity): string => strtolower($identity['email']), $identities['rows']);
        $directory_mail = $account['mail'] ?? $account['email'] ?? '';
        if (!empty($directory_mail)) {
            $emails[] = strtolower($directory_mail);
        }
        // Cache is a fallback for a missing remote account during a retry.
        if (isset($schema['avm_directory_users'])) {
            $sql = $wpdb->prepare("SELECT email FROM {$wpdb->prefix}avm_directory_users WHERE user_id = %s", $member['lldap_user_id']);
            foreach (AVPVH_Visitor_Delete_Db::rows($sql . (AVPVH_Visitor_Delete_Db::lock($lock))) as $cached) {
                $emails[] = strtolower($cached['email']);
            }
        }
        $linked_user = !empty($member['wp_user_id']) ? get_userdata((int) $member['wp_user_id']) : false;
        if ($linked_user) {
            $emails[] = strtolower($linked_user->user_email);
        }
        $emails = array_values(array_unique(array_filter($emails)));
        sort($emails);
        return $emails;
    }

    public static function shared_email_blockers(int $id, array $member, array $emails, array $schema, string $suffix): array {
        global $wpdb;
        if (!$emails) {
            return [];
        }
        $blockers = [];
        $where = AVPVH_Visitor_Delete_Db::strings('email', $emails);
        if (AVPVH_Visitor_Delete_Db::rows($wpdb->prepare("SELECT id FROM {$wpdb->prefix}avm_member_identities WHERE ($where) AND member_id <> %d$suffix", $id))) {
            $blockers[] = AVPVH_Visitor_Delete_Db::blocker('shared_email');
        }
        if (isset($schema['avm_directory_users'])
            && AVPVH_Visitor_Delete_Db::rows($wpdb->prepare("SELECT user_id FROM {$wpdb->prefix}avm_directory_users WHERE ($where) AND user_id <> %s$suffix", $member['lldap_user_id']))) {
            $blockers[] = AVPVH_Visitor_Delete_Db::blocker('shared_email');
        }
        return $blockers;
    }

    /** The WordPress accounts linked by ID or by e-mail address, with their roles and meta. */
    public static function users(int $id, array $member, array $emails, string $suffix, array &$blockers): array {
        global $wpdb;
        $user_where = $wpdb->prepare('ID = %d', (int) ($member['wp_user_id'] ?? 0));
        if ($emails) {
            $user_where .= ' OR ' . AVPVH_Visitor_Delete_Db::strings('user_email', $emails);
        }
        $users = [];
        foreach (AVPVH_Visitor_Delete_Db::rows("SELECT ID, user_login, user_email FROM {$wpdb->users} WHERE $user_where$suffix") as $user) {
            $wp_user = get_userdata((int) $user['ID']);
            $user['roles'] = $wp_user ? $wp_user->roles : [];
            array_push($blockers, ...self::user_blockers($user, $wp_user, $id, $suffix));
            $user['meta'] = AVPVH_Visitor_Delete_Db::rows($wpdb->prepare("SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d$suffix", $user['ID']));
            $users[] = $user;
        }
        return $users;
    }

    private static function user_blockers(array $user, $wp_user, int $id, string $suffix): array {
        global $wpdb;
        $blockers = [];
        if (self::is_protected_account($user, $wp_user)) {
            $blockers[] = __('Je kunt jezelf of een account met extra WordPress-rechten niet verwijderen.', 'avpvh-members');
        }
        if (AVPVH_Visitor_Delete_Db::rows($wpdb->prepare("SELECT id FROM {$wpdb->prefix}avm_members WHERE wp_user_id = %d AND id <> %d$suffix", $user['ID'], $id))) {
            $blockers[] = __('Het WordPress-account is ook aan een andere persoon gekoppeld.', 'avpvh-members');
        }
        // Do not let wp_delete_user remove published content or comments.
        if (AVPVH_Visitor_Delete_Db::rows($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_author = %d$suffix", $user['ID']))
            || AVPVH_Visitor_Delete_Db::rows($wpdb->prepare("SELECT comment_ID FROM {$wpdb->comments} WHERE user_id = %d$suffix", $user['ID']))) {
            $blockers[] = __('Dit account heeft berichten of reacties. Beheer die eerst apart.', 'avpvh-members');
        }
        return $blockers;
    }

    /** The current user, or an account with any role or capability beyond a plain subscriber/contributor. */
    private static function is_protected_account(array $user, $wp_user): bool {
        $elevated = static fn(string $capability): bool => user_can($wp_user, $capability);
        return (int) $user['ID'] === get_current_user_id()
            || array_diff($user['roles'], ['subscriber', 'contributor'])
            || ($wp_user && array_filter(['manage_options', 'edit_users', 'delete_users', 'edit_others_posts'], $elevated));
    }

    private const SHARE_TABLES = ['avb_photo_shares', 'agallery_photo_shares'];

    /** The visitor's personal Drive share folders, each with the root it must sit in. */
    public static function folders(array $records, array $users, array &$blockers, bool $lock, array $schema): array {
        global $wpdb;
        $folders = [];
        foreach (self::SHARE_TABLES as $table) {
            $rows = $records[$wpdb->prefix . $table]['rows'] ?? [];
            $folders = array_replace($folders, self::share_table_folders($table, $rows, $schema, $lock, $blockers));
        }
        array_push($blockers, ...self::manual_folder_blockers($users, $folders));
        array_push($blockers, ...self::foreign_folder_blockers($users, $folders, $lock));
        if ($folders && !class_exists('Avpvh\\Frontend\\Share_Drive')) {
            $blockers[] = __('De Drive-integratie is niet beschikbaar om de fotoshares te verwijderen.', 'avpvh-members');
        }
        ksort($folders);
        return array_values($folders);
    }

    private static function share_table_folders(string $table, array $rows, array $schema, bool $lock, array &$blockers): array {
        $parent = self::share_root($table);
        $folders = [];
        foreach ($rows as $row) {
            $folder_id = (string) ($row['drive_folder_id'] ?? '');
            if (!self::is_real_folder($folder_id)) {
                continue;
            }
            if ($parent === '' || $folder_id === $parent) {
                $blockers[] = __('Een gekoppelde Drive-map kan niet veilig als persoonlijke share worden vastgesteld.', 'avpvh-members');
                continue;
            }
            array_push($blockers, ...self::shared_folder_blockers($table, (int) $row['id'], $folder_id, $schema, $lock));
            $folders[$folder_id] = ['id' => $folder_id, 'parent' => $parent];
        }
        return $folders;
    }

    /** The Drive folder that holds the personal folders of $table's shares; '' when unknown. */
    private static function share_root(string $table): string {
        if ($table === 'avb_photo_shares') {
            return class_exists('AVBK_Photo_Share') ? AVBK_Photo_Share::ROOT_FOLDER_ID : '';
        }
        return class_exists('Avpvh\\Options') ? trim((string) \Avpvh\Options::$share_folder->get()) : '';
    }

    /** Legacy fake IDs ("share-…") are local records, not real folders. */
    private static function is_real_folder(string $folder_id): bool {
        return $folder_id !== '' && !str_starts_with($folder_id, 'share-');
    }

    /** Another share row pointing at the same folder. */
    private static function shared_folder_blockers(string $table, int $row_id, string $folder_id, array $schema, bool $lock): array {
        global $wpdb;
        $blockers = [];
        foreach (self::SHARE_TABLES as $other_table) {
            if (!isset($schema[$other_table])) {
                continue;
            }
            $except = $other_table === $table ? $wpdb->prepare(' AND id <> %d', $row_id) : '';
            $sql = $wpdb->prepare("SELECT id FROM {$wpdb->prefix}$other_table WHERE drive_folder_id = %s$except", $folder_id);
            if (AVPVH_Visitor_Delete_Db::rows($sql . AVPVH_Visitor_Delete_Db::lock($lock))) {
                $blockers[] = __('Een Drive-map is aan meerdere shares gekoppeld. Controleer die eerst apart.', 'avpvh-members');
            }
        }
        return $blockers;
    }

    /** The folder ID in a photo_share_folder_id or photo_share_url user meta row; '' for anything else. */
    private static function meta_folder_id(array $meta): string {
        if ($meta['meta_key'] === 'photo_share_url') {
            return preg_match('~/folders/([\w-]+)~', (string) $meta['meta_value'], $matches) ? $matches[1] : '';
        }
        return $meta['meta_key'] === 'photo_share_folder_id' ? (string) $meta['meta_value'] : '';
    }

    /** A folder in the visitor's own user meta that no share record proves to be personal. */
    private static function manual_folder_blockers(array $users, array $folders): array {
        $blockers = [];
        foreach ($users as $user) {
            foreach ($user['meta'] as $meta) {
                $id = self::meta_folder_id($meta);
                if (self::is_real_folder($id) && !isset($folders[$id])) {
                    $blockers[] = __('Er is een handmatig gekoppelde Drive-map. Beheer die eerst apart.', 'avpvh-members');
                }
            }
        }
        return $blockers;
    }

    /** One of the folders also linked in another account's user meta. */
    private static function foreign_folder_blockers(array $users, array $folders, bool $lock): array {
        global $wpdb;
        if (!$folders) {
            return [];
        }
        $blockers = [];
        $where = AVPVH_Visitor_Delete_Db::ints('user_id', array_column($users, 'ID'));
        $sql = "SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key IN ('photo_share_url', 'photo_share_folder_id') AND NOT ($where)";
        foreach (AVPVH_Visitor_Delete_Db::rows($sql . AVPVH_Visitor_Delete_Db::lock($lock)) as $meta) {
            if (isset($folders[self::meta_folder_id($meta)])) {
                $blockers[] = __('Een Drive-map is ook aan een ander account gekoppeld. Controleer die eerst apart.', 'avpvh-members');
            }
        }
        return $blockers;
    }

    public static function tokens(int $id, array $users, bool $lock): array {
        global $wpdb;
        $tokens = [];
        $where = $wpdb->prepare('option_name LIKE %s OR option_name LIKE %s', $wpdb->esc_like('_transient_avpvh_email_identity_') . '%', $wpdb->esc_like('_transient_avpvh_oauth_state_') . '%');
        foreach (AVPVH_Visitor_Delete_Db::rows("SELECT option_name, option_value FROM {$wpdb->options} WHERE $where" . (AVPVH_Visitor_Delete_Db::lock($lock))) as $row) {
            $payload = json_decode($row['option_value'], true);
            if (is_array($payload) && ((int) ($payload['member_id'] ?? 0) === $id || in_array((int) ($payload['requesting_user_id'] ?? 0), array_map('intval', $users), true))) {
                $tokens[substr($row['option_name'], strlen('_transient_'))] = $row['option_value'];
            }
        }
        ksort($tokens);
        return $tokens;
    }
}
