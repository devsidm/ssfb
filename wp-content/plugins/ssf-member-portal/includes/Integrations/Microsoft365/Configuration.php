<?php

namespace SSF\MemberPortal\Integrations\Microsoft365;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Graph settings use server configuration first and an encrypted admin fallback.
 */
final class Configuration
{
    public const OPTION = 'ssf_member_portal_graph_configuration';
    private const WEBHOOK_SECRET_KEY = 'SSF_MOTIONS_WEBHOOK_SECRET';
    private const INBOUND_SYNC_OPTION = 'ssf_member_portal_power_automate_inbound_enabled';

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
        'metadata_wordpress_motion_id_field' => 'SSF_GRAPH_WORDPRESS_MOTION_ID_FIELD',
        'metadata_motion_number_field' => 'SSF_GRAPH_MOTION_NUMBER_FIELD',
        'metadata_status_field' => 'SSF_GRAPH_STATUS_FIELD',
        'metadata_vessel_field' => 'SSF_GRAPH_VESSEL_FIELD',
        'metadata_received_date_field' => 'SSF_GRAPH_RECEIVED_DATE_FIELD',
    );

    private const TEXT_KEYS = array(
        'tenant_id',
        'client_id',
        'site_id',
        'drive_id',
        'annual_meeting_folder_id',
        'annual_meeting_folder_name',
        'site_hostname',
        'site_path',
        'metadata_wordpress_motion_id_field',
        'metadata_motion_number_field',
        'metadata_status_field',
        'metadata_vessel_field',
        'metadata_received_date_field',
    );

    private const DEFAULTS = array(
        'tenant_id' => 'ad928e8c-b976-4c84-a0b1-931341b5a512',
        'client_id' => '8a3bfdb3-6b8c-4982-b562-eaf43be7f39a',
        'site_id' => 'tradtionsfartyg.sharepoint.com,fcb7d0b0-8986-4dbc-a97c-e85297880b7e,5041e290-138c-442f-a20d-3e5a7918c810',
        'drive_id' => 'b!sNC3_IaJvE2pfOhSl4gLfpDiQVCMEy9Eog0-WnkYyBDVxq_wiIU3Tbpm3lUPgSuc',
        'annual_meeting_folder_id' => '01YQZLHNOR4EIPLSI6ERAKQYR2ECTT4KD2',
        'annual_meeting_folder_name' => 'Årsmöten',
        'site_hostname' => 'tradtionsfartyg.sharepoint.com',
        'site_path' => '/sites/styrelsen9',
        'metadata_wordpress_motion_id_field' => 'WordPressMotionID',
        'metadata_motion_number_field' => 'Motionnummer',
        'metadata_status_field' => 'Status',
        'metadata_vessel_field' => 'Fartyg',
        'metadata_received_date_field' => 'InkommenDatum',
    );

    public static function value(string $key): string
    {
        if (! isset(self::KEYS[$key])) {
            return '';
        }

        $server_value = self::server_value($key);
        if ('' !== $server_value) {
            return $server_value;
        }

        $stored = self::stored();
        if ('client_secret' === $key) {
            return self::decrypt((string) ($stored[$key] ?? ''));
        }

        $stored_value = trim((string) ($stored[$key] ?? ''));
        return '' !== $stored_value ? $stored_value : (string) (self::DEFAULTS[$key] ?? '');
    }

    public static function all(): array
    {
        $values = array();
        foreach (array_keys(self::KEYS) as $key) {
            $values[$key] = self::value($key);
        }

        return $values;
    }

    /**
     * Returns editable values without ever returning the client secret.
     */
    public static function editable_values(): array
    {
        $values = array();
        foreach (self::TEXT_KEYS as $key) {
            $values[$key] = self::value($key);
        }

        return $values;
    }

    public static function save_admin(array $input)
    {
        $settings = self::stored();
        foreach (self::TEXT_KEYS as $key) {
            if (array_key_exists($key, $input)) {
                $settings[$key] = self::sanitize($key, (string) $input[$key]);
            }
        }

        if (! empty($input['client_secret'])) {
            $encrypted = self::encrypt((string) $input['client_secret']);
            if (is_wp_error($encrypted)) {
                return $encrypted;
            }
            $settings['client_secret'] = $encrypted;
        }
        if (! empty($input['clear_client_secret'])) {
            unset($settings['client_secret']);
        }

        update_option(self::OPTION, $settings, false);
        delete_transient('ssf_member_portal_graph_token');

        return true;
    }

    public static function reset_admin_defaults(): void
    {
        $settings = self::stored();
        foreach (self::TEXT_KEYS as $key) {
            unset($settings[$key]);
        }
        update_option(self::OPTION, $settings, false);
        delete_transient('ssf_member_portal_graph_token');
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
            $server_value = self::server_value($key);
            $value = self::value($key);
            $stored = self::stored();
            $source = '' !== $server_value ? 'server' : (array_key_exists($key, $stored) && '' !== (string) $stored[$key] ? 'admin' : ('' !== $value ? 'default' : 'missing'));
            $status[$key] = array(
                'constant' => $constant,
                'configured' => '' !== $value,
                'source' => $source,
            );
        }

        return $status;
    }

    public static function webhook_secret(): string
    {
        $server_value = self::server_webhook_secret();
        if ('' !== $server_value) {
            return $server_value;
        }

        return self::decrypt((string) (self::stored()['webhook_secret'] ?? ''));
    }

    public static function webhook_public_status(): array
    {
        $server_value = self::server_webhook_secret();
        $stored = self::stored();
        return array(
            'configured' => '' !== self::webhook_secret(),
            'source' => '' !== $server_value ? 'server' : (! empty($stored['webhook_secret']) ? 'admin' : 'missing'),
            'inbound_enabled' => self::inbound_sync_enabled(),
        );
    }

    public static function inbound_sync_enabled(): bool
    {
        return 'no' !== get_option(self::INBOUND_SYNC_OPTION, 'yes');
    }

    public static function save_webhook_settings(array $input)
    {
        $settings = self::stored();
        if (! empty($input['webhook_secret'])) {
            $encrypted = self::encrypt((string) $input['webhook_secret']);
            if (is_wp_error($encrypted)) {
                return $encrypted;
            }
            $settings['webhook_secret'] = $encrypted;
            update_option(self::OPTION, $settings, false);
        }

        update_option(self::INBOUND_SYNC_OPTION, ! empty($input['inbound_enabled']) ? 'yes' : 'no', false);
        return true;
    }

    public static function generate_webhook_secret()
    {
        $secret = wp_generate_password(48, false, false);
        $result = self::save_webhook_settings(array('webhook_secret' => $secret, 'inbound_enabled' => '1'));
        return is_wp_error($result) ? $result : $secret;
    }

    private static function stored(): array
    {
        return (array) get_option(self::OPTION, array());
    }

    private static function server_value(string $key): string
    {
        $constant = self::KEYS[$key];
        $value = defined($constant) ? constant($constant) : getenv($constant);

        return is_string($value) ? trim($value) : '';
    }

    private static function server_webhook_secret(): string
    {
        $value = defined(self::WEBHOOK_SECRET_KEY) ? constant(self::WEBHOOK_SECRET_KEY) : getenv(self::WEBHOOK_SECRET_KEY);
        return is_string($value) ? trim($value) : '';
    }

    private static function sanitize(string $key, string $value): string
    {
        if ('site_path' === $key) {
            return '/' . ltrim(sanitize_text_field($value), '/');
        }

        return sanitize_text_field($value);
    }

    private static function encrypt(string $value)
    {
        if (! function_exists('openssl_encrypt') || ! function_exists('openssl_cipher_iv_length')) {
            return new \WP_Error('graph_secret_encryption', __('Servern saknar stöd för krypterad lagring av client secret.', 'ssf-member-portal'));
        }

        $cipher = 'aes-256-cbc';
        $iv = random_bytes(openssl_cipher_iv_length($cipher));
        $encrypted = openssl_encrypt($value, $cipher, self::encryption_key(), OPENSSL_RAW_DATA, $iv);
        if (! $encrypted) {
            return new \WP_Error('graph_secret_encryption', __('Client secret kunde inte krypteras.', 'ssf-member-portal'));
        }

        return base64_encode($iv . $encrypted);
    }

    private static function decrypt(string $value): string
    {
        if (! $value || ! function_exists('openssl_decrypt') || ! function_exists('openssl_cipher_iv_length')) {
            return '';
        }

        $cipher = 'aes-256-cbc';
        $decoded = base64_decode($value, true);
        $iv_length = openssl_cipher_iv_length($cipher);
        if (! $decoded || strlen($decoded) <= $iv_length) {
            return '';
        }

        $decrypted = openssl_decrypt(substr($decoded, $iv_length), $cipher, self::encryption_key(), OPENSSL_RAW_DATA, substr($decoded, 0, $iv_length));

        return is_string($decrypted) ? $decrypted : '';
    }

    private static function encryption_key(): string
    {
        return hash('sha256', wp_salt('auth') . wp_salt('secure_auth'), true);
    }
}
