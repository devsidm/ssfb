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
    private const LEGACY_WEBHOOK_CLEANUP_OPTION = 'ssf_member_portal_webhook_cleanup_complete';
    private const KEYS = array(
        'tenant_id' => 'SSF_GRAPH_TENANT_ID',
        'client_id' => 'SSF_GRAPH_CLIENT_ID',
        'client_secret' => 'SSF_GRAPH_CLIENT_SECRET',
        'site_id' => 'SSF_GRAPH_SITE_ID',
        'drive_id' => 'SSF_GRAPH_DRIVE_ID',
        'document_library_list_id' => 'SSF_GRAPH_DOCUMENT_LIBRARY_LIST_ID',
        'document_library_name' => 'SSF_GRAPH_DOCUMENT_LIBRARY_NAME',
        'annual_meeting_folder_id' => 'SSF_GRAPH_ANNUAL_MEETING_FOLDER_ID',
        'annual_meeting_folder_name' => 'SSF_GRAPH_ANNUAL_MEETING_FOLDER_NAME',
        'site_hostname' => 'SSF_GRAPH_SITE_HOSTNAME',
        'site_path' => 'SSF_GRAPH_SITE_PATH',
        'metadata_wordpress_motion_id_field' => 'SSF_GRAPH_WORDPRESS_MOTION_ID_FIELD',
        'metadata_motion_number_field' => 'SSF_GRAPH_MOTION_NUMBER_FIELD',
        'metadata_status_field' => 'SSF_GRAPH_STATUS_FIELD',
        'metadata_vessel_field' => 'SSF_GRAPH_VESSEL_FIELD',
        'metadata_received_date_field' => 'SSF_GRAPH_RECEIVED_DATE_FIELD',
        'application_site_id' => 'SSF_GRAPH_APPLICATION_SITE_ID',
        'application_drive_id' => 'SSF_GRAPH_APPLICATION_DRIVE_ID',
        'application_list_id' => 'SSF_GRAPH_APPLICATION_LIST_ID',
        'application_root_folder_id' => 'SSF_GRAPH_APPLICATION_ROOT_FOLDER_ID',
        'application_root_folder_name' => 'SSF_GRAPH_APPLICATION_ROOT_FOLDER_NAME',
        'application_site_hostname' => 'SSF_GRAPH_APPLICATION_SITE_HOSTNAME',
        'application_site_path' => 'SSF_GRAPH_APPLICATION_SITE_PATH',
        'metadata_application_wp_id_field' => 'SSF_GRAPH_APPLICATION_WP_ID_FIELD',
        'metadata_application_number_field' => 'SSF_GRAPH_APPLICATION_NUMBER_FIELD',
        'metadata_application_status_field' => 'SSF_GRAPH_APPLICATION_STATUS_FIELD',
        'metadata_application_membership_status_field' => 'SSF_GRAPH_APPLICATION_MEMBERSHIP_STATUS_FIELD',
        'metadata_application_inspection_status_field' => 'SSF_GRAPH_APPLICATION_INSPECTION_STATUS_FIELD',
        'metadata_application_vessel_field' => 'SSF_GRAPH_APPLICATION_VESSEL_FIELD',
        'metadata_application_representative_field' => 'SSF_GRAPH_APPLICATION_REPRESENTATIVE_FIELD',
        'metadata_application_received_field' => 'SSF_GRAPH_APPLICATION_RECEIVED_FIELD',
        'metadata_application_route_field' => 'SSF_GRAPH_APPLICATION_ROUTE_FIELD',
        'metadata_application_decision_date_field' => 'SSF_GRAPH_APPLICATION_DECISION_DATE_FIELD',
        'metadata_application_aspirant_start_field' => 'SSF_GRAPH_APPLICATION_ASPIRANT_START_FIELD',
        'metadata_application_aspirant_review_field' => 'SSF_GRAPH_APPLICATION_ASPIRANT_REVIEW_FIELD',
        'metadata_application_public_comment_field' => 'SSF_GRAPH_APPLICATION_PUBLIC_COMMENT_FIELD',
    );

    private const TEXT_KEYS = array(
        'client_id',
        'site_id',
        'drive_id',
        'document_library_list_id',
        'document_library_name',
        'annual_meeting_folder_id',
        'annual_meeting_folder_name',
        'site_hostname',
        'site_path',
        'metadata_wordpress_motion_id_field',
        'metadata_motion_number_field',
        'metadata_status_field',
        'metadata_vessel_field',
        'metadata_received_date_field',
        'application_site_id',
        'application_drive_id',
        'application_list_id',
        'application_root_folder_id',
        'application_root_folder_name',
        'application_site_hostname',
        'application_site_path',
        'metadata_application_wp_id_field',
        'metadata_application_number_field',
        'metadata_application_status_field',
        'metadata_application_membership_status_field',
        'metadata_application_inspection_status_field',
        'metadata_application_vessel_field',
        'metadata_application_representative_field',
        'metadata_application_received_field',
        'metadata_application_route_field',
        'metadata_application_decision_date_field',
        'metadata_application_aspirant_start_field',
        'metadata_application_aspirant_review_field',
        'metadata_application_public_comment_field',
    );

    private const DEFAULTS = array(
        'tenant_id' => '',
        'client_id' => '8a3bfdb3-6b8c-4982-b562-eaf43be7f39a',
        'site_id' => 'tradtionsfartyg.sharepoint.com,fcb7d0b0-8986-4dbc-a97c-e85297880b7e,5041e290-138c-442f-a20d-3e5a7918c810',
        'drive_id' => 'b!sNC3_IaJvE2pfOhSl4gLfpDiQVCMEy9Eog0-WnkYyBDVxq_wiIU3Tbpm3lUPgSuc',
        'document_library_list_id' => '',
        'document_library_name' => 'Dokument',
        'annual_meeting_folder_id' => '01YQZLHNOR4EIPLSI6ERAKQYR2ECTT4KD2',
        'annual_meeting_folder_name' => 'Årsmöten',
        'site_hostname' => 'tradtionsfartyg.sharepoint.com',
        'site_path' => '/sites/styrelsen9',
        'metadata_wordpress_motion_id_field' => 'WordPressMotionID',
        'metadata_motion_number_field' => 'Motionnummer',
        'metadata_status_field' => 'Status',
        'metadata_vessel_field' => 'Fartyg',
        'metadata_received_date_field' => 'InkommenDatum',
        'application_site_id' => '',
        'application_drive_id' => '',
        'application_list_id' => '',
        'application_root_folder_id' => '',
        'application_root_folder_name' => 'Medlemsansökningar',
        'application_site_hostname' => '',
        'application_site_path' => '',
        'metadata_application_wp_id_field' => 'WordPressApplicationID',
        'metadata_application_number_field' => 'ApplicationNumber',
        'metadata_application_status_field' => 'ApplicationStatus',
        'metadata_application_membership_status_field' => 'MembershipStatus',
        'metadata_application_inspection_status_field' => 'InspectionStatus',
        'metadata_application_vessel_field' => 'VesselName',
        'metadata_application_representative_field' => 'Fartygsombud',
        'metadata_application_received_field' => 'ReceivedDate',
        'metadata_application_route_field' => 'ApplicationPath',
        'metadata_application_decision_date_field' => 'DecisionDate',
        'metadata_application_aspirant_start_field' => 'AspirantStartDate',
        'metadata_application_aspirant_review_field' => 'AspirantReviewDate',
        'metadata_application_public_comment_field' => 'ExternStatuskommentar',
    );

    public static function value(string $key): string
    {
        if (! isset(self::KEYS[$key])) {
            return '';
        }

        if ('tenant_id' === $key) {
            self::ensure_central_config_loaded();
            return class_exists('SSF_Microsoft365_Config') ? \SSF_Microsoft365_Config::get_tenant_id() : '';
        }

        $destination = SharePointDestinations::legacy_mapping($key);
        if ($destination) {
            return SharePointDestinations::value($destination[0], $destination[1]);
        }

        return self::legacy_value($key);
    }

    public static function authority_url(string $path): string
    {
        self::ensure_central_config_loaded();
        if (class_exists('SSF_Microsoft365_Config')) {
            return \SSF_Microsoft365_Config::get_authority_url($path);
        }

        return 'https://login.microsoftonline.com/' . rawurlencode(self::value('tenant_id')) . $path;
    }

    public static function legacy_value(string $key): string
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

    public static function destination(string $destination): array
    {
        return SharePointDestinations::get($destination);
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
        delete_option('ssf_member_portal_graph_motion_schema');
        delete_option('ssf_medlemsprocess_graph_schema');

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
        delete_option('ssf_member_portal_graph_motion_schema');
        delete_option('ssf_medlemsprocess_graph_schema');
    }

    public static function remove_legacy_webhook_settings(): void
    {
        if ('yes' === get_option(self::LEGACY_WEBHOOK_CLEANUP_OPTION, 'no')) {
            return;
        }

        $settings = self::stored();
        if (array_key_exists('webhook_secret', $settings)) {
            unset($settings['webhook_secret']);
            update_option(self::OPTION, $settings, false);
        }

        delete_option('ssf_member_portal_power_automate_inbound_enabled');
        delete_option('ssf_member_portal_power_automate_last_result');
        update_option(self::LEGACY_WEBHOOK_CLEANUP_OPTION, 'yes', false);
    }

    /**
     * Persists only the discovered document-library list ID. A wp-config value
     * still takes precedence, so deployment configuration remains authoritative.
     */
    public static function save_discovered_document_library_list_id(string $list_id): void
    {
        $list_id = sanitize_text_field($list_id);
        if (! $list_id || self::server_value('document_library_list_id')) {
            return;
        }

        SharePointDestinations::save_field('annual_meetings', 'list_id', $list_id);
    }

    public static function save_discovered_application_list_id(string $list_id): void
    {
        $list_id = sanitize_text_field($list_id);
        if (! $list_id || self::server_value('application_list_id')) {
            return;
        }
        SharePointDestinations::save_field('membership_applications', 'list_id', $list_id);
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
        return ! self::missing() && SharePointDestinations::write_allowed('annual_meetings');
    }

    public static function credential_missing(): array
    {
        $labels = array('tenant_id' => 'Tenant ID', 'client_id' => 'Client ID', 'client_secret' => 'Client secret');
        $missing = array();
        foreach ($labels as $key => $label) {
            if (! self::value($key)) {
                $missing[] = $label;
            }
        }
        return $missing;
    }

    public static function public_status(): array
    {
        $status = array();
        foreach (self::KEYS as $key => $constant) {
            if ('tenant_id' === $key) {
                self::ensure_central_config_loaded();
                $status[$key] = array('constant' => 'SSF_MICROSOFT365_TENANT_ID', 'configured' => class_exists('SSF_Microsoft365_Config') && \SSF_Microsoft365_Config::is_tenant_configured(), 'source' => 'central');
                continue;
            }
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

    /**
     * Safe presentation data for the single SharePoint/Graph credential UI.
     * Deliberately never returns the encrypted value or its decrypted secret.
     */
    public static function sharepoint_credentials_status(): array
    {
        $status = self::public_status();
        $stored = self::stored();
        $client_id = (array) ($status['client_id'] ?? array());
        $client_secret = (array) ($status['client_secret'] ?? array());

        return array(
            'tenant' => (array) ($status['tenant_id'] ?? array()),
            'client_id' => array(
                'configured' => ! empty($client_id['configured']),
                'source' => (string) ($client_id['source'] ?? 'missing'),
                'value' => self::value('client_id'),
                'editable' => 'server' !== ($client_id['source'] ?? ''),
            ),
            'client_secret' => array(
                'configured' => ! empty($client_secret['configured']),
                'source' => (string) ($client_secret['source'] ?? 'missing'),
                'stored' => ! empty($stored['client_secret']),
                'server_authoritative' => 'server' === ($client_secret['source'] ?? ''),
            ),
        );
    }

    private static function stored(): array
    {
        return (array) get_option(self::OPTION, array());
    }

    private static function ensure_central_config_loaded(): void
    {
        if (class_exists('SSF_Microsoft365_Config')) {
            return;
        }
        $base = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/mu-plugins' : '');
        $file = $base ? rtrim((string) $base, '/\\') . '/ssf-microsoft365-config.php' : '';
        if ($file && is_readable($file)) {
            require_once $file;
        }
    }

    public static function server_value(string $key): string
    {
        $constant = self::KEYS[$key];
        $value = defined($constant) ? constant($constant) : getenv($constant);

        return is_string($value) ? trim($value) : '';
    }

    private static function sanitize(string $key, string $value): string
    {
        if (in_array($key, array('site_path', 'application_site_path'), true)) {
            $value = trim(sanitize_text_field($value));
            return '' === $value ? '' : '/' . ltrim($value, '/');
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
