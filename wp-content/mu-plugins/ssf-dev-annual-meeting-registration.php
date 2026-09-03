<?php
/**
 * Keep the annual meeting registration available on the development site.
 *
 * Production continues to use the release-control setting in WordPress.
 */

if (! defined('ABSPATH')) {
    exit;
}

if (
    defined('WP_ENVIRONMENT_TYPE')
    && 'development' === WP_ENVIRONMENT_TYPE
    && ! defined('SSF_FEATURE_ANNUAL_MEETING_REGISTRATION_OVERRIDE')
) {
    define('SSF_FEATURE_ANNUAL_MEETING_REGISTRATION_OVERRIDE', 'public');
}
