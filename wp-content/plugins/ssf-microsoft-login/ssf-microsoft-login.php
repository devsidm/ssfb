<?php
/**
 * Plugin Name: SSF Microsoft 365 Login
 * Plugin URI: https://github.com/devsidm/ssfb
 * Description: DEV-only Microsoft Entra ID login pilot for SSF WordPress accounts.
 * Version: 0.1.0
 * Author: SIDM
 * Text Domain: ssf-microsoft-login
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package SSF_Microsoft_Login
 */

if (! defined('ABSPATH')) {
    exit;
}

final class SSF_Microsoft_Login
{
    private const VERSION = '0.1.0';
    private const STATE_PREFIX = 'ssf_m365_login_state_';
    private const NOTICE_PREFIX = 'ssf_m365_login_notice_';
    private const MENU_SLUG = 'ssf-microsoft-login';
    private const META_TID = '_ssf_m365_tid';
    private const META_OID = '_ssf_m365_oid';
    private const QUERY_VAR = 'ssf_m365_login_callback';
    private const CALLBACK_PATH = 'ssf-auth/microsoft/callback/';

    private static ?SSF_Microsoft_Login $instance = null;

    public static function instance(): SSF_Microsoft_Login
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function activate(): void
    {
        self::instance()->register_rewrite();
        flush_rewrite_rules(false);
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules(false);
    }

    public static function login_url(string $redirect_to = ''): string
    {
        $args = array('action' => 'ssf_m365_login_start');
        if ('' !== $redirect_to) {
            $args['redirect_to'] = rawurlencode($redirect_to);
        }
        return add_query_arg($args, admin_url('admin-post.php'));
    }

    private function __construct()
    {
        add_action('init', array($this, 'register_rewrite'));
        add_filter('query_vars', array($this, 'query_vars'));
        add_action('template_redirect', array($this, 'maybe_handle_callback'));
        add_action('login_form', array($this, 'render_login_button'));
        add_action('admin_menu', array($this, 'register_admin_page'));
        add_action('admin_notices', array($this, 'render_notices'));
        add_action('show_user_profile', array($this, 'render_profile_connection'));
        add_action('edit_user_profile', array($this, 'render_profile_connection'));
        add_action('admin_post_ssf_m365_login_start', array($this, 'start_login'));
        add_action('admin_post_nopriv_ssf_m365_login_start', array($this, 'start_login'));
        add_action('admin_post_ssf_m365_link_start', array($this, 'start_link'));
        add_action('admin_post_ssf_m365_unlink', array($this, 'unlink_account'));
        add_action('admin_post_ssf_m365_test_config', array($this, 'test_configuration'));
    }

    public function register_rewrite(): void
    {
        add_rewrite_rule('^ssf-auth/microsoft/callback/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top');
    }

