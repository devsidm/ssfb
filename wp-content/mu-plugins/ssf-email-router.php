<?php
/**
 * Plugin Name: SSF Email Router
 * Description: Central environment-aware routing for SSF internal notifications.
 * Version: 1.0.0
 * Author: SIDM
 */

if (! defined('ABSPATH')) {
    exit;
}

final class SSF_Email_Router
{
    private const OPTION = 'ssf_email_routing';
    private const NOTICE_PREFIX = 'ssf_email_router_notice_';
    private const ADMIN_PAGE = 'ssf-member-portal-microsoft365';

    public static function boot(): void
    {
        add_action('admin_post_ssf_email_router_save', array(__CLASS__, 'handle_save'));
        add_action('admin_post_ssf_email_router_test', array(__CLASS__, 'handle_test'));
        add_action('admin_notices', array(__CLASS__, 'render_admin_notice'));
    }

    public static function definitions(): array
    {
        $development = 'johan.linde@ssfb.se';
        $definitions = array(
            'annual_meeting_motion' => array(
                'label' => 'Motioner',
                'description' => 'Intern notifiering när en ny motion lämnas in.',
                'production' => 'styrelsen@ssfb.se',
                'development' => $development,
            ),
            'annual_meeting_registration' => array(
                'label' => 'Årsmötesanmälningar',
                'description' => 'Intern notifiering när en deltagare anmäler sig till årsmöteshelgen.',
                'production' => 'styrelsen@ssfb.se',
                'development' => $development,
            ),
            'membership_application' => array(
                'label' => 'Medlemsansökningar',
                'description' => 'Intern notifiering när en ny medlems- eller fartygsansökan kommer in.',
                'production' => 'medlem@ssfb.se',
                'development' => $development,
            ),
            'vessel_update' => array(
                'label' => 'Fartygsuppgifter',
                'description' => 'Intern notifiering när ett fartygsombud skickar in eller ändrar fartygsuppgifter.',
                'production' => 'medlem@ssfb.se',
                'development' => $development,
            ),
            'inspection_complete' => array(
                'label' => 'Inspektioner',
                'description' => 'Intern notifiering när inspektionsrapporter är klara för handläggning.',
                'production' => 'medlem@ssfb.se',
                'development' => $development,
            ),
            'contact_form' => array(
                'label' => 'Kontaktformulär',
                'description' => 'Meddelanden från webbplatsens allmänna kontaktformulär.',
                'production' => 'info@ssfb.se',
                'development' => $development,
            ),
            'contact_board' => array(
                'label' => 'Frågor till styrelsen',
                'description' => 'Meddelanden från kontaktformulär med årsmöteskontext.',
                'production' => 'styrelsen@ssfb.se',
                'development' => $development,
            ),
        );

        return (array) apply_filters('ssf_email_router_definitions', $definitions);
    }

    public static function environment(?string $environment = null): string
    {
        $environment = $environment ?: wp_get_environment_type();
        return 'production' === $environment ? 'production' : 'development';
    }

    public static function environment_label(?string $environment = null): string
    {
        return 'production' === self::environment($environment) ? 'Produktion' : 'Development';
    }

    public static function get_recipient(string $key, ?string $environment = null): string
    {
        $key = sanitize_key($key);
        $definitions = self::definitions();
        if (! isset($definitions[$key])) {
            return '';
        }

        $environment = self::environment($environment);
        $settings = self::settings();
        $configured = sanitize_email((string) ($settings[$key][$environment] ?? ''));
        if (is_email($configured)) {
            return $configured;
        }

        $fallback = sanitize_email((string) ($definitions[$key][$environment] ?? ''));
        return is_email($fallback) ? $fallback : '';
    }

    public static function prepare_subject(string $subject, ?string $environment = null): string
    {
        $subject = trim($subject);
        if ('production' !== self::environment($environment) && 0 !== strpos($subject, '[DEV] ')) {
            return '[DEV] ' . $subject;
        }
        return $subject;
    }

    public static function send_to_function(string $key, string $subject, string $message, $headers = array(), $attachments = array()): bool
    {
        $key = sanitize_key($key);
        $environment = self::environment();
        $recipient = self::get_recipient($key, $environment);
        if (! $recipient) {
            self::log_result(false, $key, $environment, 'missing');
            return false;
        }

        $sent = wp_mail($recipient, self::prepare_subject($subject, $environment), $message, $headers, $attachments);
        self::log_result((bool) $sent, $key, $environment, $recipient);
        return (bool) $sent;
    }

