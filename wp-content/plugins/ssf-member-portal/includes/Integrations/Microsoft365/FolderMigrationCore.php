<?php

namespace SSF\MemberPortal\Integrations\Microsoft365;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Generic, copy-only SharePoint folder migration engine.
 *
 * This service intentionally has no WordPress post/application knowledge.  A
 * caller supplies stable SharePoint references for a source and a target root;
 * this class inventories, plans, copies, verifies and persists item state.
 */
final class FolderMigrationCore
{
    private const STATE_OPTION = 'ssf_sharepoint_folder_migration_state';
    private const SYSTEM_FIELDS = array('id', '@odata.etag', 'ContentType', 'ContentTypeId', 'Created', 'Modified', 'Author', 'AuthorLookupId', 'Editor', 'EditorLookupId', 'AppAuthorLookupId', 'AppEditorLookupId', 'ParentVersionStringLookupId', 'ParentLeafNameLookupId', '_UIVersionString', 'FileRef', 'FileLeafRef', 'FSObjType', 'LinkFilename', 'LinkFilenameNoMenu', 'Edit', 'DocIcon', 'FileSizeDisplay', 'ItemChildCount', 'FolderChildCount', 'ComplianceAssetId');
    private const MEMBERSHIP_CANONICAL_SIGNATURE = array('ApplicationNumber', 'ApplicationStatus', 'VesselName', 'ApplicationPath', 'ReceivedDate');
    private const MEMBERSHIP_LEGACY_FIELDS = array('Ansokningsnummer', 'Status', 'Fartyg', 'InkommenDatum', 'Ansokningsvag');

    private GraphClient $graph;

    public function __construct(GraphClient $graph)
    {
        $this->graph = $graph;
    }

    /** Full-depth, paginated inventory. It never writes to SharePoint. */
    public function inventory(array $source)
    {
        $required = $this->location($source, 'Källa');
        if (is_wp_error($required)) {
            return $required;
        }
        $root = $this->item((string) $source['drive_id'], (string) $source['folder_id']);
        if (is_wp_error($root) || empty($root['folder'])) {
            return is_wp_error($root) ? $root : new \WP_Error('migration_source_not_folder', 'Den valda källan är inte en mapp.');
        }
        $items = array();
        $queue = array(array('item' => $root, 'path' => trim((string) ($source['folder_path'] ?? $root['name']), '/'), 'depth' => 0));
        $seen = array();
        $bytes = 0;
        while ($queue) {
            $current = array_shift($queue);
            $id = (string) ($current['item']['id'] ?? '');
            if (! $id || isset($seen[$id])) {
                if ($id) {
                    return new \WP_Error('migration_inventory_loop', 'Källträdet innehåller en återkommande DriveItem-referens. Inventeringen är blockerad.');
                }
                continue;
            }
            $seen[$id] = true;
            $record = $this->record($current['item'], $current['path'], $current['depth']);
            $items[] = $record;
            if ('file' === $record['type']) {
                $bytes += (int) $record['size'];
                continue;
            }
            $children = $this->children((string) $source['drive_id'], $id);
            if (is_wp_error($children)) {
                return $children;
            }
            foreach ($children as $child) {
                $name = (string) ($child['name'] ?? '');
                $queue[] = array('item' => $child, 'path' => trim($current['path'] . '/' . $name, '/'), 'depth' => $current['depth'] + 1);
            }
        }
        $columns = $this->columns($source);
        if (is_wp_error($columns)) {
            return $columns;
        }
        $populated = array();
        foreach ($items as $item) {
            foreach ((array) $item['metadata'] as $field => $value) {
                if ($this->populated($value)) {
                    $populated[$field] = true;
                }
            }
        }
        $folders = count(array_filter($items, static fn($item) => 'folder' === $item['type']));
        $files = count($items) - $folders;
        return $this->apply_metadata_policy(array('ok' => true, 'completed_at' => gmdate('c'), 'source' => $source, 'items' => $items, 'columns' => $columns, 'summary' => array('folders' => $folders, 'files' => $files, 'bytes' => $bytes, 'metadata_fields_used' => count($populated), 'schema_fields_required' => count($populated)), 'populated_fields' => array_keys($populated)));
    }