    public function query_vars(array $vars): array
    {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    public function maybe_handle_callback(): void
    {
        if ('1' !== (string) get_query_var(self::QUERY_VAR)) {
            return;
        }
        $this->handle_callback();
    }

    public function register_admin_page(): void
    {
        if (class_exists('SSF_Admin_Navigation')) {
            add_submenu_page(null, __('Microsoft 365-inloggning', 'ssf-microsoft-login'), __('Microsoft 365-inloggning', 'ssf-microsoft-login'), 'manage_options', self::MENU_SLUG, array($this, 'render_admin_page'));
            return;
        }
        add_management_page(__('Microsoft 365-inloggning', 'ssf-microsoft-login'), __('Microsoft 365-inloggning', 'ssf-microsoft-login'), 'manage_options', self::MENU_SLUG, array($this, 'render_admin_page'));
    }

    public function render_admin_page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $status = $this->status();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Microsoft 365-inloggning', 'ssf-microsoft-login'); ?></h1>
            <?php if (class_exists('SSF_Admin_Navigation')) { SSF_Admin_Navigation::render_system_tabs(self::MENU_SLUG); } ?>
            <table class="widefat striped" style="max-width: 820px">
                <tbody>
                    <tr><th><?php esc_html_e('Environment', 'ssf-microsoft-login'); ?></th><td><?php echo esc_html(ucfirst($status['environment'])); ?></td></tr>
                    <tr><th><?php esc_html_e('Enabled', 'ssf-microsoft-login'); ?></th><td><?php echo esc_html($status['enabled'] ? 'YES' : 'NO'); ?></td></tr>
                    <tr><th><?php esc_html_e('Tenant ID', 'ssf-microsoft-login'); ?></th><td><?php echo esc_html($status['tenant_id'] ? 'CONFIGURED' : 'MISSING'); ?></td></tr>
                    <tr><th><?php esc_html_e('Client ID', 'ssf-microsoft-login'); ?></th><td><?php echo esc_html($status['client_id'] ? 'CONFIGURED' : 'MISSING'); ?></td></tr>
                    <tr><th><?php esc_html_e('Client secret', 'ssf-microsoft-login'); ?></th><td><?php echo esc_html($status['client_secret'] ? 'CONFIGURED' : 'MISSING'); ?></td></tr>
                    <tr><th><?php esc_html_e('Redirect URI', 'ssf-microsoft-login'); ?></th><td><code><?php echo esc_html($this->callback_url()); ?></code></td></tr>
                    <tr><th><?php esc_html_e('OpenID metadata', 'ssf-microsoft-login'); ?></th><td><?php echo esc_html($status['metadata'] ?? 'NOT TESTED'); ?></td></tr>
                    <tr><th><?php esc_html_e('JWKS available', 'ssf-microsoft-login'); ?></th><td><?php echo esc_html($status['jwks'] ?? 'NOT TESTED'); ?></td></tr>
                </tbody>
            </table>
            <p>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ssf_m365_test_config'), 'ssf_m365_test_config')); ?>"><?php esc_html_e('Testa Microsoft-konfiguration', 'ssf-microsoft-login'); ?></a>
            </p>
        </div>
        <?php
    }

    public function render_login_button(): void
    {
        if (! $this->is_enabled()) {
            return;
        }
        $redirect_to = isset($_REQUEST['redirect_to']) && is_scalar($_REQUEST['redirect_to']) ? esc_url_raw(wp_unslash($_REQUEST['redirect_to'])) : '';
        echo '<p style="text-align:center;margin:16px 0 8px;">' . esc_html__('eller', 'ssf-microsoft-login') . '</p>';
        echo '<p><a class="button button-secondary button-large" style="width:100%;text-align:center;" href="' . esc_url(self::login_url($redirect_to)) . '">' . esc_html__('Logga in med Microsoft 365', 'ssf-microsoft-login') . '</a></p>';
    }

    public function render_profile_connection($user): void
    {
        if (! $this->is_enabled() || ! $user instanceof WP_User || (int) get_current_user_id() !== (int) $user->ID) {
            return;
        }
        $linked = (string) get_user_meta($user->ID, self::META_TID, true) && (string) get_user_meta($user->ID, self::META_OID, true);
        ?>
        <h2><?php esc_html_e('Microsoft 365-konto', 'ssf-microsoft-login'); ?></h2>
        <table class="form-table" role="presentation"><tr><th><?php esc_html_e('Status', 'ssf-microsoft-login'); ?></th><td>
            <p><?php echo esc_html($linked ? __('Microsoft 365-kontot är kopplat.', 'ssf-microsoft-login') : __('Inget Microsoft 365-konto är kopplat.', 'ssf-microsoft-login')); ?></p>
            <?php if ($linked) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="ssf_m365_unlink">
                    <?php wp_nonce_field('ssf_m365_unlink'); ?>
                    <?php submit_button(__('Koppla från Microsoft 365', 'ssf-microsoft-login'), 'secondary', 'submit', false); ?>
                </form>
            <?php else : ?>
                <p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ssf_m365_link_start'), 'ssf_m365_link_start')); ?>"><?php esc_html_e('Koppla Microsoft 365-konto', 'ssf-microsoft-login'); ?></a></p>
            <?php endif; ?>
        </td></tr></table>
        <?php
    }

