<?php
/**
 * Plugin Name: SSF DEV Login Protection
 * Description: Requires WordPress login for frontend in development.
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('template_redirect', static function (): void {
    if ('development' !== wp_get_environment_type()) {
        return;
    }

    if (is_user_logged_in()) {
        return;
    }

    if (defined('REST_REQUEST') && REST_REQUEST) {
        return;
    }

    if (defined('DOING_CRON') && DOING_CRON) {
        return;
    }

    if (is_admin()) {
        return;
    }

    if (function_exists('ssf_dev_protection_is_public_status_route') && ssf_dev_protection_is_public_status_route()) {
        return;
    }

    auth_redirect();
});

add_action('send_headers', static function (): void {
    if ('development' === wp_get_environment_type()) {
        header('X-Robots-Tag: noindex, nofollow, noarchive', true);
    }
});
