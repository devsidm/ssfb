[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$scriptPath = Join-Path $repo 'scripts\deploy\ssf-server-deploy.sh'
$rollbackPath = Join-Path $repo 'scripts\deploy\ssf-server-rollback.sh'
$devLinkGuardPath = Join-Path $repo 'scripts\deploy\ssf-dev-link-guard.sh'
$sharePointGuardPath = Join-Path $repo 'scripts\deploy\ssf-sharepoint-config-guard.sh'
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
$sharePointGuard = Read-RepoFile 'scripts\deploy\ssf-sharepoint-config-guard.sh'
$doc = Read-RepoFile 'docs\SERVER-DEPLOYMENT.md'
$agents = Read-RepoFile 'AGENTS.md'
$config = Get-Content -Raw -Encoding UTF8 -LiteralPath $configPath | ConvertFrom-Json

$bash = Get-Command bash -ErrorAction SilentlyContinue
Assert-True 'Bash script exists' (Test-Path -LiteralPath $scriptPath)
Assert-True 'Rollback script exists' (Test-Path -LiteralPath $rollbackPath)
Assert-True 'DEV-link guard exists' (Test-Path -LiteralPath $devLinkGuardPath)
Assert-True 'SharePoint config guard exists' (Test-Path -LiteralPath $sharePointGuardPath)
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
Assert-True 'failure before PROD mutation removes maintenance' ($script -match '(?s)elif \[\[ "\$PROD_MUTATED" == "1" \]\]; then.*?else\s+deactivate_maintenance')
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
Assert-True 'eight production plugins configured' (@($config.production.plugins).Count -eq 8)
Assert-True 'one production theme configured' (@($config.production.themes).Count -eq 1)
Assert-True 'eight production MU files configured' (@($config.production.mu_files).Count -eq 8)
Assert-True 'central Microsoft 365 config MU file deploys' (@($config.production.mu_files) -contains 'ssf-microsoft365-config.php')
Assert-True 'one production MU asset directory configured' (@($config.production.mu_asset_dirs).Count -eq 1)
Assert-True 'production MU assets includes assets directory' (@($config.production.mu_asset_dirs) -contains 'assets')
Assert-True 'ssf-promotions is eligible for interactive installation' (-not (@($config.excluded.plugins) -contains 'ssf-promotions'))
Assert-True 'Microsoft ID Login production capable' (@($config.production.plugins) -contains 'microsoft-id-login')
Assert-True 'Old Microsoft login slug removed from production policy' (-not (@($config.production.plugins + $config.excluded.plugins + $config.plugin_policy.dev_only) -contains 'ssf-microsoft-login'))
Assert-True 'plugin policy dev_only exists' ($null -ne $config.plugin_policy.dev_only)
Assert-True 'Microsoft ID Login is not DEV-only plugin policy' (-not (@($config.plugin_policy.dev_only) -contains 'microsoft-id-login'))
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
Assert-Contains 'missing DEV plugin is an installation candidate' $script '$candidate = "install";'
Assert-Contains 'newer DEV plugin is an update candidate' $script '$candidate = "update";'
Assert-Contains 'PROD newer version never downgraded' $script 'version_compare($devVersion, $prodVersion, "<")'
Assert-Contains 'same version checks file contents' $script 'rsync -rcni --no-perms --no-times'
Assert-Contains 'same version mismatch warns instead of copying' $script 'Bump the plugin version before deploying these changes.'
Assert-Contains 'dev-only plugin config exception works' $script 'DEV_ONLY_ALLOWED'
Assert-Contains 'plugin install prompt defaults no' $script 'Installera %s %s i PROD? [y/N]:'
Assert-Contains 'plugin update prompt defaults no' $script 'Uppdatera %s i PROD %s -> %s? [y/N]:'
Assert-Contains 'Enter becomes skip' $script 'answer=""'
Assert-Contains 'only affirmative answers select plugin' $script 'if [[ "$answer" == "y" || "$answer" == "Y" ]]; then'
Assert-Contains 'runtime plan tracks installs' $script '"install_plugins" => array()'
Assert-Contains 'runtime plan tracks updates' $script '"update_plugins" => array()'
Assert-Contains 'runtime plan tracks skipped plugins' $script '"skipped_plugins" => array()'
Assert-Contains 'selected plugin copy comes from plan' $script 'done < <(plugin_plan_array "deploy_plugins")'
Assert-Contains 'skipped plugin version is verified against original PROD version' $script '$entry["prodVersion"]'
Assert-Contains 'selected plugin version is verified against DEV version' $script '$entry["devVersion"]'
Assert-Contains 'updates preserve PROD activation status' $script '$entry["prodStatus"]'
Assert-Contains 'new active DEV installation can activate' $script 'if ($entry["devStatus"] === "active") { $data["activate_plugins"][] = $name; }'
Assert-Contains 'plugin plan printed before dry run' $script 'section "PLUGIN DEPLOYMENT PLAN"'
Assert-Contains 'PROD-only plugin warning exists' $script 'PROD_ONLY'
Assert-Contains 'missing selected DEV plugin cannot pass silently' $script 'Selected DEV plugin files are missing:'
Assert-Contains 'plugin activation from plan only' $script 'plugin_plan_array "activate_plugins"'
Assert-Contains 'touched plugin backup plan exists' $script 'plugin_plan_array "touched_plugins"'
Assert-Contains 'post-deploy plugin parity check exists' $script 'post_deploy_plugin_parity'
$pluginDryRun = [regex]::Match($script, '(?ms)^prod_dry_run\(\) \{.*?^\}').Value
$pluginCopy = [regex]::Match($script, '(?ms)^deploy_files_to_prod\(\) \{.*?^\}').Value
$pluginBackup = [regex]::Match($script, '(?ms)^file_backup\(\) \{.*?^\}').Value
$pluginVerify = [regex]::Match($script, '(?ms)^verify_prod_components\(\) \{.*?^\}').Value
Assert-True 'plugin dry-run uses only the selected plan' ($pluginDryRun.Contains('plugin_plan_array "deploy_plugins"') -and -not $pluginDryRun.Contains('json_array "production.plugins"'))
Assert-True 'plugin file copy uses only the selected plan' ($pluginCopy.Contains('plugin_plan_array "deploy_plugins"') -and -not $pluginCopy.Contains('json_array "production.plugins"'))
Assert-True 'plugin file backup uses only touched plugins' ($pluginBackup.Contains('plugin_plan_array "touched_plugins"') -and -not $pluginBackup.Contains('json_array "production.plugins"'))
Assert-True 'plugin verification does not impose DEV version on every configured plugin' (-not $pluginVerify.Contains('json_array "production.plugins"'))
Assert-Contains 'backup component list uses selected plugins' $script 'plugin_plan_array "deploy_plugins" > "$BACKUP_DIR/components-plugins.txt"'
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
Assert-Contains 'file backup skips absent new PROD plugins' $script 'PROD plugin absent before deployment (new component, no files to back up): $plugin'
Assert-Contains 'file backup skips absent new PROD themes' $script 'PROD theme absent before deployment (new component, no files to back up): $theme'
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
Assert-Contains 'runtime paths include production MU asset dirs' $script 'json_array "production.mu_asset_dirs"'
Assert-Contains 'deployment state records new paths reversible' $script 'exists_before=false'
Assert-Contains 'MU asset sync helper exists' $script 'sync_mu_asset_dirs()'
Assert-Contains 'sync to DEV includes production MU asset dirs' $script 'sync_mu_asset_dirs "$REPO" "$DEV" real'
Assert-Contains 'deploy to PROD includes production MU asset dirs' $script 'sync_mu_asset_dirs "$DEV" "$PROD" real'
Assert-Contains 'PROD dry run reports MU asset dirs' $script 'MU-ASSET-DIR'
Assert-Contains 'backup manifest records MU asset dirs' $script 'components-mu-asset-dirs.txt'
Assert-Contains 'missing DEV MU asset dir blocks verification' $script 'Missing DEV MU asset directory:'
Assert-Contains 'missing MU asset blocks verification' $script 'Missing PROD MU asset:'
Assert-Contains 'different MU asset blocks verification' $script 'PROD MU asset differs from DEV:'
Assert-Contains 'exact DEPLOY confirmation exists' $script '[[ "$confirmation" == "DEPLOY" ]]'
Assert-True 'only one interactive read' (([regex]::Matches($script, 'read -r confirmation')).Count -eq 1)
Assert-NotContains 'no rsync delete' $script 'rsync --delete'
Assert-NotContains 'no rsync delete flag' $script '--delete'
Assert-Contains 'wp-config not touched in summary' $script 'wp-config.php'
Assert-Contains 'uploads not touched in summary' $script 'uploads'
Assert-Contains 'WordPress core not touched in summary' $script 'WordPress core'
Assert-NotContains 'no plugin-specific hardcoded promotions exclusion' $script 'ssf-promotions'
Assert-NotContains 'no plugin-specific hardcoded Microsoft login exclusion' $script 'ssf-microsoft-login'
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
Assert-True 'plugin plan and questions before dry run' ($mainOrder.IndexOf('build_plugin_parity_plan') -lt $mainOrder.IndexOf('prod_dry_run'))
Assert-True 'no mutation before final confirmation' ($mainOrder.IndexOf('confirm_once') -lt $mainOrder.IndexOf('activate_maintenance') -and $mainOrder.IndexOf('confirm_once') -lt $mainOrder.IndexOf('deploy_files_to_prod'))

Assert-Contains 'docs wrapper command' $doc '$HOME/tools/ssf-deploy'
Assert-Contains 'docs one command' $doc 'ssf-deploy'
Assert-Contains 'docs rollback' $doc 'Rollback'
Assert-Contains 'docs backups' $doc '$HOME/ssf-backups'
Assert-Contains 'docs interactive plugin choices' $doc 'the operator chooses whether to'
Assert-Contains 'docs no is the default' $doc 'The default answer is No'
Assert-Contains 'docs final confirmation remains' $doc 'The final `DEPLOY` confirmation is still required.'
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
Assert-Contains 'deploy sources shared SharePoint config guard' $script 'source "$REPO/scripts/deploy/ssf-sharepoint-config-guard.sh"'
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
Assert-Contains 'docs and CLI tests excluded from runtime scan' $devLinkGuard 'preg_match("~/(?:docs?|tests)/|\\.md$~i"'
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
Assert-Contains 'SharePoint reads destination option' $sharePointGuard 'get_option("ssf_member_portal_sharepoint_destinations"'
Assert-Contains 'SharePoint reads graph option' $sharePointGuard 'get_option("ssf_member_portal_graph_configuration"'
Assert-Contains 'SharePoint requires membership production config' $sharePointGuard '"membership_applications" => array("site_id", "drive_id", "list_id", "folder_id")'
Assert-Contains 'SharePoint requires annual meetings production config' $sharePointGuard '"annual_meetings" => array("site_id", "drive_id", "list_id", "folder_id")'
Assert-Contains 'SharePoint guard uses wrapped runtime destinations container' $sharePointGuard '$storedDestinations["destinations"] ?? null'
Assert-Contains 'SharePoint guard reads production profile directly under destination' $sharePointGuard '$destinations[$destination]["production"] ?? null'
Assert-Contains 'SharePoint guard snapshot keeps runtime wrapper' $sharePointGuard '"sharepoint_destinations" => array('
Assert-Contains 'SharePoint guard snapshot keeps schema version metadata' $sharePointGuard '"schema_version" => $storedDestinations["schema_version"] ?? null'
Assert-Contains 'SharePoint guard fingerprint uses corrected production destinations' $sharePointGuard '"destinations" => $snapshot["sharepoint_destinations"]["destinations"]'
Assert-NotContains 'SharePoint guard must not use obsolete environments wrapper' $sharePointGuard '["environments"]["production"]'
Assert-Contains 'SharePoint requires graph secret but redacts it' $sharePointGuard '"client_secret" => isset($graph["client_secret"])'
Assert-Contains 'SharePoint secret snapshot is hash only' $sharePointGuard '"sha256:" . hash("sha256", (string) $graph["client_secret"])'
Assert-Contains 'SharePoint snapshot file exists' $sharePointGuard 'prod-sharepoint-config-snapshot.json'
Assert-Contains 'SharePoint missing config blocks deploy' $sharePointGuard 'Required PROD SharePoint configuration is missing.'
Assert-Contains 'SharePoint changed fingerprint blocks deploy' $sharePointGuard 'SharePoint PROD configuration fingerprint changed during deployment.'
Assert-NotContains 'SharePoint guard never updates options' $sharePointGuard 'update_option('
Assert-NotContains 'SharePoint guard never deletes options' $sharePointGuard 'delete_option('
Assert-NotContains 'SharePoint guard never reads DEV WP' $sharePointGuard 'wp_eval_dev'

$realWrappedSharePointOption = @{
    destinations = @{
        annual_meetings = @{
            production = @{ site_id = 'annual-site'; drive_id = 'annual-drive'; list_id = 'annual-list'; folder_id = 'annual-folder' }
            development = @{ site_id = 'dev-annual-site' }
        }
        membership_applications = @{
            production = @{ site_id = 'member-site'; drive_id = 'member-drive'; list_id = 'member-list'; folder_id = 'member-folder' }
            development = @{ site_id = 'dev-member-site' }
        }
    }
    schema_version = 2
    migrated_environment = 'production'
    migrated_at = '2026-09-16T09:00:00+00:00'
}
$requiredSharePointProfiles = @{
    annual_meetings = @('site_id', 'drive_id', 'list_id', 'folder_id')
    membership_applications = @('site_id', 'drive_id', 'list_id', 'folder_id')
}
function Get-CorrectWrappedSharePointMissing([hashtable]$Option, [hashtable]$Required) {
    $missing = @()
    $destinations = $Option.destinations
    foreach ($destination in $Required.Keys) {
        if (-not $destinations.ContainsKey($destination)) { $missing += "destinations.$destination"; continue }
        $production = $destinations[$destination].production
        if (-not $production) { $missing += "destinations.$destination.production"; continue }
        foreach ($field in $Required[$destination]) {
            if (-not $production.ContainsKey($field) -or [string]::IsNullOrWhiteSpace([string]$production[$field])) {
                $missing += "destinations.$destination.production.$field"
            }
        }
    }
    return @($missing)
}
function Get-OldFlatSharePointMissing([hashtable]$Option, [hashtable]$Required) {
    $missing = @()
    foreach ($destination in $Required.Keys) {
        if (-not $Option.ContainsKey($destination)) { $missing += "destinations.$destination"; continue }
    }
    return @($missing)
}
Assert-True 'real wrapped SharePoint option schema passes corrected validation' (@(Get-CorrectWrappedSharePointMissing $realWrappedSharePointOption $requiredSharePointProfiles).Count -eq 0)
Assert-True 'old flat SharePoint assumption fails against real wrapped schema' (@(Get-OldFlatSharePointMissing $realWrappedSharePointOption $requiredSharePointProfiles).Count -eq 2)

Assert-True 'SharePoint preflight before confirmation' ($mainOrder.IndexOf('validate_sharepoint_config "preflight"') -gt $mainOrder.IndexOf('validate_turnstile_prod_config "preflight"') -and $mainOrder.IndexOf('validate_sharepoint_config "preflight"') -lt $mainOrder.IndexOf('confirm_once'))
Assert-True 'SharePoint snapshot before PROD mutation' ($mainOrder.IndexOf('create_backup_dir') -lt $mainOrder.LastIndexOf('validate_sharepoint_config "preflight"') -and $mainOrder.LastIndexOf('validate_sharepoint_config "preflight"') -lt $mainOrder.IndexOf('activate_maintenance'))
Assert-True 'SharePoint post-check after deployment before opening site' ($mainOrder.IndexOf('validate_sharepoint_config "post"') -gt $mainOrder.IndexOf('verify_prod_components') -and $mainOrder.IndexOf('validate_sharepoint_config "post"') -lt $mainOrder.IndexOf('open_site_for_public_smoke'))

# Exercise the embedded PHP planner with fake inventories; no WordPress or PROD calls.
$phpCommand = Get-Command php -ErrorAction SilentlyContinue
$phpPath = if ($phpCommand) { $phpCommand.Source } else { Join-Path ([IO.Path]::GetTempPath()) 'ssf-codex-php-8.5.10\php.exe' }
if (Test-Path -LiteralPath $phpPath) {
    $scannerMatch = [regex]::Match($devLinkGuard, '(?s)ssf_dev_link_source_check\(\) \{.*?php -r ''(?<code>.*?)'' "\$CONFIG" "\$runtime_root"')
    Assert-True 'embedded DEV-link source scanner extracted' $scannerMatch.Success
    if ($scannerMatch.Success) {
        $fixtureRoot = Join-Path ([IO.Path]::GetTempPath()) ('ssf-dev-link-guard-' + [guid]::NewGuid().ToString('N'))
        $pluginRoot = Join-Path $fixtureRoot 'plugins\ssf-example'
        $testRoot = Join-Path $pluginRoot 'tests'
        $runtimeRoot = Join-Path $pluginRoot 'includes'
        $fixtureConfig = Join-Path $fixtureRoot 'deploy-components.json'
        $scannerFile = Join-Path $fixtureRoot 'source-scanner.php'
        $testFile = Join-Path $testRoot 'cli-fixture.php'
        $runtimeFile = Join-Path $runtimeRoot 'runtime.php'
        try {
            New-Item -ItemType Directory -Path $testRoot, $runtimeRoot -Force | Out-Null
            [IO.File]::WriteAllText($fixtureConfig, '{"production":{"plugins":["ssf-example"],"themes":[],"mu_files":[]}}', [Text.UTF8Encoding]::new($false))
            [IO.File]::WriteAllText($scannerFile, '<?php' + [Environment]::NewLine + $scannerMatch.Groups['code'].Value, [Text.UTF8Encoding]::new($false))
            [IO.File]::WriteAllText($testFile, '<?php $url = "https://ssfb.se/dev/";')
            [IO.File]::WriteAllText($runtimeFile, '<?php $url = "https://ssfb.se/";')
            $scanOutput = & $phpPath $scannerFile $fixtureConfig $fixtureRoot 2>&1
            Assert-True 'CLI test DEV link ignored' ($LASTEXITCODE -eq 0 -and -not $scanOutput)
            [IO.File]::WriteAllText($runtimeFile, '<?php $url = "https://ssfb.se/dev/";')
            $scanOutput = & $phpPath $scannerFile $fixtureConfig $fixtureRoot 2>&1
            Assert-True 'runtime DEV link still blocked' ($LASTEXITCODE -eq 5 -and (($scanOutput -join "`n") -match 'includes/runtime\.php'))
            $scanOutput = & $phpPath $scannerFile $configPath (Join-Path $repo 'wp-content') 2>&1
            Assert-True 'repository runtime source has no DEV links' ($LASTEXITCODE -eq 0 -and -not $scanOutput)
        } finally {
            $resolvedFixture = [IO.Path]::GetFullPath($fixtureRoot)
            $resolvedTemp = [IO.Path]::GetFullPath([IO.Path]::GetTempPath())
            if ($resolvedFixture.StartsWith($resolvedTemp, [StringComparison]::OrdinalIgnoreCase) -and (Test-Path -LiteralPath $fixtureRoot)) {
                Remove-Item -LiteralPath $fixtureRoot -Recurse -Force
            }
        }
    }
    $patterns = @{
        initial = '(?s)build_plugin_parity_plan\(\) \{.*?php -r ''(?<code>.*?)'' "\$CONFIG" "\$dev_plugins" "\$prod_plugins" "\$PLUGIN_PLAN"'
        decide = '(?s)plugin_plan_set_action\(\) \{.*?php -r ''(?<code>.*?)'' "\$PLUGIN_PLAN" "\$name" "\$action"'
        finalize = '(?s)plugin_plan_finalize\(\) \{.*?php -r ''(?<code>.*?)'' "\$PLUGIN_PLAN"'
        baseline = '(?s)plugin_plan_verify_baseline\(\) \{.*?php -r ''(?<code>.*?)'' "\$PLUGIN_PLAN" "\$inventory"'
        verify = '(?s)post_deploy_plugin_parity\(\) \{.*?if ! php -r ''(?<code>.*?)'' "\$PLUGIN_PLAN" "\$prod_plugins"'
    }
    $phpCode = @{}
    foreach ($key in $patterns.Keys) {
        $match = [regex]::Match($script, $patterns[$key])
        Assert-True "embedded PHP $key found" $match.Success
        $phpCode[$key] = $match.Groups['code'].Value
    }
    if (@($phpCode.Values | Where-Object { -not $_ }).Count -eq 0) {
        $testDir = Join-Path ([IO.Path]::GetTempPath()) ('ssf-plugin-plan-test-' + [guid]::NewGuid().ToString('N'))
        New-Item -ItemType Directory -Path $testDir | Out-Null
        $devFile = Join-Path $testDir 'dev.json'
        $prodFile = Join-Path $testDir 'prod.json'
        $planFile = Join-Path $testDir 'plan.json'
        $afterFile = Join-Path $testDir 'after.json'
        $codeFiles = @{}
        foreach ($key in $phpCode.Keys) {
            $codeFiles[$key] = Join-Path $testDir ($key + '.php')
            [IO.File]::WriteAllText($codeFiles[$key], '<?php' + [Environment]::NewLine + $phpCode[$key])
        }
        try {
            function New-Plugin([string]$Name, [string]$Version, [string]$Status = 'active') {
                return @{ name = $Name; version = $Version; status = $Status }
            }
            function Set-Inventory([object[]]$Dev, [object[]]$Prod) {
                [IO.File]::WriteAllText($devFile, (ConvertTo-Json -InputObject @($Dev) -Depth 5))
                [IO.File]::WriteAllText($prodFile, (ConvertTo-Json -InputObject @($Prod) -Depth 5))
                & $phpPath $codeFiles.initial $configPath $devFile $prodFile $planFile | Out-Null
                Assert-True 'embedded plugin inventory planner succeeds' ($LASTEXITCODE -eq 0)
                return Get-Content -Raw -LiteralPath $planFile | ConvertFrom-Json
            }
            function Select-Plugin([string]$Name, [string]$Action) {
                & $phpPath $codeFiles.decide $planFile $Name $Action | Out-Null
                & $phpPath $codeFiles.finalize $planFile | Out-Null
                return Get-Content -Raw -LiteralPath $planFile | ConvertFrom-Json
            }
            function Test-AfterInventory([object[]]$Plugins) {
                [IO.File]::WriteAllText($afterFile, (ConvertTo-Json -InputObject @($Plugins) -Depth 5))
                $previousPreference = $ErrorActionPreference
                try {
                    $ErrorActionPreference = 'Continue'
                    & $phpPath $codeFiles.verify $planFile $afterFile 2>$null | Out-Null
                    return $LASTEXITCODE -eq 0
                } finally {
                    $ErrorActionPreference = $previousPreference
                }
            }
            function Test-BaselineInventory([object[]]$Plugins) {
                [IO.File]::WriteAllText($afterFile, (ConvertTo-Json -InputObject @($Plugins) -Depth 5))
                $previousPreference = $ErrorActionPreference
                try {
                    $ErrorActionPreference = 'Continue'
                    & $phpPath $codeFiles.baseline $planFile $afterFile 2>$null | Out-Null
                    return $LASTEXITCODE -eq 0
                } finally {
                    $ErrorActionPreference = $previousPreference
                }
            }

            $dev = New-Plugin 'ssf-example' '2.0.0'
            $old = New-Plugin 'ssf-example' '1.0.0' 'inactive'
            $plan = Set-Inventory @($dev) @($old)
            Assert-True 'DEV v2 / PROD v1 prompts update' ($plan.entries[0].candidate -eq 'update')
            Assert-True 'pre-mutation baseline matches original PROD' (Test-BaselineInventory @($old))
            Assert-True 'changed PROD before mutation stops the plan' (-not (Test-BaselineInventory @($dev)))
            $plan = Select-Plugin 'ssf-example' 'update'
            Assert-True 'yes creates update and touched plan' (@($plan.update_plugins) -contains 'ssf-example' -and @($plan.touched_plugins) -contains 'ssf-example')
            Assert-True 'update never auto-activates existing PROD plugin' (@($plan.activate_plugins).Count -eq 0)
            Assert-True 'updated plugin verifies at DEV version with prior inactive status' (Test-AfterInventory @((New-Plugin 'ssf-example' '2.0.0' 'inactive')))
            Assert-True 'selected update rejects old version' (-not (Test-AfterInventory @($old)))
            Assert-True 'selected update rejects changed activation status' (-not (Test-AfterInventory @($dev)))

            $null = Set-Inventory @($dev) @($old)
            $plan = Select-Plugin 'ssf-example' 'skip'
            Assert-True 'no update means no copy and no touched backup' (@($plan.deploy_plugins).Count -eq 0 -and @($plan.touched_plugins).Count -eq 0 -and @($plan.skipped_plugins) -contains 'ssf-example')
            Assert-True 'skipped plugin verifies unchanged PROD version and status' (Test-AfterInventory @($old))
            Assert-True 'skipped plugin rejects unexpected update' (-not (Test-AfterInventory @((New-Plugin 'ssf-example' '2.0.0' 'inactive'))))

            $plan = Set-Inventory @($dev) @()
            Assert-True 'missing PROD plugin prompts install' ($plan.entries[0].candidate -eq 'install')
            $plan = Select-Plugin 'ssf-example' 'install'
            Assert-True 'yes install copies and activates active DEV plugin' (@($plan.install_plugins) -contains 'ssf-example' -and @($plan.activate_plugins) -contains 'ssf-example')
            Assert-True 'installed active plugin verifies against DEV' (Test-AfterInventory @($dev))
            $null = Set-Inventory @($dev) @()
            $plan = Select-Plugin 'ssf-example' 'skip'
            Assert-True 'no install leaves PROD untouched' (@($plan.deploy_plugins).Count -eq 0 -and @($plan.skipped_plugins) -contains 'ssf-example' -and (Test-AfterInventory @()))
            Assert-True 'skipped install rejects unexpected files in PROD' (-not (Test-AfterInventory @($dev)))

            $same = New-Plugin 'ssf-example' '1.0.0'
            $plan = Set-Inventory @($same) @($same)
            Assert-True 'same version needs no install/update question' ($plan.entries[0].candidate -eq 'same')
            $plan = Select-Plugin 'ssf-example' 'files_differ'
            Assert-True 'same version with different files is skipped and not touched' (@($plan.skipped_plugins) -contains 'ssf-example' -and @($plan.deploy_plugins).Count -eq 0)
            $newer = New-Plugin 'ssf-example' '3.0.0'
            $plan = Set-Inventory @($dev) @($newer)
            Assert-True 'newer PROD has no downgrade candidate' ($plan.entries[0].classification -eq 'PROD_NEWER' -and $plan.entries[0].candidate -eq 'none')
            $mustUse = New-Plugin 'ssf-mu-example' '1.0.0' 'must-use'
            $plan = Set-Inventory @($mustUse) @($mustUse)
            Assert-True 'MU plugins are not prompted as normal plugins' ($plan.entries[0].candidate -eq 'none')
        } finally {
            foreach ($path in @($devFile, $prodFile, $planFile, $afterFile)) { Remove-Item -LiteralPath $path -Force -ErrorAction SilentlyContinue }
            foreach ($path in $codeFiles.Values) { Remove-Item -LiteralPath $path -Force -ErrorAction SilentlyContinue }
            Remove-Item -LiteralPath $testDir -Force -ErrorAction SilentlyContinue
        }
    }
} else {
    Fail 'PHP CLI required for embedded plugin-planner tests.'
}

if ($failures.Count) {
    $failures | ForEach-Object { Write-Error $_ }
    exit 1
}

Write-Host 'PASS: server-native deployment workflow static safety tests.'
