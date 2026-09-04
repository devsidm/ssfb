<?php
/**
 * Plugin Name: SSF Microsoft 365 Mailer
 * Plugin URI: https://github.com/devsidm/ssfb
 * Description: Skickar WordPress e-post via Microsoft 365 och Microsoft Graph med OAuth 2.0.
 * Version: 0.1.4
 * Author: SIDM
 * Text Domain: ssf-office365-mailer
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package SSF_Office365_Mailer
 */

if (! defined('ABSPATH')) {
    exit;
}

final class SSF_Office365_Mailer
{
    private const OPTION_SETTINGS = 'ssf_office365_mailer_settings';
    private const OPTION_TOKENS = 'ssf_office365_mailer_tokens';
    private const STATE_PREFIX = 'ssf_office365_mailer_state_';
    private const NOTICE_PREFIX = 'ssf_office365_mailer_notice_';
    private const MENU_SLUG = 'ssf-office365-mailer';

    private static ?SSF_Office365_Mailer $instance = null;

    public static function instance(): SSF_Office365_Mailer
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action('admin_menu', array($this, 'register_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_notices', array($this, 'render_admin_notices'));
        add_action('rest_api_init', array($this, 'register_oauth_callback'));
        add_action('rest_api_init', array($this, 'register_diagnostic_route'));
        add_action('admin_post_ssf_office365_connect', array($this, 'start_oauth'));
        add_action('admin_post_ssf_office365_disconnect', array($this, 'disconnect'));
        add_action('admin_post_ssf_office365_test_token', array($this, 'test_token'));
        add_action('admin_post_ssf_office365_send_test', array($this, 'send_test'));
        add_filter('pre_wp_mail', array($this, 'send_mail'), 1, 2);
    }

    public function register_settings_page(): void
    {
        if (class_exists('SSF_Admin_Navigation')) {
            add_submenu_page(
                null,
                __('SSF Microsoft 365 Mailer', 'ssf-office365-mailer'),
                __('SSF Microsoft 365 Mailer', 'ssf-office365-mailer'),
                'manage_options',
                self::MENU_SLUG,
                array($this, 'render_settings_page')
            );
            return;
        }

        add_options_page(
            __('SSF Microsoft 365 Mailer', 'ssf-office365-mailer'),
            __('SSF Microsoft 365 Mailer', 'ssf-office365-mailer'),
            'manage_options',
            self::MENU_SLUG,
            array($this, 'render_settings_page')
        );
    }

    public function register_settings(): void
    {
        register_setting('ssf_office365_mailer', self::OPTION_SETTINGS, array($this, 'sanitize_settings'));
    }

    public function register_oauth_callback(): void
    {
        register_rest_route(
            'ssf-office365-mailer/v1',
            '/oauth/callback',
            array(
                'methods' => 'GET',
                'callback' => array($this, 'handle_oauth_callback'),
                'permission_callback' => '__return_true',
            )
        );
    }

    public function register_diagnostic_route(): void
    {
        register_rest_route(
            'ssf-office365-mailer/v1',
            '/status',
            array(
                'methods' => 'GET',
                'callback' => array($this, 'diagnostic_status'),
                'permission_callback' => static function (): bool {
                    return current_user_can('manage_options');
                },
            )
        );
    }

    public function diagnostic_status(): WP_REST_Response
    {
        $settings = $this->settings();
        $tokens = $this->tokens();
        $last_result = (array) get_option('ssf_office365_mailer_last_result', array());

        return new WP_REST_Response(
            array(
                'enabled' => 'yes' === $settings['enabled'],
                'connected_email' => $tokens['email'] ?: '',
                'ready' => $this->is_ready(),
                'last_result' => array(
                    'status' => sanitize_key($last_result['status'] ?? ''),
                    'message' => sanitize_text_field($last_result['message'] ?? ''),
                    'at' => (int) ($last_result['at'] ?? 0),
                ),
            )
        );
    }

