<?php
defined('ABSPATH') || exit;

class AVPVH_Admin {

    public function __construct() {
        add_action('admin_menu', [$this, 'register_menus'], 5);
        add_action('admin_post_avpvh_mark_fee_paid', [$this, 'handle_mark_fee_paid']);
        add_action('admin_post_avpvh_save_settings', [$this, 'handle_save_settings']);
        add_action('admin_post_avpvh_test_oauth',    [$this, 'handle_test_oauth']);
        add_action('admin_post_avpvh_add_identity',   [$this, 'handle_add_identity']);
        add_action('admin_post_avpvh_delete_identity',[$this, 'handle_delete_identity']);
        add_action('admin_post_avpvh_primary_identity',[$this, 'handle_primary_identity']);
        add_action('admin_post_avpvh_save_participation', [$this, 'handle_save_participation']);
        add_action('admin_post_avpvh_create_activity',    [$this, 'handle_create_activity']);
        add_action('admin_post_avpvh_save_activity',      [$this, 'handle_save_activity']);
        add_action('admin_post_avpvh_save_activity_types',[$this, 'handle_save_activity_types']);
        add_action('admin_post_avpvh_export_activity_participation', [$this, 'handle_export_activity_participation']);
        add_action('admin_post_avpvh_delegate_role',      [$this, 'handle_delegate_role']);
        add_action('admin_post_avpvh_revoke_delegation',  [$this, 'handle_revoke_delegation']);
        add_action('admin_post_avpvh_save_page_permissions', [$this, 'handle_save_page_permissions']);
        add_action('admin_post_avpvh_update_address',     [$this, 'handle_update_address']);
        add_action('admin_post_avpvh_update_email',       [$this, 'handle_update_email']);
        add_action('admin_post_avpvh_save_groups',        [$this, 'handle_save_groups']);
        add_action('admin_post_avpvh_delete_address',     [$this, 'handle_delete_address']);
        add_action('admin_post_avpvh_add_member',         [$this, 'handle_add_member']);
        add_action('admin_post_avpvh_save_member_flags',  [$this, 'handle_save_member_flags']);
        add_action('admin_post_avpvh_create_flag',        [$this, 'handle_create_flag']);
        add_action('admin_post_avpvh_delete_flag',        [$this, 'handle_delete_flag']);
        add_action('admin_post_avpvh_send_newsletter',    [$this, 'handle_send_newsletter']);
        add_filter('set_screen_option_avpvh_login_attempts_per_page', static function ($status, $option, $value) {
            return max(1, min(500, (int) $value));
        }, 10, 3);
    }

    // manage_options (real WP admins) or bestuur (incl. voorzitter/
    // secretaris/penningmeester, who imply bestuur — see AVPVH_Roles) —
    // broader than the manage_options-only gate every other screen in this
    // file uses, since board members log in as plain 'contributor' WP
    // users and would otherwise never see this menu at all.
    private function can_manage_roles(): bool {
        return current_user_can('manage_options') || AVPVH_Roles::current_user_has_role('bestuur');
    }

    // secretaris (real LLDAP group membership, or a temporary delegation —
    // see AVPVH_Roles and admin/roles.php's "Nieuwe delegatie" form) is
    // traditionally the membership registrar, so gets the same member-
    // management access as a real WP admin: Ledenbeheer/Ledendetail/Nieuw
    // lid only, not the rest of this menu (Activiteiten, Instellingen,
    // Nieuwsbrief, Loginpogingen stay manage_options-only).
    private function can_manage_members(): bool {
        return AVPVH_Roles::current_user_can_access_page('members');
    }

    private function can_access_page(string $page): bool {
        return AVPVH_Roles::current_user_can_access_page($page);
    }

    private function can_manage_identities(): bool {
        return $this->can_manage_members()
            && (current_user_can('manage_options') || AVPVH_Roles::current_user_has_role('secretaris'));
    }

