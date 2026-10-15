<?php
/**
 * Members-only pages and the login/logout menu item on a tenant site.
 *
 * The pages named in members_only_pages (by slug) and all their
 * descendants are readable only for users whose directory groups include
 * one of content_groups (default: the member group). For everyone else
 * they are absent from menus, search, feeds, the REST API and sitemaps,
 * and a direct visit leads to the login (or a 403 when logged in).
 */

defined('ABSPATH') || exit;

class AVPVH_Tenant_Content {

    private AVPVH_Tenant_Config $config;
    private AVPVH_Tenant_SSO $sso;

    public function __construct(AVPVH_Tenant_Config $config, AVPVH_Tenant_SSO $sso) {
        $this->config = $config;
        $this->sso    = $sso;
        add_action('template_redirect',          [$this, 'guard_request'], 1);
        add_action('pre_get_posts',              [$this, 'exclude_from_queries']);
        add_filter('wp_nav_menu_objects',        [$this, 'filter_menu_objects']);
        add_filter('wp_nav_menu_items',          [$this, 'add_account_item'], 10, 2);
        add_filter('get_pages',                  [$this, 'filter_page_list']);
        add_filter('render_block_core/navigation-link',    [$this, 'filter_navigation_link'], 10, 2);
        add_filter('render_block_core/navigation-submenu', [$this, 'filter_navigation_link'], 10, 2);
        add_filter('render_block_core/navigation',         [$this, 'add_account_block_item'], 10, 2);
        add_filter('render_block_core/template-part',      [$this, 'add_account_header_item'], 10, 2);
        add_filter('rest_page_query',            [$this, 'exclude_from_rest_query']);
        add_filter('rest_request_before_callbacks', [$this, 'hide_from_rest_item'], 10, 3);
        add_filter('wp_sitemaps_posts_query_args', [$this, 'exclude_from_rest_query']);
        add_action('wp_head',                    [$this, 'noindex']);
    }

    /** Page IDs of the members-only pages and all their descendants. */
    public function protected_ids(): array {
        static $ids = null;
        if ($ids !== null) {
            return $ids;
        }
        // The lookups below run pre_get_posts again; they see an empty list
        // instead of recursing.
        $ids = [];
        $found = [];
        foreach ($this->config->get('members_only_pages') as $slug) {
            $roots = get_posts([
                'name'             => sanitize_title($slug),
                'post_type'        => 'page',
                'post_status'      => ['publish', 'private', 'draft', 'pending', 'future'],
                'numberposts'      => -1,
                'fields'           => 'ids',
            ]);
            foreach ($roots as $root_id) {
                $children = get_pages(['child_of' => $root_id, 'post_status' => 'publish,private,draft,pending,future']);
                $found = array_merge($found, [(int) $root_id], array_map('intval', wp_list_pluck($children, 'ID')));
            }
        }
        $ids = array_values(array_unique($found));
        return $ids;
    }

    public function current_user_may_read(): bool {
        if (!is_user_logged_in()) {
            return false;
        }
        $groups = AVPVH_Tenant_SSO::user_groups(get_current_user_id());
        return (bool) array_intersect($groups, $this->config->content_groups());
    }

    public function guard_request(): void {
        if (!is_page() || !in_array((int) get_queried_object_id(), $this->protected_ids(), true)) {
            return;
        }
        nocache_headers();
        if ($this->current_user_may_read()) {
            return;
        }
        if (!is_user_logged_in()) {
            wp_safe_redirect($this->sso->login_url($this->current_url()));
            exit;
        }
        wp_die(
            '<p>' . esc_html__('Je bent ingelogd, maar je account heeft geen toegang tot deze pagina. Deze pagina is alleen beschikbaar voor leden.', 'avpvh-members') . '</p>',
            esc_html__('Geen toegang', 'avpvh-members'),
            ['response' => 403]
        );
    }

    public function exclude_from_queries(\WP_Query $query): void {
        if ($this->current_user_may_read() || is_admin() && !wp_doing_ajax()) {
            return;
        }
        // A direct page request is handled by guard_request(), which sends
        // the visitor to the login; every listing leaves the pages out.
        if ($query->is_main_query() && $query->is_page() && !$query->is_search()) {
            return;
        }
        $ids = $this->protected_ids();
        if ($ids) {
            $query->set('post__not_in', array_merge((array) $query->get('post__not_in'), $ids));
        }
    }

    public function exclude_from_rest_query(array $args): array {
        if (!$this->current_user_may_read() && $this->protected_ids()) {
            $args['post__not_in'] = array_merge((array) ($args['post__not_in'] ?? []), $this->protected_ids());
        }
        return $args;
    }