    public function sanitize_settings(array $input): array
    {
        $current = $this->settings();
        $settings = array(
            'enabled' => ! empty($input['enabled']) ? 'yes' : 'no',
            'client_id' => sanitize_text_field($input['client_id'] ?? ''),
            'tenant_id' => sanitize_text_field($input['tenant_id'] ?? 'organizations'),
            'client_secret' => $current['client_secret'],
        );

        if (! empty($input['client_secret'])) {
            $encrypted = $this->encrypt((string) $input['client_secret']);
            if ($encrypted) {
                $settings['client_secret'] = $encrypted;
            }
        }

        if (! $settings['tenant_id']) {
            $settings['tenant_id'] = 'organizations';
        }

        return $settings;
    }

    public function render_settings_page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $settings = $this->settings();
        $tokens = $this->tokens();
        $callback_url = $this->callback_url();
        $has_secret = ! empty($settings['client_secret']);
        $can_connect = $settings['client_id'] && $has_secret;
        $last_result = (array) get_option('ssf_office365_mailer_last_result', array());
        $test_recipient = sanitize_email((string) wp_get_current_user()->user_email);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('SSF Microsoft 365 Mailer', 'ssf-office365-mailer'); ?></h1>
            <?php if (class_exists('SSF_Admin_Navigation')) { SSF_Admin_Navigation::render_system_tabs(self::MENU_SLUG); } ?>
            <p><?php esc_html_e('Skickar WordPress e-post via den Microsoft 365-postlåda som godkänner anslutningen.', 'ssf-office365-mailer'); ?></p>

