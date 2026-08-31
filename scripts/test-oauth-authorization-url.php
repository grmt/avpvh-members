<?php
/** Standalone regression test for OAuth authorization URL parameters. */

define('ABSPATH', __DIR__ . '/');

class WP_REST_Request
{
    public function get_param(string $key): mixed
    {
        return null;
    }
}

class AVPVH_Test_Redirect extends RuntimeException
{
    public function __construct(public readonly string $url)
    {
        parent::__construct('Redirect captured');
    }
}

function add_action(): void
{
}

function add_filter(): void
{
}

function get_option(): string
{
    return 'test-client-id';
}

function wp_generate_password(): string
{
    return 'test-state';
}

function set_transient(): void
{
}

function rest_url(string $path): string
{
    return 'https://example.invalid/wp-json/' . ltrim($path, '/');
}

function wp_safe_redirect(string $url): void
{
    throw new AVPVH_Test_Redirect($url);
}

require_once dirname(__DIR__) . '/includes/class-oauth.php';

$oauth = new AVPVH_OAuth();
$request = new WP_REST_Request();

foreach (array_keys(AVPVH_OAuth::PROVIDERS) as $provider) {
    try {
        $oauth->start($provider, $request);
        fwrite(STDERR, "$provider did not redirect\n");
        exit(1);
    } catch (AVPVH_Test_Redirect $redirect) {
        parse_str((string) parse_url($redirect->url, PHP_URL_QUERY), $params);
        if (($params['prompt'] ?? null) !== 'select_account') {
            fwrite(STDERR, "$provider does not require explicit account selection\n");
            exit(1);
        }
    }
}

echo "OAuth authorization URL tests: OK\n";
