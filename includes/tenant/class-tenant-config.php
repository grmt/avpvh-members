<?php
/**
 * Server-side configuration of a Rechtspreker tenant site.
 *
 * A site is a tenant when wp-content/avpvh-tenant.php returns an array,
 * or wp-config.php defines AVPVH_TENANT_CONFIG as one (the constant wins).
 * Without either the plugin runs as the AVP-PvH member system, unchanged.
 * Prefer the file: it lives in wp-content, which the WordPress and WP-CLI
 * containers share, so wp-cli can never start the site in AVP mode and
 * create pvh_avm_* tables there. The values never come from request
 * headers or the host name; see PLAN-rsp-tenant-admin-sso.md.
 *
 *   <?php // wp-content/avpvh-tenant.php
 *   return [
 *       'tenant_id'               => 'rsp_vve_vp4042',
 *       'ancestors'               => ['rsp', 'rsp_vve'],
 *       'canonical_host'          => 'vp40-42.vve.rechtspreker.nl',
 *       'authelia_url'            => 'https://auth.rechtspreker.nl',
 *       'authelia_authz_url'      => 'http://authelia:9091/api/authz/auth-request',
 *       'authelia_session_cookie' => 'rechtspreker_session',
 *       'member_group'            => 'vp4042-leden',
 *       'admin_group'             => 'rsp-vve-vp4042-admins',
 *       'ancestor_admin_groups'   => ['rsp-admins', 'rsp-vve-admins'],
 *       'members_only_pages'      => ['leden'],
 *       // Where "Inloggen"/"Uitloggen" goes: classic menu locations and/or
 *       // wp_navigation post IDs of a block theme's Navigation blocks
 *       // ('all' for every Navigation block, also one without a ref).
 *       'menu_locations'          => ['primary'],
 *       'navigation_refs'         => [123],
 *   ];
 */

defined('ABSPATH') || exit;

class AVPVH_Tenant_Config {

    const GROUP_PATTERN  = '/^[a-z0-9][a-z0-9._-]{0,63}$/';
    const TENANT_PATTERN = '/^[a-z0-9]+(?:_[a-z0-9]+)*$/';

    /** @var array<string, mixed> */
    private array $values;

    /** @var string[] */
    private array $errors;

    private function __construct(array $values, array $errors) {
        $this->values = $values;
        $this->errors = $errors;
    }

