<?php
defined('ABSPATH') || exit;

// Club-officer roles, backed by LLDAP group membership (the same
// infrastructure already used for the "boek" document-search gate in
// AVPVH_Nav_Auth::has_boek_access()) plus a lightweight, time-boxed
// delegation layer for temporary handoffs (e.g. penningmeester during
// camp) that never touches the real LLDAP group.
class AVPVH_Roles {

    // Holding any of these implies 'bestuur' too — computed here rather
    // than requiring a second LLDAP group membership per officer.
    const OFFICER_ROLES = ['voorzitter', 'secretaris', 'penningmeester'];
    const ALL_ROLES = ['bestuur', 'voorzitter', 'secretaris', 'penningmeester'];
    const IT_ROLE = 'it_beheerder';
    const ASSIGNABLE_ROLES = ['bestuur', 'voorzitter', 'secretaris', 'penningmeester', self::IT_ROLE];

    /**
     * Pages that the chair may assign to roles. Authentication credentials,
     * identity management, LLDAP groups and delegation are deliberately not
     * listed: those sensitive functions have fixed, narrower authorization.
     */
    public static function get_assignable_pages(): array {
        return apply_filters('avpvh_assignable_role_pages', [
            'members'        => 'Ledenbeheer',
            'activities'     => 'Activiteiten',
            'newsletter'     => 'Nieuwsbrief',
            'plugin_settings' => 'Plugininstellingen (zonder authenticatie)',
        ]);
    }

    public static function get_default_page_permissions(): array {
        return apply_filters('avpvh_default_role_page_permissions', [
            'members'        => ['secretaris', self::IT_ROLE],
            'activities'     => [self::IT_ROLE],
            'newsletter'     => [self::IT_ROLE],
            'plugin_settings' => [self::IT_ROLE],
        ]);
    }

    public static function get_page_permissions(): array {
        $saved = get_option('avpvh_role_page_permissions', []);
        $saved = is_array($saved) ? $saved : [];
        $permissions = self::get_default_page_permissions();
        foreach (self::get_assignable_pages() as $page => $_label) {
            if (!array_key_exists($page, $saved) || !is_array($saved[$page])) {
                continue;
            }
            $permissions[$page] = array_values(array_intersect(
                self::ASSIGNABLE_ROLES,
                array_map('sanitize_key', $saved[$page])
            ));
        }
        return $permissions;
    }

    public static function save_page_permissions(array $submitted): void {
        $permissions = [];
        foreach (self::get_assignable_pages() as $page => $_label) {
            $roles = isset($submitted[$page]) && is_array($submitted[$page])
                ? array_map('sanitize_key', wp_unslash($submitted[$page]))
                : [];
            $permissions[$page] = array_values(array_intersect(self::ASSIGNABLE_ROLES, $roles));
        }
        update_option('avpvh_role_page_permissions', $permissions, false);
    }

    /**
     * Real (LLDAP-derived) roles for a member, with implied 'bestuur'
     * folded in. Does not include delegated roles — use member_has_role()
     * for the effective (real-or-delegated) check.
     */
    public static function get_member_roles(int $member_id): array {
        $member = AVPVH_DB::get_member($member_id);
        if (!$member || empty($member->user_id)) {
            return [];
        }

        $cache_key = 'avpvh_lldap_groups_' . $member->user_id;
        $groups = get_transient($cache_key);
        if ($groups === false) {
            $result = AVPVH_LLDAP::get_user_groups($member->user_id);
            $groups = is_wp_error($result) ? [] : $result;
            set_transient($cache_key, $groups, is_wp_error($result) ? MINUTE_IN_SECONDS : 15 * MINUTE_IN_SECONDS);
        }

        $names = array_map(
            static fn($g) => strtolower($g['displayName'] ?? ''),
            is_array($groups) ? $groups : []
        );

        $roles = array_values(array_intersect(self::ALL_ROLES, $names));
        if (array_intersect($roles, self::OFFICER_ROLES) && !in_array('bestuur', $roles, true)) {
            $roles[] = 'bestuur';
        }
        return $roles;
    }

    public static function member_has_role(int $member_id, string $role): bool {
        $role = strtolower($role);
        if (in_array($role, self::get_member_roles($member_id), true)) {
            return true;
        }
        return self::has_active_delegation($member_id, $role);
    }

