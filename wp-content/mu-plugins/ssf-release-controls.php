<?php
/**
 * Plugin Name: SSF Release Controls
 * Description: Central environment, release, and feature controls for SSF.
 *
 * Configuration belongs in wp-config.php or deployment environment variables.
 */

if (! defined('ABSPATH')) {
    exit;
}

final class SSF_Environment
{
    private const ENVIRONMENTS = array('production', 'development', 'staging', 'local');

    private static ?string $environment = null;

    public static function get_environment(): string
    {
        if (null !== self::$environment) {
            return self::$environment;
        }

        $environment = self::configured_value('SSF_ENVIRONMENT');
        if (! $environment && defined('WP_ENVIRONMENT_TYPE')) {
            $environment = (string) WP_ENVIRONMENT_TYPE;
        }
        if (! $environment) {
            $wordpress_environment = function_exists('wp_get_environment_type') ? (string) wp_get_environment_type() : '';
            if ($wordpress_environment && 'production' !== $wordpress_environment) {
                $environment = $wordpress_environment;
            }
        }
        if (! $environment) {
            $environment = self::installation_environment();
        }

        $environment = strtolower(trim($environment));
        self::$environment = in_array($environment, self::ENVIRONMENTS, true) ? $environment : 'production';
        return self::$environment;
    }

    public static function is_production(): bool
    {
        return 'production' === self::get_environment();
    }

    public static function is_development(): bool
    {
        return in_array(self::get_environment(), array('development', 'local'), true);
    }

    public static function label(): string
    {
        $labels = array(
            'production' => 'PROD',
            'development' => 'DEV',
            'staging' => 'STAGING',
            'local' => 'LOCAL',
        );
        return $labels[self::get_environment()] ?? strtoupper(self::get_environment());
    }

    private static function installation_environment(): string
    {
        $path = str_replace('\\', '/', (string) ABSPATH);
        if (preg_match('#/(?:dev|development)(?:/|$)#i', rtrim($path, '/'))) {
            return 'development';
        }
        if (preg_match('#/staging(?:/|$)#i', rtrim($path, '/'))) {
            return 'staging';
        }
        return 'production';
    }

    public static function configured_value(string $name): string
    {
        if (defined($name)) {
            $value = constant($name);
            return is_scalar($value) ? trim((string) $value) : '';
        }
        $value = getenv($name);
        return is_string($value) ? trim($value) : '';
    }
}

final class SSF_Release_Info
{
    public static function get_version(): string
    {
        return self::value('SSF_RELEASE_VERSION', SSF_Environment::is_production() ? 'ej konfigurerad' : 'dev');
    }

    public static function get_environment(): string
    {
        return SSF_Environment::get_environment();
    }

    public static function get_release_date(): string
    {
        return self::value('SSF_RELEASE_DATE');
    }

    public static function get_commit(): string
    {
        return self::value('SSF_RELEASE_COMMIT');
    }

    public static function is_configured(): bool
    {
        return '' !== self::value('SSF_RELEASE_VERSION');
    }

    public static function get_display_string(): string
    {
        return sprintf('Release %s - %s', self::get_version(), SSF_Environment::label());
    }

    private static function value(string $name, string $fallback = ''): string
    {
        $value = SSF_Environment::configured_value($name);
        return '' !== $value ? $value : $fallback;
    }
}

final class SSF_Features
{
    private const CONSTANTS = array(
        'applications' => 'SSF_FEATURE_APPLICATIONS',
        'motions' => 'SSF_FEATURE_MOTIONS',
        'annual_meetings' => 'SSF_FEATURE_ANNUAL_MEETINGS',
        'annual_meeting_registration' => 'SSF_FEATURE_ANNUAL_MEETING_REGISTRATION',
        'calendar' => 'SSF_FEATURE_CALENDAR',
    );

    public static function enabled(string $feature): bool
    {
        if (! isset(self::CONSTANTS[$feature])) {
            return false;
        }

        $name = self::CONSTANTS[$feature];
        if (defined($name)) {
            return self::boolean(constant($name));
        }

        $environment_value = getenv($name);
        if (false !== $environment_value && '' !== $environment_value) {
            return self::boolean($environment_value);
        }

        if (! SSF_Environment::is_production()) {
            return true;
        }

        return in_array($feature, array('motions', 'calendar'), true);
    }

    public static function all(): array
    {
        $features = array();
        foreach (array_keys(self::CONSTANTS) as $feature) {
            $features[$feature] = self::enabled($feature);
        }
        return $features;
    }

    private static function boolean($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}

final class SSF_Release_Controls
{
    public static function boot(): void
    {
        add_filter('wp_nav_menu_objects', array(__CLASS__, 'filter_menu_items'), 20, 2);
        add_action('template_redirect', array(__CLASS__, 'guard_public_routes'));
        add_action('admin_bar_menu', array(__CLASS__, 'add_environment_node'), 90);
    }

