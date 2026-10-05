<?php
defined('ABSPATH') || exit;

/**
 * Identity directory (accounts + groups), independent of whether it lives in
 * LLDAP or OpenLDAP. Everything in the plugin goes through this class; the
 * backend is chosen with the AVPVH_DIRECTORY_BACKEND constant ('lldap', the
 * default for now, or 'openldap') — see PLAN-openldap.md.
 *
 * Groups are identified by name (cn), never by LLDAP's numeric ids. Users are
 * plain arrays: ['uid' => ..., 'mail' => ..., 'display_name' => ...].
 *
 * With the OpenLDAP backend every successful account write is mirrored into
 * the local cache table straight away (AVPVH_Directory_Cache), so member
 * lists and searches stay plain SQL.
 */
final class AVPVH_Directory {

    // The directory may hold more than avpvh's groups (LLDAP is shared by
    // every tenant; OpenLDAP is scoped by ACL but keep the same guard). Only
    // these are PvH's to show and hand out from this plugin.
    public const PVH_GROUPS = [
        'leden', 'ex-leden', 'ere-leden', 'bestuur', 'voorzitter',
        'secretaris', 'penningmeester', 'boek', 'bloggers',
    ];

    private static ?AVPVH_Directory_Backend $backend = null;

    public static function backend_name(): string {
        $name = defined('AVPVH_DIRECTORY_BACKEND') ? strtolower((string) AVPVH_DIRECTORY_BACKEND) : 'openldap';
        return $name === 'lldap' ? 'lldap' : 'openldap';
    }

    public static function is_openldap(): bool {
        return self::backend_name() === 'openldap';
    }

    public static function backend(): AVPVH_Directory_Backend {
        if (self::$backend === null) {
            self::$backend = self::is_openldap() ? new AVPVH_Directory_OpenLDAP() : new AVPVH_Directory_LLDAP();
        }
        return self::$backend;
    }

    /** For tests: inject a backend. */
    public static function set_backend(?AVPVH_Directory_Backend $backend): void {
        self::$backend = $backend;
    }

    public static function is_pvh_group(string $name): bool {
        $allowed = (array) apply_filters('avpvh_pvh_groups', self::PVH_GROUPS);
        return in_array(strtolower($name), $allowed, true);
    }

    /** @param string[] $names */
    public static function only_pvh_groups(array $names): array {
        return array_values(array_filter($names, static fn($n) => self::is_pvh_group((string) $n)));
    }

    public static function get_user(string $uid): ?array {
        return self::backend()->get_user($uid);
    }

    public static function user_exists(string $uid): bool {
        return self::get_user($uid) !== null;
    }

    public static function create_user(string $uid, string $mail, string $display_name): true|\WP_Error {
        $result = self::backend()->create_user($uid, $mail, $display_name);
        if ($result === true && self::is_openldap()) {
            AVPVH_Directory_Cache::upsert(['uid' => $uid, 'mail' => $mail, 'display_name' => $display_name]);
        }
        return $result;
    }

    /** @param array{mail?: string, display_name?: string} $fields */
    public static function update_user(string $uid, array $fields): true|\WP_Error {
        $fields = array_intersect_key($fields, ['mail' => 1, 'display_name' => 1]);
        if (!$fields) {
            return true;
        }
        $result = self::backend()->update_user($uid, $fields);
        if ($result === true && self::is_openldap()) {
            AVPVH_Directory_Cache::refresh_user($uid);
        }
        return $result;
    }

    public static function delete_user(string $uid): true|\WP_Error {
        $result = self::backend()->delete_user($uid);
        if ($result === true && self::is_openldap()) {
            AVPVH_Directory_Cache::delete($uid);
        }
        return $result;
    }

    /** @return string[]|\WP_Error group names */
    public static function list_groups(): array|\WP_Error {
        return self::backend()->list_groups();
    }

    /** @return string[]|\WP_Error group names */
    public static function get_user_groups(string $uid): array|\WP_Error {
        return self::backend()->get_user_groups($uid);
    }

    /** @return array<string, string[]>|\WP_Error uid => group names */
    public static function get_all_group_memberships(): array|\WP_Error {
        return self::backend()->get_all_group_memberships();
    }

