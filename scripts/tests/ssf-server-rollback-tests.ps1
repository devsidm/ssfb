[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$scriptPath = Join-Path $repo 'scripts\deploy\ssf-server-rollback.sh'
$deployPath = Join-Path $repo 'scripts\deploy\ssf-server-deploy.sh'
$docPath = Join-Path $repo 'docs\SERVER-DEPLOYMENT.md'
$failures = [Collections.Generic.List[string]]::new()

function Fail([string]$Message) { $failures.Add($Message) | Out-Null }
function Read-RepoFile([string]$Path) { Get-Content -Raw -Encoding UTF8 -LiteralPath (Join-Path $repo $Path) }
function Assert-True([string]$Name, [bool]$Condition) { if (-not $Condition) { Fail $Name } }
function Assert-Contains([string]$Name, [string]$Content, [string]$Expected) { if (-not $Content.Contains($Expected)) { Fail "$Name missing: $Expected" } }
function Assert-NotContains([string]$Name, [string]$Content, [string]$Unexpected) { if ($Content.Contains($Unexpected)) { Fail "$Name contains forbidden text: $Unexpected" } }

$script = Read-RepoFile 'scripts\deploy\ssf-server-rollback.sh'
$deploy = Read-RepoFile 'scripts\deploy\ssf-server-deploy.sh'
$doc = Read-RepoFile 'docs\SERVER-DEPLOYMENT.md'
$mainOrder = [regex]::Match($script, '(?s)main\(\).*?\{(?<body>.*?)\n\}', 'Singleline').Groups['body'].Value

Assert-True 'rollback script exists' (Test-Path -LiteralPath $scriptPath)
Assert-Contains 'rollback shebang' $script '#!/usr/bin/env bash'
Assert-Contains 'rollback strict mode' $script 'set -Eeuo pipefail'
Assert-Contains 'rollback maintenance numeric timestamp' $script 'printf ''<?php $upgrading = %s; ?>\n'' "$timestamp" > "$PROD/.maintenance"'
Assert-Contains 'rollback maintenance timestamp numeric check' $script '[[ "$timestamp" =~ ^[0-9]+$ ]]'
Assert-NotContains 'rollback maintenance marker must not call time' $script '$upgrading = time()'
Assert-Contains 'rollback maintenance deactivate verifies inactive' $script 'verify_maintenance_inactive'
Assert-Contains '--list exists' $script '--list'
Assert-Contains 'rollback list shows deployment target' $script 'Deployment target:'
Assert-Contains 'rollback list shows previous build' $script 'Previous build:'
Assert-Contains 'rollback list shows created' $script 'Created:'
Assert-Contains 'rollback list shows files' $script 'Files:'
Assert-Contains 'rollback list shows database' $script 'Database:'
Assert-Contains 'rollback list shows deployment result' $script 'Deployment result:'
Assert-Contains 'missing list values display UNKNOWN' $script 'json_value_or_unknown'
Assert-Contains 'UNKNOWN fallback emitted' $script 'echo "UNKNOWN"'
Assert-Contains 'rollback list uses explicit result field' $script 'deployment_state.result'
Assert-Contains '--backup exists' $script '--backup'
Assert-Contains '--with-db exists' $script '--with-db'
Assert-Contains 'exact PROD path required' $script 'Exact PROD path required'
Assert-Contains 'DEV cannot be targeted' $script 'DEV cannot be targeted'
Assert-Contains 'invalid manifest rejected' $script 'Invalid BACKUP-INFO.json'
Assert-Contains 'wrong site rejected' $script 'Wrong site rejected'
Assert-Contains 'wrong DB prefix rejected' $script 'Wrong DB prefix rejected'
Assert-Contains 'checksum mismatch rejected' $script 'checksum mismatch'
Assert-Contains 'corrupt tar rejected' $script 'tar -tzf "$file_archive" >/dev/null'
Assert-Contains 'corrupt gzip rejected' $script 'gzip -t "$db_archive"'
Assert-Contains 'old valid backup remains eligible through verified DB/file metadata' $script '($data["database_backup"]["verified"] ?? false) && ($data["file_backup"]["verified"] ?? false)'
Assert-NotContains 'old backups not rejected for missing previous build' $script 'pre_deploy_build"] ?? false'
Assert-NotContains 'old backups not rejected for missing deployment result' $script 'deployment_state"]["result"] ?? false'
Assert-Contains 'database checksum remains authoritative' $script 'Database checksum mismatch rejected.'
Assert-Contains 'file checksum remains authoritative' $script 'File archive checksum mismatch rejected.'
Assert-True 'file rollback never imports DB unless with-db' ($mainOrder.IndexOf('restore_database') -gt $mainOrder.IndexOf('if [[ "$WITH_DB" == "1" ]]'))
Assert-Contains '--with-db imports DB' $script 'wp_prod db import "$temp_sql"'
Assert-Contains 'normal confirmation exactly ROLLBACK' $script 'expected="ROLLBACK"'
Assert-Contains 'DB confirmation exactly ROLLBACK WITH DATABASE' $script 'expected="ROLLBACK WITH DATABASE"'
Assert-True 'maintenance active before rescue backup' ($mainOrder.IndexOf('activate_maintenance "rollback"') -lt $mainOrder.IndexOf('create_rescue_backup'))
Assert-True 'grace period before rescue backup' ($mainOrder.IndexOf('maintenance_grace_period') -lt $mainOrder.IndexOf('create_rescue_backup'))
Assert-True 'rescue DB backup before DB import' ($script.IndexOf('wp_prod db export "$RESCUE_BACKUP/database.sql"') -lt $script.IndexOf('wp_prod db import "$temp_sql"'))
Assert-True 'rescue files before file restore' ($mainOrder.IndexOf('create_rescue_backup') -lt $mainOrder.IndexOf('restore_files'))
Assert-Contains 'rescue archive checksummed' $script 'sha256_file "$archive" > "$archive.sha256"'
Assert-Contains 'rescue DB checksummed' $script 'sha256_file "$RESCUE_BACKUP/database.sql.gz"'
Assert-Contains 'database import uses PROD WP-CLI' $script 'wp_prod db import "$temp_sql"'
Assert-Contains 'wp db check after import' $script 'wp_prod db check'
Assert-Contains 'home verified' $script 'wp_prod option get home'
Assert-Contains 'siteurl verified' $script 'wp_prod option get siteurl'
Assert-NotContains 'DEV DB never used' $script 'wp_dev'
Assert-NotContains 'uploads never restored' $script 'uploads'
Assert-NotContains 'wp-config never restored from archive' $script 'tar -xzf "$archive" -C "$PROD" wp-config.php'
Assert-NotContains 'WordPress core never restored' $script 'wordpress core'
Assert-Contains 'only explicitly new paths may be removed' $script 'exists_before=false'
Assert-Contains 'unrelated PROD plugins untouched' $script 'plugins-before.json'
Assert-Contains 'public HTTP uses curl' $script 'curl -sS'
Assert-NotContains 'no Invoke-WebRequest' $script 'Invoke-WebRequest'
Assert-NotContains 'no Invoke-RestMethod' $script 'Invoke-RestMethod'
Assert-NotContains 'no rsync delete' $script '--delete'
Assert-Contains 'failed rollback after mutation leaves maintenance active' $script 'ROLLBACK_MUTATED" == "1"'
Assert-Contains 'successful rollback opens site' $script 'deactivate_maintenance'
Assert-Contains 'public smoke failure reactivates maintenance' $script 'rollback public smoke failure'
Assert-Contains 'SharePoint never modified' $script 'External SharePoint data: NOT ROLLED BACK'
Assert-Contains 'rollback Turnstile direct option key' $script 'get_option("cfturnstile_key", "")'
Assert-Contains 'rollback Turnstile direct option secret' $script 'get_option("cfturnstile_secret", "")'
Assert-Contains 'rollback Turnstile validates after DB rollback' $script 'validate_turnstile_prod_config'
Assert-Contains 'deploy writes JSON backup manifest' $deploy 'BACKUP-INFO.json'
Assert-Contains 'docs rollback list' $doc 'ssf-rollback --list'
Assert-Contains 'docs rollback with db' $doc 'ssf-rollback --with-db'

if ($failures.Count) {
    $failures | ForEach-Object { Write-Error $_ }
    exit 1
}

Write-Host 'PASS: server rollback workflow static safety tests.'
