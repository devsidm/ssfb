<?php
/**
 * Plugin Name: Microsoft ID Login
 * Plugin URI: https://github.com/devsidm/ssfb
 * Description: Microsoft Entra ID login for SSF WordPress accounts.
 * Version: 0.3.5
 * Author: SIDM
 * Text Domain: microsoft-id-login
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package SSF_Microsoft_ID_Login
 */

if (! defined('ABSPATH')) {
    exit;
}

final class SSF_Microsoft_ID_Login
{
    private const VERSION = '0.3.5';
    private const STATE_PREFIX = 'ssf_m365_login_state_';
    private const NOTICE_PREFIX = 'ssf_m365_login_notice_';
    private const TEST_PREFIX = 'ssf_m365_login_test_';
    private const MENU_SLUG = 'microsoft-id-login';
    private const META_TID = '_ssf_m365_tid';
    private const META_OID = '_ssf_m365_oid';
    private const META_EMAIL = '_ssf_m365_email';
    private const META_LAST_LOGIN = '_ssf_m365_last_login';
    private const SETTINGS_OPTION = 'ssf_microsoft_login_settings';
    private const INVITATIONS_OPTION = 'ssf_microsoft_login_invitations';
    private const TEST_STATUS_OPTION = 'microsoft_id_login_test_status';
    private const CAP_MANAGE_LOGIN = 'ssf_manage_microsoft_login';
    private const CAP_MANAGE_PERMISSIONS = 'ssf_manage_permission_groups';
    private const QUERY_VAR = 'ssf_m365_login_callback';
    private const CALLBACK_PATH = 'ssf-auth/microsoft/callback/';
    private const INVITATION_TTL = 7 * DAY_IN_SECONDS;

    private static ?SSF_Microsoft_ID_Login $instance = null;