            <form method="post" action="options.php">
                <?php settings_fields('ssf_office365_mailer'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Aktivera Microsoft 365-mailer', 'ssf-office365-mailer'); ?></th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[enabled]" value="1" <?php checked('yes', $settings['enabled']); ?>> <?php esc_html_e('Skicka webbplatsens e-post via Microsoft 365 när anslutningen är klar.', 'ssf-office365-mailer'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ssf-office365-client-id"><?php esc_html_e('Azure Application (Client) ID', 'ssf-office365-mailer'); ?></label></th>
                        <td><input class="regular-text code" id="ssf-office365-client-id" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[client_id]" value="<?php echo esc_attr($settings['client_id']); ?>" autocomplete="off"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ssf-office365-tenant-id"><?php esc_html_e('Microsoft Entra Tenant ID', 'ssf-office365-mailer'); ?></label></th>
                        <td><input class="regular-text code" id="ssf-office365-tenant-id" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[tenant_id]" value="<?php echo esc_attr($settings['tenant_id']); ?>" autocomplete="off"><p class="description"><?php esc_html_e('Använd Directory (tenant) ID från Microsoft Entra. Värdet "organizations" fungerar också för arbetskonton.', 'ssf-office365-mailer'); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ssf-office365-client-secret"><?php esc_html_e('Azure Client Secret', 'ssf-office365-mailer'); ?></label></th>
                        <td><input class="regular-text" id="ssf-office365-client-secret" type="password" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[client_secret]" value="" autocomplete="new-password"><p class="description"><?php echo esc_html($has_secret ? __('Ett client secret är redan sparat. Lämna fältet tomt för att behålla det.', 'ssf-office365-mailer') : __('Ange Value från Certificates & secrets, inte Secret ID.', 'ssf-office365-mailer')); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Redirect URI', 'ssf-office365-mailer'); ?></th>
                        <td><code><?php echo esc_html($callback_url); ?></code><p class="description"><?php esc_html_e('Lägg in exakt denna Web-redirect URI i Microsoft Entra App registrations > Authentication.', 'ssf-office365-mailer'); ?></p></td>
                    </tr>
                </table>
                <?php submit_button(__('Spara inställningar', 'ssf-office365-mailer')); ?>
            </form>

            <hr>
            <h2><?php esc_html_e('Microsoft 365-anslutning', 'ssf-office365-mailer'); ?></h2>
            <?php if ('error' === ($last_result['status'] ?? '')) : ?><div class="notice notice-error inline"><p><strong><?php esc_html_e('Senaste anslutnings- eller e-postförsöket misslyckades.', 'ssf-office365-mailer'); ?></strong> <?php echo esc_html((string) ($last_result['message'] ?? '')); ?></p></div><?php elseif ('sent' === ($last_result['status'] ?? '')) : ?><div class="notice notice-success inline"><p><?php esc_html_e('Senaste e-postförsöket accepterades av Microsoft 365.', 'ssf-office365-mailer'); ?></p></div><?php elseif ('token_ok' === ($last_result['status'] ?? '')) : ?><div class="notice notice-success inline"><p><?php esc_html_e('Tokenförnyelsen och åtkomsten till Microsoft 365 är verifierad.', 'ssf-office365-mailer'); ?></p></div><?php endif; ?>
            <?php if (! empty($tokens['email'])) : ?>
                <p><strong><?php esc_html_e('Ansluten som:', 'ssf-office365-mailer'); ?></strong> <?php echo esc_html($tokens['email']); ?></p>
                <?php if ($can_connect) : ?><p><a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ssf_office365_connect'), 'ssf_office365_connect')); ?>"><?php esc_html_e('Återanslut Microsoft 365', 'ssf-office365-mailer'); ?></a></p><?php endif; ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="ssf_office365_disconnect">
                    <?php wp_nonce_field('ssf_office365_disconnect'); ?>
                    <?php submit_button(__('Koppla från Microsoft 365', 'ssf-office365-mailer'), 'secondary', 'submit', false); ?>
                </form>
            <?php elseif ($can_connect) : ?>
                <p><a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ssf_office365_connect'), 'ssf_office365_connect')); ?>"><?php esc_html_e('Anslut Microsoft 365', 'ssf-office365-mailer'); ?></a></p>
            <?php else : ?>
                <p><?php esc_html_e('Spara Azure Application Client ID och Client Secret innan du ansluter Microsoft 365.', 'ssf-office365-mailer'); ?></p>
            <?php endif; ?>

            <?php if ($this->is_ready()) : ?>
                <h3><?php esc_html_e('Testa token och e-post', 'ssf-office365-mailer'); ?></h3>
                <ol>
                    <li><?php esc_html_e('Klicka Testa token. Testet tvingar fram en tokenförnyelse och verifierar åtkomst till den anslutna postlådan.', 'ssf-office365-mailer'); ?></li>
                    <li><?php esc_html_e('När tokentestet är godkänt skickar du ett testmejl till en adress du kan kontrollera.', 'ssf-office365-mailer'); ?></li>
                    <li><?php esc_html_e('Kontrollera både inkorgen och skräpposten. Ett godkänt test visar att Microsoft 365 har accepterat meddelandet.', 'ssf-office365-mailer'); ?></li>
                </ol>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="ssf_office365_test_token">
                    <?php wp_nonce_field('ssf_office365_test_token'); ?>
                    <?php submit_button(__('Testa token', 'ssf-office365-mailer'), 'secondary', 'submit', false); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="ssf_office365_send_test">
                    <?php wp_nonce_field('ssf_office365_send_test'); ?>
                    <p><label for="ssf-office365-test-email"><strong><?php esc_html_e('Mottagare för testmejl', 'ssf-office365-mailer'); ?></strong></label><br><input class="regular-text" id="ssf-office365-test-email" type="email" name="test_email" value="<?php echo esc_attr($test_recipient); ?>" required></p>
                    <?php submit_button(__('Skicka testmejl', 'ssf-office365-mailer'), 'secondary', 'submit', false); ?>
                </form>
            <?php endif; ?>

            <h2><?php esc_html_e('Azure App registration', 'ssf-office365-mailer'); ?></h2>
            <ol>
                <li><?php esc_html_e('Öppna Microsoft Entra > App registrations > din app > Authentication och lägg till Redirect URI ovan som Web.', 'ssf-office365-mailer'); ?></li>
                <li><?php esc_html_e('Under API permissions, lägg till Microsoft Graph delegated permissions Mail.Send och User.Read och bevilja administratörsgodkännande vid behov.', 'ssf-office365-mailer'); ?></li>
                <li><?php esc_html_e('Anslut sedan med samma Microsoft 365-postlåda som ska skicka webbplatsens e-post.', 'ssf-office365-mailer'); ?></li>
            </ol>
        </div>
        <?php
    }

