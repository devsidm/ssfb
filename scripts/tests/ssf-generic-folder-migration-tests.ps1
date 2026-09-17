[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$failures = [Collections.Generic.List[string]]::new()

function Read-RepoFile([string]$Path) { Get-Content -Raw -LiteralPath (Join-Path $repo $Path) -Encoding UTF8 }
function Assert-Contains([string]$Name, [string]$Content, [string]$Expected) { if (-not $Content.Contains($Expected)) { $failures.Add("$Name saknar: $Expected") } }
function Assert-NotContains([string]$Name, [string]$Content, [string]$Expected) { if ($Content.Contains($Expected)) { $failures.Add("$Name innehåller otillåtet: $Expected") } }

$core = Read-RepoFile 'wp-content\plugins\ssf-member-portal\includes\Integrations\Microsoft365\FolderMigrationCore.php'
$archive = Read-RepoFile 'wp-content\plugins\ssf-medlemsprocess\includes\class-ssf-medlemsprocess-archive-migration.php'
$discovery = Read-RepoFile 'wp-content\plugins\ssf-member-portal\includes\Integrations\Microsoft365\SharePointDiscovery.php'
$graph = Read-RepoFile 'wp-content\plugins\ssf-member-portal\includes\Integrations\Microsoft365\GraphClient.php'

Assert-Contains 'Shared discovery migration profile' $archive 'data-destination="folder_migration"'
Assert-Contains 'Generic core is separate' $archive 'FolderMigrationCore'
Assert-Contains 'Generic source stable IDs' $archive "array('site_id','drive_id','list_id','folder_id')"
Assert-Contains 'Keep or rename' $archive 'Flytta och byt namn'
Assert-Contains 'Intermediate structure' $archive 'Extra struktur'
Assert-Contains 'Live preview' $archive 'data-ssf-migration-preview'
Assert-Contains 'Root-only prepare wording' $archive 'Skapade migreringsundermapppar: 0'
Assert-Contains 'Incremental migration' $core 'Incremental, resumable source-to-source SharePoint copy'
Assert-Contains 'No arbitrary depth cutoff' $core 'while ($queue)'
Assert-Contains 'Graph pagination' $core "'@odata.nextLink'"
Assert-Contains 'Pagination loop protection' $core 'migration_pagination_loop'
Assert-Contains 'Populated metadata inventory' $core 'populated_fields'
Assert-Contains 'Dry run has zero writes' $core "'writes' => 0"
Assert-Contains 'Prepare only root path' $core "'created_source_children' => 0"
Assert-Contains 'Schema is reread after prepare' $core 'migration_schema_verify_failed'
Assert-Contains 'Live preview recalculates' $archive 'data-ssf-migration-preview'
Assert-Contains 'Resumable verified state' $core "'state' => 'VERIFIED'"
Assert-Contains 'Generic test case action' $archive 'ssf_folder_migration_test_case'
Assert-Contains 'Test case reuses core' $archive '$core->migrate($test_source'
Assert-Contains 'Copy uses Graph source item' $core "'/copy'"
Assert-Contains 'Metadata readback verification' $core 'migration_metadata_mismatch'
Assert-Contains 'Reconciliation source safety' $core "'source_untouched' => true"
Assert-NotContains 'Generic core must not delete source' $core "'DELETE', 'drives/' . rawurlencode((string) `$source['drive_id'])"
Assert-Contains 'Shared discovery pagination' $discovery 'sharepoint_pagination_loop'
Assert-Contains 'Graph accepts nextLink' $graph 'https://graph.microsoft.com'
Assert-Contains 'Async copy response support' $graph 'request_response'
Assert-Contains 'Membership adapter retained' $archive '_ssf_sp_migration_old_refs'

if ($failures.Count) {
    $failures | ForEach-Object { Write-Error $_ }
    exit 1
}

Write-Host 'PASS: generic SharePoint folder migration safety and integration contracts.'
