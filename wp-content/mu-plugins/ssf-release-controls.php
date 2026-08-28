<?php
/**
 * Plugin Name: SSF Release Controls
 * Description: Central environment, release, and feature controls for SSF.
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
            'production' => 'PRODUKTION',
            'development' => 'DEVELOPMENT',
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
            'label' => 'Årsmötesanmälan',
            'description' => 'Anmälan till ett publicerat årsmöte.',
            'default' => 'off',
            'sensitive' => true,
            'unavailable' => 'Anmälan till årsmötet är inte öppen just nu.',
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
        add_submenu_page('ssf', 'SSF Funktioner', 'Funktioner', self::CAPABILITY, 'ssf-features', array(__CLASS__, 'render_admin_page'));
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
        (function(){var form=document.getElementById('ssf-feature-form'),dialog=document.getElementById('ssf-feature-confirm-dialog'),text=document.getElementById('ssf-feature-confirm-text'),confirmButton=document.getElementById('ssf-feature-confirm-submit');if(!form||!dialog||!confirmButton){return;}var pending=[];form.addEventListener('submit',function(event){if(form.dataset.confirmed==='1'){return;}pending=[];form.querySelectorAll('input[type="radio"][value="public"]:checked').forEach(function(input){if(input.dataset.sensitive==='1'&&input.dataset.currentState!=='public'){pending.push(input);}});if(!pending.length){return;}event.preventDefault();var labels=pending.map(function(input){return input.dataset.featureLabel;});text.textContent='Du är på väg att göra '+labels.join(', ')+' publik'+(labels.length>1?'a':'')+' på '+window.location.hostname+'.';dialog.showModal();});confirmButton.addEventListener('click',function(){pending.forEach(function(input){var confirmation=document.createElement('input');confirmation.type='hidden';confirmation.name='confirm_public['+input.name.match(/\[(.+)\]/)[1]+']';confirmation.value='1';form.appendChild(confirmation);});form.dataset.confirmed='1';form.submit();});})();
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
        if (! current_user_can(SSF_Feature_Manager::CAPABILITY)) {
            return;
        }
        $admin_bar->add_node(array(
            'id' => 'ssf-environment',
            'title' => 'SSF ' . SSF_Environment::label() . ' - ' . SSF_Release_Info::get_version(),
            'href' => admin_url('admin.php?page=ssf-features'),
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
            'motion_page' => array('ok' => 'off' === SSF_Feature_Manager::get_state('motions') || $motion_page_ready, 'value' => $motion_page_ready ? 'Publicerad' : 'Saknas eller ej publicerad'),
            'footer_theme' => array('ok' => $theme_ready, 'value' => $theme_ready ? 'SSF-temat aktivt' : 'Kontrollera aktivt tema'),
        );
    }
}

SSF_Feature_Manager::boot();
SSF_Release_Controls::boot();
