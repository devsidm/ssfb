[CmdletBinding()]
param(
    [switch]$Json,
    [switch]$SkipLogin,
    [switch]$DebugOutput
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

. (Join-Path $PSScriptRoot 'lib\ssf-test-harness.ps1')

Import-SsfSecrets
$repo = Get-SsfRepoRoot
$configPath = Join-Path $repo 'config\environments.json'
$fixturesPath = Join-Path $repo 'config\test-fixtures.json'
$envConfig = Read-SsfJson $configPath
$fixtures = Read-SsfJson $fixturesPath
$dev = $envConfig.dev
$memberApplicationsPath = 'General/Medlemsans' + [char]0x00f6 + 'kningar'
$annualMeetingsPath = 'General/' + [char]0x00c5 + 'rsm' + [char]0x00f6 + 'ten'
$report = [ordered]@{
    environment = 'dev'
    timestamp = (Get-Date).ToUniversalTime().ToString('o')
    build = 'NOT_TESTED'
    git_head = 'NOT_TESTED'
    wordpress_login = if ($SkipLogin) { 'NOT_TESTED' } else { 'FAIL' }
    local = [ordered]@{}
    safety = [ordered]@{}
    membership = [ordered]@{
        site = 'NOT_TESTED'
        drive = 'NOT_TESTED'
        list = 'NOT_TESTED'
        folder = 'NOT_TESTED'
        schema = 'NOT_TESTED'
        active_mapping = 'NOT_TESTED'
    }
    motions = [ordered]@{
        site = 'NOT_TESTED'
        drive = 'NOT_TESTED'
        list = 'NOT_TESTED'
        folder = 'NOT_TESTED'
        schema = 'NOT_TESTED'
    }
    dev_protection = 'FAIL'
    wrong_ftp_root_warning = 'PASS'
    result = 'FAIL'
}
$failures = [Collections.Generic.List[string]]::new()

function Add-CheckFailure([string]$Message) {
    $failures.Add($Message) | Out-Null
}

try {
    Test-SsfDevUrl -Url ([string]$dev.wordpress.url)
    $report.safety.dev_url = 'PASS'
} catch {
    $report.safety.dev_url = 'FAIL'
    Add-CheckFailure $_.Exception.Message
}

$report.safety.prod_blocked = if ([string]$envConfig.prod.wordpress.url -eq 'https://ssfb.se') { 'PASS' } else { 'FAIL' }
if ($report.safety.prod_blocked -eq 'FAIL') { Add-CheckFailure 'PROD URL missing or unexpected.' }

$report.local.environments_json = if ($envConfig.config_version -ge 1) { 'PASS' } else { 'FAIL' }
$report.local.test_fixtures_json = if ($fixtures.config_version -ge 1) { 'PASS' } else { 'FAIL' }
$report.local.utf8 = if (
    ([string]$dev.membership_sharepoint.expected_path) -eq $memberApplicationsPath -and
    ([string]$dev.motions_sharepoint.expected_path) -eq $annualMeetingsPath -and
    -not ((Get-Content -Raw -Encoding UTF8 -LiteralPath $configPath).Contains([string][char]0x00c3))
) { 'PASS' } else { 'FAIL' }
if ($report.local.utf8 -eq 'FAIL') { Add-CheckFailure 'UTF-8 validation failed.' }

$manifestPath = Join-Path $repo 'wp-content\mu-plugins\ssf-release-manifest.json'
if (Test-Path -LiteralPath $manifestPath) {
    $manifest = Read-SsfJson $manifestPath
    $report.build = [string]$manifest.build
    $report.local.release_manifest = 'PASS'
} else {
    $report.local.release_manifest = 'FAIL'
    Add-CheckFailure 'Release-manifest saknas.'
}

$insideGit = (& git -C $repo rev-parse --is-inside-work-tree 2>$null)
if ($LASTEXITCODE -eq 0 -and $insideGit -eq 'true') {
    $report.local.git_repo = 'PASS'
    $report.git_head = (& git -C $repo rev-parse --short HEAD)
    $dirty = @(& git -C $repo status --porcelain)
    $report.local.working_tree = if ($dirty.Count -eq 0) { 'clean' } else { 'dirty' }
} else {
    $report.local.git_repo = 'FAIL'
    Add-CheckFailure 'Git repo kunde inte verifieras.'
}

$secretStatus = Get-SsfCredentialStatus @('SSF_WP_USER','SSF_WP_PASSWORD','SSF_GRAPH_CLIENT_SECRET','SSF_FTP_PASSWORD')
$report.authentication = [ordered]@{
    wp_credentials = if ($secretStatus.SSF_WP_USER -eq 'FOUND' -and $secretStatus.SSF_WP_PASSWORD -eq 'FOUND') { 'FOUND' } else { 'MISSING' }
    graph_secret = if ($secretStatus.SSF_GRAPH_CLIENT_SECRET -eq 'FOUND') { 'FOUND' } else { 'NOT_REQUIRED' }
    ftp_credentials = if ($secretStatus.SSF_FTP_PASSWORD -eq 'FOUND') { 'FOUND' } else { 'NOT_REQUIRED' }
}

if (-not $SkipLogin) {
    $loginJson = & (Join-Path $PSScriptRoot 'ssf-wp-login.ps1') -Json
    if ($LASTEXITCODE -eq 0) {
        $login = $loginJson | ConvertFrom-Json
        $report.wordpress_login = if ($login.result -eq 'PASS') { 'PASS' } else { 'FAIL' }
    } else {
        $report.wordpress_login = 'FAIL'
    }
    if ($report.wordpress_login -ne 'PASS') { Add-CheckFailure 'WordPress curl-login misslyckades.' }
}

$canonical = @($dev.membership_sharepoint.canonical_metadata)
$expectedCanonical = @('ApplicationNumber','VesselName','ApplicationPath','ApplicationStatus','MembershipStatus','ReceivedDate','DecisionDate','AspirantStartDate','AspirantReviewDate','WordPressApplicationID')
$legacy = @($dev.membership_sharepoint.legacy_fields_not_authoritative)
$report.membership.active_mapping = if (@($expectedCanonical | Where-Object { $_ -notin $canonical }).Count -eq 0 -and 'Status' -in $legacy) { 'PASS' } else { 'FAIL' }
if ($report.membership.active_mapping -eq 'FAIL') { Add-CheckFailure 'Membership canonical mapping missing or legacy field is used.' }

$report.membership.site = if ($dev.membership_sharepoint.site_url -match 'medlemsgruppen-test') { 'PASS' } else { 'FAIL' }
$report.membership.drive = if ($dev.membership_sharepoint.drive_name -eq 'Dokument' -and $dev.membership_sharepoint.drive_id) { 'PASS' } else { 'FAIL' }
$report.membership.list = if ($dev.membership_sharepoint.list_id) { 'PASS' } else { 'FAIL' }
$report.membership.folder = if ($dev.membership_sharepoint.applications_folder_id -and $dev.membership_sharepoint.expected_path -eq $memberApplicationsPath) { 'PASS' } else { 'FAIL' }
$report.membership.schema = if (
    @($dev.membership_sharepoint.application_path_choices).Count -eq 4 -and
    @($dev.membership_sharepoint.application_status_choices).Count -eq 6 -and
    @($dev.membership_sharepoint.membership_status_choices).Count -eq 5
) { 'PASS' } else { 'FAIL' }

$report.motions.site = if ($dev.motions_sharepoint.site_url -match 'styrelsen-test') { 'PASS' } else { 'FAIL' }
$report.motions.drive = if ($dev.motions_sharepoint.drive_name -eq 'Dokument' -and $dev.motions_sharepoint.drive_id) { 'PASS' } else { 'FAIL' }
$report.motions.list = if ($dev.motions_sharepoint.list_id) { 'PASS' } else { 'FAIL' }
$report.motions.folder = if ($dev.motions_sharepoint.annual_meetings_folder_id -and $dev.motions_sharepoint.expected_path -eq $annualMeetingsPath) { 'PASS' } else { 'FAIL' }
$report.motions.schema = if (@($dev.motions_sharepoint.status_choices).Count -eq 7 -and $dev.motions_sharepoint.status_column_internal_name -eq 'Status') { 'PASS' } else { 'FAIL' }

foreach ($section in @('membership','motions')) {
    foreach ($property in $report.$section.Keys) {
        if ($report.$section[$property] -eq 'FAIL') {
            Add-CheckFailure "$section $property FAIL"
        }
    }
}

if ($dev.filesystem.active_wordpress_root -eq 'public_html/dev' -and $dev.filesystem.known_wrong_ftp_root -match 'wp-content') {
    $report.wrong_ftp_root_warning = 'PASS'
} else {
    $report.wrong_ftp_root_warning = 'FAIL'
    Add-CheckFailure 'Aktiv DEV-root eller wrong-root-varning saknas.'
}

try {
    Assert-SsfCurl
    $wpAdminUrl = ([string]$dev.wordpress.url).TrimEnd('/') + '/wp-admin/'
    $ordinaryUrl = ([string]$dev.wordpress.url).TrimEnd('/') + '/ansokan/'
    $appStatusUrl = ([string]$dev.wordpress.url).TrimEnd('/') + '/ansokan-status/'
    $motionStatusUrl = ([string]$dev.wordpress.url).TrimEnd('/') + '/motion-status/'
    $checks = @()
    foreach ($item in @(
        @{ name = 'wp_admin'; url = $wpAdminUrl; shouldRedirect = $true },
        @{ name = 'ordinary'; url = $ordinaryUrl; shouldRedirect = $true },
        @{ name = 'app_status'; url = $appStatusUrl; shouldRedirect = $false },
        @{ name = 'motion_status'; url = $motionStatusUrl; shouldRedirect = $false }
    )) {
        $effective = Invoke-SsfCurl -Arguments @('-sS','-I','-L','-o','NUL','-w','%{url_effective}', $item.url)
        $redirectedToLogin = $effective -match 'wp-login\.php'
        $checks += ($redirectedToLogin -eq [bool]$item.shouldRedirect)
    }
    $report.dev_protection = if ($checks -notcontains $false) { 'PASS' } else { 'FAIL' }
} catch {
    $report.dev_protection = 'FAIL'
}
if ($report.dev_protection -ne 'PASS') { Add-CheckFailure 'DEV protection route-kontroll misslyckades.' }

$report.result = if ($failures.Count -eq 0) { 'PASS' } else { 'FAIL' }
$artifactDir = Join-Path $repo 'artifacts'
if (-not (Test-Path -LiteralPath $artifactDir)) { New-Item -ItemType Directory -Force -Path $artifactDir | Out-Null }
$artifactPath = Join-Path $artifactDir 'dev-preflight.json'
($report | ConvertTo-Json -Depth 8) | Set-Content -Encoding UTF8 -LiteralPath $artifactPath

if ($Json) {
    $report | ConvertTo-Json -Depth 8
} else {
    Write-Host 'SSF DEV ENVIRONMENT'
    Write-Host '----------------------------------------'
    Write-Host 'WordPress'
    Write-Host ('  URL                 {0}' -f $dev.wordpress.url)
    Write-Host ('  Login               {0}' -f $dev.wordpress.login_url)
    Write-Host ('  Server root         {0}' -f $dev.filesystem.active_wordpress_root)
    Write-Host ('  Build               {0}' -f $report.build)
    $configuredUser = [Environment]::GetEnvironmentVariable('SSF_WP_USER', 'Process')
    if (-not $configuredUser) { $configuredUser = 'MISSING' }
    Write-Host ('  Account             {0}' -f $configuredUser)
    Write-Host ('  Config source       {0}' -f $dev.runtime.authoritative_sharepoint_option)
    Write-Host ''
    Write-Host 'Membership SharePoint'
    Write-Host ('  Site                {0}' -f $dev.membership_sharepoint.site_url)
    Write-Host ('  Site ID             {0}' -f $dev.membership_sharepoint.site_id)
    Write-Host ('  Drive               {0}' -f $dev.membership_sharepoint.drive_name)
    Write-Host ('  Drive ID            {0}' -f $dev.membership_sharepoint.drive_id)
    Write-Host ('  List ID             {0}' -f $dev.membership_sharepoint.list_id)
    Write-Host ('  General folder      {0}' -f $dev.membership_sharepoint.general_folder_id)
    Write-Host ('  Applications folder {0}' -f $dev.membership_sharepoint.applications_folder_id)
    Write-Host '  Field mapping       canonical'
    Write-Host ''
    Write-Host 'Motions SharePoint'
    Write-Host ('  Site                {0}' -f $dev.motions_sharepoint.site_url)
    Write-Host ('  Site ID             {0}' -f $dev.motions_sharepoint.site_id)
    Write-Host ('  Drive               {0}' -f $dev.motions_sharepoint.drive_name)
    Write-Host ('  Drive ID            {0}' -f $dev.motions_sharepoint.drive_id)
    Write-Host ('  List ID             {0}' -f $dev.motions_sharepoint.list_id)
    Write-Host ('  General folder      {0}' -f $dev.motions_sharepoint.general_folder_id)
    Write-Host ('  Annual meetings fld {0}' -f $dev.motions_sharepoint.annual_meetings_folder_id)
    Write-Host ''
    Write-Host 'Authentication'
    Write-Host ('  WP credentials      {0}' -f $report.authentication.wp_credentials)
    Write-Host ('  Graph secret        {0}' -f $report.authentication.graph_secret)
    Write-Host ('  FTP credentials     {0}' -f $report.authentication.ftp_credentials)
    Write-Host ''
    Write-Host ('ACTIVE DEV ROOT:      {0}' -f $dev.filesystem.active_wordpress_root)
    Write-Host ('KNOWN WRONG ROOT:     {0}' -f $dev.filesystem.known_wrong_ftp_root)
    Write-Host ''
    if ($report.result -eq 'PASS') {
        Write-Host 'RESULT: DEV READY FOR TESTING'
    } else {
        Write-Host 'RESULT: STOP - DEV NOT READY'
        foreach ($failure in $failures) { Write-Host ('  - {0}' -f $failure) }
    }
    if ($DebugOutput) { Write-Host ('JSON report          {0}' -f $artifactPath) }
}

if ($report.result -ne 'PASS') {
    exit 1
}
