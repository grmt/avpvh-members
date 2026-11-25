<?php
defined('ABSPATH') || exit;

/** Apply a content boundary to direct comment access and canonical redirects. */
class AVPVH_Content_Guard {
    /** @var callable(int): bool */
    private $restricted;

    public function __construct(callable $restricted) {
        $this->restricted = $restricted;
        add_filter('rest_request_before_callbacks', [$this, 'guard_rest_comments'], 10, 3);
        add_filter('comments_open', [$this, 'comments_open'], 10, 2);
        add_action('pre_comment_on_post', [$this, 'guard_comment_submission']);
        // A guessed URL is resolved with direct SQL, whereas url_to_postid()
        // runs permission-filtered queries and may return zero for that URL.
        // Disable prefix guessing before core can disclose a protected slug.
        add_filter('pre_redirect_guess_404_permalink', '__return_false');
        // Check other canonical redirects before WordPress sends the Location.
        add_filter('redirect_canonical', [$this, 'guard_canonical_redirect']);
    }

    public function comments_open(bool $open, int $post_id): bool {
        return $open && !($this->restricted)($post_id);
    }

    public function guard_comment_submission(int $post_id): void {
        if (($this->restricted)($post_id)) {
            wp_die(esc_html__('Geen toegang.', 'avpvh-members'), '', ['response' => 403]);
        }
    }

    /** Check both the existing parent and a new parent supplied on a write. */
    public function guard_rest_comments($response, array $handler, \WP_REST_Request $request) {
        $route = strtolower($request->get_route());
        if (!preg_match('#^/wp/v2/comments(?:/(\d+))?(?:/|$)#i', $route, $match)) {
            return $response;
        }
        $ids = [];
        if (!empty($match[1])) {
            $comment = get_comment((int) $match[1]);
            if ($comment) {
                $ids[] = (int) $comment->comment_post_ID;
            }
        }
        $write = !in_array(strtoupper($request->get_method()), ['GET', 'HEAD', 'OPTIONS'], true);
        if ($write) {
            $ids[] = (int) $request->get_param('post');
        }
        foreach (array_unique($ids) as $id) {
            if ($id > 0 && ($this->restricted)($id)) {
                return new \WP_Error('rest_comment_invalid_id', __('Geen toegang.', 'avpvh-members'), ['status' => $write ? 403 : 404]);
            }
        }
        return $response;
    }

    public function guard_canonical_redirect($url) {
        return is_string($url) && ($this->restricted)((int) url_to_postid($url)) ? false : $url;
    }
}
