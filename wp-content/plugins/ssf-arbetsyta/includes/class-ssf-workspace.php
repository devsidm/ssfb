<?php
/** Frontend shell, routing and capability-aware service registry. */
if (! defined('ABSPATH')) {
    exit;
}

final class SSF_Workspace
{
    /** @var array<string,array<string,mixed>> */
    private static array $services = array();
    private static bool $registered = false;

    public static function boot(): void
    {
        add_action('init', array(__CLASS__, 'rewrite'), 5);
        add_action('init', array(__CLASS__, 'collect_services'), 30);
        add_filter('query_vars', static function (array $vars): array {
            $vars[] = 'ssf_workspace_path';
            return $vars;
        });
        add_action('template_redirect', array(__CLASS__, 'dispatch'), 0);
        add_filter('ssf_microsoft_login_default_redirect', static function (string $fallback): string {
            return self::url();
        });
    }

    public static function activate(): void
    {
        self::rewrite();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    public static function rewrite(): void
    {
        add_rewrite_rule('^arbetsyta/?$', 'index.php?ssf_workspace_path=home', 'top');
        add_rewrite_rule('^arbetsyta/(.+?)/?$', 'index.php?ssf_workspace_path=$matches[1]', 'top');
    }

    public static function url(string $path = ''): string
    {
        return home_url('/arbetsyta/' . ($path ? trim($path, '/') . '/' : ''));
    }

    /**
     * Register a service. Routes are relative to /arbetsyta/; callbacks receive the remaining path.
     * Returns false for invalid or duplicate definitions, leaving the existing entry untouched.
     */
    public static function register_service(array $service): bool
    {
        if (! is_string($service['id'] ?? null) || ! is_string($service['route'] ?? null)
            || ! is_string($service['capability'] ?? null) || ! is_string($service['label'] ?? null)) {
            return false;
        }
        $id = sanitize_key($service['id']);
        $route = trim($service['route'], '/');
        $capability = $service['capability'];
        if (! $id || $id !== $service['id'] || in_array($route, array('home', 'uppgifter', 'konto'), true)
            || ! preg_match('#^[a-z0-9][a-z0-9-]*(?:/[a-z0-9][a-z0-9-]*)*$#', $route)
            || ! preg_match('/^[a-z_][a-z0-9_]*$/', $capability)
            || '' === trim($service['label']) || ! is_callable($service['render'] ?? null)
            || isset(self::$services[$id])) {
            return false;
        }
        foreach (self::$services as $existing) {
            if ($existing['route'] === $route) {
                return false;
            }
        }
        $service['id'] = $id;
        $service['route'] = $route;
        $service['capability'] = $capability;
        $service['group'] = in_array(($service['group'] ?? ''), array('arbete', 'administration'), true) ? $service['group'] : 'arbete';
        $service['order'] = (int) ($service['order'] ?? 100);
        self::$services[$id] = $service;
        return true;
    }

    public static function collect_services(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        do_action('ssf_workspace_register_services');
    }

    public static function services_for_user(): array
    {
        self::collect_services();
        if (! self::active_user()) {
            return array();
        }
        $services = array_filter(self::$services, static function (array $service): bool {
            return current_user_can($service['capability']) || current_user_can('manage_options');
        });
        uasort($services, static function (array $a, array $b): int {
            return ($a['order'] <=> $b['order']) ?: strcmp($a['label'], $b['label']);
        });
        return $services;
    }

    public static function active_user(): bool
    {
        return is_user_logged_in() && class_exists('SSF_Access_Control') && SSF_Access_Control::is_active(get_current_user_id());
    }

    /** A hidden service can never be resolved by typing its URL directly. */
    public static function match_service(string $path): ?array
    {
        $services = array_values(self::services_for_user());
        usort($services, static function (array $a, array $b): int {
            return strlen($b['route']) <=> strlen($a['route']);
        });
        foreach ($services as $service) {
            if ($path === $service['route'] || 0 === strpos($path, $service['route'] . '/')) {
                return array($service, ltrim(substr($path, strlen($service['route'])), '/'));
            }
        }
        return null;
    }

    /** Providers only run after the corresponding capability has been checked. */
    public static function tasks(): array
    {
        $tasks = array();
        foreach (self::services_for_user() as $service) {
            if (! is_callable($service['tasks'] ?? null)) {
                continue;
            }
            try {
                $provided = call_user_func($service['tasks'], get_current_user_id());
                if (! is_array($provided)) {
                    continue;
                }
                foreach (array_slice($provided, 0, 30) as $item) {
                    if (! is_array($item) || ! is_string($item['title'] ?? null) || '' === trim($item['title'])
                        || ! is_string($item['url'] ?? null) || '' === $item['url']) {
                        continue;
                    }
                    $url = wp_validate_redirect((string) $item['url'], '');
                    if (! $url || 0 !== strpos($url, home_url('/'))) {
                        continue;
                    }
                    $item['service_id'] = $service['id'];
                    $item['service_label'] = $service['label'];
                    $item['priority'] = in_array(($item['priority'] ?? ''), array('urgent', 'high', 'normal', 'low'), true) ? $item['priority'] : 'normal';
                    $tasks[] = $item;
                }
            } catch (Throwable $error) {
                error_log('SSF Workspace task provider failed: ' . $service['id']);
            }
        }
        $rank = array('urgent' => 0, 'high' => 1, 'normal' => 2, 'low' => 3);
        usort($tasks, static function (array $a, array $b) use ($rank): int {
            return ($rank[$a['priority']] <=> $rank[$b['priority']]) ?: strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''));
        });
        return $tasks;
    }

    public static function dispatch(): void
    {
        $path = trim((string) get_query_var('ssf_workspace_path'), '/');
        if ('' === $path) {
            return;
        }
        nocache_headers();
        show_admin_bar(false);
        if (! is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(self::current_url()));
            exit;
        }
        if (! self::active_user()) {
            status_header(403);
            self::render_page('Åtkomst saknas', '<p>Din SSF-åtkomst är inte aktiv. Kontakta SSF:s administratör.</p>', array());
            exit;
        }

        $services = self::services_for_user();
        if ('home' === $path) {
            status_header(200);
            self::render_page('Översikt', self::dashboard($services), $services);
            exit;
        }
        if ('uppgifter' === $path) {
            status_header(200);
            self::render_page('Mina uppgifter', self::task_list(self::tasks()), $services);
            exit;
        }
        if ('konto' === $path) {
            status_header(200);
            self::render_page('Mitt konto', self::account(), $services);
            exit;
        }
        $match = self::match_service($path);
        if ($match) {
            [$service, $tail] = $match;
            try {
                $content = call_user_func($service['render'], $tail);
                $is_error = is_wp_error($content);
                if (is_wp_error($content)) {
                    status_header((int) ($content->get_error_data()['status'] ?? 404));
                    $content = '<p>' . esc_html($content->get_error_message()) . '</p>';
                } else {
                    status_header(200);
                }
                self::render_page((string) $service['label'], (string) $content, $services, $is_error || empty($service['owns_heading']));
            } catch (Throwable $error) {
                error_log('SSF Workspace service failed: ' . $service['id']);
                status_header(503);
                self::render_page((string) $service['label'], '<p>Tjänsten kunde inte visas just nu. Försök igen senare.</p>', $services);
            }
            exit;
        }
        status_header(403);
        self::render_page('Åtkomst saknas', '<p>Sidan finns inte eller så saknar du behörighet.</p>', $services);
        exit;
    }

    private static function current_url(): string
    {
        $path = trim((string) get_query_var('ssf_workspace_path'), '/');
        return self::url('home' === $path ? '' : $path);
    }

    private static function dashboard(array $services): string
    {
        ob_start();
        $tasks = self::tasks();
        $user = wp_get_current_user();
        echo '<p class="ssf-workspace-lead">Välkommen, ' . esc_html($user->first_name ?: $user->display_name) . '.</p>';
        if (! $services) {
            echo '<section class="ssf-workspace-panel"><h2>Ditt SSF-konto är aktivt</h2><p>Du har ännu inte fått åtkomst till någon arbetsfunktion. Kontakta SSF:s administratör om du behöver åtkomst.</p><p><a href="' . esc_url(self::url('konto')) . '">Mitt konto</a> · <a href="' . esc_url(wp_logout_url(home_url('/'))) . '">Logga ut</a></p></section>';
        } else {
            echo '<section aria-labelledby="ssf-workspace-attention"><div class="ssf-workspace-section-heading"><h2 id="ssf-workspace-attention">Det här behöver din uppmärksamhet</h2><a href="' . esc_url(self::url('uppgifter')) . '">Alla uppgifter</a></div>';
            echo self::task_list(array_slice($tasks, 0, 5));
            echo '</section><section aria-labelledby="ssf-workspace-services"><h2 id="ssf-workspace-services">Mina tjänster</h2><div class="ssf-workspace-cards">';
            foreach ($services as $service) {
                echo '<a class="ssf-workspace-card" href="' . esc_url(self::url($service['route'])) . '"><strong>' . esc_html($service['label']) . '</strong><span>' . esc_html((string) ($service['description'] ?? 'Öppna tjänsten')) . '</span></a>';
            }
            echo '</div></section>';
        }
        return (string) ob_get_clean();
    }

    private static function task_list(array $tasks): string
    {
        if (! $tasks) {
            return '<p class="ssf-workspace-empty">Inga aktuella uppgifter just nu.</p>';
        }
        $html = '<ul class="ssf-workspace-tasks">';
        foreach ($tasks as $task) {
            $html .= '<li><div><small>' . esc_html((string) ($task['service_label'] ?? '')) . '</small><strong>' . esc_html((string) $task['title']) . '</strong>';
            if (! empty($task['description'])) {
                $html .= '<span>' . esc_html((string) $task['description']) . '</span>';
            }
            $html .= '</div><a href="' . esc_url((string) $task['url']) . '">Öppna</a></li>';
        }
        return $html . '</ul>';
    }

    private static function account(): string
    {
        $user = wp_get_current_user();
        $groups = class_exists('SSF_Access_Control') ? SSF_Access_Control::user_groups((int) $user->ID) : array();
        $labels = class_exists('SSF_Access_Control') ? SSF_Access_Control::groups() : array();
        $names = array_map(static function (string $group) use ($labels): string { return (string) ($labels[$group]['label'] ?? $group); }, $groups);
        $linked = (string) get_user_meta($user->ID, '_ssf_m365_tid', true) !== ''
            && (string) get_user_meta($user->ID, '_ssf_m365_oid', true) !== '';
        return '<dl class="ssf-workspace-account"><dt>Namn</dt><dd>' . esc_html($user->display_name) . '</dd><dt>SSF-e-post</dt><dd>' . esc_html($user->user_email) . '</dd><dt>Microsoft-konto</dt><dd>' . ($linked ? 'Kopplat' : 'Inte kopplat') . '</dd><dt>Mina behörigheter</dt><dd>' . esc_html($names ? implode(', ', $names) : 'Inga verksamhetsbehörigheter') . '</dd></dl><p><a class="ssf-workspace-button" href="' . esc_url(wp_logout_url(home_url('/'))) . '">Logga ut</a></p>';
    }

    private static function render_page(string $title, string $content, array $services, bool $show_title = true): void
    {
        wp_enqueue_style('ssf-workspace-theme', get_stylesheet_uri(), array(), wp_get_theme()->get('Version'));
        wp_enqueue_style('ssf-workspace', SSF_WORKSPACE_URL . 'assets/workspace.css', array('ssf-workspace-theme'), SSF_WORKSPACE_VERSION);
        wp_enqueue_script('ssf-workspace', SSF_WORKSPACE_URL . 'assets/workspace.js', array(), SSF_WORKSPACE_VERSION, true);
        echo '<!doctype html><html ';
        language_attributes();
        echo '><head><meta charset="';
        bloginfo('charset');
        echo '"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html($title) . ' – SSF Arbetsyta</title>';
        wp_head();
        echo '</head><body class="ssf-workspace-body"><a class="ssf-workspace-skip" href="#ssf-workspace-main">Hoppa till innehåll</a><header class="ssf-workspace-header"><a class="ssf-workspace-brand" href="' . esc_url(self::url()) . '">SSF <span>Arbetsyta</span></a><button class="ssf-workspace-menu-toggle" type="button" aria-expanded="false" aria-controls="ssf-workspace-nav">Meny</button><a class="ssf-workspace-account-link" href="' . esc_url(self::url('konto')) . '">' . esc_html(wp_get_current_user()->display_name) . '</a></header>';
        echo '<div class="ssf-workspace-layout"><nav id="ssf-workspace-nav" class="ssf-workspace-nav" aria-label="Arbetsytans navigation"><a href="' . esc_url(self::url()) . '">Översikt</a><a href="' . esc_url(self::url('uppgifter')) . '">Mina uppgifter</a>';
        foreach (array('arbete' => 'Arbete', 'administration' => 'Administration') as $group => $label) {
            $group_services = array_filter($services, static function (array $service) use ($group): bool { return $service['group'] === $group; });
            if ($group_services) {
                echo '<div class="ssf-workspace-nav-group"><span>' . esc_html($label) . '</span>';
                foreach ($group_services as $service) {
                    echo '<a href="' . esc_url(self::url($service['route'])) . '">' . esc_html($service['label']) . '</a>';
                }
                echo '</div>';
            }
        }
        echo '<div class="ssf-workspace-nav-group"><span>Personligt</span><a href="' . esc_url(self::url('konto')) . '">Mitt konto</a></div></nav><main id="ssf-workspace-main" class="ssf-workspace-main" tabindex="-1"><div class="ssf-workspace-breadcrumb"><a href="' . esc_url(self::url()) . '">Arbetsyta</a><span aria-hidden="true">›</span><span>' . esc_html($title) . '</span></div>' . ($show_title ? '<h1>' . esc_html($title) . '</h1>' : '') . $content . '</main></div>';
        wp_footer();
        echo '</body></html>';
    }
}
