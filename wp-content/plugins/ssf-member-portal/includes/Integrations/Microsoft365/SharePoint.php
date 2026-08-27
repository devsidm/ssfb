<?php

namespace SSF\MemberPortal\Integrations\Microsoft365;

use SSF\MemberPortal\Modules\Motions\MotionStatus;

if (! defined('ABSPATH')) {
    exit;
}

final class SharePoint
{
    private const TEST_FILE_OPTION = 'ssf_member_portal_graph_test_file';

    private GraphClient $graph;

    public function __construct(GraphClient $graph)
    {
        $this->graph = $graph;
    }

    public function enabled(): bool
    {
        return Configuration::complete();
    }

    public function test_authentication()
    {
        return $this->graph->authentication()->test();
    }

    /**
     * Runs read-only checks. Folder creation and file upload have separate actions.
     */
    public function diagnostics()
    {
        if (! $this->enabled()) {
            return $this->not_configured_error();
        }

        $auth = $this->graph->authentication()->test();
        if (is_wp_error($auth)) {
            return $auth;
        }

        $site = $this->test_site();
        if (is_wp_error($site)) {
            return $site;
        }
        $drive = $this->test_drive();
        if (is_wp_error($drive)) {
            return $drive;
        }
        if (Configuration::value('drive_id') !== (string) ($drive['id'] ?? '')) {
            return new \WP_Error('sharepoint_drive_mismatch', __('Dokumentbiblioteket stämmer inte med det konfigurerade Drive ID:t.', 'ssf-member-portal'));
        }

        $root = $this->test_root_folder();
        if (is_wp_error($root)) {
            return $root;
        }

        $children = $this->list_root_children();
        if (is_wp_error($children)) {
            return $children;
        }

        return array(
            'ok' => true,
            'timestamp' => gmdate('c'),
            'authentication' => $auth,
            'site' => $site,
            'drive' => $drive,
            'root_folder' => $root,
            'root_children' => $children,
        );
    }

    public function test_write_access(int $year)
    {
        if (! $this->enabled()) {
            return $this->not_configured_error();
        }

        $folders = $this->ensure_motion_folder($year);
        if (is_wp_error($folders)) {
            return $folders;
        }

        return array(
            'ok' => true,
            'timestamp' => gmdate('c'),
            'folder' => $this->folder_path($year),
            'year_folder_id' => $folders['year_folder_id'],
            'motion_folder_id' => $folders['motion_folder_id'],
        );
    }

    /**
     * Creates and removes one uniquely named folder below Årsmöten.
     */
    public function test_temporary_write()
    {
        if (! $this->enabled()) {
            return $this->not_configured_error();
        }

        $name = 'ssf-graph-test-' . gmdate('Ymd-His') . '-' . wp_generate_password(6, false, false);
        $created = $this->graph->request(
            'POST',
            $this->children_path(Configuration::value('annual_meeting_folder_id')),
            array('name' => $name, 'folder' => new \stdClass(), '@microsoft.graph.conflictBehavior' => 'fail')
        );
        if (is_wp_error($created)) {
            return $created;
        }

        $id = (string) ($created['id'] ?? '');
        if (! $id) {
            return new \WP_Error('sharepoint_test_folder_id', __('Microsoft Graph returnerade inget ID för testmappen.', 'ssf-member-portal'));
        }

        $deleted = $this->graph->request('DELETE', $this->item_path($id));
        if (is_wp_error($deleted)) {
            return $deleted;
        }

        return array(
            'ok' => true,
            'folder_name' => $name,
            'folder_id' => $id,
            'created' => true,
            'deleted' => true,
            'timestamp' => gmdate('c'),
        );
    }

