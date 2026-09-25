<?php
/**
 * Plugin Name: SSF Inspektioner
 * Description: Digitala fartygsinspektioner för SSF.
 * Version: 0.1.0
 * Requires PHP: 8.0
 * Text Domain: ssf-inspektioner
 */

if (! defined('ABSPATH')) {
    exit;
}

define('SSF_INSPECTIONS_VERSION', '0.1.0');
define('SSF_INSPECTIONS_PATH', plugin_dir_path(__FILE__));
define('SSF_INSPECTIONS_URL', plugin_dir_url(__FILE__));

require_once SSF_INSPECTIONS_PATH . 'includes/class-ssf-inspections-db.php';
require_once SSF_INSPECTIONS_PATH . 'includes/class-ssf-inspections-core.php';
require_once SSF_INSPECTIONS_PATH . 'includes/class-ssf-inspections-templates.php';
require_once SSF_INSPECTIONS_PATH . 'includes/class-ssf-inspections-ui.php';

register_activation_hook(__FILE__, function (): void {
    SSF_Inspections_DB::install();
    SSF_Inspections_UI::routes();
    flush_rewrite_rules();
});
register_deactivation_hook(__FILE__, 'flush_rewrite_rules');
add_action('init', array('SSF_Inspections_UI', 'routes'));
add_filter('query_vars', array('SSF_Inspections_UI', 'query_vars'));
add_action('template_redirect', array('SSF_Inspections_UI', 'dispatch'), 0);
add_action('rest_api_init', array('SSF_Inspections_UI', 'rest_routes'));
add_action('admin_menu', array('SSF_Inspections_UI', 'admin_menu'), 30);
