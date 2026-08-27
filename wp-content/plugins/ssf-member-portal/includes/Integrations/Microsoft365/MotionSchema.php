<?php

namespace SSF\MemberPortal\Integrations\Microsoft365;

use SSF\MemberPortal\Core\Logger;
use SSF\MemberPortal\Modules\Motions\MotionStatus;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Owns the SharePoint document-library schema used by motion files.
 *
 * The schema is discovered only during setup, upload and repair. Routine
 * status polling uses the cached list and internal column identifiers.
 */
final class MotionSchema
{
    private const OPTION = 'ssf_member_portal_graph_motion_schema';
    private const STATUS_NAME = 'Status';

    private GraphClient $graph;

    public function __construct(GraphClient $graph)
    {
        $this->graph = $graph;
    }

    public function ensure_status_column()
    {
        $list_id = $this->document_library_list_id();
        if (is_wp_error($list_id)) {
            return $list_id;
        }

        $columns = $this->get_columns($list_id);
        if (is_wp_error($columns)) {
            return $columns;
        }

        $column = $this->find_status_column($columns);
        if (! $column) {
            $column = $this->create_status_column($list_id);
            if (is_wp_error($column)) {
                return $column;
            }
            Logger::add('motion_sharepoint_status_column_created', array('list_id' => $list_id, 'column_id' => $column['id'] ?? ''));
        }

        if (empty($column['choice']) || ! is_array($column['choice'])) {
            return new \WP_Error(
                'sharepoint_status_column_invalid',
                __('SharePoint-kolumnen Status finns, men är inte av typen Choice.', 'ssf-member-portal')
            );
        }

        $column = $this->ensure_status_choices($list_id, $column);
        if (is_wp_error($column)) {
            return $column;
        }

        $context = array(
            'list_id' => $list_id,
            'status_column_id' => sanitize_text_field((string) ($column['id'] ?? '')),
            'status_field' => sanitize_text_field((string) ($column['name'] ?? $column['displayName'] ?? '')),
            'status_display_name' => sanitize_text_field((string) ($column['displayName'] ?? self::STATUS_NAME)),
            'choices' => array_values(array_map('sanitize_text_field', (array) ($column['choice']['choices'] ?? array()))),
            'verified_at' => gmdate('c'),
        );
        if (! $context['status_column_id'] || ! $context['status_field']) {
            return new \WP_Error('sharepoint_status_column_missing_id', __('SharePoint returnerade inte kolumnens interna namn.', 'ssf-member-portal'));
        }

        update_option(self::OPTION, $context, false);
        Configuration::save_discovered_document_library_list_id($list_id);
        return $context;
    }

    /**
     * Returns a cached schema context for normal writes and status polling.
     */
    public function status_context()
    {
        $context = (array) get_option(self::OPTION, array());
        $configured_list_id = Configuration::value('document_library_list_id');
        if (
            ! empty($context['list_id'])
            && ! empty($context['status_column_id'])
            && ! empty($context['status_field'])
            && (! $configured_list_id || $configured_list_id === (string) $context['list_id'])
        ) {
            return $context;
        }

        return $this->ensure_status_column();
    }

    public function diagnostics()
    {
        $context = $this->ensure_status_column();
        if (is_wp_error($context)) {
            return $context;
        }

        $required = $this->required_choices();
        $available = (array) ($context['choices'] ?? array());
        $missing = $this->missing_choices($available);
        return array(
            'list_id' => $context['list_id'],
            'status_column' => $context['status_display_name'],
            'internal_name' => $context['status_field'],
            'choice_column' => true,
            'required_choices' => count($required),
            'available_choices' => count($available),
            'missing_choices' => $missing,
            'verified_at' => $context['verified_at'],
        );
    }

    public function get_columns(string $list_id)
    {
        $path = $this->list_base($list_id) . '/columns?$select=id,name,displayName,choice,hidden';
        $columns = array();
        while ($path) {
            $page = $this->graph->request('GET', $path);
            if (is_wp_error($page)) {
                return $page;
            }
            $columns = array_merge($columns, (array) ($page['value'] ?? array()));
            $path = $this->relative_graph_path((string) ($page['@odata.nextLink'] ?? ''));
        }

        return $columns;
    }

    public function find_status_column(array $columns): ?array
    {
        $expected = $this->normalise(self::STATUS_NAME);
        foreach ($columns as $column) {
            if (! is_array($column)) {
                continue;
            }
            if ($expected === $this->normalise((string) ($column['displayName'] ?? '')) || $expected === $this->normalise((string) ($column['name'] ?? ''))) {
                return $column;
            }
        }

        return null;
    }