    public static function add_to_group(string $uid, string $group): true|\WP_Error {
        return self::backend()->add_to_group($uid, strtolower($group));
    }

    public static function remove_from_group(string $uid, string $group): true|\WP_Error {
        return self::backend()->remove_from_group($uid, strtolower($group));
    }

    /** @return array<int, array{uid: string, mail: string, display_name: string}>|\WP_Error */
    public static function list_users(): array|\WP_Error {
        return self::backend()->list_users();
    }

    public static function test_connection(): true|\WP_Error {
        return self::backend()->test_connection();
    }

    /** Clears the per-user and all-members group caches after a group change. */
    public static function forget_groups(string ...$uids): void {
        foreach ($uids as $uid) {
            delete_transient('avpvh_dir_groups_' . strtolower($uid));
        }
        delete_transient('avpvh_all_group_memberships');
    }

    /** Cached (15 min) group names of one user; [] on error. */
    public static function cached_user_groups(string $uid): array {
        $key = 'avpvh_dir_groups_' . strtolower($uid);
        $groups = get_transient($key);
        if (!is_array($groups)) {
            $result = self::get_user_groups($uid);
            $groups = is_wp_error($result) ? [] : $result;
            set_transient($key, $groups, is_wp_error($result) ? MINUTE_IN_SECONDS : 15 * MINUTE_IN_SECONDS);
        }
        return $groups;
    }

    /** Cached (15 min) uid => group names for everyone; [] on error. */
    public static function cached_all_group_memberships(): array {
        $map = get_transient('avpvh_all_group_memberships');
        if (!is_array($map)) {
            $result = self::get_all_group_memberships();
            $map = is_wp_error($result) ? [] : $result;
            set_transient('avpvh_all_group_memberships', $map, is_wp_error($result) ? MINUTE_IN_SECONDS : 15 * MINUTE_IN_SECONDS);
        }
        return $map;
    }
}

interface AVPVH_Directory_Backend {
    public function get_user(string $uid): ?array;
    public function create_user(string $uid, string $mail, string $display_name): true|\WP_Error;
    public function update_user(string $uid, array $fields): true|\WP_Error;
    public function delete_user(string $uid): true|\WP_Error;
    public function list_groups(): array|\WP_Error;
    public function get_user_groups(string $uid): array|\WP_Error;
    public function get_all_group_memberships(): array|\WP_Error;
    public function add_to_group(string $uid, string $group): true|\WP_Error;
    public function remove_from_group(string $uid, string $group): true|\WP_Error;
    public function list_users(): array|\WP_Error;
    public function test_connection(): true|\WP_Error;
}

/**
 * LLDAP backend: a thin adapter around the existing AVPVH_LLDAP GraphQL
 * client, translating group names to LLDAP's numeric ids. Goes away with
 * LLDAP itself (phase 4 of PLAN-openldap.md).
 */
final class AVPVH_Directory_LLDAP implements AVPVH_Directory_Backend {

    /** @var array<string, int>|null lowercase name => id, per request */
    private ?array $group_ids = null;

    private function group_id(string $group): int|\WP_Error {
        if ($this->group_ids === null) {
            $groups = AVPVH_LLDAP::list_groups();
            if (is_wp_error($groups)) {
                return $groups;
            }
            $this->group_ids = [];
            foreach ($groups as $g) {
                $this->group_ids[strtolower((string) $g['displayName'])] = (int) $g['id'];
            }
        }
        return $this->group_ids[strtolower($group)] ?? new \WP_Error('avpvh_no_group', "Groep \"{$group}\" niet gevonden.");
    }

    private static function ok(array|\WP_Error $result): true|\WP_Error {
        return is_wp_error($result) ? $result : true;
    }

    public function get_user(string $uid): ?array {
        $user = AVPVH_LLDAP::get_user($uid);
        return $user ? ['uid' => $user['id'], 'mail' => (string) ($user['email'] ?? ''), 'display_name' => (string) ($user['displayName'] ?? '')] : null;
    }

    public function create_user(string $uid, string $mail, string $display_name): true|\WP_Error {
        return self::ok(AVPVH_LLDAP::create_user($uid, $mail, $display_name));
    }