    /** Dry run: destination calculation and schema diff only; zero writes. */
    public function dry_run(array $source, array $target, array $inventory)
    {
        if (empty($inventory['ok'])) {
            return new \WP_Error('migration_inventory_required', 'Inventera källan utan fel före torrkörning.');
        }
        $inventory = $this->apply_metadata_policy($inventory);
        $destination_check = $this->inspect_destination($source, $target);
        if (is_wp_error($destination_check)) {
            return $destination_check;
        }
        $segments = (array) $destination_check['segments'];
        $target_columns = $this->columns($target);
        if (is_wp_error($target_columns)) {
            return $target_columns;
        }
        $schema = $this->schema_plan((array) $inventory['columns'], $target_columns, (array) $inventory['populated_fields']);
        $destination = (string) $destination_check['destination_path'];
        $existing = (array) $destination_check['existing_destination'];
        $blockers = (array) $schema['blockers'];
        $warnings = array();
        if (! empty($schema['create'])) {
            $blockers[] = 'Målet saknar ' . count((array) $schema['create']) . ' nödvändiga kolumner. Skapa dem manuellt i målbiblioteket och kör torrkörningen igen.';
        }
        $confirmed_existing = ! empty($existing['id'])
            && 'replace_files' === (string) ($target['existing_target_policy'] ?? '')
            && hash_equals((string) $existing['id'], (string) ($target['confirmed_existing_target_id'] ?? ''));
        if (! empty($existing['id']) && ! $confirmed_existing) {
            $blockers[] = 'Målmappen finns redan: ' . $destination . '. Bekräfta i steg 3 om den ska användas och filer med samma namn skrivas över.';
        } elseif ($confirmed_existing) {
            $warnings[] = 'Den befintliga målmappen används. Filer med samma namn skrivs över; andra befintliga objekt lämnas orörda.';
        }
        if (! empty($inventory['metadata_policy']['excluded_fields'])) {
            $warnings[] = 'Äldre medlemsfält ignoreras: ' . implode(', ', (array) $inventory['metadata_policy']['excluded_fields']) . '. Kanoniska medlemsfält används i stället.';
        }
        return array('ok' => empty($blockers), 'dry_run_at' => gmdate('c'), 'writes' => 0, 'destination_path' => $destination, 'segments' => $segments, 'intermediate_segments' => array_slice($segments, 0, -1), 'final_root_name' => end($segments), 'source_summary' => (array) $inventory['summary'], 'schema' => $schema, 'existing_destination' => $existing, 'existing_destination_confirmed' => $confirmed_existing, 'blockers' => $blockers, 'warnings' => $warnings);
    }

    /** Verify only the calculated destination path; no target contents are inventoried. */
    public function inspect_destination(array $source, array $target)
    {
        $target_check = $this->location($target, 'Vald root');
        if (is_wp_error($target_check)) {
            return $target_check;
        }
        $name = (string) ($target['destination_folder_name'] ?? $source['folder_name'] ?? '');
        $segments = $this->segments((string) ($target['extra_structure'] ?? ''), $name);
        if (is_wp_error($segments)) {
            return $segments;
        }
        $destination = trim(trim((string) ($target['folder_path'] ?? ''), '/') . '/' . implode('/', $segments), '/');
        $existing = $this->find_path((string) $target['drive_id'], (string) $target['folder_id'], $segments);
        if (is_wp_error($existing)) {
            return $existing;
        }
        return array(
            'ok' => true,
            'checked_at' => gmdate('c'),
            'destination_path' => $destination,
            'segments' => $segments,
            'exists' => ! empty($existing['id']),
            'existing_destination' => is_array($existing) ? $existing : array(),
        );
    }

