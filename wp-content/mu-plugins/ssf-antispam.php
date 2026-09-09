<?php
/**
 * Plugin Name: SSF Antispam
 * Description: Centralt Turnstile-, honeypot- och rate limit-skydd för SSF:s egna formulär.
 * Version: 1.0.0
 * Author: SIDM
 */

if (! defined('ABSPATH')) {
    exit;
}

final class SSF_Antispam
{
    private const SETTINGS_OPTION = 'ssf_antispam_settings';
    private const EVENTS_OPTION = 'ssf_antispam_events';
    private const TURNSTILE_ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    private const TEST_SITE_KEYS = array('1x00000000000000000000AA', '2x00000000000000000000AB', '1x00000000000000000000BB', '2x00000000000000000000BB', '3x00000000000000000000FF');
    private const TEST_SECRET_KEYS = array('1x0000000000000000000000000000000AA', '2x0000000000000000000000000000000AA', '3x0000000000000000000000000000000AA');

    private static $last_reason = '';
    private static $styles_added = false;

    public static function boot(): void
    {
        add_action('admin_menu', array(__CLASS__, 'register_admin_page'), 40);
        add_action('admin_post_ssf_antispam_save', array(__CLASS__, 'save_settings'));
        add_action('admin_post_ssf_antispam_test', array(__CLASS__, 'test_turnstile'));
        add_action('admin_post_ssf_antispam_enable_dev_test', array(__CLASS__, 'enable_dev_test_mode'));
    }

    public static function forms(): array
    {
        return array(
            'contact' => array('label' => 'Kontaktformulär', 'default' => true, 'limit' => 8, 'source' => 'SSF Site Customizations'),
            'legacy_vessel_application' => array('label' => 'Äldre fartygsansökan', 'default' => true, 'limit' => 5, 'source' => 'SSF Site Customizations'),
            'membership_application' => array('label' => 'Medlemsansökan för fartyg', 'default' => true, 'limit' => 5, 'source' => 'SSF Medlemsprocess'),
            'motion' => array('label' => 'Motion till årsmötet', 'default' => true, 'limit' => 5, 'source' => 'SSF Medlemsportal'),
            'annual_meeting_registration' => array('label' => 'Årsmötesanmälan', 'default' => true, 'limit' => 8, 'source' => 'SSF Medlemsportal'),
            'application_completion' => array('label' => 'Komplettering via personlig länk', 'default' => false, 'limit' => 8, 'source' => 'SSF Medlemsprocess'),
            'vessel_update' => array('label' => 'Fartygsuppgifter via personlig länk', 'default' => false, 'limit' => 8, 'source' => 'SSF Medlemsfartyg'),
        );
    }

