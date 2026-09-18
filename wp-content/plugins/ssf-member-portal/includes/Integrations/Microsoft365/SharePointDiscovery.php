<?php

namespace SSF\MemberPortal\Integrations\Microsoft365;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Configuration-time discovery and diagnostics using the existing Graph client.
 */
final class SharePointDiscovery
{
    private GraphClient $graph;

    public function __construct(GraphClient $graph)
    {
        $this->graph = $graph;
    }

    public function site(string $site_url, string $hostname = '', string $site_path = '', string $group_id = '')
    {
        if ($group_id) {
            $site = $this->graph->request('GET', 'groups/' . rawurlencode($group_id) . '/sites/root?$select=id,displayName,name,webUrl');
            if (is_wp_error($site)) {
                return new \WP_Error('sharepoint_group_discovery_unavailable', 'Group ID-metoden är inte tillgänglig med appens nuvarande behörigheter. Använd SharePoint Site URL; inga bredare Group-behörigheter behöver läggas till.', $site->get_error_data());
            }
            return $site;
        }
        if ($site_url) {
            $parts = wp_parse_url($site_url);
            $hostname = strtolower((string) ($parts['host'] ?? ''));
            $site_path = (string) ($parts['path'] ?? '');
        }
        if (! $hostname || ! $site_path || ! preg_match('/\.sharepoint\.com$/i', $hostname)) {
            return new \WP_Error('sharepoint_site_address_invalid', 'Ange en giltig SharePoint-adress eller hostname och site path.');
        }
        $path = 'sites/' . rawurlencode($hostname) . ':/' . self::encode_path($site_path) . '?$select=id,displayName,name,webUrl';
        return $this->graph->request('GET', $path);
    }

    public function drives(string $site_id)
    {
        if (! $site_id) {
            return new \WP_Error('sharepoint_site_id_missing', 'Hitta eller ange Site ID först.');
        }
        $result = $this->graph->request('GET', 'sites/' . rawurlencode($site_id) . '/drives?$select=id,name,driveType,webUrl');
        if (is_wp_error($result)) {
            return $result;
        }
        return array_values(array_filter((array) ($result['value'] ?? array()), static function ($drive) {
            return 'documentLibrary' === ($drive['driveType'] ?? '');
        }));
    }

    public function drive(string $drive_id, string $site_id = '')
    {
        if (! $drive_id) {
            return new \WP_Error('sharepoint_drive_id_missing', 'Välj ett dokumentbibliotek först.');
        }
        $drive = $this->graph->request('GET', 'drives/' . rawurlencode($drive_id) . '?$select=id,name,driveType,webUrl');
        if (is_wp_error($drive)) {
            return $drive;
        }
        $root = $this->graph->request('GET', 'drives/' . rawurlencode($drive_id) . '/root?$select=id,name,webUrl,sharepointIds');
        if (is_wp_error($root)) {
            return $root;
        }
        $drive['list_id'] = sanitize_text_field((string) ($root['sharepointIds']['listId'] ?? ''));
        $drive['root_id'] = sanitize_text_field((string) ($root['id'] ?? ''));
        $drive['root_name'] = sanitize_text_field((string) ($root['name'] ?? $drive['name'] ?? ''));
        $drive['root_web_url'] = esc_url_raw((string) ($root['webUrl'] ?? $drive['webUrl'] ?? ''));
        if (! $drive['list_id'] && $site_id) {
            $drive['list_id'] = $this->find_list_id($site_id, $drive_id);
        }
        return $drive;
    }