    /** Verify the complete schema before creating any destination folder. */
    public function prepare(array $target, array $dry_run)
    {
        if (! empty($dry_run['schema']['create'])) {
            return new \WP_Error('migration_schema_provisioning_required', 'Målet saknar nödvändiga kolumner. Inga måländringar gjordes. Skapa kolumnerna manuellt och kör torrkörningen igen.');
        }
        if (empty($dry_run['ok'])) {
            return new \WP_Error('migration_prepare_blocked', 'Torrkörningen har blockerande fel. Inga måländringar gjordes.');
        }
        $confirmed_existing_id = (string) ($target['confirmed_existing_target_id'] ?? '');
        $planned_existing_id = (string) ($dry_run['existing_destination']['id'] ?? '');
        if ($planned_existing_id && (! $confirmed_existing_id || ! hash_equals($planned_existing_id, $confirmed_existing_id))) {
            return new \WP_Error('migration_existing_target_unconfirmed', 'Den befintliga målmappen är inte uttryckligen bekräftad. Inga måländringar gjordes.');
        }
        $verified_columns = $this->columns($target);
        if (is_wp_error($verified_columns)) {
            return $verified_columns;
        }
        $verified_names = array();
        foreach ((array) ($verified_columns['value'] ?? array()) as $column) {
            $verified_names[(string) ($column['name'] ?? '')] = true;
        }
        foreach ((array) ($dry_run['schema']['exact'] ?? array()) as $name) {
            if (empty($verified_names[(string) $name])) {
                return new \WP_Error('migration_schema_verify_failed', 'En verifierad målkolumn saknas nu: ' . $name . '. Inga målmappar skapades. Kör torrkörningen igen.');
            }
        }

        $parent = (string) $target['folder_id'];
        foreach ((array) $dry_run['segments'] as $segment) {
            $next = $this->find_child((string) $target['drive_id'], $parent, (string) $segment);
            if (is_wp_error($next)) {
                return $next;
            }
            if (! $next) {
                $next = $this->create_folder((string) $target['drive_id'], $parent, (string) $segment);
                if (is_wp_error($next)) {
                    return $next;
                }
            }
            $parent = (string) $next['id'];
        }
        if ($planned_existing_id && ! hash_equals($planned_existing_id, $parent)) {
            return new \WP_Error('migration_existing_target_changed', 'Målmappen har ändrats sedan verifieringen. Kör torrkörningen igen.');
        }
        $read = $this->item((string) $target['drive_id'], $parent);
        if (is_wp_error($read) || empty($read['folder'])) {
            return is_wp_error($read) ? $read : new \WP_Error('migration_prepare_verify_failed', 'Målroten kunde inte läsas tillbaka.');
        }
        return array('ok' => true, 'prepared_at' => gmdate('c'), 'target_folder_id' => $parent, 'target_folder_web_url' => esc_url_raw((string) ($read['webUrl'] ?? '')), 'created_source_children' => 0, 'schema_verified' => true);
    }

    /** A real, self-cleaning write probe against the prepared migration root. */
    public function write_test(array $target, string $root_id, array $inventory): array
    {
        $inventory = $this->apply_metadata_policy($inventory);
        $folder_name = 'ssf-migration-test-' . gmdate('Ymd-His') . '-' . wp_generate_password(4, false, false);
        $steps = array(); $folder_id = ''; $file_id = '';
        $folder = $this->create_folder((string) $target['drive_id'], $root_id, $folder_name);
        $steps['folder'] = array('label' => 'Mapp kunde skapas', 'ok' => ! is_wp_error($folder));
        if (! is_wp_error($folder)) {
            $folder_id = (string) ($folder['id'] ?? '');
            $file = $this->graph->request('PUT', 'drives/' . rawurlencode((string) $target['drive_id']) . '/items/' . rawurlencode($folder_id) . ':/diagnostic.txt:/content', "SSF generic migration write test\n", array('Content-Type' => 'text/plain; charset=utf-8'));
            $steps['file'] = array('label' => 'Fil kunde skrivas', 'ok' => ! is_wp_error($file));
            $file_id = is_wp_error($file) ? '' : (string) ($file['id'] ?? '');
            $sample = array();
            foreach ((array) ($inventory['items'] ?? array()) as $item) { if (! empty($item['metadata'])) { $sample = array_slice((array) $item['metadata'], 0, 1, true); break; } }
            if ($file_id && $sample) {
                $list = $this->item((string) $target['drive_id'], $file_id);
                $patched = is_wp_error($list) ? $list : $this->graph->request('PATCH', 'sites/' . rawurlencode((string) $target['site_id']) . '/lists/' . rawurlencode((string) $target['list_id']) . '/items/' . rawurlencode((string) ($list['listItem']['id'] ?? '')) . '/fields', $sample);
                $read = is_wp_error($patched) ? $patched : $this->item((string) $target['drive_id'], $file_id);
                $match = ! is_wp_error($read);
                foreach ($sample as $key => $value) { if (wp_json_encode($value) !== wp_json_encode($read['listItem']['fields'][$key] ?? null)) $match = false; }
                $steps['metadata'] = array('label' => 'Metadata kunde skrivas och läsas tillbaka korrekt', 'ok' => ! is_wp_error($patched) && $match);
            } else {
                $steps['metadata'] = array('label' => 'Metadata kunde skrivas och läsas tillbaka korrekt', 'ok' => true, 'message' => 'Inga relevanta metadatafält användes i källan.');
            }
            $steps['read'] = array('label' => 'Fil kunde läsas tillbaka', 'ok' => $file_id && ! is_wp_error($this->item((string) $target['drive_id'], $file_id)));
        }
        $delete_file = $file_id ? $this->graph->request('DELETE', 'drives/' . rawurlencode((string) $target['drive_id']) . '/items/' . rawurlencode($file_id)) : new \WP_Error('test_file_missing', 'Testfil saknas.');
        $delete_folder = $folder_id ? $this->graph->request('DELETE', 'drives/' . rawurlencode((string) $target['drive_id']) . '/items/' . rawurlencode($folder_id)) : new \WP_Error('test_folder_missing', 'Testmapp saknas.');
        $steps['cleanup_file'] = array('label' => 'Testfil togs bort', 'ok' => ! is_wp_error($delete_file));
        $steps['cleanup_folder'] = array('label' => 'Testmapp togs bort', 'ok' => ! is_wp_error($delete_folder));
        $ok = ! in_array(false, array_column($steps, 'ok'), true);
        return array('ok' => $ok, 'steps' => $steps, 'artifact' => $ok ? array() : array('folder_id' => $folder_id, 'file_id' => $file_id), 'tested_at' => gmdate('c'));
    }