    public function start_login(): void
    {
        $this->start_authorization('login', 0, isset($_REQUEST['redirect_to']) && is_scalar($_REQUEST['redirect_to']) ? rawurldecode((string) wp_unslash($_REQUEST['redirect_to'])) : '');
    }

    public function start_link(): void
    {
        if (! is_user_logged_in() || ! check_admin_referer('ssf_m365_link_start')) {
            wp_die(esc_html__('Du måste vara inloggad för att koppla Microsoft 365-konto.', 'ssf-microsoft-login'));
        }
        $this->start_authorization('link', get_current_user_id(), admin_url('profile.php'));
    }

    public function unlink_account(): void
    {
        if (! is_user_logged_in() || ! check_admin_referer('ssf_m365_unlink')) {
            wp_die(esc_html__('Du måste vara inloggad för att koppla från Microsoft 365.', 'ssf-microsoft-login'));
        }
        delete_user_meta(get_current_user_id(), self::META_TID);
        delete_user_meta(get_current_user_id(), self::META_OID);
        $this->set_notice(get_current_user_id(), 'success', __('Microsoft 365-kontot har kopplats från.', 'ssf-microsoft-login'));
        wp_safe_redirect(admin_url('profile.php'));
        exit;
    }

    public function test_configuration(): void
    {
        if (! current_user_can('manage_options') || ! check_admin_referer('ssf_m365_test_config')) {
            wp_die(esc_html__('Du saknar behörighet.', 'ssf-microsoft-login'));
        }
        $metadata = $this->discovery_metadata(false);
        $jwks = ! is_wp_error($metadata) ? $this->jwks(false) : new WP_Error('metadata_failed', 'metadata_failed');
        set_transient(
            self::NOTICE_PREFIX . get_current_user_id(),
            array(
                'type' => is_wp_error($metadata) || is_wp_error($jwks) ? 'error' : 'success',
                'message' => is_wp_error($metadata) || is_wp_error($jwks)
                    ? __('Microsoft-konfigurationen kunde inte verifieras.', 'ssf-microsoft-login')
                    : __('Microsoft-konfigurationen kunde läsas.', 'ssf-microsoft-login'),
            ),
            MINUTE_IN_SECONDS
        );
        wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG));
        exit;
    }

    public function render_notices(): void
    {
        $notice = get_transient(self::NOTICE_PREFIX . get_current_user_id());
        if (! $notice) {
            return;
        }
        delete_transient(self::NOTICE_PREFIX . get_current_user_id());
        printf('<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr($notice['type']), esc_html($notice['message']));
    }

    private function start_authorization(string $mode, int $user_id, string $redirect_to): void
    {
        if (! $this->is_enabled()) {
            wp_die(esc_html__('Microsoft 365-inloggning är inte aktiverad.', 'ssf-microsoft-login'));
        }
        if ('link' === $mode && $user_id <= 0) {
            wp_die(esc_html__('Du måste vara inloggad för att koppla Microsoft 365-konto.', 'ssf-microsoft-login'));
        }
        $state = $this->random_urlsafe(32);
        $nonce = $this->random_urlsafe(32);
        $verifier = $this->random_urlsafe(64);
        set_transient(
            self::STATE_PREFIX . $state,
            array(
                'mode' => $mode,
                'user_id' => $user_id,
                'nonce' => $nonce,
                'pkce_verifier' => $verifier,
                'created' => time(),
                'redirect_to' => $this->safe_redirect($redirect_to),
                'used' => false,
            ),
            10 * MINUTE_IN_SECONDS
        );
        $query = array(
            'client_id' => $this->config('client_id'),
            'response_type' => 'code',
            'redirect_uri' => $this->callback_url(),
            'response_mode' => 'query',
            'scope' => 'openid profile email',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $this->base64url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        );
        wp_redirect(add_query_arg($query, $this->authority_url('/oauth2/v2.0/authorize')));
        exit;
    }

    private function handle_callback(): void
    {
        $state = isset($_GET['state']) && is_scalar($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        $transaction = $state ? get_transient(self::STATE_PREFIX . $state) : false;
        delete_transient(self::STATE_PREFIX . $state);
        if (! is_array($transaction) || ! empty($transaction['used']) || time() - (int) ($transaction['created'] ?? 0) > 10 * MINUTE_IN_SECONDS) {
            $this->deny(__('Microsoft-inloggningen kunde inte verifieras. Försök igen.', 'ssf-microsoft-login'));
        }
        if (! empty($_GET['error'])) {
            $this->deny(__('Microsoft-inloggningen avbröts eller nekades.', 'ssf-microsoft-login'));
        }
        $code = isset($_GET['code']) && is_scalar($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        if (! $code) {
            $this->deny(__('Microsoft skickade ingen behörighetskod.', 'ssf-microsoft-login'));
        }
        $token = $this->exchange_code($code, (string) $transaction['pkce_verifier']);
        if (is_wp_error($token) || empty($token['id_token'])) {
            $this->deny(__('Microsoft-inloggningen kunde inte verifieras. Försök igen.', 'ssf-microsoft-login'));
        }
        $claims = $this->validate_id_token((string) $token['id_token'], (string) $transaction['nonce']);
        if (is_wp_error($claims)) {
            $this->deny($claims->get_error_message());
        }
        $tid = sanitize_text_field((string) ($claims['tid'] ?? ''));
        $oid = sanitize_text_field((string) ($claims['oid'] ?? ''));
        if (! $tid || ! $oid) {
            $this->deny(__('Microsoft-inloggningen kunde inte verifieras. Försök igen.', 'ssf-microsoft-login'));
        }
        if ('link' === ($transaction['mode'] ?? '')) {
            $this->complete_link($transaction, $tid, $oid);
        }
        $this->complete_login($tid, $oid, (string) ($transaction['redirect_to'] ?? ''));
    }

    private function complete_login(string $tid, string $oid, string $redirect_to): void
    {
        $users = $this->users_for_identity($tid, $oid);
        if (1 !== count($users)) {
            $this->deny(__('Ditt Microsoft-konto är inte kopplat till ett SSF-konto.', 'ssf-microsoft-login'));
        }
        $user_id = (int) $users[0]->ID;
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true);
        do_action('wp_login', $users[0]->user_login, $users[0]);
        wp_safe_redirect($this->safe_redirect($redirect_to));
        exit;
    }

    private function complete_link(array $transaction, string $tid, string $oid): void
    {
        $user_id = (int) ($transaction['user_id'] ?? 0);
        if (! is_user_logged_in() || $user_id <= 0 || get_current_user_id() !== $user_id) {
            $this->deny(__('Du måste vara inloggad för att koppla Microsoft 365-konto.', 'ssf-microsoft-login'));
        }
        foreach ($this->users_for_identity($tid, $oid) as $user) {
            if ((int) $user->ID !== $user_id) {
                $this->deny(__('Det här Microsoft-kontot är redan kopplat till ett annat SSF-konto.', 'ssf-microsoft-login'));
            }
        }
        update_user_meta($user_id, self::META_TID, $tid);
        update_user_meta($user_id, self::META_OID, $oid);
        $this->set_notice($user_id, 'success', __('Microsoft 365-kontot är nu kopplat.', 'ssf-microsoft-login'));
        wp_safe_redirect(admin_url('profile.php'));
        exit;
    }

    private function exchange_code(string $code, string $verifier)
    {
        $response = wp_remote_post($this->authority_url('/oauth2/v2.0/token'), array(
            'timeout' => 20,
            'body' => array(
                'client_id' => $this->config('client_id'),
                'client_secret' => $this->config('client_secret'),
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->callback_url(),
                'code_verifier' => $verifier,
                'scope' => 'openid profile email',
            ),
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (200 !== (int) wp_remote_retrieve_response_code($response) || ! is_array($data)) {
            return new WP_Error('ssf_m365_token_exchange_failed', 'token_exchange_failed');
        }
        return $data;
    }

    private function validate_id_token(string $jwt, string $expected_nonce)
    {
        $parts = explode('.', $jwt);
        if (3 !== count($parts)) {
            return new WP_Error('ssf_m365_jwt_format', __('Microsoft-inloggningen kunde inte verifieras. Försök igen.', 'ssf-microsoft-login'));
        }
        $header = json_decode($this->base64url_decode($parts[0]), true);
        $claims = json_decode($this->base64url_decode($parts[1]), true);
        $signature = $this->base64url_decode($parts[2]);
        if (! is_array($header) || ! is_array($claims) || ($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) {
            return new WP_Error('ssf_m365_jwt_header', __('Microsoft-inloggningen kunde inte verifieras. Försök igen.', 'ssf-microsoft-login'));
        }
        $key = $this->signing_key((string) $header['kid']);
        if (is_wp_error($key) || ! openssl_verify($parts[0] . '.' . $parts[1], $signature, $key, OPENSSL_ALGO_SHA256)) {
            return new WP_Error('ssf_m365_invalid_signature', __('Microsoft-inloggningen kunde inte verifieras. Försök igen.', 'ssf-microsoft-login'));
        }
        $tenant = $this->config('tenant_id');
        $issuer = 'https://login.microsoftonline.com/' . $tenant . '/v2.0';
        $now = time();
        if (($claims['iss'] ?? '') !== $issuer) {
            return new WP_Error('ssf_m365_invalid_issuer', __('Microsoft-inloggningen kunde inte verifieras. Försök igen.', 'ssf-microsoft-login'));
        }
        if (($claims['aud'] ?? '') !== $this->config('client_id')) {
            return new WP_Error('ssf_m365_invalid_audience', __('Microsoft-inloggningen kunde inte verifieras. Försök igen.', 'ssf-microsoft-login'));
        }
        if ((int) ($claims['exp'] ?? 0) <= $now || ((int) ($claims['nbf'] ?? 0) && (int) $claims['nbf'] > $now)) {
            return new WP_Error('ssf_m365_token_time', __('Microsoft-inloggningen kunde inte verifieras. Försök igen.', 'ssf-microsoft-login'));
        }
        if (($claims['nonce'] ?? '') !== $expected_nonce) {
            return new WP_Error('ssf_m365_invalid_nonce', __('Microsoft-inloggningen kunde inte verifieras. Försök igen.', 'ssf-microsoft-login'));
        }
        if (($claims['tid'] ?? '') !== $tenant) {
            return new WP_Error('ssf_m365_invalid_tenant', __('Det här Microsoft-kontot tillhör inte SSF:s Microsoft 365-miljö.', 'ssf-microsoft-login'));
        }
        if (empty($claims['oid']) || empty($claims['tid'])) {
            return new WP_Error('ssf_m365_missing_identity', __('Microsoft-inloggningen kunde inte verifieras. Försök igen.', 'ssf-microsoft-login'));
        }
        return $claims;
    }

    private function signing_key(string $kid)
    {
        $jwks = $this->jwks();
        if (is_wp_error($jwks)) {
            return $jwks;
        }
        foreach (($jwks['keys'] ?? array()) as $key) {
            if (($key['kid'] ?? '') === $kid && ! empty($key['x5c'][0])) {
                return "-----BEGIN CERTIFICATE-----\n" . chunk_split((string) $key['x5c'][0], 64, "\n") . "-----END CERTIFICATE-----\n";
            }
        }
        return new WP_Error('ssf_m365_signing_key_missing', 'signing_key_missing');
    }

    private function discovery_metadata(bool $cache = true)
    {
        $key = 'ssf_m365_login_discovery_' . md5($this->config('tenant_id'));
        if ($cache) {
            $cached = get_transient($key);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $response = wp_remote_get($this->authority_url('/v2.0/.well-known/openid-configuration'), array('timeout' => 20));
        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            return new WP_Error('ssf_m365_discovery_failed', 'discovery_failed');
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (! is_array($data) || empty($data['jwks_uri'])) {
            return new WP_Error('ssf_m365_discovery_invalid', 'discovery_invalid');
        }
        set_transient($key, $data, 12 * HOUR_IN_SECONDS);
        return $data;
    }

    private function jwks(bool $cache = true)
    {
        $key = 'ssf_m365_login_jwks_' . md5($this->config('tenant_id'));
        if ($cache) {
            $cached = get_transient($key);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $metadata = $this->discovery_metadata($cache);
        if (is_wp_error($metadata)) {
            return $metadata;
        }
        $response = wp_remote_get((string) $metadata['jwks_uri'], array('timeout' => 20));
        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            return new WP_Error('ssf_m365_jwks_failed', 'jwks_failed');
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (! is_array($data) || empty($data['keys'])) {
            return new WP_Error('ssf_m365_jwks_invalid', 'jwks_invalid');
        }
        set_transient($key, $data, 12 * HOUR_IN_SECONDS);
        return $data;
    }

    private function users_for_identity(string $tid, string $oid): array
    {
        return get_users(array(
            'number' => 2,
            'fields' => 'all',
            'meta_query' => array(
                'relation' => 'AND',
                array('key' => self::META_TID, 'value' => $tid, 'compare' => '='),
                array('key' => self::META_OID, 'value' => $oid, 'compare' => '='),
            ),
        ));
    }

    private function status(): array
    {
        $metadata = $this->is_configured() ? $this->discovery_metadata() : new WP_Error('not_configured', 'not_configured');
        $jwks = ! is_wp_error($metadata) ? $this->jwks() : new WP_Error('not_configured', 'not_configured');
        return array(
            'environment' => wp_get_environment_type(),
            'enabled' => $this->is_enabled(),
            'tenant_id' => '' !== $this->config('tenant_id'),
            'client_id' => '' !== $this->config('client_id'),
            'client_secret' => '' !== $this->config('client_secret'),
            'metadata' => is_wp_error($metadata) ? 'FAIL' : 'PASS',
            'jwks' => is_wp_error($jwks) ? 'FAIL' : 'PASS',
        );
    }

    private function is_enabled(): bool
    {
        return 'development' === wp_get_environment_type() && $this->truthy($this->config('enabled')) && $this->is_configured();
    }

    private function is_configured(): bool
    {
        return '' !== $this->config('tenant_id') && '' !== $this->config('client_id') && '' !== $this->config('client_secret');
    }

    private function config(string $key): string
    {
        $map = array(
            'enabled' => 'SSF_M365_LOGIN_ENABLED',
            'tenant_id' => 'SSF_M365_LOGIN_TENANT_ID',
            'client_id' => 'SSF_M365_LOGIN_CLIENT_ID',
            'client_secret' => 'SSF_M365_LOGIN_CLIENT_SECRET',
        );
        $constant = $map[$key] ?? '';
        if (! $constant) {
            return '';
        }
        $value = defined($constant) ? constant($constant) : getenv($constant);
        return is_string($value) ? trim($value) : (is_bool($value) ? ($value ? 'true' : 'false') : '');
    }

    private function authority_url(string $path): string
    {
        return 'https://login.microsoftonline.com/' . rawurlencode($this->config('tenant_id')) . $path;
    }

    private function callback_url(): string
    {
        return home_url('/' . self::CALLBACK_PATH);
    }

    private function safe_redirect(string $redirect_to): string
    {
        $fallback = admin_url('/');
        return wp_validate_redirect($redirect_to, $fallback);
    }

    private function deny(string $message): void
    {
        wp_die(esc_html($message), esc_html__('Microsoft 365-inloggning', 'ssf-microsoft-login'), array('response' => 403));
    }

    private function set_notice(int $user_id, string $type, string $message): void
    {
        set_transient(self::NOTICE_PREFIX . $user_id, array('type' => $type, 'message' => $message), MINUTE_IN_SECONDS);
    }

    private function truthy(string $value): bool
    {
        return in_array(strtolower($value), array('1', 'true', 'yes', 'on'), true);
    }

    private function random_urlsafe(int $bytes): string
    {
        return $this->base64url(random_bytes($bytes));
    }

    private function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64url_decode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    }
}

register_activation_hook(__FILE__, array('SSF_Microsoft_Login', 'activate'));
register_deactivation_hook(__FILE__, array('SSF_Microsoft_Login', 'deactivate'));

SSF_Microsoft_Login::instance();