    public function folders(string $drive_id, string $parent_id = '', string $parent_path = '')
    {
        if (! $drive_id) {
            return new \WP_Error('sharepoint_drive_id_missing', 'Välj ett dokumentbibliotek först.');
        }
        $path = $parent_id
            ? 'drives/' . rawurlencode($drive_id) . '/items/' . rawurlencode($parent_id) . '/children'
            : 'drives/' . rawurlencode($drive_id) . '/root/children';
        $folders = array();
        $next = $path . '?$select=id,name,folder,webUrl,parentReference';
        $seen = array();
        while ($next) {
            if (isset($seen[$next])) {
                return new \WP_Error('sharepoint_pagination_loop', 'Microsoft Graph returnerade en sidloop vid mappbläddring.');
            }
            $seen[$next] = true;
            $result = $this->graph->request('GET', $next);
            if (is_wp_error($result)) {
                return $result;
            }
            foreach ((array) ($result['value'] ?? array()) as $item) {
                if (empty($item['folder'])) {
                    continue;
                }
                $name = sanitize_text_field((string) ($item['name'] ?? ''));
                $folders[] = array(
                    'id' => sanitize_text_field((string) ($item['id'] ?? '')),
                    'name' => $name,
                    'path' => trim($parent_path . '/' . $name, '/'),
                    'web_url' => esc_url_raw((string) ($item['webUrl'] ?? '')),
                );
            }
            $next = esc_url_raw((string) ($result['@odata.nextLink'] ?? ''));
        }
        return $folders;
    }

    public function folder_by_path(string $drive_id, string $folder_path)
    {
        $folder_path = trim($folder_path, '/');
        if (! $drive_id || ! $folder_path) {
            return new \WP_Error('sharepoint_folder_path_missing', 'Ange Drive ID och mappväg.');
        }
        $item = $this->graph->request(
            'GET',
            'drives/' . rawurlencode($drive_id) . '/root:/' . self::encode_path($folder_path) . '?$select=id,name,folder,webUrl,parentReference'
        );
        if (is_wp_error($item)) {
            return $item;
        }
        if (empty($item['folder'])) {
            return new \WP_Error('sharepoint_folder_not_folder', 'Den angivna sökvägen pekar inte på en mapp.');
        }
        return array(
            'id' => sanitize_text_field((string) ($item['id'] ?? '')),
            'name' => sanitize_text_field((string) ($item['name'] ?? '')),
            'path' => $folder_path,
            'parent_path' => sanitize_text_field((string) ($item['parentReference']['path'] ?? '')),
            'web_url' => esc_url_raw((string) ($item['webUrl'] ?? '')),
        );
    }

    public function columns(string $site_id, string $list_id)
    {
        if (! $site_id || ! $list_id) {
            return new \WP_Error('sharepoint_list_id_missing', 'Site ID och List ID krävs för att läsa kolumner.');
        }
        $result = $this->graph->request('GET', 'sites/' . rawurlencode($site_id) . '/lists/' . rawurlencode($list_id) . '/columns?$select=id,name,displayName,choice,text,dateTime,number,boolean,hidden,readOnly');
        if (is_wp_error($result)) {
            return $result;
        }
        $columns = array();
        foreach ((array) ($result['value'] ?? array()) as $column) {
            if (! empty($column['hidden'])) {
                continue;
            }
            $type = 'unknown';
            foreach (array('choice', 'text', 'dateTime', 'number', 'boolean') as $candidate) {
                if (array_key_exists($candidate, $column)) {
                    $type = $candidate;
                    break;
                }
            }
            $columns[] = array(
                'id' => sanitize_text_field((string) ($column['id'] ?? '')),
                'name' => sanitize_text_field((string) ($column['name'] ?? '')),
                'display_name' => sanitize_text_field((string) ($column['displayName'] ?? '')),
                'type' => $type,
                'date_time_format' => sanitize_text_field((string) ($column['dateTime']['format'] ?? '')),
                'choices' => array_map('sanitize_text_field', (array) ($column['choice']['choices'] ?? array())),
                'allow_text_entry' => ! empty($column['choice']['allowTextEntry']),
                'read_only' => ! empty($column['readOnly']),
            );
        }
        return $columns;
    }

