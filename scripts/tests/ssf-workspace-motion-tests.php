<?php
/** Read-only Graph and stale-cache contract for Workspace's motion view. */
declare(strict_types=1);

namespace SSF\MemberPortal\Integrations\Microsoft365 {
    final class Configuration
    {
        public static function complete(): bool { return true; }
        public static function value(string $key): string { return array('annual_meeting_folder_id' => 'root', 'drive_id' => 'drive')[$key] ?? ''; }
    }
    final class MotionSchema { public function __construct(GraphClient $client) {} }
    final class GraphClient
    {
        public array $methods = array();
        public bool $fail = false;
        public function request(string $method, string $path)
        {
            $this->methods[] = $method;
            if ($this->fail) { return new \WP_Error('offline', 'Offline'); }
            if (str_contains($path, '/items/root/children')) {
                return array('value' => array(array('id' => 'year', 'name' => '2026', 'folder' => array())));
            }
            if (str_contains($path, '/items/year/children')) {
                return array('value' => array(array('id' => 'motions', 'name' => 'Motioner', 'folder' => array())));
            }
            if (str_contains($path, '/items/motions/children')) {
                return array('value' => array(array('id' => 'document', 'name' => 'Motion.pdf', 'file' => array(), 'webUrl' => 'https://example.sharepoint.com/motion.pdf', 'lastModifiedDateTime' => '2026-09-25T12:00:00Z')));
            }
            return new \WP_Error('missing', 'Missing');
        }
    }
}

namespace {
    define('ABSPATH', __DIR__);
    define('MINUTE_IN_SECONDS', 60);
    define('HOUR_IN_SECONDS', 3600);
    $transients = array();
    final class WP_Error
    {
        public function __construct(public string $code, public string $message) {}
    }
    function is_wp_error($value): bool { return $value instanceof WP_Error; }
    function get_transient(string $key) { global $transients; return $transients[$key] ?? false; }
    function set_transient(string $key, $value, int $ttl): void { global $transients; $transients[$key] = $value; }
    function sanitize_text_field(string $value): string { return $value; }
    function esc_url_raw(string $value): string { return $value; }
    function __($text, $domain = ''): string { return $text; }
    function check(bool $condition, string $message): void
    {
        if (! $condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
    }

    require __DIR__ . '/../../wp-content/plugins/ssf-member-portal/includes/Integrations/Microsoft365/SharePoint.php';
    $graph = new \SSF\MemberPortal\Integrations\Microsoft365\GraphClient();
    $sharepoint = new \SSF\MemberPortal\Integrations\Microsoft365\SharePoint($graph);
    $result = $sharepoint->read_motion_folder(2026);
    check(! is_wp_error($result) && count($result['items']) === 1, 'existing motion is read');
    check($graph->methods === array('GET', 'GET', 'GET'), 'motion view only calls Graph GET');
    $graph->methods = array();
    $cached = $sharepoint->read_motion_folder(2026);
    check($cached['stale'] === false && $graph->methods === array(), 'fresh result is cached');
    $key = 'ssf_workspace_motions_2026';
    $transients[$key]['fetched_at'] -= 600;
    $graph->fail = true;
    $stale = $sharepoint->read_motion_folder(2026);
    check($stale['stale'] === true && count($stale['items']) === 1, 'Graph outage labels stale data');
    check($graph->methods === array('GET'), 'failed refresh performs no Graph write');
    echo "PASS: Workspace motions use read-only Graph and controlled stale data.\n";
}
