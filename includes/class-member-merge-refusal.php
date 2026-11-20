<?php
defined('ABSPATH') || exit;

/** Why AVPVH_Member_Merge::execute() won't start: carries the error shown to the admin. */
class AVPVH_Member_Merge_Refusal extends \RuntimeException {
    public function __construct(public readonly \WP_Error $error) {
        parent::__construct($error->get_error_message());
    }
}
