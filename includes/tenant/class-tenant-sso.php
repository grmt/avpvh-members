<?php
/**
 * Login, local provisioning and role synchronisation for a Rechtspreker
 * tenant site.
 *
 * Authelia proves who the visitor is (AVPVH_Tenant_Identity); this class
 * decides the local WordPress role from the directory groups:
 *
 *   own or ancestor admin group → administrator
 *   member group                → subscriber
 *   neither                     → no session on this site
 *
 * Rights are recalculated at every login and at least every
 * recheck_seconds; any failure ends the session (fail closed). A session
 * started with Google or Microsoft (AVPVH_Tenant_OAuth) is rechecked
 * against the directory instead of Authelia, and only ever reads: an
 * administrator logs in through Authelia, with two-factor authentication.
 */

defined('ABSPATH') || exit;

class AVPVH_Tenant_SSO {

    const SUBJECT_META  = 'avpvh_directory_subject';
    const GROUPS_META   = 'avpvh_directory_groups';
    const CHECKED_META  = 'avpvh_directory_checked';
    const ROLE_META     = 'avpvh_provisioned_role';
    const LOGOUT_ACTION = 'avpvh_tenant_logout';
    /** Session information: how this WordPress session was started. */
    const LOGIN_KEY     = 'avpvh_login';
    const VIA_AUTHELIA  = 'authelia';
    const CHOOSER_PATH  = '/avpvh-login/';
    private const LOGOUT_CONFIRM_COOKIE = 'avpvh_tenant_logout_confirm';

    /** How the session about to be created was started. */
    private string $login_method = self::VIA_AUTHELIA;

    /** @var array<string, bool> Admin authorization for this request's browser session only. */
    private array $session_admin_access = [];

    private AVPVH_Tenant_Config $config;

    public function __construct(AVPVH_Tenant_Config $config) {
        $this->config = $config;
        add_filter('wp_is_application_passwords_available', '__return_false');
        add_filter('xmlrpc_enabled', '__return_false');
        add_action('init',            [$this, 'handle_logout'], 0);
        add_action('init',            [$this, 'handle_sso_callback'], 1);
        add_action('init',            [$this, 'recheck_session'], 2);
        add_action('login_init',      [$this, 'redirect_local_login']);
        add_action('admin_init',      [$this, 'restrict_wordpress_admin']);
        add_filter('show_admin_bar',  [$this, 'filter_admin_bar'], 20);
        add_filter('logout_url',      [$this, 'logout_url'], 10, 2);
        add_filter('login_url',       [$this, 'filter_login_url'], 10, 2);
        add_filter('allowed_redirect_hosts', [$this, 'allow_authelia_host']);
        add_filter('attach_session_information', [$this, 'attach_login_method']);
        add_filter('user_has_cap', [$this, 'filter_session_capabilities'], 20, 4);
        add_action('set_logged_in_cookie', [$this, 'on_logged_in_cookie']);
        add_filter('avpvh_flst_user_groups', [$this, 'filter_filelist_user_groups'], 10, 2);
        add_filter('avpvh_flst_available_groups', [$this, 'filter_filelist_available_groups']);
    }

    /** The local role for a set of directory groups, or null for no access. */
    public static function role_for_groups(array $groups, AVPVH_Tenant_Config $config): ?string {
        if (array_intersect($groups, $config->admin_groups())) {
            return 'administrator';
        }
        if (in_array($config->member_group(), $groups, true)) {
            return 'subscriber';
        }
        return null;
    }

    /**
     * Where "Inloggen" goes: the page with the choice between Google,
     * Microsoft and Authelia when OAuth is configured, otherwise Authelia.
     */
    public function login_url(string $redirect_to = ''): string {
        if (!$this->config->oauth_clients()) {
            return $this->authelia_login_url($redirect_to);
        }
        $chooser = home_url(self::CHOOSER_PATH);
        return $redirect_to !== '' ? add_query_arg('redirect_to', rawurlencode($redirect_to), $chooser) : $chooser;
    }