    public function create_status_column(string $list_id)
    {
        $created = $this->graph->request('POST', $this->list_base($list_id) . '/columns', array(
            'displayName' => self::STATUS_NAME,
            'name' => self::STATUS_NAME,
            'choice' => array(
                'allowTextEntry' => false,
                'choices' => $this->required_choices(),
                'displayAs' => 'dropDownMenu',
            ),
        ));
        if (! is_wp_error($created)) {
            return $created;
        }

        $error_data = (array) $created->get_error_data();
        if (409 !== (int) ($error_data['http_status'] ?? $error_data['status'] ?? 0)) {
            return $created;
        }

        // Another request may have created the column between discovery and POST.
        $columns = $this->get_columns($list_id);
        if (is_wp_error($columns)) {
            return $columns;
        }
        return $this->find_status_column($columns) ?: $created;
    }

    public function ensure_status_choices(string $list_id, array $column)
    {
        $existing = array_values(array_filter(array_map('sanitize_text_field', (array) ($column['choice']['choices'] ?? array()))));
        $missing = $this->missing_choices($existing);
        $choice = (array) ($column['choice'] ?? array());
        $requires_update = $missing || ! empty($choice['allowTextEntry']) || 'dropDownMenu' !== (string) ($choice['displayAs'] ?? '');
        if (! $requires_update) {
            return $column;
        }

        $choice['choices'] = array_merge($existing, $missing);
        $choice['allowTextEntry'] = false;
        $choice['displayAs'] = 'dropDownMenu';
        $updated = $this->graph->request(
            'PATCH',
            $this->list_base($list_id) . '/columns/' . rawurlencode((string) ($column['id'] ?? '')),
            array('choice' => $choice)
        );
        if (! is_wp_error($updated)) {
            Logger::add('motion_sharepoint_status_choices_repaired', array('list_id' => $list_id, 'added_choices' => $missing));
        }

        return $updated;
    }

    private function document_library_list_id()
    {
        $configured = Configuration::value('document_library_list_id');
        if ($configured) {
            return $configured;
        }

        $path = $this->site_base() . '/lists?$select=id,name,displayName,list,webUrl';
        $lists = array();
        while ($path) {
            $page = $this->graph->request('GET', $path);
            if (is_wp_error($page)) {
                return $page;
            }
            $lists = array_merge($lists, (array) ($page['value'] ?? array()));
            $path = $this->relative_graph_path((string) ($page['@odata.nextLink'] ?? ''));
        }

        $library_name = Configuration::value('document_library_name') ?: 'Dokument';
        foreach ($lists as $list) {
            if (! is_array($list) || ! $this->is_document_library($list)) {
                continue;
            }
            if ($this->normalise($library_name) === $this->normalise((string) ($list['displayName'] ?? '')) || $this->normalise($library_name) === $this->normalise((string) ($list['name'] ?? ''))) {
                return sanitize_text_field((string) ($list['id'] ?? ''));
            }
        }

        $drive_id = Configuration::value('drive_id');
        foreach ($lists as $list) {
            if (! is_array($list) || ! $this->is_document_library($list) || empty($list['id'])) {
                continue;
            }
            $drive = $this->graph->request('GET', $this->list_base((string) $list['id']) . '/drive?$select=id');
            if (! is_wp_error($drive) && $drive_id === (string) ($drive['id'] ?? '')) {
                return sanitize_text_field((string) $list['id']);
            }
        }

        return new \WP_Error('sharepoint_document_library_list_missing', __('Kunde inte hitta SharePoint-listan för dokumentbiblioteket.', 'ssf-member-portal'));
    }

    private function required_choices(): array
    {
        return array_values(MotionStatus::all());
    }

    private function missing_choices(array $existing): array
    {
        $known = array();
        foreach ($existing as $choice) {
            $known[$this->normalise((string) $choice)] = true;
        }

        return array_values(array_filter($this->required_choices(), function (string $choice) use ($known): bool {
            return ! isset($known[$this->normalise($choice)]);
        }));
    }

    private function is_document_library(array $list): bool
    {
        return 'documentlibrary' === strtolower((string) ($list['list']['template'] ?? ''));
    }

    private function site_base(): string
    {
        return 'sites/' . rawurlencode(Configuration::value('site_id'));
    }

    private function list_base(string $list_id): string
    {
        return $this->site_base() . '/lists/' . rawurlencode($list_id);
    }

    private function relative_graph_path(string $url): string
    {
        return preg_replace('#^https://graph\\.microsoft\\.com/v1\\.0/#i', '', $url) ?: '';
    }

    private function normalise(string $value): string
    {
        $value = trim(remove_accents($value));
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        return preg_replace('/\\s+/u', ' ', $value) ?: '';
    }
}