    public function start_oauth(): void
    {
        if (! current_user_can('manage_options') || ! check_admin_referer('ssf_office365_connect')) {
            wp_die(esc_html__('Du saknar behörighet.', 'ssf-office365-mailer'));
        }

        $settings = $this->settings();
        if (! $settings['client_id'] || ! $this->decrypt($settings['client_secret'])) {
            $this->set_notice(get_current_user_id(), 'error', __('Spara Azure Application Client ID och Client Secret innan anslutningen startas.', 'ssf-office365-mailer'));
            $this->redirect_to_settings();
        }

        $state = wp_generate_password(48, false, false);
        set_transient(
            self::STATE_PREFIX . $state,
            array(
                'user_id' => get_current_user_id(),
                'created_at' => time(),
            ),
            10 * MINUTE_IN_SECONDS
        );

        $query = array(
            'client_id' => $settings['client_id'],
            'response_type' => 'code',
            'redirect_uri' => $this->callback_url(),
            'response_mode' => 'query',
            'scope' => 'offline_access https://graph.microsoft.com/Mail.Send https://graph.microsoft.com/User.Read',
            'state' => $state,
            'prompt' => 'select_account',
        );
        $authorization_url = sprintf('https://login.microsoftonline.com/%s/oauth2/v2.0/authorize', rawurlencode($settings['tenant_id']));

        wp_redirect(add_query_arg($query, $authorization_url));
        exit;
    }

    public function handle_oauth_callback($request = null): void
    {
        $state = sanitize_text_field(wp_unslash($_GET['state'] ?? ''));
        $state_data = $state ? get_transient(self::STATE_PREFIX . $state) : false;
        if (! $state_data || empty($state_data['user_id'])) {
            wp_die(esc_html__('Microsoft 365-anslutningen kunde inte verifieras. Börja om från WordPress-inställningarna.', 'ssf-office365-mailer'));
        }
        delete_transient(self::STATE_PREFIX . $state);

        $user_id = (int) $state_data['user_id'];
        $error = sanitize_text_field(wp_unslash($_GET['error'] ?? ''));
        if ($error) {
            $this->set_notice($user_id, 'error', __('Microsoft 365-anslutningen avbröts eller nekades.', 'ssf-office365-mailer'));
            $this->redirect_to_settings();
        }

        $code = sanitize_text_field(wp_unslash($_GET['code'] ?? ''));
        if (! $code) {
            $this->set_notice($user_id, 'error', __('Microsoft 365 skickade ingen behörighetskod.', 'ssf-office365-mailer'));
            $this->redirect_to_settings();
        }

        $response = $this->request_token(array(
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->callback_url(),
        ));
        if (is_wp_error($response)) {
            $this->set_notice($user_id, 'error', $response->get_error_message());
            $this->redirect_to_settings();
        }

        $profile = $this->request_profile($response['access_token']);
        if (is_wp_error($profile)) {
            $this->set_notice($user_id, 'error', $profile->get_error_message());
            $this->redirect_to_settings();
        }

        $email = sanitize_email(($profile['mail'] ?? '') ?: ($profile['userPrincipalName'] ?? ''));
        if (! $email) {
            $this->set_notice($user_id, 'error', __('Kunde inte läsa en giltig avsändaradress från Microsoft 365-postlådan.', 'ssf-office365-mailer'));
            $this->redirect_to_settings();
        }

        if (! $this->save_tokens($response, $email)) {
            $this->set_notice($user_id, 'error', __('Microsoft 365-token kunde inte sparas säkert på servern.', 'ssf-office365-mailer'));
            $this->redirect_to_settings();
        }
        $settings = $this->settings();
        $settings['enabled'] = 'yes';
        update_option(self::OPTION_SETTINGS, $settings, false);
        do_action('ssf_office365_mailer_connected', $email);
        $this->set_notice($user_id, 'success', sprintf(__('Microsoft 365 är ansluten som %s.', 'ssf-office365-mailer'), $email));
        $this->redirect_to_settings();
    }