    public static function render(string $form_key, bool $force = false): void
    {
        echo self::render_html($form_key, $force); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public static function render_html(string $form_key, bool $force = false): string
    {
        if (! isset(self::forms()[$form_key])) {
            return '';
        }

        self::enqueue_assets();
        $honeypot = '<div class="ssf-antispam-honeypot" aria-hidden="true"><label>Lämna detta fält tomt<input type="text" name="ssf_antispam_website" value="" tabindex="-1" autocomplete="off"></label></div>';
        if (! self::turnstile_enabled($form_key) || (! $force && self::bypass_for_current_user())) {
            return $honeypot;
        }

        $site_key = self::site_key();
        if (! $site_key || ! self::production_configuration_is_safe()) {
            return $honeypot . '<p class="ssf-antispam-error" role="alert">Säkerhetskontrollen kunde inte laddas. Försök igen om en stund.</p>';
        }

        self::enqueue_turnstile_script();
        $id = 'ssf-turnstile-' . sanitize_html_class($form_key) . '-' . wp_rand(1000, 999999);
        $widget = sprintf(
            '<div class="ssf-antispam-widget"><div id="%1$s" class="ssf-turnstile-widget" data-sitekey="%2$s" data-action="%3$s" data-theme="auto" data-size="flexible"></div><noscript><p class="ssf-antispam-error">JavaScript måste vara aktiverat för att skicka formuläret.</p></noscript></div>',
            esc_attr($id),
            esc_attr($site_key),
            esc_attr(self::action_name($form_key))
        );
        $script = '(function(){var id=' . wp_json_encode($id) . ',tries=0,timer=setInterval(function(){var el=document.getElementById(id);if(!el||el.dataset.ssfRendered==="1"||++tries>150){clearInterval(timer);return;}if(window.turnstile&&typeof window.turnstile.render==="function"){el.dataset.ssfRendered="1";window.turnstile.render(el);clearInterval(timer);}},100);})();';
        wp_add_inline_script('cfturnstile', $script, 'after');

        return $honeypot . $widget;
    }

    public static function validate(string $form_key): bool
    {
        self::$last_reason = '';
        if (! isset(self::forms()[$form_key]) || self::bypass_for_current_user()) {
            return true;
        }
        if (! empty($_POST['ssf_antispam_website'])) {
            return self::block($form_key, 'honeypot');
        }
        if (! self::within_rate_limit($form_key)) {
            return self::block($form_key, 'rate_limit');
        }
        if (! self::turnstile_enabled($form_key)) {
            return true;
        }
        if (! self::is_configured()) {
            return self::block($form_key, 'configuration');
        }

        $token = isset($_POST['cf-turnstile-response']) && is_scalar($_POST['cf-turnstile-response'])
            ? sanitize_text_field(wp_unslash((string) $_POST['cf-turnstile-response']))
            : '';
        if (! $token) {
            return self::block($form_key, 'turnstile_missing');
        }

        $result = self::verify_token($token, $form_key);
        if (is_wp_error($result)) {
            return self::block($form_key, $result->get_error_code());
        }
        self::log_event($form_key, 'accepted', 'turnstile_ok');
        return true;
    }

    public static function error_message(): string
    {
        if ('rate_limit' === self::$last_reason) {
            return 'För många försök. Vänta några minuter och försök igen.';
        }
        if (in_array(self::$last_reason, array('configuration', 'turnstile_unavailable', 'turnstile_invalid_response'), true)) {
            return 'Vi kunde inte verifiera formuläret just nu. Försök igen om en stund.';
        }
        return 'Vi kunde inte verifiera att formuläret skickades av en riktig användare. Försök igen.';
    }

    public static function last_reason(): string
    {
        return self::$last_reason;
    }

    public static function register_admin_page(): void
    {
        add_submenu_page('ssf-overview', 'Antispam', 'Antispam', 'manage_options', 'ssf-antispam', array(__CLASS__, 'render_admin_page'), 95);
    }

    public static function render_admin_page(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Du saknar behörighet till antispaminställningarna.');
        }
        $plugin = self::plugin_status();
        $environment = wp_get_environment_type();
        $configured = self::is_configured();
        $mode = self::is_test_mode() ? 'TEST' : 'PRODUCTION';
        $tested = isset($_GET['ssf_antispam_test']) ? sanitize_key(wp_unslash($_GET['ssf_antispam_test'])) : '';
        ?>
        <div class="wrap ssf-antispam-admin">
            <h1>Antispam</h1>
            <?php if ('ok' === $tested) : ?><div class="notice notice-success is-dismissible"><p>Turnstile-token verifierades korrekt mot Cloudflare.</p></div><?php endif; ?>
            <?php if ('failed' === $tested) : ?><div class="notice notice-error"><p>Turnstile-testet misslyckades. Kontrollera nycklarna och försök igen.</p></div><?php endif; ?>
            <?php if ('production' === $environment && self::is_test_mode()) : ?><div class="notice notice-error"><p>Cloudflares testnycklar är blockerade i produktionsmiljö. Ange produktionsnycklar innan formulären öppnas.</p></div><?php endif; ?>
            <table class="widefat striped" style="max-width:900px"><tbody>
                <tr><th>Aktuell miljö</th><td><?php echo esc_html($environment); ?></td></tr>
                <tr><th>Cloudflare Turnstile</th><td><?php echo $configured ? 'Aktiv' : 'Inte konfigurerad'; ?></td></tr>
                <tr><th>Läge</th><td><strong><?php echo esc_html($mode); ?></strong></td></tr>
                <tr><th>Plugin</th><td>Simple CAPTCHA with Cloudflare Turnstile</td></tr>
                <tr><th>Pluginversion</th><td><?php echo esc_html($plugin['version'] ?: 'Okänd'); ?></td></tr>
                <tr><th>Pluginstatus</th><td><?php echo esc_html($plugin['active'] ? 'Aktivt' : 'Inte aktivt'); ?></td></tr>
                <tr><th>Honeypot</th><td>Aktiv</td></tr>
                <tr><th>Rate limiting</th><td>Aktiv</td></tr>
            </tbody></table>

            <h2>Formulär</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ssf_antispam_save">
                <?php wp_nonce_field('ssf_antispam_save'); ?>
                <table class="widefat striped" style="max-width:900px"><thead><tr><th>Formulär</th><th>Turnstile</th><th>Honeypot</th><th>Rate limit</th><th>Källa</th></tr></thead><tbody>
                <?php foreach (self::forms() as $key => $form) : ?>
                    <tr><td><?php echo esc_html($form['label']); ?></td><td><label><input type="checkbox" name="forms[<?php echo esc_attr($key); ?>]" value="1" <?php checked(self::turnstile_enabled($key)); ?>> Aktiv</label></td><td>Aktiv</td><td><?php echo esc_html(sprintf('%d / 10 min', (int) $form['limit'])); ?></td><td><?php echo esc_html($form['source']); ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
                <?php submit_button('Spara antispaminställningar'); ?>
            </form>

            <h2>Test</h2>
            <?php if ('development' === $environment && ! $configured) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="ssf_antispam_enable_dev_test"><?php wp_nonce_field('ssf_antispam_enable_dev_test'); ?>
                    <?php submit_button('Aktivera Cloudflares testnycklar på DEV', 'secondary'); ?>
                </form>
            <?php endif; ?>
            <?php if ($configured) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:420px">
                    <input type="hidden" name="action" value="ssf_antispam_test"><?php wp_nonce_field('ssf_antispam_test'); ?>
                    <?php self::render('contact', true); ?>
                    <?php submit_button('Testa Turnstile', 'secondary'); ?>
                </form>
            <?php endif; ?>
            <p>Turnstile-nycklar hanteras i <a href="<?php echo esc_url(admin_url('options-general.php?page=cfturnstile')); ?>">pluginets inställningar</a>. DEV och PROD har separata WordPress-databaser och därmed separata nycklar.</p>
        </div>
        <?php
    }