    public function upload_test_file(int $year)
    {
        $folders = $this->test_write_access($year);
        if (is_wp_error($folders)) {
            return $folders;
        }

        $filename = 'ssf-graph-test-' . gmdate('Ymd-His') . '.txt';
        $content = "SSF Medlemsportal Graph-test\n" . gmdate('c') . "\n";
        $item = $this->upload_to_folder((string) $folders['motion_folder_id'], $filename, $content, 'text/plain; charset=utf-8');
        if (is_wp_error($item)) {
            return $item;
        }

        $test_file = array(
            'id' => (string) ($item['id'] ?? ''),
            'filename' => $filename,
            'drive_id' => Configuration::value('drive_id'),
            'parent_folder_id' => (string) $folders['motion_folder_id'],
            'web_url' => esc_url_raw((string) ($item['webUrl'] ?? '')),
            'uploaded_at' => gmdate('c'),
        );
        update_option(self::TEST_FILE_OPTION, $test_file, false);

        return array_merge(array('ok' => true, 'folder' => $folders['folder']), $test_file);
    }

    public function delete_test_file()
    {
        $test_file = (array) get_option(self::TEST_FILE_OPTION, array());
        $id = (string) ($test_file['id'] ?? '');
        $filename = (string) ($test_file['filename'] ?? '');
        if (! $id || 0 !== strpos($filename, 'ssf-graph-test-')) {
            return new \WP_Error('sharepoint_test_file_missing', __('Det finns ingen testfil som kan tas bort.', 'ssf-member-portal'));
        }

        $result = $this->graph->request('DELETE', $this->drive_base() . '/items/' . rawurlencode($id));
        if (is_wp_error($result)) {
            return $result;
        }

        delete_option(self::TEST_FILE_OPTION);
        return array('ok' => true, 'filename' => $filename, 'timestamp' => gmdate('c'));
    }

    public function upload_motion_attachment(int $attachment_id, int $motion_id, int $year, string $motion_number, string $motion_title)
    {
        if (! $this->enabled()) {
            return $this->not_configured_error();
        }

        $file = get_attached_file($attachment_id);
        if (! $file || ! is_readable($file)) {
            return new \WP_Error('sharepoint_attachment_missing', __('Motionens bilaga kunde inte läsas från WordPress.', 'ssf-member-portal'));
        }

        $content = file_get_contents($file);
        if (false === $content) {
            return new \WP_Error('sharepoint_attachment_read', __('Motionens bilaga kunde inte läsas.', 'ssf-member-portal'));
        }

        $folders = $this->ensure_motion_folder($year);
        if (is_wp_error($folders)) {
            return $folders;
        }

        $extension = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
        $base = sanitize_file_name($motion_number . '-' . sanitize_title($motion_title));
        $filename = $base . ($extension ? '.' . $extension : '');
        $mime_type = (string) get_post_mime_type($attachment_id);
        $item = $this->upload_to_folder((string) $folders['motion_folder_id'], $filename, $content, $mime_type ?: 'application/octet-stream');
        if (is_wp_error($item)) {
            return $item;
        }

        $metadata = $this->update_motion_metadata((string) ($item['id'] ?? ''), $motion_id, $motion_number);
        if (is_wp_error($metadata)) {
            return $metadata;
        }

        return array(
            'drive_item_id' => (string) ($item['id'] ?? ''),
            'drive_id' => Configuration::value('drive_id'),
            'parent_folder_id' => (string) $folders['motion_folder_id'],
            'web_url' => esc_url_raw((string) ($item['webUrl'] ?? '')),
            'sharepoint_list_item_id' => sanitize_text_field((string) ($metadata['id'] ?? '')),
            'filename' => $filename,
            'uploaded_at' => gmdate('c'),
        );
    }

    /**
     * Updates only the Status column for a manual WordPress status change.
     */
    public function update_motion_status(string $drive_item_id, string $status)
    {
        if (! $this->enabled()) {
            return $this->not_configured_error();
        }

        $status = MotionStatus::canonical($status);
        $field = Configuration::value('metadata_status_field');
        if (! $status || ! $field) {
            return new \WP_Error('sharepoint_status_configuration', __('SharePoint-statusfältet är inte konfigurerat.', 'ssf-member-portal'));
        }

        return $this->update_list_item_fields($drive_item_id, array($field => MotionStatus::label($status)));
    }