    public function disconnect(): void
    {
        if (! current_user_can('manage_options') || ! check_admin_referer('ssf_office365_disconnect')) {
            wp_die(esc_html__('Du saknar behörighet.', 'ssf-office365-mailer'));
        }

        delete_option(self::OPTION_TOKENS);
        $this->set_notice(get_current_user_id(), 'success', __('Microsoft 365-anslutningen har kopplats från.', 'ssf-office365-mailer'));
        $this->redirect_to_settings();
    }

    public function test_token(): void
    {
        if (! current_user_can('manage_options') || ! check_admin_referer('ssf_office365_test_token')) {
            wp_die(esc_html__('Du saknar behörighet.', 'ssf-office365-mailer'));
        }
        if (! $this->is_ready()) {
            $message = __('Microsoft 365 är inte fullständigt konfigurerat eller anslutet.', 'ssf-office365-mailer');
            $this->record_result('error', $message);
            $this->set_notice(get_current_user_id(), 'error', $message);
            $this->redirect_to_settings();
        }

        $access_token = $this->refresh_access_token();
        if (is_wp_error($access_token)) {
            $this->record_result('error', $access_token->get_error_message());
            $this->set_notice(get_current_user_id(), 'error', $access_token->get_error_message());
            $this->redirect_to_settings();
        }
        $profile = $this->request_profile($access_token);
        if (is_wp_error($profile)) {
            $this->record_result('error', $profile->get_error_message());
            $this->set_notice(get_current_user_id(), 'error', $profile->get_error_message());
            $this->redirect_to_settings();
        }

        $message = __('Tokenförnyelsen lyckades och Microsoft 365-postlådan kunde läsas.', 'ssf-office365-mailer');
        $this->record_result('token_ok', $message);
        $tokens = $this->tokens();
        do_action('ssf_office365_mailer_connected', (string) $tokens['email']);
        $this->set_notice(get_current_user_id(), 'success', $message);
        $this->redirect_to_settings();
    }

