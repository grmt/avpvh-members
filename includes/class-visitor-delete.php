<?php
defined('ABSPATH') || exit;

/**
 * Admin-only removal of a disposable visitor, including accounts and dependants.
 * Unknown dependants and financial history fail closed. The confirmation signs
 * a snapshot, which is rebuilt under InnoDB row/range locks before any mutation.
 * Drive/LDAP cannot participate in SQL rollback: remove them first, retain the
 * local records on failure, and allow a new preview to resume the operation.
 */
class AVPVH_Visitor_Delete {
    private const TABLES = [
        'avm_addresses' => 'Adressen',
        'avm_activity_participation' => 'Activiteitdeelnames',
        'avm_activity_participation_days' => 'Deelnamedagen',
        'avm_fees' => 'Contributies',
        'avm_member_identities' => 'Inlogadressen',
        'avm_member_flag_assignments' => 'Kenmerken',
        'avm_relationships' => 'Relaties',
        'avm_member_audit_log' => 'Profielwijzigingen',
        'avm_member_name_aliases' => 'Naamvarianten',
        'avm_login_attempts' => 'Loginpogingen',
        'avb_member_student_years' => 'Studentjaren',
        'avb_fee_items' => 'Openstaande betaalposten',
        'avb_known_ibans' => 'Gekoppelde rekeningnummers',
        'avb_sheet_participation_meta' => 'Inschrijfgegevens',
        'avb_payment_requests' => 'Betaalverzoeken',
        'avb_disputes' => 'Vragen over betalingen',
        'avb_dispute_events' => 'Berichten over betalingen',
        'avb_congress_registrations' => 'Congresinschrijvingen',
        'avb_book_orders' => 'Boekbestellingen',
        'avb_orders' => 'Bestellingen',
        'avb_order_items' => 'Bestelregels',
        'avb_photo_shares' => 'Persoonlijke uploadshares',
        'agallery_photo_shares' => 'Gedeelde fotoselecties',
    ];
    private const EMAIL_TABLES = ['avm_login_attempts', 'avb_congress_registrations', 'avb_book_orders', 'avb_orders', 'avb_photo_shares'];

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
            self::check($wpdb->query('START TRANSACTION'));
            $plan = self::snapshot($id, true);
            if ($plan['blockers']) {
                throw new \RuntimeException(implode(' ', $plan['blockers']));
            }
            if (!hash_equals($plan['fingerprint'], $fingerprint)) {
                throw new \RuntimeException(__('De gegevens zijn gewijzigd. Bekijk het overzicht opnieuw voordat je verwijdert.', 'avpvh-members'));
            }

            // Mark as started before calling external services: even a failing
            // call may have changed remote state. No false "nothing changed".
            $external_started = true;
            foreach ($plan['folders'] as $folder) {
                self::remove_folder($folder['id'], $folder['parent']);
            }
            $result = AVPVH_Directory::delete_user((string) $plan['member']['lldap_user_id']);
            if (is_wp_error($result)) {
                throw new \RuntimeException(__('Het OpenLDAP-account kon niet worden verwijderd.', 'avpvh-members'));
            }
            foreach ($plan['records'] as $table => $record) {
                // All identifiers and conditions were built from schema/fixed
                // names and prepared values; never accept them from POST.
                self::check($wpdb->query("DELETE FROM `$table` WHERE {$record['where']}"));
            }
            foreach ($plan['tokens'] as $key => $payload) {
                delete_transient($key);
            }
            foreach ($plan['users'] as $user) {
                if (!function_exists('wp_delete_user')) {
                    require_once ABSPATH . 'wp-admin/includes/user.php';
                }
                if (!wp_delete_user((int) $user['ID'])) {
                    throw new \RuntimeException(__('Het WordPress-account kon niet worden verwijderd.', 'avpvh-members'));
                }
            }
            if ($wpdb->delete($wpdb->prefix . 'avm_members', ['id' => $id], ['%d']) !== 1) {
                throw new \RuntimeException(__('Het bezoekersrecord kon niet worden verwijderd.', 'avpvh-members'));
            }
            self::check($wpdb->query('COMMIT'));
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

