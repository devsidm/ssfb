<?php
/**
 * Plugin Name: SSF Arbetsyta
 * Description: Gemensam frontend för SSF:s interna verksamhetstjänster.
 * Version: 0.1.0
 * Requires PHP: 8.0
 */

if (! defined('ABSPATH')) {
    exit;
}

define('SSF_WORKSPACE_VERSION', '0.1.0');
define('SSF_WORKSPACE_PATH', plugin_dir_path(__FILE__));
define('SSF_WORKSPACE_URL', plugin_dir_url(__FILE__));

require_once SSF_WORKSPACE_PATH . 'includes/class-ssf-workspace.php';
require_once SSF_WORKSPACE_PATH . 'includes/class-ssf-workspace-services.php';

SSF_Workspace::boot();
SSF_Workspace_Services::boot();

register_activation_hook(__FILE__, array('SSF_Workspace', 'activate'));
register_deactivation_hook(__FILE__, array('SSF_Workspace', 'deactivate'));
