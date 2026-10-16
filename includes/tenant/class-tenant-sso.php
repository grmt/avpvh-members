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

    /** How the session about to be created was started. */
    private string $login_method = self::VIA_AUTHELIA;

    private AVPVH_Tenant_Config $config;

    public function __construct(AVPVH_Tenant_Config $config) {
        $this->config = $config;
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

        $role = self::role_for_groups($identity['groups'], $this->config);
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
        wp_safe_redirect($target);
        exit;
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
        return $session;
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
        $checked = (int) get_user_meta($user_id, self::CHECKED_META, true);
        if (time() - $checked < (int) $this->config->get('recheck_seconds')) {
            return;
        }

        $expected = $this->config->tenant_id() . '/';
        if ($this->session_login() === self::VIA_AUTHELIA) {
            $identity = AVPVH_Tenant_Identity::probe($this->config);
            $same_user = $identity['status'] === AVPVH_Tenant_Identity::OK
                && $subject === $expected . $identity['uid'];
            $role = $same_user ? self::role_for_groups($identity['groups'], $this->config) : null;
        } else {
            // No Authelia session behind this one: the directory says
            // whether the account still has access. Reading only.
            $directory = AVPVH_Tenant_Directory::for_config($this->config);
            $identity = $directory && str_starts_with($subject, $expected)
                ? $directory->find_by_uid(substr($subject, strlen($expected)))
                : ['status' => AVPVH_Tenant_Directory::ERROR];
            $same_user = $identity['status'] === AVPVH_Tenant_Directory::OK;
            $role = $same_user ? self::role_for_groups($identity['groups'], $this->config) : null;
            $role = $role === 'subscriber' ? $role : null;
        }
        if ($role === null) {
            $this->log('recheck', $identity['uid'] ?? '', $same_user ? 'no_tenant_access' : $identity['status']);
            wp_logout();
            wp_set_current_user(0);
            return;
        }
        $this->sync_user($user_id, $identity, $role);
    }

    /**
     * Find or create the local user linked to this directory subject.
     *
     * @return int|\WP_Error
     */
    public function provision(array $identity, string $role) {
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
     * changed by hand is left alone.
     */
    private function sync_user(int $user_id, array $identity, string $role): void {
        update_user_meta($user_id, self::GROUPS_META, $identity['groups']);
        update_user_meta($user_id, self::CHECKED_META, time());

        $user = get_user_by('id', $user_id);
        if (!$user) {
            return;
        }
        $provisioned = (string) get_user_meta($user_id, self::ROLE_META, true);
        if ($provisioned !== '' && array_values($user->roles) === [$provisioned] && $provisioned !== $role) {
            $user->set_role($role);
            update_user_meta($user_id, self::ROLE_META, $role);
        }

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

    /** Groups of a provisioned user as last confirmed by Authelia. */
    public static function user_groups(int $user_id): array {
        $groups = get_user_meta($user_id, self::GROUPS_META, true);
        return is_array($groups) ? $groups : [];
    }

    public function handle_logout(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified right below
        $nonce = sanitize_text_field(wp_unslash($_GET[self::LOGOUT_ACTION] ?? ''));
        if ($nonce === '') {
            return;
        }
        if (is_user_logged_in() && wp_verify_nonce($nonce, self::LOGOUT_ACTION)) {
            wp_logout();
        }
        // Authelia's /logout ends the single sign-on session for every
        // Rechtspreker site; the plugin's own session is gone already.
        wp_safe_redirect(add_query_arg(
            'rd',
            rawurlencode(home_url('/')),
            untrailingslashit((string) $this->config->get('authelia_url')) . '/logout'
        ));
        exit;
    }

    /**
     * wp-login.php stays available for local (emergency) accounts while
     * local_login is true; otherwise every login goes through Authelia.
     */
    public function redirect_local_login(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only the action name is compared
        $action = sanitize_key(wp_unslash($_REQUEST['action'] ?? 'login'));
        if ($this->config->get('local_login') || in_array($action, ['logout', 'postpass'], true)) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- validated by login_url()'s callback
        $redirect_to = sanitize_text_field(wp_unslash($_GET['redirect_to'] ?? ''));
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
