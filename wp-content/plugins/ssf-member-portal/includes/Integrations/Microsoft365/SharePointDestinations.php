<?php

namespace SSF\MemberPortal\Integrations\Microsoft365;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Central, environment-aware SharePoint destination registry.
 */
final class SharePointDestinations
{
    public const OPTION = 'ssf_member_portal_sharepoint_destinations';
    public const HEALTH_OPTION = 'ssf_member_portal_sharepoint_health';
    private const SCHEMA_VERSION = 2;

    public static function definitions(): array
    {
        return array(
            'annual_meetings' => array(
                'label' => 'Årsmöten och motioner',
                'uses' => array('Motioner', 'Motionsbilagor och metadata', 'Årsmötesanmälningsexporter'),
                'defaults' => array('drive_name' => 'Dokument', 'folder_name' => 'Årsmöten', 'folder_path' => 'Årsmöten'),
                'legacy' => array(
                    'site_id' => 'site_id', 'drive_id' => 'drive_id', 'list_id' => 'document_library_list_id',
                    'drive_name' => 'document_library_name', 'folder_id' => 'annual_meeting_folder_id',
                    'folder_name' => 'annual_meeting_folder_name', 'hostname' => 'site_hostname', 'site_path' => 'site_path',
                ),
                'metadata' => array(
                    'wordpress_id' => array('label' => 'WordPress Motion ID', 'default' => 'WordPressMotionID', 'legacy' => 'metadata_wordpress_motion_id_field'),
                    'number' => array('label' => 'Motionnummer', 'default' => 'Motionnummer', 'legacy' => 'metadata_motion_number_field'),
                    'status' => array('label' => 'Status', 'default' => 'Status', 'legacy' => 'metadata_status_field'),
                    'vessel' => array('label' => 'Fartyg', 'default' => 'Fartyg', 'legacy' => 'metadata_vessel_field'),
                    'received' => array('label' => 'Inkommet datum', 'default' => 'InkommenDatum', 'legacy' => 'metadata_received_date_field'),
                ),
            ),
            'membership_applications' => array(
                'label' => 'Medlemsansökningar',
                'uses' => array('Medlems- och fartygsansökan', 'Bilder och bilagor', 'Ansöknings-PDF', 'Statussynk'),
                'defaults' => array('drive_name' => 'Dokument', 'folder_name' => 'Medlemsansökningar', 'folder_path' => 'Medlemsansökningar'),
                'legacy' => array(
                    'site_id' => 'application_site_id', 'drive_id' => 'application_drive_id', 'list_id' => 'application_list_id',
                    'folder_id' => 'application_root_folder_id', 'folder_name' => 'application_root_folder_name',
                    'hostname' => 'application_site_hostname', 'site_path' => 'application_site_path',
                ),
                'metadata' => array(
                    'wordpress_id' => array('label' => 'WordPress Application ID', 'default' => 'WordPressApplicationID', 'legacy' => 'metadata_application_wp_id_field'),
                    'number' => array('label' => 'Ansökningsnummer', 'default' => 'ApplicationNumber', 'legacy' => 'metadata_application_number_field'),
                    'status' => array('label' => 'Ansökningsstatus', 'default' => 'ApplicationStatus', 'legacy' => 'metadata_application_status_field'),
                    'membership_status' => array('label' => 'Medlemsstatus', 'default' => 'MembershipStatus', 'legacy' => 'metadata_application_membership_status_field'),
                    'vessel' => array('label' => 'Fartyg', 'default' => 'VesselName', 'legacy' => 'metadata_application_vessel_field'),
                    'representative' => array('label' => 'Fartygsombud', 'default' => 'Fartygsombud', 'legacy' => 'metadata_application_representative_field'),
                    'received' => array('label' => 'Inkommet datum', 'default' => 'ReceivedDate', 'legacy' => 'metadata_application_received_field'),
                    'route' => array('label' => 'Ansökningsväg', 'default' => 'ApplicationPath', 'legacy' => 'metadata_application_route_field'),
                    'decision_date' => array('label' => 'Beslutsdatum', 'default' => 'DecisionDate', 'legacy' => 'metadata_application_decision_date_field'),
                    'aspirant_start' => array('label' => 'Aspirant från', 'default' => 'AspirantStartDate', 'legacy' => 'metadata_application_aspirant_start_field'),
                    'aspirant_review' => array('label' => 'Aspirant uppföljning', 'default' => 'AspirantReviewDate', 'legacy' => 'metadata_application_aspirant_review_field'),
                    'public_comment' => array('label' => 'Extern statuskommentar', 'default' => 'ExternStatuskommentar', 'legacy' => 'metadata_application_public_comment_field'),
                ),
            ),
        );
    }

    public static function environment(): string
    {
        return 'production' === wp_get_environment_type() ? 'production' : 'development';
    }

