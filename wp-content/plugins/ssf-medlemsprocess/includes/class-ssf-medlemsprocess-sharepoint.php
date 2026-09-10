<?php
/**
 * SharePoint archive and status synchronization for membership applications.
 *
 * @package SSF_Medlemsprocess
 */

if (! defined('ABSPATH')) {
    exit;
}

class SSF_Medlemsprocess_SharePoint
{
    private const SYNC_HOOK = 'ssf_medlemsprocess_sync_application';
    private const POLL_HOOK = 'ssf_medlemsprocess_poll_application_statuses';
    private const POLL_LOCK = 'ssf_medlemsprocess_status_poll_lock';
    private const RETRIES = array(300, 1800, 7200);

    private $graph;

    public function __construct()
    {
        if (class_exists('SSF\MemberPortal\Integrations\Microsoft365\GraphClient') && class_exists('SSF\MemberPortal\Integrations\Microsoft365\Authentication')) {
            $this->graph = new \SSF\MemberPortal\Integrations\Microsoft365\GraphClient(new \SSF\MemberPortal\Integrations\Microsoft365\Authentication());
        }
        add_action(self::SYNC_HOOK, array($this, 'sync'));
        add_filter('cron_schedules', array($this, 'cron_schedules'));
        add_action(self::POLL_HOOK, array($this, 'poll_statuses'));
    }

