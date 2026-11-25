<?php
defined('ABSPATH') || exit;
require_once __DIR__ . '/class-content-guard.php';

class AVPVH_Access {

    /** The front-end login page. */
    const LOGIN_PATH = '/avpvh-login/';
    /** Leaked-password reports accepted per IP address per 15 minutes (handle_hibp_flag()). */
    private const HIBP_FLAGS_PER_IP = 5;
    /** Session token keys tracking auth method and step-up 2FA completion. */
    const SESSION_AUTH_KEY = 'avpvh_auth_method';
    const SESSION_2FA_KEY  = 'avpvh_2fa';

    /** Front-end 1FA admin-post actions that do not require wp-admin 2FA. */
    private const FRONTEND_ADMIN_POST_ACTIONS = [
        'avpvh_export_ledenlijst',
        'avpvh_save_own_participation',
        'avpvh_set_directory_consent',
        'avpvh_set_newsletter_consent',
        'avpvh_remove_identity',
        'avpvh_make_primary_identity',
        'avpvh_request_identity',
        'avpvh_start_email_identity',
        'avpvh_gallery_share_hide',
        'avpvh_gallery_share_tidy',
        'avpvh_gallery_share_delete',
        'avpvh_gallery_share_recreate',
        'avbk_apply_balance_selection',
        'avbk_book_order',
        'avbk_check_book_email',
        'avbk_tshirt_order',
        'avbk_check_tshirt_email',
        'avbk_congress_register',
    ];

    /** Front-end AJAX handlers still enforce their own nonce and target rules. */
    private const FRONTEND_AJAX_ACTIONS = [
        'avpvh_save_member_profile', 'avpvh_address_lookup', 'avpvh_media_session_status',
        'avpvh_logged_in', 'avbk_dismiss_popup', 'avbk_check_book_email', 'avbk_check_tshirt_email',
        'gallery', 'page', 'video_proxy', 'nieuwsbrief_image', 'nieuwsbrief_pdf',
        'gallery_marks', 'gallery_mark', 'gallery_tag_add', 'gallery_tag_list', 'gallery_tag_delete',
        'gallery_comment_add', 'gallery_comment_list', 'gallery_reaction_add', 'gallery_reaction_list',
        'gallery_tag_candidates', 'gallery_share_create', 'gallery_dig_captions',
        'gallery_filter', 'gallery_filter_options', 'gallery_filter_save',
        'gallery_filter_share', 'gallery_filter_recipients', 'gallery_filter_preset_save', 'gallery_filter_preset_delete',
    ];

    /** @var array{method: string, two_factor: bool}|null */
    private static ?array $pending_session_auth = null;

    /** @var int[]|null IDs of the members section, see members_section_ids() */
    private ?array $section = null;

    public function __construct() {
        add_action('init',               [$this, 'auto_login_from_proxy_header'], 1);
        add_action('init',               [$this, 'enforce_session_idle_timeout'], 1);
        add_action('init',               [$this, 'handle_sso_callback'], 2);
        add_action('wp_login',           [$this, 'reset_session_idle_timer'], 10, 2);
        add_action('admin_init',         [$this, 'restrict_wordpress_admin']);
        add_action('admin_init',         [$this, 'enforce_step_up_2fa_for_admin_post'], 5);
        add_action('admin_init',         [$this, 'enforce_step_up_2fa_for_ajax'], 5);
        add_filter('attach_session_information', [$this, 'attach_session_auth_metadata']);
        add_filter('xmlrpc_enabled',     '__return_false');
        add_filter('wp_is_application_passwords_available', '__return_false');
        add_action('template_redirect',  [$this, 'handle_login_bridge'], 5);
        add_filter('show_admin_bar',     [$this, 'filter_admin_bar_visibility'], 20);
        add_filter('user_has_cap',       [$this, 'filter_member_capabilities'], 10, 4);
        add_filter('the_content',        [$this, 'inject_login_form'], 5);
        add_filter('post_password_required', [$this, 'bypass_for_active_member'], 10, 2);
        add_filter('the_content',          [$this, 'ex_member_notice']);
        add_filter('protected_title_format', [$this, 'clean_protected_title']);
        add_filter('the_password_form',    [$this, 'members_only_form']);
        add_action('rest_api_init',        [$this, 'register_hibp_route']);

        // template_redirect never runs for REST, search listings, comments or oEmbed:
        // the members section and restricted pages are hidden there separately.
        add_action('pre_get_posts',                  [$this, 'exclude_members_pages_from_queries']);
        add_filter('rest_page_query',                [$this, 'exclude_members_pages_from_rest_query']);
        add_filter('rest_attachment_query',          [$this, 'filter_public_media_query']);
        add_filter('rest_post_search_query',         [$this, 'exclude_members_pages_from_rest_query']);
        add_filter('rest_comment_query',             [$this, 'exclude_restricted_comments_from_rest_query']);
        add_filter('comments_clauses',               [$this, 'exclude_restricted_comments_from_clauses']);
        add_filter('rest_request_before_callbacks',  [$this, 'enforce_step_up_2fa_for_rest'], 5, 3);
        add_filter('rest_request_before_callbacks',  [$this, 'hide_members_page_from_rest'], 10, 3);
        add_filter('oembed_request_post_id',         [$this, 'hide_members_page_from_oembed']);
        AVPVH_Content_Guard::register([$this, 'post_is_restricted']);

        add_action('wp_head',                      [$this, 'noindex_members_content']);
        add_filter('wp_sitemaps_posts_query_args', [$this, 'sitemap_exclude_protected']);
        add_filter('wp_sitemaps_add_provider',     [$this, 'sitemap_remove_users'], 10, 2);
        add_filter('wp_sitemaps_post_types',       [$this, 'sitemap_remove_post_type']);
        add_filter('wp_sitemaps_taxonomies',       '__return_empty_array');

        add_filter('avpvh_flst_user_groups',       ['AVPVH_Roles', 'user_filelist_groups'], 10, 2);
        add_filter('avpvh_flst_available_groups',  ['AVPVH_Roles', 'available_filelist_groups']);
    }

