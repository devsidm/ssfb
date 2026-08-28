<?php
/**
 * Template loader.
 *
 * @package SSF_Medlemsfartyg
 */

if (! defined('ABSPATH')) {
    exit;
}

class SSF_Medlemsfartyg_Templates
{
    public function __construct()
    {
        add_filter('template_include', array($this, 'template_include'));
    }

    public static function locate(string $template): string
    {
        $theme_template = locate_template('ssf-medlemsfartyg/' . $template);
        if ($theme_template) {
            return $theme_template;
        }

        return SSF_MEDLEMSFARTYG_PATH . 'templates/' . $template;
    }

    public function template_include(string $template): string
    {
        if ((is_post_type_archive('medlemsfartyg') || is_singular('medlemsfartyg')) && class_exists('SSF_Feature_Manager') && ! SSF_Feature_Manager::can_access('member_vessels')) {
            return self::locate('unavailable-medlemsfartyg.php');
        }
        if (is_post_type_archive('medlemsfartyg')) {
            return self::locate('archive-medlemsfartyg.php');
        }

        if (is_singular('medlemsfartyg')) {
            return self::locate('single-medlemsfartyg.php');
        }

        return $template;
    }
}
