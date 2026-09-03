<?php
/**
 * Development-only access and indexing protection.
 *
 * robots.txt is a crawler instruction, not access control. The login gate
 * and noindex headers are the primary protection for this environment.
 */

if (! defined('ABSPATH')) {
    exit;
}

function ssf_dev_protection_enabled(): bool
{
    return function_exists('wp_get_environment_type')
        && 'development' === wp_get_environment_type();
}

function ssf_dev_protection_send_robots_header(): void
{
    if (! ssf_dev_protection_enabled() || headers_sent()) {
        return;
    }

    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet', true);
}

function ssf_dev_protection_sync_search_visibility(): void
{
    if (! ssf_dev_protection_enabled() || '0' === (string) get_option('blog_public')) {
        return;
    }

    update_option('blog_public', 0);
}

function ssf_dev_protection_robots(array $robots): array
{
    if (! ssf_dev_protection_enabled()) {
        return $robots;
    }

    $robots['noindex'] = true;
    $robots['nofollow'] = true;
    $robots['noarchive'] = true;
    $robots['nosnippet'] = true;

    return $robots;
}

function ssf_dev_protection_login_head(): void
{
    if (ssf_dev_protection_enabled()) {
        echo '<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">' . "\n";
    }
}

function ssf_dev_protection_allows_anonymous_request(): bool
{
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return true;
    }

    if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
        return true;
    }

    global $pagenow;
    return in_array($pagenow, array('wp-login.php', 'wp-cron.php'), true);
}

function ssf_dev_protection_require_login(): void
{
    if (! ssf_dev_protection_enabled() || is_user_logged_in() || ssf_dev_protection_allows_anonymous_request()) {
        return;
    }

    ssf_dev_protection_send_robots_header();

    $scheme = is_ssl() ? 'https' : 'http';
    $requested_url = $scheme . '://' . wp_unslash($_SERVER['HTTP_HOST'] ?? '') . wp_unslash($_SERVER['REQUEST_URI'] ?? '/');
    wp_safe_redirect(wp_login_url($requested_url), 302);
    exit;
}

add_action('init', 'ssf_dev_protection_sync_search_visibility', 0);
add_action('send_headers', 'ssf_dev_protection_send_robots_header', 0);
add_action('login_init', 'ssf_dev_protection_send_robots_header', 0);
add_action('admin_init', 'ssf_dev_protection_send_robots_header', 0);
add_action('login_head', 'ssf_dev_protection_login_head');
add_action('template_redirect', 'ssf_dev_protection_require_login', 0);
add_filter('wp_robots', 'ssf_dev_protection_robots');
add_filter('wp_sitemaps_enabled', static function (bool $enabled): bool {
    return ssf_dev_protection_enabled() ? false : $enabled;
});
