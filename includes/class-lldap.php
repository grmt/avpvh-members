<?php
defined('ABSPATH') || exit;

class AVPVH_LLDAP {

    private static ?string $token = null;

    private static function url(): string {
        return rtrim(get_option('avpvh_lldap_url', 'http://lldap:17170'), '/');
    }

    private static function token(): string|\WP_Error {
        if (self::$token !== null) {
            return self::$token;
        }
        $response = wp_remote_post(self::url() . '/auth/simple/login', [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode([
                'username' => get_option('avpvh_lldap_user', 'admin'),
                'password' => get_option('avpvh_lldap_password', ''),
            ]),
            'timeout' => 5,
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['token'])) {
            return new \WP_Error('lldap_auth', 'LLDAP login failed: ' . wp_remote_retrieve_body($response));
        }
        self::$token = $body['token'];
        return self::$token;
    }

    private static function graphql(string $query, array $variables = []): array|\WP_Error {
        $token = self::token();
        if (is_wp_error($token)) {
            return $token;
        }
        $response = wp_remote_post(self::url() . '/api/graphql', [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
            'body'    => wp_json_encode(['query' => $query, 'variables' => $variables]),
            'timeout' => 10,
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($body['errors'])) {
            return new \WP_Error('lldap_graphql', $body['errors'][0]['message'] ?? 'GraphQL error');
        }
        return $body['data'] ?? [];
    }

    public static function create_user(string $uid, string $email, string $display_name): array|\WP_Error {
        return self::graphql('
            mutation CreateUser($user: CreateUserInput!) {
                createUser(user: $user) { id email displayName }
            }',
            ['user' => ['id' => $uid, 'email' => $email, 'displayName' => $display_name]]
        );
    }

    public static function update_user(string $uid, array $fields): array|\WP_Error {
        $fields['id'] = $uid;
        return self::graphql('
            mutation UpdateUser($user: UpdateUserInput!) {
                updateUser(user: $user) { ok }
            }',
            ['user' => $fields]
        );
    }

    public static function delete_user(string $uid): array|\WP_Error {
        return self::graphql('
            mutation DeleteUser($userId: String!) {
                deleteUser(userId: $userId) { ok }
            }',
            ['userId' => $uid]
        );
    }

    public static function add_to_group(string $uid, int $group_id): array|\WP_Error {
        return self::graphql('
            mutation AddUserToGroup($userId: String!, $groupId: Int!) {
                addUserToGroup(userId: $userId, groupId: $groupId) { ok }
            }',
            ['userId' => $uid, 'groupId' => $group_id]
        );
    }

    public static function remove_from_group(string $uid, int $group_id): array|\WP_Error {
        return self::graphql('
            mutation RemoveUserFromGroup($userId: String!, $groupId: Int!) {
                removeUserFromGroup(userId: $userId, groupId: $groupId) { ok }
            }',
            ['userId' => $uid, 'groupId' => $group_id]
        );
    }

    public static function list_groups(): array|\WP_Error {
        $data = self::graphql('query { groups { id displayName } }');
        if (is_wp_error($data)) {
            return $data;
        }
        return $data['groups'] ?? [];
    }

    public static function get_user_groups(string $uid): array|\WP_Error {
        $data = self::graphql('
            query GetUserGroups($userId: String!) {
                user(userId: $userId) { groups { id displayName } }
            }',
            ['userId' => $uid]
        );
        if (is_wp_error($data)) {
            return $data;
        }
        return $data['user']['groups'] ?? [];
    }

    public static function test_connection_with(string $url, string $user, string $password): bool|\WP_Error {
        $response = wp_remote_post(rtrim($url, '/') . '/auth/simple/login', [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode(['username' => $user, 'password' => $password]),
            'timeout' => 5,
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['token'])) {
            return new \WP_Error('lldap_auth', 'Login mislukt: ' . wp_remote_retrieve_body($response));
        }
        return true;
    }

    // Derive a stable LLDAP uid from an email address.
    public static function uid_from_email(string $email): string {
        $local = strtolower(strstr($email, '@', true));
        return preg_replace('/[^a-z0-9._-]/', '.', $local);
    }
}
