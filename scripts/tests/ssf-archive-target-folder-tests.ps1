[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$failures = [Collections.Generic.List[string]]::new()

function Read-RepoFile([string]$Path) { Get-Content -Raw -LiteralPath (Join-Path $repo $Path) -Encoding UTF8 }
function Assert-Contains([string]$Name, [string]$Content, [string]$Expected) { if (-not $Content.Contains($Expected)) { $failures.Add("$Name saknar: $Expected") } }
function Assert-NotContains([string]$Name, [string]$Content, [string]$Unexpected) { if ($Content.Contains($Unexpected)) { $failures.Add("$Name innehåller otillåtet: $Unexpected") } }
function Assert-True([string]$Name, [bool]$Condition) { if (-not $Condition) { $failures.Add($Name) } }

$archive = Read-RepoFile 'wp-content\plugins\ssf-medlemsprocess\includes\class-ssf-medlemsprocess-archive-migration.php'
$css = Read-RepoFile 'wp-content\plugins\ssf-medlemsprocess\assets\css\ssf-medlemsprocess-admin.css'

Assert-Contains 'Steg 3 renderer' $archive 'render_target_location_step'
Assert-Contains 'Steg 3 heading class' $archive 'ssf-archive-target-step'
Assert-Contains 'Destination parent state' $archive 'destination_parent_selected'
Assert-Contains 'Final missing state' $archive 'final_destination_missing'
Assert-Contains 'Final exists state' $archive 'final_destination_exists'
Assert-Contains 'Final created state' $archive 'final_destination_created'
Assert-Contains 'Final verified state' $archive 'final_destination_verified'
Assert-Contains 'Source basename helper' $archive 'source_folder_name'
Assert-Contains 'Parent plus source name builds final path' $archive '$target[''folder_path''] = $this->join_drive_path((string) ($target[''parent_folder_path''] ?? ''''), (string) $target[''destination_folder_name'']);'
Assert-Contains 'Default keeps source name' $archive "'keep_source_folder_name' => '1'"
Assert-Contains 'Custom rename field' $archive 'destination_folder_name'
Assert-Contains 'Invalid SharePoint folder names rejected' $archive 'validate_sharepoint_folder_name'
Assert-Contains 'Parent selection does not persist final folder id' $archive "$target['folder_id'] = '';"
Assert-Contains 'Missing final can be created' $archive 'ssf_application_archive_create_target_folder'
Assert-Contains 'Created folder is read back' $archive 'verify_folder_item'
Assert-Contains 'Existing folder detected' $archive 'find_final_target'
Assert-Contains 'Existing folder not silently accepted' $archive 'migration_target_folder_exists'
Assert-Contains 'Use existing requires verification' $archive 'ssf_application_archive_use_existing_target'
Assert-Contains 'Stable parent folder id stored' $archive 'parent_folder_id'
Assert-Contains 'Stable final folder id stored' $archive 'folder_id'
Assert-Contains 'Paths remain display data' $archive 'display_drive_path'
Assert-Contains 'Navigator lists children' $archive 'target_browser_children'
Assert-Contains 'Navigator create child folder' $archive 'ssf_application_archive_create_browser_folder'
Assert-Contains 'Create new folder conflict fail' $archive '@microsoft.graph.conflictBehavior'
Assert-Contains 'Preview section' $archive 'ssf-archive-preview'
Assert-Contains 'Helpful parent error code' $archive 'migration_parent_folder_missing'
Assert-Contains 'Helpful create error code' $archive 'migration_target_create_failed'
Assert-Contains 'Helpful verify error code' $archive 'migration_target_verify_failed'
Assert-Contains 'Schema reader keeps sourceColumn expansion' $archive '$expand=sourceColumn'
Assert-Contains 'Schema reader keeps multiChoice normalization' $archive "'multiChoice'"
Assert-Contains 'Migration still uses copy/upload before switch' $archive 'upload_wordpress_files'
Assert-Contains 'Migration preserves old references' $archive '_ssf_sp_migration_old_refs'
Assert-Contains 'No source delete helper added' $archive '_ssf_sp_migration_old_refs'
Assert-NotContains 'No generic saved success' $archive 'Målkatalogen har sparats.'
Assert-Contains 'Target browser styling' $css '.ssf-archive-folder-list'

if ($failures.Count) {
    $failures | ForEach-Object { Write-Error $_ }
    exit 1
}

Write-Host 'PASS: archive migration target parent, final folder verification, navigator and safety contracts.'