    private function test_site()
    {
        $hostname = Configuration::value('site_hostname');
        $path = Configuration::value('site_path');
        $endpoint = $hostname && $path
            ? 'sites/' . rawurlencode($hostname) . ':/' . ltrim($path, '/')
            : 'sites/' . rawurlencode(Configuration::value('site_id'));
        $site = $this->graph->request('GET', $endpoint);
        if (is_wp_error($site)) {
            return $site;
        }

        return array(
            'id' => (string) ($site['id'] ?? ''),
            'configured_id_matches' => 0 === strcasecmp(Configuration::value('site_id'), (string) ($site['id'] ?? '')),
            'name' => sanitize_text_field((string) ($site['displayName'] ?? '')),
            'expected_name' => 'styrelsen',
            'name_matches_expected' => 0 === strcasecmp('styrelsen', (string) ($site['displayName'] ?? '')),
            'web_url' => esc_url_raw((string) ($site['webUrl'] ?? '')),
        );
    }

    private function test_drive()
    {
        $drive = $this->graph->request('GET', $this->drive_base());
        if (is_wp_error($drive)) {
            return $drive;
        }

        return array(
            'id' => (string) ($drive['id'] ?? ''),
            'name' => sanitize_text_field((string) ($drive['name'] ?? '')),
            'drive_type' => sanitize_text_field((string) ($drive['driveType'] ?? '')),
            'expected_name' => 'Dokument',
            'name_matches_expected' => 0 === strcasecmp('Dokument', (string) ($drive['name'] ?? '')),
            'web_url' => esc_url_raw((string) ($drive['webUrl'] ?? '')),
        );
    }

    private function test_root_folder()
    {
        $root = $this->graph->request('GET', $this->item_path(Configuration::value('annual_meeting_folder_id')));
        if (is_wp_error($root)) {
            return $root;
        }
        if (empty($root['folder'])) {
            return new \WP_Error('sharepoint_root_not_folder', __('Den konfigurerade Årsmöten-posten är inte en mapp.', 'ssf-member-portal'));
        }
        $expected_name = Configuration::value('annual_meeting_folder_name') ?: 'Årsmöten';
        return array(
            'id' => (string) ($root['id'] ?? ''),
            'name' => sanitize_text_field((string) ($root['name'] ?? '')),
            'expected_name' => $expected_name,
            'name_matches_expected' => 0 === strcasecmp($expected_name, (string) ($root['name'] ?? '')),
            'web_url' => esc_url_raw((string) ($root['webUrl'] ?? '')),
        );
    }

    private function list_root_children()
    {
        $children = $this->children(Configuration::value('annual_meeting_folder_id'));
        if (is_wp_error($children)) {
            return $children;
        }

        $items = array();
        foreach ((array) ($children['value'] ?? array()) as $child) {
            $items[] = array(
                'id' => (string) ($child['id'] ?? ''),
                'name' => sanitize_text_field((string) ($child['name'] ?? '')),
                'kind' => isset($child['folder']) ? 'folder' : 'file',
            );
        }

        return $items;
    }

    private function ensure_motion_folder(int $year)
    {
        $year = max(2000, $year);
        $year_folder = $this->find_or_create_folder(Configuration::value('annual_meeting_folder_id'), (string) $year);
        if (is_wp_error($year_folder)) {
            return $year_folder;
        }
        $motion_folder = $this->find_or_create_folder((string) $year_folder['id'], 'Motioner');
        if (is_wp_error($motion_folder)) {
            return $motion_folder;
        }

        return array(
            'folder' => $this->folder_path($year),
            'year_folder_id' => (string) $year_folder['id'],
            'motion_folder_id' => (string) $motion_folder['id'],
        );
    }

    private function find_or_create_folder(string $parent_id, string $name)
    {
        $existing = $this->find_child_folder($parent_id, $name);
        if (is_wp_error($existing) || $existing) {
            return $existing;
        }

        $created = $this->graph->request(
            'POST',
            $this->children_path($parent_id),
            array('name' => $name, 'folder' => new \stdClass(), '@microsoft.graph.conflictBehavior' => 'fail')
        );
        if (! is_wp_error($created)) {
            return $created;
        }

        $data = (array) $created->get_error_data();
        if (409 === (int) ($data['http_status'] ?? $data['status'] ?? 0)) {
            $existing = $this->find_child_folder($parent_id, $name);
            if (! is_wp_error($existing) && $existing) {
                return $existing;
            }
        }

        return $created;
    }

