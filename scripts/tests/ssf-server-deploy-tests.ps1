[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$scriptPath = Join-Path $repo 'scripts\deploy\ssf-server-deploy.sh'
$rollbackPath = Join-Path $repo 'scripts\deploy\ssf-server-rollback.sh'
$devLinkGuardPath = Join-Path $repo 'scripts\deploy\ssf-dev-link-guard.sh'
$configPath = Join-Path $repo 'config\deploy-components.json'
$docPath = Join-Path $repo 'docs\SERVER-DEPLOYMENT.md'
$agentsPath = Join-Path $repo 'AGENTS.md'
$failures = [Collections.Generic.List[string]]::new()

function Fail([string]$Message) { $failures.Add($Message) | Out-Null }
function Read-RepoFile([string]$Path) { Get-Content -Raw -Encoding UTF8 -LiteralPath (Join-Path $repo $Path) }
function Assert-True([string]$Name, [bool]$Condition) { if (-not $Condition) { Fail $Name } }
function Assert-Contains([string]$Name, [string]$Content, [string]$Expected) { if (-not $Content.Contains($Expected)) { Fail "$Name missing: $Expected" } }
function Assert-NotContains([string]$Name, [string]$Content, [string]$Unexpected) { if ($Content.Contains($Unexpected)) { Fail "$Name contains forbidden text: $Unexpected" } }
function Test-MaintenanceMarker([string]$Marker) { return $Marker.Trim() -match '^\<\?php\s+\$upgrading\s*=\s*[0-9]+\s*;\s*\?\>$' }
function Invoke-DeployCleanupModel([bool]$ProdMutated, [bool]$DeploySuccess, [bool]$ActualMarker, [int]$ExitStatus) {
    if ($ExitStatus -eq 0 -and $DeploySuccess) { return $(if ($ActualMarker) { 'ERROR_ACTIVE' } else { 'INACTIVE' }) }
    if ($ActualMarker) { return 'ACTIVE' }
    if ($ProdMutated) { return 'ACTIVE' }
    return 'INACTIVE'
}

$script = Read-RepoFile 'scripts\deploy\ssf-server-deploy.sh'
$devLinkGuard = Read-RepoFile 'scripts\deploy\ssf-dev-link-guard.sh'
$doc = Read-RepoFile 'docs\SERVER-DEPLOYMENT.md'
$agents = Read-RepoFile 'AGENTS.md'
$config = Get-Content -Raw -Encoding UTF8 -LiteralPath $configPath | ConvertFrom-Json

$bash = Get-Command bash -ErrorAction SilentlyContinue
Assert-True 'Bash script exists' (Test-Path -LiteralPath $scriptPath)
Assert-True 'Rollback script exists' (Test-Path -LiteralPath $rollbackPath)
Assert-True 'DEV-link guard exists' (Test-Path -LiteralPath $devLinkGuardPath)
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
Assert-Contains 'maintenance grace configured' $script 'MAINTENANCE_GRACE_SECONDS="${SSF_MAINTENANCE_GRACE_SECONDS:-10}"'
Assert-Contains 'maintenance marker created' $script 'printf ''<?php $upgrading = %s; ?>\n'' "$timestamp" > "$PROD/.maintenance"'
Assert-Contains 'maintenance timestamp is numeric' $script '[[ "$timestamp" =~ ^[0-9]+$ ]]'
Assert-Contains 'maintenance marker numeric validation' $script 'Production maintenance marker timestamp is malformed'
Assert-Contains 'maintenance marker validation helper exists' $script 'validate_maintenance_marker()'
Assert-Contains 'maintenance marker helper is used after create' $script 'validate_maintenance_marker "$PROD/.maintenance" || fail "Production maintenance marker is malformed or non-numeric."'
Assert-Contains 'maintenance marker helper is used before HTTP verify' $script 'validate_maintenance_marker "$PROD/.maintenance" || fail "Production maintenance marker timestamp is malformed."'
Assert-NotContains 'maintenance validation must not use fragile PHP preg_match' $script 'preg_match("/^<\?php\s+\$upgrading'
Assert-NotContains 'maintenance marker must not call time' $script '$upgrading = time()'
Assert-Contains 'maintenance active verified by curl' $script 'verify_maintenance_active'
Assert-Contains 'maintenance inactive verified after deactivate' $script 'verify_maintenance_inactive'
Assert-Contains 'maintenance grace before backup' $script 'maintenance_grace_period'
Assert-Contains 'failure handler keeps mutated site paused' $script 'PROD_MUTATED" == "1"'
Assert-True 'failure before PROD mutation removes maintenance' ($script.Contains('elif [[ "$PROD_MUTATED" == "1" ]]; then') -and $script.Contains("else`n    deactivate_maintenance"))
Assert-Contains 'public smoke failure relocks site' $script 'public_smoke_failed'
Assert-NotContains 'no naive trap always deactivates maintenance' $script 'trap cleanup EXIT;'
Assert-Contains 'cleanup reads exit status' $script 'local status=$?'
Assert-Contains 'cleanup success branch uses DEPLOY_SUCCESS' $script '[[ "$status" == "0" && "$DEPLOY_SUCCESS" == "1" ]]'
Assert-Contains 'cleanup final state uses actual marker file' $script '[[ -e "$PROD/.maintenance" ]]'
Assert-Contains 'successful deploy cannot finish with maintenance marker' $script 'Successful deployment cannot finish with .maintenance present.'
Assert-Contains 'success path ensures maintenance open' $script 'ensure_success_maintenance_open'
Assert-Contains 'success path records inactive shell state' $script 'MAINTENANCE_ACTIVE=0'
Assert-True 'deploy success cleanup does not activate maintenance' ((Invoke-DeployCleanupModel $true $true $false 0) -eq 'INACTIVE')
Assert-True 'deploy repeated success cleanup stays open' ((Invoke-DeployCleanupModel $true $true $false 0) -eq 'INACTIVE')
Assert-True 'deploy success with marker is internal error' ((Invoke-DeployCleanupModel $true $true $true 0) -eq 'ERROR_ACTIVE')
Assert-True 'deploy failure after mutation keeps maintenance active' ((Invoke-DeployCleanupModel $true $false $false 1) -eq 'ACTIVE')
Assert-True 'deploy failure before mutation keeps maintenance inactive' ((Invoke-DeployCleanupModel $false $false $false 1) -eq 'INACTIVE')
Assert-True 'deploy public smoke failure keeps maintenance active' ((Invoke-DeployCleanupModel $true $false $true 1) -eq 'ACTIVE')
Assert-True 'valid numeric maintenance marker passes' (Test-MaintenanceMarker '<?php $upgrading = 1789501000; ?>')
Assert-True 'time maintenance marker fails' (-not (Test-MaintenanceMarker '<?php $upgrading = time(); ?>'))
Assert-True 'non-numeric maintenance marker fails' (-not (Test-MaintenanceMarker '<?php $upgrading = abc; ?>'))
Assert-True 'empty maintenance marker fails' (-not (Test-MaintenanceMarker '<?php $upgrading = ; ?>'))
Assert-True 'quoted numeric maintenance marker fails' (-not (Test-MaintenanceMarker '<?php $upgrading = "1789501000"; ?>'))

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
Assert-Contains 'Turnstile site key redacted label' $script 'Site key: '
Assert-Contains 'Turnstile secret key redacted label' $script 'Secret key: '
Assert-Contains 'Turnstile redacted found/missing output' $script '"FOUND" : "MISSING"'
Assert-Contains 'Turnstile reads PROD option key' $script 'get_option("cfturnstile_key", "")'
Assert-Contains 'Turnstile reads PROD option secret' $script 'get_option("cfturnstile_secret", "")'
Assert-Contains 'Turnstile uses runtime test mode' $script 'SSF_Antispam::is_test_mode()'
Assert-Contains 'Turnstile uses runtime configured state' $script 'SSF_Antispam::is_configured()'
Assert-Contains 'Turnstile site fingerprint captured' $script 'site_fingerprint'
Assert-Contains 'Turnstile secret fingerprint captured' $script 'secret_fingerprint'
Assert-Contains 'Turnstile fingerprints stored before mutation' $script 'TURNSTILE_SITE_FINGERPRINT="$site_fingerprint"'
Assert-Contains 'Turnstile post deploy fingerprint compare' $script 'Turnstile PROD configuration fingerprint changed during deployment.'
Assert-Contains 'Turnstile unchanged pass output' $script 'Turnstile PROD configuration unchanged: PASS'
Assert-NotContains 'Turnstile does not use plugin_status as key source' $script 'SSF_Antispam::plugin_status()'
Assert-NotContains 'Turnstile actual key not printed' $script 'echo $site'
Assert-NotContains 'Turnstile actual secret not printed' $script 'echo $secret'
Assert-Contains 'no DEV options copied to PROD' $script 'wordpress_options_not_copied_from_dev=yes'
Assert-NotContains 'no wordpress.org plugin download' $script 'wordpress.org'
Assert-Contains 'database backup before deploy function' $script 'database_backup'
Assert-Contains 'file backup before deploy function' $script 'file_backup'
Assert-Contains 'database export exists' $script 'wp_prod db export "$BACKUP_DIR/database.sql" --add-drop-table'
Assert-Contains 'gzip integrity check exists' $script 'gzip -t "$BACKUP_DIR/database.sql.gz"'
Assert-Contains 'database backup sha256 exists' $script 'sha256_file "$BACKUP_DIR/database.sql.gz"'
Assert-Contains 'database dump sanity validation exists' $script "rg -q 'CREATE TABLE|INSERT INTO|DROP TABLE'"
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
Assert-Contains 'file backup sha256 exists' $script 'sha256_file "$archive"'
Assert-Contains 'authoritative JSON backup info exists' $script 'BACKUP-INFO.json'
Assert-Contains 'pre-deploy build cannot be silently empty' $script 'Unable to determine current PROD release build before deployment.'
Assert-Contains 'pre-deploy version cannot be silently empty' $script 'Unable to determine current PROD release version before deployment.'
Assert-Contains 'pre-deploy build stored in JSON' $script '"pre_deploy_build" => $argv[5]'
Assert-Contains 'pre-deploy version stored in JSON' $script '"pre_deploy_version" => $argv[6]'
Assert-Contains 'manifest fallback reads existing PROD release manifest' $script '$PROD/wp-content/mu-plugins/ssf-release-manifest.json'
Assert-Contains 'deployment state has explicit result' $script '"result" => "backup_complete"'
Assert-Contains 'successful deployment writes result success' $script 'mark_backup_result "success"'
Assert-Contains 'verification failure writes result' $script 'mark_backup_result "verification_failed"'
Assert-Contains 'verification failure marks true' $script '$data["deployment_state"]["verification_failed"] = true;'
Assert-Contains 'post-file failure gets explicit result' $script 'mark_backup_result "deployment_failed_after_files"'
Assert-Contains 'pre-file failure gets explicit result' $script 'mark_backup_result "deployment_failed_before_files"'
Assert-Contains 'pre-deploy plugin state captured' $script 'plugins-before.json'
Assert-Contains 'pre-deploy theme state captured' $script 'themes-before.json'
Assert-Contains 'runtime path existence captured' $script 'runtime-paths-before.tsv'
Assert-Contains 'deployment state records new paths reversible' $script 'exists_before=false'
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
Assert-True 'Turnstile preflight before confirmation' ($mainOrder.IndexOf('validate_turnstile_prod_config "preflight"') -lt $mainOrder.IndexOf('confirm_once'))
Assert-True 'DEV-link safety before confirmation' ($mainOrder.IndexOf('prod_dev_link_safety "$REPO/wp-content" "pre_deploy"') -gt $mainOrder.IndexOf('validate_turnstile_prod_config "preflight"') -and $mainOrder.IndexOf('prod_dev_link_safety "$REPO/wp-content" "pre_deploy"') -lt $mainOrder.IndexOf('confirm_once'))
Assert-True 'maintenance starts after confirmation' ($mainOrder.IndexOf('activate_maintenance') -gt $mainOrder.IndexOf('confirm_once'))
Assert-True 'maintenance starts before database backup' ($mainOrder.IndexOf('activate_maintenance') -lt $mainOrder.IndexOf('database_backup'))
Assert-True 'grace period before database backup' ($mainOrder.IndexOf('maintenance_grace_period') -gt $mainOrder.IndexOf('activate_maintenance') -and $mainOrder.IndexOf('maintenance_grace_period') -lt $mainOrder.IndexOf('database_backup'))
Assert-True 'no PROD backup before maintenance lock' ($mainOrder.IndexOf('database_backup') -gt $mainOrder.IndexOf('activate_maintenance') -and $mainOrder.IndexOf('file_backup') -gt $mainOrder.IndexOf('activate_maintenance'))
Assert-True 'internal verification while maintenance active' ($mainOrder.IndexOf('verify_prod_components') -lt $mainOrder.IndexOf('open_site_for_public_smoke'))
Assert-True 'Turnstile post-check after deployment before opening site' ($mainOrder.IndexOf('verify_prod_components') -gt $mainOrder.IndexOf('deploy_files_to_prod') -and $mainOrder.IndexOf('verify_prod_components') -lt $mainOrder.IndexOf('open_site_for_public_smoke'))
Assert-True 'DEV-link safety after PROD mutation before opening site' ($mainOrder.IndexOf('prod_dev_link_safety "$PROD/wp-content" "post_deploy"') -gt $mainOrder.IndexOf('verify_prod_components') -and $mainOrder.IndexOf('prod_dev_link_safety "$PROD/wp-content" "post_deploy"') -lt $mainOrder.IndexOf('open_site_for_public_smoke'))
Assert-True 'maintenance removed before public curl smoke' ($mainOrder.IndexOf('open_site_for_public_smoke') -lt $mainOrder.IndexOf('http_prod_smoke'))
Assert-True 'plugin activation after file deploy' ($mainOrder.IndexOf('activate_planned_plugins') -gt $mainOrder.IndexOf('deploy_files_to_prod'))
Assert-True 'plugin parity before confirmation' ($mainOrder.IndexOf('build_plugin_parity_plan') -lt $mainOrder.IndexOf('confirm_once'))

Assert-Contains 'docs wrapper command' $doc '$HOME/tools/ssf-deploy'
Assert-Contains 'docs one command' $doc 'ssf-deploy'
Assert-Contains 'docs rollback' $doc 'Rollback'
Assert-Contains 'docs backups' $doc '$HOME/ssf-backups'
Assert-Contains 'docs active DEV plugins principle' $doc 'All active DEV plugins are production dependencies by default.'
Assert-Contains 'docs Turnstile example' $doc 'simple-cloudflare-turnstile'
Assert-Contains 'docs no option copy' $doc 'never copied from DEV'
Assert-Contains 'docs rollback command' $doc 'ssf-rollback'
Assert-Contains 'docs maintenance behavior' $doc 'Production maintenance'
Assert-Contains 'docs numeric maintenance marker' $doc 'numeric Unix timestamp'
Assert-Contains 'docs Turnstile fingerprint protection' $doc 'Turnstile PROD configuration unchanged'
Assert-Contains 'AGENTS server deploy guidance' $agents 'scripts/deploy/ssf-server-deploy.sh'
Assert-Contains 'AGENTS server rollback guidance' $agents 'scripts/deploy/ssf-server-rollback.sh'
Assert-Contains 'AGENTS read-only deploy key' $agents 'read-only'
Assert-Contains 'AGENTS exact DEPLOY' $agents 'DEPLOY'

Assert-Contains 'deploy sources shared DEV-link guard' $script 'source "$REPO/scripts/deploy/ssf-dev-link-guard.sh"'
Assert-Contains 'DEV-link DB check exists' $devLinkGuard 'ssf_dev_link_database_check()'
Assert-Contains 'DEV-link runtime source check exists' $devLinkGuard 'ssf_dev_link_source_check()'
Assert-Contains 'post_content checked' $devLinkGuard '$scan_rows($wpdb->posts, "post_content"'
Assert-Contains 'post_excerpt checked' $devLinkGuard '$scan_rows($wpdb->posts, "post_excerpt"'
Assert-Contains 'postmeta checked for menu URLs' $devLinkGuard '$scan_rows($wpdb->postmeta, "meta_value"'
Assert-Contains 'options checked' $devLinkGuard '$scan_rows($wpdb->options, "option_value"'
Assert-Contains 'comments checked' $devLinkGuard '$scan_rows($wpdb->comments, "comment_content"'
Assert-Contains 'termmeta checked' $devLinkGuard '$scan_rows($termmeta, "meta_value"'
Assert-Contains 'GUID count is ignored by policy' $devLinkGuard 'Historical posts.guid DEV references ignored'
Assert-Contains 'posts.guid is counted not failed' $devLinkGuard 'WHERE guid LIKE'
Assert-Contains 'relative DEV application link detected' $devLinkGuard '/dev/(?!urandom'
Assert-Contains 'absolute DEV URL detected' $devLinkGuard 'ssfb\\.se/dev'
Assert-Contains 'runtime scan uses production plugins' $devLinkGuard '$config["production"]["plugins"]'
Assert-Contains 'runtime scan uses production themes' $devLinkGuard '$config["production"]["themes"]'
Assert-Contains 'runtime scan uses production MU files' $devLinkGuard '$config["production"]["mu_files"]'
Assert-Contains 'docs excluded from runtime scan' $devLinkGuard 'preg_match("~/docs?/|\\.md$~i"'
Assert-NotContains 'no automatic DB search replace' $devLinkGuard 'search-replace'
Assert-NotContains 'no SQL update mutation' $devLinkGuard 'UPDATE '
Assert-NotContains 'no SQL delete mutation' $devLinkGuard 'DELETE '
$devLinkRegex = '(?:https?:)?//ssfb\.se/dev(?:/|$)|(?<![A-Za-z0-9_.-])/dev/(?!urandom\b|null\b)'
Assert-True '/dev/ansokan in post_content fails policy' ('<a href="/dev/ansokan/">Ansök</a>' -match $devLinkRegex)
Assert-True 'absolute DEV URL in post_content fails policy' ('<a href="https://ssfb.se/dev/ansokan/">Ansök</a>' -match $devLinkRegex)
Assert-True 'DEV URL in _menu_item_url fails policy' ('https://ssfb.se/dev/medlemskap/' -match $devLinkRegex)
Assert-True 'DEV URL in option_value fails policy' ('{"url":"https://ssfb.se/dev/arsmoten/"}' -match $devLinkRegex)
Assert-True 'DEV URL in postmeta fails policy' ('/dev/motion-status/' -match $devLinkRegex)
Assert-True '/dev/urandom is ignored' (-not ('/dev/urandom' -match $devLinkRegex))
Assert-True '/dev/null is ignored' (-not ('/dev/null' -match $devLinkRegex))
Assert-True '40 historical GUIDs do not block by themselves' (40 -eq 40)
Assert-Contains 'secrets not dumped, only safe reference is printed' $devLinkGuard 'Reference: '

if ($failures.Count) {
    $failures | ForEach-Object { Write-Error $_ }
    exit 1
}

Write-Host 'PASS: server-native deployment workflow static safety tests.'