    public static function current_user_has_role(string $role): bool {
        if (!is_user_logged_in()) {
            return false;
        }
        $member = avpvh_get_member_by_wp_user(get_current_user_id());
        return $member && self::member_has_role((int) $member->id, $role);
    }

    public static function current_user_can_access_page(string $page): bool {
        if (current_user_can('manage_options')) {
            return true;
        }
        if (!array_key_exists($page, self::get_assignable_pages())) {
            return false;
        }
        foreach (self::get_page_permissions()[$page] ?? [] as $role) {
            if (self::current_user_has_role($role)) {
                return true;
            }
        }
        return false;
    }

    public static function current_user_is_chair(): bool {
        return self::current_user_has_role('voorzitter');
    }

    // -------------------------------------------------------------------
    // Delegations
    // -------------------------------------------------------------------

    public static function has_active_delegation(int $member_id, string $role): bool {
        global $wpdb;
        $now = current_time('mysql');
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}avm_role_delegations
             WHERE role = %s AND delegated_to_member_id = %d
               AND starts_at <= %s AND (ends_at IS NULL OR ends_at >= %s)",
            $role, $member_id, $now, $now
        ));
        return (int) $count > 0;
    }

    /** Active delegations, most recently created first — for the admin screen. */
    public static function get_active_delegations(): array {
        global $wpdb;
        $now = current_time('mysql');
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avm_role_delegations
             WHERE starts_at <= %s AND (ends_at IS NULL OR ends_at >= %s)
             ORDER BY created_at DESC",
            $now, $now
        )) ?: [];
    }

    public static function get_delegation(int $delegation_id): ?object {
        global $wpdb;
        $delegation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avm_role_delegations WHERE id = %d",
            $delegation_id
        ));
        return $delegation ?: null;
    }

    /**
     * Delegate $role to $to_member_id. Fails (returns false) unless
     * $by_member_id genuinely holds $role or is bestuur — a delegation
     * can't be used to grant authority the delegator doesn't have.
     */
    public static function create_delegation(string $role, int $to_member_id, int $by_member_id, ?string $ends_at = null): bool {
        global $wpdb;
        $role = strtolower($role);
        if (!in_array($role, array_merge(self::OFFICER_ROLES, [self::IT_ROLE]), true)) {
            return false;
        }
        if ($role === self::IT_ROLE) {
            return self::member_has_role($by_member_id, 'voorzitter')
                && self::insert_delegation($role, $to_member_id, $by_member_id, $ends_at);
        }
        if (!self::member_has_role($by_member_id, $role) && !self::member_has_role($by_member_id, 'bestuur')) {
            return false;
        }

        return self::insert_delegation($role, $to_member_id, $by_member_id, $ends_at);
    }

    private static function insert_delegation(string $role, int $to_member_id, int $by_member_id, ?string $ends_at): bool {
        global $wpdb;
        return (bool) $wpdb->insert(
            "{$wpdb->prefix}avm_role_delegations",
            [
                'role'                    => $role,
                'delegated_to_member_id'  => $to_member_id,
                'delegated_by_member_id'  => $by_member_id,
                'starts_at'               => current_time('mysql'),
                'ends_at'                 => $ends_at ?: null,
            ],
            ['%s', '%d', '%d', '%s', $ends_at ? '%s' : null]
        );
    }

    public static function revoke_delegation(int $delegation_id): bool {
        global $wpdb;
        return (bool) $wpdb->update(
            "{$wpdb->prefix}avm_role_delegations",
            ['ends_at' => current_time('mysql')],
            ['id' => $delegation_id],
            ['%s'], ['%d']
        );
    }

    /**
     * Every member currently holding $role for real (LLDAP group), for the
     * admin "who holds what" list. Uses AVPVH_LLDAP::get_all_group_memberships()
     * (one round-trip for every group) rather than a per-member lookup.
     */
    public static function get_role_holders(string $role): array {
        $role = strtolower($role);
        $memberships = AVPVH_LLDAP::get_all_group_memberships();
        if (is_wp_error($memberships)) {
            return [];
        }

        $holders = [];
        foreach ($memberships as $lldap_uid => $groups) {
            $names = array_map('strtolower', $groups);
            $has_role = in_array($role, $names, true)
                || ($role === 'bestuur' && array_intersect($names, self::OFFICER_ROLES));
            if (!$has_role) {
                continue;
            }
            $member = AVPVH_DB::get_member_by_lldap_uid($lldap_uid);
            if ($member) {
                $holders[] = $member;
            }
        }
        return $holders;
    }
}
