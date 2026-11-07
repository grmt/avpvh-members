<?php
/**
 * Who may see which blog posts (and, through avpvh-gallery, which photos).
 *
 *   everything  active members, and editors/administrators (edit_pages)
 *   a period    an ex-member the board gave archive access (Ledendetail):
 *               the years joined_year through left_year
 *   nothing     everyone else: visitors, logged-in accounts without a
 *               member record or with status visitor, ex-members without
 *               the board's permission or without both years
 *
 * A post belongs to the year of its post_date. Posts outside the scope are
 * left out of every front-end listing (home, archives, feeds, search, REST
 * collections, previous/next links) and a single one is answered like a
 * members-only page: to the login page, or with a 404 over REST and oEmbed.
 *
 * avpvh-gallery asks current_scope() / year_allowed() for the photos.
 */

defined('ABSPATH') || exit;

class AVPVH_Archive_Access {

    /** current_scope(): no restriction */
    const ALL = 'all';
    /** current_scope(): nothing */
    const NONE = 'none';

    /** @var array<int, true>|null per request: hidden post IDs */
    private static ?array $hidden = null;

    public function __construct() {
        add_action('pre_get_posts',                 [$this, 'filter_query']);
        add_filter('rest_post_query',               [$this, 'filter_rest_args']);
        add_filter('rest_post_search_query',        [$this, 'filter_rest_args']);
        add_filter('rest_request_before_callbacks', [$this, 'hide_post_from_rest'], 10, 3);
        add_filter('oembed_request_post_id',        [$this, 'hide_post_from_oembed']);
        add_filter('get_previous_post_where',       [$this, 'filter_adjacent_where']);
        add_filter('get_next_post_where',           [$this, 'filter_adjacent_where']);
    }

    /**
     * The current user's scope: self::ALL, self::NONE, or
     * ['from' => year, 'to' => year] (inclusive).
     *
     * @return string|array{from:int,to:int}
     */
    public static function current_scope() {
        if (!is_user_logged_in()) {
            return self::NONE;
        }
        if (current_user_can('edit_pages')) {
            return self::ALL;
        }
        $member = avpvh_get_member_by_wp_user(get_current_user_id());
        return self::scope_for_member($member);
    }

    /**
     * @param object|null $member a member record (avpvh_get_member_by_wp_user())
     * @return string|array{from:int,to:int}
     */
    public static function scope_for_member(?object $member) {
        if (!$member) {
            return self::NONE;
        }
        if ($member->status === 'active') {
            return self::ALL;
        }
        if ($member->status !== 'inactive' || empty($member->archive_access)) {
            return self::NONE;
        }
        $from = (int) ($member->joined_year ?? 0);
        $to   = (int) ($member->left_year ?? 0);
        if ($from <= 0 || $to < $from) {
            return self::NONE;
        }
        return ['from' => $from, 'to' => $to];
    }

    public static function year_allowed(int $year): bool {
        $scope = self::current_scope();
        if ($scope === self::ALL) {
            return true;
        }
        if ($scope === self::NONE || $year <= 0) {
            return false;
        }
        return $year >= $scope['from'] && $year <= $scope['to'];
    }

    /** Pages and other post types are left to AVPVH_Access. */
    public static function post_allowed(\WP_Post $post): bool {
        if ($post->post_type !== 'post') {
            return true;
        }
        return self::year_allowed((int) substr((string) $post->post_date, 0, 4));
    }

    /** @return array<int> the posts the current user may not see */
    public static function hidden_post_ids(): array {
        if (self::$hidden === null) {
            self::$hidden = [];
            $scope = self::current_scope();
            if ($scope !== self::ALL) {
                global $wpdb;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- once per request, cached in self::$hidden
                $rows = $wpdb->get_results(
                    "SELECT ID, YEAR(post_date) AS y FROM {$wpdb->posts} WHERE post_type = 'post'"
                );
                foreach ($rows as $row) {
                    $year = (int) $row->y;
                    if ($scope === self::NONE || $year < $scope['from'] || $year > $scope['to']) {
                        self::$hidden[(int) $row->ID] = true;
                    }
                }
            }
        }
        return array_keys(self::$hidden);
    }

    /** For tests, and after a login within the same request. */
    public static function reset(): void {
        self::$hidden = null;
    }

    /**
     * Front-end queries leave hidden posts out. The main query for a single
     * post is left alone: AVPVH_Access sends that visitor to the login page.
     * wp-admin, cron and WP-CLI (indexers) see everything.
     */
    public function filter_query(\WP_Query $query): void {
        if ((is_admin() && !wp_doing_ajax()) || wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
            return;
        }
        if ($query->is_main_query() && $query->is_singular()) {
            return;
        }
        $hidden = self::hidden_post_ids();
        if (!$hidden) {
            return;
        }
        $in = array_filter(array_map('intval', (array) $query->get('post__in')));
        if ($in) {
            // WP_Query ignores post__not_in next to post__in.
            $in = array_values(array_diff($in, $hidden));
            $query->set('post__in', $in ?: [0]);
            return;
        }
        $query->set('post__not_in', $this->merge_not_in($query->get('post__not_in'), $hidden));
    }

    /** /wp/v2/posts and /wp/v2/search: the same, over REST. */
    public function filter_rest_args(array $args): array {
        $hidden = self::hidden_post_ids();
        if ($hidden) {
            $args['post__not_in'] = $this->merge_not_in($args['post__not_in'] ?? [], $hidden);
        }
        return $args;
    }

    /**
     * A hidden post over REST (/wp/v2/posts/<id> and its sub-routes) is a
     * 404, as if it did not exist.
     *
     * @return mixed the unchanged response, or a WP_Error
     */
    public function hide_post_from_rest($response, array $handler, \WP_REST_Request $request) { // NOSONAR -- $handler: the filter passes it before $request
        if (!preg_match('#^/wp/v2/posts/(\d+)#', $request->get_route(), $match)) {
            return $response;
        }
        if (in_array((int) $match[1], self::hidden_post_ids(), true)) {
            return new \WP_Error('rest_post_invalid_id', __('Ongeldige bericht-ID.', 'avpvh-members'), ['status' => 404]);
        }
        return $response;
    }

    /** oEmbed would otherwise give away a hidden post's title. */
    public function hide_post_from_oembed($post_id) {
        return in_array((int) $post_id, self::hidden_post_ids(), true) ? 0 : $post_id;
    }

    /** Previous/next post links skip hidden posts. */
    public function filter_adjacent_where(string $where): string {
        $hidden = self::hidden_post_ids();
        if (!$hidden) {
            return $where;
        }
        return $where . ' AND p.ID NOT IN (' . implode(',', array_map('intval', $hidden)) . ')';
    }

    /** @param mixed $existing */
    private function merge_not_in($existing, array $hidden): array {
        return array_values(array_unique(array_merge(array_map('intval', array_filter((array) $existing)), $hidden)));
    }
}
