<?php
/**
 * Central, environment-specific Microsoft 365 organisation configuration.
 *
 * @package SSF
 */

if (! defined('ABSPATH')) {
    exit;
}

final class SSF_Microsoft365_Config
{
    public const OPTION = 'ssf_microsoft365_tenant_configuration';
    private const TEST_PREFIX = 'ssf_microsoft365_tenant_test_';
    private const NOTICE_PREFIX = 'ssf_microsoft365_tenant_notice_';
    private const TENANT_CONSTANT = 'SSF_MICROSOFT365_TENANT_ID';
    private const AUTHORITY_CONSTANT = 'SSF_MICROSOFT365_AUTHORITY_HOST';

    public static function boot(): void
    {
        add_action('admin_post_ssf_save_microsoft365_tenant', array(__CLASS__, 'save_admin'));
        add_action('admin_post_ssf_test_microsoft365_tenant', array(__CLASS__, 'test_admin'));
    }

    public static function get_organisation_name(): string
    {
        return (string) self::profile()['organisation_name'];
    }

    public static function get_primary_domain(): string
    {
        return (string) self::profile()['primary_domain'];
    }

    public static function get_tenant_id(): string
    {
        self::maybe_migrate_legacy_tenant();
        $override = self::server_value(self::TENANT_CONSTANT);
        return '' !== $override ? $override : (string) self::profile()['tenant_id'];
    }

    public static function get_authority_host(): string
    {
        $override = self::server_value(self::AUTHORITY_CONSTANT);
        return '' !== $override ? self::sanitize_host($override) : (string) self::profile()['authority_host'];
    }

    public static function get_authority_url(string $path = ''): string
    {
        $base = 'https://' . self::get_authority_host();
        $tenant = self::get_tenant_id();
        return $tenant ? $base . '/' . rawurlencode($tenant) . ('/' . ltrim($path, '/')) : $base;
    }

    public static function is_tenant_configured(): bool
    {
        return self::valid_tenant_id(self::get_tenant_id());
    }

    public static function environment(): string
    {
        return function_exists('wp_get_environment_type') && 'production' === wp_get_environment_type() ? 'production' : 'development';
    }

    public static function render_admin_section(): void
    {
        self::maybe_migrate_legacy_tenant();
        $profile = self::profile();
        $tenant_id = self::get_tenant_id();
        $override = '' !== self::server_value(self::TENANT_CONSTANT);
        $authority_override = '' !== self::server_value(self::AUTHORITY_CONSTANT);
        $authority_host = self::get_authority_host();
        $settings = self::settings();
        $migration = (array) ($settings['migration'][self::environment()] ?? array());
        $notice = get_transient(self::NOTICE_PREFIX . get_current_user_id());
        $test = get_transient(self::TEST_PREFIX . get_current_user_id());
        if ($notice) { delete_transient(self::NOTICE_PREFIX . get_current_user_id()); }
        ?>
        <section class="postbox" style="max-width:980px;padding:20px">
            <h2><?php esc_html_e('Microsoft 365 – organisation', 'ssf'); ?></h2>
            <?php if ($notice) : ?><div class="notice notice-<?php echo esc_attr((string) $notice['type']); ?> inline"><p><?php echo esc_html((string) $notice['message']); ?></p></div><?php endif; ?>
            <?php if ('conflict' === ($migration['status'] ?? '')) : ?><div class="notice notice-error inline"><p><strong><?php esc_html_e('Konflikt mellan äldre Tenant ID-värden.', 'ssf'); ?></strong> <?php esc_html_e('Granska och spara rätt Tenant ID centralt. Inga äldre värden har tagits bort.', 'ssf'); ?></p></div><?php endif; ?>
            <?php if ($override || $authority_override) : ?><div class="notice notice-info inline"><p><?php esc_html_e('Tenant ID och/eller Microsoft cloud styrs av serverkonfiguration och har företräde framför WordPress-värdet.', 'ssf'); ?></p></div><?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ssf_save_microsoft365_tenant">
                <?php wp_nonce_field('ssf_save_microsoft365_tenant'); ?>
                <table class="form-table" role="presentation"><tbody>
                    <tr><th><label for="ssf-m365-organisation">Organisation</label></th><td><input id="ssf-m365-organisation" class="regular-text" name="tenant[organisation_name]" value="<?php echo esc_attr((string) $profile['organisation_name']); ?>"></td></tr>
                    <tr><th><label for="ssf-m365-domain">Primary domain</label></th><td><input id="ssf-m365-domain" class="regular-text" name="tenant[primary_domain]" value="<?php echo esc_attr((string) $profile['primary_domain']); ?>"></td></tr>
                    <tr><th><label for="ssf-m365-tenant">Tenant ID</label></th><td><input id="ssf-m365-tenant" class="regular-text code" name="tenant[tenant_id]" value="<?php echo esc_attr($tenant_id); ?>" <?php disabled($override); ?>></td></tr>
                    <tr><th><label for="ssf-m365-cloud">Microsoft cloud</label></th><td><select id="ssf-m365-cloud" name="tenant[authority_host]" <?php disabled($authority_override); ?>><option value="login.microsoftonline.com" <?php selected('login.microsoftonline.com', $authority_host); ?>>Microsoft public cloud (login.microsoftonline.com)</option></select></td></tr>
                </tbody></table>
                <?php submit_button(__('Spara organisationsinställningar', 'ssf')); ?>
            </form>
            <p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ssf_test_microsoft365_tenant'), 'ssf_test_microsoft365_tenant')); ?>">Testa Microsoft-tenant</a></p>
            <?php self::render_test_result(is_array($test) ? $test : array()); ?>
            <?php self::render_integration_status(); ?>
        </section>
        <?php
    }

