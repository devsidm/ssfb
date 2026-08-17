<?php
/**
 * Uninstall handler.
 *
 * Data is kept by default.
 *
 * @package SSF_Medlemsfartyg
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (defined('SSF_MEDLEMSFARTYG_DELETE_DATA') && SSF_MEDLEMSFARTYG_DELETE_DATA) {
    delete_option('ssf_medlemsfartyg_settings');
}