    public static function settings(): array
    {
        $saved = (array) get_option(self::OPTION, array());
        $settings = array();
        foreach (self::definitions() as $key => $definition) {
            $row = isset($saved[$key]) && is_array($saved[$key]) ? $saved[$key] : array();
            $settings[$key] = array();
            foreach (array('production', 'development') as $environment) {
                $settings[$key][$environment] = array_key_exists($environment, $row)
                    ? sanitize_email((string) $row[$environment])
                    : sanitize_email((string) ($definition[$environment] ?? ''));
            }
        }
        return $settings;
    }

    public static function render_admin_section(): void
    {
        $definitions = self::definitions();
        $settings = self::settings();
        $environment = self::environment();
        $can_manage = current_user_can('manage_options');
        ?>
        <div id="ssf-email-recipients" class="postbox" style="max-width:1180px;padding:20px">
            <h2><?php esc_html_e('E-postmottagare', 'ssf-email-router'); ?></h2>
            <p><?php esc_html_e('Styr mottagare för interna systemmeddelanden. Mejl till deltagare, sökande och inspektörer påverkas inte.', 'ssf-email-router'); ?></p>
            <p><strong><?php esc_html_e('Aktuell miljö:', 'ssf-email-router'); ?></strong> <?php echo esc_html(self::environment_label($environment)); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ssf_email_router_save">
                <?php wp_nonce_field('ssf_email_router_save'); ?>
                <div style="overflow-x:auto">
                    <table class="widefat striped">
                        <thead><tr>
                            <th scope="col"><?php esc_html_e('Funktion', 'ssf-email-router'); ?></th>
                            <th scope="col"><?php esc_html_e('Används för', 'ssf-email-router'); ?></th>
                            <th scope="col"><?php esc_html_e('Produktion', 'ssf-email-router'); ?></th>
                            <th scope="col"><?php esc_html_e('Development', 'ssf-email-router'); ?></th>
                            <th scope="col"><?php esc_html_e('Aktiv mottagare', 'ssf-email-router'); ?></th>
                            <th scope="col"><?php esc_html_e('Test', 'ssf-email-router'); ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($definitions as $key => $definition) : ?>
                            <tr>
                                <th scope="row"><strong><?php echo esc_html((string) $definition['label']); ?></strong><br><code><?php echo esc_html($key); ?></code></th>
                                <td><?php echo esc_html((string) $definition['description']); ?></td>
                                <?php foreach (array('production', 'development') as $column) : ?>
                                    <td><input class="regular-text" style="width:100%;min-width:210px" type="email" name="routing[<?php echo esc_attr($key); ?>][<?php echo esc_attr($column); ?>]" value="<?php echo esc_attr((string) $settings[$key][$column]); ?>" placeholder="<?php echo esc_attr((string) $definition[$column]); ?>" <?php disabled(! $can_manage); ?>></td>
                                <?php endforeach; ?>
                                <td><strong><?php echo esc_html(self::get_recipient($key, $environment)); ?></strong></td>
                                <td>
                                    <?php if ($can_manage) : ?>
                                        <button class="button" type="submit" form="ssf-email-test-<?php echo esc_attr($key); ?>"><?php esc_html_e('Testa e-post', 'ssf-email-router'); ?></button>
                                    <?php else : ?>
                                        <span aria-hidden="true">–</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($can_manage) { submit_button(__('Spara mottagare', 'ssf-email-router')); } ?>
            </form>
            <?php if ($can_manage) : ?>
                <?php foreach ($definitions as $key => $definition) : ?>
                    <form id="ssf-email-test-<?php echo esc_attr($key); ?>" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="ssf_email_router_test">
                        <input type="hidden" name="function_key" value="<?php echo esc_attr($key); ?>">
                        <?php wp_nonce_field('ssf_email_router_test_' . $key); ?>
                    </form>
                <?php endforeach; ?>
            <?php endif; ?>
            <p class="description"><?php esc_html_e('Tomma fält använder den visade SSF-standardadressen. I development läggs [DEV] automatiskt till i ämnesraden.', 'ssf-email-router'); ?></p>
        </div>
        <?php
    }