    private static function snapshot(int $id, bool $lock = false): array {
        global $wpdb;
        $suffix = $lock ? ' FOR UPDATE' : '';
        $member = self::rows($wpdb->prepare("SELECT * FROM {$wpdb->prefix}avm_members WHERE id = %d$suffix", $id))[0] ?? null;
        if (!$member) {
            throw new \RuntimeException(__('Deze bezoeker bestaat niet meer.', 'avpvh-members'));
        }
        $blockers = [];
        if ($member['status'] !== 'visitor') {
            $blockers[] = __('Alleen bezoekers kunnen definitief worden verwijderd.', 'avpvh-members');
        }
        if (is_multisite()) {
            $blockers[] = __('Gedeelde accounts in een WordPress-netwerk moeten apart worden beheerd.', 'avpvh-members');
        }
        $schema = self::schema();
        $core_tables = self::strings('TABLE_NAME', [$wpdb->users, $wpdb->usermeta, $wpdb->options]);
        foreach (self::rows("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND $core_tables") as $table) {
            if ($table['ENGINE'] !== 'InnoDB') {
                $blockers[] = __('De gekoppelde gegevens ondersteunen geen veilige database-transactie.', 'avpvh-members');
            }
        }
        if (!isset($schema['avm_member_identities'])) {
            throw new \RuntimeException('identity schema incomplete');
        }
        $account = AVPVH_Directory::get_user((string) $member['lldap_user_id']);
        $groups = AVPVH_Directory::get_user_groups((string) $member['lldap_user_id']);
        if (is_wp_error($groups)) {
            throw new \RuntimeException('directory unavailable');
        }
        sort($groups);
        if (array_diff($groups, ['users', 'pvh-users'])) {
            $blockers[] = __('Dit account heeft groepsrechten. Verwijder die eerst voordat je het als testbezoeker verwijdert.', 'avpvh-members');
        }
        $emails = [];
        $identities = self::select('avm_member_identities', $wpdb->prepare('member_id = %d', $id), $schema, $lock);
        foreach ($identities['rows'] as $identity) {
            $emails[] = strtolower($identity['email']);
        }
        if (!empty($account['mail'] ?? $account['email'] ?? '')) {
            $emails[] = strtolower($account['mail'] ?? $account['email']);
        }
        // Cache is a fallback for a missing remote account during a retry.
        if (isset($schema['avm_directory_users'])) {
            foreach (self::rows($wpdb->prepare("SELECT email FROM {$wpdb->prefix}avm_directory_users WHERE user_id = %s$suffix", $member['lldap_user_id'])) as $cached) {
                $emails[] = strtolower($cached['email']);
            }
        }
        $linked_user = !empty($member['wp_user_id']) ? get_userdata((int) $member['wp_user_id']) : false;
        if ($linked_user) {
            $emails[] = strtolower($linked_user->user_email);
        }
        $emails = array_values(array_unique(array_filter($emails)));
        sort($emails);
        if ($emails) {
            $where = self::strings('email', $emails);
            if (self::rows($wpdb->prepare("SELECT id FROM {$wpdb->prefix}avm_member_identities WHERE ($where) AND member_id <> %d$suffix", $id))) {
                $blockers[] = __('Een inlogadres is ook aan een andere persoon gekoppeld.', 'avpvh-members');
            }
            if (isset($schema['avm_directory_users'])) {
                $where = self::strings('email', $emails);
                if (self::rows($wpdb->prepare("SELECT user_id FROM {$wpdb->prefix}avm_directory_users WHERE ($where) AND user_id <> %s$suffix", $member['lldap_user_id']))) {
                    $blockers[] = __('Een inlogadres is ook aan een andere persoon gekoppeld.', 'avpvh-members');
                }
            }
        }
        $users = [];
        $user_where = $wpdb->prepare('ID = %d', (int) ($member['wp_user_id'] ?? 0));
        if ($emails) {
            $user_where .= ' OR ' . self::strings('user_email', $emails);
        }
        foreach (self::rows("SELECT ID, user_login, user_email FROM {$wpdb->users} WHERE $user_where$suffix") as $user) {
            $wp_user = get_userdata((int) $user['ID']);
            $user['roles'] = $wp_user ? $wp_user->roles : [];
            $privileged = $wp_user && (user_can($wp_user, 'manage_options') || user_can($wp_user, 'edit_users')
                || user_can($wp_user, 'delete_users') || user_can($wp_user, 'edit_others_posts'));
            if ((int) $user['ID'] === get_current_user_id() || $privileged || array_diff($user['roles'], ['subscriber', 'contributor'])) {
                $blockers[] = __('Je kunt jezelf of een account met extra WordPress-rechten niet verwijderen.', 'avpvh-members');
            }
            $other = self::rows($wpdb->prepare("SELECT id FROM {$wpdb->prefix}avm_members WHERE wp_user_id = %d AND id <> %d$suffix", $user['ID'], $id));
            if ($other) {
                $blockers[] = __('Het WordPress-account is ook aan een andere persoon gekoppeld.', 'avpvh-members');
            }
            // Do not let wp_delete_user remove published content or comments.
            if (self::rows($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_author = %d$suffix", $user['ID']))
                || self::rows($wpdb->prepare("SELECT comment_ID FROM {$wpdb->comments} WHERE user_id = %d$suffix", $user['ID']))) {
                $blockers[] = __('Dit account heeft berichten of reacties. Beheer die eerst apart.', 'avpvh-members');
            }
            $user['meta'] = self::rows($wpdb->prepare("SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d$suffix", $user['ID']));
            $users[] = $user;
        }
        $user_ids = array_column($users, 'ID');
        $records = [];
        foreach ($schema as $table => $columns) {
            if ($table === 'avm_members' || $table === 'avm_directory_users') {
                continue;
            }
            $clauses = [];
            foreach ($columns['columns'] as $column => $type) {
                if (!in_array($type, ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'], true)) {
                    continue;
                }
                if ($column === 'member_id' || str_ends_with($column, '_member_id')) {
                    $clauses[] = $wpdb->prepare("`$column` = %d", $id);
                } elseif (in_array($column, ['user_id', 'wp_user_id'], true) && $user_ids) {
                    $clauses[] = self::ints($column, $user_ids);
                }
            }
            if (in_array($table, self::EMAIL_TABLES, true) && $emails && isset($columns['columns']['email'])) {
                $clauses[] = self::strings('email', $emails);
            }
            if (!$clauses) {
                continue;
            }
            $record = self::select($table, implode(' OR ', $clauses), $schema, $lock);
            if (!isset(self::TABLES[$table]) && $record['rows']) {
                $blockers[] = sprintf(__('Er zijn aanvullende gekoppelde gegevens in %s. Die moeten eerst apart worden beheerd.', 'avpvh-members'), $table);
            }
            $records[$wpdb->prefix . $table] = $record;
            foreach ($record['rows'] as $row) {
                if (isset($row['member_id']) && (int) $row['member_id'] > 0 && (int) $row['member_id'] !== $id && $table !== 'avm_relationships') {
                    $blockers[] = __('Gekoppelde gegevens horen ook bij een andere persoon. Verwijderen is geblokkeerd.', 'avpvh-members');
                }
                if (($table === 'avm_fees' && ((float) $row['amount_paid'] != 0 || !empty($row['paid_date']) || $row['status'] === 'paid'))
                    || $table === 'avb_transaction_allocations') {
                    $blockers[] = __('Er zijn geboekte betalingen gekoppeld. Deze bezoeker kan niet definitief worden verwijderd.', 'avpvh-members');
                }
                if (isset($row['distribution_status']) && $row['distribution_status'] !== 'pending') {
                    $blockers[] = __('Er zijn al afgehandelde bestellingen gekoppeld. Verwijderen is geblokkeerd.', 'avpvh-members');
                }
            }
        }
        // Fee references can belong to a different person or an unfamiliar
        // extension. Include them before selecting order/dispute children.
        $fee_ids = array_column($records[$wpdb->prefix . 'avb_fee_items']['rows'] ?? [], 'id');
        foreach ($schema as $table => $columns) {
            if (!isset($columns['columns']['fee_item_id'])) {
                continue;
            }
            $where = self::ints('fee_item_id', $fee_ids);
            $existing = $records[$wpdb->prefix . $table]['where'] ?? '';
            $record = self::select($table, $existing ? "($existing) OR $where" : $where, $schema, $lock);
            $records[$wpdb->prefix . $table] = $record;
            foreach ($record['rows'] as $row) {
                if ($table === 'avb_transaction_allocations') {
                    $blockers[] = __('Er zijn geboekte betalingen gekoppeld. Deze bezoeker kan niet definitief worden verwijderd.', 'avpvh-members');
                } elseif (!isset(self::TABLES[$table]) || (isset($row['member_id']) && (int) $row['member_id'] > 0 && (int) $row['member_id'] !== $id)) {
                    $blockers[] = __('Gekoppelde gegevens horen ook bij een andere persoon. Verwijderen is geblokkeerd.', 'avpvh-members');
                }
                if (isset($row['distribution_status']) && $row['distribution_status'] !== 'pending') {
                    $blockers[] = __('Er zijn al afgehandelde bestellingen gekoppeld. Verwijderen is geblokkeerd.', 'avpvh-members');
                }
            }
        }
        // Indirect children have no member_id of their own. Select them even
        // when empty to acquire insertion range locks on the parent IDs.
        foreach ([
            ['avm_activity_participation_days', 'participation_id', 'avm_activity_participation'],
            ['avb_order_items', 'order_id', 'avb_orders'],
            ['avb_dispute_events', 'dispute_id', 'avb_disputes'],
            ['avb_payment_requests', 'fee_item_id', 'avb_fee_items'],
            ['avb_transaction_allocations', 'fee_item_id', 'avb_fee_items'],
        ] as [$child, $column, $parent]) {
            if (!isset($schema[$child])) {
                if ($child === 'avb_transaction_allocations' && isset($schema[$parent])) {
                    throw new \RuntimeException('payment schema incomplete');
                }
                continue;
            }
            $ids = array_column($records[$wpdb->prefix . $parent]['rows'] ?? [], 'id');
            $where = self::ints($column, $ids);
            $existing = $records[$wpdb->prefix . $child]['where'] ?? '';
            $record = self::select($child, $existing ? "($existing) OR $where" : $where, $schema, $lock);
            $records[$wpdb->prefix . $child] = $record;
            if ($child === 'avb_transaction_allocations' && $record['rows']) {
                $blockers[] = __('Er zijn geboekte betalingen gekoppeld. Deze bezoeker kan niet definitief worden verwijderd.', 'avpvh-members');
            }
        }
        if (isset($schema['avb_transactions'])) {
            foreach (self::rows("SELECT id, suggested_member_ids, draft_data FROM {$wpdb->prefix}avb_transactions WHERE suggested_member_ids <> '' OR draft_data IS NOT NULL$suffix") as $transaction) {
                $draft = json_decode($transaction['draft_data'] ?? 'null', true);
                if (in_array((string) $id, array_map('trim', explode(',', $transaction['suggested_member_ids'])), true) || self::mentions_member($draft, $id, $fee_ids)) {
                    $blockers[] = __('Een banktransactie verwijst naar deze bezoeker. Laat de penningmeester die koppeling eerst controleren.', 'avpvh-members');
                }
            }
        }
        foreach ($records as $record) {
            if ($record['engine'] !== 'InnoDB') {
                $blockers[] = __('De gekoppelde gegevens ondersteunen geen veilige database-transactie.', 'avpvh-members');
            }
        }
        if ($schema['avm_members']['engine'] !== 'InnoDB') {
            $blockers[] = __('De gekoppelde gegevens ondersteunen geen veilige database-transactie.', 'avpvh-members');
        }
        // Delete children first, including installations with foreign keys.
        $children = [];
        foreach (['avm_activity_participation_days', 'avb_order_items', 'avb_dispute_events', 'avb_payment_requests', 'avb_transaction_allocations', 'avb_disputes', 'avb_congress_registrations', 'avb_book_orders', 'avb_orders'] as $child) {
            $table = $wpdb->prefix . $child;
            if (isset($records[$table])) {
                $children[$table] = $records[$table];
                unset($records[$table]);
            }
        }
        $records = $children + $records;
        $folders = self::folders($records, $users, $blockers, $lock, $schema);
        $tokens = self::tokens($id, $user_ids, $lock);
        $blockers = array_values(array_unique($blockers));
        $plan = compact('member', 'account', 'groups', 'emails', 'users', 'records', 'folders', 'tokens', 'blockers');
        $plan['fingerprint'] = hash_hmac('sha256', wp_json_encode($plan), wp_salt('nonce'));
        return $plan;
    }

    private static function folders(array $records, array $users, array &$blockers, bool $lock, array $schema): array {
        global $wpdb;
        $suffix = $lock ? ' FOR UPDATE' : '';
        $folders = [];
        foreach (['avb_photo_shares', 'agallery_photo_shares'] as $table) {
            $parent = $table === 'avb_photo_shares'
                ? (class_exists('AVBK_Photo_Share') ? AVBK_Photo_Share::ROOT_FOLDER_ID : '')
                : (class_exists('Avpvh\\Options') ? trim((string) \Avpvh\Options::$share_folder->get()) : '');
            foreach ($records[$wpdb->prefix . $table]['rows'] ?? [] as $row) {
                $folder_id = (string) ($row['drive_folder_id'] ?? '');
                if ($folder_id === '' || str_starts_with($folder_id, 'share-')) {
                    continue; // Legacy fake IDs are local records, not real folders.
                }
                if ($parent === '' || $folder_id === $parent) {
                    $blockers[] = __('Een gekoppelde Drive-map kan niet veilig als persoonlijke share worden vastgesteld.', 'avpvh-members');
                    continue;
                }
                foreach (['avb_photo_shares', 'agallery_photo_shares'] as $other_table) {
                    if (!isset($schema[$other_table])) {
                        continue;
                    }
                    $except = $other_table === $table ? $wpdb->prepare(' AND id <> %d', $row['id']) : '';
                    $shared = self::rows($wpdb->prepare("SELECT id FROM {$wpdb->prefix}$other_table WHERE drive_folder_id = %s$except$suffix", $folder_id));
                    if ($shared) {
                        $blockers[] = __('Een Drive-map is aan meerdere shares gekoppeld. Controleer die eerst apart.', 'avpvh-members');
                    }
                }
                $folders[$folder_id] = ['id' => $folder_id, 'parent' => $parent];
            }
        }
        foreach ($users as $user) {
            foreach ($user['meta'] as $meta) {
                if ($meta['meta_key'] === 'photo_share_folder_id') {
                    $id = $meta['meta_value'];
                } elseif ($meta['meta_key'] === 'photo_share_url' && preg_match('~/folders/([\w-]+)~', $meta['meta_value'], $matches)) {
                    $id = $matches[1];
                } else {
                    continue;
                }
                if ($id !== '' && !str_starts_with($id, 'share-') && !isset($folders[$id])) {
                    $blockers[] = __('Er is een handmatig gekoppelde Drive-map. Beheer die eerst apart.', 'avpvh-members');
                }
            }
        }
        if ($folders) {
            $where = self::ints('user_id', array_column($users, 'ID'));
            foreach (self::rows("SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key IN ('photo_share_url', 'photo_share_folder_id') AND NOT ($where)$suffix") as $meta) {
                $id = $meta['meta_value'];
                if ($meta['meta_key'] === 'photo_share_url') {
                    $id = preg_match('~/folders/([\w-]+)~', $id, $matches) ? $matches[1] : '';
                }
                if (isset($folders[$id])) {
                    $blockers[] = __('Een Drive-map is ook aan een ander account gekoppeld. Controleer die eerst apart.', 'avpvh-members');
                }
            }
        }
        if ($folders && !class_exists('Avpvh\\Frontend\\Share_Drive')) {
            $blockers[] = __('De Drive-integratie is niet beschikbaar om de fotoshares te verwijderen.', 'avpvh-members');
        }
        ksort($folders);
        return array_values($folders);
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
            throw new \RuntimeException('Drive lookup failed');
        }
        if ($id === $parent || $folder->getMimeType() !== 'application/vnd.google-apps.folder'
            || !in_array($parent, $folder->getParents() ?? [], true)) {
            throw new \RuntimeException('Drive folder is not a personal child folder');
        }
        $drive->files->delete($id, ['supportsAllDrives' => true]);
    }

    private static function tokens(int $id, array $users, bool $lock): array {
        global $wpdb;
        $tokens = [];
        $where = $wpdb->prepare('option_name LIKE %s OR option_name LIKE %s', $wpdb->esc_like('_transient_avpvh_email_identity_') . '%', $wpdb->esc_like('_transient_avpvh_oauth_state_') . '%');
        foreach (self::rows("SELECT option_name, option_value FROM {$wpdb->options} WHERE $where" . ($lock ? ' FOR UPDATE' : '')) as $row) {
            $payload = json_decode($row['option_value'], true);
            if (is_array($payload) && ((int) ($payload['member_id'] ?? 0) === $id || in_array((int) ($payload['requesting_user_id'] ?? 0), array_map('intval', $users), true))) {
                $tokens[substr($row['option_name'], strlen('_transient_'))] = $row['option_value'];
            }
        }
        ksort($tokens);
        return $tokens;
    }

    private static function mentions_member($value, int $id, array $fee_ids): bool {
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $key => $entry) {
            if (is_scalar($entry) && (($key === 'member_id' || str_ends_with((string) $key, '_member_id')) && (int) $entry === $id
                || ($key === 'fee_item_id' && in_array((int) $entry, array_map('intval', $fee_ids), true)))) {
                return true;
            }
            if (is_array($entry) && self::mentions_member($entry, $id, $fee_ids)) {
                return true;
            }
        }
        return false;
    }