    /** Incremental, resumable source-to-source SharePoint copy. Never deletes source. */
    public function migrate(array $source, array $target, array $inventory, string $target_root_id)
    {
        $inventory = $this->apply_metadata_policy($inventory);
        $state = $this->state();
        $state['items'] = (array) ($state['items'] ?? array());
        $folders = array_filter((array) $inventory['items'], static fn($item) => 'folder' === $item['type']);
        usort($folders, static fn($a, $b) => $a['depth'] <=> $b['depth']);
        $folder_map = array((string) $source['folder_id'] => $target_root_id);
        foreach ($folders as $folder) {
            $source_id = (string) $folder['id'];
            $known = (array) ($state['items'][$source_id] ?? array());
            if ('VERIFIED' === ($known['state'] ?? '') && ! empty($known['target_id'])) {
                $folder_map[$source_id] = (string) $known['target_id'];
                continue;
            }
            $target_id = $source_id === (string) $source['folder_id'] ? $target_root_id : '';
            if (! $target_id) {
                $target_parent = $folder_map[(string) $folder['parent_id']] ?? '';
                if (! $target_parent) {
                    return $this->fail($state, $source_id, $folder, 'Målmapp för källans förälder saknas.');
                }
                $target_item = $this->find_child((string) $target['drive_id'], $target_parent, (string) $folder['name']);
                if (is_wp_error($target_item)) {
                    return $this->fail($state, $source_id, $folder, $target_item->get_error_message());
                }
                if (! $target_item) {
                    $target_item = $this->create_folder((string) $target['drive_id'], $target_parent, (string) $folder['name']);
                }
                if (is_wp_error($target_item)) {
                    return $this->fail($state, $source_id, $folder, $target_item->get_error_message());
                }
                $target_id = (string) $target_item['id'];
            }
            $folder_map[$source_id] = $target_id;
            $result = $this->metadata_and_verify($target, $folder, $target_id);
            if (is_wp_error($result)) {
                return $this->fail($state, $source_id, $folder, $result->get_error_message());
            }
            $state['items'][$source_id] = $this->verified($folder, $target_id);
            $this->save_state($state);
        }
        foreach ((array) $inventory['items'] as $file) {
            if ('file' !== $file['type']) {
                continue;
            }
            $source_id = (string) $file['id'];
            if ('VERIFIED' === (($state['items'][$source_id]['state'] ?? ''))) {
                continue;
            }
            $parent = $folder_map[(string) $file['parent_id']] ?? '';
            if (! $parent) {
                return $this->fail($state, $source_id, $file, 'Målmapp för filen saknas.');
            }
            $state['items'][$source_id] = array_merge($this->pending($file), array('state' => 'COPYING'));
            $this->save_state($state);
            $copy = $this->copy_file($source, $target, $source_id, $parent, (string) $file['name']);
            if (is_wp_error($copy)) {
                return $this->fail($state, $source_id, $file, $copy->get_error_message());
            }
            $result = $this->metadata_and_verify($target, $file, (string) $copy['id']);
            if (is_wp_error($result)) {
                return $this->fail($state, $source_id, $file, $result->get_error_message());
            }
            $state['items'][$source_id] = $this->verified($file, (string) $copy['id']);
            $this->save_state($state);
        }
        $state['completed_at'] = gmdate('c');
        $this->save_state($state);
        return array('ok' => true, 'state' => $state);
    }

