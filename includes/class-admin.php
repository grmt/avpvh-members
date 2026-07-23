<?php
defined('ABSPATH') || exit;

class AVPVH_Admin {

    public function __construct() {
        // Load registration and sync admin pages
        require_once AVPVH_PLUGIN_DIR . 'admin/registrations-list.php';
        require_once AVPVH_PLUGIN_DIR . 'admin/registration-detail.php';
        require_once AVPVH_PLUGIN_DIR . 'admin/sync-status.php';

        // Priority 5: must register the 'avpvh-members' top-level menu before
        // registrations-list.php/sync-status.php's own admin_menu callbacks
        // (default priority 10) call add_submenu_page() against it — doing
        // add_submenu_page() before the parent exists yet is what caused
        // WordPress to render their sidebar links as a bare page slug
        // ("avpvh-registrations") instead of "admin.php?page=avpvh-registrations".
        add_action('admin_menu', [$this, 'register_menus'], 5);
        add_action('admin_post_avpvh_mark_fee_paid', [$this, 'handle_mark_fee_paid']);
        add_action('admin_post_avpvh_save_settings', [$this, 'handle_save_settings']);
        add_action('admin_post_avpvh_test_oauth',    [$this, 'handle_test_oauth']);
        add_action('admin_post_avpvh_add_identity',   [$this, 'handle_add_identity']);
        add_action('admin_post_avpvh_delete_identity',[$this, 'handle_delete_identity']);
        add_action('admin_post_avpvh_primary_identity',[$this, 'handle_primary_identity']);
    }

    public function register_menus(): void {
        add_menu_page(
            'AV-PvH Leden', 'AV-PvH Leden', 'manage_options',
            'avpvh-members', [$this, 'render_members_list'],
            'dashicons-groups', 30
        );
        add_submenu_page(
            'avpvh-members', 'Ledenbeheer', 'Ledenbeheer', 'manage_options',
            'avpvh-members', [$this, 'render_members_list']
        );
        add_submenu_page(
            'avpvh-members', 'Ledendetail', 'Ledendetail', 'manage_options',
            'avpvh-member-detail', [$this, 'render_member_detail']
        );
        add_submenu_page(
            'avpvh-members', 'Loginpogingen', 'Loginpogingen', 'manage_options',
            'avpvh-login-attempts', [$this, 'render_login_attempts']
        );
        add_submenu_page(
            'avpvh-members', 'Instellingen', 'Instellingen', 'manage_options',
            'avpvh-settings', [$this, 'render_settings']
        );
    }

    public function render_members_list(): void {
        require AVPVH_PLUGIN_DIR . 'admin/members-list.php';
    }

    public function render_member_detail(): void {
        require AVPVH_PLUGIN_DIR . 'admin/member-detail.php';
    }

    public function render_login_attempts(): void {
        require AVPVH_PLUGIN_DIR . 'admin/login-attempts.php';
    }

