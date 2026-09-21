<?php

namespace {
    define('ABSPATH', __DIR__);

    class WP_Error
    {
        private string $message;
        public function __construct(string $code, string $message) { $this->message = $message; }
        public function get_error_message(): string { return $this->message; }
    }

    function is_wp_error($value): bool { return $value instanceof WP_Error; }
    function sanitize_text_field($value): string { return (string) $value; }
    function esc_url_raw($value): string { return (string) $value; }
    function wp_json_encode($value): string { return json_encode($value); }
    function wp_parse_url($value): array { return parse_url($value); }
    function get_option($key, $default = null) { return $GLOBALS['options'][$key] ?? $default; }
    function update_option($key, $value, $autoload = null): bool { $GLOBALS['options'][$key] = $value; return true; }
    function wp_remote_retrieve_response_code($response): int { return $response['code']; }
    function wp_remote_retrieve_body($response): string { return json_encode($response['body']); }
    function wp_remote_retrieve_headers($response): array { return $response['headers'] ?? array(); }

    function response(int $code, array $body, array $headers = array()): array
    {
        return array('code' => $code, 'body' => $body, 'headers' => $headers);
    }

    function wp_remote_get($url, $args): array
    {
        ++$GLOBALS['monitor_calls'];
        if (str_ends_with($url, '/monitor/expired')) return response(404, array());
        if ((! str_ends_with($url, '/monitor/copy-1') && ! str_contains($url, '/monitor(copy_1)?')) || isset($args['headers']['Authorization'])) {
            throw new \RuntimeException('Copy monitor URL or credentials are unsafe.');
        }
        return response(202, array('status' => 'completed', 'resourceId' => 'TARGET_FILE'));
    }

    function wp_remote_request($url, $args): array
    {
        $path = substr($url, strlen('https://graph.microsoft.com/v1.0/'));
        $method = $args['method'];
        $folder = array('id' => 'TARGET_2026', 'name' => '2026', 'size' => 0, 'folder' => array('childCount' => 0), 'listItem' => array('id' => '2', 'fields' => $GLOBALS['fields']['2']));
        $root = array('id' => 'TARGET_ROOT', 'name' => 'Medlemsansökningar', 'size' => 0, 'folder' => array('childCount' => 1), 'listItem' => array('id' => '1', 'fields' => array()));
        $file = array('id' => 'TARGET_FILE', 'name' => 'ansokan.pdf', 'size' => 5, 'file' => array('mimeType' => 'application/pdf'), 'listItem' => array('id' => '3', 'fields' => $GLOBALS['fields']['3']));

        if ($method === 'GET' && str_contains($path, '/columns?')) {
            return response(200, array('value' => array(array('name' => 'ApplicationNumber', 'text' => array()))));
        }
        if ($method === 'GET' && str_contains($path, '/items/TARGET_ROOT/children?')) return response(200, array('value' => array($folder)));
        if ($method === 'GET' && str_contains($path, '/items/TARGET_2026/children?')) {
            return response(200, array('value' => $GLOBALS['copied'] ? array($file) : array()));
        }
        if ($method === 'GET' && str_contains($path, '/items/TARGET_ROOT?')) return response(200, $root);
        if ($method === 'GET' && str_contains($path, '/items/TARGET_2026?')) return response(200, $folder);
        if ($method === 'GET' && str_contains($path, '/items/TARGET_FILE?')) return response(200, $file);
        if ($method === 'POST' && str_contains($path, '/items/SOURCE_FILE/copy')) {
            ++$GLOBALS['copy_calls'];
            $GLOBALS['copied'] = true;
            return response(202, array(), array('location' => 'https://tenant.sharepoint.com/_api/v2.0/monitor/copy-1'));
        }
        if ($method === 'PATCH' && preg_match('#/items/(2|3)/fields$#', $path, $matches)) {
            $GLOBALS['fields'][$matches[1]] = array_merge($GLOBALS['fields'][$matches[1]], json_decode($args['body'], true));
            return response(200, $GLOBALS['fields'][$matches[1]]);
        }
        throw new \RuntimeException('Unexpected Graph request: ' . $method . ' ' . $path);
    }

    function check(bool $condition, string $message): void
    {
        if (! $condition) throw new \RuntimeException($message);
    }
}

namespace SSF\MemberPortal\Integrations\Microsoft365 {
    class Authentication { public function token(): string { return 'test-only-token'; } }
    require_once __DIR__ . '/../../wp-content/plugins/ssf-member-portal/includes/Integrations/Microsoft365/GraphClient.php';
    require_once __DIR__ . '/../../wp-content/plugins/ssf-member-portal/includes/Integrations/Microsoft365/FolderMigrationCore.php';
}

namespace {
    use SSF\MemberPortal\Integrations\Microsoft365\Authentication;
    use SSF\MemberPortal\Integrations\Microsoft365\FolderMigrationCore;
    use SSF\MemberPortal\Integrations\Microsoft365\GraphClient;

    $GLOBALS['options'] = array();
    $GLOBALS['fields'] = array('2' => array(), '3' => array());
    $GLOBALS['copied'] = false;
    $GLOBALS['copy_calls'] = 0;
    $GLOBALS['monitor_calls'] = 0;

