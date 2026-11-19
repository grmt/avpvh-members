<?php
defined('ABSPATH') || exit;

/** Thrown when a deletion check or step fails; AVPVH_Visitor_Delete turns it into a WP_Error. */
class AVPVH_Visitor_Delete_Exception extends \RuntimeException {}
