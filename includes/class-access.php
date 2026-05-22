<?php
defined('ABSPATH') || exit;

class AVPVH_Access {

    public function __construct() {
        add_action('init',               [$this, 'auto_login_from_proxy_header'], 1);
        add_action('template_redirect',  [$this, 'handle_login_bridge']);
        add_filter('the_content',        [$this, 'inject_login_form'], 5);
        add_filter('post_password_required', [$this, 'bypass_for_active_member'], 10, 2);
        add_filter('the_content',          [$this, 'ex_member_notice']);
        add_filter('protected_title_format', [$this, 'clean_protected_title']);
        add_filter('the_password_form',    [$this, 'members_only_form']);
    }

    // Called on the /avpvh-login/ page. If already logged in (via proxy header or
    // OAuth), redirect to home. Otherwise let inject_login_form render the form.
    public function handle_login_bridge(): void {
        if (!is_page('avpvh-login')) {
            return;
        }
        if (is_user_logged_in()) {
            wp_redirect(home_url('/'));
            exit;
        }
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
            [], '1.0', true
        );

        $login_config = wp_json_encode([
            'autheliaUrl'  => 'https://auth.avphilipsvanhorne.nl',
            'loginUrls'    => $login_urls,
            'hasGoogle'    => isset($providers['google']),
            'hasMicrosoft' => isset($providers['microsoft']),
        ]);
        add_action('wp_footer', function () use ($login_config) {
            echo '<script type="application/json" id="avpvh-login-config">' . $login_config . '</script>';
        });

        ob_start();
        ?>
        <div class="avpvh-login-page">
            <p class="avpvh-login-intro">Gebruik het e-mailadres waarmee u bij de vereniging geregistreerd staat. Als u met dat adres bij Google of Microsoft inlogt, hoeft u hier geen wachtwoord in te tikken. Kent Google of Microsoft u niet met dit e-mailadres, kies dan &ldquo;Inloggen met wachtwoord&rdquo; om de eerste keer een wachtwoord aan te maken.</p>
            <div class="avpvh-login-options" id="avpvh-login-options"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function auto_login_from_proxy_header(): void {
        if (is_user_logged_in()) {
            return;
        }

        // Authelia sends Remote-User = the LLDAP uid (e.g. "garmt.boekholt"), not an email.
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
                    'display_name' => trim($member->first_name . ' ' . $member->last_name),
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

        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID);
        do_action('wp_login', $user->user_login, $user);
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
            return '<div class="avpvh-notice">Uw lidmaatschap is beëindigd. Neem contact op met het bestuur.</div>';
        }
        return $content;
    }
}
