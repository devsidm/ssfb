[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$scriptPath = Join-Path $repo 'scripts\deploy\ssf-server-deploy.sh'
$configPath = Join-Path $repo 'config\deploy-components.json'
$docPath = Join-Path $repo 'docs\SERVER-DEPLOYMENT.md'
$agentsPath = Join-Path $repo 'AGENTS.md'
$failures = [Collections.Generic.List[string]]::new()

function Fail([string]$Message) { $failures.Add($Message) | Out-Null }
function Read-RepoFile([string]$Path) { Get-Content -Raw -Encoding UTF8 -LiteralPath (Join-Path $repo $Path) }
function Assert-True([string]$Name, [bool]$Condition) { if (-not $Condition) { Fail $Name } }
function Assert-Contains([string]$Name, [string]$Content, [string]$Expected) { if (-not $Content.Contains($Expected)) { Fail "$Name missing: $Expected" } }
function Assert-NotContains([string]$Name, [string]$Content, [string]$Unexpected) { if ($Content.Contains($Unexpected)) { Fail "$Name contains forbidden text: $Unexpected" } }

$script = Read-RepoFile 'scripts\deploy\ssf-server-deploy.sh'
$doc = Read-RepoFile 'docs\SERVER-DEPLOYMENT.md'
$agents = Read-RepoFile 'AGENTS.md'
$config = Get-Content -Raw -Encoding UTF8 -LiteralPath $configPath | ConvertFrom-Json

$bash = Get-Command bash -ErrorAction SilentlyContinue
Assert-True 'Bash script exists' (Test-Path -LiteralPath $scriptPath)
if ($bash) {
    $wslStubWithoutDistro = $bash.Source -match '\\System32\\bash\.exe$'
    if (-not $wslStubWithoutDistro) {
        & $bash.Source -n $scriptPath 2>$null
        $bashUsable = $LASTEXITCODE -eq 0
        if (-not $bashUsable) {
            Fail 'Bash script syntax'
        }
    }
} else {
    $bashUsable = $false
}
Assert-Contains 'Bash shebang present' $script '#!/usr/bin/env bash'
Assert-Contains 'Bash strict mode present' $script 'set -Eeuo pipefail'

Assert-True 'deploy component schema version' ($config.schema_version -eq 1)
Assert-True 'seven production plugins configured' (@($config.production.plugins).Count -eq 7)
Assert-True 'one production theme configured' (@($config.production.themes).Count -eq 1)
Assert-True 'seven production MU files configured' (@($config.production.mu_files).Count -eq 7)
Assert-True 'ssf-promotions excluded' (@($config.excluded.plugins) -contains 'ssf-promotions')
Assert-True 'plugin policy dev_only exists' ($null -ne $config.plugin_policy.dev_only)
Assert-True 'plugin policy prod_only exists' ($null -ne $config.plugin_policy.prod_only)
Assert-True 'plugin policy ignore_version exists' ($null -ne $config.plugin_policy.ignore_version)
foreach ($file in @('ssf-dev-annual-meeting-registration.php','ssf-dev-login-protection.php','ssf-dev-protection.php')) {
    Assert-True "DEV-only MU excluded $file" (@($config.dev_only.mu_files) -contains $file)
}

Assert-Contains 'exact DEV path' $script '$HOME_DIR/ssfb.se/public_html/dev'
Assert-Contains 'exact PROD path' $script '$HOME_DIR/ssfb.se/public_html'
Assert-Contains 'prod target cannot contain /dev' $script '[[ "$prod_real" != *"/dev"* ]]'
Assert-Contains 'git fetch exists' $script 'git fetch origin'
Assert-Contains 'git pull ff-only required' $script 'git pull --ff-only origin main'
Assert-Contains 'fast-forward ancestry check' $script 'git merge-base --is-ancestor HEAD origin/main'
Assert-NotContains 'no reset hard' $script 'reset --hard'
Assert-NotContains 'no force push/pull' $script '--force'
Assert-Contains 'dynamic tests enumerated' $script "find scripts/tests -maxdepth 1 -type f -name '*.ps1' | sort"
Assert-Contains 'tests run with pwsh' $script 'pwsh -NoProfile -File "$test"'
Assert-Contains 'PHP lint exists' $script 'php -l "$file"'
Assert-Contains 'PROD db check exists' $script 'wp_prod db check'
Assert-Contains 'all normal DEV plugins enumerated' $script 'wp_dev plugin list --format=json --fields=name,status,version,update,update_version'
Assert-Contains 'all normal PROD plugins enumerated' $script 'wp_prod plugin list --format=json --fields=name,status,version,update,update_version'
Assert-Contains 'active DEV missing PROD detected' $script 'DEV_ACTIVE_PROD_MISSING'
Assert-Contains 'active DEV inactive PROD detected' $script 'DEV_ACTIVE_PROD_INACTIVE'
Assert-Contains 'active DEV version difference detected' $script 'DEV_ACTIVE_VERSION_DIFFERS'
Assert-Contains 'dev-only plugin config exception works' $script 'DEV_ONLY_ALLOWED'
Assert-Contains 'PROD-only plugin warning exists' $script 'PROD_ONLY'
Assert-Contains 'DEV inactive PROD active warning exists' $script 'DEV_INACTIVE_PROD_ACTIVE'
Assert-Contains 'missing active DEV plugin cannot pass silently' $script 'Active DEV plugin cannot be deployed safely'
Assert-Contains 'plugin activation from plan only' $script 'plugin_plan_array "activate_plugins"'
Assert-Contains 'touched plugin backup plan exists' $script 'plugin_plan_array "touched_plugins"'
Assert-Contains 'post-deploy plugin parity check exists' $script 'post_deploy_plugin_parity'
Assert-Contains 'Turnstile config check exists' $script 'validate_turnstile_prod_config'
Assert-Contains 'Turnstile site key redacted' $script 'Site key: FOUND'
Assert-Contains 'Turnstile secret key redacted' $script 'Secret key: FOUND'
Assert-Contains 'no DEV options copied to PROD' $script 'wordpress_options_not_copied_from_dev=yes'
Assert-NotContains 'no wordpress.org plugin download' $script 'wordpress.org'
Assert-Contains 'database backup before deploy function' $script 'database_backup'
Assert-Contains 'file backup before deploy function' $script 'file_backup'
Assert-Contains 'database export exists' $script 'wp_prod db export "$BACKUP_DIR/database.sql" --add-drop-table'
Assert-Contains 'gzip integrity check exists' $script 'gzip -t "$BACKUP_DIR/database.sql.gz"'
Assert-Contains 'file archive exists' $script 'prod-wp-content-targets.tar.gz'
Assert-Contains 'tar integrity check exists' $script 'tar -tzf "$archive" >/dev/null'
Assert-Contains 'archive listing file exists' $script 'archive_list="$BACKUP_DIR/.prod-wp-content-targets.list"'
Assert-Contains 'archive listing written before membership checks' $script 'tar -tzf "$archive" > "$archive_list"'
Assert-Contains 'archive membership uses fixed string exact match' $script '$0 == expected'
Assert-Contains 'archive membership accepts child paths' $script 'index($0, expected "/") == 1'
Assert-NotContains 'archive membership avoids tar rg pipe' $script 'tar -tzf "$BACKUP_DIR/prod-wp-content-targets.tar.gz" | rg -q'
$archiveEntries = @('wp-content/mu-plugins/', 'wp-content/mu-plugins/ssf-antispam.php')
$expectedArchivePath = 'wp-content/mu-plugins'
$archivePathFound = @($archiveEntries | Where-Object { $_ -eq $expectedArchivePath -or $_.StartsWith("$expectedArchivePath/") }).Count -gt 0
Assert-True 'archive membership regression: directory entry with trailing slash satisfies path without slash' $archivePathFound
Assert-Contains 'exact DEPLOY confirmation exists' $script '[[ "$confirmation" == "DEPLOY" ]]'
Assert-True 'only one interactive read' (([regex]::Matches($script, 'read -r confirmation')).Count -eq 1)
Assert-NotContains 'no rsync delete' $script 'rsync --delete'
Assert-NotContains 'no rsync delete flag' $script '--delete'
Assert-Contains 'wp-config not touched in summary' $script 'wp-config.php'
Assert-Contains 'uploads not touched in summary' $script 'uploads'
Assert-Contains 'WordPress core not touched in summary' $script 'WordPress core'
Assert-Contains 'ssf-promotions not deployed' $script 'ssf-promotions'
Assert-Contains 'DEV MU not deployed to prod' $script 'DEV-only MU files'
Assert-Contains 'release deploy exists' $script 'ssf release deploy --expected-build="$BUILD"'
Assert-Contains 'release verify exists' $script 'ssf release verify --expected-build="$BUILD"'
Assert-Contains 'HTTP smoke base exists' $script 'https://ssfb.se$path'
Assert-Contains 'HTTP smoke path exists' $script '/ansokan/ 200'
Assert-Contains 'error log delta check exists' $script 'tail -n +"$((ERROR_LOG_LINES + 1))"'
Assert-Contains 'no Invoke-WebRequest' $script 'curl '
Assert-NotContains 'script avoids Invoke-WebRequest' $script 'Invoke-WebRequest'
Assert-NotContains 'script avoids Invoke-RestMethod' $script 'Invoke-RestMethod'

$mainOrder = [regex]::Match($script, '(?s)main\(\).*?\{(?<body>.*?)\n\}', 'Singleline').Groups['body'].Value
Assert-True 'database backup before file deploy in main' ($mainOrder.IndexOf('database_backup') -ge 0 -and $mainOrder.IndexOf('deploy_files_to_prod') -gt $mainOrder.IndexOf('database_backup'))
Assert-True 'file backup before file deploy in main' ($mainOrder.IndexOf('file_backup') -ge 0 -and $mainOrder.IndexOf('deploy_files_to_prod') -gt $mainOrder.IndexOf('file_backup'))
Assert-True 'confirmation before backup and deploy' ($mainOrder.IndexOf('confirm_once') -lt $mainOrder.IndexOf('database_backup') -and $mainOrder.IndexOf('confirm_once') -lt $mainOrder.IndexOf('deploy_files_to_prod'))
Assert-True 'plugin activation after file deploy' ($mainOrder.IndexOf('activate_planned_plugins') -gt $mainOrder.IndexOf('deploy_files_to_prod'))
Assert-True 'plugin parity before confirmation' ($mainOrder.IndexOf('build_plugin_parity_plan') -lt $mainOrder.IndexOf('confirm_once'))

Assert-Contains 'docs wrapper command' $doc '$HOME/tools/ssf-deploy'
Assert-Contains 'docs one command' $doc 'ssf-deploy'
Assert-Contains 'docs rollback' $doc 'Rollback'
Assert-Contains 'docs backups' $doc '$HOME/ssf-backups'
Assert-Contains 'docs active DEV plugins principle' $doc 'All active DEV plugins are production dependencies by default.'
Assert-Contains 'docs Turnstile example' $doc 'simple-cloudflare-turnstile'
Assert-Contains 'docs no option copy' $doc 'never copied from DEV'
Assert-Contains 'AGENTS server deploy guidance' $agents 'scripts/deploy/ssf-server-deploy.sh'
Assert-Contains 'AGENTS read-only deploy key' $agents 'read-only'
Assert-Contains 'AGENTS exact DEPLOY' $agents 'DEPLOY'

if ($failures.Count) {
    $failures | ForEach-Object { Write-Error $_ }
    exit 1
}

Write-Host 'PASS: server-native deployment workflow static safety tests.'
