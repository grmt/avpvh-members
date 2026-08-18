<?php
defined('ABSPATH') || exit;

class AVPVH_Access {

    public function __construct() {
        add_action('init',               [$this, 'auto_login_from_proxy_header'], 1);
        add_action('init',               [$this, 'enforce_session_idle_timeout'], 1);
        add_action('wp_login',           [$this, 'reset_session_idle_timer'], 10, 2);
        add_action('template_redirect',  [$this, 'handle_login_bridge']);
        add_filter('the_content',        [$this, 'inject_login_form'], 5);
        add_filter('post_password_required', [$this, 'bypass_for_active_member'], 10, 2);
        add_filter('the_content',          [$this, 'ex_member_notice']);
        add_filter('protected_title_format', [$this, 'clean_protected_title']);
        add_filter('the_password_form',    [$this, 'members_only_form']);
        add_action('rest_api_init',        [$this, 'register_hibp_route']);

        add_action('wp_head',                      [$this, 'noindex_members_content']);
        add_filter('wp_sitemaps_posts_query_args', [$this, 'sitemap_exclude_protected']);
        add_filter('wp_sitemaps_add_provider',     [$this, 'sitemap_remove_users'], 10, 2);
        add_filter('wp_sitemaps_post_types',       [$this, 'sitemap_remove_post_type']);
        add_filter('wp_sitemaps_taxonomies',       '__return_empty_array');
    }

    public function register_hibp_route(): void {
        register_rest_route('avpvh/v1', '/hibp-flag', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_hibp_flag'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle_hibp_flag(\WP_REST_Request $request): \WP_REST_Response {
        $email = sanitize_email($request->get_param('email') ?? '');
        AVPVH_DB::log_attempt($email ?: 'onbekend', 'password_reset', 'hibp_warned');
        return new \WP_REST_Response(['ok' => true], 200);
    }

    // Called on the /avpvh-login/ page. If already logged in (via proxy header or
    // OAuth), redirect to home. Otherwise let inject_login_form render the form.
    // Also redirects non-members away from member-only pages and posts.
    public function handle_login_bridge(): void {
        if (is_page('avpvh-login')) {
            if (is_user_logged_in()) {
                wp_redirect(home_url('/'));
                exit;
            }
            return;
        }

        // Redirect non-members away from members-only content
        if ($this->is_members_only_request() && !$this->current_user_is_active_member()) {
            wp_redirect(home_url('/avpvh-login/'));
            exit;
        }
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

    private function current_user_is_active_member(): bool {
        if (!is_user_logged_in()) {
            return false;
        }
        $member = avpvh_get_member_by_wp_user(get_current_user_id());
        return $member && $member->status === 'active';
    }

    public function inject_login_form(string $content): string {
        if (!is_page('avpvh-login') || is_user_logged_in()) {
            return $content;
        }

        $providers  = AVPVH_OAuth::configured_providers();
        $login_urls = [];
        foreach ($providers as $key => $provider) {
            $login_urls[$key] = AVPVH_OAuth::login_url($key);
        }

        wp_enqueue_script(
            'avpvh-login-form',
            plugin_dir_url(dirname(__FILE__)) . 'assets/login-form.js',
            [], avpvh_asset_version('assets/login-form.js'), true
        );

        $login_config = wp_json_encode([
            'autheliaUrl'    => 'https://auth.avphilipsvanhorne.nl',
            'loginUrls'      => $login_urls,
            'hasGoogle'      => isset($providers['google']),
            'hasMicrosoft'   => isset($providers['microsoft']),
        ]);
        add_action('wp_footer', function () use ($login_config) {
            echo '<script type="application/json" id="avpvh-login-config">' . $login_config . '</script>';
        });

        // Note: intentionally not named "error" — that collides with a
        // WordPress core reserved public query var and gets silently
        // stripped from $_GET before this filter ever runs.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $error = sanitize_key($_GET['login_error'] ?? '');

        $error_messages = [
            'no_member'      => 'Het gebruikte account is niet gekoppeld aan een lid. Probeer het opnieuw met het e-mailadres waarmee je bij de vereniging geregistreerd staat.',
            'oauth_expired'  => 'Je inlogpoging duurde te lang en is verlopen. Probeer het opnieuw.',
            'oauth_failed'   => 'Inloggen is niet gelukt. Probeer het opnieuw.',
            'session_expired' => 'Je sessie is verlopen na 24 uur inactiviteit. Log opnieuw in.',
        ];

        ob_start();
        ?>
        <div class="avpvh-login-page">
            <?php if (isset($error_messages[$error])): ?>
            <p class="avpvh-login-error"><?php echo esc_html($error_messages[$error]); ?></p>
            <?php endif; ?>
            <p class="avpvh-login-intro">Je kunt alleen inloggen met een e-mailadres dat bekend is bij de vereniging. Gebruik je datzelfde e-mailadres ook elders, dan vertrouwt deze website dat ook wanneer je het laat valideren door Google of Microsoft. Heb je speciale rechten (bijv. bloggen), dan moet je een extra 2-staps verificatieprocedure doorlopen via &ldquo;Inloggen met wachtwoord&rdquo;.</p>
            <div class="avpvh-login-options" id="avpvh-login-options"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function auto_login_from_proxy_header(): void {
        if (is_user_logged_in()) {
            return;
        }

        // Authelia sends Remote-User = the LLDAP uid (e.g. "jan.jansen"), not an email.
        // nginx passes it via fastcgi_param HTTP_REMOTE_USER → $_SERVER['HTTP_REMOTE_USER'].
        $lldap_uid = sanitize_text_field(wp_unslash($_SERVER['HTTP_REMOTE_USER'] ?? ''));
        if (!$lldap_uid) {
            return;
        }

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
                    'role'         => 'contributor',
                ]);
                AVPVH_DB::set_wp_user_id((int) $member->id, $uid);
                $user = get_user_by('id', $uid);
            } else {
                AVPVH_DB::set_wp_user_id((int) $member->id, $user->ID);
            }
        }

        if (!$user) {
            return;
        }

        AVPVH_DB::log_attempt($email, 'proxy', 'success');
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID);
        do_action('wp_login', $user->user_login, $user);
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
            wp_redirect(home_url('/avpvh-login/?login_error=session_expired'));
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
        $member = avpvh_get_member_by_wp_user(get_current_user_id());
        return ($member && $member->status === 'active') ? false : $required;
    }

    public function clean_protected_title(string $format): string {
        $member = is_user_logged_in()
            ? avpvh_get_member_by_wp_user(get_current_user_id())
            : null;
        return ($member && $member->status === 'active') ? '%s' : $format;
    }

    public function members_only_form(string $form): string {
        if (is_user_logged_in()) {
            return $form;
        }
        return '<div class="avpvh-members-only">
            <p>Deze pagina is alleen beschikbaar voor leden.</p>
            <a class="avpvh-login-btn" href="' . esc_url(home_url('/avpvh-login/')) . '">Inloggen</a>
        </div>';
    }

    public function ex_member_notice(string $content): string {
        if (!is_user_logged_in()) {
            return $content;
        }
        $member = avpvh_get_member_by_wp_user(get_current_user_id());
        if ($member && $member->status === 'inactive') {
            return '<div class="avpvh-notice">Je lidmaatschap is beëindigd. Neem contact op met het bestuur.</div>';
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
