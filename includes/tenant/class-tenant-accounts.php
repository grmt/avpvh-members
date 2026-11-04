<?php
/**
 * Account names on a tenant site are not for visitors: a login name is
 * half of a login. For visitors the REST user routes do not exist, author
 * archives (also ?author=<id>) are a 404, and the users sitemap and the
 * oEmbed author fields are left out. Logged-in users (the block editor)
 * keep the REST routes; WordPress checks their rights there.
 */

defined('ABSPATH') || exit;

class AVPVH_Tenant_Accounts {

    public function __construct() {
        add_filter('rest_endpoints',           [$this, 'filter_rest_endpoints']);
        add_action('template_redirect',        [$this, 'hide_author_archive'], 1);
        add_filter('oembed_response_data',     [$this, 'hide_oembed_author']);
        add_filter('wp_sitemaps_add_provider', [$this, 'drop_user_sitemap'], 10, 2);
    }

    public function filter_rest_endpoints(array $endpoints): array {
        if (is_user_logged_in()) {
            return $endpoints;
        }
        foreach (array_keys($endpoints) as $route) {
            if (preg_match('#^/wp/v2/users(?:/|$)#', (string) $route)) {
                unset($endpoints[$route]);
            }
        }
        return $endpoints;
    }

    public function hide_author_archive(): void {
        if (!is_author() || is_user_logged_in()) {
            return;
        }
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();
        // WordPress would otherwise redirect ?author=<id> to the name.
        remove_action('template_redirect', 'redirect_canonical');
    }

    public function hide_oembed_author(array $data): array {
        unset($data['author_name'], $data['author_url']);
        return $data;
    }

    /** @param mixed $provider */
    public function drop_user_sitemap($provider, string $name) {
        return $name === 'users' ? false : $provider;
    }
}
