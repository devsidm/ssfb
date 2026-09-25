<?php
/** Lightweight registry/permission regression tests without a WordPress database. */
declare(strict_types=1);

define('ABSPATH', __DIR__);
$hooks = array();
$active = true;
$caps = array('view_a' => true, 'view_b' => false);

function add_action(string $name, callable $callback, int $priority = 10): void { global $hooks; $hooks[$name][] = $callback; }
function add_filter(string $name, callable $callback): void { add_action($name, $callback); }
function do_action(string $name): void { global $hooks; foreach ($hooks[$name] ?? array() as $callback) { $callback(); } }
function sanitize_key(string $key): string { return preg_replace('/[^a-z0-9_-]/', '', strtolower($key)); }
function is_user_logged_in(): bool { return true; }
function get_current_user_id(): int { return 10; }
function current_user_can(string $capability): bool { global $caps; return ! empty($caps[$capability]); }
function home_url(string $path): string { return 'https://example.test/dev' . $path; }
function wp_validate_redirect(string $url, string $fallback): string { return str_starts_with($url, 'https://example.test/dev/') ? $url : $fallback; }

final class SSF_Access_Control
{
    public static function is_active(int $user_id): bool { global $active; return $active; }
}

require __DIR__ . '/../../wp-content/plugins/ssf-arbetsyta/includes/class-ssf-workspace.php';

function check(bool $condition, string $description): void
{
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$description}\n");
        exit(1);
    }
}

$render = static fn(string $path): string => $path;
check(SSF_Workspace::register_service(array('id' => 'a', 'label' => 'A', 'route' => 'a', 'capability' => 'view_a', 'render' => $render, 'order' => 20,
    'tasks' => static fn(): array => array(array('title' => 'Ärende 5', 'url' => SSF_Workspace::url('a/5'), 'updated_at' => '2026-09-25')))), 'valid service registers');
check(SSF_Workspace::register_service(array('id' => 'b', 'label' => 'B', 'route' => 'b', 'capability' => 'view_b', 'render' => $render)), 'hidden service registers');
check(! SSF_Workspace::register_service(array('id' => 'a', 'label' => 'Duplicate', 'route' => 'other', 'capability' => 'view_a', 'render' => $render)), 'duplicate id rejected');
check(! SSF_Workspace::register_service(array('id' => 'bad', 'label' => 'Bad', 'route' => '../bad', 'capability' => 'view_a', 'render' => $render)), 'invalid route rejected');
check(! SSF_Workspace::register_service(array('id' => 'reserved', 'label' => 'Reserved', 'route' => 'konto', 'capability' => 'view_a', 'render' => $render)), 'core route reserved');
check(! SSF_Workspace::register_service(array('id' => 'broken', 'label' => 'Broken', 'route' => 'broken', 'capability' => 'view_a')), 'missing renderer rejected');
check(array_keys(SSF_Workspace::services_for_user()) === array('a'), 'capability filters navigation');
check(SSF_Workspace::match_service('b/5') === null, 'direct route without capability denied');
check(SSF_Workspace::match_service('a/5')[1] === '5', 'authorized deep route resolves');
check(count(SSF_Workspace::tasks()) === 1 && SSF_Workspace::tasks()[0]['title'] === 'Ärende 5', 'task provider is called');
check(SSF_Workspace::tasks()[0]['url'] === SSF_Workspace::url('a/5'), 'deep link preserved');

$caps['view_b'] = true;
check(array_keys(SSF_Workspace::services_for_user()) === array('a', 'b'), 'new capability reveals service without core changes');
check(SSF_Workspace::register_service(array('id' => 'failure', 'label' => 'Failure', 'route' => 'failure', 'capability' => 'view_a', 'render' => $render,
    'tasks' => static function (): array { throw new RuntimeException('isolated'); })), 'failing provider registers');
check(count(SSF_Workspace::tasks()) === 1, 'provider failure isolated');

$active = false;
check(SSF_Workspace::services_for_user() === array(), 'inactive user has no services');
check(SSF_Workspace::tasks() === array(), 'inactive user has no tasks');
echo "PASS: Workspace registry, permissions, tasks and provider isolation.\n";
