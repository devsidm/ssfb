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
        if (! $file || ! is_readable($file) || ! $this->ensure_folders($folder)) {
            return false;
        }
        $path = $this->drive_path($folder . '/' . basename($file));
        $result = $this->graph->request('PUT', $path . ':/content', file_get_contents($file));
        return ! is_wp_error($result);
    }

    private function ensure_folders(string $folder): bool
    {
        $parts = array_filter(explode('/', trim($folder, '/')));
        $current = '';
        foreach ($parts as $part) {
            $parent = $current;
            $current .= ($current ? '/' : '') . $part;
            $existing = $this->graph->request('GET', $this->drive_path($current));
            if (! is_wp_error($existing)) {
                continue;
            }
            $parent_path = $parent ? $this->drive_path($parent) . ':/children' : $this->drive_path('root/children', false);
            $created = $this->graph->request('POST', $parent_path, array('name' => $part, 'folder' => new \stdClass(), '@microsoft.graph.conflictBehavior' => 'fail'));
            if (is_wp_error($created) && 409 !== (int) $created->get_error_data('status')) {
                return false;
            }
        }
        return true;
    }

    private function drive_path(string $path, bool $colon_path = true): string
    {
        $settings = Settings::all();
        $base = 'sites/' . rawurlencode($settings['sharepoint_site_id']) . '/drives/' . rawurlencode($settings['sharepoint_drive_id']) . '/';
        if (! $colon_path) {
            return $base . $path;
        }
        $segments = array_map('rawurlencode', array_filter(explode('/', trim($path, '/'))));
        return $base . 'root:/' . implode('/', $segments);
    }
}