    public function send_test(): void
    {
        if (! current_user_can('manage_options') || ! check_admin_referer('ssf_office365_send_test')) {
            wp_die(esc_html__('Du saknar behörighet.', 'ssf-office365-mailer'));
        }
        $recipient = isset($_POST['test_email']) && is_scalar($_POST['test_email']) ? sanitize_email(wp_unslash($_POST['test_email'])) : '';
        if (! is_email($recipient)) {
            $this->set_notice(get_current_user_id(), 'error', __('Ange en giltig mottagaradress för testmejlet.', 'ssf-office365-mailer'));
            $this->redirect_to_settings();
        }
        if (! $this->is_ready()) {
            $this->set_notice(get_current_user_id(), 'error', __('Microsoft 365 är inte fullständigt konfigurerat eller anslutet.', 'ssf-office365-mailer'));
            $this->redirect_to_settings();
        }

        $subject = sprintf(__('Testmejl från %s', 'ssf-office365-mailer'), wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES));
        $message = implode("\n", array(
            __('Detta är ett test av webbplatsens Microsoft 365-anslutning.', 'ssf-office365-mailer'),
            __('Om du har fått meddelandet fungerar både tokenförnyelsen och e-postleveransen.', 'ssf-office365-mailer'),
            '',
            home_url('/'),
        ));
        if (wp_mail($recipient, $subject, $message, array('Content-Type: text/plain; charset=UTF-8'))) {
            $this->set_notice(get_current_user_id(), 'success', sprintf(__('Microsoft 365 accepterade testmejlet till %s. Kontrollera inkorg och skräppost.', 'ssf-office365-mailer'), $recipient));
        } else {
            $last_result = (array) get_option('ssf_office365_mailer_last_result', array());
            $this->set_notice(get_current_user_id(), 'error', (string) ($last_result['message'] ?? __('Testmejlet kunde inte skickas.', 'ssf-office365-mailer')));
        }
        $this->redirect_to_settings();
    }

    public function send_mail($pre_wp_mail, array $args)
    {
        if (null !== $pre_wp_mail || ! $this->is_ready()) {
            return $pre_wp_mail;
        }

        $access_token = $this->access_token();
        if (is_wp_error($access_token)) {
            $this->mail_failed($args, $access_token);
            return false;
        }

        $recipients = $this->recipient_list($args['to'] ?? array());
        if (! $recipients) {
            $error = new WP_Error('ssf_office365_missing_recipient', __('E-post saknar en giltig mottagare.', 'ssf-office365-mailer'));
            $this->mail_failed($args, $error);
            return false;
        }

        $headers = $this->parse_headers($args['headers'] ?? array());
        $message = array(
            'subject' => (string) ($args['subject'] ?? ''),
            'body' => array(
                'contentType' => $headers['content_type'],
                'content' => (string) ($args['message'] ?? ''),
            ),
            'toRecipients' => $recipients,
        );
        if ($headers['cc']) {
            $message['ccRecipients'] = $headers['cc'];
        }
        if ($headers['bcc']) {
            $message['bccRecipients'] = $headers['bcc'];
        }
        if ($headers['reply_to']) {
            $message['replyTo'] = $headers['reply_to'];
        }

        $response = wp_remote_post(
            'https://graph.microsoft.com/v1.0/me/sendMail',
            array(
                'timeout' => 25,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/json',
                ),
                'body' => wp_json_encode(array('message' => $message, 'saveToSentItems' => true)),
            )
        );

        if (is_wp_error($response)) {
            $details = $response->get_error_message();
            $error = new WP_Error('ssf_office365_send_failed', sprintf(__('Microsoft 365 kunde inte skicka e-post (%s).', 'ssf-office365-mailer'), $details));
            $this->mail_failed($args, $error);
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if (202 !== (int) $status_code) {
            $details = $this->graph_error_message(wp_remote_retrieve_body($response));
            $error = new WP_Error('ssf_office365_send_failed', sprintf(__('Microsoft 365 kunde inte skicka e-post (%s).', 'ssf-office365-mailer'), $details));
            $this->mail_failed($args, $error);
            return false;
        }

        $this->record_result('sent');
        do_action('wp_mail_succeeded', $args);
        return true;
    }

    public function render_admin_notices(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $notice = get_transient(self::NOTICE_PREFIX . get_current_user_id());
        if ($notice) {
            delete_transient(self::NOTICE_PREFIX . get_current_user_id());
            printf('<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr($notice['type']), esc_html($notice['message']));
        }

        if ('yes' === $this->settings()['enabled'] && $this->is_ready() && defined('WPMS_PLUGIN_FILE')) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('SSF Microsoft 365 Mailer är aktiv. Inaktivera WP Mail SMTP när Microsoft 365-testet är godkänt för att undvika dubbla mailer-inställningar.', 'ssf-office365-mailer') . '</p></div>';
        }
    }

    private function settings(): array
    {
        return wp_parse_args(
            (array) get_option(self::OPTION_SETTINGS, array()),
            array(
                'enabled' => 'no',
                'client_id' => '',
                'tenant_id' => 'organizations',
                'client_secret' => '',
            )
        );
    }

    private function tokens(): array
    {
        return wp_parse_args(
            (array) get_option(self::OPTION_TOKENS, array()),
            array(
                'access_token' => '',
                'refresh_token' => '',
                'expires_at' => 0,
                'email' => '',
            )
        );
    }

    private function is_ready(): bool
    {
        $settings = $this->settings();
        $tokens = $this->tokens();
        return 'yes' === $settings['enabled'] && ! empty($settings['client_id']) && ! empty($settings['client_secret']) && ! empty($tokens['refresh_token']) && ! empty($tokens['email']);
    }

    private function callback_url(): string
    {
        return rest_url('ssf-office365-mailer/v1/oauth/callback');
    }

    private function request_token(array $grant)
    {
        $settings = $this->settings();
        $secret = $this->decrypt($settings['client_secret']);
        if (! $secret) {
            return new WP_Error('ssf_office365_secret', __('Azure Client Secret saknas eller kunde inte läsas.', 'ssf-office365-mailer'));
        }

        $body = array_merge(
            array(
                'client_id' => $settings['client_id'],
                'client_secret' => $secret,
            ),
            $grant
        );
        $url = sprintf('https://login.microsoftonline.com/%s/oauth2/v2.0/token', rawurlencode($settings['tenant_id']));
        $response = wp_remote_post($url, array('timeout' => 25, 'body' => $body));
        if (is_wp_error($response)) {
            return new WP_Error('ssf_office365_token_request', __('Kunde inte kontakta Microsoft 365 för en åtkomsttoken.', 'ssf-office365-mailer'));
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (200 !== (int) wp_remote_retrieve_response_code($response) || empty($data['access_token'])) {
            return new WP_Error('ssf_office365_token_response', sprintf(__('Microsoft 365 kunde inte skapa en åtkomsttoken (%s).', 'ssf-office365-mailer'), $this->graph_error_message(wp_remote_retrieve_body($response))));
        }

        return $data;
    }

    private function request_profile(string $access_token)
    {
        $response = wp_remote_get(
            'https://graph.microsoft.com/v1.0/me?$select=displayName,mail,userPrincipalName',
            array(
                'timeout' => 25,
                'headers' => array('Authorization' => 'Bearer ' . $access_token),
            )
        );
        if (is_wp_error($response)) {
            return new WP_Error('ssf_office365_profile', __('Microsoft 365 kunde inte läsa postlådan som godkände anslutningen.', 'ssf-office365-mailer'));
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (200 !== (int) wp_remote_retrieve_response_code($response) || empty($data)) {
            return new WP_Error('ssf_office365_profile', __('Microsoft 365 kunde inte läsa postlådan som godkände anslutningen.', 'ssf-office365-mailer'));
        }

        return $data;
    }

    private function access_token()
    {
        $tokens = $this->tokens();
        $access_token = $this->decrypt($tokens['access_token']);
        if ($access_token && ((int) $tokens['expires_at'] > time() + 120)) {
            return $access_token;
        }

        return $this->refresh_access_token();
    }

    private function refresh_access_token()
    {
        $tokens = $this->tokens();

        $refresh_token = $this->decrypt($tokens['refresh_token']);
        if (! $refresh_token) {
            return new WP_Error('ssf_office365_reconnect', __('Microsoft 365-anslutningen har gått ut. Anslut kontot igen under Inställningar > SSF Microsoft 365 Mailer.', 'ssf-office365-mailer'));
        }

        $response = $this->request_token(array(
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh_token,
            'scope' => 'offline_access https://graph.microsoft.com/Mail.Send https://graph.microsoft.com/User.Read',
        ));
        if (is_wp_error($response)) {
            return $response;
        }

        if (! $this->save_tokens($response, $tokens['email'], $refresh_token)) {
            return new WP_Error('ssf_office365_token_save', __('Den förnyade Microsoft 365-tokenen kunde inte sparas säkert.', 'ssf-office365-mailer'));
        }
        return $response['access_token'];
    }

    private function save_tokens(array $response, string $email, string $fallback_refresh_token = ''): bool
    {
        $access_token = $this->encrypt((string) $response['access_token']);
        $refresh_token = $this->encrypt((string) ($response['refresh_token'] ?? $fallback_refresh_token));
        if (! $access_token || ! $refresh_token) {
            return false;
        }

        return update_option(
            self::OPTION_TOKENS,
            array(
                'access_token' => $access_token,
                'refresh_token' => $refresh_token,
                'expires_at' => time() + max(60, (int) ($response['expires_in'] ?? 3600)),
                'email' => sanitize_email($email),
            ),
            false
        );
    }

    private function recipient_list($recipients): array
    {
        $parsed = array();
        foreach (wp_parse_list($recipients) as $recipient) {
            $recipient = trim((string) $recipient);
            $name = '';
            $email = $recipient;
            if (preg_match('/^(.*)<([^>]+)>$/', $recipient, $matches)) {
                $name = trim(trim($matches[1]), '" ');
                $email = trim($matches[2]);
            }
            if (! is_email($email)) {
                continue;
            }
            $email_address = array('address' => sanitize_email($email));
            if ($name) {
                $email_address['name'] = sanitize_text_field($name);
            }
            $parsed[] = array('emailAddress' => $email_address);
        }

        return $parsed;
    }

    private function parse_headers($headers): array
    {
        $parsed = array(
            'content_type' => 'Text',
            'cc' => array(),
            'bcc' => array(),
            'reply_to' => array(),
        );
        $lines = is_array($headers) ? $headers : preg_split('/\r?\n/', (string) $headers);
        foreach ((array) $lines as $line) {
            if (false === strpos($line, ':')) {
                continue;
            }
            list($name, $value) = array_map('trim', explode(':', $line, 2));
            switch (strtolower($name)) {
                case 'content-type':
                    if (false !== stripos($value, 'text/html')) {
                        $parsed['content_type'] = 'HTML';
                    }
                    break;
                case 'cc':
                    $parsed['cc'] = $this->recipient_list($value);
                    break;
                case 'bcc':
                    $parsed['bcc'] = $this->recipient_list($value);
                    break;
                case 'reply-to':
                    $parsed['reply_to'] = $this->recipient_list($value);
                    break;
            }
        }

        return $parsed;
    }

    private function graph_error_message(string $body): string
    {
        $data = json_decode($body, true);
        if (! empty($data['error']['message'])) {
            return sanitize_text_field($data['error']['message']);
        }
        if (! empty($data['error_description'])) {
            return sanitize_text_field($data['error_description']);
        }
        return __('Okänt fel', 'ssf-office365-mailer');
    }

    private function mail_failed(array $args, WP_Error $error): void
    {
        $this->record_result('error', $error->get_error_message());
        do_action('wp_mail_failed', $error);
    }

    private function record_result(string $status, string $message = ''): void
    {
        update_option('ssf_office365_mailer_last_result', array(
            'status' => sanitize_key($status),
            'message' => sanitize_text_field($message),
            'at' => time(),
        ), false);
    }

    private function encrypt(string $value): string
    {
        if (! function_exists('openssl_encrypt') || ! $value) {
            return '';
        }

        $cipher = 'aes-256-cbc';
        $iv_length = openssl_cipher_iv_length($cipher);
        $iv = random_bytes($iv_length);
        $encrypted = openssl_encrypt($value, $cipher, $this->encryption_key(), OPENSSL_RAW_DATA, $iv);
        return $encrypted ? base64_encode($iv . $encrypted) : '';
    }

    private function decrypt(string $value): string
    {
        if (! function_exists('openssl_decrypt') || ! $value) {
            return '';
        }

        $cipher = 'aes-256-cbc';
        $decoded = base64_decode($value, true);
        $iv_length = openssl_cipher_iv_length($cipher);
        if (! $decoded || strlen($decoded) <= $iv_length) {
            return '';
        }
        $iv = substr($decoded, 0, $iv_length);
        $encrypted = substr($decoded, $iv_length);
        $decrypted = openssl_decrypt($encrypted, $cipher, $this->encryption_key(), OPENSSL_RAW_DATA, $iv);
        return is_string($decrypted) ? $decrypted : '';
    }

    private function encryption_key(): string
    {
        return hash('sha256', wp_salt('auth') . wp_salt('secure_auth'), true);
    }

    private function set_notice(int $user_id, string $type, string $message): void
    {
        set_transient(self::NOTICE_PREFIX . $user_id, array('type' => $type, 'message' => $message), MINUTE_IN_SECONDS);
    }

    private function redirect_to_settings(): void
    {
        $path = class_exists('SSF_Admin_Navigation') ? 'admin.php?page=' : 'options-general.php?page=';
        wp_safe_redirect(admin_url($path . self::MENU_SLUG));
        exit;
    }
}

SSF_Office365_Mailer::instance();