    public static function save_admin(): void
    {
        self::guard('ssf_save_microsoft365_tenant');
        $input = (array) wp_unslash($_POST['tenant'] ?? array());
        $settings = self::settings();
        $environment = self::environment();
        $current = self::profile();
        $tenant_input = trim((string) ($input['tenant_id'] ?? ''));
        if (! self::server_value(self::TENANT_CONSTANT) && '' !== $tenant_input && ! self::valid_tenant_id($tenant_input)) {
            self::redirect('Tenant ID har ogiltigt format och sparades inte.', 'error');
        }
        $settings['profiles'][$environment] = array(
            'organisation_name' => sanitize_text_field((string) ($input['organisation_name'] ?? $current['organisation_name'])),
            'primary_domain' => self::sanitize_domain((string) ($input['primary_domain'] ?? $current['primary_domain'])),
            'tenant_id' => self::server_value(self::TENANT_CONSTANT) ? (string) $current['tenant_id'] : self::sanitize_tenant_id($tenant_input),
            'authority_host' => self::sanitize_host((string) ($input['authority_host'] ?? $current['authority_host'])),
        );
        $settings['migration'][$environment] = array('status' => 'central_saved', 'checked_at' => gmdate('c'));
        update_option(self::OPTION, $settings, false);
        self::redirect('Microsoft 365-organisationen har sparats.', 'success');
    }

    public static function test_admin(): void
    {
        self::guard('ssf_test_microsoft365_tenant');
        $result = self::test_tenant();
        set_transient(self::TEST_PREFIX . get_current_user_id(), $result, 10 * MINUTE_IN_SECONDS);
        self::redirect(! empty($result['ok']) ? 'Microsoft-tenant verifierades.' : 'Microsoft-tenant kunde inte verifieras.', ! empty($result['ok']) ? 'success' : 'error');
    }