    public function reconcile(array $source, array $target, array $inventory, string $target_root_id): array
    {
        $state = $this->state();
        $verified = count(array_filter((array) ($state['items'] ?? array()), static fn($item) => 'VERIFIED' === ($item['state'] ?? '')));
        return array('ok' => $verified === count((array) $inventory['items']), 'source_folders' => $inventory['summary']['folders'] ?? 0, 'source_files' => $inventory['summary']['files'] ?? 0, 'verified_items' => $verified, 'expected_items' => count((array) $inventory['items']), 'source_untouched' => true, 'target_root_id' => $target_root_id);
    }

    private function children(string $drive, string $parent)
    {
        $next = 'drives/' . rawurlencode($drive) . '/items/' . rawurlencode($parent) . '/children?$select=id,name,size,folder,file,webUrl,parentReference,listItem&$expand=listItem($expand=fields)';
        $all = array(); $seen = array();
        while ($next) {
            if (isset($seen[$next])) return new \WP_Error('migration_pagination_loop', 'Graph-paginationen återkom till samma sida; inventeringen är blockerad.');
            $seen[$next] = true;
            $page = $this->graph->request('GET', $next);
            if (is_wp_error($page)) return $page;
            $all = array_merge($all, (array) ($page['value'] ?? array()));
            $next = esc_url_raw((string) ($page['@odata.nextLink'] ?? ''));
        }
        return $all;
    }

    private function item(string $drive, string $id)
    {
        return $this->graph->request('GET', 'drives/' . rawurlencode($drive) . '/items/' . rawurlencode($id) . '?$select=id,name,size,folder,file,webUrl,parentReference,listItem&$expand=listItem($expand=fields)');
    }

    private function record(array $item, string $path, int $depth): array
    {
        $metadata = (array) ($item['listItem']['fields'] ?? array());
        foreach (self::SYSTEM_FIELDS as $field) unset($metadata[$field]);
        return array('id' => sanitize_text_field((string) ($item['id'] ?? '')), 'parent_id' => sanitize_text_field((string) ($item['parentReference']['id'] ?? '')), 'name' => sanitize_text_field((string) ($item['name'] ?? '')), 'path' => $path, 'depth' => $depth, 'type' => ! empty($item['folder']) ? 'folder' : 'file', 'size' => (int) ($item['size'] ?? 0), 'metadata' => $metadata);
    }

    /** Keep legacy membership aliases from polluting or overwriting a generic target library. */
    private function apply_metadata_policy(array $inventory): array
    {
        $column_names = array();
        foreach ((array) ($inventory['columns']['value'] ?? array()) as $column) {
            $name = (string) ($column['name'] ?? '');
            if ($name) {
                $column_names[$name] = true;
            }
        }
        $signature_matches = count(array_intersect(self::MEMBERSHIP_CANONICAL_SIGNATURE, array_keys($column_names)));
        if (empty($column_names['ApplicationStatus']) || $signature_matches < 3) {
            return $inventory;
        }

        $excluded = array();
        $populated = array();
        foreach ((array) ($inventory['items'] ?? array()) as $index => $item) {
            $metadata = (array) ($item['metadata'] ?? array());
            foreach (self::MEMBERSHIP_LEGACY_FIELDS as $field) {
                if (array_key_exists($field, $metadata)) {
                    if ($this->populated($metadata[$field])) {
                        $excluded[$field] = true;
                    }
                    unset($metadata[$field]);
                }
            }
            foreach ($metadata as $field => $value) {
                if ($this->populated($value)) {
                    $populated[$field] = true;
                }
            }
            $inventory['items'][$index]['metadata'] = $metadata;
        }
        $inventory['populated_fields'] = array_keys($populated);
        $inventory['summary']['metadata_fields_used'] = count($populated);
        $inventory['summary']['schema_fields_required'] = count($populated);
        $inventory['metadata_policy'] = array(
            'profile' => 'canonical_membership',
            'excluded_fields' => array_values(array_intersect(self::MEMBERSHIP_LEGACY_FIELDS, array_keys($excluded))),
        );
        return $inventory;
    }