    public function update_user(string $uid, array $fields): true|\WP_Error {
        $mapped = [];
        if (array_key_exists('mail', $fields)) {
            $mapped['email'] = $fields['mail'];
        }
        if (array_key_exists('display_name', $fields)) {
            $mapped['displayName'] = $fields['display_name'];
        }
        return self::ok(AVPVH_LLDAP::update_user($uid, $mapped));
    }

    public function delete_user(string $uid): true|\WP_Error {
        return self::ok(AVPVH_LLDAP::delete_user($uid));
    }

    public function list_groups(): array|\WP_Error {
        $groups = AVPVH_LLDAP::list_groups();
        return is_wp_error($groups) ? $groups : array_values(array_map(static fn($g) => strtolower((string) $g['displayName']), $groups));
    }

    public function get_user_groups(string $uid): array|\WP_Error {
        $groups = AVPVH_LLDAP::get_user_groups($uid);
        return is_wp_error($groups) ? $groups : array_values(array_map(static fn($g) => strtolower((string) $g['displayName']), $groups));
    }

    public function get_all_group_memberships(): array|\WP_Error {
        $map = AVPVH_LLDAP::get_all_group_memberships();
        if (is_wp_error($map)) {
            return $map;
        }
        $out = [];
        foreach ($map as $uid => $names) {
            $out[strtolower((string) $uid)] = array_map('strtolower', $names);
        }
        return $out;
    }

    public function add_to_group(string $uid, string $group): true|\WP_Error {
        $id = $this->group_id($group);
        return is_wp_error($id) ? $id : self::ok(AVPVH_LLDAP::add_to_group($uid, $id));
    }

    public function remove_from_group(string $uid, string $group): true|\WP_Error {
        $id = $this->group_id($group);
        return is_wp_error($id) ? $id : self::ok(AVPVH_LLDAP::remove_from_group($uid, $id));
    }

    public function list_users(): array|\WP_Error {
        $users = AVPVH_LLDAP::list_users();
        if (is_wp_error($users)) {
            return $users;
        }
        return array_map(static fn($u) => ['uid' => $u['id'], 'mail' => (string) ($u['email'] ?? ''), 'display_name' => (string) ($u['displayName'] ?? '')], $users);
    }

    public function test_connection(): true|\WP_Error {
        $groups = AVPVH_LLDAP::list_groups();
        return is_wp_error($groups) ? $groups : true;
    }
}

/**
 * OpenLDAP backend (php-ldap). Binds as cn=avpvh-admin,dc=nl, which may
 * manage ou=avpvh,dc=nl and nothing else (docker-scripts
 * openldap-avpvh-admin.sh). Configuration, all optional:
 *   AVPVH_LDAP_URL            ldap://openldap:1389
 *   AVPVH_LDAP_BIND_DN        cn=avpvh-admin,dc=nl
 *   AVPVH_LDAP_PASSWORD_FILE  /run/secrets/openldap_avpvh_admin_password
 *   AVPVH_LDAP_BASE           ou=avpvh,dc=nl
 *
 * Groups are groupOfNames under ou=groups (member is mandatory), people are
 * inetOrgPerson under ou=people. A group that would become empty keeps the
 * placeholder cn=vacant,<base> as its only member instead.
 */
final class AVPVH_Directory_OpenLDAP implements AVPVH_Directory_Backend {

    private const NO_SUCH_OBJECT   = 32;
    private const NO_SUCH_ATTRIBUTE = 16;
    private const ALREADY_EXISTS   = 68;
    private const VALUE_EXISTS     = 20;

    /** @var \LDAP\Connection|null */
    private $conn = null;

    private static function conf(string $name, string $default): string {
        return defined($name) ? (string) constant($name) : $default;
    }

    private function base(): string {
        return self::conf('AVPVH_LDAP_BASE', 'ou=avpvh,dc=nl');
    }

    private function people_dn(): string {
        return 'ou=people,' . $this->base();
    }

    private function groups_dn(): string {
        return 'ou=groups,' . $this->base();
    }

    private function vacant_dn(): string {
        return 'cn=vacant,' . $this->base();
    }