    /** Records auth method & 2FA state to be attached to the next WP session cookie. */
    public static function mark_next_session_auth(string $method, bool $two_factor): void {
        self::$pending_session_auth = ['method' => $method, 'two_factor' => $two_factor];
    }

    public function attach_session_auth_metadata(array $session): array {
        if (self::$pending_session_auth !== null) {
            $session[self::SESSION_AUTH_KEY] = self::$pending_session_auth['method'];
            $session[self::SESSION_2FA_KEY]  = self::$pending_session_auth['two_factor'];
            self::$pending_session_auth = null;
        }
        return $session;
    }

    /** Marks the current WP session token as having satisfied Authelia 2FA. */
    private static function upgrade_current_session_2fa(): void {
        if (!is_user_logged_in() || !function_exists('wp_get_session_token') || !class_exists('WP_Session_Tokens')) {
            return;
        }
        $token = wp_get_session_token();
        if ($token === '') {
            return;
        }
        $manager = \WP_Session_Tokens::get_instance(get_current_user_id());
        $session = $manager->get($token);
        if (is_array($session) && (!isset($session[self::SESSION_AUTH_KEY]) || empty($session[self::SESSION_2FA_KEY]))) {
            $session[self::SESSION_AUTH_KEY] ??= 'proxy';
            $session[self::SESSION_2FA_KEY] = true;
            $manager->update($token, $session);
        }
    }

    /**
     * Whether the current WP session has passed step-up 2FA (i.e. visited a
     * 2FA-gated /wp-admin/ route via Authelia). Sessions started via 1FA
     * (OAuth or /avpvh-sso/) return false until upgraded.
     */
    public static function current_session_has_2fa(): bool {
        if (!is_user_logged_in()) {
            return false;
        }
        if (!function_exists('wp_get_session_token') || !class_exists('WP_Session_Tokens')) {
            return false;
        }
        $token = wp_get_session_token();
        if ($token === '') {
            return false;
        }
        $session = \WP_Session_Tokens::get_instance(get_current_user_id())->get($token);
        if (!is_array($session) || !isset($session[self::SESSION_AUTH_KEY])) {
            return false;
        }
        return !empty($session[self::SESSION_2FA_KEY]);
    }

