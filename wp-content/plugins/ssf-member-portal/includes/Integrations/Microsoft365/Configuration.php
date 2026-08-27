<?php

namespace SSF\MemberPortal\Integrations\Microsoft365;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Reads Graph configuration exclusively from server-side constants or environment variables.
 */
final class Configuration
{
    private const KEYS = array(
        'tenant_id' => 'SSF_GRAPH_TENANT_ID',
        'client_id' => 'SSF_GRAPH_CLIENT_ID',
        'client_secret' => 'SSF_GRAPH_CLIENT_SECRET',
        'site_id' => 'SSF_GRAPH_SITE_ID',
        'drive_id' => 'SSF_GRAPH_DRIVE_ID',
        'annual_meeting_folder_id' => 'SSF_GRAPH_ANNUAL_MEETING_FOLDER_ID',
        'annual_meeting_folder_name' => 'SSF_GRAPH_ANNUAL_MEETING_FOLDER_NAME',
        'site_hostname' => 'SSF_GRAPH_SITE_HOSTNAME',
        'site_path' => 'SSF_GRAPH_SITE_PATH',
    );

    public static function value(string $key): string
    {
        if (! isset(self::KEYS[$key])) {
            return '';
        }

        $constant = self::KEYS[$key];
        $value = defined($constant) ? constant($constant) : getenv($constant);

        return is_string($value) ? trim($value) : '';
    }

    public static function all(): array
    {
        $values = array();
        foreach (array_keys(self::KEYS) as $key) {
            $values[$key] = self::value($key);
        }

        return $values;
    }

    public static function missing(): array
    {
        $labels = array(
            'tenant_id' => 'Tenant ID',
            'client_id' => 'Client ID',
            'client_secret' => 'Client secret',
            'site_id' => 'Site ID',
            'drive_id' => 'Drive ID',
            'annual_meeting_folder_id' => 'Årsmöten-mappens ID',
        );
        $missing = array();
        foreach ($labels as $key => $label) {
            if (! self::value($key)) {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    public static function complete(): bool
    {
        return ! self::missing();
    }

    public static function public_status(): array
    {
        $status = array();
        foreach (self::KEYS as $key => $constant) {
            $status[$key] = array(
                'constant' => $constant,
                'configured' => '' !== self::value($key),
            );
        }

        return $status;
    }
}