    public static function get(string $destination, ?string $environment = null): array
    {
        self::maybe_migrate();
        $definition = self::definitions()[$destination] ?? null;
        if (! $definition) {
            return array();
        }

        $environment = self::valid_environment($environment ?: self::environment());
        $stored = self::stored();
        $defaults = self::profile_defaults($definition);
        $saved = (array) ($stored['destinations'][$destination][$environment] ?? array());
        $profile = array_merge($defaults, $saved);
        $profile['metadata'] = array_merge((array) $defaults['metadata'], (array) ($saved['metadata'] ?? array()));
        if ($environment === self::environment()) {
            $profile = self::apply_server_overrides($destination, $profile);
        }

        $profile = self::normalize_site_details($profile);
        $profile['destination'] = $destination;
        $profile['environment'] = $environment;
        $profile['write_blocked'] = ! self::write_allowed($destination, $environment, false);
        return $profile;
    }

    public static function value(string $destination, string $field, ?string $environment = null): string
    {
        $profile = self::get($destination, $environment);
        if (0 === strpos($field, 'metadata.')) {
            return (string) ($profile['metadata'][substr($field, 9)] ?? '');
        }
        return (string) ($profile[$field] ?? '');
    }

    public static function save(string $destination, string $environment, array $input)
    {
        if (! isset(self::definitions()[$destination])) {
            return new \WP_Error('sharepoint_destination_invalid', 'Okänd SharePoint-destination.');
        }
        $environment = self::valid_environment($environment);
        self::maybe_migrate();
        $stored = self::stored();
        $stored['schema_version'] = self::SCHEMA_VERSION;
        $stored['destinations'][$destination][$environment] = self::sanitize_profile($input, self::definitions()[$destination]);
        update_option(self::OPTION, $stored, false);
        return true;
    }

    public static function save_field(string $destination, string $field, string $value, ?string $environment = null): void
    {
        $environment = self::valid_environment($environment ?: self::environment());
        $profile = self::get($destination, $environment);
        unset($profile['destination'], $profile['environment'], $profile['write_blocked']);
        if (0 === strpos($field, 'metadata.')) {
            $profile['metadata'][substr($field, 9)] = sanitize_text_field($value);
        } else {
            $profile[$field] = sanitize_text_field($value);
        }
        self::save($destination, $environment, $profile);
    }

    public static function save_policy(bool $block_shared_development): void
    {
        self::maybe_migrate();
        $stored = self::stored();
        $stored['block_shared_development'] = $block_shared_development;
        update_option(self::OPTION, $stored, false);
    }

    public static function policy_enabled(): bool
    {
        self::maybe_migrate();
        return ! empty(self::stored()['block_shared_development']);
    }

    public static function write_allowed(string $destination, ?string $environment = null, bool $migrate = true): bool
    {
        if ($migrate) {
            self::maybe_migrate();
        }
        $environment = self::valid_environment($environment ?: self::environment());
        if ('development' !== $environment) {
            return true;
        }
        $stored = self::stored();
        if (empty($stored['block_shared_development'])) {
            return true;
        }
        $development = (array) ($stored['destinations'][$destination]['development'] ?? array());
        if ('development' === self::environment()) {
            $development = self::apply_server_overrides($destination, $development);
        }
        $production = (array) ($stored['destinations'][$destination]['production'] ?? array());
        return ! self::same_target($development, $production);
    }

    public static function write_allowed_for_profile(string $destination, string $environment, array $profile): bool
    {
        $environment = self::valid_environment($environment);
        if ('development' !== $environment || ! self::policy_enabled()) {
            return true;
        }
        $stored = self::stored();
        return ! self::same_target($profile, (array) ($stored['destinations'][$destination]['production'] ?? array()));
    }

    public static function warnings(string $destination): array
    {
        self::maybe_migrate();
        $stored = self::stored();
        $development = (array) ($stored['destinations'][$destination]['development'] ?? array());
        if ('development' === self::environment()) {
            $development = self::apply_server_overrides($destination, $development);
        }
        $production = (array) ($stored['destinations'][$destination]['production'] ?? array());
        if (! self::same_target($development, $production)) {
            return array();
        }
        return array(empty($stored['block_shared_development'])
            ? 'Development och Production använder samma SharePoint-destination. Testdata kan skrivas till produktionsytan.'
            : 'Development och Production använder samma SharePoint-destination. Skrivning från Development är blockerad.');
    }

    public static function missing(string $destination, ?string $environment = null): array
    {
        $profile = self::get($destination, $environment);
        $labels = array('site_id' => 'Site ID', 'drive_id' => 'Drive ID', 'folder_id' => 'Folder ID');
        $missing = array();
        foreach ($labels as $key => $label) {
            if (empty($profile[$key])) {
                $missing[] = $label;
            }
        }
        return $missing;
    }

    public static function legacy_mapping(string $legacy_key): array
    {
        foreach (self::definitions() as $destination => $definition) {
            foreach ((array) $definition['legacy'] as $field => $candidate) {
                if ($candidate === $legacy_key) {
                    return array($destination, $field);
                }
            }
            foreach ((array) $definition['metadata'] as $key => $metadata) {
                if (($metadata['legacy'] ?? '') === $legacy_key) {
                    return array($destination, 'metadata.' . $key);
                }
            }
        }
        return array();
    }

    public static function health(string $destination, ?string $environment = null): array
    {
        $environment = self::valid_environment($environment ?: self::environment());
        $health = (array) get_option(self::HEALTH_OPTION, array());
        return (array) ($health[$destination][$environment] ?? array());
    }