    public function diagnostics(array $profile): array
    {
        $steps = array();
        $auth = $this->graph->authentication()->test();
        if (is_wp_error($auth)) {
            return $this->diagnostic_failure('authentication', $auth, $steps, $profile);
        }
        $steps['authentication'] = array('ok' => true, 'label' => 'Microsoft Graph-token');

        foreach (array('site_id' => 'SharePoint-site', 'drive_id' => 'Dokumentbibliotek', 'folder_id' => 'Mapp') as $field => $label) {
            if (empty($profile[$field])) {
                return $this->diagnostic_failure($field, new \WP_Error('sharepoint_configuration_missing', $label . ' är inte konfigurerad.'), $steps, $profile);
            }
        }

        $site = $this->graph->request('GET', 'sites/' . rawurlencode((string) $profile['site_id']) . '?$select=id,displayName,name,webUrl');
        if (is_wp_error($site)) {
            return $this->diagnostic_failure('site', $site, $steps, $profile);
        }
        $steps['site'] = array('ok' => true, 'label' => 'SharePoint-site', 'name' => sanitize_text_field((string) ($site['displayName'] ?? '')));

        $drive = $this->drive((string) $profile['drive_id'], (string) $profile['site_id']);
        if (is_wp_error($drive)) {
            return $this->diagnostic_failure('drive', $drive, $steps, $profile);
        }
        $steps['drive'] = array('ok' => true, 'label' => 'Dokumentbibliotek', 'name' => sanitize_text_field((string) ($drive['name'] ?? '')));
        $list_id = (string) ($profile['list_id'] ?: ($drive['list_id'] ?? ''));
        if ($list_id) {
            $columns = $this->columns((string) $profile['site_id'], $list_id);
            $steps['list'] = is_wp_error($columns)
                ? array('ok' => false, 'label' => 'Listmetadata', 'message' => $this->friendly_error($columns)['message'])
                : array('ok' => true, 'label' => 'Listmetadata', 'columns' => count($columns));
        } else {
            $steps['list'] = array('ok' => false, 'label' => 'Listmetadata', 'message' => 'List ID kunde inte identifieras.');
        }

        $folder = $this->graph->request('GET', 'drives/' . rawurlencode((string) $profile['drive_id']) . '/items/' . rawurlencode((string) $profile['folder_id']) . '?$select=id,name,folder,webUrl,parentReference');
        if (is_wp_error($folder)) {
            return $this->diagnostic_failure('folder', $folder, $steps, $profile);
        }
        if (empty($folder['folder'])) {
            return $this->diagnostic_failure('folder', new \WP_Error('sharepoint_folder_not_folder', 'Den valda posten är inte en mapp.'), $steps, $profile);
        }
        $steps['folder'] = array(
            'ok' => true,
            'label' => 'Mapp',
            'id' => sanitize_text_field((string) ($folder['id'] ?? '')),
            'name' => sanitize_text_field((string) ($folder['name'] ?? '')),
            'parent_path' => sanitize_text_field((string) ($folder['parentReference']['path'] ?? '')),
        );
        $steps['read'] = array('ok' => true, 'label' => 'Läsåtkomst');
        return array('ok' => ! empty($steps['list']['ok']), 'timestamp' => gmdate('c'), 'steps' => $steps, 'list_id' => $list_id);
    }

    public function write_test(array $profile): array
    {
        if (empty($profile['drive_id']) || empty($profile['folder_id'])) {
            return array('ok' => false, 'message' => 'Drive ID och Folder ID krävs för skrivtestet.');
        }
        $filename = 'ssf-wordpress-write-test-' . gmdate('Ymd-His') . '-' . wp_generate_password(5, false, false) . '.txt';
        $path = 'drives/' . rawurlencode((string) $profile['drive_id']) . '/items/' . rawurlencode((string) $profile['folder_id']) . ':/' . rawurlencode($filename) . ':/content';
        $created = $this->graph->request('PUT', $path, "SSF WordPress SharePoint connection test\n", array('Content-Type' => 'text/plain; charset=utf-8'));
        if (is_wp_error($created)) {
            $error = $this->friendly_error($created, $profile);
            return array('ok' => false, 'write' => false, 'cleanup' => false, 'message' => $error['message'], 'error' => $error);
        }
        $item_id = sanitize_text_field((string) ($created['id'] ?? ''));
        $web_url = esc_url_raw((string) ($created['webUrl'] ?? ''));
        if (! $item_id) {
            return array('ok' => false, 'write' => true, 'cleanup' => false, 'filename' => $filename, 'web_url' => $web_url, 'message' => 'Testfilen skapades men Graph returnerade inget item-ID.');
        }
        $deleted = $this->graph->request('DELETE', 'drives/' . rawurlencode((string) $profile['drive_id']) . '/items/' . rawurlencode($item_id));
        if (is_wp_error($deleted)) {
            return array('ok' => false, 'write' => true, 'cleanup' => false, 'filename' => $filename, 'item_id' => $item_id, 'web_url' => $web_url, 'message' => 'Skrivning fungerade men testfilen kunde inte tas bort.');
        }
        return array('ok' => true, 'write' => true, 'cleanup' => true, 'filename' => $filename, 'timestamp' => gmdate('c'));
    }