    /** The tenant configuration of this site, or null for the AVP-PvH site. */
    public static function current(): ?self {
        static $config = false;
        if ($config === false) {
            $raw = defined('AVPVH_TENANT_CONFIG') ? AVPVH_TENANT_CONFIG : self::read_file();
            $config = $raw === null
                ? null
                // A file or constant that is not an array is a broken tenant
                // config, not an AVP-PvH site: fail closed.
                : self::from_array(is_array($raw) ? $raw : [], (string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        }
        return $config;
    }

    private static function read_file(): mixed {
        $file = WP_CONTENT_DIR . '/avpvh-tenant.php';
        if (!is_file($file)) {
            return null;
        }
        // Executed over HTTP the file only returns its array: no output.
        return include $file;
    }

    /**
     * Normalises and validates raw configuration. $site_host is the host of
     * home_url(); the configured canonical host must match it.
     */
    public static function from_array(array $raw, string $site_host): self {
        $defaults = [
            'tenant_id'               => '',
            'ancestors'               => [],
            'canonical_host'          => '',
            'authelia_url'            => '',
            'authelia_authz_url'      => '',
            'authelia_session_cookie' => '',
            'identity_probe_path'     => '/avpvh-sso/',
            'member_group'            => '',
            'admin_group'             => '',
            'ancestor_admin_groups'   => [],
            'content_groups'          => null,
            'members_only_pages'      => [],
            'menu_locations'          => [],
            'navigation_refs'         => [],
            'local_login'             => true,
            'recheck_seconds'         => 300,
        ];
        $values = array_merge($defaults, array_intersect_key($raw, $defaults));
        $values['ancestors']             = array_values(array_map('strval', (array) $values['ancestors']));
        $values['ancestor_admin_groups'] = array_values(array_map('strval', (array) $values['ancestor_admin_groups']));
        $values['members_only_pages']    = array_values(array_map('strval', (array) $values['members_only_pages']));
        $values['menu_locations']        = array_values(array_map('strval', (array) $values['menu_locations']));
        $values['navigation_refs']       = $values['navigation_refs'] === 'all'
            ? 'all'
            : array_values(array_map('intval', (array) $values['navigation_refs']));
        $values['content_groups']        = $values['content_groups'] === null
            ? [$values['member_group']]
            : array_values(array_map('strval', (array) $values['content_groups']));
        $values['canonical_host']        = strtolower((string) $values['canonical_host']);
        $values['local_login']           = (bool) $values['local_login'];
        $values['recheck_seconds']       = max(60, (int) $values['recheck_seconds']);

        $errors = [];
        $tenant_id = (string) $values['tenant_id'];
        if (!preg_match(self::TENANT_PATTERN, $tenant_id)) {
            $errors[] = 'tenant_id';
        }
        // The ancestor path is explicit: each tenant ID extends its parent's.
        $parent = '';
        foreach ($values['ancestors'] as $ancestor) {
            if (!preg_match(self::TENANT_PATTERN, $ancestor) || ($parent !== '' && !str_starts_with($ancestor, $parent . '_'))) {
                $errors[] = 'ancestors';
                break;
            }
            $parent = $ancestor;
        }
        if ($parent !== '' && !str_starts_with($tenant_id, $parent . '_')) {
            $errors[] = 'ancestors';
        }
        if ($values['canonical_host'] === '' || $values['canonical_host'] !== strtolower($site_host)) {
            $errors[] = 'canonical_host';
        }
        foreach (['authelia_url', 'authelia_authz_url'] as $key) {
            $scheme = wp_parse_url((string) $values[$key], PHP_URL_SCHEME);
            if (!in_array($scheme, ['http', 'https'], true) || !wp_parse_url((string) $values[$key], PHP_URL_HOST)) {
                $errors[] = $key;
            }
        }
        if (wp_parse_url((string) $values['authelia_url'], PHP_URL_SCHEME) !== 'https') {
            $errors[] = 'authelia_url';
        }
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', (string) $values['authelia_session_cookie'])) {
            $errors[] = 'authelia_session_cookie';
        }
        if (!preg_match('#^/[A-Za-z0-9/_-]*$#', (string) $values['identity_probe_path'])) {
            $errors[] = 'identity_probe_path';
        }
        $groups = array_merge(
            [$values['member_group'], $values['admin_group']],
            $values['ancestor_admin_groups'],
            $values['content_groups']
        );
        foreach ($groups as $group) {
            if (!preg_match(self::GROUP_PATTERN, (string) $group)) {
                $errors[] = 'groups';
                break;
            }
        }
        // Member and admin groups stay separate (an admin is not a member by
        // virtue of being an admin, and the other way round).
        if (in_array($values['member_group'], self::admin_groups_of($values), true)) {
            $errors[] = 'groups';
        }
        if (count($values['ancestor_admin_groups']) !== count($values['ancestors'])) {
            $errors[] = 'ancestor_admin_groups';
        }

        return new self($values, array_values(array_unique($errors)));
    }

    private static function admin_groups_of(array $values): array {
        return array_values(array_unique(array_merge($values['ancestor_admin_groups'], [$values['admin_group']])));
    }

    public function is_valid(): bool {
        return $this->errors === [];
    }

    /** @return string[] names of the settings that failed validation */
    public function errors(): array {
        return $this->errors;
    }

    public function get(string $key): mixed {
        return $this->values[$key] ?? null;
    }

    public function tenant_id(): string {
        return (string) $this->values['tenant_id'];
    }

    /** Groups whose members administer this site: own plus ancestors'. */
    public function admin_groups(): array {
        return self::admin_groups_of($this->values);
    }

    /** Groups that may read the members-only pages. */
    public function content_groups(): array {
        return $this->values['content_groups'];
    }

    public function member_group(): string {
        return (string) $this->values['member_group'];
    }

    public function identity_probe_url(): string {
        return 'https://' . $this->values['canonical_host'] . $this->values['identity_probe_path'];
    }
}
