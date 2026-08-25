<?php
/**
 * Plugin Name: SSF Stadgar & Dokument
 * Plugin URI: https://github.com/devsidm/ssfb
 * Description: Hanterar SSF:s stadgar, versionshistorik och relaterade styrdokument.
 * Version: 0.1.1
 * Author: SIDM
 * Text Domain: ssf-stadgar
 *
 * @package SSF_Stadgar
 */

if (! defined('ABSPATH')) {
    exit;
}

define('SSF_STADGAR_VERSION', '0.1.1');
define('SSF_STADGAR_FILE', __FILE__);
define('SSF_STADGAR_PATH', plugin_dir_path(__FILE__));
define('SSF_STADGAR_URL', plugin_dir_url(__FILE__));

require_once SSF_STADGAR_PATH . 'includes/class-ssf-stadgar-plugin.php';

register_activation_hook(SSF_STADGAR_FILE, array('SSF_Stadgar_Plugin', 'activate'));
register_deactivation_hook(SSF_STADGAR_FILE, array('SSF_Stadgar_Plugin', 'deactivate'));

SSF_Stadgar_Plugin::instance();
