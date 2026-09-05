<?php
/**
 * Plugin Name: SSF Release Controls
 * Description: Central environment, release, and feature controls for SSF.
 * Version: 2.0.0
 *
 * Normal feature settings are managed in SSF > Funktioner. wp-config.php is
 * reserved for environment configuration and emergency overrides.
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
            $environment = function_exists('wp_get_environment_type') ? (string) wp_get_environment_type() : 'production';
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
            'production' => 'Production',
            'development' => 'Development',
            'staging' => 'Staging',
            'local' => 'Local',
        );
        return $labels[self::get_environment()] ?? ucfirst(self::get_environment());
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

final class SSF_Release_Manager
{
    public const POST_TYPE = 'ssf_release';
    public const CAPABILITY = 'manage_ssf_releases';
    public const AUDIT_OPTION = 'ssf_release_audit_log';
    public const DEPLOYMENTS_OPTION = 'ssf_release_deployments';
    public const CURRENT_DEPLOYMENT_OPTION = 'ssf_release_current_deployment';

    private const CACHE_GROUP = 'ssf_release_manager';
    private const MANIFEST_FILE = 'ssf-release-manifest.json';
    private const STATUSES = array('draft', 'development', 'prepared', 'released', 'superseded');
    private const CURRENT_STATUSES = array('prepared', 'development', 'released', 'draft');

    private static ?array $current_release = null;
    private static ?array $manifest = null;
    private static bool $manifest_loaded = false;

    public static function boot(): void
    {
        add_action('init', array(__CLASS__, 'register_post_type'), 4);
        add_action('init', array(__CLASS__, 'ensure_capability'), 5);
        add_action('init', array(__CLASS__, 'sync_manifest'), 6);
        add_action('admin_menu', array(__CLASS__, 'register_admin_page'), 55);
        add_action('admin_post_ssf_save_release', array(__CLASS__, 'save_release'));
        add_action('admin_post_ssf_register_release_build', array(__CLASS__, 'register_build_from_admin'));
        add_action('admin_post_ssf_verify_release_deployment', array(__CLASS__, 'verify_deployment_from_admin'));
        add_action('admin_post_ssf_fail_release_deployment', array(__CLASS__, 'fail_deployment_from_admin'));
        add_action('wp_dashboard_setup', array(__CLASS__, 'register_dashboard_widget'));
    }

    public static function register_post_type(): void
    {
        register_post_type(
            self::POST_TYPE,
            array(
                'labels' => array(
                    'name' => 'SSF releaser',
                    'singular_name' => 'SSF release',
                ),
                'public' => false,
                'show_ui' => false,
                'show_in_rest' => false,
                'supports' => array('title'),
                'capability_type' => 'post',
            )
        );
    }

    public static function ensure_capability(): void
    {
        $administrator = get_role('administrator');
        if ($administrator && ! $administrator->has_cap(self::CAPABILITY)) {
            $administrator->add_cap(self::CAPABILITY);
        }
    }

    public static function get_current_release(): array
    {
        if (null !== self::$current_release) {
            return self::$current_release;
        }

        $environment = self::get_environment();
        $cache_key = 'current_' . $environment;
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if (is_array($cached)) {
            self::$current_release = $cached;
            return self::$current_release;
        }

        $release = self::find_current_release($environment);
        if (! $release) {
            $release = self::legacy_release($environment);
        }
        if (! $release) {
            $release = self::empty_release($environment);
        }

        $manifest = self::get_manifest();
        if ($manifest) {
            $release['version'] = (string) $manifest['version'];
            $release['release_name'] = (string) $manifest['release_name'];
            $release['build'] = (string) $manifest['build'];
            $release['built_at'] = (string) $manifest['built_at'];
            $release['source'] = (string) $manifest['source'];
            $release['source_commit'] = (string) $manifest['source_revision'];
            $release['components'] = (array) $manifest['components'];
            $release['notes'] = (string) $manifest['notes'];
            $release['environment'] = $environment;
            $deployment = self::get_current_deployment();
            $last_successful_deployment = self::get_last_successful_deployment((string) $manifest['build']);
            $release['deployment_status'] = (string) ($deployment['status'] ?? 'pending');
            $release['deployed_at'] = (string) ($last_successful_deployment['deployed_at'] ?? '');
            if ('production' === $environment) {
                $release['status'] = 'success' === ($deployment['status'] ?? '') && $manifest['build'] === ($deployment['build'] ?? '') ? 'released' : 'prepared';
            } else {
                $release['status'] = (string) $manifest['status'];
            }
        }

        self::$current_release = $release;
        wp_cache_set($cache_key, $release, self::CACHE_GROUP, 300);
        return self::$current_release;
    }

    public static function get_version(): string
    {
        return (string) (self::get_current_release()['version'] ?? '');
    }

    public static function get_release_name(): string
    {
        return (string) (self::get_current_release()['release_name'] ?? 'SSF Web');
    }

    public static function get_release_date(): string
    {
        return (string) (self::get_current_release()['release_date'] ?? '');
    }

    public static function get_build(): string
    {
        return (string) (self::get_current_release()['build'] ?? '');
    }

    public static function get_built_at(): string
    {
        return (string) (self::get_current_release()['built_at'] ?? '');
    }

    public static function get_deployed_at(): string
    {
        return (string) (self::get_current_release()['deployed_at'] ?? '');
    }

    public static function get_environment(): string
    {
        return SSF_Environment::get_environment();
    }

    public static function get_environment_label(): string
    {
        $labels = array(
            'production' => 'Production',
            'development' => 'Development',
            'staging' => 'Staging',
            'local' => 'Local',
        );
        return $labels[self::get_environment()] ?? ucfirst(self::get_environment());
    }

    public static function get_status(): string
    {
        return (string) (self::get_current_release()['status'] ?? '');
    }

    public static function get_commit(): string
    {
        return (string) (self::get_current_release()['source_commit'] ?? '');
    }

    public static function get_display_string(): string
    {
        $release = self::get_current_release();
        $parts = array(self::release_title($release));
        if (! empty($release['build'])) {
            $parts[] = 'Build ' . $release['build'];
        }
        $parts[] = self::get_environment_label();
        return implode(' · ', $parts);
    }

    public static function is_production(): bool
    {
        return 'production' === self::get_environment();
    }

    public static function is_configured(): bool
    {
        return '' !== self::get_version();
    }

    public static function validate_version(string $version): bool
    {
        return 1 === preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*)?$/', $version);
    }

    public static function status_label(string $status): string
    {
        $labels = array(
            'draft' => 'Draft',
            'development' => 'Development',
            'prepared' => 'Förberedd',
            'released' => 'Released',
            'superseded' => 'Superseded',
        );
        if ('' === $status) {
            return 'Ej konfigurerat';
        }
        return $labels[$status] ?? ucfirst($status);
    }

    public static function get_allowed_statuses(): array
    {
        return self::is_production() ? array('draft', 'released', 'superseded') : self::STATUSES;
    }

    public static function manifest_path(): string
    {
        return __DIR__ . '/' . self::MANIFEST_FILE;
    }

    public static function get_manifest(): ?array
    {
        if (self::$manifest_loaded) {
            return self::$manifest;
        }
        self::$manifest_loaded = true;
        $path = self::manifest_path();
        if (! is_readable($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return null;
        }
        self::$manifest = self::normalize_manifest($decoded);
        return self::$manifest;
    }

    public static function write_manifest(array $manifest)
    {
        $manifest = self::normalize_manifest($manifest);
        if (! $manifest) {
            return new WP_Error('ssf_release_invalid_manifest', 'Release-manifestet innehåller ogiltiga värden.');
        }
        $json = wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($json)) {
            return new WP_Error('ssf_release_manifest_encode', 'Release-manifestet kunde inte skapas.');
        }
        $path = self::manifest_path();
        $temporary = $path . '.tmp-' . wp_generate_password(8, false, false);
        if (false === file_put_contents($temporary, $json . "\n", LOCK_EX) || ! @rename($temporary, $path)) {
            @unlink($temporary);
            return new WP_Error('ssf_release_manifest_write', 'Release-manifestet kunde inte skrivas. Kontrollera filrättigheterna.');
        }
        self::$manifest_loaded = false;
        self::$manifest = null;
        self::clear_cache();
        return self::get_manifest();
    }

    public static function create_build(array $args = array())
    {
        if (self::is_production()) {
            return new WP_Error('ssf_release_production_build', 'Nya builds får inte skapas i production. Promovera en testad DEV-build i stället.');
        }
        $lock = self::build_lock();
        if (! $lock) {
            return new WP_Error('ssf_release_build_locked', 'En annan buildregistrering pågår. Försök igen.');
        }
        try {
            self::$manifest_loaded = false;
            $current = self::get_manifest();
            $date = gmdate('Ymd');
            $sequence = 0;
            if ($current && preg_match('/^' . preg_quote($date, '/') . '\.(\d+)$/', (string) $current['build'], $matches)) {
                $sequence = (int) $matches[1];
            }
            $sequence_option = 'ssf_release_build_sequence_' . $date;
            $sequence = max($sequence, (int) get_option($sequence_option, 0)) + 1;
            update_option($sequence_option, $sequence, false);
            $requested_version = isset($args['version']) ? trim((string) $args['version']) : '';
            $version = '' !== $requested_version ? $requested_version : (string) ($current['version'] ?? '');
            if ('' !== $version && ! self::validate_version($version)) {
                return new WP_Error('ssf_release_invalid_version', 'Versionen måste följa Semantic Versioning.');
            }
            $manifest = array(
                'schema_version' => 1,
                'release_name' => 'SSF Web',
                'version' => $version,
                'build' => $date . '.' . $sequence,
                'built_at' => gmdate('c'),
                'source' => sanitize_key((string) ($args['source'] ?? 'manual')) ?: 'manual',
                'source_revision' => sanitize_text_field((string) ($args['source_revision'] ?? '')),
                'description' => sanitize_text_field((string) ($args['description'] ?? '')),
                'notes' => sanitize_textarea_field((string) ($args['notes'] ?? ($current['notes'] ?? ''))),
                'components' => self::sanitize_components((array) ($args['components'] ?? array())),
                'status' => 'development',
                'prepared_at' => '',
            );
            $written = self::write_manifest($manifest);
            if (is_wp_error($written)) {
                return $written;
            }
            self::sync_manifest(true);
            return $written;
        } finally {
            self::release_build_lock($lock);
        }
    }

    public static function prepare_release(string $level = '', string $explicit_version = '', string $notes = '')
    {
        if (self::is_production()) {
            return new WP_Error('ssf_release_prepare_production', 'En release ska förberedas i DEV, inte i production.');
        }
        $manifest = self::get_manifest();
        if (! $manifest) {
            return new WP_Error('ssf_release_missing_manifest', 'Registrera en DEV-build innan releasen förbereds.');
        }
        $version = trim($explicit_version);
        if ($version) {
            if (! self::validate_version($version) || false !== strpos($version, '-')) {
                return new WP_Error('ssf_release_invalid_version', 'Den förberedda versionen måste vara ett stabilt Semantic Versioning-nummer.');
            }
        } else {
            $version = self::bump_version((string) $manifest['version'], $level);
            if (! $version) {
                return new WP_Error('ssf_release_missing_base_version', 'Ange ett explicit versionsnummer när manifestet ännu saknar version.');
            }
        }
        $manifest['version'] = $version;
        $manifest['status'] = 'prepared';
        $manifest['prepared_at'] = gmdate('c');
        $manifest['notes'] = sanitize_textarea_field($notes ?: (string) $manifest['notes']);
        $written = self::write_manifest($manifest);
        if (is_wp_error($written)) {
            return $written;
        }
        self::sync_manifest(true);
        self::add_audit_entry(sprintf('Release %s prepared from build %s', $version, $manifest['build']), self::get_environment(), 'release_prepared');
        return $written;
    }

    public static function bump_version(string $version, string $level): string
    {
        if (! self::validate_version($version) || ! in_array($level, array('patch', 'minor', 'major'), true)) {
            return '';
        }
        $core = explode('-', $version, 2)[0];
        $parts = array_map('intval', explode('.', $core));
        if ('major' === $level) {
            return ($parts[0] + 1) . '.0.0';
        }
        if ('minor' === $level) {
            return $parts[0] . '.' . ($parts[1] + 1) . '.0';
        }
        return $parts[0] . '.' . $parts[1] . '.' . ($parts[2] + 1);
    }

    public static function sync_manifest(bool $force = false): void
    {
        $manifest = self::get_manifest();
        if (! $manifest) {
            return;
        }
        $environment = self::get_environment();
        $post_id = self::find_release_id_by_build((string) $manifest['build'], $environment);
        $created = false;
        if (! $post_id) {
            $post_id = wp_insert_post(array(
                'post_type' => self::POST_TYPE,
                'post_status' => 'publish',
                'post_title' => self::release_title($manifest),
                'post_content' => (string) $manifest['notes'],
            ), true);
            if (is_wp_error($post_id)) {
                return;
            }
            $created = true;
        }
        $status = 'production' === $environment ? 'prepared' : (string) $manifest['status'];
        if ($created || $force) {
            self::save_release_meta((int) $post_id, array_merge($manifest, array(
                'environment' => $environment,
                'status' => $status,
                'release_date' => '',
                'source_commit' => (string) $manifest['source_revision'],
            )));
        }
        if ($created) {
            self::add_audit_entry(sprintf('Build %s installed; verification pending', $manifest['build']), $environment, 'build_installed');
        }
        $deployment = self::get_current_deployment();
        if ($force || (string) ($deployment['build'] ?? '') !== (string) $manifest['build']) {
            self::record_deployment('pending', (string) $manifest['build'], (string) $manifest['build'], 'Installerad build väntar på verifiering.', (string) $manifest['source']);
        }
        self::clear_cache();
    }

    public static function get_current_deployment(): array
    {
        return (array) get_option(self::CURRENT_DEPLOYMENT_OPTION, array());
    }

    public static function get_deployments(int $limit = 50): array
    {
        return array_slice((array) get_option(self::DEPLOYMENTS_OPTION, array()), 0, max(1, $limit));
    }

    public static function get_last_successful_deployment(string $build = ''): array
    {
        foreach (self::get_deployments(100) as $deployment) {
            if ('success' === ($deployment['status'] ?? '') && ('' === $build || $build === ($deployment['build'] ?? ''))) {
                return (array) $deployment;
            }
        }
        return array();
    }

    public static function verify_deployment(string $expected_build = ''): array
    {
        $manifest = self::get_manifest();
        $detected = $manifest ? (string) $manifest['build'] : '';
        $expected = $expected_build ?: $detected;
        if (! $manifest) {
            return self::record_deployment('failed', $expected, '', 'Release-manifest saknas eller är ogiltigt.', 'verification');
        }
        if ($expected && $expected !== $detected) {
            return self::record_deployment('failed', $expected, $detected, 'Installerad build matchar inte förväntad build.', 'verification');
        }
        $entry = self::record_deployment('success', $expected, $detected, 'Manifest och installerad build verifierade.', 'verification');
        $post_id = self::find_release_id_by_build($detected, self::get_environment());
        if ($post_id) {
            update_post_meta($post_id, '_ssf_release_status', self::is_production() ? 'released' : (string) $manifest['status']);
            update_post_meta($post_id, '_ssf_release_date', current_time('Y-m-d'));
            if (self::is_production()) {
                self::supersede_previous_releases($post_id, 'production');
            }
        }
        self::clear_cache();
        return $entry;
    }

    public static function begin_deployment(string $expected_build = '')
    {
        $manifest = self::get_manifest();
        if (! $manifest) {
            return new WP_Error('ssf_release_missing_manifest', 'Release-manifest saknas eller är ogiltigt.');
        }
        if (self::is_production() && 'prepared' !== $manifest['status']) {
            return new WP_Error('ssf_release_not_prepared', 'Production kräver en förberedd release.');
        }
        $expected = $expected_build ?: (string) $manifest['build'];
        return self::record_deployment('pending', $expected, (string) $manifest['build'], 'Deployment registrerad; verifiering återstår.', 'deployment');
    }

    public static function record_failed_deployment(string $expected_build, string $reason): array
    {
        $manifest = self::get_manifest();
        return self::record_deployment('failed', $expected_build, (string) ($manifest['build'] ?? ''), $reason, 'deployment');
    }

    public static function deployment_status_label(string $status): string
    {
        $labels = array('pending' => 'Väntar på verifiering', 'success' => 'Verifierad', 'failed' => 'Misslyckad');
        return $labels[$status] ?? 'Ej registrerad';
    }

    public static function register_admin_page(): void
    {
        add_submenu_page(class_exists('SSF_Admin_Navigation') ? null : 'ssf', 'SSF Release', 'Release', self::CAPABILITY, 'ssf-release', array(__CLASS__, 'render_admin_page'));
    }

    public static function register_dashboard_widget(): void
    {
        if (current_user_can(self::CAPABILITY)) {
            wp_add_dashboard_widget('ssf-release-dashboard', 'SSF: Release', array(__CLASS__, 'render_dashboard_widget'));
        }
    }

    public static function render_dashboard_card(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            return;
        }
        $release = self::get_current_release();
        ?>
        <section class="ssf-release-dashboard-card">
            <h2>Release</h2>
            <p><strong><?php echo esc_html(self::get_display_string()); ?></strong></p>
            <p><?php echo esc_html(self::status_label((string) $release['status'])); ?><?php echo ! empty($release['release_date']) ? ' ' . esc_html((string) $release['release_date']) : ''; ?></p>
            <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ssf-release')); ?>">Hantera release</a></p>
        </section>
        <?php
    }

    public static function render_dashboard_widget(): void
    {
        self::render_dashboard_card();
    }

    public static function render_admin_page(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die('Du saknar behörighet att ändra SSF:s releaser.');
        }

        $current = self::get_current_release();
        $manifest = self::get_manifest();
        $deployment = self::get_current_deployment();
        $error = isset($_GET['ssf_release_error']) ? sanitize_key(wp_unslash($_GET['ssf_release_error'])) : '';
        $updated = isset($_GET['ssf_release_updated']);
        $deployment_notice = isset($_GET['ssf_release_deployment']) ? sanitize_key(wp_unslash($_GET['ssf_release_deployment'])) : '';
        $history = self::get_history();
        ?>
        <div class="wrap ssf-release-admin">
            <h1>SSF Release</h1>
            <?php if (class_exists('SSF_Admin_Navigation')) { SSF_Admin_Navigation::render_system_tabs('ssf-release'); } ?>
            <?php if ($updated) : ?><div class="notice notice-success is-dismissible"><p>Releaseinformationen har sparats.</p></div><?php endif; ?>
            <?php if ($error) : ?><div class="notice notice-error"><p><?php echo esc_html(self::error_message($error)); ?></p></div><?php endif; ?>
            <?php if ('success' === $deployment_notice) : ?><div class="notice notice-success is-dismissible"><p>Installerad build är verifierad och deploymenten är markerad som lyckad.</p></div><?php endif; ?>
            <?php if ('failed' === $deployment_notice) : ?><div class="notice notice-error"><p>Deploymenten kunde inte verifieras och har markerats som misslyckad.</p></div><?php endif; ?>
            <?php if (! $manifest) : ?><div class="notice notice-warning inline"><p>Inget giltigt release-manifest finns ännu. Registrera en build för att börja använda den automatiserade modellen. Äldre releaseposter finns kvar som historik.</p></div><?php endif; ?>

            <h2>Aktuell installation</h2>
            <section class="ssf-release-current">
                <div>
                    <span>Miljö</span><strong><?php echo esc_html(self::get_environment_label()); ?></strong>
                </div>
                <div>
                    <span>Version</span><strong><?php echo esc_html((string) ($current['version'] ?: 'Ej förberedd')); ?></strong>
                </div>
                <div><span>Build</span><strong><?php echo esc_html((string) ($current['build'] ?: 'Ej registrerad')); ?></strong></div>
                <div><span>Senast byggd</span><strong><?php echo esc_html(self::format_timestamp((string) ($current['built_at'] ?? ''))); ?></strong></div>
                <div><span>Senast driftsatt</span><strong><?php echo esc_html(self::format_timestamp((string) ($current['deployed_at'] ?? ''))); ?></strong></div>
                <div><span>Källa</span><strong><?php echo esc_html((string) ($current['source'] ?: 'Legacy/manuell')); ?></strong></div>
                <div><span>Releasestatus</span><strong><?php echo esc_html(self::status_label((string) $current['status'])); ?></strong></div>
                <div><span>Deployment</span><strong class="ssf-deployment-status--<?php echo esc_attr((string) ($deployment['status'] ?? 'none')); ?>"><?php echo esc_html(self::deployment_status_label((string) ($deployment['status'] ?? ''))); ?></strong></div>
            </section>

            <div class="ssf-release-actions">
                <?php if (! self::is_production()) : ?>
                    <section>
                        <h2>Registrera build</h2>
                        <p>Skapar nästa unika buildnummer för dagens datum. Kommandot i deployflödet kan även registrera Git-revision och ändrade komponenter.</p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="ssf_register_release_build">
                            <?php wp_nonce_field('ssf_register_release_build'); ?>
                            <p><label for="ssf-build-description">Kort beskrivning</label><br><input id="ssf-build-description" class="regular-text" name="description"></p>
                            <?php submit_button('Registrera DEV-build', 'secondary', 'submit', false); ?>
                        </form>
                    </section>
                <?php endif; ?>

                <?php if ($manifest && ! self::is_production()) : ?>
                    <section>
                        <h2>Förbered release</h2>
                        <p>Versionsnumret är ett releasebeslut. Build <strong><?php echo esc_html((string) $manifest['build']); ?></strong> ändras inte.</p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="ssf-release-form">
                            <input type="hidden" name="action" value="ssf_save_release">
                            <input type="hidden" name="status" value="prepared">
                            <?php wp_nonce_field('ssf_save_release'); ?>
                            <p><label for="ssf-release-version">Version</label><br><input id="ssf-release-version" class="regular-text" name="version" required value="<?php echo esc_attr((string) $manifest['version']); ?>" placeholder="1.0.0"></p>
                            <p><label for="ssf-release-notes">Release notes</label><br><textarea id="ssf-release-notes" class="large-text" name="notes" rows="5"><?php echo esc_textarea((string) $manifest['notes']); ?></textarea></p>
                            <?php submit_button('Förbered denna build', 'primary', 'submit', false); ?>
                        </form>
                    </section>
                <?php endif; ?>

                <?php if ($manifest) : ?>
                    <section>
                        <h2>Verifiera deployment</h2>
                        <p>Markerar aldrig deploymenten som lyckad förrän manifestets installerade build har verifierats.</p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="ssf_verify_release_deployment">
                            <input type="hidden" name="expected_build" value="<?php echo esc_attr((string) $manifest['build']); ?>">
                            <?php wp_nonce_field('ssf_verify_release_deployment'); ?>
                            <?php submit_button('Verifiera installerad build', 'secondary', 'submit', false); ?>
                        </form>
                        <details><summary>Registrera misslyckad deployment</summary>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action" value="ssf_fail_release_deployment">
                                <input type="hidden" name="expected_build" value="<?php echo esc_attr((string) $manifest['build']); ?>">
                                <?php wp_nonce_field('ssf_fail_release_deployment'); ?>
                                <p><label for="ssf-deployment-failure-reason">Orsak</label><br><input id="ssf-deployment-failure-reason" class="regular-text" name="reason" required></p>
                                <?php submit_button('Markera som misslyckad', 'secondary', 'submit', false); ?>
                            </form>
                        </details>
                    </section>
                <?php endif; ?>
            </div>

            <?php if ($manifest && (! empty($manifest['description']) || ! empty($manifest['components']) || ! empty($manifest['source_revision']))) : ?>
                <details class="ssf-release-technical"><summary>Teknisk information</summary>
                    <?php if ($manifest['description']) : ?><p><?php echo esc_html((string) $manifest['description']); ?></p><?php endif; ?>
                    <?php if ($manifest['source_revision']) : ?><p>Source revision: <code><?php echo esc_html((string) $manifest['source_revision']); ?></code></p><?php endif; ?>
                    <?php if ($manifest['components']) : ?><p>Komponenter: <?php echo esc_html(implode(', ', (array) $manifest['components'])); ?></p><?php endif; ?>
                </details>
            <?php endif; ?>

            <h2>Deploymenthistorik</h2>
            <?php self::render_deployment_history(); ?>
            <h2>Releasehistorik och legacydata</h2>
            <?php self::render_history_table($history); ?>
            <h2>Audit log</h2>
            <?php self::render_audit_log(); ?>
        </div>
        <style>
            .ssf-release-admin{max-width:1180px}.ssf-release-current{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:1px;background:#c3c4c7;border:1px solid #c3c4c7;margin:12px 0 24px}.ssf-release-current div{display:flex;flex-direction:column;gap:7px;min-width:0;background:#fff;padding:16px}.ssf-release-current span{color:#50575e}.ssf-release-current strong{overflow-wrap:anywhere}.ssf-release-actions{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:18px;margin:20px 0}.ssf-release-actions section{border:1px solid #c3c4c7;background:#fff;padding:18px}.ssf-release-actions h2{margin-top:0;font-size:17px}.ssf-release-technical{margin:20px 0;padding:14px 16px;border:1px solid #c3c4c7;background:#fff}.ssf-deployment-status--success{color:#18724b}.ssf-deployment-status--pending{color:#996800}.ssf-deployment-status--failed{color:#b32d2e}.ssf-release-dashboard-card{border:1px solid #c3c4c7;background:#fff;padding:16px;margin:16px 0;max-width:720px}.ssf-release-dashboard-card h2{margin:0 0 8px}.ssf-release-status--released{color:#18724b}.ssf-release-status--prepared{color:#135e96}.ssf-release-status--development{color:#996800}.ssf-release-status--superseded{color:#646970}@media(max-width:900px){.ssf-release-current{grid-template-columns:repeat(2,minmax(150px,1fr))}}@media(max-width:500px){.ssf-release-current{grid-template-columns:1fr}}
        </style>
        <?php
    }

    public static function save_release(): void
    {
        if (! current_user_can(self::CAPABILITY) || ! check_admin_referer('ssf_save_release')) {
            wp_die('Du saknar behörighet att ändra SSF:s releaser.');
        }
        $version = isset($_POST['version']) ? sanitize_text_field(wp_unslash($_POST['version'])) : '';
        $notes = isset($_POST['notes']) ? sanitize_textarea_field(wp_unslash($_POST['notes'])) : '';
        $result = self::prepare_release('', $version, $notes);
        if (is_wp_error($result)) {
            self::redirect_with_error($result->get_error_code());
        }
        wp_safe_redirect(add_query_arg(array('page' => 'ssf-release', 'ssf_release_updated' => '1'), admin_url('admin.php')));
        exit;
    }

    public static function register_build_from_admin(): void
    {
        if (! current_user_can(self::CAPABILITY) || ! check_admin_referer('ssf_register_release_build')) {
            wp_die('Du saknar behörighet att registrera builds.');
        }
        $description = isset($_POST['description']) ? sanitize_text_field(wp_unslash($_POST['description'])) : '';
        $result = self::create_build(array('source' => 'manual', 'description' => $description, 'components' => array('ssf-release-controls')));
        if (is_wp_error($result)) {
            self::redirect_with_error($result->get_error_code());
        }
        wp_safe_redirect(add_query_arg(array('page' => 'ssf-release', 'ssf_release_updated' => '1'), admin_url('admin.php')));
        exit;
    }

    public static function get_history(): array
    {
        $posts = get_posts(array(
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'numberposts' => 50,
            'orderby' => 'date',
            'order' => 'DESC',
            'suppress_filters' => true,
        ));
        return array_map(static fn(WP_Post $post): array => self::release_from_post($post), $posts);
    }

    private static function find_current_release(string $environment): ?array
    {
        $current_statuses = 'production' === $environment ? array('released') : self::CURRENT_STATUSES;
        foreach ($current_statuses as $status) {
            $posts = get_posts(array(
                'post_type' => self::POST_TYPE,
                'post_status' => 'publish',
                'numberposts' => 1,
                'meta_query' => array(
                    array('key' => '_ssf_release_environment', 'value' => $environment),
                    array('key' => '_ssf_release_status', 'value' => $status),
                ),
                'meta_key' => '_ssf_release_date',
                'orderby' => array('meta_value' => 'DESC', 'date' => 'DESC'),
                'suppress_filters' => true,
            ));
            if ($posts) {
                return self::release_from_post($posts[0]);
            }
        }
        return null;
    }

    private static function release_from_post(WP_Post $post): array
    {
        return array(
            'id' => (int) $post->ID,
            'version' => (string) get_post_meta($post->ID, '_ssf_release_version', true),
            'release_name' => (string) (get_post_meta($post->ID, '_ssf_release_name', true) ?: 'SSF Web'),
            'release_date' => (string) get_post_meta($post->ID, '_ssf_release_date', true),
            'environment' => (string) get_post_meta($post->ID, '_ssf_release_environment', true),
            'status' => (string) get_post_meta($post->ID, '_ssf_release_status', true),
            'build' => (string) get_post_meta($post->ID, '_ssf_release_build', true),
            'built_at' => (string) get_post_meta($post->ID, '_ssf_release_built_at', true),
            'source' => (string) get_post_meta($post->ID, '_ssf_release_source', true),
            'source_commit' => (string) get_post_meta($post->ID, '_ssf_release_source_commit', true),
            'components' => (array) get_post_meta($post->ID, '_ssf_release_components', true),
            'notes' => (string) get_post_meta($post->ID, '_ssf_release_notes', true),
            'created_by' => (int) $post->post_author,
            'created_at' => (string) $post->post_date_gmt,
        );
    }

    private static function release_title(array $release): string
    {
        $name = trim((string) ($release['release_name'] ?? 'SSF Web'));
        $version = trim((string) ($release['version'] ?? ''));
        if ('' === $name) {
            $name = 'SSF Web';
        }
        if ('' === $version || false !== strpos($name, $version)) {
            return $name;
        }
        return $name . ' ' . $version;
    }

    private static function save_release_meta(int $post_id, array $release): void
    {
        update_post_meta($post_id, '_ssf_release_version', (string) ($release['version'] ?? ''));
        update_post_meta($post_id, '_ssf_release_name', (string) ($release['release_name'] ?? 'SSF Web'));
        update_post_meta($post_id, '_ssf_release_date', (string) ($release['release_date'] ?? ''));
        update_post_meta($post_id, '_ssf_release_environment', (string) ($release['environment'] ?? self::get_environment()));
        update_post_meta($post_id, '_ssf_release_status', (string) ($release['status'] ?? 'draft'));
        update_post_meta($post_id, '_ssf_release_build', (string) ($release['build'] ?? ''));
        update_post_meta($post_id, '_ssf_release_built_at', (string) ($release['built_at'] ?? ''));
        update_post_meta($post_id, '_ssf_release_source', (string) ($release['source'] ?? 'legacy'));
        update_post_meta($post_id, '_ssf_release_source_commit', (string) ($release['source_commit'] ?? $release['source_revision'] ?? ''));
        update_post_meta($post_id, '_ssf_release_components', (array) ($release['components'] ?? array()));
        update_post_meta($post_id, '_ssf_release_notes', (string) ($release['notes'] ?? ''));
    }

    private static function supersede_previous_releases(int $current_id, string $environment): void
    {
        $posts = get_posts(array(
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields' => 'ids',
            'meta_query' => array(
                array('key' => '_ssf_release_environment', 'value' => $environment),
                array('key' => '_ssf_release_status', 'value' => 'released'),
            ),
            'suppress_filters' => true,
        ));
        foreach ($posts as $post_id) {
            if ((int) $post_id !== $current_id) {
                update_post_meta((int) $post_id, '_ssf_release_status', 'superseded');
            }
        }
    }

    private static function legacy_release(string $environment): ?array
    {
        if ('production' === $environment) {
            return null;
        }

        $version = SSF_Environment::configured_value('SSF_RELEASE_VERSION');
        if ('' === $version || ! self::validate_version($version)) {
            return null;
        }
        return array(
            'id' => 0,
            'version' => $version,
            'release_name' => 'SSF Web',
            'release_date' => SSF_Environment::configured_value('SSF_RELEASE_DATE'),
            'environment' => $environment,
            'status' => 'production' === $environment ? 'released' : 'development',
            'build' => '',
            'built_at' => '',
            'source' => 'legacy',
            'source_commit' => SSF_Environment::configured_value('SSF_RELEASE_COMMIT'),
            'components' => array(),
            'notes' => '',
            'created_by' => 0,
            'created_at' => '',
        );
    }

    private static function empty_release(string $environment): array
    {
        return array(
            'id' => 0,
            'version' => '',
            'release_name' => 'SSF Web',
            'release_date' => '',
            'environment' => $environment,
            'status' => '',
            'build' => '',
            'built_at' => '',
            'source' => '',
            'source_commit' => '',
            'components' => array(),
            'deployment_status' => '',
            'deployed_at' => '',
            'notes' => '',
            'created_by' => 0,
            'created_at' => '',
        );
    }

    private static function render_history_table(array $history): void
    {
        if (! $history) {
            echo '<p>Inga releaser har registrerats ännu.</p>';
            return;
        }
        ?>
        <table class="widefat striped"><thead><tr><th>Version</th><th>Build</th><th>Byggd</th><th>Releasedatum</th><th>Miljö</th><th>Status</th><th>Revision</th><th>Anteckningar</th></tr></thead><tbody>
        <?php foreach ($history as $release) : ?><tr><td><strong><?php echo esc_html((string) ($release['version'] ?: 'Legacy')); ?></strong></td><td><?php echo esc_html((string) ($release['build'] ?: 'Ej registrerad')); ?></td><td><?php echo esc_html(self::format_timestamp((string) $release['built_at'])); ?></td><td><?php echo esc_html((string) ($release['release_date'] ?: 'Ej registrerat')); ?></td><td><?php echo esc_html(self::environment_label((string) $release['environment'])); ?></td><td class="ssf-release-status--<?php echo esc_attr((string) $release['status']); ?>"><?php echo esc_html(self::status_label((string) $release['status'])); ?></td><td><code><?php echo esc_html((string) $release['source_commit']); ?></code></td><td><?php echo esc_html(wp_trim_words((string) $release['notes'], 18)); ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <?php
    }

    private static function render_deployment_history(): void
    {
        $deployments = self::get_deployments();
        if (! $deployments) {
            echo '<p>Inga deployments har registrerats ännu.</p>';
            return;
        }
        ?>
        <div style="overflow-x:auto"><table class="widefat striped"><thead><tr><th>Tid</th><th>Version</th><th>Build</th><th>Miljö</th><th>Status</th><th>Utförd av</th><th>Föregående build</th><th>Information</th></tr></thead><tbody>
        <?php foreach ($deployments as $deployment) : ?><tr><td><?php echo esc_html(self::format_timestamp((string) ($deployment['deployed_at'] ?? ''))); ?></td><td><?php echo esc_html((string) ($deployment['version'] ?? '')); ?></td><td><strong><?php echo esc_html((string) ($deployment['build'] ?? '')); ?></strong></td><td><?php echo esc_html(self::environment_label((string) ($deployment['environment'] ?? ''))); ?></td><td class="ssf-deployment-status--<?php echo esc_attr((string) ($deployment['status'] ?? '')); ?>"><?php echo esc_html(self::deployment_status_label((string) ($deployment['status'] ?? ''))); ?></td><td><?php echo esc_html((string) ($deployment['deployed_by'] ?? '')); ?></td><td><?php echo esc_html((string) ($deployment['previous_build'] ?? '')); ?></td><td><?php echo esc_html((string) ($deployment['reason'] ?? '')); ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php
    }

    private static function render_audit_log(): void
    {
        $entries = (array) get_option(self::AUDIT_OPTION, array());
        if (! $entries) {
            echo '<p>Inga releaseändringar har registrerats ännu.</p>';
            return;
        }
        ?>
        <table class="widefat striped"><thead><tr><th>Tid</th><th>Händelse</th><th>Miljö</th><th>Användare</th></tr></thead><tbody>
        <?php foreach (array_slice($entries, 0, 25) as $entry) : ?><tr><td><?php echo esc_html((string) ($entry['timestamp'] ?? '')); ?></td><td><?php echo esc_html((string) ($entry['message'] ?? '')); ?></td><td><?php echo esc_html(self::environment_label((string) ($entry['environment'] ?? ''))); ?></td><td><?php echo esc_html((string) ($entry['user'] ?? '')); ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <?php
    }

    private static function add_audit_entry(string $message, string $environment, string $event = 'release_changed'): void
    {
        $user = wp_get_current_user();
        $entries = (array) get_option(self::AUDIT_OPTION, array());
        array_unshift($entries, array(
            'timestamp' => current_time('Y-m-d H:i:s', true),
            'event' => sanitize_key($event),
            'message' => $message,
            'environment' => $environment,
            'user_id' => get_current_user_id(),
            'user' => $user && $user->exists() ? $user->user_login : (defined('WP_CLI') && WP_CLI ? 'wp-cli' : 'system'),
        ));
        update_option(self::AUDIT_OPTION, array_slice($entries, 0, 100), false);
    }

    public static function verify_deployment_from_admin(): void
    {
        if (! current_user_can(self::CAPABILITY) || ! check_admin_referer('ssf_verify_release_deployment')) {
            wp_die('Du saknar behörighet att verifiera deployment.');
        }
        $expected = isset($_POST['expected_build']) ? sanitize_text_field(wp_unslash($_POST['expected_build'])) : '';
        $result = self::verify_deployment($expected);
        wp_safe_redirect(add_query_arg(array(
            'page' => 'ssf-release',
            'ssf_release_deployment' => 'success' === $result['status'] ? 'success' : 'failed',
        ), admin_url('admin.php')));
        exit;
    }

    public static function fail_deployment_from_admin(): void
    {
        if (! current_user_can(self::CAPABILITY) || ! check_admin_referer('ssf_fail_release_deployment')) {
            wp_die('Du saknar behörighet att registrera en misslyckad deployment.');
        }
        $expected = isset($_POST['expected_build']) ? sanitize_text_field(wp_unslash($_POST['expected_build'])) : '';
        $reason = isset($_POST['reason']) ? sanitize_text_field(wp_unslash($_POST['reason'])) : 'Deployment avbruten eller smoke test misslyckades.';
        self::record_failed_deployment($expected, $reason);
        wp_safe_redirect(add_query_arg(array('page' => 'ssf-release', 'ssf_release_deployment' => 'failed'), admin_url('admin.php')));
        exit;
    }

    private static function normalize_manifest(array $manifest): ?array
    {
        $build = sanitize_text_field((string) ($manifest['build'] ?? ''));
        $version = trim(sanitize_text_field((string) ($manifest['version'] ?? '')));
        $built_at = sanitize_text_field((string) ($manifest['built_at'] ?? ''));
        $status = sanitize_key((string) ($manifest['status'] ?? 'development'));
        if (! preg_match('/^\d{8}\.\d+$/', $build)) {
            return null;
        }
        if ('' !== $version && ! self::validate_version($version)) {
            return null;
        }
        if (! $built_at || false === strtotime($built_at)) {
            return null;
        }
        if (! in_array($status, array('development', 'prepared'), true)) {
            return null;
        }
        return array(
            'schema_version' => 1,
            'release_name' => sanitize_text_field((string) ($manifest['release_name'] ?? 'SSF Web')) ?: 'SSF Web',
            'version' => $version,
            'build' => $build,
            'built_at' => gmdate('c', (int) strtotime($built_at)),
            'source' => sanitize_key((string) ($manifest['source'] ?? 'manual')) ?: 'manual',
            'source_revision' => sanitize_text_field((string) ($manifest['source_revision'] ?? '')),
            'description' => sanitize_text_field((string) ($manifest['description'] ?? '')),
            'notes' => sanitize_textarea_field((string) ($manifest['notes'] ?? '')),
            'components' => self::sanitize_components((array) ($manifest['components'] ?? array())),
            'status' => $status,
            'prepared_at' => ! empty($manifest['prepared_at']) && false !== strtotime((string) $manifest['prepared_at']) ? gmdate('c', (int) strtotime((string) $manifest['prepared_at'])) : '',
        );
    }

    private static function sanitize_components(array $components): array
    {
        $components = array_map(static function ($component): string {
            return sanitize_key((string) $component);
        }, $components);
        return array_values(array_unique(array_filter($components)));
    }

    private static function build_lock()
    {
        $path = trailingslashit(sys_get_temp_dir()) . 'ssf-release-build-' . md5((string) ABSPATH) . '.lock';
        $handle = @fopen($path, 'c');
        if (! $handle || ! flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            return false;
        }
        return $handle;
    }

    private static function release_build_lock($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function find_release_id_by_build(string $build, string $environment): int
    {
        if (! $build) {
            return 0;
        }
        $posts = get_posts(array(
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'numberposts' => 1,
            'fields' => 'ids',
            'meta_query' => array(
                array('key' => '_ssf_release_build', 'value' => $build),
                array('key' => '_ssf_release_environment', 'value' => $environment),
            ),
            'suppress_filters' => true,
        ));
        return $posts ? (int) $posts[0] : 0;
    }

    private static function record_deployment(string $status, string $expected_build, string $detected_build, string $reason, string $source): array
    {
        $environment = self::get_environment();
        $history = (array) get_option(self::DEPLOYMENTS_OPTION, array());
        $current = self::get_current_deployment();
        $manifest = self::get_manifest();
        $user = wp_get_current_user();
        $previous_version = (string) ($current['version'] ?? '');
        $previous_build = (string) ($current['build'] ?? '');
        if ('pending' === ($current['status'] ?? '') && $detected_build === ($current['build'] ?? '')) {
            $previous_version = (string) ($current['previous_version'] ?? '');
            $previous_build = (string) ($current['previous_build'] ?? '');
        }
        $entry = array(
            'id' => wp_generate_uuid4(),
            'version' => (string) ($manifest['version'] ?? ''),
            'build' => $detected_build,
            'expected_build' => $expected_build,
            'detected_build' => $detected_build,
            'environment' => $environment,
            'deployed_at' => gmdate('c'),
            'deployed_by' => $user && $user->exists() ? $user->user_login : (defined('WP_CLI') && WP_CLI ? 'wp-cli' : 'system'),
            'source' => sanitize_key($source),
            'status' => in_array($status, array('pending', 'success', 'failed'), true) ? $status : 'failed',
            'reason' => sanitize_text_field($reason),
            'previous_version' => $previous_version,
            'previous_build' => $previous_build,
        );
        array_unshift($history, $entry);
        update_option(self::DEPLOYMENTS_OPTION, array_slice($history, 0, 100), false);
        update_option(self::CURRENT_DEPLOYMENT_OPTION, $entry, false);
        self::add_audit_entry(sprintf('Deployment %s: expected %s, detected %s', strtoupper($entry['status']), $expected_build ?: 'none', $detected_build ?: 'none'), $environment, 'deployment_' . $entry['status']);
        self::clear_cache();
        return $entry;
    }

    private static function clear_cache(): void
    {
        self::$current_release = null;
        foreach (array('production', 'development', 'staging', 'local') as $environment) {
            wp_cache_delete('current_' . $environment, self::CACHE_GROUP);
        }
    }

    private static function valid_date(string $date): bool
    {
        $parsed = date_create_from_format('Y-m-d', $date);
        return $parsed && $parsed->format('Y-m-d') === $date;
    }

    private static function format_timestamp(string $timestamp): string
    {
        $unix = $timestamp ? strtotime($timestamp) : false;
        return false === $unix ? 'Ej registrerat' : wp_date('Y-m-d H:i', $unix, wp_timezone());
    }

    private static function error_message(string $error): string
    {
        $messages = array(
            'version' => 'Versionen måste följa Semantic Versioning, till exempel 1.0.0 eller 1.1.0-dev.3.',
            'status' => 'Ogiltig release-status.',
            'date' => 'Releasedatum måste ha formatet YYYY-MM-DD.',
            'confirmation' => 'Bekräfta innan en production release publiceras.',
            'save' => 'Release kunde inte sparas.',
            'ssf_release_missing_manifest' => 'Registrera en DEV-build innan releasen förbereds.',
            'ssf_release_missing_base_version' => 'Ange ett explicit versionsnummer när den första releasen förbereds.',
            'ssf_release_invalid_version' => 'Versionen måste vara ett stabilt Semantic Versioning-nummer, till exempel 1.0.0.',
            'ssf_release_manifest_write' => 'Release-manifestet kunde inte skrivas. Kontrollera filrättigheterna.',
            'ssf_release_manifest_encode' => 'Release-manifestet kunde inte skapas.',
            'ssf_release_invalid_manifest' => 'Release-manifestet innehåller ogiltiga värden.',
            'ssf_release_production_build' => 'Nya builds får inte skapas i production.',
            'ssf_release_prepare_production' => 'Releasen ska förberedas i DEV och därefter flyttas oförändrad till production.',
            'ssf_release_build_locked' => 'En annan buildregistrering pågår. Försök igen.',
        );
        return $messages[$error] ?? 'Release kunde inte sparas.';
    }

    private static function redirect_with_error(string $error): void
    {
        wp_safe_redirect(add_query_arg(array('page' => 'ssf-release', 'ssf_release_error' => $error), admin_url('admin.php')));
        exit;
    }

    private static function environment_label(string $environment): string
    {
        $labels = array(
            'production' => 'Production',
            'development' => 'Development',
            'staging' => 'Staging',
            'local' => 'Local',
        );
        return $labels[$environment] ?? ucfirst($environment);
    }
}

if (defined('WP_CLI') && WP_CLI) {
    final class SSF_Release_CLI_Command extends WP_CLI_Command
    {
        /** Shows the installed artifact and local deployment. */
        public function status(array $args, array $assoc_args): void
        {
            $release = SSF_Release_Manager::get_current_release();
            $deployment = SSF_Release_Manager::get_current_deployment();
            WP_CLI\Utils\format_items('table', array(array(
                'environment' => SSF_Release_Manager::get_environment(),
                'version' => (string) ($release['version'] ?: 'not-prepared'),
                'build' => (string) ($release['build'] ?: 'not-registered'),
                'built_at' => (string) ($release['built_at'] ?? ''),
                'deployment' => (string) ($deployment['status'] ?? 'not-registered'),
                'deployed_at' => (string) ($deployment['deployed_at'] ?? ''),
                'source_revision' => (string) ($release['source_commit'] ?? ''),
            )), array('environment', 'version', 'build', 'built_at', 'deployment', 'deployed_at', 'source_revision'));
        }

        /** Registers a unique build in a non-production environment. */
        public function build(array $args, array $assoc_args): void
        {
            $result = SSF_Release_Manager::create_build(array(
                'version' => (string) ($assoc_args['version'] ?? ''),
                'source' => (string) ($assoc_args['source'] ?? 'codex'),
                'source_revision' => (string) ($assoc_args['revision'] ?? ''),
                'description' => (string) ($assoc_args['description'] ?? ''),
                'components' => isset($assoc_args['components']) ? explode(',', (string) $assoc_args['components']) : array(),
            ));
            if (is_wp_error($result)) {
                WP_CLI::error($result->get_error_message());
            }
            WP_CLI::success(sprintf('Build %s registrerad i %s.', $result['build'], SSF_Release_Manager::get_environment()));
        }

        /** Prepares the current build as a patch, minor, major, or explicit version. */
        public function prepare(array $args, array $assoc_args): void
        {
            $level = '';
            foreach (array('patch', 'minor', 'major') as $candidate) {
                if (isset($assoc_args[$candidate])) {
                    $level = $candidate;
                }
            }
            $result = SSF_Release_Manager::prepare_release($level, (string) ($assoc_args['version'] ?? ''), (string) ($assoc_args['notes'] ?? ''));
            if (is_wp_error($result)) {
                WP_CLI::error($result->get_error_message());
            }
            WP_CLI::success(sprintf('Release %s förberedd med oförändrad build %s.', $result['version'], $result['build']));
        }

        /** Registers that the artifact has been deployed and is awaiting verification. */
        public function deploy(array $args, array $assoc_args): void
        {
            $result = SSF_Release_Manager::begin_deployment((string) ($assoc_args['expected-build'] ?? ''));
            if (is_wp_error($result)) {
                WP_CLI::error($result->get_error_message());
            }
            WP_CLI::success(sprintf('Deployment av build %s registrerad som pending.', $result['build']));
        }

        /** Verifies the installed manifest against the expected build. */
        public function verify(array $args, array $assoc_args): void
        {
            $result = SSF_Release_Manager::verify_deployment((string) ($assoc_args['expected-build'] ?? ''));
            if ('success' !== $result['status']) {
                WP_CLI::error(sprintf('Verifiering misslyckades. Expected: %s. Detected: %s.', $result['expected_build'], $result['detected_build']));
            }
            WP_CLI::success(sprintf('Build %s verifierad i %s.', $result['build'], $result['environment']));
        }
    }

    WP_CLI::add_command('ssf release', 'SSF_Release_CLI_Command');
}