    public function authelia_login_url(string $redirect_to = ''): string {
        $target = home_url('/' . trim((string) $this->config->get('identity_probe_path'), '/') . '/');
        if ($redirect_to !== '') {
            $target = add_query_arg('redirect_to', rawurlencode($redirect_to), $target);
        }
        return add_query_arg('rd', rawurlencode($target), untrailingslashit((string) $this->config->get('authelia_url')) . '/');
    }

    public function filter_login_url(string $login_url, string $redirect): string {
        return $this->config->get('local_login') ? $login_url : $this->login_url($redirect);
    }

    public function logout_url(string $logout_url, string $redirect): string {
        return add_query_arg(self::LOGOUT_ACTION, wp_create_nonce(self::LOGOUT_ACTION), home_url('/'));
    }

    public function allow_authelia_host(array $hosts): array {
        $hosts[] = wp_parse_url((string) $this->config->get('authelia_url'), PHP_URL_HOST);
        return $hosts;
    }

    private function is_probe_request(): bool {
        $path = wp_parse_url(sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '')), PHP_URL_PATH);
        return is_string($path) && trim($path, '/') === trim((string) $this->config->get('identity_probe_path'), '/');
    }

    /**
     * Grants 'administrator' only when Authelia confirms 2FA on /wp-admin/;
     * an admin logged in with 1FA only is downgraded to 'subscriber' (if in
     * the member group) or gets null (requiring 2FA step-up).
     *
     * @return array{0: ?string, 1: string} [resolved role, reason ('ok'|'needs_2fa'|'error'|'no_access')]
     */
    private function resolve_role_with_2fa(array $identity): array {
        $role = self::role_for_groups($identity['groups'], $this->config);
        if ($role !== 'administrator') {
            return [$role, $role !== null ? 'ok' : 'no_access'];
        }
        $twofa = AVPVH_Tenant_Identity::probe_admin_2fa($this->config);
        if ($twofa['status'] === AVPVH_Tenant_Identity::OK
            && ($twofa['uid'] ?? '') === ($identity['uid'] ?? '')
            && self::role_for_groups($twofa['groups'] ?? [], $this->config) === 'administrator') {
            return ['administrator', 'ok'];
        }
        if ($twofa['status'] === AVPVH_Tenant_Identity::ERROR) {
            return [null, 'error'];
        }
        if (in_array($this->config->member_group(), $identity['groups'], true)) {
            return ['subscriber', 'ok'];
        }
        return [null, 'needs_2fa'];
    }

    /**
     * Authelia sends the visitor back to the probe path after login. Ask
     * Authelia who it is, provision the local user and continue to the
     * requested page on this site.
     */
    public function handle_sso_callback(): void {
        if (!$this->is_probe_request()) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the redirect target is validated against this site
        $target = $this->safe_target(sanitize_text_field(wp_unslash($_GET['redirect_to'] ?? '')));

        $identity = AVPVH_Tenant_Identity::probe($this->config);
        if ($identity['status'] !== AVPVH_Tenant_Identity::OK) {
            $this->log('callback', '', $identity['status']);
            $this->deny(__('Inloggen is niet gelukt. Probeer het later opnieuw.', 'avpvh-members'), 503);
        }

        [$role, $reason] = $this->resolve_role_with_2fa($identity);
        if ($reason === 'error') {
            $this->log('callback', $identity['uid'], 'twofa_probe_error');
            $this->deny(__('Inloggen is niet gelukt. Probeer het later opnieuw.', 'avpvh-members'), 503);
        }
        if ($reason === 'needs_2fa') {
            // Admin-only account arrived via 1FA: bounce to Authelia for /wp-admin/
            // so 2FA is completed before any administrator WP session is created.
            $this->log('callback', $identity['uid'], 'needs_2fa');
            $step_up = add_query_arg(
                'rd',
                rawurlencode('https://' . $this->config->get('canonical_host') . '/wp-admin/'),
                untrailingslashit((string) $this->config->get('authelia_url')) . '/'
            );
            wp_safe_redirect($step_up);
            exit;
        }
        if ($role === null) {
            $this->log('callback', $identity['uid'], 'no_tenant_access');
            if (is_user_logged_in()) {
                wp_logout();
            }
            $this->deny(__('Je bent ingelogd, maar je account heeft geen toegang tot deze site.', 'avpvh-members'), 403);
        }

        $user_id = $this->provision($identity, $role);
        if (is_wp_error($user_id)) {
            $this->log('callback', $identity['uid'], $user_id->get_error_code());
            $this->deny(__('Je account kon niet aan deze site worden gekoppeld. Neem contact op met de beheerder.', 'avpvh-members'), 409);
        }

        $this->log_in($user_id, self::VIA_AUTHELIA);
        $this->log('callback', $identity['uid'], $role);
        if (function_exists('nocache_headers')) {
            nocache_headers();
        }
        wp_safe_redirect($target);
        exit;
    }

    /**
     * Start a WordPress session when the visitor already has an Authelia
     * session with a role on this site (for example right after Authelia's
     * own gate on wp-admin or a fully gated tenant).
     */
    public function try_authelia_login(): bool {
        if (is_user_logged_in()) {
            return true;
        }
        $identity = AVPVH_Tenant_Identity::probe($this->config);
        if ($identity['status'] !== AVPVH_Tenant_Identity::OK) {
            return false;
        }
        [$role] = $this->resolve_role_with_2fa($identity);
        if ($role === null) {
            return false;
        }
        $user_id = $this->provision($identity, $role);
        if (is_wp_error($user_id)) {
            return false;
        }
        $this->log_in($user_id, self::VIA_AUTHELIA);
        $this->log('sso', $identity['uid'], $role);
        return true;
    }

    /** A same-site target for after the login; the home page otherwise. */
    public function safe_target(string $redirect_to): string {
        $target = $redirect_to !== '' ? wp_validate_redirect($redirect_to, home_url('/')) : home_url('/');
        return wp_parse_url($target, PHP_URL_HOST) === $this->config->get('canonical_host') ? $target : home_url('/');
    }

    /**
     * Start a WordPress session for $user_id, started via $method. An
     * existing session of someone else, or one started another way, ends
     * first, so the recheck always asks the right source.
     */
    public function log_in(int $user_id, string $method): void {
        if (is_user_logged_in() && (get_current_user_id() !== $user_id || $this->session_login() !== $method)) {
            wp_logout();
            wp_set_current_user(0);
        }
        if (get_current_user_id() === $user_id) {
            return;
        }
        $user = get_user_by('id', $user_id);
        $this->login_method = $method;
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id);
        $this->login_method = self::VIA_AUTHELIA;
        do_action('wp_login', $user->user_login, $user); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP core's own hook, so other login listeners still run
    }

    public function attach_login_method(array $session): array {
        $session[self::LOGIN_KEY] = $this->login_method;
        $session['avpvh_checked'] = time();
        return $session;
    }

    /**
     * WP roles belong to the account, while 2FA belongs to the browser.
     * Recheck the current browser before using any capability beyond reading,
     * including REST, AJAX and admin-post requests during the recheck interval.
     * Local emergency accounts and checks about other users are left alone.
     */
    public function filter_session_capabilities(array $allcaps, array $caps, array $args, \WP_User $user): array { // NOSONAR -- $args: WordPress passes it before $user
        $reader_caps = ['read', 'level_0', 'exist'];
        if ($user->ID !== get_current_user_id() || !array_diff($caps, $reader_caps)) {
            return $allcaps;
        }
        $subject = (string) get_user_meta($user->ID, self::SUBJECT_META, true);
        if ($subject === '' || $this->session_allows_elevated_capabilities($user, $subject)) {
            return $allcaps;
        }
        foreach (array_keys($allcaps) as $cap) {
            if (!in_array($cap, $reader_caps, true)) {
                $allcaps[$cap] = false;
            }
        }
        return $allcaps;
    }

    private function session_allows_elevated_capabilities(\WP_User $user, string $subject): bool {
        if ($this->session_login() !== self::VIA_AUTHELIA) {
            return false;
        }
        $requires_admin_group = in_array('administrator', $user->roles, true);
        $cookie = (string) ($_COOKIE[(string) $this->config->get('authelia_session_cookie')] ?? '');
        $key = hash('sha256', $subject . ':' . wp_get_session_token() . ':' . $cookie . ':' . (int) $requires_admin_group);
        if (!array_key_exists($key, $this->session_admin_access)) {
            // Deny any recursive capability check while the probe is running.
            $this->session_admin_access[$key] = false;
            $identity = AVPVH_Tenant_Identity::probe_admin_2fa($this->config);
            $directory_role = self::role_for_groups($identity['groups'] ?? [], $this->config);
            // Preserve manually assigned roles such as editor after 2FA, but
            // administrator always requires an actual directory admin group.
            $this->session_admin_access[$key] = $identity['status'] === AVPVH_Tenant_Identity::OK
                && $subject === $this->config->tenant_id() . '/' . ($identity['uid'] ?? '')
                && $directory_role !== null
                && (!$requires_admin_group || $directory_role === 'administrator');
        }
        return $this->session_admin_access[$key];
    }

    /** Make the newly set logged-in cookie available for nonces in the same request. */
    public function on_logged_in_cookie(string $logged_in_cookie): void {
        if (defined('LOGGED_IN_COOKIE')) {
            $_COOKIE[LOGGED_IN_COOKIE] = $logged_in_cookie;
        }
    }

    /** How the current session was started: authelia, google or microsoft. */
    public function session_login(): string {
        $token = wp_get_session_token();
        if (!is_user_logged_in() || $token === '') {
            return self::VIA_AUTHELIA;
        }
        $session = \WP_Session_Tokens::get_instance(get_current_user_id())->get($token);
        return (string) ($session[self::LOGIN_KEY] ?? self::VIA_AUTHELIA);
    }

    /**
     * Strict subscriber-only check for OAuth sessions: user must have only the
     * subscriber role and no elevated capabilities.
     */
    public static function user_is_subscriber_only(int $user_id): bool {
        $user = get_user_by('id', $user_id);
        if (!$user || array_values((array) $user->roles) !== ['subscriber']) {
            return false;
        }
        foreach ($user->allcaps as $cap => $granted) {
            if ($granted && !in_array($cap, ['read', 'level_0', 'exist', 'subscriber'], true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Re-ask Authelia for directory-provisioned users once per
     * recheck_seconds, so removed group memberships and ended Authelia
     * sessions take effect quickly.
     */
    public function recheck_session(): void {
        if (!is_user_logged_in() || $this->is_probe_request()) {
            return;
        }
        $user_id = get_current_user_id();
        $subject = (string) get_user_meta($user_id, self::SUBJECT_META, true);
        if ($subject === '') {
            return; // a local account (emergency access), not provisioned
        }
        $meta_checked = (int) get_user_meta($user_id, self::CHECKED_META, true);
        $session_checked = $this->session_checked_at($user_id);
        $checked = ($session_checked !== null && $meta_checked > 0)
            ? min($session_checked, $meta_checked)
            : ($session_checked ?? $meta_checked);
        if (time() - $checked < (int) $this->config->get('recheck_seconds')) {
            return;
        }

        // OAuth sessions may never carry more than subscriber rights, even if
        // a higher role (e.g. editor) was assigned manually after login.
        if ($this->session_login() !== self::VIA_AUTHELIA && !self::user_is_subscriber_only($user_id)) {
            $this->log('recheck', '', 'oauth_elevated_role');
            wp_logout();
            wp_set_current_user(0);
            return;
        }

        [$identity, $same_user, $role] = $this->current_access($subject);
        if ($role === null) {
            $this->log('recheck', $identity['uid'] ?? '', $same_user ? 'no_tenant_access' : $identity['status']);
            wp_logout();
            wp_set_current_user(0);
            return;
        }
        $this->sync_user($user_id, $identity, $role);
        $this->touch_session_checked_at($user_id);
    }

    private function session_checked_at(int $user_id): ?int {
        $token = wp_get_session_token();
        if ($token === '') {
            return null;
        }
        $session = \WP_Session_Tokens::get_instance($user_id)->get($token);
        return is_array($session) && isset($session['avpvh_checked']) ? (int) $session['avpvh_checked'] : null;
    }

    private function touch_session_checked_at(int $user_id): void {
        $token = wp_get_session_token();
        if ($token === '') {
            return;
        }
        $manager = \WP_Session_Tokens::get_instance($user_id);
        if (!method_exists($manager, 'update')) {
            return;
        }
        $session = $manager->get($token);
        if (is_array($session)) {
            $session['avpvh_checked'] = time();
            $manager->update($token, $session);
        }
    }

    /**
     * Who the session's subject is now and which role that gives: asked of
     * Authelia, or of the directory for a Google/Microsoft session (which
     * has no Authelia session behind it and only ever reads).
     *
     * @return array{0: array, 1: bool, 2: ?string} identity, same user, role
     */
    private function current_access(string $subject): array {
        $expected = $this->config->tenant_id() . '/';
        if ($this->session_login() === self::VIA_AUTHELIA) {
            $identity = AVPVH_Tenant_Identity::probe($this->config);
            $same_user = $identity['status'] === AVPVH_Tenant_Identity::OK
                && $subject === $expected . $identity['uid'];
            $role = $same_user ? $this->resolve_role_with_2fa($identity)[0] : null;
            return [$identity, $same_user, $role];
        }
        $directory = AVPVH_Tenant_Directory::for_config($this->config);
        $identity = $directory && str_starts_with($subject, $expected)
            ? $directory->find_by_uid(substr($subject, strlen($expected)))
            : ['status' => AVPVH_Tenant_Directory::ERROR];
        $same_user = $identity['status'] === AVPVH_Tenant_Directory::OK;
        $role = $same_user ? self::role_for_groups($identity['groups'], $this->config) : null;
        return [$identity, $same_user, $role === 'subscriber' ? $role : null];
    }

    /**
     * Find or create the local user linked to this directory subject.
     *
     * @return int|\WP_Error
     */
    public function provision(array $identity, string $role, bool $subscriber_only = false) {
        $subject = $this->config->tenant_id() . '/' . $identity['uid'];
        $user_id = $this->find_user($subject);
        if (is_wp_error($user_id)) {
            return $user_id;
        }
        if ($user_id === 0) {
            // Concurrent first logins must not create two users.
            $lock = 'avpvh_tenant_lock_' . substr(hash('sha256', $subject), 0, 32);
            if (!add_option($lock, time(), '', false)) {
                if (time() - (int) get_option($lock) < 30) {
                    return new \WP_Error('provision_busy');
                }
                update_option($lock, time(), false);
            }
            try {
                $user_id = $this->find_user($subject);
                if ($user_id === 0) {
                    $user_id = $this->create_user($subject, $identity, $role);
                }
            } finally {
                delete_option($lock);
            }
            if (is_wp_error($user_id)) {
                return $user_id;
            }
        }
        if ($subscriber_only && !self::user_is_subscriber_only($user_id)) {
            return new \WP_Error('oauth_elevated_account');
        }
        $this->sync_user($user_id, $identity, $role);
        return $user_id;
    }

    /** @return int|\WP_Error 0 when no user is linked yet */
    private function find_user(string $subject) {
        $ids = get_users([
            'meta_key'   => self::SUBJECT_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one indexed lookup per login
            'meta_value' => $subject, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            'number'     => 2,
            'fields'     => 'ID',
        ]);
        if (count($ids) > 1) {
            return new \WP_Error('subject_linked_twice');
        }
        return $ids ? (int) $ids[0] : 0;
    }

    /** @return int|\WP_Error */
    private function create_user(string $subject, array $identity, string $role) {
        $login = sanitize_user($identity['uid'], true);
        // Never take over an existing local account with the same name or
        // address; an administrator links it explicitly (see README).
        if ($login === '' || username_exists($login)) {
            return new \WP_Error('login_taken');
        }
        if ($identity['email'] !== '' && email_exists($identity['email'])) {
            return new \WP_Error('email_taken');
        }
        $user_id = wp_insert_user([
            'user_login'   => $login,
            'user_pass'    => wp_generate_password(64, true, true),
            'user_email'   => $identity['email'],
            'display_name' => $identity['name'] !== '' ? $identity['name'] : $login,
            'role'         => $role,
        ]);
        if (is_wp_error($user_id)) {
            return $user_id;
        }
        update_user_meta($user_id, self::SUBJECT_META, $subject);
        update_user_meta($user_id, self::ROLE_META, $role);
        return (int) $user_id;
    }

    /**
     * Store the current groups and apply the role. A role this plugin set
     * earlier follows the groups, including revocation; a role someone
     * changed by hand is left alone, except administrator, which only an
     * admin group gives.
     */
    private function sync_user(int $user_id, array $identity, string $role): void {
        update_user_meta($user_id, self::GROUPS_META, $identity['groups']);
        update_user_meta($user_id, self::CHECKED_META, time());

        $user = get_user_by('id', $user_id);
        if (!$user) {
            return;
        }
        $this->apply_role($user, $identity, $role);

        $changes = [];
        if ($identity['name'] !== '' && $identity['name'] !== $user->display_name) {
            $changes['display_name'] = $identity['name'];
        }
        if ($identity['email'] !== '' && $identity['email'] !== $user->user_email) {
            $owner = email_exists($identity['email']);
            if (!$owner || (int) $owner === $user_id) {
                $changes['user_email'] = $identity['email'];
            }
        }
        if ($changes) {
            wp_update_user(['ID' => $user_id] + $changes);
        }
    }

    /**
     * A role this plugin set follows the groups; a linked account never
     * stays administrator without an admin group, also when the role was
     * set by hand or before the link.
     */
    private function apply_role(\WP_User $user, array $identity, string $role): void {
        if (array_values($user->roles) === [$role]) {
            return;
        }
        $provisioned = (string) get_user_meta($user->ID, self::ROLE_META, true);
        $follows_groups = $provisioned !== '' && array_values($user->roles) === [$provisioned];
        $exceeds_groups = $role !== 'administrator' && in_array('administrator', $user->roles, true);
        if (!$follows_groups && !$exceeds_groups) {
            return;
        }
        $user->set_role($role);
        update_user_meta($user->ID, self::ROLE_META, $role);
        if ($exceeds_groups) {
            $this->log('sync', $identity['uid'] ?? '', 'admin_role_revoked');
        }
    }

    /** Groups of a provisioned user as last confirmed by Authelia. */
    public static function user_groups(int $user_id): array {
        $groups = get_user_meta($user_id, self::GROUPS_META, true);
        return is_array($groups) ? $groups : [];
    }

    /**
     * Supply the user's confirmed directory groups to AV-PvH File List
     * (the avpvh_flst_user_groups filter).
     *
     * @param string[] $groups
     * @return string[]
     */
    public function filter_filelist_user_groups(array $groups, int $user_id): array {
        if ($user_id <= 0) {
            return $groups;
        }
        return array_values(array_unique(array_merge($groups, self::user_groups($user_id))));
    }

    /**
     * Supply this tenant's configured groups to AV-PvH File List
     * (the avpvh_flst_available_groups filter).
     *
     * @param string[] $groups
     * @return string[]
     */
    public function filter_filelist_available_groups(array $groups): array {
        return array_values(array_unique(array_merge(
            $groups,
            [$this->config->member_group()],
            $this->config->content_groups(),
            $this->config->admin_groups()
        )));
    }

    public function authelia_logout_url(string $target): string {
        return add_query_arg('rd', rawurlencode($this->safe_target($target)),
            untrailingslashit((string) $this->config->get('authelia_url')) . '/logout');
    }

    public function handle_logout(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified right below
        $nonce = sanitize_text_field(wp_unslash($_POST[self::LOGOUT_ACTION] ?? $_GET[self::LOGOUT_ACTION] ?? ''));
        if ($nonce === '') {
            return;
        }
        if (!is_user_logged_in()) {
            $this->confirm_signed_out_logout($nonce);
        }
        if (!wp_verify_nonce($nonce, self::LOGOUT_ACTION)) {
            wp_die(
                '<p>' . esc_html__('Wil je uitloggen?', 'avpvh-members') . '</p><p><a class="button" href="' . esc_url($this->logout_url('', '')) . '">'
                    . esc_html__('Uitloggen', 'avpvh-members') . '</a> <a href="' . esc_url(home_url('/')) . '">' . esc_html__('Nee, terug', 'avpvh-members') . '</a></p>',
                esc_html__('Uitloggen', 'avpvh-members'),
                ['response' => 200]
            );
        }
        wp_logout();
        // Authelia's /logout ends the single sign-on session for every
        // Rechtspreker site; the plugin's own session is gone already.
        wp_safe_redirect($this->authelia_logout_url(home_url('/')));
        exit;
    }

    /** Bind the confirmation to this browser when WordPress has no session. */
    private function confirm_signed_out_logout(string $nonce): void {
        $cookie_name = (is_ssl() ? '__Host-' : '') . self::LOGOUT_CONFIRM_COOKIE;
        $cookie = (string) ($_COOKIE[$cookie_name] ?? '');
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce and browser token are both verified below
        $confirmation = sanitize_text_field(wp_unslash($_POST['avpvh_logout_confirmation'] ?? ''));
        $confirmed = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
            && wp_verify_nonce($nonce, self::LOGOUT_ACTION)
            && $cookie !== '' && $confirmation !== '' && hash_equals($cookie, $confirmation);
        $token = $confirmed ? '' : bin2hex(random_bytes(32));
        setcookie($cookie_name, $token, [
            'expires' => $confirmed ? time() - 3600 : time() + 600,
            'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Strict',
        ]);
        if ($confirmed) {
            unset($_COOKIE[$cookie_name]);
            return;
        }
        $_COOKIE[$cookie_name] = $token;
        nocache_headers();
        wp_die(
            '<p>' . esc_html__('Wil je uitloggen?', 'avpvh-members') . '</p>'
            . '<form method="post" action="' . esc_url(home_url('/')) . '">'
            . '<input type="hidden" name="' . esc_attr(self::LOGOUT_ACTION) . '" value="' . esc_attr(wp_create_nonce(self::LOGOUT_ACTION)) . '">'
            . '<input type="hidden" name="avpvh_logout_confirmation" value="' . esc_attr($token) . '">'
            . '<button type="submit">' . esc_html__('Uitloggen', 'avpvh-members') . '</button>'
            . ' <a href="' . esc_url(home_url('/')) . '">' . esc_html__('Nee, terug', 'avpvh-members') . '</a></form>',
            esc_html__('Uitloggen', 'avpvh-members'), ['response' => 200]
        );
    }

    /**
     * wp-login.php stays available for local (emergency) accounts only
     * when local_login is true (default false); otherwise it leads to the
     * login choice (Authelia, or Google/Microsoft for members), or directly
     * to the target when the visitor already has an Authelia session.
     */
    public function redirect_local_login(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only the action name is compared
        $action = sanitize_key(wp_unslash($_REQUEST['action'] ?? 'login'));
        if ($this->config->get('local_login') || in_array($action, ['logout', 'postpass'], true)) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- validated by safe_target() and login_url()
        $redirect_to = sanitize_text_field(wp_unslash($_GET['redirect_to'] ?? ''));
        if ($this->try_authelia_login()) {
            wp_safe_redirect($this->safe_target($redirect_to));
            exit;
        }
        wp_safe_redirect($this->login_url($redirect_to));
        exit;
    }

    /** Members have a session for reading, not for wp-admin. */
    public function restrict_wordpress_admin(): void {
        global $pagenow;
        if (wp_doing_ajax() || in_array($pagenow, ['admin-post.php', 'async-upload.php'], true)) {
            return;
        }
        if (!current_user_can('edit_posts')) {
            wp_safe_redirect(home_url('/'));
            exit;
        }
    }

    public function filter_admin_bar(bool $show): bool {
        return $show && current_user_can('edit_posts');
    }

    private function deny(string $message, int $status): void {
        wp_die(
            '<p>' . esc_html($message) . '</p><p><a href="' . esc_url($this->logout_url('', '')) . '">'
                . esc_html__('Uitloggen', 'avpvh-members') . '</a> · <a href="' . esc_url(home_url('/')) . '">'
                . esc_html__('Naar de homepage', 'avpvh-members') . '</a></p>',
            esc_html__('Geen toegang', 'avpvh-members'),
            ['response' => $status] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an options array, not output
        );
    }

    /** Decisions are logged with tenant and a hashed subject only. */
    private function log(string $step, string $uid, string $decision): void {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log(sprintf(
            '[avpvh-tenant] tenant=%s step=%s subject=%s decision=%s',
            $this->config->tenant_id(),
            $step,
            $uid !== '' ? AVPVH_Tenant_Identity::log_subject($uid) : '-',
            sanitize_key($decision)
        ));
    }
}
