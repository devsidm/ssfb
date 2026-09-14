<?php

namespace SSF\MemberPortal\Integrations\Microsoft365;

use SSF\MemberPortal\Core\Logger;
use SSF\MemberPortal\Modules\Motions\MotionStatus;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Verifies the SharePoint document-library schema used by motion files.
 *
 * The WordPress runtime must not administer SharePoint schema. It may read
 * and cache the verified list/internal column identifiers only.
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
            return $this->remember_failure($list_id, 'document_library');
        }

        // Keep the discovered library ID even when Sites.Selected permits file
        // writes but does not allow the broader columns endpoint.
        Configuration::save_discovered_document_library_list_id($list_id);

        $columns = $this->get_columns($list_id);
        if (is_wp_error($columns)) {
            return $this->remember_failure($columns, 'read_columns', $list_id);
        }

        $column = $this->find_status_column($columns);
        if (! $column) {
            return $this->remember_failure(new \WP_Error(
                'sharepoint_status_column_missing',
                __('SharePoint-kolumnen Status saknas. Skapa kolumnen manuellt i dokumentbiblioteket och kör kontrollen igen.', 'ssf-member-portal')
            ), 'validate_column', $list_id);
        }

        if (empty($column['choice']) || ! is_array($column['choice'])) {
            return $this->remember_failure(new \WP_Error(
                'sharepoint_status_column_invalid',
                __('SharePoint-kolumnen Status finns, men är inte av typen Choice.', 'ssf-member-portal')
            ), 'validate_column', $list_id);
        }

        $choice = (array) ($column['choice'] ?? array());
        $existing_choices = array_values(array_map('sanitize_text_field', (array) ($choice['choices'] ?? array())));
        $missing_choices = $this->missing_choices($existing_choices);
        if ($missing_choices || ! empty($choice['allowTextEntry'])) {
            return $this->remember_failure(new \WP_Error(
                'sharepoint_status_column_manual_action_required',
                __('SharePoint-kolumnen Status har fel schema. Egna val ska vara avstängt och alla fördefinierade statusvärden måste finnas. Åtgärda manuellt i SharePoint och kör kontrollen igen.', 'ssf-member-portal'),
                array(
                    'missing_choices' => $missing_choices,
                    'allow_text_entry' => ! empty($choice['allowTextEntry']),
                )
            ), 'validate_column', $list_id);
        }

        $verified_at = gmdate('c');
        $context = array(
            'list_id' => $list_id,
            'status_column_id' => sanitize_text_field((string) ($column['id'] ?? '')),
            'status_field' => sanitize_text_field((string) ($column['name'] ?? $column['displayName'] ?? '')),
            'status_display_name' => sanitize_text_field((string) ($column['displayName'] ?? self::STATUS_NAME)),
            'choices' => $existing_choices,
            'allow_text_entry' => ! empty($choice['allowTextEntry']),
            'last_checked_at' => $verified_at,
            'verified_at' => $verified_at,
        );
        if (! $context['status_column_id'] || ! $context['status_field']) {
            return $this->remember_failure(
                new \WP_Error('sharepoint_status_column_missing_id', __('SharePoint returnerade inte kolumnens interna namn.', 'ssf-member-portal')),
                'validate_column',
                $list_id
            );
        }

        update_option(self::OPTION, $context, false);
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
        return new \WP_Error(
            'sharepoint_schema_write_disabled',
            __('Automatisk skapning av SharePoint-kolumner är avstängd. Skapa kolumnen manuellt i SharePoint.', 'ssf-member-portal')
        );
    }

    public function ensure_status_choices(string $list_id, array $column)
    {
        return new \WP_Error(
            'sharepoint_schema_write_disabled',
            __('Automatisk uppdatering av SharePoint Choice-värden är avstängd. Uppdatera kolumnen manuellt i SharePoint.', 'ssf-member-portal')
        );
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

    private function remember_failure(\WP_Error $error, string $stage, string $list_id = ''): \WP_Error
    {
        $data = (array) $error->get_error_data();
        $status = (int) ($data['http_status'] ?? $data['status'] ?? 0);
        if (403 === $status) {
            $data['schema_stage'] = $stage;
            $data['required_application_permission'] = 'Sites.Selected';
            $data['required_site_role'] = 'site-scoped access for reading list columns';
            $error = new \WP_Error(
                'sharepoint_motion_schema_access_denied',
                __('Microsoft Graph nekade läsåtkomst till dokumentbibliotekets kolumnschema. WordPress försöker inte reparera SharePoint-schema automatiskt; kontrollera site-avgränsad Sites.Selected-åtkomst och att kolumnen finns manuellt i SharePoint.', 'ssf-member-portal'),
                $data
            );
        }

        $context = (array) get_option(self::OPTION, array());
        if ($list_id) {
            $context['list_id'] = sanitize_text_field($list_id);
        }
        $context['last_checked_at'] = gmdate('c');
        $context['last_error'] = sanitize_text_field($error->get_error_message());
        $context['last_error_code'] = sanitize_key($error->get_error_code());
        $context['last_error_stage'] = sanitize_key($stage);
        update_option(self::OPTION, $context, false);
        Logger::add('motion_sharepoint_status_schema_failed', array(
            'stage' => $stage,
            'http_status' => $status,
            'graph_code' => sanitize_key((string) ($data['graph_code'] ?? '')),
        ));

        return $error;
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