    public function register(): void
    {
        if (! wp_next_scheduled(self::POLL_HOOK)) {
            wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'ssf_thirty_minutes', self::POLL_HOOK);
        }
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::POLL_HOOK);
    }

    public function cron_schedules(array $schedules): array
    {
        if (! isset($schedules['ssf_thirty_minutes'])) {
            $schedules['ssf_thirty_minutes'] = array('interval' => 30 * MINUTE_IN_SECONDS, 'display' => 'Var 30:e minut');
        }
        return $schedules;
    }

    public function enabled(): bool
    {
        $this->ensure_graph();
        $writes_allowed = ! class_exists('SSF\MemberPortal\Integrations\Microsoft365\SharePointDestinations')
            || \SSF\MemberPortal\Integrations\Microsoft365\SharePointDestinations::write_allowed('membership_applications');
        return $writes_allowed && $this->graph && $this->config('application_site_id') && $this->config('application_drive_id') && ($this->config('application_root_folder_id') || $this->config('application_root_folder_name'));
    }

    public function queue(int $application_id, int $delay = 15): void
    {
        if (! $this->enabled()) {
            update_post_meta($application_id, '_ssf_sp_sync_status', 'not_configured');
            update_post_meta($application_id, '_ssf_sp_last_error', 'Medlemsgruppens SharePoint-destination är inte konfigurerad.');
            return;
        }
        update_post_meta($application_id, '_ssf_sp_sync_status', 'pending');
        if (! wp_next_scheduled(self::SYNC_HOOK, array($application_id))) {
            wp_schedule_single_event(time() + max(1, $delay), self::SYNC_HOOK, array($application_id));
        }
    }

    public function retry(int $application_id): void
    {
        wp_clear_scheduled_hook(self::SYNC_HOOK, array($application_id));
        update_post_meta($application_id, '_ssf_sp_sync_attempts', 0);
        delete_post_meta($application_id, '_ssf_sp_last_error');
        $this->queue($application_id, 1);
    }

    public function sync(int $application_id): void
    {
        $application = get_post($application_id);
        if (! $application || SSF_Medlemsprocess_Application::POST_TYPE !== $application->post_type || ! $this->enabled()) {
            return;
        }
        $lock = 'ssf_medlemsprocess_sync_lock_' . $application_id;
        if (get_transient($lock)) {
            return;
        }
        set_transient($lock, 1, 10 * MINUTE_IN_SECONDS);
        update_post_meta($application_id, '_ssf_sp_sync_status', 'syncing');
        update_post_meta($application_id, '_ssf_sp_last_attempt_at', gmdate('c'));

        try {
            $folders = $this->ensure_folders($application_id);
            if (is_wp_error($folders)) {
                $this->fail($application_id, $folders);
                return;
            }
            $pdf_id = (int) get_post_meta($application_id, '_ssf_application_pdf_id', true);
            if (! $pdf_id) {
                $pdf_id = SSF_Medlemsprocess_Plugin::instance()->pdf->create_attachment($application_id);
                if ($pdf_id) { update_post_meta($application_id, '_ssf_application_pdf_id', $pdf_id); }
            }
            if (! $pdf_id) {
                $this->fail($application_id, new WP_Error('application_pdf_missing', 'Ansöknings-PDF kunde inte skapas för arkivering.'));
                return;
            }
            $items = (array) get_post_meta($application_id, '_ssf_sp_items', true);
            $groups = array(
                'pdf' => array($pdf_id),
                'images' => array_values(array_unique(array_filter(array_merge(
                    array((int) get_post_meta($application_id, '_ssf_application_main_image_id', true)),
                    array_map('absint', (array) get_post_meta($application_id, '_ssf_application_gallery_ids', true))
                )))),
                'documents' => array_values(array_unique(array_filter(array_merge(
                    array_map('absint', (array) get_post_meta($application_id, '_ssf_application_document_ids', true)),
                    array_map('absint', (array) get_post_meta($application_id, '_ssf_completion_files', true))
                )))),
            );
            foreach ($groups as $group => $attachment_ids) {
                $folder_id = 'pdf' === $group ? $folders['application_folder_id'] : ('images' === $group ? $folders['images_folder_id'] : $folders['documents_folder_id']);
                foreach ($attachment_ids as $attachment_id) {
                    if ('synced' === ($items[$attachment_id]['status'] ?? '')) {
                        continue;
                    }
                    $remote = $this->upload_attachment($attachment_id, $folder_id);
                    if (is_wp_error($remote)) {
                        $this->fail($application_id, $remote);
                        return;
                    }
                    $items[$attachment_id] = array(
                        'status' => 'synced',
                        'group' => $group,
                        'drive_item_id' => (string) ($remote['id'] ?? ''),
                        'web_url' => esc_url_raw((string) ($remote['webUrl'] ?? '')),
                        'filename' => sanitize_file_name((string) ($remote['name'] ?? '')),
                        'uploaded_at' => gmdate('c'),
                    );
                    if ('pdf' === $group) {
                        update_post_meta($application_id, '_ssf_sp_pdf_drive_item_id', (string) ($remote['id'] ?? ''));
                        update_post_meta($application_id, '_ssf_sp_pdf_web_url', esc_url_raw((string) ($remote['webUrl'] ?? '')));
                        $pdf_list_item = $this->list_item((string) ($remote['id'] ?? ''));
                        if (! is_wp_error($pdf_list_item)) {
                            update_post_meta($application_id, '_ssf_sp_pdf_list_item_id', sanitize_text_field((string) ($pdf_list_item['id'] ?? '')));
                        }
                    }
                    update_post_meta($application_id, '_ssf_sp_items', $items);
                }
            }

            $metadata = $this->set_folder_metadata($application_id, $folders['application_folder_id']);
            if (is_wp_error($metadata)) {
                update_post_meta($application_id, '_ssf_sp_schema_warning', $metadata->get_error_message());
            } else {
                delete_post_meta($application_id, '_ssf_sp_schema_warning');
            }
            update_post_meta($application_id, '_ssf_sp_sync_status', 'synced');
            update_post_meta($application_id, '_ssf_sp_synced_at', gmdate('c'));
            update_post_meta($application_id, '_ssf_sp_sync_attempts', 0);
            delete_post_meta($application_id, '_ssf_sp_last_error');
            SSF_Medlemsprocess_Application::add_history($application_id, 'sharepoint', 'Ansökningsarkivet synkroniserades till SharePoint.', false);
            $this->log('application_sharepoint_synced', array('application_id' => $application_id));
        } finally {
            delete_transient($lock);
        }
    }

    public function poll_statuses(): array
    {
        if (get_transient(self::POLL_LOCK)) {
            return array('ok' => true, 'skipped' => 'locked');
        }
        set_transient(self::POLL_LOCK, 1, 10 * MINUTE_IN_SECONDS);
        $summary = array('ok' => true, 'timestamp' => gmdate('c'), 'checked' => 0, 'changed' => 0, 'emails_sent' => 0, 'errors' => 0);
        try {
            if (! $this->enabled()) {
                $summary['ok'] = false;
                return $summary;
            }
            $ids = get_posts(array(
                'post_type' => SSF_Medlemsprocess_Application::POST_TYPE,
                'post_status' => 'private',
                'fields' => 'ids',
                'posts_per_page' => 200,
                'meta_query' => array(
                    array('key' => '_ssf_sp_sync_status', 'value' => 'synced'),
                    array('key' => '_ssf_sp_application_folder_id', 'compare' => 'EXISTS'),
                    array('key' => '_ssf_process_status', 'value' => array('approved', 'approved_aspirant', 'rejected', 'archived'), 'compare' => 'NOT IN'),
                ),
            ));
            foreach ($ids as $id) {
                ++$summary['checked'];
                $result = $this->poll_application((int) $id);
                if (is_wp_error($result)) {
                    ++$summary['errors'];
                } elseif (! empty($result['changed'])) {
                    ++$summary['changed'];
                    $summary['emails_sent'] += ! empty($result['email_sent']) ? 1 : 0;
                }
            }
            return $summary;
        } finally {
            update_option('ssf_medlemsprocess_status_poll_diagnostics', $summary, false);
            delete_transient(self::POLL_LOCK);
        }
    }

    public function poll_application(int $application_id)
    {
        $lock = 'ssf_medlemsprocess_status_lock_' . $application_id;
        if (get_transient($lock)) {
            return new WP_Error('application_status_locked', 'Ansökans status kontrolleras redan.');
        }
        set_transient($lock, 1, 5 * MINUTE_IN_SECONDS);
        try {
            return $this->poll_application_unlocked($application_id);
        } finally {
            delete_transient($lock);
        }
    }

    private function poll_application_unlocked(int $application_id)
    {
        $folder_id = (string) get_post_meta($application_id, '_ssf_sp_application_folder_id', true);
        $list_id = (string) (get_post_meta($application_id, '_ssf_sp_list_id', true) ?: $this->application_list_id());
        $list_item_id = (string) get_post_meta($application_id, '_ssf_sp_application_list_item_id', true);
        if (! $folder_id || (! $list_item_id && ! $folder_id)) {
            return new WP_Error('application_sharepoint_reference_missing', 'Ansökan saknar en stabil SharePoint-referens.');
        }
        $status_field = $this->config('metadata_application_status_field');
        $comment_field = $this->config('metadata_application_public_comment_field');
        if ($list_id && $list_item_id) {
            $remote = $this->request('GET', $this->list_base($list_id) . '/items/' . rawurlencode($list_item_id) . '?$expand=fields');
        } else {
            $remote = $this->request('GET', $this->item_path($folder_id) . '/listItem?$expand=fields');
        }
        update_post_meta($application_id, '_ssf_sp_last_checked_at', gmdate('c'));
        if (is_wp_error($remote)) {
            update_post_meta($application_id, '_ssf_sp_status_poll_error', $remote->get_error_message());
            return $remote;
        }
        $fields = (array) ($remote['fields'] ?? array());
        $remote_label = sanitize_text_field((string) ($fields[$status_field] ?? ''));
        $public_comment = sanitize_textarea_field((string) ($fields[$comment_field] ?? ''));
        update_post_meta($application_id, '_ssf_sp_last_status', $remote_label);
        update_post_meta($application_id, '_ssf_sp_last_checked_at', gmdate('c'));
        update_post_meta($application_id, '_ssf_sp_public_comment', $public_comment);
        delete_post_meta($application_id, '_ssf_sp_status_poll_error');
        $status = $this->wordpress_status($remote_label);
        if (! $status) {
            update_post_meta($application_id, '_ssf_sp_status_warning', 'Okänd SharePoint-status: ' . $remote_label);
            return array('changed' => false, 'unknown_status' => true);
        }
        delete_post_meta($application_id, '_ssf_sp_status_warning');
        $old = SSF_Medlemsprocess_Application::status($application_id);
        if ($old === $status) {
            return array('changed' => false, 'email_sent' => false);
        }
        $changed = SSF_Medlemsprocess_Application::transition($application_id, $status, $public_comment, true);
        update_post_meta($application_id, '_ssf_status_source', 'sharepoint');
        update_post_meta($application_id, '_ssf_sp_status_changed_at', sanitize_text_field((string) ($remote['lastModifiedDateTime'] ?? gmdate('c'))));
        $this->log('application_status_changed', array('application_id' => $application_id, 'old_status' => $old, 'new_status' => $status));
        return array('changed' => $changed, 'email_sent' => $changed);
    }

    public function push_status(int $application_id): void
    {
        $folder_id = (string) get_post_meta($application_id, '_ssf_sp_application_folder_id', true);
        if (! $folder_id) {
            return;
        }
        $field = $this->config('metadata_application_status_field');
        $result = $this->request('PATCH', $this->item_path($folder_id) . '/listItem/fields', array($field => $this->sharepoint_status(SSF_Medlemsprocess_Application::status($application_id))));
        if (is_wp_error($result)) {
            update_post_meta($application_id, '_ssf_sp_status_push_error', $result->get_error_message());
        } else {
            delete_post_meta($application_id, '_ssf_sp_status_push_error');
        }
    }

    private function ensure_folders(int $application_id)
    {
        $application_folder_id = (string) get_post_meta($application_id, '_ssf_sp_application_folder_id', true);
        if ($application_folder_id) {
            $application_folder = $this->request('GET', $this->item_path($application_folder_id));
            if (! is_wp_error($application_folder)) {
                $images = $this->find_or_create_folder($application_folder_id, 'Bilder');
                $documents = $this->find_or_create_folder($application_folder_id, 'Bilagor');
                if (! is_wp_error($images) && ! is_wp_error($documents)) {
                    return array('application_folder_id' => $application_folder_id, 'images_folder_id' => (string) $images['id'], 'documents_folder_id' => (string) $documents['id']);
                }
            }
        }
        $root_id = $this->config('application_root_folder_id');
        if (! $root_id) {
            $root = $this->find_or_create_folder('', $this->config('application_root_folder_name'));
            if (is_wp_error($root)) {
                return $root;
            }
            $root_id = (string) $root['id'];
        }
        $submitted = (string) get_post_meta($application_id, '_ssf_submitted_at', true);
        $year = $submitted ? (int) mysql2date('Y', $submitted) : (int) wp_date('Y');
        $year_folder = $this->find_or_create_folder($root_id, (string) $year);
        if (is_wp_error($year_folder)) {
            return $year_folder;
        }
        $data = SSF_Medlemsprocess_Application::data($application_id);
        $number = (string) get_post_meta($application_id, '_ssf_application_number', true);
        $folder_name = sanitize_file_name($number . ' - ' . ($data['ship_name'] ?? 'Fartyg'));
        $application_folder = $this->find_or_create_folder((string) $year_folder['id'], $folder_name);
        if (is_wp_error($application_folder)) {
            return $application_folder;
        }
        $images = $this->find_or_create_folder((string) $application_folder['id'], 'Bilder');
        $documents = $this->find_or_create_folder((string) $application_folder['id'], 'Bilagor');
        if (is_wp_error($images) || is_wp_error($documents)) {
            return is_wp_error($images) ? $images : $documents;
        }
        update_post_meta($application_id, '_ssf_sp_site_id', $this->config('application_site_id'));
        update_post_meta($application_id, '_ssf_sp_drive_id', $this->config('application_drive_id'));
        update_post_meta($application_id, '_ssf_sp_application_folder_id', (string) $application_folder['id']);
        update_post_meta($application_id, '_ssf_sp_application_web_url', esc_url_raw((string) ($application_folder['webUrl'] ?? '')));
        update_post_meta($application_id, '_ssf_sp_images_folder_id', (string) $images['id']);
        update_post_meta($application_id, '_ssf_sp_documents_folder_id', (string) $documents['id']);
        return array('application_folder_id' => (string) $application_folder['id'], 'images_folder_id' => (string) $images['id'], 'documents_folder_id' => (string) $documents['id']);
    }

    private function set_folder_metadata(int $application_id, string $folder_id)
    {
        $schema = $this->ensure_schema();
        $schema_error = is_wp_error($schema) ? $schema : null;
        $list_item = $this->list_item($folder_id);
        if (is_wp_error($list_item)) {
            return $list_item;
        }
        $list_id = $this->application_list_id();
        update_post_meta($application_id, '_ssf_sp_list_id', $list_id);
        update_post_meta($application_id, '_ssf_sp_application_list_item_id', sanitize_text_field((string) ($list_item['id'] ?? '')));
        $data = SSF_Medlemsprocess_Application::data($application_id);
        $submitted = (string) get_post_meta($application_id, '_ssf_submitted_at', true);
        $fields = array(
            $this->config('metadata_application_wp_id_field') => (string) $application_id,
            $this->config('metadata_application_number_field') => (string) get_post_meta($application_id, '_ssf_application_number', true),
            $this->config('metadata_application_vessel_field') => (string) ($data['ship_name'] ?? ''),
            $this->config('metadata_application_representative_field') => (string) ($data['applicant_name'] ?? ''),
            $this->config('metadata_application_received_field') => $submitted ? gmdate('Y-m-d', strtotime($submitted)) : gmdate('Y-m-d'),
            $this->config('metadata_application_route_field') => (string) ($data['application_path'] ?? ''),
        );
        $is_initial = ! get_post_meta($application_id, '_ssf_sp_application_list_item_id', true);
        if ($is_initial) {
            $fields[$this->config('metadata_application_status_field')] = 'Inkommen';
        }
        $result = $this->request('PATCH', $this->item_path($folder_id) . '/listItem/fields', array_filter($fields, static function ($value, $key) { return '' !== (string) $key && '' !== (string) $value; }, ARRAY_FILTER_USE_BOTH));
        if (! is_wp_error($result)) {
            if ($is_initial) { update_post_meta($application_id, '_ssf_sp_last_status', 'Inkommen'); }
        }
        return ! is_wp_error($result) && $schema_error ? $schema_error : $result;
    }

    private function ensure_schema()
    {
        $list_id = $this->application_list_id();
        if (! $list_id) {
            return new WP_Error('application_sharepoint_list_missing', 'Dokumentbibliotekets List ID kunde inte identifieras.');
        }
        $columns = $this->request('GET', $this->list_base($list_id) . '/columns?$select=id,name,displayName,choice,text,dateTime');
        if (is_wp_error($columns)) {
            return $columns;
        }
        $existing = array();
        foreach ((array) ($columns['value'] ?? array()) as $column) {
            $existing[strtolower((string) ($column['name'] ?? ''))] = $column;
        }
        $definitions = array(
            $this->config('metadata_application_wp_id_field') => array('displayName' => 'WordPress Application ID', 'text' => new stdClass()),
            $this->config('metadata_application_number_field') => array('displayName' => 'Ansökningsnummer', 'text' => new stdClass()),
            $this->config('metadata_application_status_field') => array('displayName' => 'Status', 'choice' => array('allowTextEntry' => false, 'displayAs' => 'dropDownMenu', 'choices' => array_values($this->status_labels()))),
            $this->config('metadata_application_vessel_field') => array('displayName' => 'Fartyg', 'text' => new stdClass()),
            $this->config('metadata_application_representative_field') => array('displayName' => 'Fartygsombud', 'text' => new stdClass()),
            $this->config('metadata_application_received_field') => array('displayName' => 'Inkommen datum', 'dateTime' => array('displayAs' => 'default', 'format' => 'dateOnly')),
            $this->config('metadata_application_route_field') => array('displayName' => 'Ansökningsväg', 'text' => new stdClass()),
            $this->config('metadata_application_public_comment_field') => array('displayName' => 'Extern statuskommentar', 'text' => array('allowMultipleLines' => true, 'appendChangesToExistingText' => false, 'linesForEditing' => 6, 'maxLength' => 4000)),
        );
        foreach ($definitions as $name => $definition) {
            if (! $name) {
                continue;
            }
            $existing_column = $existing[strtolower($name)] ?? null;
            if ($existing_column) {
                if ($name === $this->config('metadata_application_status_field')) {
                    $current_choices = (array) ($existing_column['choice']['choices'] ?? array());
                    $required_choices = array_values($this->status_labels());
                    if (array_diff($required_choices, $current_choices) && ! empty($existing_column['id'])) {
                        $updated = $this->request('PATCH', $this->list_base($list_id) . '/columns/' . rawurlencode((string) $existing_column['id']), array('choice' => array('allowTextEntry' => false, 'displayAs' => 'dropDownMenu', 'choices' => array_values(array_unique(array_merge($current_choices, $required_choices))))));
                        if (is_wp_error($updated)) { return $updated; }
                    }
                }
                continue;
            }
            $result = $this->request('POST', $this->list_base($list_id) . '/columns', array_merge(array('name' => $name), $definition));
            if (is_wp_error($result)) {
                return $result;
            }
        }
        update_option('ssf_medlemsprocess_graph_schema', array('verified_at' => gmdate('c'), 'list_id' => $list_id), false);
        return array('list_id' => $list_id);
    }

    private function application_list_id(): string
    {
        $list_id = $this->config('application_list_id');
        if ($list_id) {
            return $list_id;
        }
        $list = $this->request('GET', $this->drive_base() . '/list?$select=id');
        if (! is_wp_error($list) && ! empty($list['id'])) {
            $list_id = sanitize_text_field((string) $list['id']);
            \SSF\MemberPortal\Integrations\Microsoft365\Configuration::save_discovered_application_list_id($list_id);
        }
        return $list_id;
    }

    private function find_or_create_folder(string $parent_id, string $name)
    {
        $children_path = $parent_id ? $this->item_path($parent_id) . '/children' : $this->drive_base() . '/root/children';
        $children = $this->request('GET', $children_path . '?$select=id,name,folder,webUrl');
        if (is_wp_error($children)) {
            return $children;
        }
        foreach ((array) ($children['value'] ?? array()) as $child) {
            if (isset($child['folder']) && 0 === strcasecmp($name, (string) ($child['name'] ?? ''))) {
                return $child;
            }
        }
        return $this->request('POST', $children_path, array('name' => $name, 'folder' => new stdClass(), '@microsoft.graph.conflictBehavior' => 'fail'));
    }

    private function upload_attachment(int $attachment_id, string $folder_id)
    {
        $file = get_attached_file($attachment_id);
        if (! $file || ! is_readable($file)) {
            return new WP_Error('application_attachment_missing', 'En ansökningsfil kunde inte läsas från WordPress.');
        }
        $content = file_get_contents($file);
        if (false === $content) {
            return new WP_Error('application_attachment_read', 'En ansökningsfil kunde inte läsas.');
        }
        return $this->request('PUT', $this->item_path($folder_id) . ':/' . rawurlencode(sanitize_file_name(basename($file))) . ':/content', $content, array('Content-Type' => get_post_mime_type($attachment_id) ?: 'application/octet-stream'));
    }

    private function list_item(string $drive_item_id)
    {
        $result = null;
        for ($attempt = 0; $attempt < 4; ++$attempt) {
            $result = $this->request('GET', $this->item_path($drive_item_id) . '/listItem?$expand=fields');
            if (! is_wp_error($result) && ! empty($result['id'])) {
                return $result;
            }
            if ($attempt < 3) {
                usleep((250 + ($attempt * 250)) * 1000);
            }
        }
        return is_wp_error($result) ? $result : new WP_Error('application_list_item_missing', 'SharePoint returnerade inget ListItem-ID.');
    }

    private function fail(int $application_id, WP_Error $error): void
    {
        $attempts = (int) get_post_meta($application_id, '_ssf_sp_sync_attempts', true) + 1;
        update_post_meta($application_id, '_ssf_sp_sync_attempts', $attempts);
        update_post_meta($application_id, '_ssf_sp_sync_status', 'error');
        update_post_meta($application_id, '_ssf_sp_last_error', $error->get_error_message());
        SSF_Medlemsprocess_Application::add_history($application_id, 'sharepoint_error', 'SharePoint-synkningen misslyckades och kommer att försöka igen.', false);
        $this->log('application_sharepoint_failed', array('application_id' => $application_id, 'attempt' => $attempts, 'error' => $error->get_error_code()));
        if ($attempts <= count(self::RETRIES)) {
            wp_schedule_single_event(time() + self::RETRIES[$attempts - 1], self::SYNC_HOOK, array($application_id));
        }
    }

    private function status_labels(): array
    {
        return array(
            'received' => 'Inkommen', 'under_review' => 'Under granskning', 'needs_completion' => 'Begär komplettering', 'completion_submitted' => 'Komplettering inkommen',
            'inspection_planned' => 'Inspektion ska bokas', 'inspection_booked' => 'Inspektion bokad',
            'inspection_completed' => 'Inspektion genomförd', 'awaiting_decision' => 'Under bedömning',
            'approved_aspirant' => 'Godkänd som aspirant', 'approved' => 'Godkänd', 'rejected' => 'Avslagen', 'paused' => 'Vilande', 'archived' => 'Avslutad',
        );
    }

    private function wordpress_status(string $label): string
    {
        $normalized = strtolower(trim($label));
        foreach ($this->status_labels() as $status => $status_label) {
            if ($normalized === strtolower($status_label)) {
                return $status;
            }
        }
        $legacy = array('mottagen' => 'received', 'inskickad' => 'received', 'komplettering krävs' => 'needs_completion', 'väntar på beslut' => 'awaiting_decision', 'arkiverad' => 'archived');
        return $legacy[$normalized] ?? '';
    }

    private function sharepoint_status(string $status): string
    {
        return $this->status_labels()[$status] ?? SSF_Medlemsprocess_Application::status_label($status);
    }

    private function config(string $key): string
    {
        return class_exists('SSF\MemberPortal\Integrations\Microsoft365\Configuration') ? \SSF\MemberPortal\Integrations\Microsoft365\Configuration::value($key) : '';
    }

    private function request(string $method, string $path, $body = null, array $headers = array())
    {
        $this->ensure_graph();
        return $this->graph ? $this->graph->request($method, $path, $body, $headers) : new WP_Error('graph_unavailable', 'Microsoft Graph-klienten är inte tillgänglig.');
    }

    private function ensure_graph(): void
    {
        if (! $this->graph && class_exists('SSF\\MemberPortal\\Integrations\\Microsoft365\\GraphClient') && class_exists('SSF\\MemberPortal\\Integrations\\Microsoft365\\Authentication')) {
            $this->graph = new \SSF\MemberPortal\Integrations\Microsoft365\GraphClient(new \SSF\MemberPortal\Integrations\Microsoft365\Authentication());
        }
    }

    private function drive_base(): string
    {
        return 'drives/' . rawurlencode($this->config('application_drive_id'));
    }

    private function item_path(string $item_id): string
    {
        return $this->drive_base() . '/items/' . rawurlencode($item_id);
    }

    private function list_base(string $list_id): string
    {
        return 'sites/' . rawurlencode($this->config('application_site_id')) . '/lists/' . rawurlencode($list_id);
    }

    private function log(string $event, array $context): void
    {
        if (class_exists('SSF\MemberPortal\Core\Logger')) {
            \SSF\MemberPortal\Core\Logger::add($event, $context);
        }
    }
}
