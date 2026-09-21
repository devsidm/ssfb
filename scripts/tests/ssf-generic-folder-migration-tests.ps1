[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$failures = [Collections.Generic.List[string]]::new()

function Read-RepoFile([string]$Path) { Get-Content -Raw -LiteralPath (Join-Path $repo $Path) -Encoding UTF8 }
function Assert-Contains([string]$Name, [string]$Content, [string]$Expected) { if (-not $Content.Contains($Expected)) { $failures.Add("$Name saknar: $Expected") } }
function Assert-Matches([string]$Name, [string]$Content, [string]$Pattern) { if ($Content -notmatch $Pattern) { $failures.Add("$Name matchar inte: $Pattern") } }
function Assert-NotContains([string]$Name, [string]$Content, [string]$Expected) { if ($Content.Contains($Expected)) { $failures.Add("$Name innehåller otillåtet: $Expected") } }

$core = Read-RepoFile 'wp-content\plugins\ssf-member-portal\includes\Integrations\Microsoft365\FolderMigrationCore.php'
$archive = Read-RepoFile 'wp-content\plugins\ssf-medlemsprocess\includes\class-ssf-medlemsprocess-archive-migration.php'
$discovery = Read-RepoFile 'wp-content\plugins\ssf-member-portal\includes\Integrations\Microsoft365\SharePointDiscovery.php'
$graph = Read-RepoFile 'wp-content\plugins\ssf-member-portal\includes\Integrations\Microsoft365\GraphClient.php'

Assert-Contains 'Shared discovery migration profile' $archive 'data-destination="folder_migration"'
Assert-Contains 'Generic core is separate' $archive 'FolderMigrationCore'
Assert-Contains 'Generic source stable IDs' $archive "array('site_id','drive_id','list_id','folder_id')"
Assert-Contains 'Keep source name option' $archive 'name="keep_name" value="1"'
Assert-Contains 'Rename destination option' $archive 'name="keep_name" value="0"'
Assert-Contains 'Rename field disabled with keep mode' $archive 'name="destination_folder_name" value="<?php echo esc_attr($custom_name); ?>" <?php disabled($keep_name); ?>'
Assert-Contains 'Intermediate structure' $archive 'Extra struktur'
Assert-Contains 'Live preview' $archive 'data-ssf-migration-preview'
Assert-Contains 'Source selector stays in source step' $archive "render_generic_discovery_form('source', `$source)"
Assert-Contains 'Target selector stays in target step' $archive "render_generic_discovery_form('target', `$target)"
Assert-Contains 'Target library root is explicit' $archive '$direct_to_root = ! empty('
Assert-Contains 'Direct root selection is persisted' $archive "'direct_to_root'"
Assert-Contains 'Target preview only reads target selector' $archive 'data-location-kind="target"] [data-sp-field="folder_path"]'
Assert-Contains 'Mode change explains no migration starts' $archive 'Ingen migrering har startats.'
Assert-Matches 'Generic core retries Graph after plugin load' $archive 'private function generic_core\(\)\s*\{\s*\$this->ensure_graph\(\);'
Assert-Contains 'Dry run lists every blocker' $archive 'foreach ($blockers as $blocker)'
Assert-Contains 'Dry run lists planned columns' $archive 'foreach ($create_columns as $column)'
Assert-Contains 'Prepare explains disabled state' $archive "if (empty(`$dry_run['ok']))"
Assert-Contains 'Server preview removes empty path segments' $archive "array_filter(array(trim((string) (`$target['folder_path']"
Assert-Contains 'Root-only prepare wording' $archive 'Skapade migreringsundermapppar: 0'
Assert-Contains 'Incremental migration' $core 'Incremental, resumable source-to-source SharePoint copy'
Assert-Contains 'No arbitrary depth cutoff' $core 'while ($queue)'
Assert-Contains 'Graph pagination' $core "'@odata.nextLink'"
Assert-Contains 'Pagination loop protection' $core 'migration_pagination_loop'
Assert-Contains 'Populated metadata inventory' $core 'populated_fields'
Assert-Contains 'Graph etag ignored as system metadata' $core "'@odata.etag'"
Assert-Contains 'SharePoint author lookup ignored as system metadata' $core "'AuthorLookupId'"
Assert-Contains 'SharePoint editor lookup ignored as system metadata' $core "'EditorLookupId'"
Assert-Contains 'SharePoint file presentation fields ignored' $core "'DocIcon', 'FileSizeDisplay'"
Assert-Contains 'Schema plan defensively skips system metadata' $core 'in_array($name, self::SYSTEM_FIELDS, true)'
Assert-Contains 'Canonical membership signature' $core 'MEMBERSHIP_CANONICAL_SIGNATURE'
Assert-Contains 'Legacy membership aliases are isolated' $core 'MEMBERSHIP_LEGACY_FIELDS'
Assert-Contains 'Inventory applies metadata policy' $core 'return $this->apply_metadata_policy(array('
Assert-Contains 'Saved inventory is normalized in dry run' $core '$inventory = $this->apply_metadata_policy($inventory);'
Assert-Contains 'Legacy fields are removed before writes' $core 'unset($metadata[$field]);'
Assert-Contains 'Canonical status is required for legacy filtering' $core "empty(`$column_names['ApplicationStatus'])"
Assert-Contains 'UI explains canonical membership metadata' $archive 'Kanonisk medlemsmetadata:'
Assert-Contains 'Dry run has zero writes' $core "'writes' => 0"
Assert-Contains 'Missing columns block dry run' $core "if (! empty(`$schema['create']))"
Assert-Contains 'Old approved plans cannot create a folder' $core 'migration_schema_provisioning_required'
Assert-NotContains 'Runtime must not create SharePoint columns' $core 'migration_schema_create_failed'
Assert-Contains 'Destination inspection is read only' $core 'public function inspect_destination(array $source, array $target)'
Assert-Contains 'Direct library root creates no duplicate source folder' $core "'segments' => array()"
Assert-Contains 'Direct library root is verified specially' $core "'verification_result' => 'target-library-root'"
Assert-Contains 'Existing destination requires confirmation' $core 'migration_existing_target_unconfirmed'
Assert-Contains 'Existing destination identity is rechecked' $core 'migration_existing_target_changed'
Assert-Contains 'Confirmed file conflicts use replace' $core "?@microsoft.graph.conflictBehavior=replace"
Assert-Contains 'Prepare only root path' $core "'created_source_children' => 0"
Assert-Contains 'Schema is verified before prepare' $core 'migration_schema_verify_failed'
Assert-Matches 'Schema verification precedes folder creation' $core '(?s)\$verified_columns = \$this->columns\(\$target\).*?\$parent = \(string\) \$target\[''folder_id''\]'
Assert-Contains 'Missing columns have manual details' $archive '$choice_values = (array)'
Assert-Contains 'Choice object is normalized before display' $archive '$choice_config = (array)'
Assert-Contains 'Live preview recalculates' $archive 'data-ssf-migration-preview'
Assert-Contains 'Resumable verified state' $core "'state' => 'VERIFIED'"
Assert-Contains 'Resume state is scoped to source and target' $core "`$state['context']"
Assert-Contains 'Generic test case action' $archive 'ssf_folder_migration_test_case'
Assert-Contains 'Generic existing destination confirmation action' $archive 'ssf_folder_migration_confirm_existing'
Assert-Contains 'Existing destination path is shown' $archive 'Det finns redan en mapp med samma namn i'
Assert-Contains 'Overwrite policy is explicit' $archive "existing_target_policy'] = 'replace_files'"
Assert-Contains 'Changing destination clears confirmation' $archive "unset(`$state['target']['use_existing_target'], `$state['target']['existing_target_policy'], `$state['target']['confirmed_existing_target_id'])"
Assert-Contains 'Test case reuses core' $archive '$core->migrate($test_source'
Assert-Contains 'Copy uses Graph source item' $core "'/copy'"
Assert-Contains 'Copy checks existing files as files' $core '$this->find_child_file((string) $target[''drive_id''], $parent_id, $name)'
Assert-Contains 'Copy waits for asynchronous completion' $core '$this->graph->copy_status($monitor_url)'
Assert-Contains 'Expired monitor can recover an already copied file' $core "`$this->find_child_file((string) `$target['drive_id'], `$parent_id, `$name)"
Assert-Contains 'Expired monitor recovery checks file size' $core "(int) (`$found['size'] ?? -1) === (int) (`$source_file['size'] ?? -2)"
Assert-Contains 'Copy requires completed status' $core "'completed' === (string) (`$status['status'] ?? '')"
Assert-Contains 'Copy requires returned target file ID' $core "`$status['resourceId']"
Assert-Contains 'In-flight copy is saved for resume' $core "'monitor_url' => `$monitor_url"
Assert-Contains 'Completed copy ID is saved before metadata write' $core "'state' => 'COPIED', 'target_id' => `$target_id"
Assert-Contains 'Test case verified items transfer to full run' $core 'Carry its'
Assert-Contains 'Old test folder resumes only with matching context' $core 'is_resumable_target($source, $target'
Assert-Contains 'Folders do not compare aggregate size before copying children' $core "('file' === `$type && (! isset(`$target_item['file']) || (int) (`$source_item['size']"
Assert-Contains 'Folder and file metadata is written and read back' $core '$this->metadata_and_verify($target, $folder, $target_id)'
Assert-Contains 'File metadata is written and read back' $core '$this->metadata_and_verify($target, $file, $target_id)'
Assert-Contains 'Copy monitor never sends Graph bearer token' $graph 'wp_remote_get($url, array('
Assert-Contains 'Metadata readback verification' $core 'migration_metadata_mismatch'
Assert-Contains 'Write test guards error before metadata array access' $core 'if ($match) {'
Assert-Contains 'Write test exposes Graph error message' $core '$metadata_error->get_error_message()'
Assert-Contains 'Write test renders failed step details' $archive "empty(`$step['ok']) && ! empty(`$step['message'])"
Assert-Contains 'Read-only SharePoint fields are filtered dynamically' $core "! empty(`$column['readOnly'])"
Assert-Contains 'Abandoned write-test artifacts are narrowly matched' $core 'ssf-migration-test-(\d{8}-\d{6})-[A-Za-z0-9]{4}'
Assert-Contains 'Abandoned write-test artifacts have an age guard' $core '5 * MINUTE_IN_SECONDS'
Assert-Contains 'Reconciliation source safety' $core "'source_untouched' => true"
Assert-NotContains 'Generic core must not delete source' $core "'DELETE', 'drives/' . rawurlencode((string) `$source['drive_id'])"
Assert-Contains 'Shared discovery pagination' $discovery 'sharepoint_pagination_loop'
Assert-Contains 'Drive discovery exposes the stable root ID' $discovery "`$drive['root_id']"
Assert-Contains 'Graph accepts nextLink' $graph 'https://graph.microsoft.com'
Assert-Contains 'Async copy response support' $graph 'request_response'
Assert-Contains 'Membership adapter retained' $archive '_ssf_sp_migration_old_refs'

if ($failures.Count) {
    $failures | ForEach-Object { Write-Error $_ }
    exit 1
}

Write-Host 'PASS: generic SharePoint folder migration safety and integration contracts.'