    public static function instance(): SSF_Microsoft_ID_Login
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function activate(): void
    {
        self::instance()->register_rewrite();
        self::ensure_capabilities();
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

    public static function ensure_capabilities(): void
    {
        $administrator = get_role('administrator');
        if ($administrator) {
            $administrator->add_cap(self::CAP_MANAGE_LOGIN);
            $administrator->add_cap(self::CAP_MANAGE_PERMISSIONS);
        }
    }

    public static function permission_groups(): array
    {
        return class_exists('SSF_Access_Control') ? SSF_Access_Control::groups() : array();
    }

    private function user_groups(int $user_id): array
    {
        return class_exists('SSF_Access_Control') ? SSF_Access_Control::user_groups($user_id) : array();
    }

    private function save_user_groups(int $target_user_id, array $groups, int $actor_user_id): void
    {
        if (class_exists('SSF_Access_Control')) {
            SSF_Access_Control::save_groups($target_user_id, $groups, $actor_user_id);
        }
    }

    private function can_manage_login(): bool
    {
        return current_user_can(self::CAP_MANAGE_LOGIN) || current_user_can('manage_options');
    }

    private function can_manage_permission_groups(): bool
    {
        return class_exists('SSF_Access_Control') && SSF_Access_Control::can_manage_users();
    }

    private function __construct()
    {
        add_action('init', array($this, 'register_rewrite'));
        add_filter('query_vars', array($this, 'query_vars'));
        add_action('template_redirect', array($this, 'maybe_handle_callback'));
        add_action('login_enqueue_scripts', array($this, 'enqueue_login_assets'));
        add_filter('login_message', array($this, 'render_login_message'));
        add_action('login_form', array($this, 'render_login_button'));
        add_action('admin_menu', array($this, 'register_admin_page'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('admin_notices', array($this, 'render_notices'));
        add_action('show_user_profile', array($this, 'render_profile_connection'));
        add_action('edit_user_profile', array($this, 'render_profile_connection'));
        add_action('admin_post_ssf_m365_login_start', array($this, 'start_login'));
        add_action('admin_post_nopriv_ssf_m365_login_start', array($this, 'start_login'));
        add_action('admin_post_ssf_m365_link_start', array($this, 'start_link'));
        add_action('admin_post_ssf_m365_unlink', array($this, 'unlink_account'));
        add_action('admin_post_ssf_m365_admin_unlink', array($this, 'admin_unlink_account'));
        add_action('admin_post_ssf_m365_save_settings', array($this, 'save_settings'));
        add_action('admin_post_ssf_m365_test_config', array($this, 'test_configuration'));
        add_action('admin_post_ssf_m365_test_login', array($this, 'start_real_login_test'));
        add_action('admin_post_ssf_m365_create_invitation', array($this, 'create_invitation'));
        add_action('admin_post_ssf_m365_resend_invitation', array($this, 'resend_invitation'));
        add_action('admin_post_ssf_m365_cancel_invitation', array($this, 'cancel_invitation'));
        add_action('admin_post_nopriv_ssf_m365_invite_activate', array($this, 'render_invitation_activation'));
        add_action('admin_post_ssf_m365_invite_activate', array($this, 'render_invitation_activation'));
        add_action('admin_post_nopriv_ssf_m365_invite_start', array($this, 'start_invitation_activation'));
        add_action('admin_post_ssf_m365_invite_start', array($this, 'start_invitation_activation'));
        add_action('admin_post_ssf_save_permission_groups', array($this, 'save_permission_groups'));
        add_action('init', array(__CLASS__, 'ensure_capabilities'), 6);
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
            add_submenu_page(null, __('Microsoft-inloggning', 'microsoft-id-login'), __('Microsoft-inloggning', 'microsoft-id-login'), self::CAP_MANAGE_LOGIN, self::MENU_SLUG, array($this, 'render_admin_page'));
            return;
        }
        add_management_page(__('Microsoft ID Login', 'microsoft-id-login'), __('Microsoft ID Login', 'microsoft-id-login'), self::CAP_MANAGE_LOGIN, self::MENU_SLUG, array($this, 'render_admin_page'));
    }

    public function enqueue_admin_assets(string $hook): void
    {
        if (false === strpos($hook, self::MENU_SLUG)) {
            return;
        }
        wp_enqueue_script('microsoft-id-login-admin', plugins_url('assets/js/admin.js', __FILE__), array(), self::VERSION, true);
    }

    public function enqueue_login_assets(): void
    {
        wp_enqueue_style('microsoft-id-login-ssf', plugins_url('assets/css/ssf-account.css', __FILE__), array(), self::VERSION);
    }

    public function render_login_message(string $message): string
    {
        if (! $this->is_enabled()) {
            return $message;
        }
        $redirect_to = isset($_REQUEST['redirect_to']) && is_scalar($_REQUEST['redirect_to']) ? esc_url_raw(wp_unslash($_REQUEST['redirect_to'])) : '';
        ob_start();
        ?>
        <section class="ssf-account-login" aria-labelledby="ssf-account-login-title">
            <div class="ssf-account-logo"><?php echo wp_kses_post($this->logo_markup()); ?></div>
            <h1 id="ssf-account-login-title"><?php esc_html_e('Välkommen till SSF', 'microsoft-id-login'); ?></h1>
            <p><?php esc_html_e('Logga in för att komma åt SSF:s administrativa funktioner.', 'microsoft-id-login'); ?></p>
            <p><a class="button button-primary button-large ssf-account-primary" href="<?php echo esc_url(self::login_url($redirect_to)); ?>"><?php esc_html_e('Logga in med ditt SSF-konto', 'microsoft-id-login'); ?></a></p>
            <p class="ssf-account-domain"><?php esc_html_e('Använd ditt @ssfb.se-konto', 'microsoft-id-login'); ?></p>
            <p class="ssf-account-help"><?php esc_html_e('Inloggningen hanteras säkert av Microsoft.', 'microsoft-id-login'); ?></p>
            <div class="ssf-account-reserve">
                <strong><?php esc_html_e('Administratör / reservinloggning', 'microsoft-id-login'); ?></strong>
                <span><?php esc_html_e('Logga in med WordPress', 'microsoft-id-login'); ?></span>
            </div>
        </section>
        <?php
        return ob_get_clean() . $message;
    }

    public function render_admin_page(): void
    {
        if (! $this->can_manage_login()) {
            return;
        }
        if (class_exists('SSF_Microsoft365_Config')) {
            wp_safe_redirect(add_query_arg(array('page' => 'ssf-member-portal-microsoft365', 'm365_tab' => 'login'), admin_url('admin.php')));
            exit;
        }
        $status = $this->status();
        $linked_count = $this->linked_user_count();
        $pending_count = $this->pending_invitation_count();
        $connection_test = get_transient(self::TEST_PREFIX . 'config_' . get_current_user_id());
        $login_test = get_transient(self::TEST_PREFIX . 'login_' . get_current_user_id());
        ?>
        <div class="wrap microsoft-id-login-admin">
            <h1><?php esc_html_e('Microsoft ID Login', 'microsoft-id-login'); ?></h1>
            <p class="description"><?php esc_html_e('Microsoft 365 / Entra ID-inloggning', 'microsoft-id-login'); ?></p>
            <?php if (class_exists('SSF_Admin_Navigation')) { SSF_Admin_Navigation::render_system_tabs(self::MENU_SLUG); } ?>
            <p><?php esc_html_e('Microsoft 365 används för att verifiera vem användaren är. Behörigheter till SSF:s funktioner styrs i WordPress.', 'microsoft-id-login'); ?></p>

            <div class="ssf-admin-grid ssf-admin-grid--system">
                <section class="ssf-admin-card">
                    <h2><?php esc_html_e('Översikt', 'microsoft-id-login'); ?></h2>
                    <dl>
                        <div><dt><?php esc_html_e('Miljö', 'microsoft-id-login'); ?></dt><dd><?php echo esc_html(ucfirst($status['environment'])); ?></dd></div>
                        <div><dt><?php esc_html_e('Status', 'microsoft-id-login'); ?></dt><dd><?php echo esc_html($this->status_label($status)); ?></dd></div>
                        <div><dt><?php esc_html_e('Microsoft-app', 'microsoft-id-login'); ?></dt><dd><?php echo esc_html($this->configured_label($status['tenant_id'] && $status['client_id'] && $status['client_secret'])); ?></dd></div>
                        <div><dt><?php esc_html_e('OpenID', 'microsoft-id-login'); ?></dt><dd><?php echo esc_html($status['metadata']); ?></dd></div>
                        <div><dt><?php esc_html_e('Callback', 'microsoft-id-login'); ?></dt><dd><?php echo esc_html($status['callback']); ?></dd></div>
                        <div><dt><?php esc_html_e('Kopplade användare', 'microsoft-id-login'); ?></dt><dd><?php echo esc_html((string) $linked_count); ?></dd></div>
                        <div><dt><?php esc_html_e('Väntande inbjudningar', 'microsoft-id-login'); ?></dt><dd><?php echo esc_html((string) $pending_count); ?></dd></div>
                    </dl>
                    <p><a class="button button-primary" href="#ssf-m365-create-user"><?php esc_html_e('Lägg till SSF-användare', 'microsoft-id-login'); ?></a></p>
                    <p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ssf_m365_test_config'), 'ssf_m365_test_config')); ?>"><?php esc_html_e('Testa Microsoft-konfiguration', 'microsoft-id-login'); ?></a></p>
                    <p><a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ssf_m365_test_login'), 'ssf_m365_test_login')); ?>"><?php esc_html_e('Testa riktig Microsoft-inloggning', 'microsoft-id-login'); ?></a></p>
                </section>
                <?php $this->render_settings_card($status); ?>
                <section class="ssf-admin-card">
                    <h2><?php esc_html_e('Ditt Microsoft-konto', 'microsoft-id-login'); ?></h2>
                    <?php $this->render_own_account_card(); ?>
                </section>
            </div>

            <?php $this->render_test_results($connection_test, $login_test); ?>
            <?php $this->render_technical_details($status, $linked_count); ?>
        </div>
        <?php
    }

    public function render_login_button(): void
    {
        if (! $this->is_enabled()) {
            return;
        }
        $redirect_to = isset($_REQUEST['redirect_to']) && is_scalar($_REQUEST['redirect_to']) ? esc_url_raw(wp_unslash($_REQUEST['redirect_to'])) : '';
        echo '<p class="ssf-account-inline-login"><a class="button button-secondary button-large" href="' . esc_url(self::login_url($redirect_to)) . '">' . esc_html__('Logga in med ditt SSF-konto', 'microsoft-id-login') . '</a></p>';
    }

    private function render_settings_card(array $status): void
    {
        $settings = $this->settings();
        $active_profile = $this->active_profile_key();
        $effective = $this->public_configuration_status();
        $force_off = $this->is_force_disabled();
        $this->ensure_central_config_loaded();
        $central_tenant_configured = class_exists('SSF_Microsoft365_Config') && SSF_Microsoft365_Config::is_tenant_configured();
        $legacy_tenant_warnings = $this->legacy_tenant_warnings();
        ?>
        <section id="microsoft-login" class="ssf-admin-card">
            <h2><?php esc_html_e('Microsoft / Entra', 'microsoft-id-login'); ?></h2>
            <?php if (class_exists('SSF_Admin_Feedback')) { SSF_Admin_Feedback::render_inline('microsoft-login'); } ?>
            <p><?php esc_html_e('Konfigurera Microsoft-appen för den aktiva WordPress-installationen. Microsoft-kontot används för identitet; behörigheter styrs i WordPress.', 'microsoft-id-login'); ?></p>
            <?php if ($force_off) : ?><div class="notice notice-warning inline"><p><strong><?php esc_html_e('Avstängd av serverkonfiguration', 'microsoft-id-login'); ?></strong></p></div><?php endif; ?>
            <?php if ('server' === $effective['client_id_source']) : ?><div class="notice notice-warning inline"><p><strong><?php esc_html_e('Serverkonfiguration överstyr WordPress', 'microsoft-id-login'); ?></strong><br><code>SSF_M365_LOGIN_CLIENT_ID</code></p></div><?php endif; ?>
            <?php if ('server' === $effective['client_secret_source']) : ?><div class="notice notice-warning inline"><p><strong><?php esc_html_e('Serverkonfiguration överstyr WordPress', 'microsoft-id-login'); ?></strong><br><code>SSF_M365_LOGIN_CLIENT_SECRET</code></p></div><?php endif; ?>
            <?php if (class_exists('SSF_Microsoft365_Config')) : ?>
                <div class="notice notice-info inline"><p><strong><?php esc_html_e('Tenant', 'microsoft-id-login'); ?></strong><br><?php esc_html_e('Central Microsoft 365 configuration', 'microsoft-id-login'); ?><br><?php echo esc_html(SSF_Microsoft365_Config::get_organisation_name()); ?><br><?php echo esc_html(SSF_Microsoft365_Config::get_primary_domain()); ?><br><?php echo esc_html($central_tenant_configured ? 'CONFIGURED' : 'MISSING'); ?></p><p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ssf-member-portal-microsoft365')); ?>">Hantera Microsoft 365-inställningar</a></p></div>
            <?php else : ?>
                <div class="notice notice-error inline"><p><strong><?php esc_html_e('Tenant', 'microsoft-id-login'); ?></strong><br><?php esc_html_e('Central Microsoft 365 configuration', 'microsoft-id-login'); ?><br><?php echo esc_html('MISSING'); ?></p></div>
            <?php endif; ?>
            <?php foreach ($legacy_tenant_warnings as $warning) : ?><div class="notice notice-warning inline"><p><?php echo esc_html($warning); ?></p></div><?php endforeach; ?>
            <dl>
                <div><dt><?php esc_html_e('Aktuell WordPress-installation', 'microsoft-id-login'); ?></dt><dd><?php echo esc_html($active_profile); ?></dd></div>
                <div><dt><?php esc_html_e('Central Microsoft 365 configuration', 'microsoft-id-login'); ?></dt><dd><?php echo esc_html($central_tenant_configured ? 'CONFIGURED' : 'MISSING'); ?></dd></div>
                <div><dt><?php esc_html_e('Client ID', 'microsoft-id-login'); ?></dt><dd><?php echo esc_html($this->configured_label($status['client_id'])); ?></dd></div>
                <div><dt><?php esc_html_e('Client Secret', 'microsoft-id-login'); ?></dt><dd><?php echo esc_html($this->configured_label($status['client_secret'])); ?></dd></div>
                <div><dt><?php esc_html_e('Effective status', 'microsoft-id-login'); ?></dt><dd><?php echo esc_html($this->status_label($status)); ?></dd></div>
                <div><dt><?php esc_html_e('Kontotyp', 'microsoft-id-login'); ?></dt><dd><?php esc_html_e('Endast konton i SSF:s organisation', 'microsoft-id-login'); ?></dd></div>
                <div><dt><?php esc_html_e('Scopes', 'microsoft-id-login'); ?></dt><dd><code>openid profile email</code></dd></div>
                <div><dt><?php esc_html_e('SharePoint permissions', 'microsoft-id-login'); ?></dt><dd><?php esc_html_e('Inga', 'microsoft-id-login'); ?></dd></div>
                <div><dt><?php esc_html_e('Graph application permissions', 'microsoft-id-login'); ?></dt><dd><?php esc_html_e('Inga', 'microsoft-id-login'); ?></dd></div>
            </dl>
            <p><label for="ssf-m365-callback"><strong><?php esc_html_e('Redirect URI', 'microsoft-id-login'); ?></strong></label><br><input id="ssf-m365-callback" class="regular-text code" value="<?php echo esc_attr($this->callback_url()); ?>" readonly> <button type="button" class="button" data-ssf-copy="#ssf-m365-callback"><?php esc_html_e('Kopiera callback-URL', 'microsoft-id-login'); ?></button></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ssf_m365_save_settings">
                <?php wp_nonce_field('ssf_m365_save_settings'); ?>
                <?php $profile_key = $active_profile; $profile = $settings['profiles'][$profile_key]; ?>
                    <fieldset style="border:1px solid #dcdcde;padding:12px;margin:12px 0;">
                        <legend><strong><?php esc_html_e('Inloggningsapp för denna installation', 'microsoft-id-login'); ?></strong></legend>
                        <p><label><?php if ($force_off) : ?><input type="hidden" name="profiles[<?php echo esc_attr($profile_key); ?>][enabled]" value="<?php echo ! empty($profile['enabled']) ? '1' : '0'; ?>"><?php endif; ?><input type="checkbox" name="profiles[<?php echo esc_attr($profile_key); ?>][enabled]" value="1" <?php checked(! $force_off && ! empty($profile['enabled'])); ?> <?php disabled($force_off); ?>> <?php esc_html_e('Aktivera Microsoft-login', 'microsoft-id-login'); ?></label></p>
                        <p><label><?php esc_html_e('Application ID / Client ID', 'microsoft-id-login'); ?><br><input class="regular-text code" name="profiles[<?php echo esc_attr($profile_key); ?>][client_id]" value="<?php echo esc_attr((string) $profile['client_id']); ?>" autocomplete="off"></label></p>
                        <p><label><?php esc_html_e('Client Secret', 'microsoft-id-login'); ?><br><input class="regular-text code" type="password" name="profiles[<?php echo esc_attr($profile_key); ?>][client_secret]" value="" autocomplete="new-password" placeholder="<?php echo esc_attr(! empty($profile['client_secret']) ? __('Secret finns - lämna tomt för att behålla', 'microsoft-id-login') : __('Saknas', 'microsoft-id-login')); ?>"></label></p>
                        <p><label><input type="checkbox" name="profiles[<?php echo esc_attr($profile_key); ?>][clear_secret]" value="1"> <?php esc_html_e('Ta bort sparad client secret', 'microsoft-id-login'); ?></label></p>
                    </fieldset>
                <?php submit_button(__('Spara Microsoft-konfiguration', 'microsoft-id-login'), 'primary', 'submit', false); ?>
            </form>
        </section>
        <?php
    }

    private function render_own_account_card(): void
    {
        $user_id = get_current_user_id();
        $linked = $this->is_user_linked($user_id);
        ?>
        <p><strong><?php esc_html_e('Status:', 'microsoft-id-login'); ?></strong> <?php echo esc_html($linked ? __('Kopplat', 'microsoft-id-login') : __('Inte kopplat', 'microsoft-id-login')); ?></p>
        <?php if ($linked) : ?>
            <p><strong><?php esc_html_e('Konto:', 'microsoft-id-login'); ?></strong> <?php echo esc_html($this->linked_email_label($user_id)); ?></p>
            <p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ssf_m365_test_login'), 'ssf_m365_test_login')); ?>"><?php esc_html_e('Testa inloggning', 'microsoft-id-login'); ?></a></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ssf_m365_unlink">
                <?php wp_nonce_field('ssf_m365_unlink'); ?>
                <?php submit_button(__('Koppla från konto', 'microsoft-id-login'), 'secondary', 'submit', false); ?>
            </form>
        <?php else : ?>
            <p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ssf_m365_link_start'), 'ssf_m365_link_start')); ?>"><?php esc_html_e('Koppla Microsoft 365-konto', 'microsoft-id-login'); ?></a></p>
        <?php endif; ?>
        <?php
    }

    private function render_test_results($connection_test, $login_test): void
    {
        if (is_array($connection_test)) {
            echo '<h2>' . esc_html__('Tekniskt konfigurationstest', 'microsoft-id-login') . '</h2>';
            echo '<p class="description">' . esc_html__('Vad kontrollerades: lokal WordPress-miljö, aktivering, Microsoft-appens grundvärden, OpenID discovery, signeringsnycklar, callback-URL och att inga Graph-/SharePoint-behörigheter används.', 'microsoft-id-login') . '</p>';
            $this->render_check_table($connection_test);
        }
        if (is_array($login_test)) {
            echo '<h2>' . esc_html__('Riktigt Microsoft-inloggningstest', 'microsoft-id-login') . '</h2>';
            echo '<p class="description">' . esc_html__('Vad kontrollerades: Microsofts riktiga inloggningsflöde, state/nonce/PKCE, ID-token, tenant, kopplat WordPress-konto och att WordPress-behörigheter inte ändras.', 'microsoft-id-login') . '</p>';
            $this->render_check_table($login_test);
        }
    }

    private function render_check_table(array $checks): void
    {
        $passed = $this->checks_passed($checks);
        echo '<p><strong>' . esc_html__('Samlat resultat:', 'microsoft-id-login') . '</strong> ' . esc_html($passed ? 'PASS' : 'FAIL') . '</p>';
        echo '<table class="widefat striped ssf-m365-test-result" style="max-width:1100px"><thead><tr><th>' . esc_html__('Kontroll', 'microsoft-id-login') . '</th><th>' . esc_html__('Resultat', 'microsoft-id-login') . '</th><th>' . esc_html__('Detalj', 'microsoft-id-login') . '</th><th>' . esc_html__('Åtgärd vid fel', 'microsoft-id-login') . '</th></tr></thead><tbody>';
        foreach ($checks as $label => $result) {
            $check = $this->normalize_check_result($result);
            printf(
                '<tr><th>%s</th><td><strong>%s</strong></td><td>%s</td><td>%s</td></tr>',
                esc_html((string) $label),
                esc_html($check['passed'] ? 'PASS' : 'FAIL'),
                esc_html($check['detail']),
                esc_html($check['action'])
            );
        }
        echo '</tbody></table>';
    }

    private function normalize_check_result($result): array
    {
        if (is_array($result)) {
            return array(
                'passed' => ! empty($result['passed']),
                'detail' => isset($result['detail']) ? (string) $result['detail'] : '',
                'action' => isset($result['action']) ? (string) $result['action'] : '',
            );
        }
        return array(
            'passed' => ! empty($result),
            'detail' => ! empty($result) ? __('Kontrollen passerade.', 'microsoft-id-login') : __('Kontrollen misslyckades.', 'microsoft-id-login'),
            'action' => '',
        );
    }

    private function checks_passed(array $checks): bool
    {
        foreach ($checks as $result) {
            if (! $this->normalize_check_result($result)['passed']) {
                return false;
            }
        }
        return true;
    }

    private function render_users_and_permissions(): void
    {
        if (! $this->can_manage_permission_groups()) {
            return;
        }
        $filter = isset($_GET['ssf_m365_filter']) ? sanitize_key(wp_unslash($_GET['ssf_m365_filter'])) : 'all';
        $users = get_users(array('number' => 200, 'orderby' => 'display_name', 'order' => 'ASC'));
        $groups = self::permission_groups();
        ?>
        <div id="microsoft-users">
        <?php if (class_exists('SSF_Admin_Feedback')) { SSF_Admin_Feedback::render_inline('microsoft-users'); } ?>
        <h2><?php esc_html_e('Användare', 'microsoft-id-login'); ?></h2>
        <?php $this->render_invitation_form($groups); ?>
        <h2><?php esc_html_e('Användare och behörigheter', 'microsoft-id-login'); ?></h2>
        <p><a href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)); ?>"><?php esc_html_e('Alla användare', 'microsoft-id-login'); ?></a> | <a href="<?php echo esc_url(add_query_arg('ssf_m365_filter', 'linked', admin_url('admin.php?page=' . self::MENU_SLUG))); ?>"><?php esc_html_e('Kopplade användare', 'microsoft-id-login'); ?></a> | <a href="<?php echo esc_url(add_query_arg('ssf_m365_filter', 'unlinked', admin_url('admin.php?page=' . self::MENU_SLUG))); ?>"><?php esc_html_e('Ej kopplade användare', 'microsoft-id-login'); ?></a></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="ssf_save_permission_groups">
            <?php wp_nonce_field('ssf_save_permission_groups'); ?>
            <table class="widefat striped">
                <thead><tr><th><?php esc_html_e('Namn', 'microsoft-id-login'); ?></th><th><?php esc_html_e('SSF-konto', 'microsoft-id-login'); ?></th><th><?php esc_html_e('Microsoft', 'microsoft-id-login'); ?></th><th><?php esc_html_e('Behörighetsgrupper', 'microsoft-id-login'); ?></th><th><?php esc_html_e('Status', 'microsoft-id-login'); ?></th><th><?php esc_html_e('Åtgärder', 'microsoft-id-login'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($users as $user) : $linked = $this->is_user_linked((int) $user->ID); if ('linked' === $filter && ! $linked) { continue; } if ('unlinked' === $filter && $linked) { continue; } ?>
                    <?php $invitation = $this->latest_invitation_for_user((int) $user->ID); ?>
                    <tr>
                        <td><input type="hidden" name="user_ids[]" value="<?php echo esc_attr((string) $user->ID); ?>"><?php echo esc_html($user->display_name); ?><br><span class="description"><?php echo esc_html($user->user_login); ?></span></td>
                        <td><?php echo esc_html($user->user_email); ?></td>
                        <td><?php echo esc_html($linked ? __('Kopplad', 'microsoft-id-login') : __('Inte kopplad', 'microsoft-id-login')); ?><?php if ($linked) : ?><br><span class="description"><?php echo esc_html($this->linked_email_label((int) $user->ID)); ?></span><?php endif; ?></td>
                        <td><?php foreach ($groups as $key => $group) : ?><label style="display:block"><input type="checkbox" name="groups[<?php echo esc_attr((string) $user->ID); ?>][]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $this->user_groups((int) $user->ID), true)); ?> <?php disabled((int) $user->ID === get_current_user_id() && ! current_user_can('manage_options')); ?>> <?php echo esc_html($group['label']); ?></label><?php endforeach; ?></td>
                        <td><?php echo esc_html($this->user_status_label((int) $user->ID, $linked, $invitation)); ?></td>
                        <td>
                            <?php if ($linked) : ?><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ssf_m365_admin_unlink&user_id=' . (int) $user->ID), 'ssf_m365_admin_unlink_' . (int) $user->ID)); ?>"><?php esc_html_e('Koppla från', 'microsoft-id-login'); ?></a><?php endif; ?>
                            <?php if (! $linked) : ?>
                                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ssf_m365_resend_invitation&user_id=' . (int) $user->ID), 'ssf_m365_resend_invitation_' . (int) $user->ID)); ?>"><?php esc_html_e('Skicka inbjudan igen', 'microsoft-id-login'); ?></a>
                            <?php endif; ?>
                            <?php if ($invitation && empty($invitation['used_at']) && empty($invitation['canceled_at'])) : ?>
                                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ssf_m365_cancel_invitation&invite_id=' . rawurlencode((string) $invitation['id'])), 'ssf_m365_cancel_invitation_' . (string) $invitation['id'])); ?>"><?php esc_html_e('Avbryt inbjudan', 'microsoft-id-login'); ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php submit_button(__('Spara behörigheter', 'microsoft-id-login')); ?>
        </form></div>
        <?php
    }

    private function render_invitation_form(array $groups): void
    {
        ?>
        <section id="ssf-m365-create-user" class="ssf-admin-card" style="max-width:900px">
            <h3><?php esc_html_e('Lägg till SSF-användare', 'microsoft-id-login'); ?></h3>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ssf_m365_create_invitation">
                <?php wp_nonce_field('ssf_m365_create_invitation'); ?>
                <p><label><?php esc_html_e('Namn', 'microsoft-id-login'); ?><br><input class="regular-text" name="display_name" required autocomplete="name" placeholder="<?php esc_attr_e('Anna Andersson', 'microsoft-id-login'); ?>"></label></p>
                <p><label><?php esc_html_e('SSF-konto / e-post', 'microsoft-id-login'); ?><br><input class="regular-text" type="email" name="email" required autocomplete="email" placeholder="<?php esc_attr_e('anna.andersson@ssfb.se', 'microsoft-id-login'); ?>"></label></p>
                <fieldset>
                    <legend><strong><?php esc_html_e('Behörighetsgrupper', 'microsoft-id-login'); ?></strong></legend>
                    <?php foreach ($groups as $key => $group) : ?>
                        <label style="display:block"><input type="checkbox" name="groups[]" value="<?php echo esc_attr($key); ?>"> <?php echo esc_html($group['label']); ?></label>
                    <?php endforeach; ?>
                </fieldset>
                <?php submit_button(__('Skapa och skicka inbjudan', 'microsoft-id-login'), 'primary', 'submit', false); ?>
            </form>
        </section>
        <?php
    }

    private function render_permission_matrix(): void
    {
        echo '<div id="microsoft-permissions"><h2>' . esc_html__('Behörighetsmatris', 'microsoft-id-login') . '</h2>';
        echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>' . esc_html__('Grupp', 'microsoft-id-login') . '</th><th>' . esc_html__('Beskrivning', 'microsoft-id-login') . '</th><th>' . esc_html__('Faktiska WordPress-capabilities', 'microsoft-id-login') . '</th></tr></thead><tbody>';
        foreach (self::permission_groups() as $group) {
            printf('<tr><th>%s</th><td>%s</td><td><code>%s</code></td></tr>', esc_html($group['label']), esc_html($group['description']), esc_html(implode(', ', (array) $group['capabilities'])));
        }
        echo '</tbody></table></div>';
    }

    private function render_technical_details(array $status, int $linked_count): void
    {
        $test_status = get_option(self::TEST_STATUS_OPTION, array());
        $test_status = is_array($test_status) ? $test_status : array();
        ?>
        <details>
            <summary><?php esc_html_e('Tekniska detaljer', 'microsoft-id-login'); ?></summary>
            <table class="widefat striped" style="max-width:900px"><tbody>
                <tr><th><?php esc_html_e('Environment', 'microsoft-id-login'); ?></th><td><?php echo esc_html($status['environment']); ?></td></tr>
                <tr><th><?php esc_html_e('Plugin version', 'microsoft-id-login'); ?></th><td><?php echo esc_html(self::VERSION); ?></td></tr>
                <tr><th><?php esc_html_e('Callback URL', 'microsoft-id-login'); ?></th><td><code><?php echo esc_html($this->callback_url()); ?></code></td></tr>
                <tr><th><?php esc_html_e('OpenID issuer', 'microsoft-id-login'); ?></th><td><code><?php echo esc_html($this->config('tenant_id') ? $this->expected_issuer() : ''); ?></code></td></tr>
                <tr><th><?php esc_html_e('Tenant configured', 'microsoft-id-login'); ?></th><td><?php echo esc_html($this->configured_label($status['tenant_id'])); ?></td></tr>
                <tr><th><?php esc_html_e('Client configured', 'microsoft-id-login'); ?></th><td><?php echo esc_html($this->configured_label($status['client_id'])); ?></td></tr>
                <tr><th><?php esc_html_e('JWKS cache status', 'microsoft-id-login'); ?></th><td><?php echo esc_html($status['jwks']); ?></td></tr>
                <tr><th><?php esc_html_e('Linked users', 'microsoft-id-login'); ?></th><td><?php echo esc_html((string) $linked_count); ?></td></tr>
                <tr><th><?php esc_html_e('Senaste konfigurationstest', 'microsoft-id-login'); ?></th><td><?php echo esc_html($this->format_test_status($test_status['technical'] ?? null)); ?></td></tr>
                <tr><th><?php esc_html_e('Senaste riktiga inloggningstest', 'microsoft-id-login'); ?></th><td><?php echo esc_html($this->format_test_status($test_status['real_login'] ?? null)); ?></td></tr>
            </tbody></table>
        </details>
        <?php
    }

    private function format_test_status($status): string
    {
        if (! is_array($status) || empty($status['result']) || empty($status['timestamp'])) {
            return __('Inte kört', 'microsoft-id-login');
        }
        return (string) $status['result'] . ' - ' . (string) $status['timestamp'];
    }

    private function status_label(array $status): string
    {
        if (! empty($status['force_off'])) {
            return __('Avstängd av serverkonfiguration', 'microsoft-id-login');
        }
        if (empty($status['admin_enabled'])) {
            return __('Avstängd av administratör', 'microsoft-id-login');
        }
        if (empty($status['configured']) || 'PASS' !== ($status['metadata'] ?? '') || 'PASS' !== ($status['callback'] ?? '')) {
            return __('Inte färdigkonfigurerad', 'microsoft-id-login');
        }
        return __('AKTIV', 'microsoft-id-login');
    }

    private function configured_label(bool $configured): string
    {
        return $configured ? __('Konfigurerad', 'microsoft-id-login') : __('Saknas', 'microsoft-id-login');
    }

    private function linked_user_count(): int
    {
        return count(get_users(array(
            'fields' => 'ids',
            'meta_query' => array(
                array('key' => self::META_TID, 'compare' => 'EXISTS'),
                array('key' => self::META_OID, 'compare' => 'EXISTS'),
            ),
        )));
    }

    private function is_user_linked(int $user_id): bool
    {
        return '' !== (string) get_user_meta($user_id, self::META_TID, true) && '' !== (string) get_user_meta($user_id, self::META_OID, true);
    }

    private function linked_email_label(int $user_id): string
    {
        $email = (string) get_user_meta($user_id, self::META_EMAIL, true);
        return '' !== $email ? $email : __('Microsoft-ID kopplat', 'microsoft-id-login');
    }

    public function render_profile_connection($user): void
    {
        if (! $user instanceof WP_User) {
            return;
        }
        $is_own_profile = (int) get_current_user_id() === (int) $user->ID;
        $linked = $this->is_user_linked((int) $user->ID);
        ?>
        <h2><?php esc_html_e('Microsoft 365-konto', 'microsoft-id-login'); ?></h2>
        <table class="form-table" role="presentation"><tr><th><?php esc_html_e('Status', 'microsoft-id-login'); ?></th><td>
            <p><?php echo esc_html($linked ? __('Microsoft 365-kontot är kopplat.', 'microsoft-id-login') : __('Inget Microsoft 365-konto är kopplat.', 'microsoft-id-login')); ?></p>
            <?php if ($is_own_profile && $this->is_enabled()) : ?>
            <?php if ($linked) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="ssf_m365_unlink">
                    <?php wp_nonce_field('ssf_m365_unlink'); ?>
                    <?php submit_button(__('Koppla från Microsoft 365', 'microsoft-id-login'), 'secondary', 'submit', false); ?>
                </form>
            <?php else : ?>
                <p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ssf_m365_link_start'), 'ssf_m365_link_start')); ?>"><?php esc_html_e('Koppla Microsoft 365-konto', 'microsoft-id-login'); ?></a></p>
            <?php endif; ?>
            <?php endif; ?>
        </td></tr>
        </table>
        <?php
    }

    public function start_login(): void
    {
        $this->start_authorization('login', 0, isset($_REQUEST['redirect_to']) && is_scalar($_REQUEST['redirect_to']) ? rawurldecode((string) wp_unslash($_REQUEST['redirect_to'])) : '');
    }

    public function start_link(): void
    {
        if (! is_user_logged_in() || ! check_admin_referer('ssf_m365_link_start')) {
            wp_die(esc_html__('Du måste vara inloggad för att koppla Microsoft 365-konto.', 'microsoft-id-login'));
        }
        $this->start_authorization('link', get_current_user_id(), admin_url('profile.php'));
    }

    public function unlink_account(): void
    {
        if (! is_user_logged_in() || ! check_admin_referer('ssf_m365_unlink')) {
            wp_die(esc_html__('Du måste vara inloggad för att koppla från Microsoft 365.', 'microsoft-id-login'));
        }
        $was_linked = $this->is_user_linked(get_current_user_id());
        delete_user_meta(get_current_user_id(), self::META_TID);
        delete_user_meta(get_current_user_id(), self::META_OID);
        delete_user_meta(get_current_user_id(), self::META_EMAIL);
        if ($was_linked && class_exists('SSF_Access_Control')) {
            SSF_Access_Control::audit_identity_event(get_current_user_id(), get_current_user_id(), 'microsoft_unlinked');
        }
        $this->set_notice(get_current_user_id(), 'success', __('Microsoft 365-kontot har kopplats från.', 'microsoft-id-login'));
        wp_safe_redirect(admin_url('profile.php'));
        exit;
    }

    public function admin_unlink_account(): void
    {
        $user_id = isset($_GET['user_id']) ? absint($_GET['user_id']) : 0;
        if (! $this->can_manage_login() || $user_id <= 0 || ! check_admin_referer('ssf_m365_admin_unlink_' . $user_id)) {
            wp_die(esc_html__('Du saknar behorighet.', 'microsoft-id-login'));
        }
        $was_linked = $this->is_user_linked($user_id);
        delete_user_meta($user_id, self::META_TID);
        delete_user_meta($user_id, self::META_OID);
        delete_user_meta($user_id, self::META_EMAIL);
        if ($was_linked && class_exists('SSF_Access_Control')) {
            SSF_Access_Control::audit_identity_event($user_id, get_current_user_id(), 'microsoft_unlinked');
        }
        $this->set_notice(get_current_user_id(), 'success', __('Microsoft 365-kopplingen har tagits bort. WordPress-behorigheter andrades inte.', 'microsoft-id-login'), 'accounts');
        wp_safe_redirect($this->admin_section_url('accounts'));
        exit;
    }

    public function start_real_login_test(): void
    {
        if (! is_user_logged_in() || ! $this->can_manage_login() || ! check_admin_referer('ssf_m365_test_login')) {
            wp_die(esc_html__('Du saknar behorighet.', 'microsoft-id-login'));
        }
        $this->start_authorization('test', get_current_user_id(), $this->admin_section_url('microsoft-login'));
    }

    public function create_invitation(): void
    {
        if (! $this->can_manage_permission_groups() || ! check_admin_referer('ssf_m365_create_invitation')) {
            wp_die(esc_html__('Du saknar behörighet.', 'microsoft-id-login'));
        }
        $email = isset($_POST['email']) && is_scalar($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $display_name = isset($_POST['display_name']) && is_scalar($_POST['display_name']) ? sanitize_text_field(wp_unslash($_POST['display_name'])) : '';
        $groups = isset($_POST['groups']) && is_array($_POST['groups']) ? array_map('sanitize_key', wp_unslash($_POST['groups'])) : array();
        if (! $this->is_ssf_email($email)) {
            $this->set_notice(get_current_user_id(), 'error', __('SSF-konto måste vara en @ssfb.se-adress.', 'microsoft-id-login'), 'microsoft-users');
            wp_safe_redirect($this->admin_section_url('microsoft-users'));
            exit;
        }
        $user_id = $this->find_or_create_user($email, $display_name);
        if (is_wp_error($user_id)) {
            $this->set_notice(get_current_user_id(), 'error', __('Användaren kunde inte skapas.', 'microsoft-id-login'), 'microsoft-users');
            wp_safe_redirect($this->admin_section_url('microsoft-users'));
            exit;
        }
        if (class_exists('SSF_Access_Control') && ! SSF_Access_Control::is_active((int) $user_id)) {
            $this->set_notice(get_current_user_id(), 'error', __('Återaktivera användaren innan en ny inbjudan skickas.', 'microsoft-id-login'), 'microsoft-users');
            wp_safe_redirect($this->admin_section_url('microsoft-users'));
            exit;
        }
        $this->save_user_groups((int) $user_id, $groups, get_current_user_id());
        $invitation = $this->issue_invitation((int) $user_id, $email);
        if (class_exists('SSF_Access_Control')) {
            SSF_Access_Control::audit_identity_event((int) $user_id, get_current_user_id(), 'invitation_created');
        }
        $sent = $this->send_invitation_email((int) $user_id, $invitation['raw_token']);
        $this->set_notice(get_current_user_id(), $sent ? 'success' : 'error', $sent ? __('SSF-användaren skapades och inbjudan skickades.', 'microsoft-id-login') : __('SSF-användaren skapades men inbjudningsmailet kunde inte skickas.', 'microsoft-id-login'), 'microsoft-users');
        wp_safe_redirect($this->admin_section_url('microsoft-users'));
        exit;
    }

    public function resend_invitation(): void
    {
        $user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
        if (! $this->can_manage_permission_groups() || $user_id <= 0 || ! check_admin_referer('ssf_m365_resend_invitation_' . $user_id)) {
            wp_die(esc_html__('Du saknar behörighet.', 'microsoft-id-login'));
        }
        $user = get_user_by('id', $user_id);
        if (! $user instanceof WP_User || ! $this->is_ssf_email((string) $user->user_email)) {
            $this->set_notice(get_current_user_id(), 'error', __('Inbjudan kan bara skickas till @ssfb.se-konton.', 'microsoft-id-login'), 'microsoft-users');
            wp_safe_redirect($this->admin_section_url('microsoft-users'));
            exit;
        }
        $this->invalidate_open_invitations($user_id);
        $invitation = $this->issue_invitation($user_id, (string) $user->user_email);
        if (class_exists('SSF_Access_Control')) {
            SSF_Access_Control::audit_identity_event($user_id, get_current_user_id(), 'invitation_resent');
        }
        $sent = $this->send_invitation_email($user_id, $invitation['raw_token']);
        $this->set_notice(get_current_user_id(), $sent ? 'success' : 'error', $sent ? __('Ny inbjudan har skickats.', 'microsoft-id-login') : __('Inbjudan kunde inte skickas.', 'microsoft-id-login'), 'microsoft-users');
        wp_safe_redirect($this->admin_section_url('microsoft-users'));
        exit;
    }

    public function cancel_invitation(): void
    {
        $invite_id = isset($_GET['invite_id']) && is_scalar($_GET['invite_id']) ? sanitize_key(wp_unslash($_GET['invite_id'])) : '';
        if (! $this->can_manage_permission_groups() || '' === $invite_id || ! check_admin_referer('ssf_m365_cancel_invitation_' . $invite_id)) {
            wp_die(esc_html__('Du saknar behörighet.', 'microsoft-id-login'));
        }
        $invitations = $this->invitations();
        if (isset($invitations[$invite_id]) && empty($invitations[$invite_id]['canceled_at']) && empty($invitations[$invite_id]['used_at'])) {
            $invitations[$invite_id]['canceled_at'] = gmdate('c');
            $this->save_invitations($invitations);
            if (class_exists('SSF_Access_Control')) {
                SSF_Access_Control::audit_identity_event((int) ($invitations[$invite_id]['user_id'] ?? 0), get_current_user_id(), 'invitation_canceled');
            }
        }
        $this->set_notice(get_current_user_id(), 'success', __('Inbjudan har avbrutits.', 'microsoft-id-login'), 'microsoft-users');
        wp_safe_redirect($this->admin_section_url('microsoft-users'));
        exit;
    }

    public function render_invitation_activation(): void
    {
        $token = isset($_GET['token']) && is_scalar($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
        $resolved = $this->invitation_by_token($token);
        $invitation = $resolved['invitation'] ?? null;
        if (! is_array($invitation) || ! $this->invitation_is_open($invitation)) {
            $this->render_account_page(__('Inbjudan kan inte användas', 'microsoft-id-login'), __('Länken är använd, avbruten eller har gått ut.', 'microsoft-id-login'), '', '', __('Be administratören skicka en ny inbjudan.', 'microsoft-id-login'));
        }
        $email = (string) $invitation['email'];
        $action_url = add_query_arg(array('action' => 'ssf_m365_invite_start', 'token' => rawurlencode($token)), admin_url('admin-post.php'));
        $this->render_account_page(__('Aktivera ditt SSF-konto', 'microsoft-id-login'), __('Du har blivit inbjuden att använda SSF:s system.', 'microsoft-id-login'), $email, $action_url, __('Fortsätt med SSF-konto', 'microsoft-id-login'));
    }

    public function start_invitation_activation(): void
    {
        $token = isset($_GET['token']) && is_scalar($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
        $resolved = $this->invitation_by_token($token);
        $invitation = $resolved['invitation'] ?? null;
        if (! is_array($invitation) || ! $this->invitation_is_open($invitation)) {
            $this->render_account_page(__('Inbjudan kan inte användas', 'microsoft-id-login'), __('Länken är använd, avbruten eller har gått ut.', 'microsoft-id-login'), '', '', __('Be administratören skicka en ny inbjudan.', 'microsoft-id-login'));
        }
        $this->start_authorization('invite', (int) $invitation['user_id'], '', (string) $resolved['id'], $token);
    }

    public function save_permission_groups(): void
    {
        if (! $this->can_manage_permission_groups() || ! check_admin_referer('ssf_save_permission_groups')) {
            wp_die(esc_html__('Du saknar behorighet.', 'microsoft-id-login'));
        }
        $user_ids = isset($_POST['user_ids']) && is_array($_POST['user_ids']) ? array_map('absint', wp_unslash($_POST['user_ids'])) : array();
        $posted_groups = isset($_POST['groups']) && is_array($_POST['groups']) ? wp_unslash($_POST['groups']) : array();
        foreach ($user_ids as $user_id) {
            if ($user_id <= 0 || ($user_id === get_current_user_id() && ! current_user_can('manage_options'))) {
                continue;
            }
            $groups = isset($posted_groups[$user_id]) && is_array($posted_groups[$user_id]) ? array_map('sanitize_key', $posted_groups[$user_id]) : array();
            $this->save_user_groups($user_id, $groups, get_current_user_id());
        }
        $this->set_notice(get_current_user_id(), 'success', __('Behorigheterna har sparats.', 'microsoft-id-login'), 'microsoft-users');
        wp_safe_redirect($this->admin_section_url('microsoft-users'));
        exit;
    }

    public function save_settings(): void
    {
        if (! $this->can_manage_login() || ! check_admin_referer('ssf_m365_save_settings')) {
            wp_die(esc_html__('Du saknar behörighet.', 'microsoft-id-login'));
        }

        $current = $this->settings();
        $posted = isset($_POST['profiles']) && is_array($_POST['profiles']) ? wp_unslash($_POST['profiles']) : array();

        foreach (array($this->active_profile_key()) as $profile_key) {
            $profile = isset($posted[$profile_key]) && is_array($posted[$profile_key]) ? $posted[$profile_key] : array();
            $current['profiles'][$profile_key]['enabled'] = ! empty($profile['enabled']);
            $current['profiles'][$profile_key]['client_id'] = $this->sanitize_guid((string) ($profile['client_id'] ?? ''));

            if (! empty($profile['clear_secret'])) {
                $current['profiles'][$profile_key]['client_secret'] = '';
            } elseif (isset($profile['client_secret']) && '' !== trim((string) $profile['client_secret'])) {
                $current['profiles'][$profile_key]['client_secret'] = sanitize_text_field((string) $profile['client_secret']);
            }
        }

        $current['schema_version'] = 1;
        $current['updated_at'] = gmdate('c');
        $current['updated_by'] = get_current_user_id();
        update_option(self::SETTINGS_OPTION, $current, false);
        $this->set_notice(get_current_user_id(), 'success', __('Microsoft-konfigurationen har sparats.', 'microsoft-id-login'), 'microsoft-login');
        wp_safe_redirect($this->admin_section_url('microsoft-login'));
        exit;
    }

    public function test_configuration(): void
    {
        if (! $this->can_manage_login() || ! check_admin_referer('ssf_m365_test_config')) {
            wp_die(esc_html__('Du saknar behörighet.', 'microsoft-id-login'));
        }
        $checks = $this->run_connection_checks();
        $passed = $this->checks_passed($checks);
        set_transient(self::TEST_PREFIX . 'config_' . get_current_user_id(), $checks, 10 * MINUTE_IN_SECONDS);
        $this->record_test_status('technical', $passed);
        $this->set_notice(
            get_current_user_id(),
            $passed ? 'success' : 'error',
            $passed ? __('Microsoft-konfigurationen kunde lasas.', 'microsoft-id-login') : __('Microsoft-konfigurationen kunde inte verifieras.', 'microsoft-id-login'),
            'microsoft-login'
        );
        wp_safe_redirect($this->admin_section_url('microsoft-login'));
        exit;
    }

    private function run_connection_checks(): array
    {
        $enable_state = $this->enable_state();
        $metadata = $this->is_configured() ? $this->discovery_metadata(false) : new WP_Error('not_configured', 'not_configured');
        $jwks = ! is_wp_error($metadata) ? $this->jwks(false) : new WP_Error('metadata_failed', 'metadata_failed');
        $tenant = $this->config('tenant_id');
        $metadata_error = is_wp_error($metadata) ? $metadata->get_error_message() : '';
        $jwks_error = is_wp_error($jwks) ? $jwks->get_error_message() : '';

        return array(
            'WordPress environment' => array(
                'passed' => in_array(wp_get_environment_type(), array('development', 'production'), true),
                'detail' => sprintf(__('Aktiv WordPress-miljö: %s.', 'microsoft-id-login'), wp_get_environment_type()),
                'action' => __('Kontrollera WP_ENVIRONMENT_TYPE om miljön är oväntad.', 'microsoft-id-login'),
            ),
            'Feature flag' => array(
                'passed' => ! empty($enable_state['admin_enabled']) && empty($enable_state['force_off']),
                'detail' => $enable_state['message'],
                'action' => ! empty($enable_state['force_off']) ? __('Ändra serverkonfigurationen innan funktionen kan aktiveras.', 'microsoft-id-login') : __('Aktivera profilen under SSF -> System -> Inloggning.', 'microsoft-id-login'),
            ),
            'Tenant ID finns' => array(
                'passed' => '' !== $tenant,
                'detail' => '' !== $tenant ? __('Tenant ID är sparat.', 'microsoft-id-login') : __('Tenant ID saknas.', 'microsoft-id-login'),
                'action' => __('Lägg in Directory/Tenant ID för rätt miljö.', 'microsoft-id-login'),
            ),
            'Client ID finns' => array(
                'passed' => '' !== $this->config('client_id'),
                'detail' => '' !== $this->config('client_id') ? __('Application ID / Client ID är sparat.', 'microsoft-id-login') : __('Application ID / Client ID saknas.', 'microsoft-id-login'),
                'action' => __('Lägg in Application ID från Entra app registration.', 'microsoft-id-login'),
            ),
            'Client Secret finns' => array(
                'passed' => '' !== $this->config('client_secret'),
                'detail' => '' !== $this->config('client_secret') ? __('Client secret finns sparad men visas inte.', 'microsoft-id-login') : __('Client secret saknas.', 'microsoft-id-login'),
                'action' => __('Skapa eller lägg in client secret. Lämna fältet tomt senare för att behålla befintlig secret.', 'microsoft-id-login'),
            ),
            'OpenID discovery' => array(
                'passed' => ! is_wp_error($metadata),
                'detail' => ! is_wp_error($metadata) ? __('Microsoft OpenID metadata kunde läsas.', 'microsoft-id-login') : sprintf(__('Microsoft OpenID metadata kunde inte läsas (%s).', 'microsoft-id-login'), $metadata_error),
                'action' => __('Kontrollera tenant ID och att servern kan nå login.microsoftonline.com.', 'microsoft-id-login'),
            ),
            'Issuer matchar tenant' => array(
                'passed' => ! is_wp_error($metadata) && (($metadata['issuer'] ?? '') === $this->expected_issuer()),
                'detail' => ! is_wp_error($metadata) ? __('Issuer i Microsoft metadata matchar aktiv tenant.', 'microsoft-id-login') : __('Issuer kunde inte kontrolleras när metadata saknas.', 'microsoft-id-login'),
                'action' => __('Kontrollera att tenant ID hör till SSF:s Entra tenant.', 'microsoft-id-login'),
            ),
            'JWKS/signeringsnycklar' => array(
                'passed' => ! is_wp_error($jwks) && ! empty($jwks['keys']),
                'detail' => ! is_wp_error($jwks) && ! empty($jwks['keys']) ? __('Microsofts signeringsnycklar kunde läsas.', 'microsoft-id-login') : sprintf(__('Signeringsnycklar kunde inte läsas (%s).', 'microsoft-id-login'), $jwks_error),
                'action' => __('Kontrollera nätverk/DNS från webbservern och OpenID metadata.', 'microsoft-id-login'),
            ),
            'Callback URL' => array(
                'passed' => false !== strpos($this->callback_url(), self::CALLBACK_PATH),
                'detail' => sprintf(__('Callback URL är %s.', 'microsoft-id-login'), $this->callback_url()),
                'action' => __('Lägg in exakt callback URL i Entra app registration.', 'microsoft-id-login'),
            ),
            'Inga Graph- eller SharePoint-behorigheter behovs' => array(
                'passed' => true,
                'detail' => __('Loginmodulen använder bara openid profile email och inga Graph-/SharePoint-rättigheter.', 'microsoft-id-login'),
                'action' => __('Ingen åtgärd.', 'microsoft-id-login'),
            ),
        );
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

    private function start_authorization(string $mode, int $user_id, string $redirect_to, string $invite_id = '', string $invite_token = ''): void
    {
        $enable_state = $this->enable_state();
        if (empty($enable_state['active'])) {
            wp_die(esc_html((string) $enable_state['message']));
        }
        if (class_exists('SSF_Access_Control') && ! SSF_Access_Control::is_active($user_id)) {
            $this->set_notice(get_current_user_id(), 'error', __('Återaktivera användaren innan inbjudan skickas igen.', 'microsoft-id-login'), 'microsoft-users');
            wp_safe_redirect($this->admin_section_url('microsoft-users'));
            exit;
        }
        $client_id = $this->config('client_id');
        if (! $this->is_valid_client_id($client_id)) {
            wp_die(esc_html__('Microsoft-inloggningens Client ID är ogiltigt. Kontakta en administratör.', 'microsoft-id-login'));
        }
        if (in_array($mode, array('link', 'test', 'invite'), true) && $user_id <= 0) {
            wp_die(esc_html__('Du måste vara inloggad för att koppla Microsoft 365-konto.', 'microsoft-id-login'));
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
                'invite_id' => $invite_id,
                'invite_token' => $invite_token,
                'used' => false,
            ),
            10 * MINUTE_IN_SECONDS
        );
        $query = array(
            'client_id' => $client_id,
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
            $this->deny(__('Microsoft-inloggningen kunde inte verifieras. Försök igen.', 'microsoft-id-login'));
        }
        $enable_state = $this->enable_state();
        if (empty($enable_state['active'])) {
            $this->deny((string) $enable_state['message']);
        }
        if (! empty($_GET['error'])) {
            $this->deny(__('Microsoft-inloggningen avbröts eller nekades.', 'microsoft-id-login'));
        }
        $code = isset($_GET['code']) && is_scalar($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        if (! $code) {
            $this->deny(__('Microsoft skickade ingen behörighetskod.', 'microsoft-id-login'));
        }
        $token = $this->exchange_code($code, (string) $transaction['pkce_verifier']);
        if (is_wp_error($token) || empty($token['id_token'])) {
            $this->deny(__('Microsoft-inloggningen kunde inte verifieras. Försök igen.', 'microsoft-id-login'));
        }
        $claims = $this->validate_id_token((string) $token['id_token'], (string) $transaction['nonce']);
        if (is_wp_error($claims)) {
            $this->deny($claims->get_error_message());
        }
        $tid = sanitize_text_field((string) ($claims['tid'] ?? ''));
        $oid = sanitize_text_field((string) ($claims['oid'] ?? ''));
        $email = $this->claim_email($claims);
        if (! $tid || ! $oid) {
            $this->deny(__('Microsoft-inloggningen kunde inte verifieras. Försök igen.', 'microsoft-id-login'));
        }
        if ('link' === ($transaction['mode'] ?? '')) {
            $this->complete_link($transaction, $tid, $oid, $email);
        }
        if ('test' === ($transaction['mode'] ?? '')) {
            $this->complete_real_login_test($transaction, $tid, $oid);
        }
        if ('invite' === ($transaction['mode'] ?? '')) {
            $this->complete_invitation_activation($transaction, $tid, $oid, $email);
        }
        $this->complete_login($tid, $oid, (string) ($transaction['redirect_to'] ?? ''), $email);
    }

    private function complete_login(string $tid, string $oid, string $redirect_to, string $email = ''): void
    {
        $users = $this->users_for_identity($tid, $oid);
        if (1 !== count($users)) {
            $this->deny(__('Ditt Microsoft-konto är inte kopplat till ett SSF-konto.', 'microsoft-id-login'));
        }
        $user_id = (int) $users[0]->ID;
        if (class_exists('SSF_Access_Control') && ! SSF_Access_Control::is_active($user_id)) {
            $this->deny(__('SSF-åtkomsten är inaktiv. Kontakta en administratör.', 'microsoft-id-login'));
        }
        update_user_meta($user_id, self::META_LAST_LOGIN, time());
        if ('' !== $email) {
            update_user_meta($user_id, self::META_EMAIL, $email);
        }
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true);
        do_action('wp_login', $users[0]->user_login, $users[0]);
        wp_safe_redirect($this->safe_redirect($redirect_to));
        exit;
    }

    private function complete_link(array $transaction, string $tid, string $oid, string $email = ''): void
    {
        $user_id = (int) ($transaction['user_id'] ?? 0);
        if (! is_user_logged_in() || $user_id <= 0 || get_current_user_id() !== $user_id) {
            $this->deny(__('Du måste vara inloggad för att koppla Microsoft 365-konto.', 'microsoft-id-login'));
        }
        foreach ($this->users_for_identity($tid, $oid) as $user) {
            if ((int) $user->ID !== $user_id) {
                $this->deny(__('Det här Microsoft-kontot är redan kopplat till ett annat SSF-konto.', 'microsoft-id-login'));
            }
        }
        $identity_changed = (string) get_user_meta($user_id, self::META_TID, true) !== $tid
            || (string) get_user_meta($user_id, self::META_OID, true) !== $oid;
        update_user_meta($user_id, self::META_TID, $tid);
        update_user_meta($user_id, self::META_OID, $oid);
        if ('' !== $email) {
            update_user_meta($user_id, self::META_EMAIL, $email);
        }
        if ($identity_changed && class_exists('SSF_Access_Control')) {
            SSF_Access_Control::audit_identity_event($user_id, $user_id, 'microsoft_linked');
        }
        $this->set_notice($user_id, 'success', __('Microsoft 365-kontot är nu kopplat.', 'microsoft-id-login'));
        wp_safe_redirect(admin_url('profile.php'));
        exit;
    }

    private function complete_real_login_test(array $transaction, string $tid, string $oid): void
    {
        $user_id = (int) ($transaction['user_id'] ?? 0);
        if (! is_user_logged_in() || $user_id <= 0 || get_current_user_id() !== $user_id) {
            $this->deny(__('Testet maste avslutas med samma WordPress-session som startade det.', 'microsoft-id-login'));
        }
        $linked = (string) get_user_meta($user_id, self::META_TID, true) === $tid && (string) get_user_meta($user_id, self::META_OID, true) === $oid;
        $this->record_test_status('real_login', true);
        set_transient(
            self::TEST_PREFIX . 'login_' . $user_id,
            array(
                'Microsoft-inloggning' => array(
                    'passed' => true,
                    'detail' => __('Microsoft skickade tillbaka användaren via callback.', 'microsoft-id-login'),
                    'action' => __('Ingen åtgärd.', 'microsoft-id-login'),
                ),
                'State' => array(
                    'passed' => true,
                    'detail' => __('State matchade startad WordPress-session och användes bara en gång.', 'microsoft-id-login'),
                    'action' => __('Starta om testet om sessionen hinner löpa ut.', 'microsoft-id-login'),
                ),
                'Nonce' => array(
                    'passed' => true,
                    'detail' => __('Nonce i ID-token matchade testets nonce.', 'microsoft-id-login'),
                    'action' => __('Starta om testet om webbläsarsessionen byts mitt i flödet.', 'microsoft-id-login'),
                ),
                'PKCE' => array(
                    'passed' => true,
                    'detail' => __('PKCE-verifieringen accepterades av Microsoft token endpoint.', 'microsoft-id-login'),
                    'action' => __('Ingen åtgärd.', 'microsoft-id-login'),
                ),
                'ID-token signatur' => array(
                    'passed' => true,
                    'detail' => __('ID-token verifierades med Microsofts signeringsnycklar.', 'microsoft-id-login'),
                    'action' => __('Kontrollera JWKS om detta börjar fallera.', 'microsoft-id-login'),
                ),
                'Issuer' => array(
                    'passed' => true,
                    'detail' => __('Token issuer matchade aktiv tenant.', 'microsoft-id-login'),
                    'action' => __('Kontrollera tenant ID i aktiv profil.', 'microsoft-id-login'),
                ),
                'Audience' => array(
                    'passed' => true,
                    'detail' => __('Token audience matchade Application ID / Client ID.', 'microsoft-id-login'),
                    'action' => __('Kontrollera Application ID i aktiv profil.', 'microsoft-id-login'),
                ),
                'Tenant' => array(
                    'passed' => true,
                    'detail' => __('Microsoft-kontot kom från den konfigurerade tenant:en.', 'microsoft-id-login'),
                    'action' => __('Använd ett konto i SSF:s organisation.', 'microsoft-id-login'),
                ),
                'tid mottaget' => array(
                    'passed' => '' !== $tid,
                    'detail' => '' !== $tid ? __('Tenant claim mottogs.', 'microsoft-id-login') : __('Tenant claim saknas.', 'microsoft-id-login'),
                    'action' => __('Kontrollera app registration och token claims.', 'microsoft-id-login'),
                ),
                'oid mottaget' => array(
                    'passed' => '' !== $oid,
                    'detail' => '' !== $oid ? __('Object ID claim mottogs.', 'microsoft-id-login') : __('Object ID claim saknas.', 'microsoft-id-login'),
                    'action' => __('Kontrollera app registration och token claims.', 'microsoft-id-login'),
                ),
                'Microsoft-identitet matchar kopplat konto' => array(
                    'passed' => $linked,
                    'detail' => $linked ? __('Microsoft-kontot matchar den WordPress-användare som startade testet.', 'microsoft-id-login') : __('Microsoft-kontot är inte kopplat till den WordPress-användare som startade testet.', 'microsoft-id-login'),
                    'action' => __('Koppla rätt Microsoft-konto under Ditt Microsoft-konto.', 'microsoft-id-login'),
                ),
                'WordPress-behorigheter oforandrade' => array(
                    'passed' => true,
                    'detail' => __('Testet ändrade inte WordPress-roll eller SSF-behörighetsgrupper.', 'microsoft-id-login'),
                    'action' => __('Ingen åtgärd.', 'microsoft-id-login'),
                ),
            ),
            10 * MINUTE_IN_SECONDS
        );
        wp_safe_redirect($this->admin_section_url('microsoft-login'));
        exit;
    }

    private function complete_invitation_activation(array $transaction, string $tid, string $oid, string $email): void
    {
        $invite_id = sanitize_key((string) ($transaction['invite_id'] ?? ''));
        $invitations = $this->invitations();
        $invitation = $invitations[$invite_id] ?? null;
        if (! is_array($invitation) || ! $this->invitation_is_open($invitation)) {
            $this->render_account_page(__('Inbjudan kan inte användas', 'microsoft-id-login'), __('Länken är använd, avbruten eller har gått ut.', 'microsoft-id-login'), '', '', __('Be administratören skicka en ny inbjudan.', 'microsoft-id-login'));
        }
        $expected_email = strtolower((string) $invitation['email']);
        if (strtolower($email) !== $expected_email) {
            $retry_url = add_query_arg(array('action' => 'ssf_m365_invite_activate', 'token' => rawurlencode((string) ($transaction['invite_token'] ?? ''))), admin_url('admin-post.php'));
            $this->render_account_page(__('Fel SSF-konto', 'microsoft-id-login'), __('Du loggade in med ett annat Microsoft-konto.', 'microsoft-id-login'), $expected_email, $retry_url, __('Försök igen med rätt konto', 'microsoft-id-login'));
        }
        foreach ($this->users_for_identity($tid, $oid) as $user) {
            if ((int) $user->ID !== (int) $invitation['user_id']) {
                $this->render_account_page(__('Kontot är redan kopplat', 'microsoft-id-login'), __('Det här Microsoft-kontot är redan kopplat till en annan WordPress-användare.', 'microsoft-id-login'), $expected_email, '', __('Kontakta administratören.', 'microsoft-id-login'));
            }
        }
        $user_id = (int) $invitation['user_id'];
        if (class_exists('SSF_Access_Control') && ! SSF_Access_Control::is_active($user_id)) {
            $this->deny(__('SSF-åtkomsten är inaktiv. Inbjudan har inte förbrukats.', 'microsoft-id-login'));
        }
        update_user_meta($user_id, self::META_TID, $tid);
        update_user_meta($user_id, self::META_OID, $oid);
        update_user_meta($user_id, self::META_EMAIL, $email);
        update_user_meta($user_id, self::META_LAST_LOGIN, time());
        $invitations[$invite_id]['used_at'] = gmdate('c');
        $this->save_invitations($invitations);
        if (class_exists('SSF_Access_Control')) {
            SSF_Access_Control::audit_identity_event($user_id, $user_id, 'invitation_activated');
        }
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true);
        $user = get_user_by('id', $user_id);
        if ($user instanceof WP_User) {
            do_action('wp_login', $user->user_login, $user);
        }
        $this->render_account_page(__('Ditt SSF-konto är aktiverat', 'microsoft-id-login'), __('Du kan nu logga in med ditt SSF-konto.', 'microsoft-id-login'), $expected_email, admin_url('/'), __('Fortsätt till SSF', 'microsoft-id-login'));
    }

    private function find_or_create_user(string $email, string $display_name)
    {
        $existing = get_user_by('email', $email);
        if ($existing instanceof WP_User) {
            if ('' !== $display_name && $existing->display_name !== $display_name) {
                wp_update_user(array('ID' => (int) $existing->ID, 'display_name' => $display_name));
            }
            return (int) $existing->ID;
        }
        $email_parts = explode('@', $email);
        $login = sanitize_user((string) $email_parts[0], true);
        if (username_exists($login)) {
            $login .= '-' . wp_generate_password(4, false, false);
        }
        return wp_insert_user(array(
            'user_login' => $login,
            'user_email' => $email,
            'display_name' => $display_name ?: $email,
            'user_pass' => wp_generate_password(32, true, true),
            'role' => 'subscriber',
        ));
    }

    private function issue_invitation(int $user_id, string $email): array
    {
        $this->invalidate_open_invitations($user_id);
        $raw_token = $this->random_urlsafe(32);
        $invite_id = sanitize_key(wp_generate_uuid4());
        $invitations = $this->invitations();
        $invitations[$invite_id] = array(
            'id' => $invite_id,
            'user_id' => $user_id,
            'email' => strtolower($email),
            'token_hash' => $this->invitation_token_hash($raw_token),
            'created_at' => gmdate('c'),
            'expires_at' => gmdate('c', time() + self::INVITATION_TTL),
            'used_at' => '',
            'canceled_at' => '',
        );
        $this->save_invitations($invitations);
        return array('id' => $invite_id, 'raw_token' => $raw_token);
    }

    private function invalidate_open_invitations(int $user_id): void
    {
        $invitations = $this->invitations();
        foreach ($invitations as $id => $invitation) {
            if ((int) ($invitation['user_id'] ?? 0) === $user_id && $this->invitation_is_open($invitation)) {
                $invitations[$id]['canceled_at'] = gmdate('c');
            }
        }
        $this->save_invitations($invitations);
    }

    private function send_invitation_email(int $user_id, string $raw_token): bool
    {
        $user = get_user_by('id', $user_id);
        if (! $user instanceof WP_User) {
            return false;
        }
        $url = add_query_arg(array('action' => 'ssf_m365_invite_activate', 'token' => rawurlencode($raw_token)), admin_url('admin-post.php'));
        $subject = __('Aktivera ditt SSF-konto', 'microsoft-id-login');
        if (! class_exists('SSF_Email_Template')) {
            return false;
        }
        return SSF_Email_Template::send(
                (string) $user->user_email,
                $subject,
                'ssf_account_invitation',
                array(
                    'category' => 'general',
                    'title' => __('Aktivera ditt SSF-konto', 'microsoft-id-login'),
                    'preheader' => __('Du har fått tillgång till SSF:s administrativa system.', 'microsoft-id-login'),
                    'intro' => sprintf(__('Hej %s,', 'microsoft-id-login'), $user->display_name ?: $user->user_email),
                    'body' => sprintf(__('Du har fått tillgång till SSF:s administrativa system. Klicka nedan för att aktivera ditt konto med ditt %s-konto.', 'microsoft-id-login'), $user->user_email),
                    'button_url' => $url,
                    'button_label' => __('Aktivera SSF-konto', 'microsoft-id-login'),
                    'notice_title' => __('Inloggningen verifieras av Microsoft.', 'microsoft-id-login'),
                    'notice' => __('SSF hanterar aldrig ditt Microsoft-lösenord.', 'microsoft-id-login'),
                )
            );
    }

    private function invitation_by_token(string $raw_token): array
    {
        if ('' === $raw_token) {
            return array();
        }
        $hash = $this->invitation_token_hash($raw_token);
        foreach ($this->invitations() as $id => $invitation) {
            if (hash_equals((string) ($invitation['token_hash'] ?? ''), $hash)) {
                return array('id' => (string) $id, 'invitation' => $invitation);
            }
        }
        return array();
    }

    private function invitation_token_hash(string $raw_token): string
    {
        return hash_hmac('sha256', $raw_token, wp_salt('auth'));
    }

    private function invitations(): array
    {
        $stored = get_option(self::INVITATIONS_OPTION, array());
        return is_array($stored) ? $stored : array();
    }

    private function save_invitations(array $invitations): void
    {
        update_option(self::INVITATIONS_OPTION, $invitations, false);
    }

    private function invitation_is_open(array $invitation): bool
    {
        if (! empty($invitation['used_at']) || ! empty($invitation['canceled_at'])) {
            return false;
        }
        return strtotime((string) ($invitation['expires_at'] ?? '')) > time();
    }

    private function latest_invitation_for_user(int $user_id): ?array
    {
        $latest = null;
        foreach ($this->invitations() as $id => $invitation) {
            if ((int) ($invitation['user_id'] ?? 0) !== $user_id) {
                continue;
            }
            $invitation['id'] = (string) $id;
            if (null === $latest || strcmp((string) ($invitation['created_at'] ?? ''), (string) ($latest['created_at'] ?? '')) > 0) {
                $latest = $invitation;
            }
        }
        return $latest;
    }

    private function pending_invitation_count(): int
    {
        $count = 0;
        foreach ($this->invitations() as $invitation) {
            if ($this->invitation_is_open($invitation)) {
                $count++;
            }
        }
        return $count;
    }

    private function user_status_label(int $user_id, bool $linked, ?array $invitation): string
    {
        if ($linked) {
            return __('Aktiv', 'microsoft-id-login');
        }
        if (is_array($invitation)) {
            if (! empty($invitation['used_at'])) {
                return __('Aktiv', 'microsoft-id-login');
            }
            if (! empty($invitation['canceled_at'])) {
                return __('Ej aktiverad', 'microsoft-id-login');
            }
            if (! $this->invitation_is_open($invitation)) {
                return __('Inbjudan utgången', 'microsoft-id-login');
            }
            return __('Inbjuden', 'microsoft-id-login');
        }
        return __('Microsoft ej kopplat', 'microsoft-id-login');
    }

    private function is_ssf_email(string $email): bool
    {
        return (bool) preg_match('/^[^@\s]+@ssfb\.se$/i', $email);
    }

    private function logo_markup(): string
    {
        $theme_logo_path = function_exists('get_theme_file_path') ? get_theme_file_path('/assets/images/ssf-logo.svg') : '';
        if ($theme_logo_path && is_readable($theme_logo_path)) {
            return '<img class="ssf-account-logo-image" src="' . esc_url(get_theme_file_uri('/assets/images/ssf-logo.svg')) . '" alt="' . esc_attr__('Sveriges Segelfartygsförbund', 'microsoft-id-login') . '">';
        }
        if (function_exists('get_custom_logo')) {
            $logo = get_custom_logo();
            if ('' !== $logo) {
                return $logo;
            }
        }
        $icon = get_site_icon_url(96);
        if ($icon) {
            return '<img src="' . esc_url($icon) . '" alt="' . esc_attr__('SSF', 'microsoft-id-login') . '">';
        }
        return '<span class="ssf-account-logo-fallback">SSF</span>';
    }

    private function safe_http_error(WP_Error $error): string
    {
        $message = sanitize_text_field($error->get_error_message());
        foreach (array($this->config('tenant_id'), $this->config('client_id'), $this->config('client_secret')) as $protected) {
            if ('' !== $protected) {
                $message = str_replace($protected, '[skyddat]', $message);
            }
        }
        return sprintf('Transportfel %s: %s', sanitize_key($error->get_error_code()), $message ?: 'Ingen felbeskrivning returnerades.');
    }

    private function render_account_page(string $title, string $body, string $email, string $action_url, string $button_label): void
    {
        status_header(200);
        nocache_headers();
        echo '<!doctype html><html ' . get_language_attributes() . '><head><meta charset="' . esc_attr(get_bloginfo('charset')) . '"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . esc_html($title) . '</title>';
        echo '<link rel="stylesheet" href="' . esc_url(plugins_url('assets/css/ssf-account.css', __FILE__)) . '?ver=' . esc_attr(self::VERSION) . '">';
        echo '</head><body class="ssf-account-page"><main class="ssf-account-card">';
        echo '<div class="ssf-account-logo">' . wp_kses_post($this->logo_markup()) . '</div>';
        echo '<h1>' . esc_html($title) . '</h1><p>' . esc_html($body) . '</p>';
        if ('' !== $email) {
            echo '<p class="ssf-account-email"><strong>' . esc_html__('Konto:', 'microsoft-id-login') . '</strong> ' . esc_html($email) . '</p>';
        }
        if ('' !== $action_url) {
            echo '<p><a class="button button-primary ssf-account-primary" href="' . esc_url($action_url) . '">' . esc_html($button_label) . '</a></p>';
            echo '<p class="ssf-account-help">' . esc_html__('Inloggningen hanteras av Microsoft.', 'microsoft-id-login') . '</p>';
        } else {
            echo '<p class="ssf-account-help">' . esc_html($button_label) . '</p>';
        }
        echo '</main></body></html>';
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
            return new WP_Error('ssf_m365_jwt_format', __('Microsoft-inloggningen kunde inte verifieras. Försök igen.', 'microsoft-id-login'));
        }
        $header = json_decode($this->base64url_decode($parts[0]), true);
        $claims = json_decode($this->base64url_decode($parts[1]), true);
        $signature = $this->base64url_decode($parts[2]);
        if (! is_array($header) || ! is_array($claims) || ($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) {
            return new WP_Error('ssf_m365_jwt_header', __('Microsoft-inloggningen kunde inte verifieras. Försök igen.', 'microsoft-id-login'));
        }
        $key = $this->signing_key((string) $header['kid']);
        if (is_wp_error($key) || ! openssl_verify($parts[0] . '.' . $parts[1], $signature, $key, OPENSSL_ALGO_SHA256)) {
            return new WP_Error('ssf_m365_invalid_signature', __('Microsoft-inloggningen kunde inte verifieras. Försök igen.', 'microsoft-id-login'));
        }
        $tenant = $this->config('tenant_id');
        $issuer = $this->expected_issuer();
        $now = time();
        if (($claims['iss'] ?? '') !== $issuer) {
            return new WP_Error('ssf_m365_invalid_issuer', __('Microsoft-inloggningen kunde inte verifieras. Försök igen.', 'microsoft-id-login'));
        }
        if (($claims['aud'] ?? '') !== $this->config('client_id')) {
            return new WP_Error('ssf_m365_invalid_audience', __('Microsoft-inloggningen kunde inte verifieras. Försök igen.', 'microsoft-id-login'));
        }
        if ((int) ($claims['exp'] ?? 0) <= $now || ((int) ($claims['nbf'] ?? 0) && (int) $claims['nbf'] > $now)) {
            return new WP_Error('ssf_m365_token_time', __('Microsoft-inloggningen kunde inte verifieras. Försök igen.', 'microsoft-id-login'));
        }
        if (($claims['nonce'] ?? '') !== $expected_nonce) {
            return new WP_Error('ssf_m365_invalid_nonce', __('Microsoft-inloggningen kunde inte verifieras. Försök igen.', 'microsoft-id-login'));
        }
        if (($claims['tid'] ?? '') !== $tenant) {
            return new WP_Error('ssf_m365_invalid_tenant', __('Det här Microsoft-kontot tillhör inte SSF:s Microsoft 365-miljö.', 'microsoft-id-login'));
        }
        if (empty($claims['oid']) || empty($claims['tid'])) {
            return new WP_Error('ssf_m365_missing_identity', __('Microsoft-inloggningen kunde inte verifieras. Försök igen.', 'microsoft-id-login'));
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

    private function claim_email(array $claims): string
    {
        foreach (array('email', 'preferred_username', 'upn') as $key) {
            if (! empty($claims[$key]) && is_string($claims[$key])) {
                return sanitize_email($claims[$key]);
            }
        }
        return '';
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
        if (is_wp_error($response)) {
            return new WP_Error('ssf_m365_discovery_failed', $this->safe_http_error($response));
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if (200 !== $status) {
            return new WP_Error('ssf_m365_discovery_failed', sprintf('HTTP %d från Microsoft OpenID.', $status));
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
        if (is_wp_error($response)) {
            return new WP_Error('ssf_m365_jwks_failed', $this->safe_http_error($response));
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if (200 !== $status) {
            return new WP_Error('ssf_m365_jwks_failed', sprintf('HTTP %d från Microsofts signeringsnycklar.', $status));
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
        $enable_state = $this->enable_state();
        $metadata = ! empty($enable_state['local_configured']) ? $this->discovery_metadata() : new WP_Error('not_configured', 'not_configured');
        $jwks = ! is_wp_error($metadata) ? $this->jwks() : new WP_Error('not_configured', 'not_configured');
        return array(
            'environment' => wp_get_environment_type(),
            'enabled' => $enable_state['active'],
            'admin_enabled' => $enable_state['admin_enabled'],
            'configured' => $enable_state['configured'],
            'force_off' => $enable_state['force_off'],
            'tenant_id' => '' !== $this->config('tenant_id'),
            'client_id' => '' !== $this->config('client_id'),
            'client_secret' => '' !== $this->config('client_secret'),
            'metadata' => ! empty($enable_state['openid_valid']) ? 'PASS' : 'FAIL',
            'jwks' => is_wp_error($jwks) ? 'FAIL' : 'PASS',
            'callback' => false !== strpos($this->callback_url(), self::CALLBACK_PATH) ? 'PASS' : 'FAIL',
        );
    }

    private function record_test_status(string $key, bool $passed): void
    {
        $status = get_option(self::TEST_STATUS_OPTION, array());
        $status = is_array($status) ? $status : array();
        $status[$key] = array(
            'result' => $passed ? 'PASS' : 'FAIL',
            'timestamp' => gmdate('c'),
            'user_id' => get_current_user_id(),
        );
        update_option(self::TEST_STATUS_OPTION, $status, false);
    }

    private function is_enabled(): bool
    {
        return ! empty($this->enable_state()['active']);
    }

    private function enable_state(): array
    {
        $settings = $this->settings();
        $profile = (array) ($settings['profiles'][$this->active_profile_key()] ?? array());
        $admin_enabled = ! empty($profile['enabled']);
        $local_configured = $this->is_configured();
        $metadata = $local_configured ? $this->discovery_metadata() : new WP_Error('not_configured', 'not_configured');
        $openid_valid = ! is_wp_error($metadata) && (($metadata['issuer'] ?? '') === $this->expected_issuer());
        $configured = $local_configured && $openid_valid;
        $force_off = $this->is_force_disabled();
        $active = $admin_enabled && $configured && ! $force_off;

        if ($force_off) {
            $message = __('Microsoft-inloggningen är avstängd av serverkonfiguration.', 'microsoft-id-login');
            $reason = 'force_off';
        } elseif (! $admin_enabled) {
            $message = __('Microsoft-inloggningen är avstängd av en administratör.', 'microsoft-id-login');
            $reason = 'admin_disabled';
        } elseif (! $configured) {
            $message = __('Microsoft-inloggningen är inte färdigkonfigurerad.', 'microsoft-id-login');
            $reason = 'incomplete_configuration';
        } else {
            $message = __('Microsoft-inloggningen är aktiv.', 'microsoft-id-login');
            $reason = 'active';
        }

        return array('admin_enabled' => $admin_enabled, 'local_configured' => $local_configured, 'openid_valid' => $openid_valid, 'configured' => $configured, 'force_off' => $force_off, 'active' => $active, 'reason' => $reason, 'message' => $message);
    }

    private function is_force_disabled(): bool
    {
        if (defined('SSF_M365_LOGIN_ENABLED')) {
            return ! $this->truthy(constant('SSF_M365_LOGIN_ENABLED'));
        }
        $value = getenv('SSF_M365_LOGIN_ENABLED');
        return false !== $value && '' !== trim((string) $value) && ! $this->truthy($value);
    }

    private function is_configured(): bool
    {
        return '' !== $this->config('tenant_id')
            && $this->is_valid_client_id($this->config('client_id'))
            && '' !== $this->config('client_secret')
            && false !== strpos($this->callback_url(), self::CALLBACK_PATH);
    }

    private function config(string $key): string
    {
        if ('tenant_id' === $key) {
            $this->ensure_central_config_loaded();
            return class_exists('SSF_Microsoft365_Config') ? SSF_Microsoft365_Config::get_tenant_id() : '';
        }
        $map = array(
            'client_id' => 'SSF_M365_LOGIN_CLIENT_ID',
            'client_secret' => 'SSF_M365_LOGIN_CLIENT_SECRET',
        );
        $constant = $map[$key] ?? '';
        if (! $constant) {
            return '';
        }
        $value = defined($constant) ? constant($constant) : getenv($constant);
        if (is_string($value) && '' !== trim($value) && ('client_id' !== $key || $this->is_valid_client_id(trim($value)))) {
            return trim($value);
        }

        $settings = $this->settings();
        $profile = $settings['profiles'][$this->active_profile_key()] ?? array();
        $fallback = is_string($profile[$key] ?? null) ? trim($profile[$key]) : '';
        return 'client_id' === $key && ! $this->is_valid_client_id($fallback) ? '' : $fallback;
    }

    public function public_configuration_status(): array
    {
        $settings = $this->settings();
        $profile = (array) ($settings['profiles'][$this->active_profile_key()] ?? array());
        $sources = array();
        foreach (array('client_id' => 'SSF_M365_LOGIN_CLIENT_ID', 'client_secret' => 'SSF_M365_LOGIN_CLIENT_SECRET') as $key => $name) {
            $override = defined($name) ? constant($name) : getenv($name);
            $valid = is_string($override) && '' !== trim($override) && ('client_id' !== $key || $this->is_valid_client_id(trim($override)));
            $sources[$key] = $valid ? 'server' : ('' !== (string) ($profile[$key] ?? '') ? 'wordpress' : 'missing');
        }
        return array(
            'enabled' => ! empty($profile['enabled']) && ! $this->is_force_disabled(),
            'tenant' => '' !== $this->config('tenant_id'),
            'client_id' => '' !== $this->config('client_id'),
            'client_secret' => '' !== $this->config('client_secret'),
            'client_id_source' => $sources['client_id'],
            'client_secret_source' => $sources['client_secret'],
            'callback' => $this->callback_url(),
        );
    }

    public function render_login_settings_section(): void
    {
        if (! $this->can_manage_login()) {
            wp_die(esc_html__('Du saknar behörighet.', 'microsoft-id-login'));
        }
        $this->render_settings_card($this->status());
        $this->render_test_results(
            get_transient(self::TEST_PREFIX . 'config_' . get_current_user_id()),
            get_transient(self::TEST_PREFIX . 'login_' . get_current_user_id())
        );
    }

    public function render_account_links_section(): void
    {
        if (! $this->can_manage_login()) {
            wp_die(esc_html__('Du saknar behörighet.', 'microsoft-id-login'));
        }
        $selected_id = absint($_GET['user_id'] ?? 0);
        $users = $selected_id ? array_filter(array(get_userdata($selected_id))) : get_users(array('orderby' => 'display_name', 'order' => 'ASC'));
        if ($selected_id) {
            echo '<p><a href="' . esc_url($this->admin_section_url('accounts')) . '">← Alla kontokopplingar</a></p>';
        }
        echo '<table class="widefat striped"><thead><tr><th>SSF-användare</th><th>Microsoft-koppling</th><th>Senaste inloggning</th><th>Åtgärd</th></tr></thead><tbody>';
        foreach ($users as $user) {
            $id = (int) $user->ID;
            $linked = $this->is_user_linked($id);
            $last = (int) get_user_meta($id, self::META_LAST_LOGIN, true);
            echo '<tr><td>' . esc_html($user->display_name . ' · ' . $user->user_email) . '</td><td>' . esc_html($linked ? 'Kopplat' : 'Ej kopplat') . '</td><td>' . esc_html($last ? wp_date('Y-m-d H:i', $last) : '–') . '</td><td>';
            if ($linked) {
                echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=ssf_m365_admin_unlink&user_id=' . $id), 'ssf_m365_admin_unlink_' . $id)) . '">Koppla från Microsoft</a>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function is_valid_client_id(string $value): bool
    {
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }

    private function ensure_central_config_loaded(): void
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

    private function legacy_tenant_warnings(): array
    {
        $this->ensure_central_config_loaded();
        if (! class_exists('SSF_Microsoft365_Config') || ! method_exists('SSF_Microsoft365_Config', 'legacy_tenant_warnings')) {
            return array();
        }
        return SSF_Microsoft365_Config::legacy_tenant_warnings();
    }

    private function profile_keys(): array
    {
        return array('development', 'production');
    }

    private function active_profile_key(): string
    {
        return 'production' === wp_get_environment_type() ? 'production' : 'development';
    }

    private function settings(): array
    {
        $stored = get_option(self::SETTINGS_OPTION, array());
        $settings = is_array($stored) ? $stored : array();
        $settings['schema_version'] = 1;
        $settings['profiles'] = is_array($settings['profiles'] ?? null) ? $settings['profiles'] : array();

        foreach ($this->profile_keys() as $profile_key) {
            $profile = is_array($settings['profiles'][$profile_key] ?? null) ? $settings['profiles'][$profile_key] : array();
            $settings['profiles'][$profile_key] = array(
                'enabled' => ! empty($profile['enabled']),
                'tenant_id' => is_string($profile['tenant_id'] ?? null) ? trim((string) $profile['tenant_id']) : '',
                'client_id' => is_string($profile['client_id'] ?? null) ? trim((string) $profile['client_id']) : '',
                'client_secret' => is_string($profile['client_secret'] ?? null) ? (string) $profile['client_secret'] : '',
            );
        }

        return $settings;
    }

    private function sanitize_guid(string $value): string
    {
        $value = trim($value);
        return preg_match('/^[A-Za-z0-9._:-]+$/', $value) ? $value : sanitize_text_field($value);
    }

    private function authority_url(string $path): string
    {
        $this->ensure_central_config_loaded();
        return class_exists('SSF_Microsoft365_Config')
            ? SSF_Microsoft365_Config::get_authority_url($path)
            : 'https://login.microsoftonline.com/' . rawurlencode($this->config('tenant_id')) . $path;
    }

    private function expected_issuer(): string
    {
        $this->ensure_central_config_loaded();
        $host = class_exists('SSF_Microsoft365_Config') ? SSF_Microsoft365_Config::get_authority_host() : 'login.microsoftonline.com';
        return 'https://' . $host . '/' . $this->config('tenant_id') . '/v2.0';
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
        wp_die(esc_html($message), esc_html__('Microsoft 365-inloggning', 'microsoft-id-login'), array('response' => 403));
    }

    private function set_notice(int $user_id, string $type, string $message, string $section = 'microsoft-login'): void
    {
        if (class_exists('SSF_Admin_Feedback') && $user_id === get_current_user_id()) {
            $page = 'microsoft-users' === $section ? 'ssf-users' : ('accounts' === $section ? 'ssf-member-portal-microsoft365' : self::MENU_SLUG);
            SSF_Admin_Feedback::set_flash($page, 'microsoft-users' === $section ? 'users' : $section, $type, $message);
            return;
        }
        set_transient(self::NOTICE_PREFIX . $user_id, array('type' => $type, 'message' => $message), MINUTE_IN_SECONDS);
    }

    private function admin_section_url(string $section): string
    {
        if ('microsoft-users' === $section && class_exists('SSF_User_Admin')) {
            return SSF_User_Admin::url('users');
        }
        if ('microsoft-login' === $section && class_exists('SSF_Microsoft365_Config')) {
            return add_query_arg(array('page' => 'ssf-member-portal-microsoft365', 'm365_tab' => 'login'), admin_url('admin.php'));
        }
        if ('accounts' === $section && class_exists('SSF_Microsoft365_Config')) {
            return add_query_arg(array('page' => 'ssf-member-portal-microsoft365', 'm365_tab' => 'accounts'), admin_url('admin.php'));
        }
        if (class_exists('SSF_Admin_Feedback')) {
            return SSF_Admin_Feedback::redirect_url(self::MENU_SLUG, $section);
        }
        return admin_url('admin.php?page=' . self::MENU_SLUG . '#' . sanitize_key($section));
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

register_activation_hook(__FILE__, array('SSF_Microsoft_ID_Login', 'activate'));
register_deactivation_hook(__FILE__, array('SSF_Microsoft_ID_Login', 'deactivate'));

SSF_Microsoft_ID_Login::instance();
