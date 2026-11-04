<?php
/**
 * Access check for the private files of a tenant site, for nginx's
 * auth_request: nginx asks /avpvh-file-auth/ before it serves anything
 * under wp-content/uploads/private/ (or another upload directory it puts
 * behind this check), and serves the file itself, so large files never
 * pass through PHP.
 *
 *   204  may read the members-only pages: a WordPress session with one of
 *        content_groups (also one started with Google or Microsoft), or
 *        an Authelia session with such a group
 *   401  not logged in; X-Avpvh-Login holds the login URL, back to the file
 *   403  logged in without access
 *
 * The nginx side, with the original URI passed on as X-Original-URI, is in
 * PLAN-rsp-tenant-admin-sso.md (fase 3).
 */

defined('ABSPATH') || exit;

class AVPVH_Tenant_Files {

    const PATH         = '/avpvh-file-auth/';
    /** nginx may put any upload directory behind this check (kb-kennis/ on kb). */
    const UPLOADS_PATH = '/wp-content/uploads/';

    private AVPVH_Tenant_Config $config;
    private AVPVH_Tenant_SSO $sso;
    private AVPVH_Tenant_Content $content;

    public function __construct(AVPVH_Tenant_Config $config, AVPVH_Tenant_SSO $sso, AVPVH_Tenant_Content $content) {
        $this->config  = $config;
        $this->sso     = $sso;
        $this->content = $content;
        // After AVPVH_Tenant_SSO::recheck_session() (init, 2), so a revoked
        // session no longer opens files.
        add_action('init', [$this, 'handle'], 4);
    }

    public function handle(): void {
        $path = wp_parse_url(sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '')), PHP_URL_PATH);
        if (!is_string($path) || trim($path, '/') !== trim(self::PATH, '/')) {
            return;
        }
        nocache_headers();
        $status = $this->decide();
        if ($status === 401) {
            header('X-Avpvh-Login: ' . $this->sso->login_url($this->original_url()));
        }
        status_header($status);
        exit;
    }

    private function decide(): int {
        if ($this->content->current_user_may_read()) {
            return 204;
        }
        $identity = AVPVH_Tenant_Identity::probe($this->config);
        if ($identity['status'] === AVPVH_Tenant_Identity::OK) {
            return array_intersect($identity['groups'], $this->config->content_groups()) ? 204 : 403;
        }
        return is_user_logged_in() ? 403 : 401;
    }

    /**
     * The requested file, to return to after the login; the home page
     * otherwise. Only a plain path below uploads/ on this site: no query,
     * no climbing, no second slash that could start another host.
     */
    private function original_url(): string {
        $path = (string) wp_parse_url(wp_unslash($_SERVER['HTTP_X_ORIGINAL_URI'] ?? ''), PHP_URL_PATH); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated against a strict pattern below
        if (!str_starts_with($path, self::UPLOADS_PATH) || 1 !== preg_match('#^[A-Za-z0-9._~%/-]+$#', $path)
            || str_contains($path, '//') || str_contains($path, '..')) {
            return home_url('/');
        }
        return home_url($path);
    }
}
