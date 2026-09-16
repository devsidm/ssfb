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
    private const TEST_PREFIX = 'ssf_m365_login_test_';
    private const MENU_SLUG = 'ssf-microsoft-login';
    private const META_TID = '_ssf_m365_tid';
    private const META_OID = '_ssf_m365_oid';
    private const META_EMAIL = '_ssf_m365_email';
    private const META_LAST_LOGIN = '_ssf_m365_last_login';
    private const GROUP_META = '_ssf_permission_groups';
    private const AUDIT_OPTION = 'ssf_microsoft_login_permission_audit';
    private const CAP_MANAGE_LOGIN = 'ssf_manage_microsoft_login';
    private const CAP_MANAGE_PERMISSIONS = 'ssf_manage_permission_groups';
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
        return array(
            'styrelse' => array(
                'label' => 'Styrelse',
                'description' => 'Styrelsearbete i medlemsportal och årsmötesflöden.',
                'capabilities' => array('ssf_manage_member_portal', 'ssf_manage_motions', 'manage_ssf_annual_meetings', 'ssf_view_applications'),
            ),
            'ansokningar' => array(
                'label' => 'Ansökningar',
                'description' => 'Handläggning av fartygs- och medlemsansökningar.',
                'capabilities' => array('ssf_view_applications', 'edit_ssf_applications', 'edit_others_ssf_applications', 'read_private_ssf_applications', 'edit_private_ssf_applications', 'edit_published_ssf_applications', 'ssf_review_applications', 'ssf_decide_applications', 'ssf_manage_application_settings'),
            ),
            'motioner' => array(
                'label' => 'Motioner',
                'description' => 'Motioner, statusflöde och motionsrelaterad SharePoint-synk.',
                'capabilities' => array('ssf_manage_motions'),
            ),
            'arsmoten' => array(
                'label' => 'Årsmöten',
                'description' => 'Årsmöten, anmälningar och deltagarexporter.',
                'capabilities' => array('manage_ssf_annual_meetings', 'ssf_manage_member_portal'),
            ),
            'inspektorer' => array(
                'label' => 'Inspektörer',
                'description' => 'Tilldelade inspektioner utan övrig administration.',
                'capabilities' => array('ssf_view_assigned_applications', 'ssf_view_application_details', 'ssf_edit_inspection', 'ssf_submit_inspection', 'ssf_send_application_message'),
            ),
            'systemadministration' => array(
                'label' => 'Systemadministration',
                'description' => 'SSF-systeminställningar, Microsoft-login och tekniska diagnostikvyer.',
                'capabilities' => array(self::CAP_MANAGE_LOGIN, self::CAP_MANAGE_PERMISSIONS, 'ssf_manage_member_portal', 'manage_ssf_features', 'manage_ssf_releases'),
            ),
        );
    }

    public function grant_group_capabilities(array $allcaps, array $caps, array $args, WP_User $user): array
    {
        foreach ($this->user_groups((int) $user->ID) as $group_key) {
            $group = self::permission_groups()[$group_key] ?? null;
            if (! $group) {
                continue;
            }
            foreach ((array) $group['capabilities'] as $capability) {
                $allcaps[$capability] = true;
            }
        }
        return $allcaps;
    }

    private function user_groups(int $user_id): array
    {
        $stored = (array) get_user_meta($user_id, self::GROUP_META, true);
        $valid = array_keys(self::permission_groups());
        return array_values(array_intersect(array_map('sanitize_key', $stored), $valid));
    }

    private function save_user_groups(int $target_user_id, array $groups, int $actor_user_id): void
    {
        $old = $this->user_groups($target_user_id);
        $valid = array_keys(self::permission_groups());
        $new = array_values(array_intersect(array_map('sanitize_key', $groups), $valid));
        sort($old);
        sort($new);
        update_user_meta($target_user_id, self::GROUP_META, $new);
        $this->audit_permission_change($target_user_id, $actor_user_id, array_values(array_diff($new, $old)), array_values(array_diff($old, $new)));
    }

    private function audit_permission_change(int $target_user_id, int $actor_user_id, array $added, array $removed): void
    {
        if (! $added && ! $removed) {
            return;
        }
        $entries = (array) get_option(self::AUDIT_OPTION, array());
        $entries[] = array(
            'target_user_id' => $target_user_id,
            'actor_user_id' => $actor_user_id,
            'added' => array_values($added),
            'removed' => array_values($removed),
            'timestamp' => gmdate('c'),
        );
        update_option(self::AUDIT_OPTION, array_slice($entries, -100), false);
    }

    private function can_manage_login(): bool
    {
        return current_user_can(self::CAP_MANAGE_LOGIN) || current_user_can('manage_options');
    }

    private function can_manage_permission_groups(): bool
    {
        return current_user_can(self::CAP_MANAGE_PERMISSIONS) || current_user_can('manage_options');
    }

    private function __construct()
    {
        add_action('init', array($this, 'register_rewrite'));
        add_filter('query_vars', array($this, 'query_vars'));
        add_action('template_redirect', array($this, 'maybe_handle_callback'));
        add_action('login_form', array($this, 'render_login_button'));
        add_action('admin_menu', array($this, 'register_admin_page'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('admin_notices', array($this, 'render_notices'));
        add_action('show_user_profile', array($this, 'render_profile_connection'));
        add_action('edit_user_profile', array($this, 'render_profile_connection'));
        add_action('personal_options_update', array($this, 'save_profile_groups'));
        add_action('edit_user_profile_update', array($this, 'save_profile_groups'));
        add_action('admin_post_ssf_m365_login_start', array($this, 'start_login'));
        add_action('admin_post_nopriv_ssf_m365_login_start', array($this, 'start_login'));
        add_action('admin_post_ssf_m365_link_start', array($this, 'start_link'));
        add_action('admin_post_ssf_m365_unlink', array($this, 'unlink_account'));
        add_action('admin_post_ssf_m365_admin_unlink', array($this, 'admin_unlink_account'));
        add_action('admin_post_ssf_m365_test_config', array($this, 'test_configuration'));
        add_action('admin_post_ssf_m365_test_login', array($this, 'start_real_login_test'));
        add_action('admin_post_ssf_save_permission_groups', array($this, 'save_permission_groups'));
        add_filter('user_has_cap', array($this, 'grant_group_capabilities'), 10, 4);
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
            add_submenu_page(SSF_Admin_Navigation::ROOT, __('Microsoft-inloggning', 'ssf-microsoft-login'), __('Inloggning', 'ssf-microsoft-login'), self::CAP_MANAGE_LOGIN, self::MENU_SLUG, array($this, 'render_admin_page'));
            return;
        }
        add_management_page(__('Microsoft-inloggning', 'ssf-microsoft-login'), __('Microsoft-inloggning', 'ssf-microsoft-login'), self::CAP_MANAGE_LOGIN, self::MENU_SLUG, array($this, 'render_admin_page'));
    }

    public function enqueue_admin_assets(string $hook): void
    {
        if (false === strpos($hook, self::MENU_SLUG)) {
            return;
        }
        wp_enqueue_script('ssf-microsoft-login-admin', plugins_url('assets/js/admin.js', __FILE__), array(), self::VERSION, true);
    }

    public function render_admin_page(): void
    {
        if (! $this->can_manage_login()) {
            return;
        }
        $status = $this->status();
        $linked_count = $this->linked_user_count();
        $connection_test = get_transient(self::TEST_PREFIX . 'config_' . get_current_user_id());
        $login_test = get_transient(self::TEST_PREFIX . 'login_' . get_current_user_id());
        ?>
        <div class="wrap ssf-microsoft-login-admin">
            <h1><?php esc_html_e('Microsoft-inloggning', 'ssf-microsoft-login'); ?></h1>
            <?php if (class_exists('SSF_Admin_Navigation')) { SSF_Admin_Navigation::render_system_tabs(self::MENU_SLUG); } ?>
            <p><?php esc_html_e('Microsoft 365 används för att verifiera vem användaren är. Behörigheter till SSF:s funktioner styrs i WordPress.', 'ssf-microsoft-login'); ?></p>

            <div class="ssf-admin-grid ssf-admin-grid--system">
                <section class="ssf-admin-card">
                    <h2><?php esc_html_e('Översikt', 'ssf-microsoft-login'); ?></h2>
                    <dl>
                        <div><dt><?php esc_html_e('Miljö', 'ssf-microsoft-login'); ?></dt><dd><?php echo esc_html(ucfirst($status['environment'])); ?></dd></div>
                        <div><dt><?php esc_html_e('Status', 'ssf-microsoft-login'); ?></dt><dd><?php echo esc_html($this->status_label($status)); ?></dd></div>
                        <div><dt><?php esc_html_e('Microsoft-app', 'ssf-microsoft-login'); ?></dt><dd><?php echo esc_html($this->configured_label($status['tenant_id'] && $status['client_id'] && $status['client_secret'])); ?></dd></div>
                        <div><dt><?php esc_html_e('OpenID', 'ssf-microsoft-login'); ?></dt><dd><?php echo esc_html($status['metadata']); ?></dd></div>
                        <div><dt><?php esc_html_e('Callback', 'ssf-microsoft-login'); ?></dt><dd><?php echo esc_html($status['callback']); ?></dd></div>
                        <div><dt><?php esc_html_e('Kopplade användare', 'ssf-microsoft-login'); ?></dt><dd><?php echo esc_html((string) $linked_count); ?></dd></div>
                    </dl>
                    <p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ssf_m365_test_config'), 'ssf_m365_test_config')); ?>"><?php esc_html_e('Testa Microsoft-konfiguration', 'ssf-microsoft-login'); ?></a></p>
                    <p><a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ssf_m365_test_login'), 'ssf_m365_test_login')); ?>"><?php esc_html_e('Testa riktig Microsoft-inloggning', 'ssf-microsoft-login'); ?></a></p>
                </section>
                <section class="ssf-admin-card">
                    <h2><?php esc_html_e('Microsoft / Entra', 'ssf-microsoft-login'); ?></h2>
                    <dl>
                        <div><dt><?php esc_html_e('Tenant ID', 'ssf-microsoft-login'); ?></dt><dd><?php echo esc_html($this->configured_label($status['tenant_id'])); ?></dd></div>
                        <div><dt><?php esc_html_e('Client ID', 'ssf-microsoft-login'); ?></dt><dd><?php echo esc_html($this->configured_label($status['client_id'])); ?></dd></div>
                        <div><dt><?php esc_html_e('Client Secret', 'ssf-microsoft-login'); ?></dt><dd><?php echo esc_html($this->configured_label($status['client_secret'])); ?></dd></div>
                        <div><dt><?php esc_html_e('Appnamn', 'ssf-microsoft-login'); ?></dt><dd>SSF Web Login DEV</dd></div>
                        <div><dt><?php esc_html_e('Kontotyp', 'ssf-microsoft-login'); ?></dt><dd><?php esc_html_e('Endast konton i SSF:s organisation', 'ssf-microsoft-login'); ?></dd></div>
                        <div><dt><?php esc_html_e('Scopes', 'ssf-microsoft-login'); ?></dt><dd><code>openid profile email</code></dd></div>
                        <div><dt><?php esc_html_e('SharePoint permissions', 'ssf-microsoft-login'); ?></dt><dd><?php esc_html_e('Inga', 'ssf-microsoft-login'); ?></dd></div>
                        <div><dt><?php esc_html_e('Graph application permissions', 'ssf-microsoft-login'); ?></dt><dd><?php esc_html_e('Inga', 'ssf-microsoft-login'); ?></dd></div>
                    </dl>
                    <p><label for="ssf-m365-callback"><strong><?php esc_html_e('Redirect URI', 'ssf-microsoft-login'); ?></strong></label><br><input id="ssf-m365-callback" class="regular-text code" value="<?php echo esc_attr($this->callback_url()); ?>" readonly> <button type="button" class="button" data-ssf-copy="#ssf-m365-callback"><?php esc_html_e('Kopiera callback-URL', 'ssf-microsoft-login'); ?></button></p>
                </section>
                <section class="ssf-admin-card">
                    <h2><?php esc_html_e('Ditt Microsoft-konto', 'ssf-microsoft-login'); ?></h2>
                    <?php $this->render_own_account_card(); ?>
                </section>
            </div>

            <?php $this->render_test_results($connection_test, $login_test); ?>
            <?php $this->render_users_and_permissions(); ?>
            <?php $this->render_permission_matrix(); ?>
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
        echo '<p style="text-align:center;margin:16px 0 8px;">' . esc_html__('eller', 'ssf-microsoft-login') . '</p>';
        echo '<p><a class="button button-secondary button-large" style="width:100%;text-align:center;" href="' . esc_url(self::login_url($redirect_to)) . '">' . esc_html__('Logga in med Microsoft 365', 'ssf-microsoft-login') . '</a></p>';
    }

    private function render_own_account_card(): void
    {
        $user_id = get_current_user_id();
        $linked = $this->is_user_linked($user_id);
        ?>
        <p><strong><?php esc_html_e('Status:', 'ssf-microsoft-login'); ?></strong> <?php echo esc_html($linked ? __('Kopplat', 'ssf-microsoft-login') : __('Inte kopplat', 'ssf-microsoft-login')); ?></p>
        <?php if ($linked) : ?>
            <p><strong><?php esc_html_e('Konto:', 'ssf-microsoft-login'); ?></strong> <?php echo esc_html($this->linked_email_label($user_id)); ?></p>
            <p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ssf_m365_test_login'), 'ssf_m365_test_login')); ?>"><?php esc_html_e('Testa inloggning', 'ssf-microsoft-login'); ?></a></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ssf_m365_unlink">
                <?php wp_nonce_field('ssf_m365_unlink'); ?>
                <?php submit_button(__('Koppla från konto', 'ssf-microsoft-login'), 'secondary', 'submit', false); ?>
            </form>
        <?php else : ?>
            <p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ssf_m365_link_start'), 'ssf_m365_link_start')); ?>"><?php esc_html_e('Koppla Microsoft 365-konto', 'ssf-microsoft-login'); ?></a></p>
        <?php endif; ?>
        <?php
    }

    private function render_test_results($connection_test, $login_test): void
    {
        if (is_array($connection_test)) {
            echo '<h2>' . esc_html__('Tekniskt konfigurationstest', 'ssf-microsoft-login') . '</h2>';
            $this->render_check_table($connection_test);
        }
        if (is_array($login_test)) {
            echo '<h2>' . esc_html__('Riktigt Microsoft-inloggningstest', 'ssf-microsoft-login') . '</h2>';
            $this->render_check_table($login_test);
        }
    }

    private function render_check_table(array $checks): void
    {
        echo '<table class="widefat striped" style="max-width:900px"><tbody>';
        foreach ($checks as $label => $result) {
            printf('<tr><th>%s</th><td>%s</td></tr>', esc_html((string) $label), esc_html(! empty($result) ? 'PASS' : 'FAIL'));
        }
        echo '</tbody></table>';
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
        <h2><?php esc_html_e('Användare och behörigheter', 'ssf-microsoft-login'); ?></h2>
        <p><a href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)); ?>"><?php esc_html_e('Alla användare', 'ssf-microsoft-login'); ?></a> | <a href="<?php echo esc_url(add_query_arg('ssf_m365_filter', 'linked', admin_url('admin.php?page=' . self::MENU_SLUG))); ?>"><?php esc_html_e('Kopplade användare', 'ssf-microsoft-login'); ?></a> | <a href="<?php echo esc_url(add_query_arg('ssf_m365_filter', 'unlinked', admin_url('admin.php?page=' . self::MENU_SLUG))); ?>"><?php esc_html_e('Ej kopplade användare', 'ssf-microsoft-login'); ?></a></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="ssf_save_permission_groups">
            <?php wp_nonce_field('ssf_save_permission_groups'); ?>
            <table class="widefat striped">
                <thead><tr><th><?php esc_html_e('WordPress-användare', 'ssf-microsoft-login'); ?></th><th><?php esc_html_e('Namn', 'ssf-microsoft-login'); ?></th><th><?php esc_html_e('E-post', 'ssf-microsoft-login'); ?></th><th><?php esc_html_e('Microsoft', 'ssf-microsoft-login'); ?></th><th><?php esc_html_e('Behörighetsgrupper', 'ssf-microsoft-login'); ?></th><th><?php esc_html_e('WordPress-roll', 'ssf-microsoft-login'); ?></th><th><?php esc_html_e('Senast inloggad', 'ssf-microsoft-login'); ?></th><th><?php esc_html_e('Åtgärd', 'ssf-microsoft-login'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($users as $user) : $linked = $this->is_user_linked((int) $user->ID); if ('linked' === $filter && ! $linked) { continue; } if ('unlinked' === $filter && $linked) { continue; } ?>
                    <tr>
                        <td><input type="hidden" name="user_ids[]" value="<?php echo esc_attr((string) $user->ID); ?>"><?php echo esc_html($user->user_login); ?></td>
                        <td><?php echo esc_html($user->display_name); ?></td>
                        <td><?php echo esc_html($user->user_email); ?></td>
                        <td><?php echo esc_html($linked ? __('Kopplad', 'ssf-microsoft-login') : __('Inte kopplad', 'ssf-microsoft-login')); ?><?php if ($linked) : ?><br><span class="description"><?php echo esc_html($this->linked_email_label((int) $user->ID)); ?></span><?php endif; ?></td>
                        <td><?php foreach ($groups as $key => $group) : ?><label style="display:block"><input type="checkbox" name="groups[<?php echo esc_attr((string) $user->ID); ?>][]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $this->user_groups((int) $user->ID), true)); ?> <?php disabled((int) $user->ID === get_current_user_id() && ! current_user_can('manage_options')); ?>> <?php echo esc_html($group['label']); ?></label><?php endforeach; ?></td>
                        <td><?php echo esc_html(implode(', ', $user->roles)); ?></td>
                        <td><?php $last = (int) get_user_meta($user->ID, self::META_LAST_LOGIN, true); echo esc_html($last ? wp_date('Y-m-d H:i', $last) : ''); ?></td>
                        <td><?php if ($linked) : ?><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ssf_m365_admin_unlink&user_id=' . (int) $user->ID), 'ssf_m365_admin_unlink_' . (int) $user->ID)); ?>"><?php esc_html_e('Koppla från', 'ssf-microsoft-login'); ?></a><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php submit_button(__('Spara behörigheter', 'ssf-microsoft-login')); ?>
        </form>
        <?php
    }

    private function render_permission_matrix(): void
    {
        echo '<h2>' . esc_html__('Behörighetsmatris', 'ssf-microsoft-login') . '</h2>';
        echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>' . esc_html__('Grupp', 'ssf-microsoft-login') . '</th><th>' . esc_html__('Beskrivning', 'ssf-microsoft-login') . '</th><th>' . esc_html__('Faktiska WordPress-capabilities', 'ssf-microsoft-login') . '</th></tr></thead><tbody>';
        foreach (self::permission_groups() as $group) {
            printf('<tr><th>%s</th><td>%s</td><td><code>%s</code></td></tr>', esc_html($group['label']), esc_html($group['description']), esc_html(implode(', ', (array) $group['capabilities'])));
        }
        echo '</tbody></table>';
    }

    private function render_technical_details(array $status, int $linked_count): void
    {
        ?>
        <details>
            <summary><?php esc_html_e('Tekniska detaljer', 'ssf-microsoft-login'); ?></summary>
            <table class="widefat striped" style="max-width:900px"><tbody>
                <tr><th><?php esc_html_e('Environment', 'ssf-microsoft-login'); ?></th><td><?php echo esc_html($status['environment']); ?></td></tr>
                <tr><th><?php esc_html_e('Plugin version', 'ssf-microsoft-login'); ?></th><td><?php echo esc_html(self::VERSION); ?></td></tr>
                <tr><th><?php esc_html_e('Callback URL', 'ssf-microsoft-login'); ?></th><td><code><?php echo esc_html($this->callback_url()); ?></code></td></tr>
                <tr><th><?php esc_html_e('OpenID issuer', 'ssf-microsoft-login'); ?></th><td><code><?php echo esc_html($this->config('tenant_id') ? 'https://login.microsoftonline.com/' . $this->config('tenant_id') . '/v2.0' : ''); ?></code></td></tr>
                <tr><th><?php esc_html_e('Tenant configured', 'ssf-microsoft-login'); ?></th><td><?php echo esc_html($this->configured_label($status['tenant_id'])); ?></td></tr>
                <tr><th><?php esc_html_e('Client configured', 'ssf-microsoft-login'); ?></th><td><?php echo esc_html($this->configured_label($status['client_id'])); ?></td></tr>
                <tr><th><?php esc_html_e('JWKS cache status', 'ssf-microsoft-login'); ?></th><td><?php echo esc_html($status['jwks']); ?></td></tr>
                <tr><th><?php esc_html_e('Linked users', 'ssf-microsoft-login'); ?></th><td><?php echo esc_html((string) $linked_count); ?></td></tr>
            </tbody></table>
        </details>
        <?php
    }

    private function status_label(array $status): string
    {
        if ('development' !== $status['environment']) {
            return __('Endast DEV', 'ssf-microsoft-login');
        }
        return ! empty($status['enabled']) ? __('Aktiv', 'ssf-microsoft-login') : __('Ej aktiv', 'ssf-microsoft-login');
    }

    private function configured_label(bool $configured): string
    {
        return $configured ? __('Konfigurerad', 'ssf-microsoft-login') : __('Saknas', 'ssf-microsoft-login');
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
        return '' !== $email ? $email : __('Microsoft-ID kopplat', 'ssf-microsoft-login');
    }

    public function render_profile_connection($user): void
    {
        if (! $user instanceof WP_User) {
            return;
        }
        $is_own_profile = (int) get_current_user_id() === (int) $user->ID;
        $linked = $this->is_user_linked((int) $user->ID);
        ?>
        <h2><?php esc_html_e('Microsoft 365-konto', 'ssf-microsoft-login'); ?></h2>
        <table class="form-table" role="presentation"><tr><th><?php esc_html_e('Status', 'ssf-microsoft-login'); ?></th><td>
            <p><?php echo esc_html($linked ? __('Microsoft 365-kontot är kopplat.', 'ssf-microsoft-login') : __('Inget Microsoft 365-konto är kopplat.', 'ssf-microsoft-login')); ?></p>
            <?php if ($is_own_profile && $this->is_enabled()) : ?>
            <?php if ($linked) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="ssf_m365_unlink">
                    <?php wp_nonce_field('ssf_m365_unlink'); ?>
                    <?php submit_button(__('Koppla från Microsoft 365', 'ssf-microsoft-login'), 'secondary', 'submit', false); ?>
                </form>
            <?php else : ?>
                <p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ssf_m365_link_start'), 'ssf_m365_link_start')); ?>"><?php esc_html_e('Koppla Microsoft 365-konto', 'ssf-microsoft-login'); ?></a></p>
            <?php endif; ?>
            <?php endif; ?>
        </td></tr>
        <?php if ($this->can_manage_permission_groups()) : ?>
            <tr><th><?php esc_html_e('SSF-behorighetsgrupper', 'ssf-microsoft-login'); ?></th><td>
                <?php wp_nonce_field('ssf_m365_profile_groups_' . (int) $user->ID, 'ssf_m365_profile_groups_nonce'); ?>
                <?php foreach (self::permission_groups() as $key => $group) : ?>
                    <label style="display:block"><input type="checkbox" name="ssf_permission_groups[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $this->user_groups((int) $user->ID), true)); ?> <?php disabled((int) $user->ID === get_current_user_id() && ! current_user_can('manage_options')); ?>> <?php echo esc_html($group['label']); ?></label>
                <?php endforeach; ?>
                <p class="description"><?php esc_html_e('Microsoft bekraftar identitet. Dessa WordPress-grupper styr atkomst i SSF.', 'ssf-microsoft-login'); ?></p>
            </td></tr>
        <?php endif; ?>
        </table>
        <?php
    }

    public function save_profile_groups(int $user_id): void
    {
        if (! $this->can_manage_permission_groups() || ! isset($_POST['ssf_m365_profile_groups_nonce'])) {
            return;
        }
        if ((int) $user_id === get_current_user_id() && ! current_user_can('manage_options')) {
            return;
        }
        $nonce = sanitize_text_field(wp_unslash($_POST['ssf_m365_profile_groups_nonce']));
        if (! wp_verify_nonce($nonce, 'ssf_m365_profile_groups_' . (int) $user_id)) {
            return;
        }
        $groups = isset($_POST['ssf_permission_groups']) && is_array($_POST['ssf_permission_groups']) ? array_map('sanitize_key', wp_unslash($_POST['ssf_permission_groups'])) : array();
        $this->save_user_groups((int) $user_id, $groups, get_current_user_id());
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
        delete_user_meta(get_current_user_id(), self::META_EMAIL);
        $this->set_notice(get_current_user_id(), 'success', __('Microsoft 365-kontot har kopplats från.', 'ssf-microsoft-login'));
        wp_safe_redirect(admin_url('profile.php'));
        exit;
    }

    public function admin_unlink_account(): void
    {
        $user_id = isset($_GET['user_id']) ? absint($_GET['user_id']) : 0;
        if (! $this->can_manage_login() || $user_id <= 0 || ! check_admin_referer('ssf_m365_admin_unlink_' . $user_id)) {
            wp_die(esc_html__('Du saknar behorighet.', 'ssf-microsoft-login'));
        }
        delete_user_meta($user_id, self::META_TID);
        delete_user_meta($user_id, self::META_OID);
        delete_user_meta($user_id, self::META_EMAIL);
        $this->set_notice(get_current_user_id(), 'success', __('Microsoft 365-kopplingen har tagits bort. WordPress-behorigheter andrades inte.', 'ssf-microsoft-login'));
        wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG));
        exit;
    }

    public function start_real_login_test(): void
    {
        if (! is_user_logged_in() || ! $this->can_manage_login() || ! check_admin_referer('ssf_m365_test_login')) {
            wp_die(esc_html__('Du saknar behorighet.', 'ssf-microsoft-login'));
        }
        $this->start_authorization('test', get_current_user_id(), admin_url('admin.php?page=' . self::MENU_SLUG));
    }

    public function save_permission_groups(): void
    {
        if (! $this->can_manage_permission_groups() || ! check_admin_referer('ssf_save_permission_groups')) {
            wp_die(esc_html__('Du saknar behorighet.', 'ssf-microsoft-login'));
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
        $this->set_notice(get_current_user_id(), 'success', __('Behorigheterna har sparats.', 'ssf-microsoft-login'));
        wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG));
        exit;
    }

    public function test_configuration(): void
    {
        if (! $this->can_manage_login() || ! check_admin_referer('ssf_m365_test_config')) {
            wp_die(esc_html__('Du saknar behörighet.', 'ssf-microsoft-login'));
        }
        $checks = $this->run_connection_checks();
        $passed = ! in_array(false, $checks, true);
        set_transient(self::TEST_PREFIX . 'config_' . get_current_user_id(), $checks, 10 * MINUTE_IN_SECONDS);
        set_transient(
            self::NOTICE_PREFIX . get_current_user_id(),
            array(
                'type' => $passed ? 'success' : 'error',
                'message' => $passed
                    ? __('Microsoft-konfigurationen kunde lasas.', 'ssf-microsoft-login')
                    : __('Microsoft-konfigurationen kunde inte verifieras.', 'ssf-microsoft-login'),
            ),
            MINUTE_IN_SECONDS
        );
        wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG));
        exit;
    }

    private function run_connection_checks(): array
    {
        $metadata = $this->is_configured() ? $this->discovery_metadata(false) : new WP_Error('not_configured', 'not_configured');
        $jwks = ! is_wp_error($metadata) ? $this->jwks(false) : new WP_Error('metadata_failed', 'metadata_failed');
        $tenant = $this->config('tenant_id');

        return array(
            'DEV-miljo' => 'development' === wp_get_environment_type(),
            'Microsoft-login aktiverad' => $this->is_enabled(),
            'Tenant ID finns' => '' !== $tenant,
            'Client ID finns' => '' !== $this->config('client_id'),
            'Client Secret finns' => '' !== $this->config('client_secret'),
            'OpenID discovery' => ! is_wp_error($metadata),
            'Issuer matchar tenant' => ! is_wp_error($metadata) && (($metadata['issuer'] ?? '') === 'https://login.microsoftonline.com/' . $tenant . '/v2.0'),
            'JWKS/signeringsnycklar' => ! is_wp_error($jwks) && ! empty($jwks['keys']),
            'Callback URL' => false !== strpos($this->callback_url(), self::CALLBACK_PATH),
            'Inga Graph- eller SharePoint-behorigheter behovs' => true,
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

    private function start_authorization(string $mode, int $user_id, string $redirect_to): void
    {
        if (! $this->is_enabled()) {
            wp_die(esc_html__('Microsoft 365-inloggning är inte aktiverad.', 'ssf-microsoft-login'));
        }
        if (in_array($mode, array('link', 'test'), true) && $user_id <= 0) {
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
        $email = $this->claim_email($claims);
        if (! $tid || ! $oid) {
            $this->deny(__('Microsoft-inloggningen kunde inte verifieras. Försök igen.', 'ssf-microsoft-login'));
        }
        if ('link' === ($transaction['mode'] ?? '')) {
            $this->complete_link($transaction, $tid, $oid, $email);
        }
        if ('test' === ($transaction['mode'] ?? '')) {
            $this->complete_real_login_test($transaction, $tid, $oid);
        }
        $this->complete_login($tid, $oid, (string) ($transaction['redirect_to'] ?? ''), $email);
    }

    private function complete_login(string $tid, string $oid, string $redirect_to, string $email = ''): void
    {
        $users = $this->users_for_identity($tid, $oid);
        if (1 !== count($users)) {
            $this->deny(__('Ditt Microsoft-konto är inte kopplat till ett SSF-konto.', 'ssf-microsoft-login'));
        }
        $user_id = (int) $users[0]->ID;
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
            $this->deny(__('Du måste vara inloggad för att koppla Microsoft 365-konto.', 'ssf-microsoft-login'));
        }
        foreach ($this->users_for_identity($tid, $oid) as $user) {
            if ((int) $user->ID !== $user_id) {
                $this->deny(__('Det här Microsoft-kontot är redan kopplat till ett annat SSF-konto.', 'ssf-microsoft-login'));
            }
        }
        update_user_meta($user_id, self::META_TID, $tid);
        update_user_meta($user_id, self::META_OID, $oid);
        if ('' !== $email) {
            update_user_meta($user_id, self::META_EMAIL, $email);
        }
        $this->set_notice($user_id, 'success', __('Microsoft 365-kontot är nu kopplat.', 'ssf-microsoft-login'));
        wp_safe_redirect(admin_url('profile.php'));
        exit;
    }

    private function complete_real_login_test(array $transaction, string $tid, string $oid): void
    {
        $user_id = (int) ($transaction['user_id'] ?? 0);
        if (! is_user_logged_in() || $user_id <= 0 || get_current_user_id() !== $user_id) {
            $this->deny(__('Testet maste avslutas med samma WordPress-session som startade det.', 'ssf-microsoft-login'));
        }
        $linked = (string) get_user_meta($user_id, self::META_TID, true) === $tid && (string) get_user_meta($user_id, self::META_OID, true) === $oid;
        set_transient(
            self::TEST_PREFIX . 'login_' . $user_id,
            array(
                'Microsoft-inloggning' => true,
                'State' => true,
                'Nonce' => true,
                'PKCE' => true,
                'ID-token signatur' => true,
                'Issuer' => true,
                'Audience' => true,
                'Tenant' => true,
                'tid mottaget' => '' !== $tid,
                'oid mottaget' => '' !== $oid,
                'Microsoft-identitet matchar kopplat konto' => $linked,
                'WordPress-behorigheter oforandrade' => true,
            ),
            10 * MINUTE_IN_SECONDS
        );
        wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG));
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
            'callback' => false !== strpos($this->callback_url(), self::CALLBACK_PATH) ? 'PASS' : 'FAIL',
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