    public static function save_settings(): void
    {
        if (! current_user_can('manage_options') || ! check_admin_referer('ssf_antispam_save')) {
            wp_die('Du saknar behörighet.');
        }
        $submitted = isset($_POST['forms']) && is_array($_POST['forms']) ? wp_unslash($_POST['forms']) : array();
        $enabled = array();
        foreach (self::forms() as $key => $form) {
            $enabled[$key] = ! empty($submitted[$key]);
        }
        update_option(self::SETTINGS_OPTION, array('forms' => $enabled), false);
        wp_safe_redirect(add_query_arg('updated', '1', admin_url('admin.php?page=ssf-antispam')));
        exit;
    }

    public static function enable_dev_test_mode(): void
    {
        if (! current_user_can('manage_options') || ! check_admin_referer('ssf_antispam_enable_dev_test')) {
            wp_die('Du saknar behörighet.');
        }
        if ('development' !== wp_get_environment_type()) {
            wp_die('Testnycklar kan endast aktiveras i development-miljön.');
        }
        update_option('cfturnstile_key', self::TEST_SITE_KEYS[0], false);
        update_option('cfturnstile_secret', self::TEST_SECRET_KEYS[0], false);
        update_option('cfturnstile_tested', 'yes', false);
        wp_safe_redirect(add_query_arg('updated', '1', admin_url('admin.php?page=ssf-antispam')));
        exit;
    }

    public static function test_turnstile(): void
    {
        if (! current_user_can('manage_options') || ! check_admin_referer('ssf_antispam_test')) {
            wp_die('Du saknar behörighet.');
        }
        $token = isset($_POST['cf-turnstile-response']) && is_scalar($_POST['cf-turnstile-response']) ? sanitize_text_field(wp_unslash((string) $_POST['cf-turnstile-response'])) : '';
        $result = $token ? self::verify_token($token, 'contact') : new WP_Error('turnstile_missing');
        wp_safe_redirect(add_query_arg('ssf_antispam_test', is_wp_error($result) ? 'failed' : 'ok', admin_url('admin.php?page=ssf-antispam')));
        exit;
    }