final class SSF_Release_Info
{
    public static function get_version(): string
    {
        return SSF_Release_Manager::get_version();
    }

    public static function get_environment(): string
    {
        return SSF_Release_Manager::get_environment();
    }

    public static function get_release_date(): string
    {
        return SSF_Release_Manager::get_release_date();
    }

    public static function get_commit(): string
    {
        return SSF_Release_Manager::get_commit();
    }

    public static function get_build(): string
    {
        return SSF_Release_Manager::get_build();
    }

    public static function get_built_at(): string
    {
        return SSF_Release_Manager::get_built_at();
    }

    public static function get_deployed_at(): string
    {
        return SSF_Release_Manager::get_deployed_at();
    }

    public static function is_configured(): bool
    {
        return SSF_Release_Manager::is_configured();
    }

    public static function get_display_string(): string
    {
        return SSF_Release_Manager::get_display_string();
    }
}

/**
 * The single source of truth for public SSF functionality.
 */
final class SSF_Feature_Manager
{
    public const OPTION = 'ssf_feature_settings';
    public const AUDIT_OPTION = 'ssf_feature_audit_log';
    public const CAPABILITY = 'manage_ssf_features';

    private const STATES = array('off', 'admin', 'public');

    private const REGISTRY = array(
        'applications' => array(
            'label' => 'Ansökan',
            'description' => 'Medlems- och fartygsansökan.',
            'default' => 'off',
            'sensitive' => true,
            'unavailable' => 'Den digitala ansökan är inte tillgänglig just nu. Kontakta SSF om du har frågor om medlemskap.',
        ),
        'annual_meetings' => array(
            'label' => 'Årsmöten',
            'description' => 'Information och program för SSF:s årsmöten.',
            'default' => 'off',
            'sensitive' => false,
            'unavailable' => 'Årsmöten är inte tillgängliga just nu. Information om nästa årsmöte publiceras här.',
        ),
        'annual_meeting_registration' => array(
            'label' => 'Middag och aktiviteter vid årsmötet',
            'description' => 'Anmälan till middag och valfria aktiviteter. Själva årsmötet kräver ingen anmälan.',
            'default' => 'off',
            'sensitive' => true,
            'unavailable' => 'Anmälan till middag och aktiviteter är inte öppen just nu.',
        ),
        'motions' => array(
            'label' => 'Motioner',
            'description' => 'Inlämning och uppföljning av motioner.',
            'default' => 'off',
            'sensitive' => true,
            'unavailable' => 'Motionstiden är inte öppen just nu. Information om nästa motionsperiod publiceras inför kommande årsmöte.',
        ),
        'calendar' => array(
            'label' => 'Kalender',
            'description' => 'Kalender med SSF:s aktiviteter och evenemang.',
            'default' => 'public',
            'sensitive' => false,
            'unavailable' => 'Kalendern är inte tillgänglig just nu.',
        ),
        'promotions' => array(
            'label' => 'Aktuellt',
            'description' => 'Tidsstyrda och prioriterade budskap på startsidan och andra valda platser.',
            'default' => 'admin',
            'sensitive' => false,
            'unavailable' => 'Aktuella budskap är inte tillgängliga just nu.',
        ),
        'newsletters' => array(
            'label' => 'Nyhetsbrev',
            'description' => 'Arkiv för nyhetsbrev och äldre nummer av Fördevind.',
            'default' => 'public',
            'sensitive' => false,
            'unavailable' => 'Nyhetsbrevsarkivet är inte tillgängligt just nu.',
        ),
        'member_vessels' => array(
            'label' => 'Medlemsfartyg',
            'description' => 'Den publika presentationen av SSF:s medlemsfartyg.',
            'default' => 'public',
            'sensitive' => false,
            'unavailable' => 'Medlemsfartygen är inte tillgängliga just nu.',
        ),
    );