    $source = array('site_id' => 'SOURCE_SITE', 'drive_id' => 'SOURCE_DRIVE', 'list_id' => 'SOURCE_LIST', 'folder_id' => 'SOURCE_2026', 'folder_name' => '2026');
    $target = array('site_id' => 'TARGET_SITE', 'drive_id' => 'TARGET_DRIVE', 'list_id' => 'TARGET_LIST', 'folder_id' => 'TARGET_ROOT', 'drive_name' => 'Medlemsansökningar', 'destination_folder_name' => '2026');
    $folder = array('id' => 'SOURCE_2026', 'parent_id' => 'SOURCE_ROOT', 'name' => '2026', 'path' => '2026', 'depth' => 0, 'type' => 'folder', 'size' => 5, 'metadata' => array('ApplicationNumber' => 'SSF-2026'));
    $file = array('id' => 'SOURCE_FILE', 'parent_id' => 'SOURCE_2026', 'name' => 'ansokan.pdf', 'path' => '2026/ansokan.pdf', 'depth' => 1, 'type' => 'file', 'size' => 5, 'metadata' => array('ApplicationNumber' => 'SSF-2026'));
    $inventory = array('ok' => true, 'items' => array($folder, $file), 'columns' => array('value' => array(array('name' => 'ApplicationNumber', 'text' => array()))), 'populated_fields' => array('ApplicationNumber'), 'summary' => array('folders' => 1, 'files' => 1, 'bytes' => 5));
    $GLOBALS['options']['ssf_sharepoint_folder_migration_state'] = array(
        'context' => hash('sha256', 'SOURCE_DRIVE|SOURCE_2026|TARGET_DRIVE|TARGET_2026'),
        'items' => array('SOURCE_2026' => array('state' => 'ERROR', 'source_id' => 'SOURCE_2026')),
    );

    $graph = new GraphClient(new Authentication());
    $alternate_status = $graph->copy_status('https://tenant.sharepoint.com/sites/source/_api/v2.1/monitor(copy_1)?job=123');
    check(! is_wp_error($alternate_status) && $alternate_status['status'] === 'completed', 'Valid opaque Microsoft monitor path was rejected.');
    $monitor_calls = $GLOBALS['monitor_calls'];
    check(is_wp_error($graph->copy_status('http://tenant.sharepoint.com/monitor/copy-1')), 'Insecure monitor URL was accepted.');
    check(is_wp_error($graph->copy_status('https://example.com/monitor/copy-1')), 'Untrusted monitor host was accepted.');
    check(is_wp_error($graph->copy_status('https://user:password@tenant.sharepoint.com/monitor/copy-1')), 'Credential-bearing monitor URL was accepted.');
    check($GLOBALS['monitor_calls'] === $monitor_calls, 'Invalid monitor URL triggered an HTTP request.');
    $GLOBALS['monitor_calls'] = 0;

    $core = new FolderMigrationCore($graph);
    $plan = $core->dry_run($source, $target, $inventory);
    check(! is_wp_error($plan) && $plan['ok'] && $plan['resume_existing'], 'Existing 2026 test folder was not resumable.');
    $prepared = $core->prepare($target, $plan);
    check(! is_wp_error($prepared) && $prepared['target_folder_id'] === 'TARGET_2026', 'Existing 2026 folder was not prepared.');
    $result = $core->migrate($source, $target, $inventory, 'TARGET_2026');
    check(! is_wp_error($result) && $result['ok'], is_wp_error($result) ? $result->get_error_message() : 'Test migration failed.');
    check($GLOBALS['copy_calls'] === 1 && $GLOBALS['monitor_calls'] === 1, 'File was not copied and monitored exactly once.');
    check($GLOBALS['fields']['2']['ApplicationNumber'] === 'SSF-2026' && $GLOBALS['fields']['3']['ApplicationNumber'] === 'SSF-2026', 'Folder or file metadata was not written.');

    $state = $GLOBALS['options']['ssf_sharepoint_folder_migration_state'];
    $state['items']['SOURCE_FILE'] = array('state' => 'COPYING', 'source_id' => 'SOURCE_FILE', 'monitor_url' => 'https://tenant.sharepoint.com/monitor/expired');
    $GLOBALS['options']['ssf_sharepoint_folder_migration_state'] = $state;
    $resumed = $core->migrate($source, $target, $inventory, 'TARGET_2026');
    check(! is_wp_error($resumed) && $resumed['ok'] && $GLOBALS['copy_calls'] === 1, 'Expired monitor did not recover the completed file safely.');

    $full_source = $source;
    $full_source['folder_id'] = 'SOURCE_ROOT';
    $full_target = $target;
    $full_target['direct_to_root'] = '1';
    $full_inventory = $inventory;
    $root = $folder;
    $root['id'] = 'SOURCE_ROOT';
    $root['name'] = 'Medlemsansökningar';
    $root['size'] = 5;
    $root['metadata'] = array();
    $folder['depth'] = 1;
    $file['depth'] = 2;
    $full_inventory['items'] = array($root, $folder, $file);
    $full_inventory['summary']['folders'] = 2;
    $full_result = $core->migrate($full_source, $full_target, $full_inventory, 'TARGET_ROOT');
    check(! is_wp_error($full_result) && $full_result['ok'], is_wp_error($full_result) ? $full_result->get_error_message() : 'Full migration failed.');
    check($GLOBALS['copy_calls'] === 1, 'Full migration copied the verified test file twice.');
    $reconciliation = $core->reconcile($full_source, $full_target, $full_inventory, 'TARGET_ROOT');
    check($reconciliation['ok'] && $reconciliation['verified_items'] === 3, 'Not every folder and file was verified.');
    echo "PASS: 2026 folder, file copy, metadata and full-run reuse.\n";
}
