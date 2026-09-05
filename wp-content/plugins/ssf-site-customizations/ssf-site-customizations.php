<?php
/**
 * Plugin Name: SSF Site Customizations
 * Plugin URI: https://github.com/devsidm/ssfb
 * Description: Content types, shortcodes, forms, and styling for Sveriges Segelfartygsförbund.
 * Version: 0.2.5
 * Author: SIDM
 * Text Domain: ssf-site
 *
 * @package SSF_Site
 */

if (! defined('ABSPATH')) {
    exit;
}

define('SSF_SITE_VERSION', '0.2.5');
define('SSF_SITE_PATH', plugin_dir_path(__FILE__));
define('SSF_SITE_URL', plugin_dir_url(__FILE__));

require_once SSF_SITE_PATH . 'includes/post-types.php';
require_once SSF_SITE_PATH . 'includes/newsletters.php';
require_once SSF_SITE_PATH . 'includes/information-architecture.php';
require_once SSF_SITE_PATH . 'includes/forms.php';
require_once SSF_SITE_PATH . 'includes/content-admin.php';
require_once SSF_SITE_PATH . 'includes/shortcodes.php';
require_once SSF_SITE_PATH . 'includes/content-renderers.php';

function ssf_site_enqueue_assets(): void
{
    wp_enqueue_style(
        'ssf-typography',
        SSF_SITE_URL . 'assets/css/ssf-typography.css',
        array(),
        SSF_SITE_VERSION
    );

    wp_enqueue_style(
        'ssf-site',
        SSF_SITE_URL . 'assets/css/ssf-site.css',
        array('ssf-typography'),
        SSF_SITE_VERSION
    );

    wp_enqueue_script(
        'ssf-site',
        SSF_SITE_URL . 'assets/js/ssf-site.js',
        array(),
        SSF_SITE_VERSION,
        true
    );
}
add_action('wp_enqueue_scripts', 'ssf_site_enqueue_assets');

function ssf_site_enqueue_admin_typography(): void
{
    wp_enqueue_style(
        'ssf-typography',
        SSF_SITE_URL . 'assets/css/ssf-typography.css',
        array(),
        SSF_SITE_VERSION
    );
    wp_enqueue_style(
        'ssf-admin-typography',
        SSF_SITE_URL . 'assets/css/ssf-admin-typography.css',
        array('ssf-typography'),
        SSF_SITE_VERSION
    );
}
add_action('admin_enqueue_scripts', 'ssf_site_enqueue_admin_typography');

function ssf_site_document_title(array $parts): array
{
    if (is_front_page()) {
        $parts['title'] = 'Sveriges Segelfartygsförbund - SSF';
    }

    return $parts;
}
add_filter('document_title_parts', 'ssf_site_document_title');

function ssf_site_meta_tags(): void
{
    if (! is_front_page()) {
        return;
    }

    $description = 'SSF samlar Sveriges traditionella segelfartyg, fartygsombud och stödmedlemmar för att bevara, bruka och utveckla det svenska segelfartygsarvet.';
    $image = SSF_SITE_URL . 'assets/images/ssf-hero.jpg';
    ?>
    <meta name="description" content="<?php echo esc_attr($description); ?>">
    <meta property="og:title" content="Sveriges Segelfartygsförbund - SSF">
    <meta property="og:description" content="<?php echo esc_attr($description); ?>">
    <meta property="og:image" content="<?php echo esc_url($image); ?>">
    <meta property="og:type" content="website">
    <?php
}
add_action('wp_head', 'ssf_site_meta_tags', 5);

function ssf_site_register_admin_routes(): void
{
    register_rest_route(
        'ssf-site/v1',
        '/activate-theme',
        array(
            'methods'             => 'POST',
            'callback'            => 'ssf_site_activate_theme_route',
            'permission_callback' => static function (): bool {
                return current_user_can('switch_themes');
            },
        )
    );

    register_rest_route(
        'ssf-site/v1',
        '/sync-information-architecture',
        array(
            'methods'             => 'POST',
            'callback'            => 'ssf_site_sync_information_architecture_route',
            'permission_callback' => static function (): bool {
                return current_user_can('edit_theme_options');
            },
        )
    );
}
add_action('rest_api_init', 'ssf_site_register_admin_routes');

function ssf_site_activate_theme_route(WP_REST_Request $request): WP_REST_Response
{
    $stylesheet = sanitize_key((string) $request->get_param('stylesheet'));
    if (! $stylesheet) {
        $stylesheet = 'ssf';
    }

    $theme = wp_get_theme($stylesheet);
    if (! $theme->exists()) {
        return new WP_REST_Response(
            array(
                'success' => false,
                'message' => 'Theme not found.',
            ),
            404
        );
    }

    switch_theme($stylesheet);

    return new WP_REST_Response(
        array(
            'success' => true,
            'stylesheet' => get_stylesheet(),
            'template' => get_template(),
        )
    );
}

function ssf_site_activate(): void
{
    ssf_site_register_post_types();
    ssf_site_register_newsletter_post_type();
    ssf_site_grant_newsletter_capabilities();
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'ssf_site_activate');

function ssf_site_deactivate(): void
{
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'ssf_site_deactivate');