    public function register_menus(): void {
        // add_menu_page()/add_submenu_page()'s capability must be a real WP
        // capability string, not a club role — 'read' (every logged-in user
        // has it) stands in for "yes" here, since register_menus() itself
        // re-runs per admin pageview for whoever's viewing, same trick
        // already used below for can_manage_roles()/'Rollen & delegatie'.
        $members_cap = $this->can_manage_members() ? 'read' : 'manage_options';
        $activities_cap = $this->can_access_page('activities') ? 'read' : 'manage_options';
        $login_attempts_cap = $this->can_access_page('login_attempts') ? 'read' : 'manage_options';
        $newsletter_cap = $this->can_access_page('newsletter') ? 'read' : 'manage_options';
        $settings_cap = $this->can_access_page('plugin_settings') ? 'read' : 'manage_options';

        $hook = add_menu_page(
            'AV-PvH Leden', 'AV-PvH Leden', $members_cap,
            'avpvh-members', [$this, 'render_members_list'],
            'dashicons-groups', 30
        );
        add_submenu_page(
            'avpvh-members', 'Ledenbeheer', 'Ledenbeheer', $members_cap,
            'avpvh-members', [$this, 'render_members_list']
        );

        // Wires up the "Screen Options" column show/hide checkboxes for the
        // members list — WordPress remembers the choice per user on its own
        // once this filter exists, no custom persistence code needed.
        add_action('load-' . $hook, function () {
            require_once AVPVH_PLUGIN_DIR . 'admin/class-members-list-table.php';
            add_filter('manage_' . get_current_screen()->id . '_columns', function () {
                return (new AVPVH_Members_List_Table())->get_columns();
            });
        });
        add_submenu_page(
            'avpvh-members', 'Ledendetail', 'Ledendetail', $members_cap,
            'avpvh-member-detail', [$this, 'render_member_detail']
        );
        add_submenu_page(
            'avpvh-members', 'Nieuw lid', 'Nieuw lid', $members_cap,
            'avpvh-add-member', [$this, 'render_add_member']
        );
        add_submenu_page(
            'avpvh-members', 'Activiteiten', 'Activiteiten', $activities_cap,
            'avpvh-activity-participation', [$this, 'render_activity_participation_list']
        );
        // Not shown in the sidebar — only reachable via the "Nieuwe
        // deelname"/"Bewerken" links on the Activiteiten list page, which
        // always pass an activity_id (and usually an id). Landed on cold
        // (no params), it can only ever offer "create new" — not useful as
        // its own standalone menu entry. Registering with a null parent
        // (rather than add_submenu_page() + remove_submenu_page()) keeps
        // the page itself reachable by URL — remove_submenu_page() strips
        // the page from the $submenu registry that admin.php uses to
        // dispatch the request, so a direct link 404s/"not allowed"s
        // instead of just being hidden from the menu.
        add_submenu_page(
            null, 'Deelname bewerken', 'Deelname bewerken', $activities_cap,
            'avpvh-activity-participation-detail', [$this, 'render_activity_participation_detail']
        );
        $login_attempts_hook = add_submenu_page(
            'avpvh-members', 'Loginpogingen', 'Loginpogingen', $login_attempts_cap,
            'avpvh-login-attempts', [$this, 'render_login_attempts']
        );
        add_action('load-' . $login_attempts_hook, function () {
            require_once AVPVH_PLUGIN_DIR . 'admin/class-login-attempts-list-table.php';
            add_screen_option('per_page', [
                'label' => 'Loginpogingen per pagina',
                'default' => 50,
                'option' => 'avpvh_login_attempts_per_page',
            ]);
            add_filter('manage_' . get_current_screen()->id . '_columns', static function () {
                return (new AVPVH_Login_Attempts_List_Table())->get_columns();
            });
            add_filter('default_hidden_columns', static function (array $hidden, $screen): array {
                if ($screen && $screen->id === get_current_screen()->id) {
                    $hidden[] = 'id';
                }
                return array_values(array_unique($hidden));
            }, 10, 2);
        });
        add_submenu_page(
            'avpvh-members', 'Nieuwsbrief', 'Nieuwsbrief', $newsletter_cap,
            'avpvh-newsletter', [$this, 'render_newsletter']
        );
        add_submenu_page(
            'avpvh-members', 'Instellingen', 'Instellingen', $settings_cap,
            'avpvh-settings', [$this, 'render_settings']
        );

        // Only registered at all when the viewer qualifies — 'read' is the
        // only WP capability every logged-in member has, so gating on the
        // real rule here (rather than in add_submenu_page's capability
        // argument) is what keeps this out of the menu for everyone else.
        if ($this->can_manage_roles()) {
            add_submenu_page(
                'avpvh-members', 'Rollen & delegatie', 'Rollen & delegatie', 'read',
                'avpvh-roles', [$this, 'render_roles']
            );
        }
        if (AVPVH_Roles::current_user_is_it_admin()) {
            add_submenu_page(
                'avpvh-members', 'Paginarechten', 'Paginarechten', 'read',
                'avpvh-page-permissions', [$this, 'render_page_permissions']
            );
        }
    }

    public function render_members_list(): void {
        require AVPVH_PLUGIN_DIR . 'admin/members-list.php';
    }

    public function render_member_detail(): void {
        require AVPVH_PLUGIN_DIR . 'admin/member-detail.php';
    }

    public function render_add_member(): void {
        require AVPVH_PLUGIN_DIR . 'admin/add-member.php';
    }

    public function render_login_attempts(): void {
        require AVPVH_PLUGIN_DIR . 'admin/login-attempts.php';
    }

    public function render_newsletter(): void {
        require AVPVH_PLUGIN_DIR . 'admin/newsletter.php';
    }

    public function render_activity_participation_list(): void {
        require AVPVH_PLUGIN_DIR . 'admin/activity-participation-list.php';
    }

    public function render_activity_participation_detail(): void {
        require AVPVH_PLUGIN_DIR . 'admin/activity-participation-detail.php';
    }

    public function render_roles(): void {
        require AVPVH_PLUGIN_DIR . 'admin/roles.php';
    }

    public function render_page_permissions(): void {
        require AVPVH_PLUGIN_DIR . 'admin/page-permissions.php';
    }