    /**
     * A single protected page over REST (/wp/v2/pages/<id> and its
     * sub-routes) answers 404, as if it did not exist. Checked before the
     * callback runs: a WP_Error from rest_prepare_page breaks the controller.
     *
     * @return mixed the unchanged response, or a WP_Error
     */
    public function hide_from_rest_item($response, array $handler, \WP_REST_Request $request) {
        if (!preg_match('#^/wp/v2/pages/(\d+)#', $request->get_route(), $match)) {
            return $response;
        }
        if (!$this->current_user_may_read() && in_array((int) $match[1], $this->protected_ids(), true)) {
            return new \WP_Error('rest_post_invalid_id', __('Ongeldige bericht-ID.', 'avpvh-members'), ['status' => 404]);
        }
        return $response;
    }

    public function filter_menu_objects(array $items): array {
        if ($this->current_user_may_read()) {
            return $items;
        }
        $ids = $this->protected_ids();
        $hidden = [];
        foreach ($items as $item) {
            if (($item->object === 'page' && in_array((int) $item->object_id, $ids, true))
                || in_array((int) $item->menu_item_parent, $hidden, true)) {
                $hidden[] = (int) $item->ID;
            }
        }
        return array_values(array_filter($items, static fn($item): bool => !in_array((int) $item->ID, $hidden, true)));
    }

    /** Page lists (wp_list_pages(), the Page List block) skip protected pages. */
    public function filter_page_list(array $pages): array {
        if ($this->current_user_may_read()) {
            return $pages;
        }
        $ids = $this->protected_ids();
        return array_values(array_filter($pages, static fn($page): bool => !in_array((int) $page->ID, $ids, true)));
    }

    /** Navigation block links (and submenus) to protected pages. */
    public function filter_navigation_link(string $html, array $block): string {
        $id = (int) ($block['attrs']['id'] ?? 0);
        if ($id && ($block['attrs']['kind'] ?? '') === 'post-type' && !$this->current_user_may_read()
            && in_array($id, $this->protected_ids(), true)) {
            return '';
        }
        return $html;
    }

    /**
     * Block themes: the account link goes at the end of the Navigation
     * blocks whose wp_navigation post is listed in navigation_refs, or of
     * every Navigation block when that setting is 'all'.
     */
    public function add_account_block_item(string $html, array $block): string {
        $refs = $this->config->get('navigation_refs');
        $ref  = (int) ($block['attrs']['ref'] ?? 0);
        if ($refs === 'all' || (is_array($refs) && $ref && in_array($ref, $refs, true))) {
            return $this->append_block_item($html);
        }
        return $html;
    }

    /**
     * navigation_refs 'header': the Navigation block of the header template
     * part, which in block themes often has no ref of its own.
     */
    public function add_account_header_item(string $html, array $block): string {
        $is_header = ($block['attrs']['slug'] ?? '') === 'header' || ($block['attrs']['area'] ?? '') === 'header';
        if ($this->config->get('navigation_refs') !== 'header' || !$is_header || !str_contains($html, 'wp-block-navigation__container')) {
            return $html;
        }
        return $this->append_block_item($html);
    }

    private function append_block_item(string $html): string {
        $close = strrpos($html, '</ul>');
        if ($close === false) {
            return $html;
        }
        $item = '<li class="wp-block-navigation-item wp-block-navigation-link avpvh-account-item">'
            . str_replace('<a ', '<a class="wp-block-navigation-item__content" ', $this->account_link()) . '</li>';
        return substr_replace($html, $item, $close, 0);
    }

    /** "Inloggen" / "Uitloggen" at the end of the configured classic menus. */
    public function add_account_item(string $items, \stdClass $args): string {
        $location = (string) ($args->theme_location ?? '');
        if ($location === '' || !in_array($location, $this->config->get('menu_locations'), true)) {
            return $items;
        }
        return $items . '<li class="menu-item avpvh-account-item">' . $this->account_link() . '</li>';
    }

    /** The account icon is the same person silhouette on every tenant. */
    const ACCOUNT_ICON = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false" style="vertical-align:-3px;margin-right:4px"><path fill="currentColor" d="M12 12c2.7 0 4.9-2.2 4.9-4.9S14.7 2.2 12 2.2 7.1 4.4 7.1 7.1 9.3 12 12 12zm0 2.5c-3.3 0-9.8 1.6-9.8 4.9v2.4h19.6v-2.4c0-3.3-6.5-4.9-9.8-4.9z"/></svg>';

    private function account_link(): string {
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            return '<a href="' . esc_url(wp_logout_url()) . '" title="' . esc_attr(sprintf(
                /* translators: %s: display name of the logged-in user */
                __('Ingelogd als %s', 'avpvh-members'),
                $user->display_name
            )) . '">' . self::ACCOUNT_ICON . esc_html__('Uitloggen', 'avpvh-members') . '</a>';
        }
        return '<a href="' . esc_url($this->sso->login_url($this->current_url())) . '">'
            . self::ACCOUNT_ICON . esc_html__('Inloggen', 'avpvh-members') . '</a>';
    }

    private function current_url(): string {
        return home_url(sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '/')));
    }

    public function noindex(): void {
        if (is_page() && in_array((int) get_queried_object_id(), $this->protected_ids(), true)) {
            echo '<meta name="robots" content="noindex, nofollow">' . "\n";
        }
    }
}