    private function columns(array $location)
    {
        return $this->graph->request('GET', 'sites/' . rawurlencode((string) $location['site_id']) . '/lists/' . rawurlencode((string) $location['list_id']) . '/columns?$select=id,name,displayName,description,hidden,readOnly,required,choice,text,number,currency,boolean,dateTime,personOrGroup,lookup,hyperlinkOrPicture');
    }

    private function schema_plan(array $source_columns, array $target_columns, array $used): array
    {
        $source_by_name = array(); foreach ((array) ($source_columns['value'] ?? array()) as $column) $source_by_name[(string) ($column['name'] ?? '')] = $column;
        $target_by_name = array(); foreach ((array) ($target_columns['value'] ?? array()) as $column) $target_by_name[(string) ($column['name'] ?? '')] = $column;
        $exact = array(); $create = array(); $blockers = array();
        foreach ($used as $name) {
            if (in_array($name, self::SYSTEM_FIELDS, true)) { continue; }
            $source = $source_by_name[$name] ?? null;
            if (! $source || ! empty($source['hidden']) || ! empty($source['readOnly'])) { $blockers[] = 'Det använda fältet ' . $name . ' kan inte återskapas säkert.'; continue; }
            $type = $this->column_type($source);
            if (! $type || ! in_array($type, array('text', 'choice', 'number', 'currency', 'boolean', 'dateTime'), true)) { $blockers[] = 'Det använda fältet ' . $name . ' har en beroende eller ej stödd typ (' . ($type ?: 'okänd') . ').'; continue; }
            if (isset($target_by_name[$name])) {
                if ($type !== $this->column_type($target_by_name[$name]) || ('choice' === $type && array_diff((array) ($source['choice']['choices'] ?? array()), (array) ($target_by_name[$name]['choice']['choices'] ?? array())))) $blockers[] = 'Målkolumnen ' . $name . ' har inkompatibel semantik.'; else $exact[] = $name;
                continue;
            }
            $payload = array('name' => $name, 'displayName' => (string) ($source['displayName'] ?? $name), $type => (object) ((array) ($source[$type] ?? array())));
            $create[] = array('name' => $name, 'display_name' => (string) ($source['displayName'] ?? $name), 'type' => $type, 'payload' => $payload);
        }
        return array('exact' => $exact, 'create' => $create, 'blockers' => $blockers);
    }

    private function column_type(array $column): string { foreach (array('text','choice','number','currency','boolean','dateTime','personOrGroup','lookup','hyperlinkOrPicture') as $type) if (array_key_exists($type, $column)) return $type; return ''; }
    private function populated($value): bool { return ! (null === $value || '' === $value || array() === $value); }
    private function location(array $location, string $label) { foreach (array('site_id','drive_id','list_id','folder_id') as $key) if (empty($location[$key])) return new \WP_Error('migration_location_missing', $label . ' saknar verifierat ' . $key . '.'); return true; }
    private function segments(string $extra, string $name) { $segments = array_filter(explode('/', trim($extra, '/')), 'strlen'); $segments[] = trim($name); foreach ($segments as $segment) if (! $segment || '.' === $segment || '..' === $segment || preg_match('/["*:<>?\\\\|\/]/', $segment) || preg_match('/[. ]$/', $segment)) return new \WP_Error('migration_destination_name_invalid', 'Målsökvägen innehåller ett ogiltigt SharePoint-mappnamn.'); return array_values($segments); }
    private function find_child(string $drive, string $parent, string $name) { $children = $this->children($drive, $parent); if (is_wp_error($children)) return $children; foreach ($children as $child) if (! empty($child['folder']) && 0 === strcasecmp($name, (string) ($child['name'] ?? ''))) return $child; return array(); }
    private function find_path(string $drive, string $root, array $segments) { $parent = $root; $last = array(); foreach ($segments as $segment) { $last = $this->find_child($drive, $parent, $segment); if (is_wp_error($last) || ! $last) return $last; $parent = (string) $last['id']; } return $last; }
    private function create_folder(string $drive, string $parent, string $name) { return $this->graph->request('POST', 'drives/' . rawurlencode($drive) . '/items/' . rawurlencode($parent) . '/children', array('name' => $name, 'folder' => new \stdClass(), '@microsoft.graph.conflictBehavior' => 'fail')); }