    private static function schema(): array {
        global $wpdb;
        $schema = [];
        $sql = $wpdb->prepare("SELECT c.TABLE_NAME, c.COLUMN_NAME, c.DATA_TYPE, t.ENGINE FROM information_schema.COLUMNS c JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME LIKE %s ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION", $wpdb->esc_like($wpdb->prefix) . '%');
        foreach (self::rows($sql) as $column) {
            $table = substr($column['TABLE_NAME'], strlen($wpdb->prefix));
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $column['TABLE_NAME']) || !preg_match('/^[a-zA-Z0-9_]+$/', $column['COLUMN_NAME'])) {
                throw new \RuntimeException('invalid identifier');
            }
            // WordPress core user tables are handled via its account API.
            if (in_array($column['TABLE_NAME'], [$wpdb->users, $wpdb->usermeta, $wpdb->comments], true)) {
                continue;
            }
            $schema[$table]['columns'][$column['COLUMN_NAME']] = $column['DATA_TYPE'];
            $schema[$table]['engine'] = $column['ENGINE'];
        }
        return $schema;
    }

    private static function select(string $table, string $where, array $schema, bool $lock): array {
        global $wpdb;
        if (!isset($schema[$table])) {
            return ['rows' => [], 'where' => $where, 'engine' => 'InnoDB', 'label' => __(self::TABLES[$table] ?? $table, 'avpvh-members')];
        }
        return ['rows' => self::rows("SELECT * FROM `{$wpdb->prefix}$table` WHERE $where" . ($lock ? ' FOR UPDATE' : '')),
            'where' => $where, 'engine' => $schema[$table]['engine'], 'label' => __(self::TABLES[$table] ?? $table, 'avpvh-members')];
    }

    private static function rows(string $sql): array {
        global $wpdb;
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if ($wpdb->last_error !== '' || !is_array($rows)) {
            throw new \RuntimeException(__('De databasecontrole is mislukt.', 'avpvh-members'));
        }
        usort($rows, static fn($a, $b) => strcmp(wp_json_encode($a), wp_json_encode($b)));
        return $rows;
    }

    private static function ints(string $column, array $ids): string {
        return $ids ? "`$column` IN (" . implode(',', array_map('intval', $ids)) . ')' : '0 = 1';
    }

    private static function strings(string $column, array $values): string {
        global $wpdb;
        return $wpdb->prepare("`$column` IN (" . implode(',', array_fill(0, count($values), '%s')) . ')', ...$values);
    }

    private static function check($result): void {
        if ($result === false) {
            throw new \RuntimeException(__('De databasewijziging is mislukt.', 'avpvh-members'));
        }
    }
}
