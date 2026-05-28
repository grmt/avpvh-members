<?php
defined('ABSPATH') || exit;

class AVPVH_Google_Sheets_Auth {

    private string $client_id;
    private string $client_secret;
    private string $redirect_uri;

    public function __construct() {
        $this->client_id = get_option('avpvh_google_sheets_client_id', '');
        $this->client_secret = get_option('avpvh_google_sheets_client_secret', '');
        $this->redirect_uri = admin_url('admin.php?page=avpvh-settings&tab=google-sheets');

        add_action('wp_ajax_avpvh_google_auth_start', [$this, 'handle_auth_start']);
        add_action('wp_ajax_avpvh_google_auth_callback', [$this, 'handle_auth_callback']);
    }

    /**
     * Render Google Sheets auth settings section.
     */
    public static function render_settings_section(): void {
        $token = get_option('avpvh_google_sheets_token');
        $sheet_id = get_option('avpvh_google_sheets_id', '');
        $is_connected = !empty($token);

        ?>
        <h3>Google Sheets Integration</h3>

        <table class="form-table">
            <tr>
                <th scope="row"><label for="avpvh_google_sheets_id">Google Sheet ID</label></th>
                <td>
                    <input type="text" id="avpvh_google_sheets_id" name="avpvh_google_sheets_id"
                        value="<?php echo esc_attr($sheet_id); ?>" class="regular-text"
                        placeholder="1q1N1aVDoo5SKFlkxTCJjnGbFXTQmlaKbre3X59V8kZo">
                    <p class="description">The ID from your Google Sheets URL (sheets.google.com/spreadsheets/d/<strong>[ID]</strong>/edit)</p>
                </td>
            </tr>

            <tr>
                <th scope="row">Connection Status</th>
                <td>
                    <?php if ($is_connected): ?>
                        <p style="color: green;">✓ <strong>Connected</strong></p>
                        <button type="button" class="button" id="avpvh-google-disconnect">Disconnect</button>
                    <?php else: ?>
                        <p style="color: red;">✗ <strong>Not Connected</strong></p>
                        <button type="button" class="button button-primary" id="avpvh-google-auth">Authorize Google Sheets</button>
                    <?php endif; ?>
                </td>
            </tr>

            <tr>
                <th scope="row">Test Connection</th>
                <td>
                    <button type="button" class="button" id="avpvh-google-test" <?php disabled(!$is_connected); ?>>Test Access</button>
                    <span id="avpvh-google-test-result"></span>
                </td>
            </tr>
        </table>

        <script>
        (function($) {
            $(document).ready(function() {
                $('#avpvh-google-auth').on('click', function() {
                    $.post(ajaxurl, {
                        action: 'avpvh_google_auth_start',
                    }, function(response) {
                        if (response.success) {
                            window.location.href = response.data.auth_url;
                        } else {
                            alert('Failed to start authorization: ' + response.data);
                        }
                    });
                });

                $('#avpvh-google-disconnect').on('click', function() {
                    if (confirm('Disconnect from Google Sheets?')) {
                        $.post(ajaxurl, {
                            action: 'avpvh_google_disconnect',
                        }, function() {
                            location.reload();
                        });
                    }
                });

                $('#avpvh-google-test').on('click', function() {
                    const $result = $('#avpvh-google-test-result');
                    $result.text('Testing...');

                    $.post(ajaxurl, {
                        action: 'avpvh_google_test_sheets',
                    }, function(response) {
                        if (response.success) {
                            $result.text('✓ Connection successful!').css('color', 'green');
                        } else {
                            $result.text('✗ ' + response.data).css('color', 'red');
                        }
                    });
                });
            });
        })(jQuery);
        </script>

        <style>
        #avpvh-google-test-result {
            display: inline-block;
            margin-left: 1rem;
            font-weight: bold;
        }
        </style>
        <?php
    }

    /**
     * Handle authorization start.
     */
    public function handle_auth_start(): void {
        check_ajax_referer('avpvh_settings_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        try {
            $client = $this->get_google_client();
            $auth_url = $client->createAuthUrl();

            wp_send_json_success(['auth_url' => $auth_url]);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Handle authorization callback.
     * This should be called from the Google OAuth redirect.
     */
    public function handle_auth_callback(): void {
        if (empty($_REQUEST['code'])) {
            wp_die('Missing authorization code');
        }

        try {
            $client = $this->get_google_client();
            $token = $client->fetchAccessTokenWithAuthCode($_REQUEST['code']);

            if (array_key_exists('error', $token)) {
                throw new Exception($token['error_description']);
            }

            update_option('avpvh_google_sheets_token', json_encode($token));

            // Redirect back to settings
            wp_safe_redirect(admin_url('admin.php?page=avpvh-settings&tab=google-sheets&connected=1'));
            exit;
        } catch (Exception $e) {
            wp_die('Authorization failed: ' . esc_html($e->getMessage()));
        }
    }

    /**
     * Get configured Google Client instance.
     */
    private function get_google_client() {
        $client = new Client();
        $client->setApplicationName('AVP Philips van Horne');
        $client->setClientId($this->client_id);
        $client->setClientSecret($this->client_secret);
        $client->setRedirectUri($this->redirect_uri);
        $client->setAccessType('offline');
        $client->setScopes([
            'https://www.googleapis.com/auth/spreadsheets',
            'https://www.googleapis.com/auth/drive',
        ]);

        return $client;
    }

    /**
     * Test Google Sheets access.
     */
    public static function handle_test_connection(): void {
        check_ajax_referer('avpvh_settings_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        try {
            $sync = new AVPVH_Google_Sheets_Sync();
            if ($sync->test_access()) {
                wp_send_json_success('Connection successful!');
            } else {
                wp_send_json_error('Connection failed. Check your credentials.');
            }
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Disconnect from Google Sheets.
     */
    public static function handle_disconnect(): void {
        check_ajax_referer('avpvh_settings_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        delete_option('avpvh_google_sheets_token');
        wp_send_json_success();
    }
}

// Hook up AJAX handlers
add_action('wp_ajax_avpvh_google_test_sheets', ['AVPVH_Google_Sheets_Auth', 'handle_test_connection']);
add_action('wp_ajax_avpvh_google_disconnect', ['AVPVH_Google_Sheets_Auth', 'handle_disconnect']);

new AVPVH_Google_Sheets_Auth();