    private function user_dn(string $uid): string {
        return 'uid=' . ldap_escape(strtolower($uid), '', LDAP_ESCAPE_DN) . ',' . $this->people_dn();
    }

    private function group_dn(string $group): string {
        return 'cn=' . ldap_escape(strtolower($group), '', LDAP_ESCAPE_DN) . ',' . $this->groups_dn();
    }

    /** uid from a member DN under ou=people of our base, or null (placeholder, other subtree). */
    private function uid_from_dn(string $dn): ?string {
        $suffix = ',' . strtolower($this->people_dn());
        $dn = strtolower(trim($dn));
        if (!str_starts_with($dn, 'uid=') || !str_ends_with($dn, $suffix)) {
            return null;
        }
        $rdn_value = substr($dn, 4, -strlen($suffix));
        return str_contains($rdn_value, ',') ? null : $rdn_value;
    }

    private function error(string $what): \WP_Error {
        $code = $this->conn ? ldap_errno($this->conn) : -1;
        $msg  = $this->conn ? ldap_error($this->conn) : 'geen verbinding';
        return new \WP_Error('avpvh_ldap', "{$what}: {$msg}", ['ldap_errno' => $code]);
    }

    /** @return \LDAP\Connection|\WP_Error */
    private function connection() {
        if ($this->conn) {
            return $this->conn;
        }
        if (!function_exists('ldap_connect')) {
            return new \WP_Error('avpvh_ldap', 'De PHP ldap-extensie ontbreekt.');
        }
        $file = self::conf('AVPVH_LDAP_PASSWORD_FILE', '/run/secrets/openldap_avpvh_admin_password');
        $password = is_readable($file) ? trim((string) file_get_contents($file)) : '';
        if ($password === '') {
            return new \WP_Error('avpvh_ldap', 'Wachtwoord voor de directory-koppeling ontbreekt.');
        }
        $conn = ldap_connect(self::conf('AVPVH_LDAP_URL', 'ldap://openldap:1389'));
        if (!$conn) {
            return new \WP_Error('avpvh_ldap', 'Kan geen verbinding maken met de directory.');
        }
        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 5);
        $this->conn = $conn;
        if (!@ldap_bind($conn, self::conf('AVPVH_LDAP_BIND_DN', 'cn=avpvh-admin,dc=nl'), $password)) {
            $err = $this->error('Aanmelden bij de directory mislukt');
            $this->conn = null;
            return $err;
        }
        return $conn;
    }

    /**
     * One-level search with paging (OpenLDAP's default size limit is 500,
     * close to the number of members).
     * @return array<int, array<string, mixed>>|\WP_Error ldap_get_entries-style entries
     */
    private function search(string $base, string $filter, array $attrs): array|\WP_Error {
        $conn = $this->connection();
        if (is_wp_error($conn)) {
            return $conn;
        }
        $entries = [];
        $cookie  = '';
        do {
            $controls = [['oid' => LDAP_CONTROL_PAGEDRESULTS, 'value' => ['size' => 200, 'cookie' => $cookie]]];
            $result = @ldap_search($conn, $base, $filter, $attrs, 0, -1, -1, LDAP_DEREF_NEVER, $controls);
            if ($result === false) {
                if (ldap_errno($conn) === self::NO_SUCH_OBJECT) {
                    return [];
                }
                return $this->error('Zoeken in de directory mislukt');
            }
            $errcode = $dn = $errmsg = $refs = null;
            $resp_controls = [];
            ldap_parse_result($conn, $result, $errcode, $dn, $errmsg, $refs, $resp_controls);
            $page = ldap_get_entries($conn, $result);
            for ($i = 0; $i < ($page['count'] ?? 0); $i++) {
                $entries[] = $page[$i];
            }
            $cookie = $resp_controls[LDAP_CONTROL_PAGEDRESULTS]['value']['cookie'] ?? '';
        } while ($cookie !== '' && $cookie !== null);
        return $entries;
    }

    private static function first(array $entry, string $attr): string {
        $attr = strtolower($attr);
        return isset($entry[$attr][0]) ? (string) $entry[$attr][0] : '';
    }

    private static function user_from_entry(array $entry): array {
        $display = self::first($entry, 'displayName') ?: self::first($entry, 'cn');
        return ['uid' => strtolower(self::first($entry, 'uid')), 'mail' => self::first($entry, 'mail'), 'display_name' => $display];
    }

    public function get_user(string $uid): ?array {
        $conn = $this->connection();
        if (is_wp_error($conn)) {
            return null;
        }
        $result = @ldap_read($conn, $this->user_dn($uid), '(objectClass=inetOrgPerson)', ['uid', 'mail', 'displayName', 'cn']);
        if ($result === false) {
            return null;
        }
        $entries = ldap_get_entries($conn, $result);
        return ($entries['count'] ?? 0) > 0 ? self::user_from_entry($entries[0]) : null;
    }

    public function create_user(string $uid, string $mail, string $display_name): true|\WP_Error {
        $conn = $this->connection();
        if (is_wp_error($conn)) {
            return $conn;
        }
        $display_name = trim($display_name) !== '' ? trim($display_name) : $uid;
        $parts = preg_split('/\s+/', $display_name);
        $entry = [
            'objectClass' => ['inetOrgPerson'],
            'uid'         => strtolower($uid),
            'cn'          => $display_name,
            'sn'          => (string) end($parts),
            'displayName' => $display_name,
        ];
        if ($mail !== '') {
            $entry['mail'] = $mail;
        }
        if (!@ldap_add($conn, $this->user_dn($uid), $entry)) {
            return $this->error(ldap_errno($conn) === self::ALREADY_EXISTS ? 'Account bestaat al' : 'Account aanmaken mislukt');
        }
        return true;
    }

    public function update_user(string $uid, array $fields): true|\WP_Error {
        $conn = $this->connection();
        if (is_wp_error($conn)) {
            return $conn;
        }
        $mods = [];
        if (array_key_exists('display_name', $fields) && trim((string) $fields['display_name']) !== '') {
            $mods['displayName'] = trim((string) $fields['display_name']);
            $mods['cn'] = $mods['displayName'];
        }
        if (array_key_exists('mail', $fields)) {
            if ((string) $fields['mail'] === '') {
                $user = $this->get_user($uid);
                if ($user && $user['mail'] !== '' && !@ldap_mod_del($conn, $this->user_dn($uid), ['mail' => []])) {
                    return $this->error('E-mailadres wissen mislukt');
                }
            } else {
                $mods['mail'] = (string) $fields['mail'];
            }
        }
        if ($mods && !@ldap_mod_replace($conn, $this->user_dn($uid), $mods)) {
            return $this->error('Account bijwerken mislukt');
        }
        return true;
    }

    public function delete_user(string $uid): true|\WP_Error {
        $groups = $this->get_user_groups($uid);
        if (is_wp_error($groups)) {
            return $groups;
        }
        foreach ($groups as $group) {
            $removed = $this->remove_from_group($uid, $group);
            if (is_wp_error($removed)) {
                return $removed;
            }
        }
        $conn = $this->connection();
        if (is_wp_error($conn)) {
            return $conn;
        }
        if (!@ldap_delete($conn, $this->user_dn($uid)) && ldap_errno($conn) !== self::NO_SUCH_OBJECT) {
            return $this->error('Account verwijderen mislukt');
        }
        return true;
    }

    public function list_groups(): array|\WP_Error {
        $entries = $this->search($this->groups_dn(), '(objectClass=groupOfNames)', ['cn']);
        if (is_wp_error($entries)) {
            return $entries;
        }
        $names = array_map(static fn($e) => strtolower(self::first($e, 'cn')), $entries);
        sort($names);
        return $names;
    }

    public function get_user_groups(string $uid): array|\WP_Error {
        $filter = '(&(objectClass=groupOfNames)(member=' . ldap_escape($this->user_dn($uid), '', LDAP_ESCAPE_FILTER) . '))';
        $entries = $this->search($this->groups_dn(), $filter, ['cn']);
        if (is_wp_error($entries)) {
            return $entries;
        }
        $names = array_map(static fn($e) => strtolower(self::first($e, 'cn')), $entries);
        sort($names);
        return $names;
    }

    public function get_all_group_memberships(): array|\WP_Error {
        $entries = $this->search($this->groups_dn(), '(objectClass=groupOfNames)', ['cn', 'member']);
        if (is_wp_error($entries)) {
            return $entries;
        }
        $map = [];
        foreach ($entries as $entry) {
            $group = strtolower(self::first($entry, 'cn'));
            for ($i = 0; $i < ($entry['member']['count'] ?? 0); $i++) {
                $uid = $this->uid_from_dn((string) $entry['member'][$i]);
                if ($uid !== null) {
                    $map[$uid][] = $group;
                }
            }
        }
        ksort($map);
        return $map;
    }

    /** @return string[]|\WP_Error lowercase member DNs */
    private function group_members(string $group): array|\WP_Error {
        $conn = $this->connection();
        if (is_wp_error($conn)) {
            return $conn;
        }
        $result = @ldap_read($conn, $this->group_dn($group), '(objectClass=groupOfNames)', ['member']);
        if ($result === false) {
            return ldap_errno($conn) === self::NO_SUCH_OBJECT
                ? new \WP_Error('avpvh_no_group', "Groep \"{$group}\" niet gevonden.")
                : $this->error('Groep lezen mislukt');
        }
        $entries = ldap_get_entries($conn, $result);
        $members = [];
        for ($i = 0; $i < ($entries[0]['member']['count'] ?? 0); $i++) {
            $members[] = strtolower((string) $entries[0]['member'][$i]);
        }
        return $members;
    }

    public function add_to_group(string $uid, string $group): true|\WP_Error {
        $members = $this->group_members($group);
        if (is_wp_error($members)) {
            return $members;
        }
        $conn    = $this->connection();
        $user_dn = strtolower($this->user_dn($uid));
        $vacant  = strtolower($this->vacant_dn());
        $batch   = [];
        if (!in_array($user_dn, $members, true)) {
            $batch[] = ['attrib' => 'member', 'modtype' => LDAP_MODIFY_BATCH_ADD, 'values' => [$this->user_dn($uid)]];
        }
        if (in_array($vacant, $members, true)) {
            $batch[] = ['attrib' => 'member', 'modtype' => LDAP_MODIFY_BATCH_REMOVE, 'values' => [$this->vacant_dn()]];
        }
        if ($batch && !@ldap_modify_batch($conn, $this->group_dn($group), $batch) && ldap_errno($conn) !== self::VALUE_EXISTS) {
            return $this->error('Toevoegen aan groep mislukt');
        }
        return true;
    }

    public function remove_from_group(string $uid, string $group): true|\WP_Error {
        $members = $this->group_members($group);
        if (is_wp_error($members)) {
            return $members;
        }
        $user_dn = strtolower($this->user_dn($uid));
        if (!in_array($user_dn, $members, true)) {
            return true;
        }
        $conn  = $this->connection();
        $batch = [];
        // groupOfNames needs at least one member: the last one out leaves
        // the placeholder behind (vacant bestuursfunctie).
        if (count($members) === 1) {
            $batch[] = ['attrib' => 'member', 'modtype' => LDAP_MODIFY_BATCH_ADD, 'values' => [$this->vacant_dn()]];
        }
        $batch[] = ['attrib' => 'member', 'modtype' => LDAP_MODIFY_BATCH_REMOVE, 'values' => [$this->user_dn($uid)]];
        if (!@ldap_modify_batch($conn, $this->group_dn($group), $batch) && ldap_errno($conn) !== self::NO_SUCH_ATTRIBUTE) {
            return $this->error('Verwijderen uit groep mislukt');
        }
        return true;
    }

    public function list_users(): array|\WP_Error {
        $entries = $this->search($this->people_dn(), '(objectClass=inetOrgPerson)', ['uid', 'mail', 'displayName', 'cn']);
        if (is_wp_error($entries)) {
            return $entries;
        }
        return array_values(array_filter(array_map([self::class, 'user_from_entry'], $entries), static fn($u) => $u['uid'] !== ''));
    }

    public function test_connection(): true|\WP_Error {
        $conn = $this->connection();
        if (is_wp_error($conn)) {
            return $conn;
        }
        $result = @ldap_read($conn, $this->base(), '(objectClass=*)', ['ou']);
        return $result === false ? $this->error('Directory niet leesbaar') : true;
    }
}
