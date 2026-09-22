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
    if (ssf_dev_protection_is_public_status_route()) {
        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private', true);
        header('Pragma: no-cache', true);
    }
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

    if (ssf_dev_protection_is_public_status_route()) {
        return true;
    }

    global $pagenow;
    return in_array($pagenow, array('wp-login.php', 'wp-cron.php'), true);
}

function ssf_dev_protection_is_public_status_route(): bool
{
    $request_path = trim((string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
    $home_path = trim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
    if ($home_path && 0 === strpos($request_path . '/', $home_path . '/')) {
        $request_path = trim(substr($request_path, strlen($home_path)), '/');
    }

    $request_path = ssf_dev_protection_normalize_public_status_path($request_path);

    if (in_array($request_path, array('ansokan-status', 'motion-status'), true)) {
        return true;
    }
    return (bool) preg_match('#^skriv-nyhet/[A-Za-z0-9_-]{1,128}$#', $request_path);
}

function ssf_dev_protection_normalize_public_status_path(string $request_path): string
{
    if (in_array($request_path, array('ansokan-status', 'motion-status'), true)) {
        return $request_path;
    }

    $parts = explode('/', trim($request_path, '/'));
    if (count($parts) >= 2 && in_array($parts[1], array('ansokan-status', 'motion-status'), true)) {
        return implode('/', array_slice($parts, 1));
    }

    return $request_path;
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
add_filter('rest_pre_serve_request', static function (bool $served): bool {
    ssf_dev_protection_send_robots_header();
    return $served;
}, 100);
add_filter('wp_sitemaps_enabled', static function (bool $enabled): bool {
    return ssf_dev_protection_enabled() ? false : $enabled;
});
