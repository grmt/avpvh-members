<?php
/**
 * Read-only lookups in the directory for a tenant site: which account
 * belongs to an e-mail address that Google or Microsoft verified, and
 * which groups an account is in. It never writes.
 *
 * Accounts are inetOrgPerson entries anywhere under base_dn (a VvE member
 * can also be an AVP-PvH account); groups are groupOfNames entries whose
 * member is the account's DN, named by cn — the same model Authelia uses
 * (groups_filter (member={dn})), so a role comes out the same either way.
 *
 * Needs the PHP ldap extension and the 'directory' key in the tenant
 * file; without either, every lookup answers ERROR.
 */

defined('ABSPATH') || exit;

class AVPVH_Tenant_Directory {

    const OK        = 'ok';
    const NONE      = 'none';
    const AMBIGUOUS = 'ambiguous';
    const ERROR     = 'error';

    private array $settings;

    /** @var resource|\LDAP\Connection|null */
    private $connection = null;

    public function __construct(array $settings) {
        $this->settings = $settings;
    }

    /** The directory of a tenant, or null when the tenant has none configured. */
    public static function for_config(AVPVH_Tenant_Config $config): ?self {
        $settings = $config->directory();
        return $settings ? new self($settings) : null;
    }

    /**
     * The one account whose mail is $email (case-insensitive).
     *
     * @return array{status: string, uid?: string, dn?: string, email?: string, name?: string, groups?: string[]}
     */
    public function find_by_email(string $email): array {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['status' => self::NONE];
        }
        return $this->find_one('mail', $email);
    }

    /**
     * The groups of the account with this uid, for the periodic recheck.
     *
     * @return array{status: string, uid?: string, dn?: string, email?: string, name?: string, groups?: string[]}
     */
    public function find_by_uid(string $uid): array {
        $uid = strtolower(trim($uid));
        if (!preg_match('/^[a-z0-9][a-z0-9._@-]{0,99}$/', $uid)) {
            return ['status' => self::NONE];
        }
        return $this->find_one('uid', $uid);
    }

    /** Whether the read-only account can bind (for the healthcheck). */
    public function reachable(): bool {
        return (bool) $this->connect();
    }

    private function find_one(string $attribute, string $value): array {
        $connection = $this->connect();
        if (!$connection) {
            return ['status' => self::ERROR];
        }
        $filter = '(&(objectClass=inetOrgPerson)(' . $attribute . '=' . ldap_escape($value, '', LDAP_ESCAPE_FILTER) . '))';
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed search is reported through the status
        $result = @ldap_search($connection, $this->settings['base_dn'], $filter, ['uid', 'mail', 'displayName', 'cn'], 0, 2);
        if ($result === false) {
            return ['status' => self::ERROR];
        }
        $entries = ldap_get_entries($connection, $result);
        // A size limit of 2 is enough to tell one from more than one.
        if (($entries['count'] ?? 0) === 0) {
            return ['status' => self::NONE];
        }
        if ($entries['count'] > 1) {
            return ['status' => self::AMBIGUOUS];
        }
        $entry = $entries[0];
        $uid = strtolower((string) ($entry['uid'][0] ?? ''));
        if ($uid === '') {
            return ['status' => self::ERROR];
        }
        $groups = $this->groups_of((string) $entry['dn']);
        if ($groups === null) {
            return ['status' => self::ERROR];
        }
        return [
            'status' => self::OK,
            'uid'    => $uid,
            'dn'     => (string) $entry['dn'],
            // The first address, also when another one was looked up: the
            // same one Authelia passes on, so the WordPress user keeps it.
            'email'  => strtolower((string) ($entry['mail'][0] ?? '')),
            'name'   => (string) ($entry['displayname'][0] ?? $entry['cn'][0] ?? $uid),
            'groups' => $groups,
        ];
    }

    /** @return string[]|null lowercase group names, null on a directory error */
    private function groups_of(string $dn): ?array {
        $filter = '(&(objectClass=groupOfNames)(member=' . ldap_escape($dn, '', LDAP_ESCAPE_FILTER) . '))';
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed search is reported through the status
        $result = @ldap_search($this->connection, $this->settings['base_dn'], $filter, ['cn']);
        if ($result === false) {
            return null;
        }
        $entries = ldap_get_entries($this->connection, $result);
        $groups = [];
        for ($i = 0; $i < ($entries['count'] ?? 0); $i++) {
            $name = strtolower((string) ($entries[$i]['cn'][0] ?? ''));
            if ($name !== '') {
                $groups[] = $name;
            }
        }
        sort($groups);
        return array_values(array_unique($groups));
    }

    /** @return resource|\LDAP\Connection|null a bound connection, reused within the request */
    private function connect() {
        if ($this->connection) {
            return $this->connection;
        }
        if (!function_exists('ldap_connect')) {
            return null;
        }
        $password = is_readable($this->settings['bind_password_file'])
            ? trim((string) file_get_contents($this->settings['bind_password_file'])) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local Docker secret
            : '';
        if ($password === '') {
            return null;
        }
        $connection = ldap_connect($this->settings['url']);
        if (!$connection) {
            return null;
        }
        ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($connection, LDAP_OPT_NETWORK_TIMEOUT, $this->settings['timeout']);
        ldap_set_option($connection, LDAP_OPT_TIMELIMIT, $this->settings['timeout']);
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed bind is reported through the status
        if (!@ldap_bind($connection, $this->settings['bind_dn'], $password)) {
            return null;
        }
        $this->connection = $connection;
        return $connection;
    }
}