    public function render_settings(): void {
        $oauth_test = isset($_GET['oauth_test']) ? sanitize_text_field($_GET['oauth_test']) : null;
        $oauth_test_provider = isset($_GET['oauth_provider']) ? sanitize_text_field($_GET['oauth_provider']) : null;

        $test_result = null;
        if (isset($_POST['test_lldap'])) {
            check_admin_referer('avpvh_test_lldap');
            $url      = sanitize_url(wp_unslash($_POST['lldap_url'] ?? 'http://lldap:17170'));
            $user     = sanitize_text_field(wp_unslash($_POST['lldap_user'] ?? 'admin'));
            $password = sanitize_text_field(wp_unslash($_POST['lldap_password'] ?? ''));
            $test_result = AVPVH_LLDAP::test_connection_with($url, $user, $password);
        }
        ?>
        <div class="wrap">
            <h1>AVP-PvH Instellingen</h1>
            <?php if ($test_result === true) : ?>
                <div class="notice notice-success"><p>LLDAP verbinding OK.</p></div>
            <?php elseif (is_wp_error($test_result)) : ?>
                <div class="notice notice-error"><p>LLDAP fout: <?php echo esc_html($test_result->get_error_message()); ?></p></div>
            <?php endif; ?>
            <?php if ($oauth_test === 'ok') : ?>
                <div class="notice notice-success"><p><?php echo esc_html(ucfirst($oauth_test_provider)); ?> credentials OK — client ID en secret zijn geldig.</p></div>
            <?php elseif ($oauth_test === 'fail') : ?>
                <div class="notice notice-error"><p>
                    <?php echo esc_html(ucfirst($oauth_test_provider)); ?> credentials ongeldig — controleer de client ID en secret
                    <?php if ($oauth_test_provider === 'google') : ?>in de Google Cloud Console<?php else : ?>in de Azure portal<?php endif; ?>.
                    <?php if (!empty($_GET['oauth_error'])) : ?>
                        <br><small>Fout: <?php echo esc_html($_GET['oauth_error']); ?></small>
                    <?php endif; ?>
                </p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('avpvh_save_settings'); ?>
                <input type="hidden" name="action" value="avpvh_save_settings">
                <table class="form-table">
                    <tr><th colspan="2"><h2 style="margin:0">Google OAuth</h2></th></tr>
                    <tr>
                        <th><label for="oauth_google_client_id">Client ID</label></th>
                        <td><input type="text" id="oauth_google_client_id" name="oauth_google_client_id" class="regular-text"
                                   value="<?php echo esc_attr(get_option('avpvh_oauth_google_client_id', '')); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="oauth_google_client_secret">Client Secret</label></th>
                        <td><input type="password" id="oauth_google_client_secret" name="oauth_google_client_secret" class="regular-text"
                                   value="<?php echo esc_attr(get_option('avpvh_oauth_google_client_secret', '')); ?>">
                            <p class="description">Redirect URI voor Google Console: <code><?php echo esc_html(rest_url('avpvh/v1/oauth/google/callback')); ?></code></p>
                        </td>
                    </tr>
                    <tr>
                        <th></th>
                        <td>
                            <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'avpvh_test_oauth', 'provider' => 'google'], admin_url('admin-post.php')), 'avpvh_test_oauth_google')); ?>"
                               class="button">Google credentials testen</a>
                        </td>
                    </tr>
                    <tr><th colspan="2"><h2 style="margin:0">Microsoft OAuth</h2></th></tr>
                    <tr>
                        <th><label for="oauth_microsoft_client_id">Client ID</label></th>
                        <td><input type="text" id="oauth_microsoft_client_id" name="oauth_microsoft_client_id" class="regular-text"
                                   value="<?php echo esc_attr(get_option('avpvh_oauth_microsoft_client_id', '')); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="oauth_microsoft_client_secret">Client Secret</label></th>
                        <td><input type="password" id="oauth_microsoft_client_secret" name="oauth_microsoft_client_secret" class="regular-text"
                                   value="<?php echo esc_attr(get_option('avpvh_oauth_microsoft_client_secret', '')); ?>">
                            <p class="description">Redirect URI voor Azure: <code><?php echo esc_html(rest_url('avpvh/v1/oauth/microsoft/callback')); ?></code></p>
                        </td>
                    </tr>
                    <tr>
                        <th></th>
                        <td>
                            <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'avpvh_test_oauth', 'provider' => 'microsoft'], admin_url('admin-post.php')), 'avpvh_test_oauth_microsoft')); ?>"
                               class="button">Microsoft credentials testen</a>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Opslaan'); ?>
            </form>

            <hr>
            <h2>LLDAP verbinding testen</h2>
            <form method="post">
                <?php wp_nonce_field('avpvh_test_lldap'); ?>
                <input type="hidden" name="test_lldap" value="1">
                <table class="form-table">
                    <tr>
                        <th><label for="lldap_url">URL</label></th>
                        <td><input type="url" id="lldap_url" name="lldap_url" class="regular-text" value="http://lldap:17170"></td>
                    </tr>
                    <tr>
                        <th><label for="lldap_user">Gebruikersnaam</label></th>
                        <td><input type="text" id="lldap_user" name="lldap_user" class="regular-text" value="admin"></td>
                    </tr>
                    <tr>
                        <th><label for="lldap_password">Wachtwoord</label></th>
                        <td><input type="password" id="lldap_password" name="lldap_password" class="regular-text" value=""></td>
                    </tr>
                </table>
                <?php submit_button('Verbinding testen', 'secondary'); ?>
            </form>
        </div>
        <?php
    }

    public function handle_save_settings(): void {
        check_admin_referer('avpvh_save_settings');
        if (!current_user_can('manage_options')) {
            wp_die('Geen toegang.', 403);
        }
        update_option('avpvh_oauth_google_client_id',         sanitize_text_field(wp_unslash($_POST['oauth_google_client_id'] ?? '')));
        update_option('avpvh_oauth_google_client_secret',     sanitize_text_field(wp_unslash($_POST['oauth_google_client_secret'] ?? '')));
        update_option('avpvh_oauth_microsoft_client_id',      sanitize_text_field(wp_unslash($_POST['oauth_microsoft_client_id'] ?? '')));
        update_option('avpvh_oauth_microsoft_client_secret',  sanitize_text_field(wp_unslash($_POST['oauth_microsoft_client_secret'] ?? '')));
        wp_safe_redirect(add_query_arg(['page' => 'avpvh-settings', 'updated' => '1'], admin_url('admin.php')));
        exit;
    }

    public function handle_test_oauth(): void {
        $provider = sanitize_text_field($_GET['provider'] ?? '');
        if (!in_array($provider, ['google', 'microsoft'], true)) {
            wp_die('Onbekende provider.', 400);
        }
        check_admin_referer('avpvh_test_oauth_' . $provider);
        if (!current_user_can('manage_options')) {
            wp_die('Geen toegang.', 403);
        }

        $client_id     = get_option('avpvh_oauth_' . $provider . '_client_id');
        $client_secret = get_option('avpvh_oauth_' . $provider . '_client_secret');

        if (!$client_id || !$client_secret) {
            wp_safe_redirect(add_query_arg([
                'page'          => 'avpvh-settings',
                'oauth_test'    => 'fail',
                'oauth_provider' => $provider,
                'oauth_error'   => 'Client ID of secret is niet ingevuld.',
            ], admin_url('admin.php')));
            exit;
        }

        $config    = AVPVH_OAuth::PROVIDERS[$provider];
        $response  = wp_remote_post($config['token_url'], [
            'body' => [
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'code'          => 'test_dummy_code',
                'redirect_uri'  => rest_url('avpvh/v1/oauth/' . $provider . '/callback'),
                'grant_type'    => 'authorization_code',
            ],
            'timeout' => 10,
        ]);

        $redirect_args = ['page' => 'avpvh-settings', 'oauth_provider' => $provider];

        if (is_wp_error($response)) {
            $redirect_args['oauth_test']  = 'fail';
            $redirect_args['oauth_error'] = $response->get_error_message();
        } else {
            $body  = json_decode(wp_remote_retrieve_body($response), true);
            $error = $body['error'] ?? '';
            // invalid_grant = credentials OK, dummy code rejected (expected)
            // invalid_client = credentials wrong
            if ($error === 'invalid_grant' || $error === 'invalid_request') {
                $redirect_args['oauth_test'] = 'ok';
            } else {
                $redirect_args['oauth_test']  = 'fail';
                $redirect_args['oauth_error'] = $body['error_description'] ?? $error ?: 'Onbekende fout.';
            }
        }

        wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
        exit;
    }

    public function handle_mark_fee_paid(): void {
        check_admin_referer('avpvh_mark_fee_paid');
        if (!current_user_can('manage_options')) {
            wp_die('Geen toegang.', 403);
        }
        $fee_id    = (int) ($_POST['fee_id'] ?? 0);
        $member_id = (int) ($_POST['member_id'] ?? 0);
        if ($fee_id > 0) {
            AVPVH_DB::mark_fee_paid($fee_id);
            $member = AVPVH_DB::get_member($member_id);
            if ($member && $member->wp_user_id) {
                delete_user_meta((int) $member->wp_user_id, '_avpvh_show_fee_popup');
            }
        }
        wp_safe_redirect(add_query_arg(
            ['page' => 'avpvh-member-detail', 'id' => $member_id, 'updated' => '1'],
            admin_url('admin.php')
        ));
        exit;
    }

    public function handle_add_identity(): void {
        check_admin_referer('avpvh_add_identity');
        if (!current_user_can('manage_options')) {
            wp_die('Geen toegang.', 403);
        }

        $member_id = (int) ($_POST['member_id'] ?? 0);
        $provider  = sanitize_key($_POST['provider'] ?? '');
        $email     = sanitize_email(wp_unslash($_POST['email'] ?? ''));

        if ($member_id <= 0 || !$email) {
            wp_safe_redirect(add_query_arg(['page' => 'avpvh-member-detail', 'id' => $member_id, 'identity_error' => 'onvolledig'], admin_url('admin.php')));
            exit;
        }

        if (!AVPVH_DB::ensure_identity($member_id, $provider, $email)) {
            wp_safe_redirect(add_query_arg(['page' => 'avpvh-member-detail', 'id' => $member_id, 'identity_error' => 'limiet'], admin_url('admin.php')));
            exit;
        }

        wp_safe_redirect(add_query_arg(['page' => 'avpvh-member-detail', 'id' => $member_id, 'identity_ok' => '1'], admin_url('admin.php')));
        exit;
    }

    public function handle_delete_identity(): void {
        check_admin_referer('avpvh_delete_identity');
        if (!current_user_can('manage_options')) {
            wp_die('Geen toegang.', 403);
        }

        $member_id   = (int) ($_POST['member_id'] ?? 0);
        $identity_id = (int) ($_POST['identity_id'] ?? 0);
        AVPVH_DB::delete_identity_by_id($member_id, $identity_id);

        wp_safe_redirect(add_query_arg(['page' => 'avpvh-member-detail', 'id' => $member_id, 'identity_deleted' => '1'], admin_url('admin.php')));
        exit;
    }

    public function handle_primary_identity(): void {
        check_admin_referer('avpvh_primary_identity');
        if (!current_user_can('manage_options')) {
            wp_die('Geen toegang.', 403);
        }

        $member_id   = (int) ($_POST['member_id'] ?? 0);
        $identity_id = (int) ($_POST['identity_id'] ?? 0);
        AVPVH_DB::set_primary_identity($member_id, $identity_id);

        wp_safe_redirect(add_query_arg(['page' => 'avpvh-member-detail', 'id' => $member_id, 'identity_primary' => '1'], admin_url('admin.php')));
        exit;
    }
}
