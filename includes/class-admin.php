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
        add_action('admin_post_avpvh_appoint_officer',   [$this, 'handle_appoint_officer']);
        add_action('admin_post_avpvh_set_bestuur',       [$this, 'handle_set_bestuur']);
        add_action('admin_post_avpvh_step_down',         [$this, 'handle_step_down']);
        add_action('admin_post_avpvh_self_delegate_secretaris', [$this, 'handle_self_delegate_secretaris']);
        add_action('admin_post_avpvh_update_address',     [$this, 'handle_update_address']);
        add_action('admin_post_avpvh_update_email',       [$this, 'handle_update_email']);
        add_action('admin_post_avpvh_save_groups',        [$this, 'handle_save_groups']);
        add_action('admin_post_avpvh_delete_address',     [$this, 'handle_delete_address']);
        add_action('admin_post_avpvh_add_member',         [$this, 'handle_add_member']);
        add_action('admin_post_avpvh_save_member_flags',  [$this, 'handle_save_member_flags']);
        add_action('admin_post_avpvh_create_flag',        [$this, 'handle_create_flag']);
        add_action('admin_post_avpvh_delete_flag',        [$this, 'handle_delete_flag']);
        add_action('admin_post_avpvh_send_newsletter',    [$this, 'handle_send_newsletter']);
    }

    // See AVPVH_Roles::can_manage_roles(): WP admins plus voorzitter.
    // Board members log in as plain 'contributor' WP users, so this is
    // checked by hand rather than through a WP capability.
    private function can_manage_roles(): bool {
        return AVPVH_Roles::can_manage_roles();
    }

    // See AVPVH_Roles::can_manage_members(): WP admins plus the officer
    // roles in AVPVH_Roles::MEMBER_ADMIN_ROLES get Ledenbeheer/Ledendetail/
    // Nieuw lid only. Activiteiten has its own, narrower rule
    // (AVPVH_Roles::can_manage_activities(): secretaris only), as does
    // Nieuwsbrief (can_send_newsletter(): secretaris); Instellingen and
    // Loginpogingen stay manage_options-only.
    private function can_manage_members(): bool {
        return AVPVH_Roles::can_manage_members();
    }

    public function register_menus(): void {
        // add_menu_page()/add_submenu_page()'s capability must be a real WP
        // capability string, not a club role — 'read' (every logged-in user
        // has it) stands in for "yes" here, since register_menus() itself
        // re-runs per admin pageview for whoever's viewing, same trick
        // already used below for can_manage_roles()/'Rollen & delegatie'.
        $members_cap = $this->can_manage_members() ? 'read' : 'manage_options';
        $activities_cap = AVPVH_Roles::can_manage_activities() ? 'read' : 'manage_options';
        $newsletter_cap = AVPVH_Roles::can_send_newsletter() ? 'read' : 'manage_options';

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
        add_submenu_page(
            'avpvh-members', 'Loginpogingen', 'Loginpogingen', 'manage_options',
            'avpvh-login-attempts', [$this, 'render_login_attempts']
        );
        add_submenu_page(
            'avpvh-members', 'Nieuwsbrief', 'Nieuwsbrief', $newsletter_cap,
            'avpvh-newsletter', [$this, 'render_newsletter']
        );
        add_submenu_page(
            'avpvh-members', 'Instellingen', 'Instellingen', 'manage_options',
            'avpvh-settings', [$this, 'render_settings']
        );

        // Only registered at all when the viewer qualifies — 'read' is the
        // only WP capability every logged-in member has, so gating on the
        // real rule here (rather than in add_submenu_page's capability
        // argument) is what keeps this out of the menu for everyone else.
        if (AVPVH_Roles::can_view_roles_page()) {
            add_submenu_page(
                'avpvh-members', 'Rollen & delegatie', 'Rollen & delegatie', 'read',
                'avpvh-roles', [$this, 'render_roles']
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

    public function render_settings(): void {
        $oauth_test = isset($_GET['oauth_test']) ? sanitize_text_field(wp_unslash($_GET['oauth_test'])) : null;
        $oauth_test_provider = isset($_GET['oauth_provider']) ? sanitize_text_field(wp_unslash($_GET['oauth_provider'])) : null;

        $test_result = null;
        if (isset($_POST['test_lldap'])) {
            check_admin_referer('avpvh_test_lldap');
            $url      = sanitize_url(wp_unslash($_POST['lldap_url'] ?? 'http://lldap:17170'));
            $user     = sanitize_text_field(wp_unslash($_POST['lldap_user'] ?? 'admin'));
            $password = sanitize_text_field(wp_unslash($_POST['lldap_password'] ?? ''));
            $test_result = AVPVH_LLDAP::test_connection_with($url, $user, $password);
        }
        $directory_test = null;
        $directory_sync = null;
        if (isset($_POST['test_directory'])) {
            check_admin_referer('avpvh_directory_tools');
            $directory_test = AVPVH_Directory::test_connection();
        }
        if (isset($_POST['sync_directory'])) {
            check_admin_referer('avpvh_directory_tools');
            $directory_sync = AVPVH_Directory_Cache::full_sync();
        }
        ?>
        <div class="wrap">
            <h1>AVP-PvH Instellingen</h1>
            <?php if ($test_result === true) : ?>
                <div class="notice notice-success"><p>LLDAP verbinding OK.</p></div>
            <?php elseif (is_wp_error($test_result)) : ?>
                <div class="notice notice-error"><p>LLDAP fout: <?php echo esc_html($test_result->get_error_message()); ?></p></div>
            <?php endif; ?>
            <?php if ($directory_test === true) : ?>
                <div class="notice notice-success"><p>Directory-verbinding OK.</p></div>
            <?php elseif (is_wp_error($directory_test)) : ?>
                <div class="notice notice-error"><p>Directory-fout: <?php echo esc_html($directory_test->get_error_message()); ?></p></div>
            <?php endif; ?>
            <?php if (is_array($directory_sync)) : ?>
                <div class="notice notice-<?php echo $directory_sync['ok'] ? 'success' : 'error'; ?>"><p>
                    <?php echo $directory_sync['ok']
                        ? esc_html(sprintf('Gesynchroniseerd: %d accounts, %d verwijderd.', $directory_sync['upserted'], $directory_sync['deleted']))
                        : esc_html('Synchroniseren mislukt: ' . $directory_sync['error']); ?>
                </p></div>
            <?php endif; ?>
            <?php if ($oauth_test === 'ok') : ?>
                <div class="notice notice-success"><p><?php echo esc_html(ucfirst($oauth_test_provider)); ?> credentials OK — client ID en secret zijn geldig.</p></div>
            <?php elseif ($oauth_test === 'fail') : ?>
                <div class="notice notice-error"><p>
                    <?php echo esc_html(ucfirst($oauth_test_provider)); ?> credentials ongeldig — controleer de client ID en secret
                    <?php if ($oauth_test_provider === 'google') : ?>in de Google Cloud Console<?php else : ?>in de Azure portal<?php endif; ?>.
                    <?php if (!empty($_GET['oauth_error'])) : ?>
                        <br><small>Fout: <?php echo esc_html(sanitize_text_field(wp_unslash($_GET['oauth_error']))); ?></small>
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

            <hr>
            <h2>Directory (accounts en groepen)</h2>
            <?php global $wpdb; ?>
            <table class="form-table">
                <tr><th>Backend</th><td><code><?php echo esc_html(AVPVH_Directory::backend_name()); ?></code> <span class="description">(instelbaar met de constante AVPVH_DIRECTORY_BACKEND in wp-config.php)</span></td></tr>
                <?php if (AVPVH_Directory::is_openldap()) : ?>
                    <tr><th>Cache</th><td>
                        <?php echo (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . AVPVH_Directory_Cache::table()); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- fixed table name ($wpdb->prefix + constant), no user input ?> accounts,
                        laatst volledig gesynchroniseerd: <?php echo esc_html(get_option('avpvh_directory_synced_at') ?: 'nog nooit'); ?>
                    </td></tr>
                <?php endif; ?>
            </table>
            <form method="post">
                <?php wp_nonce_field('avpvh_directory_tools'); ?>
                <?php submit_button('Verbinding testen', 'secondary', 'test_directory', false); ?>
                <?php if (AVPVH_Directory::is_openldap()) : ?>
                    <?php submit_button('Nu synchroniseren', 'secondary', 'sync_directory', false); ?>
                <?php endif; ?>
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
        if (!$this->can_manage_members()) {
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
        if (!$this->can_manage_members()) {
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
        if (!$this->can_manage_members()) {
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
                $result = AVPVH_Directory::update_user($member->lldap_user_id, ['mail' => $identity->email]);
                if (is_wp_error($result)) {
                    error_log("AVPVH_Admin: failed to sync primary identity ({$identity->email}) to the directory for member {$member_id}: " . $result->get_error_message());
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
        $stripped = false;
        if ($member_id) {
            AVPVH_DB::set_member_flags($member_id, $flag_ids);
            // Geroyeerd (or another kenmerk that makes a member inactive):
            // they can't stay in the bestuur or hold an officer role.
            if (AVPVH_DB::member_has_inactivating_flag($member_id)) {
                $stripped = AVPVH_Roles::strip_bestuur_roles($member_id);
            }
        }

        wp_safe_redirect(add_query_arg(array_filter(['page' => 'avpvh-member-detail', 'id' => $member_id, 'flags_saved' => '1', 'bestuur_stripped' => $stripped ? '1' : null]), admin_url('admin.php')));
        exit;
    }

    public function handle_create_flag(): void {
        check_admin_referer('avpvh_create_flag');
        if (!current_user_can('manage_options')) {
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
        if (!current_user_can('manage_options')) {
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
        if (!AVPVH_Roles::can_send_newsletter()) {
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

        $result = AVPVH_Directory::update_user($member->lldap_user_id, ['mail' => $email]);
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

        $all_groups     = AVPVH_Directory::list_groups();
        $current_groups = AVPVH_Directory::get_user_groups($member->lldap_user_id);
        if (is_wp_error($all_groups) || is_wp_error($current_groups)) {
            wp_safe_redirect(add_query_arg(['page' => 'avpvh-member-detail', 'id' => $member_id, 'tab' => 'contact', 'groups_error' => '1'], admin_url('admin.php')));
            exit;
        }

        // Only PvH groups are on the form, so only those may change: a
        // member's groups of other tenants (or lldap_* system groups) must
        // survive a save untouched, and a forged POST can't add them.
        $pvh_groups = AVPVH_Directory::only_pvh_groups($all_groups);
        $selected   = array_values(array_intersect(array_map('strtolower', array_map('sanitize_text_field', (array) wp_unslash($_POST['groups'] ?? []))), $pvh_groups));
        $current    = AVPVH_Directory::only_pvh_groups($current_groups);

        $had_error = false;
        foreach (array_diff($selected, $current) as $group) {
            $result = AVPVH_Directory::add_to_group($member->lldap_user_id, $group);
            if (is_wp_error($result)) {
                $had_error = true;
                error_log("AVPVH_Admin: failed to add member {$member_id} to group {$group}: " . $result->get_error_message());
            }
        }
        foreach (array_diff($current, $selected) as $group) {
            $result = AVPVH_Directory::remove_from_group($member->lldap_user_id, $group);
            if (is_wp_error($result)) {
                $had_error = true;
                error_log("AVPVH_Admin: failed to remove member {$member_id} from group {$group}: " . $result->get_error_message());
            }
        }

        // Otherwise role checks, the ledenlijst, and the member's own
        // "Groepen:" display wouldn't reflect this for up to 15 minutes.
        AVPVH_Directory::forget_groups($member->lldap_user_id);

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
        $birth_raw  = trim(sanitize_text_field(wp_unslash($_POST['birth_date'] ?? '')));
        [$birth_date, $birth_year] = AVPVH_Member_Profile_Form::parse_birth_date($birth_raw);
        $status     = sanitize_key(wp_unslash($_POST['status'] ?? 'inactive'));
        $status     = in_array($status, ['active', 'inactive', 'visitor'], true) ? $status : 'inactive';
        $confirmed  = !empty($_POST['confirmed']);

        if ($first_name === '' || $last_name === '') {
            wp_safe_redirect(add_query_arg([
                'page' => 'avpvh-add-member', 'add_member_error' => 'onvolledig',
            ], admin_url('admin.php')));
            exit;
        }

        if ($birth_raw !== '' && $birth_date === null && $birth_year === null) {
            wp_safe_redirect(add_query_arg([
                'page' => 'avpvh-add-member', 'add_member_error' => 'geboortedatum',
            ], admin_url('admin.php')));
            exit;
        }

        if (!$confirmed) {
            $matches = AVPVH_DB::find_members_by_name($first_name, $last_name);
            if ($matches) {
                set_transient('avpvh_add_member_pending_' . get_current_user_id(), [
                    'first_name' => $first_name, 'suffix' => $suffix, 'last_name' => $last_name,
                    'birth_date' => $birth_raw, 'status' => $status,
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
        while (AVPVH_Directory::user_exists($uid)) {
            $n++;
            $uid = "{$base_uid}{$n}";
        }
        $email = "{$uid}@avpvh.local";
        $display_name = trim(preg_replace('/\s+/', ' ', "{$first_name} {$suffix} {$last_name}"));

        $created = AVPVH_Directory::create_user($uid, $email, $display_name);
        if (is_wp_error($created)) {
            wp_safe_redirect(add_query_arg([
                'page' => 'avpvh-add-member', 'add_member_error' => 'lldap',
                'add_member_error_message' => rawurlencode($created->get_error_message()),
            ], admin_url('admin.php')));
            exit;
        }

        $added = AVPVH_Directory::add_to_group($uid, 'leden');
        if (is_wp_error($added)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operational log of a failed directory action, for the server log; not debug output
            error_log("AVPVH_Admin: new member {$uid} not added to leden: " . $added->get_error_message());
        }
        AVPVH_Directory::forget_groups($uid);

        $member_id = AVPVH_DB::create_member($uid, $first_name, $suffix, $last_name, $birth_date, $status, $birth_year);

        wp_safe_redirect(add_query_arg(['page' => 'avpvh-member-detail', 'id' => $member_id, 'created' => '1'], admin_url('admin.php')));
        exit;
    }

    public function handle_save_participation(): void {
        check_admin_referer('avpvh_save_participation');
        if (!AVPVH_Roles::can_manage_activities()) {
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
        if (!AVPVH_Roles::can_manage_activities()) {
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
        if (!AVPVH_Roles::can_manage_activities()) {
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
        if (!AVPVH_Roles::can_manage_activities()) {
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
        if (!AVPVH_Roles::can_manage_activities()) {
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
        // Datetime-local input ("2026-08-20T18:00") -> MySQL DATETIME; a bare
        // date means the end of that day. Blank = indefinite.
        $ends_at = null;
        if ($ends_at_raw !== '') {
            // Round-trip check: createFromFormat() silently rolls "2026-13-01"
            // or "25:00" over into another date instead of failing.
            $format = strlen($ends_at_raw) === 10 ? 'Y-m-d' : 'Y-m-d\TH:i';
            $parsed = \DateTime::createFromFormat('!' . $format, $ends_at_raw);
            if (!$parsed || $parsed->format($format) !== $ends_at_raw) {
                $this->roles_redirect('delegate_error');
            }
            $ends_at = $parsed->format(strlen($ends_at_raw) === 10 ? 'Y-m-d 23:59:59' : 'Y-m-d H:i:s');
            if ($ends_at <= current_time('mysql')) {
                $this->roles_redirect('delegate_past');
            }
        }

        $candidate_ids = array_map(static fn($m) => (int) $m->id, AVPVH_Roles::get_officer_candidates());
        // WP admins may delegate without a member record of their own (or
        // without a club role); everyone else must be a member.
        $is_admin = current_user_can('manage_options');
        if ((!$by_member && !$is_admin) || !in_array($to_member_id, $candidate_ids, true) || !in_array($role, AVPVH_Roles::OFFICER_ROLES, true)) {
            $this->roles_redirect('delegate_error');
        }

        // A non-bestuurslid may stand in for an officer role, but only
        // temporarily: the end date is required, so it can never quietly
        // turn into a permanent role outside the bestuur.
        if ($ends_at === null && !in_array('bestuur', AVPVH_Roles::get_member_roles($to_member_id), true)) {
            $this->roles_redirect('delegate_needs_end');
        }

        $ok = AVPVH_Roles::create_delegation($role, $to_member_id, $by_member ? (int) $by_member->id : 0, $ends_at, $is_admin);
        wp_safe_redirect(add_query_arg(
            ['page' => 'avpvh-roles', $ok ? 'delegate_ok' : 'delegate_error' => '1'],
            admin_url('admin.php')
        ));
        exit;
    }

    private function roles_redirect(string $key): never {
        wp_safe_redirect(add_query_arg(['page' => 'avpvh-roles', $key => '1'], admin_url('admin.php')));
        exit;
    }

    public function handle_appoint_officer(): void {
        check_admin_referer('avpvh_appoint_officer');
        if (!AVPVH_Roles::can_appoint_officers()) {
            wp_die('Geen toegang.', 403);
        }

        $role          = sanitize_key(wp_unslash($_POST['role'] ?? ''));
        $to_member_id  = absint(wp_unslash($_POST['new_holder_id'] ?? 0));
        $candidate_ids = array_map(static fn($m) => (int) $m->id, AVPVH_Roles::get_officer_candidates());
        if (!in_array($role, AVPVH_Roles::OFFICER_ROLES, true) || !in_array($to_member_id, $candidate_ids, true)) {
            wp_safe_redirect(add_query_arg(['page' => 'avpvh-roles', 'appoint_error' => '1'], admin_url('admin.php')));
            exit;
        }
        // Normally only bestuursleden become rolhouder; appointing anyone
        // else needs the explicit "geen bestuurslid (uitzondering)" box.
        if (empty($_POST['allow_non_bestuur']) && !in_array('bestuur', AVPVH_Roles::get_member_roles($to_member_id), true)) {
            wp_safe_redirect(add_query_arg(['page' => 'avpvh-roles', 'appoint_needs_exception' => '1'], admin_url('admin.php')));
            exit;
        }

        $result = AVPVH_Roles::appoint_officer($role, $to_member_id);
        if (is_wp_error($result)) {
            error_log("AVPVH_Admin: appointing {$role} failed: " . $result->get_error_message());
        }
        wp_safe_redirect(add_query_arg(
            ['page' => 'avpvh-roles', is_wp_error($result) ? 'appoint_error' : 'appoint_ok' => $role],
            admin_url('admin.php')
        ));
        exit;
    }

    public function handle_self_delegate_secretaris(): void {
        check_admin_referer('avpvh_self_delegate_secretaris');
        if (!AVPVH_Roles::can_self_delegate_secretaris()) {
            wp_die('Geen toegang.', 403);
        }
        $me  = avpvh_get_member_by_wp_user(get_current_user_id());
        $raw = sanitize_text_field(wp_unslash($_POST['ends_at'] ?? ''));
        $parsed = \DateTime::createFromFormat('!Y-m-d\TH:i', $raw);
        if (!$parsed || $parsed->format('Y-m-d\TH:i') !== $raw) {
            $this->roles_redirect('self_delegate_error');
        }
        $ends_at = $parsed->format('Y-m-d H:i:s');
        $max     = wp_date('Y-m-d H:i:s', time() + AVPVH_Roles::SELF_DELEGATION_MAX_HOURS * HOUR_IN_SECONDS);
        if ($ends_at <= current_time('mysql') || $ends_at > $max) {
            $this->roles_redirect('self_delegate_error');
        }
        $ok = AVPVH_Roles::create_delegation('secretaris', (int) $me->id, (int) $me->id, $ends_at);
        $this->roles_redirect($ok ? 'self_delegate_ok' : 'self_delegate_error');
    }

    public function handle_step_down(): void {
        check_admin_referer('avpvh_step_down');
        $role      = sanitize_key(wp_unslash($_POST['role'] ?? ''));
        $member_id = absint(wp_unslash($_POST['member_id'] ?? 0));
        if (!AVPVH_Roles::can_step_down($role, $member_id)) {
            wp_die('Geen toegang.', 403);
        }
        $result = AVPVH_Roles::step_down($role, $member_id);
        if (is_wp_error($result)) {
            error_log("AVPVH_Admin: stepping down as {$role} failed: " . $result->get_error_message());
        }
        wp_safe_redirect(add_query_arg(
            ['page' => 'avpvh-roles', is_wp_error($result) ? 'step_down_error' : 'step_down_ok' => $role],
            admin_url('admin.php')
        ));
        exit;
    }

    public function handle_set_bestuur(): void {
        check_admin_referer('avpvh_set_bestuur');
        if (!AVPVH_Roles::can_appoint_officers()) {
            wp_die('Geen toegang.', 403);
        }

        $member_id = absint(wp_unslash($_POST['member_id'] ?? 0));
        $add       = sanitize_key(wp_unslash($_POST['op'] ?? '')) === 'add';
        $result    = $member_id ? AVPVH_Roles::set_bestuur_member($member_id, $add) : new \WP_Error('avpvh_no_member', 'Geen lid gekozen.');
        if (is_wp_error($result)) {
            error_log('AVPVH_Admin: changing bestuur failed: ' . $result->get_error_message());
        }
        wp_safe_redirect(add_query_arg(
            ['page' => 'avpvh-roles', is_wp_error($result) ? 'bestuur_error' : ($add ? 'bestuur_added' : 'bestuur_removed') => '1'],
            admin_url('admin.php')
        ));
        exit;
    }

    public function handle_revoke_delegation(): void {
        check_admin_referer('avpvh_revoke_delegation');
        if (!$this->can_manage_roles()) {
            wp_die('Geen toegang.', 403);
        }

        AVPVH_Roles::revoke_delegation(absint(wp_unslash($_POST['delegation_id'] ?? 0)));
        wp_safe_redirect(add_query_arg(['page' => 'avpvh-roles', 'revoke_ok' => '1'], admin_url('admin.php')));
        exit;
    }
}
