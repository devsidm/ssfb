<?php
/**
 * Plugin Name: SSF Kalender
 * Plugin URI: https://github.com/devsidm/ssfb
 * Description: Kalender för SSF:s aktiviteter med direktvisning av aktiva årsmöten.
 * Version: 0.1.2
 * Author: SIDM
 * Text Domain: ssf-calendar
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package SSF\Calendar
 */

if (! defined('ABSPATH')) {
    exit;
}

define('SSF_CALENDAR_VERSION', '0.1.2');
define('SSF_CALENDAR_FILE', __FILE__);
define('SSF_CALENDAR_PATH', plugin_dir_path(__FILE__));
define('SSF_CALENDAR_URL', plugin_dir_url(__FILE__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'SSF\\Calendar\\';
    if (0 !== strpos($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $file = SSF_CALENDAR_PATH . 'includes/' . $relative . '.php';
    if (is_readable($file)) {
        require_once $file;
    }
});

register_activation_hook(SSF_CALENDAR_FILE, array('SSF\\Calendar\\Plugin', 'activate'));
register_deactivation_hook(SSF_CALENDAR_FILE, array('SSF\\Calendar\\Plugin', 'deactivate'));

SSF\Calendar\Plugin::instance();