    public static function boot(): void
    {
        add_action('init', array(__CLASS__, 'ensure_capability'), 5);
        add_action('admin_menu', array(__CLASS__, 'register_admin_page'), 60);
        add_action('admin_post_ssf_save_feature_settings', array(__CLASS__, 'save_settings'));
        add_action('wp_dashboard_setup', array(__CLASS__, 'register_dashboard_widget'));
        add_filter('wp_nav_menu_objects', array(__CLASS__, 'filter_menu_items'), 20, 2);
        add_action('template_redirect', array(__CLASS__, 'guard_public_route'));
        add_filter('the_content', array(__CLASS__, 'filter_public_content'), 1);
        add_action('wp_head', array(__CLASS__, 'frontend_styles'));
    }

    public static function ensure_capability(): void
    {
        $administrator = get_role('administrator');
        if ($administrator && ! $administrator->has_cap(self::CAPABILITY)) {
            $administrator->add_cap(self::CAPABILITY);
        }
    }

    public static function get_registry(): array
    {
        return self::REGISTRY;
    }

    public static function get_all_features(): array
    {
        $features = array();
        foreach (self::REGISTRY as $feature => $definition) {
            $features[$feature] = array_merge($definition, array(
                'key' => $feature,
                'state' => self::get_state($feature),
                'source' => self::get_source($feature),
                'override' => self::get_override($feature),
            ));
        }
        return $features;
    }