    public static function test_tenant(): array
    {
        $tenant = self::get_tenant_id();
        $format_ok = self::valid_tenant_id($tenant);
        $response = $format_ok ? wp_remote_get(self::get_authority_url('/v2.0/.well-known/openid-configuration'), array('timeout' => 20)) : new WP_Error('tenant_invalid_format', 'Tenant ID har ogiltigt format.');
        $http_status = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        $body = is_wp_error($response) ? array() : json_decode(wp_remote_retrieve_body($response), true);
        $body = is_array($body) ? $body : array();
        $error = self::safe_microsoft_error($response, $body, $http_status);
        $discovery_ok = ! is_wp_error($response) && 200 === $http_status && ! empty($body['issuer']);
        $expected_issuer = 'https://' . self::get_authority_host() . '/' . $tenant . '/v2.0';
        $rows = array(
            array('label' => 'Tenant ID', 'ok' => '' !== $tenant && $format_ok, 'detail' => '' !== $tenant ? ($format_ok ? 'Format giltigt.' : 'Ogiltigt format.') : 'Saknas.'),
            array('label' => 'OpenID discovery', 'ok' => $discovery_ok, 'detail' => $discovery_ok ? 'Metadata kunde läsas.' : $error['message']),
            array('label' => 'Issuer', 'ok' => $discovery_ok && hash_equals($expected_issuer, (string) ($body['issuer'] ?? '')), 'detail' => $discovery_ok ? (string) ($body['issuer'] ?? '') : 'Kunde inte verifieras.'),
            array('label' => 'Authorization endpoint', 'ok' => $discovery_ok && self::valid_https_url((string) ($body['authorization_endpoint'] ?? '')), 'detail' => ! empty($body['authorization_endpoint']) ? 'Finns.' : 'Saknas.'),
            array('label' => 'Token endpoint', 'ok' => $discovery_ok && self::valid_https_url((string) ($body['token_endpoint'] ?? '')), 'detail' => ! empty($body['token_endpoint']) ? 'Finns.' : 'Saknas.'),
            array('label' => 'JWKS', 'ok' => $discovery_ok && self::valid_https_url((string) ($body['jwks_uri'] ?? '')), 'detail' => ! empty($body['jwks_uri']) ? 'Finns.' : 'Saknas.'),
        );
        return array('ok' => ! in_array(false, array_column($rows, 'ok'), true), 'tested_at' => gmdate('c'), 'error_code' => $error['code'], 'rows' => $rows);
    }

    public static function maybe_migrate_legacy_tenant(): void
    {
        $settings = self::settings();
        $environment = self::environment();
        if ('' !== self::server_value(self::TENANT_CONSTANT) || '' !== (string) ($settings['profiles'][$environment]['tenant_id'] ?? '')) { return; }
        $candidates = self::legacy_tenant_candidates($environment);
        $values = array_values(array_unique(array_filter(array_map(static function ($candidate) { return strtolower((string) $candidate['value']); }, $candidates))));
        if (1 === count($values)) {
            $settings['profiles'][$environment]['tenant_id'] = self::sanitize_tenant_id($values[0]);
            $settings['migration'][$environment] = array('status' => 'migrated', 'sources' => array_column($candidates, 'source'), 'checked_at' => gmdate('c'));
            update_option(self::OPTION, $settings, false);
        } elseif (count($values) > 1) {
            $settings['migration'][$environment] = array('status' => 'conflict', 'sources' => array_column($candidates, 'source'), 'candidate_count' => count($values), 'checked_at' => gmdate('c'));
            update_option(self::OPTION, $settings, false);
        }
    }

    private static function legacy_tenant_candidates(string $environment): array
    {
        $candidates = array();
        foreach (array('SSF_GRAPH_TENANT_ID' => 'SharePoint serverkonfiguration', 'SSF_M365_LOGIN_TENANT_ID' => 'Microsoft ID Login serverkonfiguration') as $constant => $source) {
            $value = self::server_value($constant);
            if (self::valid_tenant_id($value)) { $candidates[] = array('source' => $source, 'value' => $value); }
        }
        $graph = (array) get_option('ssf_member_portal_graph_configuration', array());
        if (self::valid_tenant_id((string) ($graph['tenant_id'] ?? ''))) { $candidates[] = array('source' => 'SharePoint WordPress-inställning', 'value' => (string) $graph['tenant_id']); }
        $login = (array) get_option('ssf_microsoft_login_settings', array());
        $login_profile = (array) ($login['profiles'][$environment] ?? array());
        if (self::valid_tenant_id((string) ($login_profile['tenant_id'] ?? ''))) { $candidates[] = array('source' => 'Microsoft ID Login WordPress-inställning', 'value' => (string) $login_profile['tenant_id']); }
        return $candidates;
    }

    private static function profile(): array
    {
        $settings = self::settings();
        return array_merge(self::defaults(), (array) ($settings['profiles'][self::environment()] ?? array()));
    }

    private static function settings(): array
    {
        $stored = get_option(self::OPTION, array());
        return is_array($stored) ? $stored : array();
    }

    private static function defaults(): array
    {
        return array('organisation_name' => 'Sveriges Segelfartygsförbund', 'primary_domain' => 'ssfb.se', 'tenant_id' => '', 'authority_host' => 'login.microsoftonline.com');
    }