    public static function save_health(string $destination, string $environment, array $health): void
    {
        $all = (array) get_option(self::HEALTH_OPTION, array());
        $all[$destination][self::valid_environment($environment)] = $health;
        update_option(self::HEALTH_OPTION, $all, false);
    }

    private static function maybe_migrate(): void
    {
        $stored = self::stored();
        if ((int) ($stored['schema_version'] ?? 0) >= self::SCHEMA_VERSION) {
            return;
        }
        $environment = self::environment();
        foreach (self::definitions() as $destination => $definition) {
            if (! empty($stored['destinations'][$destination][$environment])) {
                continue;
            }
            $profile = self::profile_defaults($definition);
            foreach ((array) $definition['legacy'] as $field => $legacy_key) {
                $profile[$field] = Configuration::legacy_value($legacy_key);
            }
            foreach ((array) $definition['metadata'] as $key => $metadata) {
                $profile['metadata'][$key] = Configuration::legacy_value((string) $metadata['legacy']);
            }
            $stored['destinations'][$destination][$environment] = self::sanitize_profile($profile, $definition);
        }
        $stored['schema_version'] = self::SCHEMA_VERSION;
        $stored['migrated_environment'] = $environment;
        $stored['migrated_at'] = gmdate('c');
        update_option(self::OPTION, $stored, false);
    }

    private static function profile_defaults(array $definition): array
    {
        $defaults = array_merge(array(
            'site_url' => '', 'site_name' => '', 'hostname' => '', 'site_path' => '', 'group_id' => '', 'site_id' => '',
            'drive_name' => '', 'drive_id' => '', 'drive_web_url' => '', 'list_id' => '',
            'folder_name' => '', 'folder_path' => '', 'folder_id' => '', 'folder_web_url' => '', 'metadata' => array(),
        ), (array) ($definition['defaults'] ?? array()));
        foreach ((array) ($definition['metadata'] ?? array()) as $key => $metadata) {
            $defaults['metadata'][$key] = (string) ($metadata['default'] ?? '');
        }
        return $defaults;
    }

    private static function sanitize_profile(array $input, array $definition): array
    {
        $clean = self::profile_defaults($definition);
        foreach (array('site_name', 'hostname', 'site_path', 'group_id', 'site_id', 'drive_name', 'drive_id', 'drive_web_url', 'list_id', 'folder_name', 'folder_path', 'folder_id', 'folder_web_url') as $key) {
            $clean[$key] = sanitize_text_field((string) ($input[$key] ?? ''));
        }
        $clean['site_url'] = esc_url_raw((string) ($input['site_url'] ?? ''));
        $clean['hostname'] = strtolower(trim($clean['hostname']));
        $clean['site_path'] = '' === $clean['site_path'] ? '' : '/' . ltrim($clean['site_path'], '/');
        $clean['folder_path'] = trim($clean['folder_path'], '/');
        foreach ((array) $definition['metadata'] as $key => $metadata) {
            $clean['metadata'][$key] = sanitize_text_field((string) ($input['metadata'][$key] ?? $clean['metadata'][$key]));
        }
        return self::normalize_site_details($clean);
    }

    private static function normalize_site_details(array $profile): array
    {
        if (! empty($profile['site_url'])) {
            $parts = wp_parse_url((string) $profile['site_url']);
            if (! empty($parts['host'])) {
                $profile['hostname'] = strtolower((string) $parts['host']);
                $profile['site_path'] = '/' . ltrim((string) ($parts['path'] ?? ''), '/');
            }
        } elseif (! empty($profile['hostname']) && ! empty($profile['site_path'])) {
            $profile['site_url'] = esc_url_raw('https://' . $profile['hostname'] . '/' . ltrim($profile['site_path'], '/'));
        }
        return $profile;
    }

    private static function same_target(array $development, array $production): bool
    {
        if (! empty($development['site_id']) && ! empty($production['site_id'])) {
            return 0 === strcasecmp((string) $development['site_id'], (string) $production['site_id']);
        }
        return ! empty($development['drive_id']) && ! empty($production['drive_id'])
            && 0 === strcasecmp((string) $development['drive_id'], (string) $production['drive_id']);
    }

    private static function apply_server_overrides(string $destination, array $profile): array
    {
        $definition = self::definitions()[$destination] ?? array();
        foreach ((array) ($definition['legacy'] ?? array()) as $field => $legacy_key) {
            $server_value = Configuration::server_value($legacy_key);
            if ('' !== $server_value) {
                $profile[$field] = $server_value;
            }
        }
        foreach ((array) ($definition['metadata'] ?? array()) as $key => $metadata) {
            $server_value = Configuration::server_value((string) ($metadata['legacy'] ?? ''));
            if ('' !== $server_value) {
                $profile['metadata'][$key] = $server_value;
            }
        }
        return $profile;
    }

    private static function valid_environment(string $environment): string
    {
        return 'production' === $environment ? 'production' : 'development';
    }

    private static function stored(): array
    {
        return (array) get_option(self::OPTION, array());
    }
}