    public static function get_state(string $feature): string
    {
        if (! self::has_feature($feature)) {
            return 'off';
        }

        $override = self::get_override($feature);
        if (null !== $override) {
            return $override;
        }

        $settings = self::stored_settings();
        if (isset($settings[$feature])) {
            return $settings[$feature];
        }

        $legacy = self::legacy_state($feature);
        if (null !== $legacy) {
            return $legacy;
        }

        return self::REGISTRY[$feature]['default'];
    }

    public static function is_enabled(string $feature): bool
    {
        return 'off' !== self::get_state($feature);
    }

    public static function is_public(string $feature): bool
    {
        return 'public' === self::get_state($feature);
    }

    public static function can_access(string $feature, ?int $user_id = null): bool
    {
        $state = self::get_state($feature);
        if ('public' === $state) {
            return true;
        }
        if ('admin' !== $state) {
            return false;
        }

        if (null === $user_id) {
            return current_user_can(self::CAPABILITY);
        }

        return user_can($user_id, self::CAPABILITY);
    }

    public static function get_override(string $feature): ?string
    {
        if (! self::has_feature($feature)) {
            return null;
        }

        $value = SSF_Environment::configured_value(self::override_constant($feature));
        return self::valid_state($value) ? $value : null;
    }

    public static function get_source(string $feature): string
    {
        if (null !== self::get_override($feature)) {
            return 'wp-config.php';
        }
        if (array_key_exists($feature, self::stored_settings())) {
            return 'WordPress admin';
        }
        if (null !== self::legacy_state($feature)) {
            return 'Äldre wp-config.php';
        }
        return 'Standardvärde';
    }

