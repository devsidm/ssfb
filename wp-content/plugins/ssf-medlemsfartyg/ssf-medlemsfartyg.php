<?php
/**
 * Plugin Name: SSF Medlemsfartyg
 * Plugin URI: https://github.com/devsidm/ssfb
 * Description: Hanterar och visar Sveriges Segelfartygsförbunds medlemsfartyg.
 * Version: 0.2.3
 * Author: SIDM
 * Text Domain: ssf-medlemsfartyg
 *
 * @package SSF_Medlemsfartyg
 */

if (! defined('ABSPATH')) {
    exit;
}

define('SSF_MEDLEMSFARTYG_VERSION', '0.2.3');
define('SSF_MEDLEMSFARTYG_FILE', __FILE__);
define('SSF_MEDLEMSFARTYG_PATH', plugin_dir_path(__FILE__));
define('SSF_MEDLEMSFARTYG_URL', plugin_dir_url(__FILE__));

require_once SSF_MEDLEMSFARTYG_PATH . 'includes/class-ssf-medlemsfartyg-plugin.php';

SSF_Medlemsfartyg_Plugin::instance();

register_activation_hook(__FILE__, array('SSF_Medlemsfartyg_Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('SSF_Medlemsfartyg_Plugin', 'deactivate'));
