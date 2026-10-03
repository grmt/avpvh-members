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

    // Officer roles that get the same member-management access as a real WP
    // admin (Ledenbeheer, Ledendetail, Nieuw lid and their save handlers):
    // secretaris as membership registrar, penningmeester for contributie
    // and member records. Either via real LLDAP group membership or a
    // temporary delegation (see admin/roles.php).
    const MEMBER_ADMIN_ROLES = ['secretaris', 'penningmeester'];

    public static function can_manage_members(): bool {
        return self::is_admin_or_has_any_role(self::MEMBER_ADMIN_ROLES);
    }

    // Activiteiten (activities, activity types, participation, export) is
    // narrower than member admin: secretaris only, not penningmeester.
    const ACTIVITY_ADMIN_ROLES = ['secretaris'];

    public static function can_manage_activities(): bool {
        return self::is_admin_or_has_any_role(self::ACTIVITY_ADMIN_ROLES);
    }

    // Nieuwsbrief (compose and send to members who opted in): secretaris.
    const NEWSLETTER_ROLES = ['secretaris'];

    public static function can_send_newsletter(): bool {
        return self::is_admin_or_has_any_role(self::NEWSLETTER_ROLES);
    }

    // Rollen & delegatie: voorzitter only. A delegation hands out real
    // officer rights (secretaris = ledenbeheer, activiteiten, nieuwsbrief),
    // so any bestuur member being able to delegate would let them grant
    // those to themselves.
    const ROLE_ADMIN_ROLES = ['voorzitter'];

    public static function can_manage_roles(): bool {
        return self::is_admin_or_has_any_role(self::ROLE_ADMIN_ROLES);
    }

    // Rollen & delegatie is visible to every real rolhouder too, so a
    // secretaris or penningmeester can step down there themselves; what
    // each viewer may change is gated per section/handler.
    public static function can_view_roles_page(): bool {
        if (self::can_manage_roles()) {
            return true;
        }
        $member = is_user_logged_in() ? avpvh_get_member_by_wp_user(get_current_user_id()) : null;
        return $member && array_intersect(self::OFFICER_ROLES, self::get_member_roles((int) $member->id));
    }

    // The penningmeester may stand in as secretaris themselves, but only
    // briefly: a self-delegation of at most this many hours, end required.
    // (Delegating to anyone else stays with the voorzitter.)
    const SELF_DELEGATION_MAX_HOURS = 48;

    public static function can_self_delegate_secretaris(): bool {
        $member = is_user_logged_in() ? avpvh_get_member_by_wp_user(get_current_user_id()) : null;
        return $member && in_array('penningmeester', self::get_member_roles((int) $member->id), true);
    }

    // A rolhouder may lay down their own (real, LLDAP) role; the voorzitter
    // or a WP admin may do it for any rolhouder.
    public static function can_step_down(string $role, int $holder_id): bool {
        if (self::can_appoint_officers()) {
            return true;
        }
        $member = is_user_logged_in() ? avpvh_get_member_by_wp_user(get_current_user_id()) : null;
        return $member && (int) $member->id === $holder_id
            && in_array($role, self::get_member_roles($holder_id), true);
    }

    // Real WP admins always qualify; otherwise any one of the given club
    // roles, via LLDAP group membership or an active delegation.
    private static function is_admin_or_has_any_role(array $roles): bool {
        if (current_user_can('manage_options')) {
            return true;
        }
        foreach ($roles as $role) {
            if (self::current_user_has_role($role)) {
                return true;
            }
        }
        return false;
    }

    public static function current_user_has_role(string $role): bool {
        if (!is_user_logged_in()) {
            return false;
        }
        $member = avpvh_get_member_by_wp_user(get_current_user_id());
        return $member && self::member_has_role((int) $member->id, $role);
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
    /**
     * What a member does in the bestuur, as display lines: their real
     * bestuursfunctie(s) or "Bestuurslid", plus active delegations
     * ("Secretaris (tijdelijk, tot 12 okt 2026)", "Secretaris (gedelegeerd)"
     * without end date) and an IT-beheerder appointment (stored as an
     * it_beheerder delegation by the voorzitter). Empty for an ordinary lid.
     */
    public static function describe_member_roles(int $member_id): array {
        $labels = ['voorzitter' => 'Voorzitter', 'secretaris' => 'Secretaris', 'penningmeester' => 'Penningmeester'];
        $real   = self::get_member_roles($member_id);
        $lines  = [];
        foreach ($labels as $role => $label) {
            if (in_array($role, $real, true)) {
                $lines[] = $label;
            }
        }
        if (!$lines && in_array('bestuur', $real, true)) {
            $lines[] = 'Bestuurslid';
        }
        foreach (self::get_active_delegations() as $d) {
            if ((int) $d->delegated_to_member_id !== $member_id) {
                continue;
            }
            if ($d->role === 'it_beheerder') {
                $lines[] = 'IT-beheerder';
            } elseif (isset($labels[$d->role])) {
                $lines[] = $labels[$d->role] . ($d->ends_at
                    ? ' (tijdelijk, tot ' . wp_date('j M Y', strtotime($d->ends_at)) . ')'
                    : ' (gedelegeerd)');
            }
        }
        return $lines;
    }

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

    /** Most recently ended delegations (expired or revoked), newest first. */
    public static function get_expired_delegations(int $limit = 10): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avm_role_delegations
             WHERE ends_at IS NOT NULL AND ends_at < %s
             ORDER BY ends_at DESC LIMIT %d",
            current_time('mysql'), $limit
        )) ?: [];
    }

    /**
     * Delegate $role to $to_member_id. Fails (returns false) unless
     * $by_member_id genuinely holds $role or is bestuur — a delegation
     * can't be used to grant authority the delegator doesn't have — or
     * $by_admin is set (a WP admin, who may delegate any officer role and
     * may have no member record at all: $by_member_id is then 0).
     */
    public static function create_delegation(string $role, int $to_member_id, int $by_member_id, ?string $ends_at = null, bool $by_admin = false): bool {
        global $wpdb;
        $role = strtolower($role);
        if (!in_array($role, self::OFFICER_ROLES, true)) {
            return false;
        }
        if (!$by_admin && !self::member_has_role($by_member_id, $role) && !self::member_has_role($by_member_id, 'bestuur')) {
            return false;
        }

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
     * Only a real voorzitter (LLDAP group, not a delegation) or a WP admin
     * may appoint a new voorzitter, secretaris or penningmeester —
     * otherwise a temporary stand-in could make their own role permanent.
     */
    public static function can_appoint_officers(): bool {
        if (current_user_can('manage_options')) {
            return true;
        }
        $member = is_user_logged_in() ? avpvh_get_member_by_wp_user(get_current_user_id()) : null;
        return $member && in_array('voorzitter', self::get_member_roles((int) $member->id), true);
    }

    /**
     * Who can be appointed to an officer role: active members with a real
     * login. Placeholder @avpvh.local accounts (minors, see
     * AVPVH_Admin::handle_add_member()) can't log in, so are left out.
     */
    public static function get_officer_candidates(): array {
        return array_values(array_filter(
            AVPVH_DB::get_members(['status' => 'active']),
            static fn($m) => !empty($m->lldap_user_id)
                && !str_ends_with(strtolower((string) ($m->email ?? '')), '@avpvh.local')
        ));
    }

    /**
     * Add or remove a bestuurslid (LLDAP group "bestuur"). Only members who
     * qualify via get_officer_candidates() — active, with a real login —
     * can be added; anyone can be removed. Officers stay bestuur by
     * implication even when not in the group themselves.
     */
    public static function set_bestuur_member(int $member_id, bool $add): true|\WP_Error {
        $member = AVPVH_DB::get_member($member_id);
        if (!$member || empty($member->lldap_user_id)) {
            return new \WP_Error('avpvh_no_member', 'Lid niet gevonden.');
        }
        if ($add && !in_array($member_id, array_map(static fn($m) => (int) $m->id, self::get_officer_candidates()), true)) {
            return new \WP_Error('avpvh_not_eligible', 'Alleen actieve leden met een eigen login kunnen bestuurslid worden.');
        }

        $group_id = self::lldap_group_id('bestuur');
        if (is_wp_error($group_id)) {
            return $group_id;
        }
        $result = $add
            ? AVPVH_LLDAP::add_to_group($member->lldap_user_id, $group_id)
            : AVPVH_LLDAP::remove_from_group($member->lldap_user_id, $group_id);
        if (is_wp_error($result)) {
            return $result;
        }

        delete_transient('avpvh_lldap_groups_' . $member->lldap_user_id);
        delete_transient('avpvh_all_group_memberships');
        if (!$add) {
            self::mark_oud_bestuurder_if_left($member_id);
        }
        return true;
    }

    /**
     * Whoever leaves the bestuur keeps a lasting record of it as the
     * "oud-bestuurder" kenmerk. Called after a group change; does nothing
     * while the member still counts as bestuur (directly, or through an
     * officer role). The kenmerk is created on first use.
     */
    private static function mark_oud_bestuurder_if_left(int $member_id): void {
        if (in_array('bestuur', self::get_member_roles($member_id), true)) {
            return;
        }
        if (!AVPVH_DB::flag_exists('oud-bestuurder')) {
            AVPVH_DB::create_flag('oud-bestuurder', 'Oud-bestuurder');
        }
        AVPVH_DB::set_member_flag_by_slug($member_id, 'oud-bestuurder', true);
    }

    private static function lldap_group_id(string $name): int|\WP_Error {
        $groups = AVPVH_LLDAP::list_groups();
        if (is_wp_error($groups)) {
            return $groups;
        }
        foreach ($groups as $group) {
            if (strtolower($group['displayName']) === $name) {
                return (int) $group['id'];
            }
        }
        return new \WP_Error('avpvh_no_group', "Groep \"{$name}\" niet gevonden.");
    }

    /**
     * Lay down $role (voorzitter, secretaris or penningmeester): $member_id
     * leaves that LLDAP group but stays an ordinary bestuurslid (added to
     * "bestuur" first, so they're never briefly outside the bestuur). The
     * role stays vacant until someone is appointed.
     */
    public static function step_down(string $role, int $member_id): true|\WP_Error {
        $role = strtolower($role);
        $member = AVPVH_DB::get_member($member_id);
        if (!in_array($role, self::OFFICER_ROLES, true) || !$member || empty($member->lldap_user_id)) {
            return new \WP_Error('avpvh_bad_input', 'Onbekende rol of lid.');
        }
        if (!in_array($role, self::get_member_roles($member_id), true)) {
            return new \WP_Error('avpvh_not_holder', 'Dit lid heeft deze rol niet.');
        }
        $role_group    = self::lldap_group_id($role);
        $bestuur_group = self::lldap_group_id('bestuur');
        if (is_wp_error($role_group)) {
            return $role_group;
        }
        if (is_wp_error($bestuur_group)) {
            return $bestuur_group;
        }
        $added = AVPVH_LLDAP::add_to_group($member->lldap_user_id, $bestuur_group);
        if (is_wp_error($added)) {
            return $added;
        }
        $removed = AVPVH_LLDAP::remove_from_group($member->lldap_user_id, $role_group);
        delete_transient('avpvh_lldap_groups_' . $member->lldap_user_id);
        delete_transient('avpvh_all_group_memberships');
        return is_wp_error($removed) ? $removed : true;
    }

    /**
     * Take $member_id out of the bestuur entirely — the "bestuur" group and
     * every officer group they're in — and end delegations to them. Used
     * when a member is geroyeerd (or gets any other kenmerk that makes them
     * inactive): a bestuurder can lose their place that way too.
     * Returns whether anything was removed.
     */
    public static function strip_bestuur_roles(int $member_id): bool {
        global $wpdb;
        $member = AVPVH_DB::get_member($member_id);
        if (!$member || empty($member->lldap_user_id)) {
            return false;
        }
        $groups = AVPVH_LLDAP::get_user_groups($member->lldap_user_id);
        if (is_wp_error($groups)) {
            error_log("AVPVH_Roles: could not read LLDAP groups of member {$member_id}: " . $groups->get_error_message());
            return false;
        }

        $changed = false;
        foreach ($groups as $group) {
            if (!in_array(strtolower((string) $group['displayName']), self::ALL_ROLES, true)) {
                continue;
            }
            $removed = AVPVH_LLDAP::remove_from_group($member->lldap_user_id, (int) $group['id']);
            if (is_wp_error($removed)) {
                error_log("AVPVH_Roles: could not remove member {$member_id} from {$group['displayName']}: " . $removed->get_error_message());
                continue;
            }
            $changed = true;
        }

        $now = current_time('mysql');
        $ended = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}avm_role_delegations SET ends_at = %s
             WHERE delegated_to_member_id = %d AND (ends_at IS NULL OR ends_at > %s)",
            $now, $member_id, $now
        ));

        delete_transient('avpvh_lldap_groups_' . $member->lldap_user_id);
        delete_transient('avpvh_all_group_memberships');
        if ($changed) {
            self::mark_oud_bestuurder_if_left($member_id);
        }
        return $changed || $ended > 0;
    }

    /**
     * Appoint $to_member_id as the new $role (voorzitter, secretaris or
     * penningmeester): adds them to that LLDAP group, removes every other
     * current holder and ends active delegations of the role, so exactly
     * one person holds it afterwards. Adding happens first, so a failure
     * never leaves the role empty.
     */
    public static function appoint_officer(string $role, int $to_member_id): true|\WP_Error {
        global $wpdb;
        $role = strtolower($role);
        if (!in_array($role, self::OFFICER_ROLES, true)) {
            return new \WP_Error('avpvh_bad_role', 'Onbekende rol.');
        }
        $to = AVPVH_DB::get_member($to_member_id);
        if (!$to || empty($to->lldap_user_id)) {
            return new \WP_Error('avpvh_no_member', 'Lid niet gevonden.');
        }

        $group_id = self::lldap_group_id($role);
        if (is_wp_error($group_id)) {
            return $group_id;
        }

        $previous = self::get_role_holders($role);
        $added = AVPVH_LLDAP::add_to_group($to->lldap_user_id, $group_id);
        if (is_wp_error($added)) {
            return $added;
        }

        $affected = [$to->lldap_user_id];
        $replaced = [];
        foreach ($previous as $holder) {
            if ((int) $holder->id === $to_member_id) {
                continue;
            }
            $removed = AVPVH_LLDAP::remove_from_group($holder->lldap_user_id, $group_id);
            if (is_wp_error($removed)) {
                error_log("AVPVH_Roles: {$role} appointment could not remove member {$holder->id}: " . $removed->get_error_message());
            }
            $affected[] = $holder->lldap_user_id;
            $replaced[] = (int) $holder->id;
        }

        $now = current_time('mysql');
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}avm_role_delegations SET ends_at = %s
             WHERE role = %s AND (ends_at IS NULL OR ends_at > %s)",
            $now, $role, $now
        ));

        foreach ($affected as $uid) {
            delete_transient('avpvh_lldap_groups_' . $uid);
        }
        delete_transient('avpvh_all_group_memberships');
        foreach ($replaced as $member_id) {
            self::mark_oud_bestuurder_if_left($member_id);
        }
        return true;
    }

    /**
     * Every member currently holding $role for real (LLDAP group), for the
     * admin "who holds what" list. Uses AVPVH_LLDAP::get_all_group_memberships()
     * (one round-trip for every group) rather than a per-member lookup.
     */
    public static function get_role_holders(string $role, bool $include_implied = true): array {
        $role = strtolower($role);
        $memberships = AVPVH_LLDAP::get_all_group_memberships();
        if (is_wp_error($memberships)) {
            return [];
        }

        $holders = [];
        foreach ($memberships as $lldap_uid => $groups) {
            $names = array_map('strtolower', $groups);
            $has_role = in_array($role, $names, true)
                || ($include_implied && $role === 'bestuur' && array_intersect($names, self::OFFICER_ROLES));
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