    public function permission_instructions(array $profile): array
    {
        $site_id = sanitize_text_field((string) ($profile['site_id'] ?? ''));
        $client_id = Configuration::value('client_id');
        $site_reference = $site_id ? rawurlencode($site_id) : '{SITE_ID}';
        $hostname = sanitize_text_field((string) ($profile['hostname'] ?? ''));
        $site_path = sanitize_text_field((string) ($profile['site_path'] ?? ''));
        return array(
            'message' => 'WordPress-appen verkar sakna Sites.Selected-behörighet för den här siten.',
            'client_id' => $client_id,
            'app_name' => 'SSF WordPress',
            'site_id' => $site_id,
            'site_name' => sanitize_text_field((string) ($profile['site_name'] ?? '')),
            'role' => 'write',
            'site_lookup_endpoint' => $hostname && $site_path ? 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($hostname) . ':/' . self::encode_path($site_path) . '?$select=id,displayName,webUrl' : '',
            'get_endpoint' => 'https://graph.microsoft.com/v1.0/sites/' . $site_reference . '/permissions',
            'post_endpoint' => 'https://graph.microsoft.com/v1.0/sites/' . $site_reference . '/permissions',
            'request_body' => wp_json_encode(array(
                'roles' => array('write'),
                'grantedToIdentities' => array(array('application' => array('id' => $client_id, 'displayName' => 'SSF WordPress'))),
            ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    public function friendly_error(\WP_Error $error, array $profile = array()): array
    {
        $data = (array) $error->get_error_data();
        $status = (int) ($data['http_status'] ?? $data['status'] ?? 0);
        $messages = array(
            401 => 'Autentisering misslyckades. Kontrollera Tenant ID, Client ID och Client Secret.',
            403 => 'Appen är autentiserad men saknar behörighet till denna SharePoint-site.',
            404 => 'Den angivna siten, dokumentbiblioteket eller mappen kunde inte hittas.',
            429 => 'Microsoft Graph begränsar tillfälligt antalet anrop. Försök igen senare.',
        );
        $result = array(
            'message' => 'sharepoint_group_discovery_unavailable' === $error->get_error_code() ? $error->get_error_message() : ($messages[$status] ?? $error->get_error_message()),
            'http_status' => $status,
            'graph_code' => sanitize_text_field((string) ($data['graph_code'] ?? $data['microsoft_code'] ?? '')),
            'technical_message' => $error->get_error_message(),
        );
        if (403 === $status && 'sharepoint_group_discovery_unavailable' !== $error->get_error_code()) {
            $result['sites_selected'] = $this->permission_instructions($profile);
        }
        return $result;
    }

    private function diagnostic_failure(string $step, \WP_Error $error, array $steps, array $profile): array
    {
        $friendly = $this->friendly_error($error, $profile);
        $steps[$step] = array('ok' => false, 'label' => $step, 'message' => $friendly['message']);
        return array('ok' => false, 'timestamp' => gmdate('c'), 'steps' => $steps, 'error' => $friendly);
    }

    private function find_list_id(string $site_id, string $drive_id): string
    {
        $lists = $this->graph->request('GET', 'sites/' . rawurlencode($site_id) . '/lists?$select=id,name,displayName,list');
        if (is_wp_error($lists)) {
            return '';
        }
        foreach ((array) ($lists['value'] ?? array()) as $list) {
            if ('documentLibrary' !== ($list['list']['template'] ?? '') || empty($list['id'])) {
                continue;
            }
            $drive = $this->graph->request('GET', 'sites/' . rawurlencode($site_id) . '/lists/' . rawurlencode((string) $list['id']) . '/drive?$select=id');
            if (! is_wp_error($drive) && $drive_id === (string) ($drive['id'] ?? '')) {
                return sanitize_text_field((string) $list['id']);
            }
        }
        return '';
    }

    private static function encode_path(string $path): string
    {
        return implode('/', array_map('rawurlencode', array_filter(explode('/', trim(rawurldecode($path), '/')), 'strlen')));
    }
}