    private function copy_file(array $source, array $target, string $source_id, string $parent_id, string $name)
    {
        $conflict_query = 'replace_files' === (string) ($target['existing_target_policy'] ?? '') ? '?@microsoft.graph.conflictBehavior=replace' : '';
        $response = $this->graph->request_response('POST', 'drives/' . rawurlencode((string) $source['drive_id']) . '/items/' . rawurlencode($source_id) . '/copy' . $conflict_query, array('parentReference' => array('driveId' => (string) $target['drive_id'], 'id' => $parent_id), 'name' => $name));
        if (is_wp_error($response)) return $response;
        $headers = $response['headers']; $monitor = is_object($headers) ? $headers['location'] : ($headers['location'] ?? '');
        for ($attempt = 0; $attempt < 20 && $monitor; $attempt++) { $poll = $this->graph->request('GET', (string) $monitor); if (! is_wp_error($poll) && ! empty($poll['resourceId'])) break; }
        $copied = $this->find_child((string) $target['drive_id'], $parent_id, $name);
        if (is_wp_error($copied) || ! $copied) return is_wp_error($copied) ? $copied : new \WP_Error('migration_copy_unverified', 'Kopieringen slutfördes inte eller kunde inte verifieras.');
        return $copied;
    }

    private function metadata_and_verify(array $target, array $source_item, string $target_id)
    {
        $target_item = $this->item((string) $target['drive_id'], $target_id); if (is_wp_error($target_item)) return $target_item;
        if ((string) $target_item['name'] !== (string) $source_item['name'] || (int) ($source_item['size'] ?? 0) !== (int) ($target_item['size'] ?? 0)) return new \WP_Error('migration_item_mismatch', 'Målobjektets namn eller storlek matchar inte källan.');
        $values = (array) ($source_item['metadata'] ?? array());
        if ($values) { $list_id = (string) ($target_item['listItem']['id'] ?? ''); if (! $list_id) return new \WP_Error('migration_metadata_item_missing', 'Målobjektets ListItem saknas.'); $patched = $this->graph->request('PATCH', 'sites/' . rawurlencode((string) $target['site_id']) . '/lists/' . rawurlencode((string) $target['list_id']) . '/items/' . rawurlencode($list_id) . '/fields', $values); if (is_wp_error($patched)) return $patched; $read = $this->item((string) $target['drive_id'], $target_id); if (is_wp_error($read)) return $read; foreach ($values as $key => $value) if (wp_json_encode($value) !== wp_json_encode($read['listItem']['fields'][$key] ?? null)) return new \WP_Error('migration_metadata_mismatch', 'Metadata kunde inte läsas tillbaka korrekt: ' . $key); }
        return true;
    }
    private function pending(array $item): array { return array('source_id' => $item['id'], 'source_path' => $item['path'], 'type' => $item['type'], 'state' => 'PENDING', 'attempt_count' => 1); }
    private function verified(array $item, string $target_id): array { return array_merge($this->pending($item), array('target_id' => $target_id, 'target_path' => $item['path'], 'state' => 'VERIFIED', 'verification_result' => 'name,size,metadata', 'verified_at' => gmdate('c'), 'error' => '')); }
    private function fail(array &$state, string $id, array $item, string $message) { $state['items'][$id] = array_merge($this->pending($item), array('state' => 'ERROR', 'error' => sanitize_text_field($message))); $this->save_state($state); return new \WP_Error('migration_item_error', $message); }
    private function state(): array { return (array) get_option(self::STATE_OPTION, array()); }
    private function save_state(array $state): void { update_option(self::STATE_OPTION, $state, false); }
}
