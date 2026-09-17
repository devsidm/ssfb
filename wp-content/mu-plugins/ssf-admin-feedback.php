<?php
/**
 * Plugin Name: SSF Admin Feedback
 * Description: Shared return-to-section and flash feedback for SSF admin actions.
 */

if (! defined('ABSPATH')) {
    exit;
}

final class SSF_Admin_Feedback
{
    private const TRANSIENT_PREFIX = 'ssf_admin_feedback_';
    private const SECTIONS = array(
        'archive-source', 'archive-inventory', 'archive-target', 'archive-schema',
        'archive-write-test', 'archive-plan', 'archive-migrate', 'archive-batch',
        'archive-cutover', 'microsoft-directory', 'microsoft-login',
        'microsoft-users', 'microsoft-permissions', 'sharepoint', 'mailer',
    );

    private static $flash = null;

    public static function boot(): void
    {
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
        add_action('admin_notices', array(__CLASS__, 'render_toast'));
    }

    public static function safe_section(string $section): string
    {
        $section = sanitize_key($section);
        return in_array($section, self::SECTIONS, true) ? $section : '';
    }

    public static function requested_section(string $fallback = ''): string
    {
        $requested = isset($_REQUEST['_ssf_return_section']) && is_scalar($_REQUEST['_ssf_return_section'])
            ? (string) wp_unslash($_REQUEST['_ssf_return_section'])
            : '';
        return self::safe_section($requested) ?: self::safe_section($fallback);
    }

    public static function redirect_url(string $page, string $section = '', array $query = array()): string
    {
        $url = add_query_arg(array_merge(array('page' => sanitize_key($page)), self::safe_query($query)), admin_url('admin.php'));
        $section = self::safe_section($section);
        return $section ? $url . '#' . $section : $url;
    }

    public static function set_flash(string $page, string $section, string $type, string $message, array $details = array()): void
    {
        $type = in_array($type, array('success', 'info', 'warning', 'error'), true) ? $type : 'info';
        $safe_details = array();
        foreach (array('code', 'http_status', 'request_id', 'message') as $key) {
            if (isset($details[$key]) && is_scalar($details[$key])) {
                $safe_details[$key] = self::redact(sanitize_text_field((string) $details[$key]));
            }
        }
        set_transient(self::TRANSIENT_PREFIX . get_current_user_id(), array(
            'page' => sanitize_key($page),
            'section' => self::safe_section($section),
            'type' => $type,
            'message' => self::redact(sanitize_text_field($message)),
            'details' => $safe_details,
        ), MINUTE_IN_SECONDS);
    }

    public static function redirect(string $page, string $section, string $type, string $message, array $query = array(), array $details = array()): void
    {
        $section = self::requested_section($section);
        self::set_flash($page, $section, $type, $message, $details);
        wp_safe_redirect(self::redirect_url($page, $section, $query));
        exit;
    }

    public static function render_inline(string $section): void
    {
        $flash = self::flash();
        if (! $flash || self::safe_section($section) !== ($flash['section'] ?? '')) {
            return;
        }
        self::render_message($flash, 'ssf-admin-feedback ssf-admin-feedback--inline');
    }

    public static function render_toast(): void
    {
        $flash = self::flash();
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (! $flash || $page !== ($flash['page'] ?? '')) {
            return;
        }
        self::render_message($flash, 'ssf-admin-toast', true);
    }

    public static function enqueue_assets(): void
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (! $page || (0 !== strpos($page, 'ssf-') && 'microsoft-id-login' !== $page)) {
            return;
        }
        wp_enqueue_style('ssf-admin-feedback', content_url('mu-plugins/assets/ssf-admin-feedback.css'), array(), '1.0.0');
        wp_enqueue_script('ssf-admin-feedback', content_url('mu-plugins/assets/ssf-admin-feedback.js'), array(), '1.0.0', true);
    }

    private static function flash()
    {
        if (null !== self::$flash) {
            return self::$flash;
        }
        $key = self::TRANSIENT_PREFIX . get_current_user_id();
        $flash = get_transient($key);
        delete_transient($key);
        self::$flash = is_array($flash) ? $flash : false;
        return self::$flash;
    }

    private static function render_message(array $flash, string $class, bool $toast = false): void
    {
        $type = (string) ($flash['type'] ?? 'info');
        $role = in_array($type, array('error', 'warning'), true) ? 'alert' : 'status';
        $live = 'alert' === $role ? 'assertive' : 'polite';
        $icon = 'success' === $type ? '&#10003;' : (in_array($type, array('error', 'warning'), true) ? '&#10005;' : '&#8505;');
        echo '<div class="' . esc_attr($class . ' ssf-admin-feedback--' . $type) . '" role="' . esc_attr($role) . '" aria-live="' . esc_attr($live) . '"' . ($toast && 'success' === $type ? ' data-ssf-toast-autoclose="5000"' : '') . '>';
        echo '<span class="ssf-admin-feedback__icon" aria-hidden="true">' . $icon . '</span><div><p>' . esc_html((string) ($flash['message'] ?? '')) . '</p>';
        if (! empty($flash['details'])) {
            echo '<details><summary>Visa tekniska detaljer</summary><dl>';
            foreach ((array) $flash['details'] as $key => $value) {
                echo '<div><dt>' . esc_html(str_replace('_', ' ', ucfirst((string) $key))) . '</dt><dd><code>' . esc_html((string) $value) . '</code></dd></div>';
            }
            echo '</dl></details>';
        }
        echo '</div>';
        if ($toast) {
            echo '<button type="button" class="ssf-admin-toast__dismiss" data-ssf-toast-dismiss aria-label="Stäng meddelandet">&times;</button>';
        }
        echo '</div>';
    }

    private static function safe_query(array $query): array
    {
        $safe = array();
        foreach (array('m365_tab', 'destination', 'profile_environment') as $key) {
            if (isset($query[$key]) && is_scalar($query[$key])) {
                $safe[$key] = sanitize_key((string) $query[$key]);
            }
        }
        return $safe;
    }

    private static function redact(string $value): string
    {
        $value = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [REDACTED]', $value);
        return preg_replace('/(client_secret|access_token|password|authorization)\s*[:=]\s*\S+/i', '$1=[REDACTED]', (string) $value);
    }
}

SSF_Admin_Feedback::boot();
