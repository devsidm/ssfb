<?php

namespace SSF\MemberPortal\Core;

if (! defined('ABSPATH')) {
    exit;
}

final class Settings
{
    public const OPTION = 'ssf_member_portal_settings';

    public static function all(): array
    {
        return wp_parse_args(
            (array) get_option(self::OPTION, array()),
            array(
                'notification_email' => '',
                'max_upload_mb' => 10,
                'motion_status_messages' => array(),
            )
        );
    }

    public static function save(array $input): void
    {
        $current = self::all();
        $settings = $current;

        if (array_key_exists('notification_email', $input)) {
            $settings['notification_email'] = sanitize_email($input['notification_email']);
        }
        if (array_key_exists('max_upload_mb', $input)) {
            $settings['max_upload_mb'] = min(25, max(1, absint($input['max_upload_mb'])));
        }
        if (array_key_exists('motion_status_messages', $input) && is_array($input['motion_status_messages'])) {
            $messages = array();
            foreach ($input['motion_status_messages'] as $status => $message) {
                $messages[sanitize_key((string) $status)] = sanitize_textarea_field((string) $message);
            }
            $settings['motion_status_messages'] = $messages;
        }
        update_option(self::OPTION, $settings, false);
    }

    /**
     * Removes Graph values from the database after moving configuration to wp-config.php.
     */
    public static function remove_legacy_graph_settings(): void
    {
        $settings = (array) get_option(self::OPTION, array());
        $legacy_keys = array(
            'sharepoint_enabled',
            'microsoft_tenant_id',
            'microsoft_client_id',
            'microsoft_client_secret',
            'sharepoint_site_id',
            'sharepoint_drive_id',
        );
        $changed = false;
        foreach ($legacy_keys as $key) {
            if (array_key_exists($key, $settings)) {
                unset($settings[$key]);
                $changed = true;
            }
        }

        if ($changed) {
            update_option(self::OPTION, $settings, false);
            delete_transient('ssf_member_portal_graph_token');
        }
    }
}