    public static function filter_menu_items(array $items, $args): array
    {
        $filtered = array();
        $has_motions_link = false;
        $member_parent = 0;

        foreach ($items as $item) {
            $path = trim((string) wp_parse_url((string) $item->url, PHP_URL_PATH), '/');
            $is_application = self::path_contains($path, 'ansokan');
            $is_annual_meeting = self::path_contains($path, 'arsmote') || self::path_contains($path, 'arsmoten');
            $is_registration = $is_annual_meeting && self::path_contains($path, 'anmalan');

            if (! SSF_Features::enabled('applications') && $is_application) {
                continue;
            }
            if (! SSF_Features::enabled('annual_meetings') && $is_annual_meeting) {
                continue;
            }
            if (! SSF_Features::enabled('annual_meeting_registration') && $is_registration) {
                continue;
            }

            if (self::path_contains($path, 'motioner')) {
                $has_motions_link = true;
            }
            if (self::path_contains($path, 'for-medlemmar') || 'fÃ¶r medlemmar' === self::normalise((string) $item->title)) {
                $member_parent = (int) $item->ID;
            }
            $filtered[] = $item;
        }

        if (SSF_Features::enabled('motions') && ! $has_motions_link) {
            $filtered[] = (object) array(
                'ID' => 0,
                'db_id' => 0,
                'menu_item_parent' => $member_parent,
                'object_id' => 0,
                'object' => 'custom',
                'type' => 'custom',
                'type_label' => 'Custom Link',
                'title' => 'Motioner',
                'url' => home_url('/motioner/'),
                'target' => '',
                'attr_title' => '',
                'description' => '',
                'classes' => array('ssf-menu-item-motioner'),
                'xfn' => '',
                'status' => 'publish',
                'current' => false,
                'current_item_parent' => false,
                'current_item_ancestor' => false,
            );
        }

        return $filtered;
    }

    public static function guard_public_routes(): void
    {
        if (! SSF_Environment::is_production() || ! is_page()) {
            return;
        }

        $annual_page_ids = array_filter(array(
            (int) get_option('ssf_member_portal_annual_meeting_page_id', 0),
            (int) get_option('ssf_member_portal_annual_meetings_archive_page_id', 0),
            (int) get_option('ssf_member_portal_annual_meeting_registration_page_id', 0),
        ));
        if (! SSF_Features::enabled('annual_meetings') && $annual_page_ids && is_page($annual_page_ids)) {
            wp_safe_redirect(home_url('/'), 302);
            exit;
        }
        if (! SSF_Features::enabled('annual_meeting_registration')) {
            $registration_id = (int) get_option('ssf_member_portal_annual_meeting_registration_page_id', 0);
            if ($registration_id && is_page($registration_id)) {
                wp_safe_redirect(home_url('/'), 302);
                exit;
            }
        }
    }

    public static function add_environment_node($admin_bar): void
    {
        if (SSF_Environment::is_production() || ! current_user_can('manage_options')) {
            return;
        }
        $admin_bar->add_node(array(
            'id' => 'ssf-environment',
            'title' => 'SSF ' . SSF_Environment::label() . ' - ' . SSF_Release_Info::get_version(),
            'href' => admin_url('admin.php?page=ssf-member-portal-status'),
        ));
    }

    public static function health(): array
    {
        global $wpdb;
        $database_ok = $wpdb instanceof wpdb && '1' === (string) $wpdb->get_var('SELECT 1');
        $portal_active = defined('SSF_MEMBER_PORTAL_VERSION');
        $applications_active = defined('SSF_MEDLEMSPROCESS_VERSION');
        $calendar_active = defined('SSF_CALENDAR_VERSION');
        $motion_page_id = (int) get_option('ssf_member_portal_motion_hub_page_id', 0);
        $motion_page_ready = $motion_page_id && 'publish' === get_post_status($motion_page_id);
        $theme_ready = 'ssf' === get_stylesheet();
        return array(
            'environment' => array('ok' => true, 'value' => SSF_Environment::get_environment()),
            'release' => array('ok' => SSF_Release_Info::is_configured(), 'value' => SSF_Release_Info::get_version()),
            'database' => array('ok' => $database_ok, 'value' => $database_ok ? 'Ansluten' : 'Kunde inte verifieras'),
            'plugins' => array('ok' => $portal_active && $applications_active && $calendar_active, 'value' => $portal_active && $applications_active && $calendar_active ? 'Aktiva' : 'Kontrollera SSF-plugins'),
            'motion_page' => array('ok' => ! SSF_Features::enabled('motions') || $motion_page_ready, 'value' => $motion_page_ready ? 'Publicerad' : 'Saknas eller ej publicerad'),
            'footer_theme' => array('ok' => $theme_ready, 'value' => $theme_ready ? 'SSF-temat aktivt' : 'Kontrollera aktivt tema'),
            'motions' => array('ok' => SSF_Features::enabled('motions'), 'value' => SSF_Features::enabled('motions') ? 'Aktiv' : 'AvstÃ¤ngd'),
            'applications' => array('ok' => ! SSF_Environment::is_production() || ! SSF_Features::enabled('applications'), 'value' => SSF_Features::enabled('applications') ? 'Aktiv' : 'AvstÃ¤ngd'),
            'annual_registration' => array('ok' => ! SSF_Environment::is_production() || ! SSF_Features::enabled('annual_meeting_registration'), 'value' => SSF_Features::enabled('annual_meeting_registration') ? 'Aktiv' : 'AvstÃ¤ngd'),
        );
    }

    private static function path_contains(string $path, string $needle): bool
    {
        return false !== strpos(self::normalise($path), self::normalise($needle));
    }

    private static function normalise(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}

SSF_Release_Controls::boot();
