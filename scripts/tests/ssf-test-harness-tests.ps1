[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$failures = [Collections.Generic.List[string]]::new()

function Fail([string]$Message) { $failures.Add($Message) | Out-Null }
function Read-RepoFile([string]$Path) { Get-Content -Raw -Encoding UTF8 -LiteralPath (Join-Path $repo $Path) }
function Assert-True([string]$Name, [bool]$Condition) { if (-not $Condition) { Fail $Name } }
function Assert-Contains([string]$Name, [string]$Content, [string]$Expected) { if (-not $Content.Contains($Expected)) { Fail "$Name missing: $Expected" } }
function Assert-NotContains([string]$Name, [string]$Content, [string]$Unexpected) { if ($Content.Contains($Unexpected)) { Fail "$Name contains forbidden text: $Unexpected" } }

$envPath = Join-Path $repo 'config\environments.json'
$fixturesPath = Join-Path $repo 'config\test-fixtures.json'
$envJson = Get-Content -Raw -Encoding UTF8 -LiteralPath $envPath | ConvertFrom-Json
$fixturesJson = Get-Content -Raw -Encoding UTF8 -LiteralPath $fixturesPath | ConvertFrom-Json
$envText = Read-RepoFile 'config\environments.json'
$fixturesText = Read-RepoFile 'config\test-fixtures.json'
$login = Read-RepoFile 'scripts\ssf-wp-login.ps1'
$preflight = Read-RepoFile 'scripts\ssf-dev-preflight.ps1'
$lib = Read-RepoFile 'scripts\lib\ssf-test-harness.ps1'
$agents = Read-RepoFile 'AGENTS.md'
$gitignore = Read-RepoFile '.gitignore'

$u00e4 = [char]0x00e4
$u00e5 = [char]0x00e5
$u00c5 = [char]0x00c5
$u00f6 = [char]0x00f6

Assert-True 'environments.json has config_version' ($envJson.config_version -eq 1)
Assert-True 'test-fixtures.json has config_version' ($fixturesJson.config_version -eq 1)
Assert-True 'DEV WordPress URL exists' ([string]$envJson.dev.wordpress.url -eq 'https://ssfb.se/dev')
Assert-True 'DEV URL contains /dev' ([string]$envJson.dev.wordpress.url -match '/dev$')
Assert-True 'PROD URL is not DEV' ([string]$envJson.prod.wordpress.url -eq 'https://ssfb.se')
Assert-True 'PROD live tests blocked' ($envJson.prod.safety.live_tests_allowed -eq $false)
Assert-True 'Active DEV root is public_html/dev' ([string]$envJson.dev.filesystem.active_wordpress_root -eq 'public_html/dev')
Assert-True 'Wrong FTP root is not active root' ([string]$envJson.dev.filesystem.known_wrong_ftp_root -ne [string]$envJson.dev.filesystem.active_wordpress_root)

$expectedUtf8 = @(
    "Medlemsans${u00f6}kningar",
    "${u00c5}rsm${u00f6}ten",
    "Beg${u00e4}r komplettering",
    "F${u00e4}rdigbehandlad av styrelsen"
)
foreach ($needle in $expectedUtf8) {
    Assert-Contains "UTF-8 $needle" $envText $needle
}

$badMojibake = @(
    ('Medlemsans' + [char]0x00c3),
    ([string]([char]0x00c3)),
    (([string]([char]0x00c3)) + 'rsm')
)
foreach ($bad in $badMojibake) {
    Assert-NotContains "No mojibake $bad" $envText $bad
}

$canonical = @($envJson.dev.membership_sharepoint.canonical_metadata)
foreach ($field in @('ApplicationNumber','VesselName','ApplicationPath','ApplicationStatus','MembershipStatus','ReceivedDate','DecisionDate','AspirantStartDate','AspirantReviewDate','WordPressApplicationID')) {
    Assert-True "Canonical membership field $field" ($canonical -contains $field)
}

foreach ($choice in @('Normalfallet','Mindre registrerat fartyg','Fartyg under restaurering','Nybyggt traditionsfartyg')) {
    Assert-True "ApplicationPath choice $choice" (@($envJson.dev.membership_sharepoint.application_path_choices) -contains $choice)
}
foreach ($choice in @('Inkommen','Under granskning',"Beg${u00e4}r komplettering","V${u00e4}ntar p${u00e5} komplettering",'Inspektion ska bokas','Inspektion bokad',"Under slutbed${u00f6}mning","Godk${u00e4}nd som aspirant",'Avslagen')) {
    Assert-True "ApplicationStatus choice $choice" (@($envJson.dev.membership_sharepoint.application_status_choices) -contains $choice)
}
foreach ($choice in @('Ej medlem','Aspirant',"Uppf${u00f6}ljning",'Medlemsfartyg','Avslutad')) {
    Assert-True "MembershipStatus choice $choice" (@($envJson.dev.membership_sharepoint.membership_status_choices) -contains $choice)
}
foreach ($choice in @('Inkommen','Under behandling',"Beg${u00e4}r komplettering","F${u00e4}rdigbehandlad av styrelsen","Till ${u00e5}rsm${u00f6}tet","Beslutad p${u00e5} ${u00e5}rsm${u00f6}tet",'Avslutad')) {
    Assert-True "Motion status choice $choice" (@($envJson.dev.motions_sharepoint.status_choices) -contains $choice)
}

$allowedPolicies = @($fixturesJson.reuse_policies)
foreach ($fixture in @($fixturesJson.fixtures)) {
    Assert-True "Fixture policy $($fixture.reference)" ($allowedPolicies -contains [string]$fixture.reuse_policy)
    Assert-True "Fixture type $($fixture.reference)" (@('membership_application','motion') -contains [string]$fixture.type)
}

$secretPatterns = @(
    'HotellSvea',
    'HotelSvea',
    'client_secret"\s*:\s*"[^"]+',
    'SSF_WP_PASSWORD\s*=\s*[^<\r\n][^\r\n]*',
    'SSF_FTP_PASSWORD\s*=\s*[^<\r\n][^\r\n]*',
    'SSF_GRAPH_CLIENT_SECRET\s*=\s*[^<\r\n][^\r\n]*'
)
foreach ($pattern in $secretPatterns) {
    Assert-True "No secret in config files for $pattern" (-not (($envText + "`n" + $fixturesText) -match $pattern))
}

Assert-Contains 'Login helper uses curl wrapper' $login 'Invoke-SsfCurl'
Assert-Contains 'Curl wrapper uses curl.exe' $lib 'curl.exe'
Assert-NotContains 'Login helper does not use Invoke-WebRequest' $login 'Invoke-WebRequest'
Assert-NotContains 'Login helper does not use Invoke-RestMethod' $login 'Invoke-RestMethod'
Assert-Contains 'Preflight uses curl helper' $preflight 'Invoke-SsfCurl'
Assert-NotContains 'Preflight does not use Invoke-WebRequest' $preflight 'Invoke-WebRequest'
Assert-NotContains 'Preflight does not use Invoke-RestMethod' $preflight 'Invoke-RestMethod'
Assert-Contains 'AGENTS requires curl' $agents 'ALWAYS use `curl.exe`'
Assert-Contains 'AGENTS forbids Invoke-WebRequest' $agents 'NEVER use `Invoke-WebRequest`'
Assert-Contains '.gitignore ignores local env' $gitignore 'config/ssf-dev-test.env'
Assert-Contains '.gitignore ignores preflight artifact' $gitignore 'artifacts/dev-preflight.json'

if ($failures.Count) {
    $failures | ForEach-Object { Write-Error $_ }
    exit 1
}

Write-Host 'PASS: SSF test harness config, fixtures, docs and curl-only safeguards.'