    /**
     * True when the current request is a /wp-admin/ route gated by Authelia
     * two_factor (not bypassed admin-ajax.php, admin-post.php or async-upload.php).
     */
    private function is_wp_admin_2fa_request(): bool {
        if (!function_exists('is_admin') || !is_admin()) {
            return false;
        }
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return false;
        }
        global $pagenow;
        return !in_array($pagenow ?? '', ['admin-ajax.php', 'admin-post.php', 'async-upload.php'], true);
    }

    /**
     * Plugin admin-post actions, and other administrative actions for
     * privileged users, need step-up outside the explicit frontend allowlist.
     */
    public function enforce_step_up_2fa_for_admin_post(): void {
        global $pagenow;
        if (($pagenow ?? '') !== 'admin-post.php' || !is_user_logged_in()) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing gate; each handler verifies its own nonce
        $action = sanitize_key(wp_unslash($_REQUEST['action'] ?? ''));
        $frontend = (array) apply_filters('avpvh_mbrs_2fa_frontend_admin_post_actions', self::FRONTEND_ADMIN_POST_ACTIONS);
        if ($action === '' || in_array($action, $frontend, true)) {
            return;
        }
        if (!str_starts_with($action, 'avpvh_') && !AVPVH_Roles::current_user_needs_wp_admin()) {
            return;
        }
        if (!self::current_session_has_2fa()) {
            wp_die(
                esc_html__('Voor deze beheerdersactie is tweestapsverificatie (2FA) nodig. Open eerst /wp-admin/ om met 2FA in te loggen.', 'avpvh-members'),
                esc_html__('Tweestapsverificatie vereist', 'avpvh-members'),
                ['response' => 403]
            );
        }
    }

    public function enforce_step_up_2fa_for_ajax(): void {
        global $pagenow;
        if (($pagenow ?? '') !== 'admin-ajax.php' || !is_user_logged_in()
            || self::current_session_has_2fa() || !AVPVH_Roles::current_user_needs_wp_admin()) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only; each handler checks its nonce
        $action = sanitize_key(wp_unslash($_REQUEST['action'] ?? ''));
        $frontend = (array) apply_filters('avpvh_mbrs_2fa_frontend_ajax_actions', self::FRONTEND_AJAX_ACTIONS);
        if (!in_array($action, $frontend, true)) {
            wp_die(esc_html__('Voor deze actie is tweestapsverificatie (2FA) nodig.', 'avpvh-members'), '', ['response' => 403]);
        }
    }

    /**
     * Prevents a 1FA session (e.g. OAuth login on the front end, which carries
     * a wp_rest nonce) of a WP admin or officer from performing privileged
     * REST writes or reading /wp/v2/users or /wp/v2/settings without 2FA.
     *
     * @return mixed
     */
    public function enforce_step_up_2fa_for_rest($response, array $handler, \WP_REST_Request $request) {
        if (!is_user_logged_in() || self::current_session_has_2fa()) {
            return $response;
        }
        $route = strtolower($request->get_route());
        if (str_starts_with($route, '/avpvh/v1/')) {
            return $response;
        }
        if (in_array($route, self::read_only_rest_routes(), true)) {
            return $response;
        }
        $is_privileged = current_user_can('manage_options')
            || (class_exists('AVPVH_Roles') && AVPVH_Roles::current_user_needs_wp_admin());
        if (!$is_privileged) {
            return $response;
        }
        $method = strtoupper((string) $request->get_method());
        $is_write = !in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
        $is_sensitive_read = (bool) preg_match('#^/wp/v2/(users|settings)(/|$)#i', $route);
        if ($is_write || $is_sensitive_read) {
            return new \WP_Error(
                'avpvh_2fa_required',
                __('Voor deze actie is tweestapsverificatie (2FA) nodig. Open eerst /wp-admin/ om met 2FA in te loggen.', 'avpvh-members'),
                ['status' => 403]
            );
        }
        return $response;
    }

    /**
     * Exact REST routes that use POST but only read, such as
     * avpvh-find-and-view's search proxy (/avpvh-fav/v1/search). Other
     * plugins add theirs with the avpvh_mbrs_2fa_exempt_rest_routes filter;
     * a route that changes anything must never be added. The route's own
     * permission_callback still applies.
     *
     * @return list<string>
     */
    private static function read_only_rest_routes(): array {
        $routes = apply_filters('avpvh_mbrs_2fa_exempt_rest_routes', []);
        if (!is_array($routes)) {
            return [];
        }
        return array_values(array_filter($routes, static fn($r): bool => is_string($r) && preg_match('#^/[a-z0-9_-]+/v[0-9]+/[A-Za-z0-9_/-]+$#D', $r) === 1));
    }

    /**
     * A member's WP user exists for the session, not as a CMS account. Only
     * those with a reason to be there (AVPVH_Roles::current_user_needs_wp_admin())
     * may enter wp-admin; admin-post/AJAX stay open for front-end forms.
     */
    public function restrict_wordpress_admin(): void {
        global $pagenow;
        if (wp_doing_ajax() || in_array($pagenow, ['admin-post.php', 'async-upload.php'], true)) {
            return;
        }
        if (AVPVH_Roles::current_user_needs_wp_admin()) {
            return;
        }
        wp_safe_redirect(home_url('/'));
        exit;
    }

    /**
     * avpvh-theme-fixes hides the toolbar from users without edit_posts.
     * Board members and other plugin-page users no longer have edit_posts
     * (see filter_member_capabilities()) but still reach Ledenbeheer etc.
     * through the toolbar, so show it to them.
     */
    public function filter_admin_bar_visibility(bool $show): bool {
        if (!is_user_logged_in()) {
            return false;
        }
        return $show || AVPVH_Roles::current_user_needs_wp_admin();
    }

    /**
     * The directory group "bloggers" is the single source for writing
     * rights, whatever WP role is stored on a member's WP user. Bloggers get
     * contributor-level rights: their own draft posts only, without
     * publishing, media or pages. WP admins are left alone.
     */
    public function filter_member_capabilities(array $allcaps, array $caps, array $args, \WP_User $user): array {
        if (!empty($allcaps['manage_options'])) {
            return $allcaps;
        }
        // user_has_cap runs for every capability check; look the member and
        // their groups up once per user per request.
        static $is_blogger = [];
        if (!array_key_exists($user->ID, $is_blogger)) {
            $member = avpvh_get_member_by_wp_user((int) $user->ID);
            $is_blogger[$user->ID] = $member ? AVPVH_Roles::member_is_blogger((int) $member->id) : null;
        }
        if ($is_blogger[$user->ID] === null) {
            return $allcaps;
        }

        $content_caps = [
            'edit_posts', 'delete_posts', 'publish_posts',
            'edit_published_posts', 'delete_published_posts',
            'edit_private_posts', 'delete_private_posts', 'read_private_posts',
            'edit_others_posts', 'delete_others_posts',
            'edit_pages', 'delete_pages', 'publish_pages',
            'edit_published_pages', 'delete_published_pages',
            'edit_private_pages', 'delete_private_pages', 'read_private_pages',
            'edit_others_pages', 'delete_others_pages',
            'upload_files', 'manage_categories', 'moderate_comments',
        ];
        foreach ($content_caps as $capability) {
            $allcaps[$capability] = false;
        }

        if ($is_blogger[$user->ID]) {
            $allcaps['edit_posts'] = true;
            $allcaps['delete_posts'] = true;
        }
        return $allcaps;
    }

    public function register_hibp_route(): void {
        register_rest_route('avpvh/v1', '/hibp-flag', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_hibp_flag'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * The Authelia reset page (assets/authelia-custom.js) reports that a
     * member chose a leaked password. Open to anyone, so: at most
     * HIBP_FLAGS_PER_IP per 15 minutes per address, and only an e-mail that
     * belongs to a member is logged - anything else would let a stranger
     * fill Loginpogingen (and make us look up DNS for made-up domains).
     * The answer is the same either way, so it doesn't reveal who's a member.
     */
    public function handle_hibp_flag(\WP_REST_Request $request): \WP_REST_Response {
        $key   = 'avpvh_hibp_' . hash('sha256', sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')));
        $count = (int) get_transient($key);
        if ($count >= self::HIBP_FLAGS_PER_IP) {
            return new \WP_REST_Response(['ok' => false], 429);
        }
        set_transient($key, $count + 1, 15 * MINUTE_IN_SECONDS);

        $email = strtolower(sanitize_email((string) ($request->get_param('email') ?? '')));
        if ($email !== '' && AVPVH_DB::get_member_ids_by_emails([$email])) {
            AVPVH_DB::log_attempt($email, 'password_reset', 'hibp_warned');
        }
        return new \WP_REST_Response(['ok' => true], 200);
    }

    /**
     * Validates a user-supplied post-login redirect target strictly against
     * this site's own host, never allowing externally whitelisted IdP hosts
     * (Google, Microsoft, Authelia) as user-controlled redirect_to targets.
     */
    public static function safe_same_site_redirect(string $redirect_to): string {
        $home = home_url('/');
        if ($redirect_to === '') {
            return $home;
        }
        $target = wp_validate_redirect($redirect_to, $home);
        $home_host = strtolower((string) wp_parse_url($home, PHP_URL_HOST));
        $target_host = strtolower((string) wp_parse_url($target, PHP_URL_HOST));
        return ($target_host === '' || $target_host === $home_host) ? $target : $home;
    }

    // SSO callback after Authelia 1FA password login. Authelia redirects to /avpvh-sso/
    // where auto_login_from_proxy_header() (priority 1) has authenticated the user.
    // This handler (priority 2) redirects the user to their target page or the home page.
    public function handle_sso_callback(): void {
        $path = wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if ($path === null || trim($path, '/') !== 'avpvh-sso') {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $redirect_to = sanitize_text_field(wp_unslash($_GET['redirect_to'] ?? ''));
        $target = (!empty($redirect_to) && is_user_logged_in())
            ? self::safe_same_site_redirect($redirect_to)
            : (is_user_logged_in() ? home_url('/') : home_url(self::LOGIN_PATH));

        wp_safe_redirect($target);
        exit;
    }

    // Logged-in accounts without member access see an explanation on the
    // login page; other authenticated visits redirect to their target or home.
    public function handle_login_bridge(): void {
        if (is_page('avpvh-login')) {
            if (is_user_logged_in()) {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presentation only; membership is checked separately
                $notice = sanitize_key(wp_unslash($_GET['login_notice'] ?? ''));
                if ($notice === 'members_only' && !$this->current_user_is_active_member()) {
                    return;
                }
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $redirect_to = sanitize_text_field(wp_unslash($_GET['redirect_to'] ?? ''));
                $target = self::safe_same_site_redirect($redirect_to);
                wp_safe_redirect($target);
                exit;
            }
            return;
        }

        // Redirect non-members away from members-only content
        if ($this->is_members_only_request() && !$this->current_user_is_active_member() && !$this->archive_allows_current_post()) {
            $target = home_url(self::LOGIN_PATH);
            if (is_user_logged_in()) {
                $target = add_query_arg('login_notice', 'members_only', $target);
            }
            wp_safe_redirect($target);
            exit;
        }

        $this->redirect_dmarc_page();
        $this->redirect_boek_page();
    }

    /** A blog post within the period an ex-member may see (AVPVH_Archive_Access). */
    private function archive_allows_current_post(): bool {
        $post = get_queried_object();
        return is_single() && $post instanceof \WP_Post && $post->post_type === 'post'
            && AVPVH_Archive_Access::current_scope() !== AVPVH_Archive_Access::NONE
            && AVPVH_Archive_Access::post_allowed($post);
    }

    // dmarc-reports.py (docker-scripts) publishes its report as a page
    // under /leden/; it is shown in wp-admin under DMARC-rapporten, so
    // the page itself is never shown, not even to ordinary members.
    private function redirect_dmarc_page(): void {
        $dmarc_page_id = $this->dmarc_page_id();
        if ($dmarc_page_id && is_page($dmarc_page_id)) {
            wp_safe_redirect(AVPVH_Roles::can_view_dmarc()
                ? admin_url('admin.php?page=avpvh-dmarc')
                : home_url('/'));
            exit;
        }
    }

    // Server-side enforcement for "Zoeken in documenten" (group:boek) so it
    // does not depend solely on Authelia's URL regex.
    private function redirect_boek_page(): void {
        $doc_page_id = $this->doc_search_page_id();
        if ($doc_page_id && is_page($doc_page_id) && !$this->current_user_has_boek_access()) {
            wp_safe_redirect(is_user_logged_in() ? home_url('/') : home_url(self::LOGIN_PATH));
            exit;
        }
    }

    private function dmarc_page_id(): int {
        if (!defined('AVPVH_DMARC_PAGE_OPTION') || !function_exists('get_option')) {
            return 0;
        }
        return (int) get_option(AVPVH_DMARC_PAGE_OPTION);
    }

    private function doc_search_page_id(): int {
        return class_exists('AVPVH_Nav_Auth') ? (int) AVPVH_Nav_Auth::DOC_SEARCH_PAGE_ID : 0;
    }

    private function current_user_has_boek_access(): bool {
        if (current_user_can('manage_options')) {
            return true;
        }
        if (!is_user_logged_in()) {
            return false;
        }
        $member = avpvh_get_member_by_wp_user(get_current_user_id());
        if (!$member || ($member->status ?? '') !== 'active' || empty($member->user_id)) {
            return false;
        }
        return class_exists('AVPVH_Directory')
            && in_array('boek', AVPVH_Directory::cached_user_groups($member->user_id), true);
    }

    /**
     * Page IDs the current viewer must never see in search, feeds, REST,
     * oEmbed or comments: /leden/ for non-members, the DMARC report page for
     * anyone without DMARC permission, and the boek search page for non-boek users.
     *
     * @return int[]
     */
    private function restricted_page_ids_for_current_user(): array {
        $ids = $this->current_user_may_read_members_section() ? [] : $this->members_section_ids();
        $dmarc_id = $this->dmarc_page_id();
        if ($dmarc_id && !(class_exists('AVPVH_Roles') && AVPVH_Roles::can_view_dmarc())) {
            $ids[] = $dmarc_id;
        }
        $doc_id = $this->doc_search_page_id();
        if ($doc_id && !$this->current_user_has_boek_access()) {
            $ids[] = $doc_id;
        }
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    private function is_password_protected_post(int $post_id): bool {
        if ($post_id <= 0 || !function_exists('get_post')) {
            return false;
        }
        $post = get_post($post_id);
        return $post instanceof \WP_Post && !empty($post->post_password);
    }

    /**
     * Applies excluded IDs safely to WP_Query args: when post__in is present,
     * WP_Query ignores post__not_in completely, so excluded IDs must be
     * subtracted from post__in (falling back to [0] for no matches).
     */
    private function apply_exclusions_to_args(array $args, array $excluded): array {
        if (!$excluded) {
            return $args;
        }
        $in = array_values(array_filter(array_map('intval', (array) ($args['post__in'] ?? []))));
        if ($in) {
            $remaining = array_values(array_diff($in, $excluded));
            $args['post__in'] = $remaining ?: [0];
            return $args;
        }
        $args['post__not_in'] = array_values(array_unique(array_merge(
            array_values(array_filter(array_map('intval', (array) ($args['post__not_in'] ?? [])))),
            $excluded
        )));
        return $args;
    }

    private function is_members_only_request(): bool {
        // All individual posts are members-only
        if (is_single()) {
            return true;
        }

        // Author archives are members-only
        if (is_author()) {
            return true;
        }

        // Profile and balance shortcodes enforce access to the viewer's
        // own data. Their page may live under /leden/ and have a password,
        // but it must also be reachable by authenticated visitors.
        if (is_page() && is_user_logged_in() && $this->is_personal_account_page()) {
            return false;
        }

        // Password-protected pages are members-only
        if (is_page() && is_singular() && post_password_required()) {
            return true;
        }

        // Pages in the members section (/leden/ and descendants)
        if (is_page()) {
            $leden_roots = get_posts([
                'name'        => 'leden',
                'post_type'   => 'page',
                'post_status' => 'publish',
                'numberposts' => -1,
                'fields'      => 'ids',
            ]);
            $members_ids = [];
            foreach ($leden_roots as $root_id) {
                $children    = get_pages(['child_of' => $root_id, 'post_status' => 'publish']);
                $members_ids = array_merge($members_ids, wp_list_pluck($children, 'ID'), [$root_id]);
            }
            if (!empty($members_ids) && is_page($members_ids)) {
                return true;
            }
        }

        return false;
    }

    /** IDs of every page called 'leden' and all their descendants. */
    private function members_section_ids(): array {
        if ($this->section !== null) {
            return $this->section;
        }
        // The lookups below run pre_get_posts again; they see an empty list
        // instead of recursing.
        $this->section = [];
        $found = [];
        $roots = get_posts([
            'name'             => 'leden',
            'post_type'        => 'page',
            'post_status'      => ['publish', 'private', 'draft', 'pending', 'future'],
            'numberposts'      => -1,
            'fields'           => 'ids',
        ]);
        foreach ($roots as $root_id) {
            $children = get_pages(['child_of' => $root_id, 'post_status' => 'publish,private,draft,pending,future']);
            $found = array_merge($found, [(int) $root_id], array_map('intval', wp_list_pluck($children, 'ID')));
        }
        $this->section = array_values(array_unique($found));
        return $this->section;
    }

    /**
     * Who may see the members section outside the front-end page itself:
     * active members, and editors (the block editor reads pages over REST).
     */
    private function current_user_may_read_members_section(): bool {
        return $this->current_user_is_active_member() || current_user_can('edit_pages');
    }

    /**
     * Search and feeds leave the members section out for everyone else,
     * and search also password-protected posts and pages, whose titles are
     * members-only too. Other queries are left alone: the plugin itself
     * looks up the members section with get_posts().
     */
    public function exclude_members_pages_from_queries(\WP_Query $query): void {
        if (is_admin() && !wp_doing_ajax() || !$query->is_search() && !$query->is_feed()) {
            return;
        }
        $excluded = $this->restricted_page_ids_for_current_user();
        if ($excluded) {
            $in = array_values(array_filter(array_map('intval', (array) $query->get('post__in'))));
            if ($in) {
                $remaining = array_values(array_diff($in, $excluded));
                $query->set('post__in', $remaining ?: [0]);
            } else {
                $query->set('post__not_in', array_values(array_unique(array_merge(
                    array_values(array_filter(array_map('intval', (array) $query->get('post__not_in')))),
                    $excluded
                ))));
            }
        }
        if ($query->is_search() && !$this->current_user_may_read_members_section()) {
            $query->set('has_password', false);
        }
    }

    /** The same for REST collections: /wp/v2/pages and /wp/v2/search. */
    public function exclude_members_pages_from_rest_query(array $args): array {
        $excluded = $this->restricted_page_ids_for_current_user();
        if ($excluded) {
            $args = $this->apply_exclusions_to_args($args, $excluded);
        }
        if (!$this->current_user_may_read_members_section()) {
            $args['has_password'] = false;
        }
        return $args;
    }

    /** Hides comments on restricted pages and password-protected posts over REST. */
    public function exclude_restricted_comments_from_rest_query(array $args): array {
        $excluded = $this->restricted_page_ids_for_current_user();
        if (!$this->current_user_may_read_members_section()) {
            $excluded = array_values(array_unique(array_merge($excluded, $this->password_protected_post_ids())));
        }
        if (!$excluded) {
            return $args;
        }
        if (!empty($args['post_id']) && in_array((int) $args['post_id'], $excluded, true)) {
            unset($args['post_id']);
            $args['post__in'] = [0];
            return $args;
        }
        return $this->apply_exclusions_to_args($args, $excluded);
    }

    /** Hides comments on restricted pages and password-protected posts in feeds/front-end queries. */
    public function exclude_restricted_comments_from_clauses(array $clauses): array {
        if ((is_admin() && !wp_doing_ajax()) || wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
            return $clauses;
        }
        global $wpdb;
        if (!$wpdb) {
            return $clauses;
        }
        $clauses['where'] = trim($clauses['where'] ?? '') ?: '1=1';
        $excluded = $this->restricted_page_ids_for_current_user();
        if ($excluded) {
            $id_list = implode(',', array_map('intval', $excluded));
            $clauses['where'] .= " AND {$wpdb->comments}.comment_post_ID NOT IN ({$id_list})";
        }
        if (!$this->current_user_may_read_members_section()) {
            $clauses['where'] .= " AND {$wpdb->comments}.comment_post_ID NOT IN (SELECT ID FROM {$wpdb->posts} WHERE post_password != '')";
        }
        return $clauses;
    }

    /** @return int[] */
    private function password_protected_post_ids(): array {
        global $wpdb;
        if (!$wpdb) {
            return [];
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- short query for REST comment filtering
        $ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_password != ''");
        return array_map('intval', $ids ?: []);
    }

    /**
     * A restricted page over REST (/wp/v2/pages/<id> and its sub-routes)
     * answers 404, as if it did not exist. Checked before the callback runs:
     * a WP_Error from rest_prepare_page breaks the controller.
     *
     * @return mixed the unchanged response, or a WP_Error
     */
    public function hide_members_page_from_rest($response, array $handler, \WP_REST_Request $request) { // NOSONAR -- $handler: the filter passes it before $request
        $route = strtolower($request->get_route());
        if (preg_match('#^/wp/v2/media/(\d+)(?:/|$)#i', $route, $media)
            && !$this->current_user_may_read_members_section()
            && !str_starts_with((string) get_post_meta((int) $media[1], '_wp_attached_file', true), 'public/')) {
            return new \WP_Error('rest_forbidden', __('Geen toegang.', 'avpvh-members'), ['status' => 403]);
        }
        if (!preg_match('#^/wp/v2/pages/(\d+)(?:/|$)#i', $route, $match)) {
            return $response;
        }
        $page_id = (int) $match[1];
        $blocked = $this->post_is_restricted($page_id);
        if ($blocked) {
            return new \WP_Error('rest_post_invalid_id', __('Ongeldige bericht-ID.', 'avpvh-members'), ['status' => 404]);
        }
        return $response;
    }

    public function post_is_restricted(int $post_id): bool {
        return in_array($post_id, $this->restricted_page_ids_for_current_user(), true)
            || (!$this->current_user_may_read_members_section() && $this->is_password_protected_post($post_id));
    }

    /** Public REST media listings may expose only files deliberately published to public/. */
    public function filter_public_media_query(array $args): array {
        if (!$this->current_user_may_read_members_section()) {
            $public = ['key' => '_wp_attached_file', 'value' => '^public/', 'compare' => 'REGEXP'];
            $args['meta_query'] = empty($args['meta_query'])
                ? [$public]
                : ['relation' => 'AND', $args['meta_query'], $public];
        }
        return $args;
    }

    /** oEmbed would otherwise give away a restricted or password-protected page's title. */
    public function hide_members_page_from_oembed($post_id) {
        $id = (int) $post_id;
        if (in_array($id, $this->restricted_page_ids_for_current_user(), true)
            || (!$this->current_user_may_read_members_section() && $this->is_password_protected_post($id))) {
            return 0;
        }
        return $post_id;
    }

    private function is_personal_account_page(?\WP_Post $post = null): bool {
        $post = $post ?? get_post();
        return $post instanceof \WP_Post && $post->post_type === 'page'
            && (has_shortcode($post->post_content, 'avpvh_member_profile')
                || has_shortcode($post->post_content, 'avpvh_bk_balance'));
    }

    private function current_user_is_active_member(): bool {
        if (!is_user_logged_in()) {
            return false;
        }
        $member = avpvh_get_member_by_wp_user(get_current_user_id());
        return $member && $member->status === 'active';
    }

    public function inject_login_form(string $content): string {
        if (!is_page('avpvh-login')) {
            return $content;
        }
        if (is_user_logged_in()) {
            return $this->current_user_is_active_member() ? $content : $this->render_members_only_notice();
        }

        // Pass the 1FA SSO target as the 'rd' redirection URL so Authelia only requires
        // 1FA (username/password). Step-up to 2FA is only triggered on protected areas like /wp-admin/.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $redirect_to = sanitize_text_field(wp_unslash($_GET['redirect_to'] ?? ''));
        $sso_target  = home_url('/avpvh-sso/');
        if (!empty($redirect_to)) {
            $sso_target = add_query_arg('redirect_to', rawurlencode($redirect_to), $sso_target);
        }
        $authelia_url = add_query_arg('rd', rawurlencode($sso_target), 'https://auth.avphilipsvanhorne.nl/');

        $providers  = AVPVH_OAuth::configured_providers();
        $login_urls = [];
        foreach ($providers as $key => $provider) {
            $login_urls[$key] = AVPVH_OAuth::login_url($key, $redirect_to);
        }

        wp_enqueue_script(
            'avpvh-login-form',
            plugin_dir_url(dirname(__FILE__)) . 'assets/login-form.js',
            [], avpvh_asset_version('assets/login-form.js'), true
        );

        $login_config = wp_json_encode([
            'autheliaUrl'    => $authelia_url,
            'loginUrls'      => $login_urls,
            'hasGoogle'      => isset($providers['google']),
            'hasMicrosoft'   => isset($providers['microsoft']),
            'labels'         => [
                'google'    => __('Inloggen met Google', 'avpvh-members'),
                'microsoft' => __('Inloggen met Microsoft', 'avpvh-members'),
                'password'  => __('Inloggen met wachtwoord', 'avpvh-members'),
            ],
        ]);
        add_action('wp_footer', function () use ($login_config) {
            // wp_print_inline_script_tag() (WP core, 5.7+) is the standard
            // way to output raw JSON/JS in a <script> tag — it escapes a
            // literal "</script>" in the content so it can't prematurely
            // close the tag, which a plain echo of json_encode() output
            // doesn't guard against.
            wp_print_inline_script_tag($login_config, ['type' => 'application/json', 'id' => 'avpvh-login-config']);
        });

        // Note: intentionally not named "error" — that collides with a
        // WordPress core reserved public query var and gets silently
        // stripped from $_GET before this filter ever runs.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $error = sanitize_key(wp_unslash($_GET['login_error'] ?? ''));

        $error_messages = [
            'no_member'       => __('Het gebruikte account is niet gekoppeld aan een lid. Probeer het opnieuw met het e-mailadres waarmee je bij de vereniging geregistreerd staat.', 'avpvh-members'),
            'oauth_expired'   => __('Je inlogpoging duurde te lang en is verlopen. Probeer het opnieuw.', 'avpvh-members'),
            'oauth_failed'    => __('Inloggen is niet gelukt. Probeer het opnieuw.', 'avpvh-members'),
            'session_expired' => __('Je sessie is verlopen na 24 uur inactiviteit. Log opnieuw in.', 'avpvh-members'),
            'identity_mismatch' => __('Je was tegelijk met twee verschillende accounts ingelogd. Beide sessies zijn beëindigd; log opnieuw in.', 'avpvh-members'),
        ];

        ob_start();
        ?>
        <div class="avpvh-login-page">
            <?php if (isset($error_messages[$error])): ?>
            <p class="avpvh-login-error"><?php echo esc_html($error_messages[$error]); ?></p>
            <?php endif; ?>
            <p class="avpvh-login-intro"><?php echo esc_html__('Je kunt inloggen met een e-mailadres dat bekend is bij de vereniging. Gebruik je datzelfde e-mailadres ook bij Google of Microsoft, dan kun je direct zonder apart wachtwoord inloggen. Je kunt ook inloggen met je gebruikersnaam en wachtwoord. Pas wanneer je naar een beheerderspagina gaat (bijv. om te bloggen), wordt om een extra tweestapsverificatie (2FA) gevraagd.', 'avpvh-members'); ?></p>
            <div class="avpvh-login-options" id="avpvh-login-options"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function auto_login_from_proxy_header(): void {
        // Never bootstrap a WP session from proxy headers on REST or XML-RPC:
        // REST endpoints authenticate via WP cookies + wp_rest nonce (setting
        // the current user at init priority 1 would bypass REST CSRF checks).
        if ((defined('REST_REQUEST') && REST_REQUEST) || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)) {
            return;
        }
        $req_path = (string) wp_parse_url(sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '')), PHP_URL_PATH);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing detection only; REST performs authentication and nonce checks
        if (isset($_GET['rest_route']) || isset($_POST['rest_route']) || preg_match('#/wp-json(?:/|$)#i', $req_path)) {
            return;
        }

        // Optional defense-in-depth shared secret between Nginx and PHP-FPM
        // (define AVPVH_PROXY_SECRET in wp-config.php and pass X-AVPVH-Proxy-Secret
        // from Nginx) so a direct request to PHP-FPM can never spoof Remote-User.
        if (defined('AVPVH_PROXY_SECRET') && AVPVH_PROXY_SECRET !== '') {
            $sent = (string) ($_SERVER['HTTP_X_AVPVH_PROXY_SECRET'] ?? '');
            if (!hash_equals((string) AVPVH_PROXY_SECRET, $sent)) {
                return;
            }
        }

        // Authelia sends Remote-User = the OpenLDAP uid (e.g. "jan.jansen"), not an email.
        // nginx passes it via fastcgi_param HTTP_REMOTE_USER → $_SERVER['HTTP_REMOTE_USER'].
        $lldap_uid = sanitize_text_field(wp_unslash($_SERVER['HTTP_REMOTE_USER'] ?? ''));
        if (!$lldap_uid) {
            return;
        }

        $member = AVPVH_DB::get_member_by_lldap_uid($lldap_uid);
        if (!$member) {
            // Refresh this one account once when not in the local cache yet,
            // so a brand-new account works without waiting for the 15-minute sync.
            AVPVH_Directory_Cache::refresh_user($lldap_uid);
            $member = AVPVH_DB::get_member_by_lldap_uid($lldap_uid);
            if (!$member) {
                return;
            }
        }

        $is_2fa_route = $this->is_wp_admin_2fa_request();

        if (is_user_logged_in()) {
            if ((int) get_current_user_id() === (int) $member->wp_user_id) {
                if ($is_2fa_route) {
                    self::upgrade_current_session_2fa();
                }
                return;
            }
            // The WP auth cookie and the Authelia cookie (HTTP_REMOTE_USER,
            // set by nginx) name different accounts in one request. Never
            // silently switch the WP session to the other account: end both
            // sessions and make the user log in again.
            AVPVH_Nav_Auth::remember_signed_out_identities();
            wp_logout();
            AVPVH_Nav_Auth::invalidate_authelia_session();
            wp_safe_redirect(AVPVH_Nav_Auth::authelia_logout_url(
                home_url('/avpvh-login/?login_error=identity_mismatch')
            ));
            exit;
        }

        // Refresh the user's cache entry before creating a new session so
        // recently changed mail/displayName is up to date.
        AVPVH_Directory_Cache::refresh_user($lldap_uid);
        $member = AVPVH_DB::get_member_by_lldap_uid($lldap_uid);
        if (!$member) {
            return;
        }

        $email = $member->email;
        AVPVH_DB::sync_primary_email_identity((int) $member->id, $email);

        // Provision a WP user on first login — it exists solely so WordPress
        // can maintain a session. Members never set a WP password.
        if ($member->wp_user_id) {
            $user = get_user_by('id', (int) $member->wp_user_id);
        } else {
            $user = get_user_by('email', $email);
            if (!$user) {
                $uid = wp_create_user(
                    sanitize_user($lldap_uid),
                    wp_generate_password(64),
                    $email
                );
                if (is_wp_error($uid)) {
                    return;
                }
                wp_update_user([
                    'ID'           => $uid,
                    'display_name' => avpvh_format_name($member),
                    // Writing rights come from the bloggers group, not
                    // the stored role (see filter_member_capabilities()).
                    'role'         => 'subscriber',
                ]);
                AVPVH_DB::set_wp_user_id((int) $member->id, $uid);
                $user = get_user_by('id', $uid);
            } else {
                if (!AVPVH_DB::can_safely_link_wp_user($user, (int) $member->id)) {
                    error_log("AVPVH_Access: refused unsafe auto-link of WP user {$user->ID} to member {$member->id}."); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operational security refusal log
                    return;
                }
                AVPVH_DB::set_wp_user_id((int) $member->id, $user->ID);
            }
        }

        if (!$user) {
            return;
        }

        AVPVH_DB::log_attempt($email, 'proxy', 'success');
        self::mark_next_session_auth('proxy', $is_2fa_route);
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID);
        do_action('wp_login', $user->user_login, $user); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- deliberately firing WP core's own wp_login hook (not a custom hook) so other code listening for a normal login still runs on this proxy/OAuth auth bridge
    }

    // Forces a logout after 24h of inactivity, independent of the auth
    // cookie's own (much longer) technical expiration — "activity" means
    // any request while logged in, so this is a sliding window, not a
    // fixed time-since-login cutoff.
    public function enforce_session_idle_timeout(): void {
        if (!is_user_logged_in()) {
            return;
        }

        $user_id       = get_current_user_id();
        $now           = time();
        $last_activity = (int) get_user_meta($user_id, 'avpvh_last_activity', true);

        if ($last_activity && ($now - $last_activity) > DAY_IN_SECONDS) {
            // Must clear this before wp_logout(): if the underlying identity
            // provider (Authelia proxy header, or a still-valid OAuth
            // session) immediately re-authenticates the user on the very
            // next request, a stale timestamp here would trip this same
            // branch again right away — an infinite logout redirect loop.
            delete_user_meta($user_id, 'avpvh_last_activity');
            wp_logout();
            wp_safe_redirect(home_url('/avpvh-login/?login_error=session_expired'));
            exit;
        }

        // Throttle: only write on the first request of a "session" or once
        // every 5 minutes, not on every single page load.
        if (!$last_activity || ($now - $last_activity) > 5 * MINUTE_IN_SECONDS) {
            update_user_meta($user_id, 'avpvh_last_activity', $now);
        }
    }

    // A stale avpvh_last_activity timestamp can survive a normal end-of-session
    // (only the forced timeout logout above clears it). Without this, a fresh
    // login after such a gap would trip enforce_session_idle_timeout() on the
    // very next request, immediately logging the user back out.
    public function reset_session_idle_timer(string $user_login, \WP_User $user): void {
        update_user_meta($user->ID, 'avpvh_last_activity', time());
    }

    public function bypass_for_active_member(bool $required, \WP_Post $post): bool {
        if (!is_user_logged_in()) {
            return $required;
        }
        if ($this->is_personal_account_page($post)) {
            return false;
        }
        $member = avpvh_get_member_by_wp_user(get_current_user_id());
        if ($member && $member->status === 'active') {
            return false;
        }
        // An ex-member's own years, with the board's permission.
        if ($post->post_type === 'post' && $member && $member->status === 'inactive'
            && is_array(AVPVH_Archive_Access::scope_for_member($member)) && AVPVH_Archive_Access::post_allowed($post)) {
            return false;
        }
        return $required;
    }

    public function clean_protected_title(string $format): string {
        $member = is_user_logged_in()
            ? avpvh_get_member_by_wp_user(get_current_user_id())
            : null;
        return ($member && $member->status === 'active')
            || (is_user_logged_in() && $this->is_personal_account_page()) ? '%s' : $format;
    }

    public function members_only_form(string $form): string {
        if (is_user_logged_in()) {
            return $this->render_members_only_notice();
        }
        return '<div class="avpvh-members-only">
            <p>' . esc_html__('Deze pagina is alleen beschikbaar voor leden.', 'avpvh-members') . '</p>
            <a class="avpvh-login-btn" href="' . esc_url(home_url(self::LOGIN_PATH)) . '">' . esc_html__('Inloggen', 'avpvh-members') . '</a>
        </div>';
    }

    private function render_members_only_notice(): string {
        $member = avpvh_get_member_by_wp_user(get_current_user_id());
        $user = wp_get_current_user();
        $name = $member ? avpvh_format_name($member) : $user->display_name;

        return '<div class="avpvh-members-only">'
            . '<p><strong>' . esc_html__('Ingelogd als:', 'avpvh-members') . '</strong> ' . esc_html($name) . '</p>'
            . '<p>' . esc_html__('Je bent ingelogd, maar je account heeft geen toegang tot deze pagina. Deze pagina is alleen beschikbaar voor actieve leden.', 'avpvh-members') . '</p>'
            . '<a class="avpvh-login-btn" href="' . esc_url(AVPVH_Nav_Auth::profile_url()) . '">' . esc_html__('Mijn profiel', 'avpvh-members') . '</a>'
            . '<a href="' . esc_url(wp_logout_url()) . '">' . esc_html__('Uitloggen', 'avpvh-members') . '</a>'
            . '</div>';
    }

    public function ex_member_notice(string $content): string {
        if (!is_user_logged_in() || is_page('avpvh-login') || $this->is_personal_account_page()) {
            return $content;
        }
        $member = avpvh_get_member_by_wp_user(get_current_user_id());
        if ($member && $member->status === 'inactive') {
            return '<div class="avpvh-notice">' . esc_html__('Je lidmaatschap is beëindigd. Neem contact op met het bestuur.', 'avpvh-members') . '</div>';
        }
        return $content;
    }

    public function noindex_members_content(): void {
        // Always noindex author archives and individual posts (all members-only)
        if (is_author() || is_single()) {
            echo '<meta name="robots" content="noindex, nofollow">' . "\n";
            return;
        }

        if (!is_page()) {
            return;
        }

        $should_noindex = is_page(['avpvh-login']);

        if (!$should_noindex) {
            $leden_roots = get_posts([
                'name'        => 'leden',
                'post_type'   => 'page',
                'post_status' => 'publish',
                'numberposts' => -1,
                'fields'      => 'ids',
            ]);
            $members_ids = [];
            foreach ($leden_roots as $root_id) {
                $children     = get_pages(['child_of' => $root_id, 'post_status' => 'publish']);
                $members_ids  = array_merge($members_ids, wp_list_pluck($children, 'ID'), [$root_id]);
            }
            $should_noindex = !empty($members_ids) && is_page($members_ids);
        }

        if ($should_noindex) {
            echo '<meta name="robots" content="noindex, nofollow">' . "\n";
        }
    }

    public function sitemap_exclude_protected(array $args): array {
        $args['has_password'] = false;

        // Exclude all pages with slug 'leden' (may be multiple) and their descendants
        $leden_pages = get_posts([
            'name'        => 'leden',
            'post_type'   => 'page',
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields'      => 'ids',
        ]);
        $leden_pages[] = get_page_by_path('avpvh-login') ? get_page_by_path('avpvh-login')->ID : 0;

        $exclude = (array) ($args['post__not_in'] ?? []);
        foreach (array_filter($leden_pages) as $root_id) {
            $children = get_pages(['child_of' => $root_id, 'post_status' => 'publish']);
            $exclude   = array_merge($exclude, wp_list_pluck($children, 'ID'), [$root_id]);
        }
        $args['post__not_in'] = $exclude;

        return $args;
    }

    public function sitemap_remove_users($provider, string $name) {
        return in_array($name, ['users', 'taxonomies'], true) ? false : $provider;
    }

    public function sitemap_remove_post_type(array $post_types): array {
        unset($post_types['post']);
        return $post_types;
    }
}
