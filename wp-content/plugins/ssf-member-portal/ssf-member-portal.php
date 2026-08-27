<?php
/**
 * Plugin Name: SSF Medlemsportal
 * Plugin URI: https://github.com/devsidm/ssfb
 * Description: Gemensam grund för SSF:s digitala medlemsfunktioner. Innehåller Motioner i version 1.
 * Version: 0.2.0
 * Author: SIDM
 * Text Domain: ssf-member-portal
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package SSF\MemberPortal
 */

if (! defined('ABSPATH')) {
    exit;
}

define('SSF_MEMBER_PORTAL_VERSION', '0.2.0');
define('SSF_MEMBER_PORTAL_FILE', __FILE__);
define('SSF_MEMBER_PORTAL_PATH', plugin_dir_path(__FILE__));
define('SSF_MEMBER_PORTAL_URL', plugin_dir_url(__FILE__));

spl_autoload_register(
    static function (string $class): void {
        $prefix = 'SSF\\MemberPortal\\';
        if (0 !== strpos($class, $prefix)) {
            return;
        }

        $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
        $file = SSF_MEMBER_PORTAL_PATH . 'includes/' . $relative . '.php';
        if (is_readable($file)) {
            require_once $file;
        }
    }
);

register_activation_hook(SSF_MEMBER_PORTAL_FILE, array('SSF\\MemberPortal\\Core\\Plugin', 'activate'));
register_deactivation_hook(SSF_MEMBER_PORTAL_FILE, array('SSF\\MemberPortal\\Core\\Plugin', 'deactivate'));

SSF\MemberPortal\Core\Plugin::instance();
