<?php

namespace SSF\MemberPortal\Integrations\Microsoft365;

use SSF\MemberPortal\Core\Settings;

if (! defined('ABSPATH')) {
    exit;
}

final class SharePoint
{
    private GraphClient $graph;

    public function __construct(GraphClient $graph)
    {
        $this->graph = $graph;
    }

    public function enabled(): bool
    {
        $settings = Settings::all();
        return 'yes' === $settings['sharepoint_enabled'] && $settings['sharepoint_site_id'] && $settings['sharepoint_drive_id'];
    }

    public function upload(int $attachment_id, string $folder): bool
    {
        $settings = Settings::all();
        $file = get_attached_file($attachment_id);
        $folders = $this->ensure_folders($folder);
        if (! $file || ! is_readable($file) || is_wp_error($folders)) {
            return false;
        }
        $result = $this->upload_to_folder((string) $folders['id'], basename($file), file_get_contents($file));
        return ! is_wp_error($result);
    }

    public function test_connection(int $year)
    {
        if (! $this->enabled()) {
            return new \WP_Error('sharepoint_not_configured', __('SharePoint-synk är inte fullt konfigurerad eller aktiverad.', 'ssf-member-portal'));
        }

        $this->graph->clear_token();
        $folder = 'Årsmöten/' . max(2000, $year) . '/Motioner';
        $folders = $this->ensure_folders($folder);
        if (is_wp_error($folders)) {
            return $folders;
        }

        $filename = 'SSF-anslutningstest-' . wp_date('Ymd-His', null, wp_timezone()) . '.txt';
        $content = "SSF Medlemsportal\nSharePoint-anslutning verifierad " . wp_date('c', null, wp_timezone()) . "\n";
        $result = $this->upload_to_folder((string) $folders['id'], $filename, $content);
        if (is_wp_error($result)) {
            return $result;
        }

        return array('folder' => $folder . '/', 'filename' => $filename, 'web_url' => esc_url_raw((string) ($result['webUrl'] ?? '')));
    }

    private function ensure_folders(string $folder)
    {
        $parts = array_filter(explode('/', trim($folder, '/')));
        $parent_id = 'root';
        foreach ($parts as $part) {
            $children = $this->graph->request('GET', $this->children_path($parent_id));
            if (is_wp_error($children)) {
                return $children;
            }
            $existing_id = '';
            foreach ((array) ($children['value'] ?? array()) as $child) {
                if ($part === ($child['name'] ?? '') && isset($child['folder'])) {
                    $existing_id = (string) ($child['id'] ?? '');
                    break;
                }
            }
            if ($existing_id) {
                $parent_id = $existing_id;
                continue;
            }
            $created = $this->graph->request('POST', $this->children_path($parent_id), array('name' => $part, 'folder' => new \stdClass(), '@microsoft.graph.conflictBehavior' => 'fail'));
            if (is_wp_error($created)) {
                $data = (array) $created->get_error_data();
                if (409 !== (int) ($data['status'] ?? 0)) {
                    return $created;
                }
                return new \WP_Error('sharepoint_folder_conflict', __('En SharePoint-mapp kunde inte verifieras efter en samtidig ändring.', 'ssf-member-portal'));
            }
            $parent_id = (string) ($created['id'] ?? '');
            if (! $parent_id) {
                return new \WP_Error('sharepoint_folder_id', __('SharePoint returnerade ingen mappidentifierare.', 'ssf-member-portal'));
            }
        }
        return array('id' => $parent_id);
    }

    private function upload_to_folder(string $folder_id, string $filename, string $content)
    {
        return $this->graph->request('PUT', $this->drive_base() . '/items/' . rawurlencode($folder_id) . ':/' . rawurlencode($filename) . ':/content', $content);
    }

    private function children_path(string $parent_id): string
    {
        return 'root' === $parent_id ? $this->drive_base() . '/root/children' : $this->drive_base() . '/items/' . rawurlencode($parent_id) . '/children';
    }

    private function drive_base(): string
    {
        $settings = Settings::all();
        return 'sites/' . rawurlencode($settings['sharepoint_site_id']) . '/drives/' . rawurlencode($settings['sharepoint_drive_id']);
    }
}