    public static function handle_save(): void
    {
        if (! current_user_can('manage_options') || ! check_admin_referer('ssf_email_router_save')) {
            wp_die(esc_html__('Du saknar behörighet.', 'ssf-email-router'));
        }

        $input = isset($_POST['routing']) && is_array($_POST['routing']) ? wp_unslash($_POST['routing']) : array();
        $next = array();
        $invalid = array();
        foreach (self::definitions() as $key => $definition) {
            $row = isset($input[$key]) && is_array($input[$key]) ? $input[$key] : array();
            foreach (array('production', 'development') as $environment) {
                $raw = isset($row[$environment]) && is_scalar($row[$environment]) ? trim((string) $row[$environment]) : '';
                $email = sanitize_email($raw);
                if ('' !== $raw && ! is_email($email)) {
                    $invalid[] = sprintf('%s (%s)', (string) $definition['label'], self::environment_label($environment));
                }
                $next[$key][$environment] = $email;
            }
        }

        if ($invalid) {
            self::set_notice('error', sprintf(__('Inställningarna sparades inte. Kontrollera e-postadressen för: %s.', 'ssf-email-router'), implode(', ', $invalid)));
            self::redirect_to_admin();
        }

        update_option(self::OPTION, $next, false);
        self::set_notice('success', __('E-postmottagarna har sparats.', 'ssf-email-router'));
        self::redirect_to_admin();
    }

    public static function handle_test(): void
    {
        $key = isset($_POST['function_key']) && is_scalar($_POST['function_key']) ? sanitize_key(wp_unslash($_POST['function_key'])) : '';
        if (! current_user_can('manage_options') || ! $key || ! check_admin_referer('ssf_email_router_test_' . $key)) {
            wp_die(esc_html__('Du saknar behörighet.', 'ssf-email-router'));
        }
        $definitions = self::definitions();
        if (! isset($definitions[$key])) {
            self::set_notice('error', __('Den valda e-postfunktionen finns inte.', 'ssf-email-router'));
            self::redirect_to_admin();
        }

        $environment = self::environment();
        $recipient = self::get_recipient($key, $environment);
        $message = implode("\n", array(
            'Detta är ett testmeddelande från SSF:s webbplats.',
            '',
            'Funktion: ' . $definitions[$key]['label'],
            'Miljö: ' . self::environment_label($environment),
            'Aktiv mottagare: ' . $recipient,
        ));
        $sent = self::send_to_function($key, 'SSF test – ' . $definitions[$key]['label'], $message, array('Content-Type: text/plain; charset=UTF-8'));
        self::set_notice(
            $sent ? 'success' : 'error',
            $sent
                ? sprintf(__('Testmeddelandet skickades till %s.', 'ssf-email-router'), $recipient)
                : sprintf(__('Testmeddelandet kunde inte skickas till %s. Kontrollera Microsoft 365-anslutningen.', 'ssf-email-router'), $recipient)
        );
        self::redirect_to_admin();
    }

    public static function render_admin_notice(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $notice = get_transient(self::NOTICE_PREFIX . get_current_user_id());
        if (! is_array($notice)) {
            return;
        }
        delete_transient(self::NOTICE_PREFIX . get_current_user_id());
        printf('<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr((string) $notice['type']), esc_html((string) $notice['message']));
    }

    private static function log_result(bool $sent, string $key, string $environment, string $recipient): void
    {
        $context = array(
            'function' => $key,
            'environment' => $environment,
            'recipient' => $recipient,
        );
        if (class_exists('SSF\\MemberPortal\\Core\\Logger')) {
            \SSF\MemberPortal\Core\Logger::add($sent ? 'email_router_sent' : 'email_router_failed', $context);
        }
        do_action('ssf_email_router_result', $sent, $context);
    }

    private static function set_notice(string $type, string $message): void
    {
        set_transient(self::NOTICE_PREFIX . get_current_user_id(), array('type' => $type, 'message' => $message), MINUTE_IN_SECONDS);
    }

    private static function redirect_to_admin(): void
    {
        wp_safe_redirect(admin_url('admin.php?page=' . self::ADMIN_PAGE . '#ssf-email-recipients'));
        exit;
    }
}

SSF_Email_Router::boot();