    public function render_settings(): void {
        if (!$this->can_access_page('plugin_settings')) {
            wp_die('Geen toegang.', 403);
        }
        $can_manage_authentication = current_user_can('manage_options');
        $oauth_test = isset($_GET['oauth_test']) ? sanitize_text_field(wp_unslash($_GET['oauth_test'])) : null;
        $oauth_test_provider = isset($_GET['oauth_provider']) ? sanitize_text_field(wp_unslash($_GET['oauth_provider'])) : null;

        $test_result = null;
        if (isset($_POST['test_lldap']) && !$can_manage_authentication) {
            wp_die('Geen toegang.', 403);
        }
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
            <?php if ($can_manage_authentication && $test_result === true) : ?>
                <div class="notice notice-success"><p>LLDAP verbinding OK.</p></div>
            <?php elseif ($can_manage_authentication && is_wp_error($test_result)) : ?>
                <div class="notice notice-error"><p>LLDAP fout: <?php echo esc_html($test_result->get_error_message()); ?></p></div>
            <?php endif; ?>
            <?php if ($can_manage_authentication && $oauth_test === 'ok') : ?>
                <div class="notice notice-success"><p><?php echo esc_html(ucfirst($oauth_test_provider)); ?> credentials OK — client ID en secret zijn geldig.</p></div>
            <?php elseif ($can_manage_authentication && $oauth_test === 'fail') : ?>
                <div class="notice notice-error"><p>
                    <?php echo esc_html(ucfirst($oauth_test_provider)); ?> credentials ongeldig — controleer de client ID en secret
                    <?php if ($oauth_test_provider === 'google') : ?>in de Google Cloud Console<?php else : ?>in de Azure portal<?php endif; ?>.
                    <?php if (!empty($_GET['oauth_error'])) : ?>
                        <br><small>Fout: <?php echo esc_html(sanitize_text_field(wp_unslash($_GET['oauth_error']))); ?></small>
                    <?php endif; ?>
                </p></div>
            <?php endif; ?>
            <?php if ($can_manage_authentication) : ?>
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
            <?php endif; ?>

            <hr>
            <h2>Kenmerken</h2>
            <p class="description">Vrij uitbreidbare lijst met kenmerken die aan een lid toegekend kunnen worden (Ledendetail &rarr; Kenmerken), en waarop de ledenlijst gefilterd kan worden. "Vrijgesteld van contributie" (bijv. ere-lid) zorgt dat er nooit een contributie-item voor dat lid wordt aangemaakt. "Zet lid op inactief" (bijv. geroyeerd) zet de status van een lid automatisch op inactief zodra dit kenmerk wordt toegekend (nooit andersom bij het weghalen).</p>
            <?php if (!empty($_GET['flag_created'])) : ?>
                <div class="notice notice-success is-dismissible"><p>Kenmerk aangemaakt.</p></div>
            <?php elseif (!empty($_GET['flag_error'])) : ?>
                <div class="notice notice-error is-dismissible"><p>Kon kenmerk niet aanmaken (naam al in gebruik?).</p></div>
            <?php elseif (!empty($_GET['flag_deleted'])) : ?>
                <div class="notice notice-success is-dismissible"><p>Kenmerk verwijderd.</p></div>
            <?php endif; ?>
            <table class="wp-list-table widefat striped" style="max-width:600px">
                <thead><tr><th>Label</th><th>Vrijgesteld van contributie</th><th>Zet op inactief</th><th></th></tr></thead>
                <tbody>
                <?php $flags = AVPVH_DB::get_all_flags(); ?>
                <?php if (!$flags) : ?>
                    <tr><td colspan="4">Nog geen kenmerken.</td></tr>
                <?php else : foreach ($flags as $flag) : ?>
                    <tr>
                        <td><?php echo esc_html($flag->label); ?></td>
                        <td><?php echo $flag->affects_fees ? 'Ja' : 'Nee'; ?></td>
                        <td><?php echo $flag->sets_inactive ? 'Ja' : 'Nee'; ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                onsubmit="return confirm('Kenmerk &quot;<?php echo esc_js($flag->label); ?>&quot; verwijderen? Dit haalt het ook weg bij alle leden die het hebben.');">
                                <?php wp_nonce_field('avpvh_delete_flag'); ?>
                                <input type="hidden" name="action" value="avpvh_delete_flag">
                                <input type="hidden" name="flag_id" value="<?php echo esc_attr($flag->id); ?>">
                                <button type="submit" class="button button-small">Verwijder</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>

            <h3 style="margin-top:1rem">Nieuw kenmerk</h3>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('avpvh_create_flag'); ?>
                <input type="hidden" name="action" value="avpvh_create_flag">
                <table class="form-table">
                    <tr>
                        <th><label for="flag_label">Naam</label></th>
                        <td><input type="text" id="flag_label" name="label" class="regular-text" placeholder="bv. Belangrijk voor opgraving X"></td>
                    </tr>
                    <tr>
                        <th><label for="flag_affects_fees">Vrijgesteld van contributie</label></th>
                        <td><input type="checkbox" id="flag_affects_fees" name="affects_fees" value="1"></td>
                    </tr>
                    <tr>
                        <th><label for="flag_sets_inactive">Zet lid op inactief</label></th>
                        <td><input type="checkbox" id="flag_sets_inactive" name="sets_inactive" value="1"></td>
                    </tr>
                </table>
                <?php submit_button('Kenmerk aanmaken', 'secondary'); ?>
            </form>

            <?php if ($can_manage_authentication) : ?>
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
            <?php endif; ?>
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
        $provider = sanitize_text_field(wp_unslash($_GET['provider'] ?? ''));
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
        if (!$this->can_manage_members()) {
            wp_die('Geen toegang.', 403);
        }
        $fee_id    = absint(wp_unslash($_POST['fee_id'] ?? 0));
        $member_id = absint(wp_unslash($_POST['member_id'] ?? 0));
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

    /**
     * Deliberately doesn't pass $verified=true to ensure_identity() below —
     * an admin typing an address in has no proof the member actually
     * controls it, unlike the self-service OAuth/e-mail-link flows.
     */
    public function handle_add_identity(): void {
        check_admin_referer('avpvh_add_identity');
        if (!$this->can_manage_identities()) {
            wp_die('Geen toegang.', 403);
        }

        $member_id = absint(wp_unslash($_POST['member_id'] ?? 0));
        $email     = sanitize_email(wp_unslash($_POST['email'] ?? ''));

        if ($member_id <= 0 || !$email) {
            wp_safe_redirect(add_query_arg(['page' => 'avpvh-member-detail', 'id' => $member_id, 'identity_error' => 'onvolledig'], admin_url('admin.php')));
            exit;
        }

        // Stored as a placeholder 'email' provider — an admin has no way to
        // know which method (Google/Microsoft/e-maillink) the member will
        // actually verify with. AVPVH_OAuth::handle_callback() and
        // AVPVH_DB::get_identity_by_email() upgrade this to the real
        // provider once the member does.
        if (!AVPVH_DB::ensure_identity($member_id, 'email', $email)) {
            wp_safe_redirect(add_query_arg(['page' => 'avpvh-member-detail', 'id' => $member_id, 'identity_error' => 'limiet'], admin_url('admin.php')));
            exit;
        }

        wp_safe_redirect(add_query_arg(['page' => 'avpvh-member-detail', 'id' => $member_id, 'identity_ok' => '1'], admin_url('admin.php')));
        exit;
    }

    public function handle_delete_identity(): void {
        check_admin_referer('avpvh_delete_identity');
        if (!$this->can_manage_identities()) {
            wp_die('Geen toegang.', 403);
        }

        $member_id   = absint(wp_unslash($_POST['member_id'] ?? 0));
        $identity_id = absint(wp_unslash($_POST['identity_id'] ?? 0));

        // Same rule as the self-service member-profile page: at least 2
        // *verified* identities must remain, so nobody — including an admin
        // editing their own record — can delete their way down to zero
        // working logins. An admin-added, never-verified extra doesn't
        // count as a safe fallback to remove down to.
        $identities     = AVPVH_DB::get_member_identities($member_id);
        $verified_count = count(array_filter($identities, fn($i) => !empty($i->verified_at)));
        if ($verified_count <= 1) {
            wp_safe_redirect(add_query_arg(['page' => 'avpvh-member-detail', 'id' => $member_id, 'identity_error' => 'laatste'], admin_url('admin.php')));
            exit;
        }

        AVPVH_DB::delete_identity_by_id($member_id, $identity_id);

        wp_safe_redirect(add_query_arg(['page' => 'avpvh-member-detail', 'id' => $member_id, 'identity_deleted' => '1'], admin_url('admin.php')));
        exit;
    }

    public function handle_primary_identity(): void {
        check_admin_referer('avpvh_primary_identity');
        if (!$this->can_manage_identities()) {
            wp_die('Geen toegang.', 403);
        }

        $member_id   = absint(wp_unslash($_POST['member_id'] ?? 0));
        $identity_id = absint(wp_unslash($_POST['identity_id'] ?? 0));
        AVPVH_DB::set_primary_identity($member_id, $identity_id);

        // The LLDAP contact e-mail (this page's "E-mail" field, used for
        // correspondence, separate from login) should follow whichever
        // identity is now primary, replacing whatever was there before.
        $member = AVPVH_DB::get_member($member_id);
        foreach (AVPVH_DB::get_member_identities($member_id) as $identity) {
            if ($member && (int) $identity->id === $identity_id) {
                $result = AVPVH_LLDAP::update_user($member->lldap_user_id, ['email' => $identity->email]);
                if (is_wp_error($result)) {
                    error_log("AVPVH_Admin: failed to sync primary identity ({$identity->email}) to LLDAP for member {$member_id}: " . $result->get_error_message());
                }
                break;
            }
        }

        wp_safe_redirect(add_query_arg(['page' => 'avpvh-member-detail', 'id' => $member_id, 'identity_primary' => '1'], admin_url('admin.php')));
        exit;
    }

    public function handle_save_member_flags(): void {
        check_admin_referer('avpvh_save_member_flags');
        if (!$this->can_manage_members()) {
            wp_die('Geen toegang.', 403);
        }

        $member_id = absint(wp_unslash($_POST['member_id'] ?? 0));
        $flag_ids  = array_map('intval', (array) wp_unslash($_POST['flag_ids'] ?? []));
        if ($member_id) {
            AVPVH_DB::set_member_flags($member_id, $flag_ids);
        }

        wp_safe_redirect(add_query_arg(['page' => 'avpvh-member-detail', 'id' => $member_id, 'flags_saved' => '1'], admin_url('admin.php')));
        exit;
    }

    public function handle_create_flag(): void {
        check_admin_referer('avpvh_create_flag');
        if (!$this->can_access_page('plugin_settings')) {
            wp_die('Geen toegang.', 403);
        }

        $label         = sanitize_text_field(wp_unslash($_POST['label'] ?? ''));
        $affects_fees  = !empty($_POST['affects_fees']);
        $sets_inactive = !empty($_POST['sets_inactive']);
        $ok = $label && AVPVH_DB::create_flag($label, $label, $affects_fees, $sets_inactive);

        wp_safe_redirect(add_query_arg(['page' => 'avpvh-settings', $ok ? 'flag_created' : 'flag_error' => '1'], admin_url('admin.php')));
        exit;
    }

    public function handle_delete_flag(): void {
        check_admin_referer('avpvh_delete_flag');
        if (!$this->can_access_page('plugin_settings')) {
            wp_die('Geen toegang.', 403);
        }

        $flag_id = absint(wp_unslash($_POST['flag_id'] ?? 0));
        if ($flag_id) {
            AVPVH_DB::delete_flag($flag_id);
        }

        wp_safe_redirect(add_query_arg(['page' => 'avpvh-settings', 'flag_deleted' => '1'], admin_url('admin.php')));
        exit;
    }

    /**
     * Sends individually (not one big BCC) so nobody sees the rest of the
     * recipient list, to every active member with the 'nieuwsbrief' flag —
     * see AVPVH_DB's "Member flags" section and
     * AVPVH_Newsletter_Consent for the self-service opt-in checkbox.
     * No send history/log is kept — this is intentionally a thin, direct
     * "send it now" tool, not a mailing campaign manager.
     */
    public function handle_send_newsletter(): void {
        check_admin_referer('avpvh_send_newsletter');
        if (!$this->can_access_page('newsletter')) {
            wp_die('Geen toegang.', 403);
        }

        $subject = sanitize_text_field(wp_unslash($_POST['subject'] ?? ''));
        $body    = sanitize_textarea_field(wp_unslash($_POST['body'] ?? ''));
        if (!$subject || !$body) {
            wp_safe_redirect(add_query_arg(['page' => 'avpvh-newsletter', 'newsletter_error' => '1'], admin_url('admin.php')));
            exit;
        }

        $flags = AVPVH_DB::get_all_flags();
        $flag_id = 0;
        foreach ($flags as $flag) {
            if ($flag->slug === 'nieuwsbrief') {
                $flag_id = (int) $flag->id;
                break;
            }
        }

        $sent = 0;
        if ($flag_id) {
            foreach (AVPVH_DB::get_members(['status' => 'active', 'flag_id' => $flag_id]) as $member) {
                if ($member->email && wp_mail($member->email, $subject, $body)) {
                    $sent++;
                }
            }
        }

        wp_safe_redirect(add_query_arg(['page' => 'avpvh-newsletter', 'newsletter_sent' => $sent], admin_url('admin.php')));
        exit;
    }

    public function handle_update_address(): void {
        check_admin_referer('avpvh_update_address');
        if (!$this->can_manage_members()) {
            wp_die('Geen toegang.', 403);
        }
        $id = absint(wp_unslash($_POST['id'] ?? 0));
        $member_id = absint(wp_unslash($_POST['member_id'] ?? 0));
        $valid_from = sanitize_text_field(wp_unslash($_POST['valid_from'] ?? '')) ?: null;
        $valid_until = sanitize_text_field(wp_unslash($_POST['valid_until'] ?? '')) ?: null;
        if ($id && $member_id) {
            AVPVH_DB::update_address($id, $member_id, $valid_from, $valid_until);
        }
        wp_safe_redirect(add_query_arg(['page' => 'avpvh-member-detail', 'id' => $member_id, 'tab' => 'contact', 'address_updated' => '1'], admin_url('admin.php')));
        exit;
    }

    // This edits the LLDAP account's own 'email' attribute directly — the
    // "E-mail" contact field shown in Ledendetail (class-db.php's
    // member_select() reads it straight from LLDAP via `u.email`, not from
    // avm_members). It's unrelated to Inlogadressen (avm_member_identities):
    // that table only reflects logins that have actually happened, while
    // this is LLDAP's own contact record, and only this plugin's WP-admin
    // side had no UI to change or clear it — the field itself always
    // existed and was editable directly in LLDAP.
    public function handle_update_email(): void {
        check_admin_referer('avpvh_update_email');
        if (!$this->can_manage_members()) {
            wp_die('Geen toegang.', 403);
        }

        $member_id = absint(wp_unslash($_POST['member_id'] ?? 0));
        $email     = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $member    = $member_id ? AVPVH_DB::get_member($member_id) : null;

        if (!$member) {
            wp_die('Lid niet gevonden.', 'Fout', ['response' => 404]);
        }

        if ($email !== '' && !is_email($email)) {
            wp_safe_redirect(add_query_arg(['page' => 'avpvh-member-detail', 'id' => $member_id, 'tab' => 'contact', 'email_error' => '1'], admin_url('admin.php')));
            exit;
        }

        // Clearing the field means "remove it" — same placeholder convention
        // handle_add_member() already uses for members with no real e-mail
        // (e.g. under-16s), rather than relying on LLDAP accepting a blank
        // 'mail' attribute, which its schema may not allow at all.
        if ($email === '') {
            $email = $member->lldap_user_id . '@avpvh.local';
        }

        $result = AVPVH_LLDAP::update_user($member->lldap_user_id, ['email' => $email]);
        if (is_wp_error($result)) {
            wp_safe_redirect(add_query_arg(['page' => 'avpvh-member-detail', 'id' => $member_id, 'tab' => 'contact', 'email_error' => '1'], admin_url('admin.php')));
            exit;
        }

        wp_safe_redirect(add_query_arg(['page' => 'avpvh-member-detail', 'id' => $member_id, 'tab' => 'contact', 'email_updated' => '1'], admin_url('admin.php')));
        exit;
    }

    // manage_options only, not secretaris — unlike identities/address/flags,
    // LLDAP groups can grant real elevated access (secretaris, bestuur, the
    // boek-group), so letting a secretaris hand those out themselves would
    // be a privilege-escalation path. Was previously only possible via
    // scripts/manage-lldap-group.sh run on the server by hand.
    public function handle_save_groups(): void {
        check_admin_referer('avpvh_save_groups');
        if (!current_user_can('manage_options')) {
            wp_die('Geen toegang.', 403);
        }

        $member_id = absint(wp_unslash($_POST['member_id'] ?? 0));
        $member    = $member_id ? AVPVH_DB::get_member($member_id) : null;
        if (!$member) {
            wp_die('Lid niet gevonden.', 'Fout', ['response' => 404]);
        }

        $current_groups = AVPVH_LLDAP::get_user_groups($member->lldap_user_id);
        if (is_wp_error($current_groups)) {
            wp_safe_redirect(add_query_arg(['page' => 'avpvh-member-detail', 'id' => $member_id, 'tab' => 'contact', 'groups_error' => '1'], admin_url('admin.php')));
            exit;
        }

        $selected_ids = array_map('intval', (array) wp_unslash($_POST['groups'] ?? []));
        $current_ids  = array_map('intval', array_column($current_groups, 'id'));

        $had_error = false;
        foreach (array_diff($selected_ids, $current_ids) as $group_id) {
            $result = AVPVH_LLDAP::add_to_group($member->lldap_user_id, $group_id);
            if (is_wp_error($result)) {
                $had_error = true;
                error_log("AVPVH_Admin: failed to add member {$member_id} to LLDAP group {$group_id}: " . $result->get_error_message());
            }
        }
        foreach (array_diff($current_ids, $selected_ids) as $group_id) {
            $result = AVPVH_LLDAP::remove_from_group($member->lldap_user_id, $group_id);
            if (is_wp_error($result)) {
                $had_error = true;
                error_log("AVPVH_Admin: failed to remove member {$member_id} from LLDAP group {$group_id}: " . $result->get_error_message());
            }
        }

        // Same caches scripts/manage-lldap-group.sh's clear-cache clears —
        // otherwise role checks, the ledenlijst, and the member's own
        // "Groepen:" display wouldn't reflect this for up to 15 minutes.
        delete_transient('avpvh_lldap_groups_' . $member->lldap_user_id);
        delete_transient('avpvh_all_group_memberships');

        $notice_key = $had_error ? 'groups_error' : 'groups_saved';
        wp_safe_redirect(add_query_arg(['page' => 'avpvh-member-detail', 'id' => $member_id, 'tab' => 'contact', $notice_key => '1'], admin_url('admin.php')));
        exit;
    }

    public function handle_delete_address(): void {
        check_admin_referer('avpvh_delete_address');
        if (!$this->can_manage_members()) {
            wp_die('Geen toegang.', 403);
        }
        $id = absint(wp_unslash($_POST['id'] ?? 0));
        $member_id = absint(wp_unslash($_POST['member_id'] ?? 0));
        if ($id && $member_id) {
            AVPVH_DB::delete_address($id, $member_id);
        }
        wp_safe_redirect(add_query_arg(['page' => 'avpvh-member-detail', 'id' => $member_id, 'tab' => 'contact', 'address_deleted' => '1'], admin_url('admin.php')));
        exit;
    }

    /**
     * Creates a member the same way the one-off avpvh-ops-scripts have
     * always done it by hand: a placeholder LLDAP account under a local
     * @avpvh.local address (club policy — under-16 members never get a
     * real login, and this form doesn't ask for a real email at all) plus
     * the matching avm_members row. Warns on a same-name match rather than
     * silently blocking it — two real people can share a name — and
     * requires an explicit "add anyway" resubmit to proceed past that.
     */
    public function handle_add_member(): void {
        check_admin_referer('avpvh_add_member');
        if (!$this->can_manage_members()) {
            wp_die('Geen toegang.', 403);
        }

        $first_name = sanitize_text_field(wp_unslash($_POST['first_name'] ?? ''));
        $suffix     = sanitize_text_field(wp_unslash($_POST['suffix'] ?? ''));
        $last_name  = sanitize_text_field(wp_unslash($_POST['last_name'] ?? ''));
        $birth_date = sanitize_text_field(wp_unslash($_POST['birth_date'] ?? '')) ?: null;
        $status     = sanitize_key(wp_unslash($_POST['status'] ?? 'inactive'));
        $status     = in_array($status, ['active', 'inactive', 'visitor'], true) ? $status : 'inactive';
        $confirmed  = !empty($_POST['confirmed']);

        if ($first_name === '' || $last_name === '') {
            wp_safe_redirect(add_query_arg([
                'page' => 'avpvh-add-member', 'add_member_error' => 'onvolledig',
            ], admin_url('admin.php')));
            exit;
        }

        if (!$confirmed) {
            $matches = AVPVH_DB::find_members_by_name($first_name, $last_name);
            if ($matches) {
                set_transient('avpvh_add_member_pending_' . get_current_user_id(), [
                    'first_name' => $first_name, 'suffix' => $suffix, 'last_name' => $last_name,
                    'birth_date' => $birth_date, 'status' => $status,
                    'matches'    => wp_list_pluck($matches, 'id'),
                ], 10 * MINUTE_IN_SECONDS);
                wp_safe_redirect(add_query_arg(['page' => 'avpvh-add-member', 'add_member_duplicate' => '1'], admin_url('admin.php')));
                exit;
            }
        }

        // uid: first.last, lowercased, non [a-z0-9._-] characters folded to
        // "." — same slug shape the ops-scripts already use, so a later
        // hand-run script never collides with one created here.
        $base_uid = preg_replace('/[^a-z0-9._-]/', '.', strtolower("{$first_name}.{$last_name}"));
        $uid = $base_uid;
        $n = 1;
        while (AVPVH_LLDAP::get_user_display_name($uid) !== null) {
            $n++;
            $uid = "{$base_uid}{$n}";
        }
        $email = "{$uid}@avpvh.local";
        $display_name = trim(preg_replace('/\s+/', ' ', "{$first_name} {$suffix} {$last_name}"));

        $created = AVPVH_LLDAP::create_user($uid, $email, $display_name);
        if (is_wp_error($created)) {
            wp_safe_redirect(add_query_arg([
                'page' => 'avpvh-add-member', 'add_member_error' => 'lldap',
                'add_member_error_message' => rawurlencode($created->get_error_message()),
            ], admin_url('admin.php')));
            exit;
        }

        $groups = AVPVH_LLDAP::list_groups();
        $group_id = null;
        if (!is_wp_error($groups)) {
            foreach ($groups as $group) {
                if (strtolower($group['displayName']) === 'leden') {
                    $group_id = (int) $group['id'];
                    break;
                }
            }
        }
        if ($group_id) {
            AVPVH_LLDAP::add_to_group($uid, $group_id);
        }

        $member_id = AVPVH_DB::create_member($uid, $first_name, $suffix, $last_name, $birth_date, $status);

        wp_safe_redirect(add_query_arg(['page' => 'avpvh-member-detail', 'id' => $member_id, 'created' => '1'], admin_url('admin.php')));
        exit;
    }

    public function handle_save_participation(): void {
        check_admin_referer('avpvh_save_participation');
        if (!$this->can_access_page('activities')) {
            wp_die('Geen toegang.', 403);
        }

        $activity_id = absint(wp_unslash($_POST['activity_id'] ?? 0));
        $member_id   = absint(wp_unslash($_POST['member_id'] ?? 0));
        if (!$activity_id || !$member_id) {
            wp_die('Activiteit of lid ontbreekt.', 400);
        }

        $days = [];
        foreach ((array) wp_unslash($_POST['day'] ?? []) as $date => $status) {
            $date = sanitize_text_field($date);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $days[$date] = sanitize_text_field($status);
            }
        }

        // Nachten is derived from the dagen grid (count of 'n' days), not a
        // separately typed number — the two used to drift apart (a day
        // corrected without the treasurer noticing the total needed
        // updating too, or vice versa) and only the day grid is the real
        // record of who was actually there. Only an activity with no
        // start/end date (so no day grid to derive from at all — see
        // admin/activity-participation-detail.php) falls back to a manual
        // 'nights' field.
        $nights = isset($_POST['day'])
            ? count(array_filter($days, fn($status) => $status === 'n'))
            : (isset($_POST['nights']) && $_POST['nights'] !== '' ? absint(wp_unslash($_POST['nights'])) : '');

        $fields = [
            'nights'  => $nights,
            'nawacht' => !empty($_POST['nawacht']),
            'diet'    => sanitize_text_field(wp_unslash($_POST['diet'] ?? '')),
            'notes'   => sanitize_textarea_field(wp_unslash($_POST['notes'] ?? '')),
        ];
        $participation_id = AVPVH_DB::save_participation($member_id, $activity_id, $fields);
        AVPVH_DB::save_participation_days($participation_id, $days);

        wp_safe_redirect(add_query_arg([
            'page' => 'avpvh-activity-participation-detail', 'id' => $participation_id, 'activity_id' => $activity_id, 'updated' => '1',
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * The "Instellingen" form on the Activiteiten page only ever edits
     * whichever activity is currently selected in the page's own dropdown
     * — there was no way to create a new one at all, so someone trying to
     * make e.g. a new "Contributie 2025" activity would silently overwrite
     * whatever activity happened to be selected instead (discovered when
     * this exact thing corrupted the live "Goeblange" kamp activity's type
     * and dates). This is the missing "actually create a new row" path,
     * a thin wrapper around the existing AVPVH_DB::get_or_create_activity()
     * (already idempotent on name+year, so resubmitting is harmless).
     */
    public function handle_create_activity(): void {
        check_admin_referer('avpvh_create_activity');
        if (!$this->can_access_page('activities')) {
            wp_die('Geen toegang.', 403);
        }

        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $year = absint(wp_unslash($_POST['year'] ?? 0));
        if ($name === '' || !$year) {
            wp_die('Naam en jaar zijn verplicht.', 400);
        }

        $activity_id = AVPVH_DB::get_or_create_activity(
            $name,
            $year,
            sanitize_text_field(wp_unslash($_POST['kenmerk'] ?? '')),
            sanitize_text_field(wp_unslash($_POST['start_date'] ?? '')) ?: null,
            sanitize_text_field(wp_unslash($_POST['end_date'] ?? '')) ?: null,
            absint(wp_unslash($_POST['type_id'] ?? 0))
        );

        wp_safe_redirect(add_query_arg([
            'page' => 'avpvh-activity-participation', 'activity_id' => $activity_id, 'activity_created' => '1',
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_save_activity(): void {
        check_admin_referer('avpvh_save_activity');
        if (!$this->can_access_page('activities')) {
            wp_die('Geen toegang.', 403);
        }

        $activity_id = absint(wp_unslash($_POST['activity_id'] ?? 0));
        $type_id = absint(wp_unslash($_POST['type_id'] ?? 0));
        global $wpdb;
        $wpdb->update("{$wpdb->prefix}avm_activities", [
            'kenmerk'    => sanitize_text_field(wp_unslash($_POST['kenmerk'] ?? '')),
            'type_id'    => $type_id ?: null,
            'start_date' => sanitize_text_field(wp_unslash($_POST['start_date'] ?? '')) ?: null,
            'end_date'   => sanitize_text_field(wp_unslash($_POST['end_date'] ?? '')) ?: null,
        ], ['id' => $activity_id]);

        wp_safe_redirect(add_query_arg([
            'page' => 'avpvh-activity-participation', 'activity_id' => $activity_id, 'activity_saved' => '1',
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_save_activity_types(): void {
        check_admin_referer('avpvh_save_activity_types');
        if (!$this->can_access_page('activities')) {
            wp_die('Geen toegang.', 403);
        }

        foreach ((array) wp_unslash($_POST['type_name'] ?? []) as $id => $name) {
            AVPVH_DB::rename_activity_type((int) $id, sanitize_text_field($name));
        }

        $new_name = sanitize_text_field(wp_unslash($_POST['new_type_name'] ?? ''));
        if ($new_name !== '') {
            AVPVH_DB::add_activity_type($new_name);
        }

        $activity_id = absint(wp_unslash($_POST['activity_id'] ?? 0));
        wp_safe_redirect(add_query_arg([
            'page' => 'avpvh-activity-participation', 'activity_id' => $activity_id, 'types_saved' => '1',
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_export_activity_participation(): void {
        check_admin_referer('avpvh_export_activity_participation');
        if (!$this->can_access_page('activities')) {
            wp_die('Geen toegang.', 403);
        }

        $activity_id = absint(wp_unslash($_GET['activity_id'] ?? 0));
        $activity = AVPVH_DB::get_activity($activity_id);
        if (!$activity) {
            wp_die('Activiteit niet gevonden.', 404);
        }

        require_once AVPVH_PLUGIN_DIR . 'includes/class-activity-participation-export.php';
        $bytes = AVPVH_Activity_Participation_Export::build($activity);

        $filename = sanitize_file_name('deelname-' . $activity->name . '-' . $activity->year . '-' . current_time('Y-m-d') . '.xlsx');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($bytes));
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw .xlsx binary file download, not HTML
        echo $bytes;
        exit;
    }

    public function handle_delegate_role(): void {
        check_admin_referer('avpvh_delegate_role');
        if (!$this->can_manage_roles()) {
            wp_die('Geen toegang.', 403);
        }

        $by_member = avpvh_get_member_by_wp_user(get_current_user_id());
        $role      = sanitize_key(wp_unslash($_POST['role'] ?? ''));
        $to_member_id = absint(wp_unslash($_POST['delegated_to_member_id'] ?? 0));
        $ends_at_raw  = sanitize_text_field(wp_unslash($_POST['ends_at'] ?? ''));
        // Datetime-local input ("2026-08-20T18:00") -> MySQL DATETIME, end of
        // day if only a date was somehow submitted. Blank = indefinite.
        $ends_at = $ends_at_raw !== '' ? str_replace('T', ' ', $ends_at_raw) . (strlen($ends_at_raw) === 10 ? ':00' : '') : null;

        $allowed_roles = array_merge(AVPVH_Roles::OFFICER_ROLES, [AVPVH_Roles::IT_ROLE]);
        if (!$by_member || !$to_member_id || !in_array($role, $allowed_roles, true)) {
            wp_safe_redirect(add_query_arg(['page' => 'avpvh-roles', 'delegate_error' => '1'], admin_url('admin.php')));
            exit;
        }

        $ok = AVPVH_Roles::create_delegation($role, $to_member_id, (int) $by_member->id, $ends_at);
        wp_safe_redirect(add_query_arg(
            ['page' => 'avpvh-roles', $ok ? 'delegate_ok' : 'delegate_error' => '1'],
            admin_url('admin.php')
        ));
        exit;
    }

    public function handle_revoke_delegation(): void {
        check_admin_referer('avpvh_revoke_delegation');
        if (!$this->can_manage_roles()) {
            wp_die('Geen toegang.', 403);
        }

        $delegation_id = absint(wp_unslash($_POST['delegation_id'] ?? 0));
        $delegation = AVPVH_Roles::get_delegation($delegation_id);
        if (!$delegation || ($delegation->role === AVPVH_Roles::IT_ROLE && !AVPVH_Roles::current_user_is_chair())) {
            wp_die('Alleen de voorzitter kan een IT-beheerder intrekken.', 403);
        }

        AVPVH_Roles::revoke_delegation($delegation_id);
        wp_safe_redirect(add_query_arg(['page' => 'avpvh-roles', 'revoke_ok' => '1'], admin_url('admin.php')));
        exit;
    }

    public function handle_save_page_permissions(): void {
        check_admin_referer('avpvh_save_page_permissions');
        if (!AVPVH_Roles::current_user_is_it_admin()) {
            wp_die('Alleen de IT-beheerder kan paginarechten wijzigen.', 403);
        }

        AVPVH_Roles::save_page_permissions((array) ($_POST['permissions'] ?? []));
        wp_safe_redirect(add_query_arg(
            ['page' => 'avpvh-page-permissions', 'updated' => '1'],
            admin_url('admin.php')
        ));
        exit;
    }
}
