<?php
/**
 * Plugin Name: SSF Medlemsprocess
 * Plugin URI: https://github.com/devsidm/ssfb
 * Description: Ansökan, granskning, inspektion och beslut för Sveriges Segelfartygsförbund.
 * Version: 0.2.2
 * Author: SIDM
 * Text Domain: ssf-medlemsprocess
 */

if (! defined('ABSPATH')) {
    exit;
}

define('SSF_MEDLEMSPROCESS_VERSION', '0.2.2');
define('SSF_MEDLEMSPROCESS_FILE', __FILE__);
define('SSF_MEDLEMSPROCESS_PATH', plugin_dir_path(__FILE__));
define('SSF_MEDLEMSPROCESS_URL', plugin_dir_url(__FILE__));

require_once SSF_MEDLEMSPROCESS_PATH . 'includes/class-ssf-medlemsprocess-plugin.php';

register_activation_hook(SSF_MEDLEMSPROCESS_FILE, array('SSF_Medlemsprocess_Plugin', 'activate'));
register_deactivation_hook(SSF_MEDLEMSPROCESS_FILE, array('SSF_Medlemsprocess_Plugin', 'deactivate'));

SSF_Medlemsprocess_Plugin::instance();