    private static function render_test_result(array $result): void
    {
        if (empty($result['rows'])) { return; }
        echo '<h3>Tenantkontroll</h3><table class="widefat striped"><tbody>';
        foreach ($result['rows'] as $row) { echo '<tr><th>' . esc_html((string) $row['label']) . '</th><td>' . esc_html(! empty($row['ok']) ? 'PASS' : 'FAIL') . '</td><td>' . esc_html((string) $row['detail']) . '</td></tr>'; }
        echo '</tbody></table>';
        if (! empty($result['error_code'])) { echo '<p><strong>Microsoft-felkod:</strong> <code>' . esc_html((string) $result['error_code']) . '</code></p>'; }
    }

    private static function render_integration_status(): void
    {
        $graph_status = class_exists('SSF\MemberPortal\Integrations\Microsoft365\Configuration') ? \SSF\MemberPortal\Integrations\Microsoft365\Configuration::public_status() : array();
        $login = (array) get_option('ssf_microsoft_login_settings', array());
        $login_profile = (array) ($login['profiles'][self::environment()] ?? array());
        $sharepoint_ready = ! empty($graph_status['client_id']['configured']) && ! empty($graph_status['client_secret']['configured']) && self::is_tenant_configured();
        $login_ready = ! empty($login_profile['client_id']) && ! empty($login_profile['client_secret']) && self::is_tenant_configured();
        echo '<h3>Integrationer</h3><div class="ssf-sp-overview">';
        echo '<article class="ssf-sp-destination"><h4>SharePoint</h4><p>' . esc_html($sharepoint_ready ? 'Konfigurerad' : 'Ej komplett') . '</p><dl><div><dt>Appuppgifter</dt><dd>' . esc_html(! empty($graph_status['client_id']['configured']) && ! empty($graph_status['client_secret']['configured']) ? 'Konfigurerade' : 'Saknas') . '</dd></div></dl></article>';
        echo '<article class="ssf-sp-destination"><h4>Microsoft ID Login</h4><p>' . esc_html($login_ready ? 'Konfigurerad' : 'Ej komplett') . '</p><dl><div><dt>Appuppgifter</dt><dd>' . esc_html(! empty($login_profile['client_id']) && ! empty($login_profile['client_secret']) ? 'Konfigurerade' : 'Saknas') . '</dd></div></dl></article>';
        echo '</div>';
    }

    private static function safe_microsoft_error($response, array $body, int $http_status): array
    {
        $code = sanitize_key((string) ($body['error'] ?? ''));
        if ('invalid_tenant' === $code) { return array('code' => $code, 'message' => 'Microsoft känner inte igen Tenant ID:t. Kontrollera värdet och vald Microsoft cloud.'); }
        if (is_wp_error($response)) { return array('code' => sanitize_key((string) $response->get_error_code()), 'message' => 'Microsoft kunde inte nås för en read-only tenantkontroll.'); }
        return array('code' => $code ?: ($http_status ? 'http_' . $http_status : ''), 'message' => $http_status ? 'Microsoft svarade med HTTP ' . $http_status . '.' : 'Tenantkontrollen kunde inte genomföras.');
    }

    private static function valid_tenant_id(string $value): bool
    {
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', trim($value));
    }

    private static function sanitize_tenant_id(string $value): string
    {
        $value = strtolower(trim(sanitize_text_field($value)));
        return self::valid_tenant_id($value) ? $value : '';
    }

    private static function sanitize_domain(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9.-]/i', '', trim($value)));
    }

    private static function sanitize_host(string $value): string
    {
        $host = strtolower((string) wp_parse_url(false === strpos($value, '://') ? 'https://' . trim($value) : trim($value), PHP_URL_HOST));
        return $host ?: 'login.microsoftonline.com';
    }

    private static function valid_https_url(string $value): bool
    {
        return 0 === strpos($value, 'https://') && (bool) wp_http_validate_url($value);
    }

    private static function server_value(string $constant): string
    {
        $value = defined($constant) ? constant($constant) : getenv($constant);
        return is_string($value) ? trim($value) : '';
    }

    private static function guard(string $nonce): void
    {
        if (! (current_user_can('ssf_manage_member_portal') || current_user_can('manage_options')) || ! check_admin_referer($nonce)) { wp_die('Du saknar behörighet.'); }
    }

    private static function redirect(string $message, string $type): void
    {
        set_transient(self::NOTICE_PREFIX . get_current_user_id(), array('message' => $message, 'type' => $type), MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('admin.php?page=ssf-member-portal-microsoft365'));
        exit;
    }
}

SSF_Microsoft365_Config::boot();
