<?php
/**
 * Plugin Name: SSF Aktuellt
 * Plugin URI: https://github.com/devsidm/ssfb
 * Description: Tidsstyrda och prioriterade budskap för SSF:s webbplats.
 * Version: 1.0.0
 * Author: SIDM
 * Text Domain: ssf-promotions
 * Requires PHP: 7.4
 */

if (! defined('ABSPATH')) {
    exit;
}

define('SSF_PROMOTIONS_VERSION', '1.0.0');
define('SSF_PROMOTIONS_FILE', __FILE__);
define('SSF_PROMOTIONS_PATH', plugin_dir_path(__FILE__));
define('SSF_PROMOTIONS_URL', plugin_dir_url(__FILE__));

require_once SSF_PROMOTIONS_PATH . 'includes/class-ssf-promotion-relations.php';
require_once SSF_PROMOTIONS_PATH . 'includes/class-ssf-promotion-repository.php';
require_once SSF_PROMOTIONS_PATH . 'includes/class-ssf-promotion-renderer.php';
require_once SSF_PROMOTIONS_PATH . 'includes/class-ssf-promotion-admin.php';
require_once SSF_PROMOTIONS_PATH . 'includes/class-ssf-promotions.php';

register_activation_hook(__FILE__, array('SSF_Promotions', 'activate'));
register_deactivation_hook(__FILE__, array('SSF_Promotions', 'deactivate'));

SSF_Promotions::instance();

/**
 * Render active promotions without coupling a template to the CPT implementation.
 */
function ssf_promotions_render_current(array $args = array()): string
{
    return SSF_Promotions::render_current($args);
}