    public static function state_label(string $state): string
    {
        $labels = array(
            'off' => 'Av',
            'admin' => 'Endast administratörer',
            'public' => 'Publik',
        );
        return $labels[$state] ?? $labels['off'];
    }

    public static function unavailable_markup(string $feature): string
    {
        $definition = self::REGISTRY[$feature] ?? array(
            'label' => 'Funktionen',
            'unavailable' => 'Den här funktionen är inte tillgänglig just nu.',
        );
        return sprintf(
            '<section class="ssf-feature-message ssf-feature-message--unavailable"><h1>%s</h1><p>%s</p></section>',
            esc_html((string) $definition['label']),
            esc_html((string) $definition['unavailable'])
        );
    }

    public static function test_mode_banner(string $feature): string
    {
        if ('admin' !== self::get_state($feature) || ! self::can_access($feature)) {
            return '';
        }
        $label = self::REGISTRY[$feature]['label'] ?? 'Funktionen';
        return sprintf(
            '<aside class="ssf-feature-test-banner" role="status"><strong>TESTLÄGE</strong><span>%s är endast synlig för administratörer.</span></aside>',
            esc_html($label)
        );
    }

    public static function render_dashboard_card(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            return;
        }
        $features = self::get_all_features();
        ?>
        <section class="ssf-feature-dashboard-card">
            <div><h2>Publika funktioner</h2><p>Miljö: <strong><?php echo esc_html(SSF_Environment::label()); ?></strong></p></div>
            <ul><?php foreach ($features as $feature) : ?><li><span><?php echo esc_html($feature['label']); ?></span><strong class="ssf-feature-state ssf-feature-state--<?php echo esc_attr($feature['state']); ?>"><?php echo esc_html(self::state_label($feature['state'])); ?></strong></li><?php endforeach; ?></ul>
            <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ssf-features')); ?>">Hantera funktioner</a></p>
        </section>
        <?php
    }

    public static function register_dashboard_widget(): void
    {
        if (current_user_can(self::CAPABILITY)) {
            wp_add_dashboard_widget('ssf-feature-dashboard', 'SSF: Funktioner', array(__CLASS__, 'render_dashboard_widget'));
        }
    }

    public static function render_dashboard_widget(): void
    {
        self::render_dashboard_card();
    }

    public static function register_admin_page(): void
    {
        add_submenu_page(class_exists('SSF_Admin_Navigation') ? null : 'ssf', 'SSF Funktioner', 'Funktioner', self::CAPABILITY, 'ssf-features', array(__CLASS__, 'render_admin_page'));
    }

    public static function render_admin_page(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die('Du saknar behörighet att ändra SSF:s funktioner.');
        }

        $features = self::get_all_features();
        $error = isset($_GET['ssf_features_error']) ? sanitize_key(wp_unslash($_GET['ssf_features_error'])) : '';
        $updated = isset($_GET['ssf_features_updated']);
        ?>
        <div class="wrap ssf-feature-admin">
            <h1>SSF Funktioner</h1>
            <?php if (class_exists('SSF_Admin_Navigation')) { SSF_Admin_Navigation::render_system_tabs('ssf-features'); } ?>
            <div class="ssf-feature-environment ssf-feature-environment--<?php echo esc_attr(SSF_Environment::get_environment()); ?>">
                <strong>Miljö: <?php echo esc_html(SSF_Environment::label()); ?></strong>
                <?php if (SSF_Environment::is_production()) : ?><p>Du ändrar funktioner på den publika webbplatsen.</p><?php else : ?><p>Inställningarna gäller endast denna WordPress-installation.</p><?php endif; ?>
            </div>
            <?php if ('confirmation' === $error) : ?><div class="notice notice-error"><p>Bekräfta innan en känslig funktion görs publik.</p></div><?php endif; ?>
            <?php if ($updated) : ?><div class="notice notice-success is-dismissible"><p>Funktionsinställningarna har sparats.</p></div><?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="ssf-feature-form">
                <input type="hidden" name="action" value="ssf_save_feature_settings">
                <?php wp_nonce_field('ssf_save_feature_settings'); ?>
                <div class="ssf-feature-list">
                    <?php foreach ($features as $feature) : $locked = null !== $feature['override']; ?>
                        <section class="ssf-feature-row<?php echo $locked ? ' is-locked' : ''; ?>">
                            <div class="ssf-feature-row__copy"><h2><?php echo esc_html($feature['label']); ?></h2><p><?php echo esc_html($feature['description']); ?></p>
                                <p class="description">Aktivt läge: <strong><?php echo esc_html(self::state_label($feature['state'])); ?></strong><?php if ($locked) : ?> <span class="ssf-feature-lock">Styrs av <?php echo esc_html(self::override_constant($feature['key'])); ?></span><?php else : ?> <span>Styrs av <?php echo esc_html($feature['source']); ?></span><?php endif; ?></p>
                            </div>
                            <fieldset class="ssf-feature-radios"><legend class="screen-reader-text">Status för <?php echo esc_html($feature['label']); ?></legend>
                                <?php foreach (self::STATES as $state) : $id = 'ssf-feature-' . $feature['key'] . '-' . $state; ?>
                                    <label for="<?php echo esc_attr($id); ?>"><input id="<?php echo esc_attr($id); ?>" type="radio" name="features[<?php echo esc_attr($feature['key']); ?>]" value="<?php echo esc_attr($state); ?>" data-sensitive="<?php echo ! empty($feature['sensitive']) ? '1' : '0'; ?>" data-feature-label="<?php echo esc_attr($feature['label']); ?>" data-current-state="<?php echo esc_attr($feature['state']); ?>" <?php checked($feature['state'], $state); ?> <?php disabled($locked); ?>> <?php echo esc_html(self::state_label($state)); ?></label>
                                <?php endforeach; ?>
                            </fieldset>
                        </section>
                    <?php endforeach; ?>
                </div>
                <?php submit_button('Spara funktionsinställningar'); ?>
            </form>
            <h2>Senaste ändringar</h2>
            <?php self::render_audit_log(); ?>
        </div>
        <dialog id="ssf-feature-confirm-dialog" aria-labelledby="ssf-feature-confirm-title">
            <form method="dialog"><h2 id="ssf-feature-confirm-title">Gör funktion publik?</h2><p id="ssf-feature-confirm-text"></p><menu><button value="cancel">Avbryt</button><button class="button button-primary" id="ssf-feature-confirm-submit" value="confirm">Gör publik</button></menu></form>
        </dialog>
        <style>
            .ssf-feature-admin{max-width:1100px}.ssf-feature-environment{border-left:4px solid #2271b1;background:#f6f7f7;margin:16px 0;padding:12px 16px}.ssf-feature-environment--production{border-color:#b32d2e;background:#fcf0f1}.ssf-feature-list{border:1px solid #c3c4c7;background:#fff}.ssf-feature-row{display:grid;grid-template-columns:minmax(260px,1fr) minmax(390px,1.25fr);gap:24px;padding:20px;border-bottom:1px solid #dcdcde}.ssf-feature-row:last-child{border-bottom:0}.ssf-feature-row h2{margin:0 0 6px}.ssf-feature-row p{margin:0 0 8px}.ssf-feature-radios{display:flex;align-items:center;gap:16px;margin:0}.ssf-feature-radios label{white-space:nowrap}.ssf-feature-lock{display:inline-block;color:#8a2424;font-weight:600;margin-left:8px}.ssf-feature-dashboard-card{border:1px solid #c3c4c7;background:#fff;padding:16px;margin:16px 0;max-width:720px}.ssf-feature-dashboard-card h2{margin:0}.ssf-feature-dashboard-card ul{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px 24px;padding:0;margin:16px 0;list-style:none}.ssf-feature-dashboard-card li{display:flex;justify-content:space-between;border-bottom:1px solid #f0f0f1;padding-bottom:5px}.ssf-feature-state--public{color:#18724b}.ssf-feature-state--admin{color:#996800}.ssf-feature-state--off{color:#646970}@media(max-width:782px){.ssf-feature-row{grid-template-columns:1fr}.ssf-feature-radios{align-items:flex-start;flex-direction:column;gap:8px}.ssf-feature-dashboard-card ul{grid-template-columns:1fr}}
        </style>
        <script>
        (function(){var form=document.getElementById('ssf-feature-form'),dialog=document.getElementById('ssf-feature-confirm-dialog'),text=document.getElementById('ssf-feature-confirm-text'),confirmButton=document.getElementById('ssf-feature-confirm-submit');if(!form||!dialog||!confirmButton){return;}var pending=[];function addConfirmations(){form.querySelectorAll('input[data-ssf-feature-confirmation]').forEach(function(input){input.remove();});pending.forEach(function(input){var match=input.name.match(/\[([^\]]+)\]/);if(!match){return;}var confirmation=document.createElement('input');confirmation.type='hidden';confirmation.name='confirm_public['+match[1]+']';confirmation.value='1';confirmation.dataset.ssfFeatureConfirmation='1';form.appendChild(confirmation);});}function submitConfirmed(){addConfirmations();form.dataset.confirmed='1';if(typeof form.requestSubmit==='function'){form.requestSubmit();return;}HTMLFormElement.prototype.submit.call(form);}form.addEventListener('submit',function(event){if(form.dataset.confirmed==='1'){return;}pending=[];form.querySelectorAll('input[type="radio"][value="public"]:checked').forEach(function(input){if(input.dataset.sensitive==='1'&&input.dataset.currentState!=='public'){pending.push(input);}});if(!pending.length){return;}event.preventDefault();var labels=pending.map(function(input){return input.dataset.featureLabel;});var message='Du är på väg att göra '+labels.join(', ')+' publik'+(labels.length>1?'a':'')+' på '+window.location.hostname+'.';text.textContent=message;if(typeof dialog.showModal==='function'){dialog.showModal();return;}if(window.confirm(message)){submitConfirmed();}});confirmButton.addEventListener('click',function(event){event.preventDefault();dialog.close();submitConfirmed();});})();
        </script>
        <?php
    }

    public static function save_settings(): void
    {
        if (! current_user_can(self::CAPABILITY) || ! check_admin_referer('ssf_save_feature_settings')) {
            wp_die('Du saknar behörighet att ändra SSF:s funktioner.');
        }

        $incoming = isset($_POST['features']) && is_array($_POST['features']) ? wp_unslash($_POST['features']) : array();
        $confirmations = isset($_POST['confirm_public']) && is_array($_POST['confirm_public']) ? wp_unslash($_POST['confirm_public']) : array();
        $settings = self::stored_settings();
        $changes = array();

        foreach (self::REGISTRY as $feature => $definition) {
            if (null !== self::get_override($feature) || ! isset($incoming[$feature])) {
                continue;
            }
            $new_state = sanitize_key((string) $incoming[$feature]);
            if (! self::valid_state($new_state)) {
                continue;
            }
            $old_state = self::get_state($feature);
            if (! empty($definition['sensitive']) && 'public' === $new_state && 'public' !== $old_state && empty($confirmations[$feature])) {
                wp_safe_redirect(add_query_arg(array('page' => 'ssf-features', 'ssf_features_error' => 'confirmation'), admin_url('admin.php')));
                exit;
            }
            $settings[$feature] = $new_state;
            if ($old_state !== $new_state) {
                $changes[] = array('feature' => $feature, 'old' => $old_state, 'new' => $new_state);
            }
        }

        update_option(self::OPTION, $settings, false);
        wp_cache_delete(self::OPTION, 'options');
        do_action('ssf_features_updated', $changes, $settings);

        foreach ($changes as $change) {
            self::add_audit_entry($change['feature'], $change['old'], $change['new']);
        }

        wp_safe_redirect(add_query_arg(array('page' => 'ssf-features', 'ssf_features_updated' => '1'), admin_url('admin.php')));
        exit;
    }

    public static function filter_menu_items(array $items, $args): array
    {
        $filtered = array();
        $has_motions_link = false;
        $member_parent = 0;

        foreach ($items as $item) {
            $path = trim((string) wp_parse_url((string) $item->url, PHP_URL_PATH), '/');
            $feature = self::feature_for_path($path);
            if ($feature && ! self::can_access($feature)) {
                continue;
            }

            if ('motions' === $feature) {
                $has_motions_link = true;
            }
            if ($feature && 'admin' === self::get_state($feature)) {
                $item->title = trim((string) $item->title) . ' (test)';
            }
            if ('for-medlemmar' === sanitize_title($path) || 'for-medlemmar' === sanitize_title((string) $item->title)) {
                $member_parent = (int) $item->ID;
            }
            $filtered[] = $item;
        }

        if (self::can_access('motions') && ! $has_motions_link) {
            $filtered[] = (object) array(
                'ID' => 0,
                'db_id' => 0,
                'menu_item_parent' => $member_parent,
                'object_id' => 0,
                'object' => 'custom',
                'type' => 'custom',
                'type_label' => 'Custom Link',
                'title' => 'admin' === self::get_state('motions') ? 'Motioner (test)' : 'Motioner',
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

    public static function guard_public_route(): void
    {
        $feature = self::current_feature();
        if ($feature && 'admin' === self::get_state($feature)) {
            nocache_headers();
        }
    }

    public static function filter_public_content(string $content): string
    {
        if (! is_main_query() || ! in_the_loop()) {
            return $content;
        }
        $feature = self::current_feature();
        if (! $feature) {
            return $content;
        }
        if (! self::can_access($feature)) {
            return self::unavailable_markup($feature);
        }
        return self::test_mode_banner($feature) . $content;
    }

    public static function frontend_styles(): void
    {
        if (is_admin()) {
            return;
        }
        ?>
        <style id="ssf-feature-manager-styles">
            .ssf-feature-message,.ssf-feature-test-banner{max-width:760px;margin:24px auto;padding:20px 24px;border:1px solid #c8d2de;background:#f7fafc;color:#102f55}.ssf-feature-message h1{margin-top:0}.ssf-feature-message p{margin-bottom:0}.ssf-feature-test-banner{display:flex;gap:12px;border-left:5px solid #b98228;background:#fff8e8;font-size:15px}.ssf-feature-test-banner strong{letter-spacing:0}
        </style>
        <?php
    }

    private static function render_audit_log(): void
    {
        $entries = (array) get_option(self::AUDIT_OPTION, array());
        if (! $entries) {
            echo '<p>Inga ändringar har registrerats ännu.</p>';
            return;
        }
        ?>
        <table class="widefat striped" style="max-width:1100px"><thead><tr><th>Tid</th><th>Funktion</th><th>Från</th><th>Till</th><th>Ändrad av</th><th>Miljö</th><th>Källa</th></tr></thead><tbody>
        <?php foreach (array_slice($entries, 0, 25) as $entry) : ?><tr><td><?php echo esc_html((string) ($entry['timestamp'] ?? '')); ?></td><td><?php echo esc_html((string) ($entry['label'] ?? '')); ?></td><td><?php echo esc_html(self::state_label((string) ($entry['old'] ?? 'off'))); ?></td><td><?php echo esc_html(self::state_label((string) ($entry['new'] ?? 'off'))); ?></td><td><?php echo esc_html((string) ($entry['user'] ?? '')); ?></td><td><?php echo esc_html((string) ($entry['environment'] ?? '')); ?></td><td><?php echo esc_html((string) ($entry['source'] ?? '')); ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <?php
    }

    private static function add_audit_entry(string $feature, string $old_state, string $new_state): void
    {
        $user = wp_get_current_user();
        $entries = (array) get_option(self::AUDIT_OPTION, array());
        array_unshift($entries, array(
            'timestamp' => current_time('Y-m-d H:i:s', true),
            'feature' => $feature,
            'label' => self::REGISTRY[$feature]['label'] ?? $feature,
            'old' => $old_state,
            'new' => $new_state,
            'user_id' => get_current_user_id(),
            'user' => $user && $user->exists() ? $user->user_login : 'okänd',
            'environment' => SSF_Environment::get_environment(),
            'source' => 'WordPress admin',
        ));
        update_option(self::AUDIT_OPTION, array_slice($entries, 0, 100), false);
    }

    private static function current_feature(): ?string
    {
        if (is_singular('ssf_calendar_event')) {
            return 'calendar';
        }
        if (is_singular('ssf_newsletter') || is_post_type_archive('ssf_newsletter')) {
            return 'newsletters';
        }
        if (is_singular('medlemsfartyg') || is_post_type_archive('medlemsfartyg')) {
            return 'member_vessels';
        }
        if (! is_page()) {
            return null;
        }

        $page_id = (int) get_queried_object_id();
        $registration_page_id = (int) get_option('ssf_member_portal_annual_meeting_registration_page_id', 0);
        if ($registration_page_id && $registration_page_id === $page_id) {
            return 'annual_meeting_registration';
        }

        $annual_page_ids = array_filter(array(
            (int) get_option('ssf_member_portal_annual_meeting_page_id', 0),
            (int) get_option('ssf_member_portal_annual_meetings_archive_page_id', 0),
        ));
        if (in_array($page_id, $annual_page_ids, true)) {
            return 'annual_meetings';
        }

        $motion_page_ids = array_filter(array(
            (int) get_option('ssf_member_portal_motion_hub_page_id', 0),
            (int) get_option('ssf_member_portal_motion_form_page_id', 0),
        ));
        if (in_array($page_id, $motion_page_ids, true)) {
            return 'motions';
        }

        $application_page_id = (int) get_option('ssf_medlemsprocess_ansokan_page_id', 0);
        if ($application_page_id && $application_page_id === $page_id) {
            return 'applications';
        }
        $application_status_page_id = (int) get_option('ssf_medlemsprocess_ansokan_status_page_id', 0);
        $inspector_page_id = (int) get_option('ssf_medlemsprocess_mina_inspektioner_page_id', 0);
        if (($application_status_page_id && $application_status_page_id === $page_id) || ($inspector_page_id && $inspector_page_id === $page_id)) {
            return null;
        }

        $path = trim((string) wp_parse_url((string) get_permalink($page_id), PHP_URL_PATH), '/');
        $feature = self::feature_for_path($path);
        return 'member_vessels' === $feature ? null : $feature;
    }

    private static function feature_for_path(string $path): ?string
    {
        $path = trim($path, '/');
        if (false !== strpos($path, 'motioner') || false !== strpos($path, 'lamna-motion')) {
            return 'motions';
        }
        if (false !== strpos($path, 'arsmote') || false !== strpos($path, 'arsmoten')) {
            return false !== strpos($path, 'anmalan') ? 'annual_meeting_registration' : 'annual_meetings';
        }
        if (false !== strpos($path, 'ansokan')) {
            return 'applications';
        }
        if (false !== strpos($path, 'nyhetsbrev')) {
            return 'newsletters';
        }
        if (false !== strpos($path, 'medlemsfartyg')) {
            return 'member_vessels';
        }
        if (false !== strpos($path, 'kalender')) {
            return 'calendar';
        }
        return null;
    }

    private static function stored_settings(): array
    {
        $stored = (array) get_option(self::OPTION, array());
        $settings = array();
        foreach (self::REGISTRY as $feature => $definition) {
            if (isset($stored[$feature]) && self::valid_state((string) $stored[$feature])) {
                $settings[$feature] = (string) $stored[$feature];
            }
        }
        return $settings;
    }

    private static function legacy_state(string $feature): ?string
    {
        $name = 'SSF_FEATURE_' . strtoupper($feature);
        if (defined($name)) {
            $value = constant($name);
            if (is_bool($value)) {
                return $value ? 'public' : 'off';
            }
            if (is_scalar($value) && '' !== trim((string) $value)) {
                return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'public' : 'off';
            }
            return null;
        }

        $value = getenv($name);
        if (false === $value || '' === trim((string) $value)) {
            return null;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'public' : 'off';
    }

    private static function override_constant(string $feature): string
    {
        return 'SSF_FEATURE_' . strtoupper($feature) . '_OVERRIDE';
    }

    private static function has_feature(string $feature): bool
    {
        return isset(self::REGISTRY[$feature]);
    }

    private static function valid_state(string $state): bool
    {
        return in_array($state, self::STATES, true);
    }
}

/**
 * Compatibility facade for existing SSF plugins. New code should use
 * SSF_Feature_Manager directly.
 */
final class SSF_Features
{
    public static function enabled(string $feature): bool
    {
        return SSF_Feature_Manager::can_access($feature);
    }

    public static function all(): array
    {
        $features = array();
        foreach (SSF_Feature_Manager::get_all_features() as $feature => $definition) {
            $features[$feature] = SSF_Feature_Manager::can_access($feature);
        }
        return $features;
    }
}

final class SSF_Release_Controls
{
    public static function boot(): void
    {
        add_action('admin_bar_menu', array(__CLASS__, 'add_environment_node'), 90);
    }

    public static function add_environment_node($admin_bar): void
    {
        if (! current_user_can(SSF_Release_Manager::CAPABILITY)) {
            return;
        }
        $admin_bar->add_node(array(
            'id' => 'ssf-environment',
            'title' => SSF_Release_Manager::get_display_string(),
            'href' => admin_url('admin.php?page=ssf-release'),
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
        $deployment = SSF_Release_Manager::get_current_deployment();
        return array(
            'environment' => array('ok' => true, 'value' => SSF_Release_Manager::get_environment_label()),
            'release' => array('ok' => SSF_Release_Info::is_configured(), 'value' => SSF_Release_Manager::get_display_string()),
            'build' => array('ok' => '' !== SSF_Release_Manager::get_build(), 'value' => SSF_Release_Manager::get_build() ?: 'Ej registrerad'),
            'deployment' => array('ok' => 'success' === ($deployment['status'] ?? ''), 'value' => SSF_Release_Manager::deployment_status_label((string) ($deployment['status'] ?? ''))),
            'release_date' => array('ok' => true, 'value' => SSF_Release_Manager::get_release_date() ?: 'Ej konfigurerat'),
            'release_status' => array('ok' => true, 'value' => SSF_Release_Manager::status_label(SSF_Release_Manager::get_status())),
            'database' => array('ok' => $database_ok, 'value' => $database_ok ? 'Ansluten' : 'Kunde inte verifieras'),
            'plugins' => array('ok' => $portal_active && $applications_active && $calendar_active, 'value' => $portal_active && $applications_active && $calendar_active ? 'Aktiva' : 'Kontrollera SSF-plugins'),
            'motion_page' => array('ok' => 'off' === SSF_Feature_Manager::get_state('motions') || $motion_page_ready, 'value' => $motion_page_ready ? 'Publicerad' : 'Saknas eller ej publicerad'),
            'footer_theme' => array('ok' => $theme_ready, 'value' => $theme_ready ? 'SSF-temat aktivt' : 'Kontrollera aktivt tema'),
        );
    }
}

SSF_Release_Manager::boot();
SSF_Feature_Manager::boot();
SSF_Release_Controls::boot();