    public static function plugin_status(): array
    {
        $file = WP_PLUGIN_DIR . '/simple-cloudflare-turnstile/simple-cloudflare-turnstile.php';
        $active = in_array('simple-cloudflare-turnstile/simple-cloudflare-turnstile.php', (array) get_option('active_plugins', array()), true);
        $version = '';
        if (is_readable($file)) {
            if (! function_exists('get_plugin_data')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $data = get_plugin_data($file, false, false);
            $version = (string) ($data['Version'] ?? '');
        }
        return array('active' => $active, 'version' => $version);
    }

    public static function turnstile_enabled(string $form_key): bool
    {
        $forms = self::forms();
        if (! isset($forms[$form_key])) {
            return false;
        }
        $settings = get_option(self::SETTINGS_OPTION, array());
        if (isset($settings['forms']) && array_key_exists($form_key, (array) $settings['forms'])) {
            return ! empty($settings['forms'][$form_key]);
        }
        return ! empty($forms[$form_key]['default']);
    }

    public static function is_configured(): bool
    {
        return (bool) self::site_key() && (bool) self::secret_key() && self::production_configuration_is_safe();
    }

    public static function is_test_mode(): bool
    {
        return in_array(self::site_key(), self::TEST_SITE_KEYS, true) || in_array(self::secret_key(), self::TEST_SECRET_KEYS, true);
    }

    private static function verify_token(string $token, string $form_key)
    {
        $response = wp_remote_post(self::TURNSTILE_ENDPOINT, array(
            'timeout' => 8,
            'body' => array('secret' => self::secret_key(), 'response' => $token),
        ));
        if (is_wp_error($response)) {
            return new WP_Error('turnstile_unavailable');
        }
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (! is_array($body)) {
            return new WP_Error('turnstile_invalid_response');
        }
        if (empty($body['success'])) {
            return new WP_Error('turnstile_failed');
        }
        if (! empty($body['action']) && self::action_name($form_key) !== (string) $body['action']) {
            return new WP_Error('turnstile_action_mismatch');
        }
        if (! self::is_test_mode() && ! empty($body['hostname'])) {
            $expected = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
            if ($expected && strtolower((string) $body['hostname']) !== $expected) {
                return new WP_Error('turnstile_hostname_mismatch');
            }
        }
        return $body;
    }

    private static function within_rate_limit(string $form_key): bool
    {
        $forms = self::forms();
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) wp_unslash($_SERVER['REMOTE_ADDR']) : 'unknown';
        $source = hash_hmac('sha256', $form_key . '|' . $ip, wp_salt('nonce'));
        $key = 'ssf_as_' . substr($source, 0, 32);
        $attempts = (int) get_transient($key);
        if ($attempts >= (int) $forms[$form_key]['limit']) {
            return false;
        }
        set_transient($key, $attempts + 1, 10 * MINUTE_IN_SECONDS);
        return true;
    }

    private static function block(string $form_key, string $reason): bool
    {
        self::$last_reason = sanitize_key($reason);
        self::log_event($form_key, 'blocked', self::$last_reason);
        return false;
    }

    private static function log_event(string $form_key, string $result, string $reason): void
    {
        $events = (array) get_option(self::EVENTS_OPTION, array());
        $events[] = array(
            'timestamp' => current_time('mysql', true),
            'form_key' => sanitize_key($form_key),
            'result' => sanitize_key($result),
            'reason' => sanitize_key($reason),
            'environment' => sanitize_key(wp_get_environment_type()),
        );
        update_option(self::EVENTS_OPTION, array_slice($events, -50), false);
    }

    private static function enqueue_assets(): void
    {
        if (self::$styles_added) {
            return;
        }
        wp_register_style('ssf-antispam', false, array(), '1.0.0');
        wp_enqueue_style('ssf-antispam');
        wp_add_inline_style('ssf-antispam', '.ssf-antispam-honeypot{position:absolute!important;left:-10000px!important;top:auto!important;width:1px!important;height:1px!important;overflow:hidden!important;pointer-events:none!important}.ssf-antispam-widget{margin:16px 0;max-width:100%}.ssf-turnstile-widget{max-width:100%}.ssf-antispam-error{color:#8a2424;font-weight:600}');
        self::$styles_added = true;
    }

    private static function enqueue_turnstile_script(): void
    {
        if (function_exists('cfturnstile_register_api') && ! wp_script_is('cfturnstile', 'registered')) {
            cfturnstile_register_api(array('strategy' => 'defer'));
        }
        if (! wp_script_is('cfturnstile', 'registered')) {
            wp_register_script('cfturnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit', array(), null, array('strategy' => 'defer', 'in_footer' => true));
        }
        wp_enqueue_script('cfturnstile');
    }

    private static function action_name(string $form_key): string
    {
        return 'ssf_' . str_replace('_', '-', sanitize_key($form_key));
    }

    private static function site_key(): string
    {
        return trim((string) get_option('cfturnstile_key', ''));
    }

    private static function secret_key(): string
    {
        return trim((string) get_option('cfturnstile_secret', ''));
    }

    private static function production_configuration_is_safe(): bool
    {
        return 'production' !== wp_get_environment_type() || ! self::is_test_mode();
    }

    private static function bypass_for_current_user(): bool
    {
        return is_user_logged_in() && current_user_can('manage_options');
    }
}

SSF_Antispam::boot();