    private function find_child_folder(string $parent_id, string $name)
    {
        $children = $this->children($parent_id);
        if (is_wp_error($children)) {
            return $children;
        }

        foreach ((array) ($children['value'] ?? array()) as $child) {
            if (isset($child['folder']) && 0 === strcasecmp($name, (string) ($child['name'] ?? ''))) {
                return $child;
            }
        }

        return null;
    }

    private function upload_to_folder(string $folder_id, string $filename, string $content, string $mime_type)
    {
        return $this->graph->request(
            'PUT',
            $this->item_path($folder_id) . ':/' . rawurlencode($filename) . ':/content',
            $content,
            array('Content-Type' => sanitize_text_field($mime_type))
        );
    }

    private function update_motion_metadata(string $drive_item_id, int $motion_id, string $motion_number)
    {
        if (! $drive_item_id) {
            return new \WP_Error('sharepoint_drive_item_missing', __('Microsoft Graph returnerade inget dokument-ID.', 'ssf-member-portal'));
        }

        $status = MotionStatus::canonical((string) get_post_meta($motion_id, '_ssf_mp_status', true)) ?: MotionStatus::IN_SORTERAD;
        $submitted_at = (int) get_post_meta($motion_id, '_ssf_mp_submitted_at', true);
        $vessel = '';
        foreach (array('_ssf_mp_vessel', '_ssf_mp_ship', '_ssf_mp_fartyg') as $meta_key) {
            $vessel = sanitize_text_field((string) get_post_meta($motion_id, $meta_key, true));
            if ($vessel) {
                break;
            }
        }

        $fields = array(
            Configuration::value('metadata_wordpress_motion_id_field') => (string) $motion_id,
            Configuration::value('metadata_motion_number_field') => $motion_number,
            Configuration::value('metadata_status_field') => MotionStatus::label($status),
            Configuration::value('metadata_vessel_field') => $vessel,
            Configuration::value('metadata_received_date_field') => $submitted_at ? gmdate('Y-m-d', $submitted_at) : '',
        );

        return $this->update_list_item_fields($drive_item_id, $fields);
    }

    private function update_list_item_fields(string $drive_item_id, array $fields)
    {
        $fields = array_filter($fields, static function ($value, $key): bool {
            return is_string($key) && '' !== trim($key) && is_scalar($value);
        }, ARRAY_FILTER_USE_BOTH);
        if (! $fields) {
            return new \WP_Error('sharepoint_metadata_configuration', __('SharePoint-metadatafälten är inte konfigurerade.', 'ssf-member-portal'));
        }

        return $this->graph->request('PATCH', $this->item_path($drive_item_id) . '/listItem/fields', $fields);
    }

    private function children(string $folder_id)
    {
        return $this->graph->request('GET', $this->children_path($folder_id) . '?$select=id,name,folder,file,webUrl');
    }

    private function children_path(string $folder_id): string
    {
        return $this->item_path($folder_id) . '/children';
    }

    private function item_path(string $item_id): string
    {
        return $this->drive_base() . '/items/' . rawurlencode($item_id);
    }

    private function drive_base(): string
    {
        return 'drives/' . rawurlencode(Configuration::value('drive_id'));
    }

    private function folder_path(int $year): string
    {
        return (Configuration::value('annual_meeting_folder_name') ?: 'Årsmöten') . '/' . max(2000, $year) . '/Motioner/';
    }

    private function not_configured_error(): \WP_Error
    {
        return new \WP_Error(
            'sharepoint_not_configured',
            __('SharePoint är inte komplett konfigurerat på servern.', 'ssf-member-portal'),
            array('http_status' => 0, 'missing' => Configuration::missing())
        );
    }
}
