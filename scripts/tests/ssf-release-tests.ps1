[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$testRoot = Join-Path ([IO.Path]::GetTempPath()) ('ssf-release-tests-' + [guid]::NewGuid().ToString('N'))
$results = [Collections.Generic.List[object]]::new()

function Assert-Equal {
    param([string]$Name, $Expected, $Actual)
    if ($Expected -ne $Actual) {
        throw "$Name misslyckades. Förväntat: $Expected. Faktiskt: $Actual."
    }
    $results.Add([pscustomobject]@{ Test = $Name; Result = 'PASS' })
}

function Write-FixtureManifest {
    param([string]$Version)
    $manifest = [ordered]@{
        schema_version = 1
        release_name = 'SSF Web'
        version = $Version
        build = '20260904.9'
        built_at = '2026-09-04T12:00:00Z'
        source = 'test'
        source_revision = ''
        description = 'Test fixture'
        notes = ''
        components = @('ssf-release-controls')
        status = 'development'
        prepared_at = ''
    }
    $path = Join-Path $testRoot 'wp-content\mu-plugins\ssf-release-manifest.json'
    [IO.File]::WriteAllText($path, (($manifest | ConvertTo-Json -Depth 5) + [Environment]::NewLine), [Text.UTF8Encoding]::new($false))
}

function Invoke-DeployGuardCase {
    param([string]$Environment, [string]$RemoteRoot, [bool]$SupplyRemoteRoot)
    try {
        if ($SupplyRemoteRoot) {
            & (Join-Path $repo 'scripts\ssf-release-deploy.ps1') -Environment $Environment -BaseUrl 'https://example.test/dev' -FtpHost 'ftp.example.test' -FtpUser 'test-user' -RemoteRoot $RemoteRoot
        } else {
            & (Join-Path $repo 'scripts\ssf-release-deploy.ps1') -Environment $Environment -BaseUrl 'https://example.test/dev' -FtpHost 'ftp.example.test' -FtpUser 'test-user'
        }
        return ''
    } catch {
        return $_.Exception.Message
    }
}

try {
    New-Item -ItemType Directory -Path (Join-Path $testRoot 'scripts') -Force | Out-Null
    New-Item -ItemType Directory -Path (Join-Path $testRoot 'wp-content\mu-plugins') -Force | Out-Null
    Copy-Item -LiteralPath (Join-Path $repo 'scripts\ssf-release-build.ps1') -Destination (Join-Path $testRoot 'scripts\ssf-release-build.ps1')
    Copy-Item -LiteralPath (Join-Path $repo 'scripts\ssf-release-prepare.ps1') -Destination (Join-Path $testRoot 'scripts\ssf-release-prepare.ps1')

    git -C $testRoot init --quiet
    git -C $testRoot config user.email 'release-tests@localhost'
    git -C $testRoot config user.name 'SSF Release Tests'
    git -C $testRoot add .
    git -C $testRoot commit --quiet -m 'Test fixture'

    & (Join-Path $testRoot 'scripts\ssf-release-build.ps1') -Description 'Första testbuild' -Components 'komponent-a,komponent-b' | Out-Null
    $firstManifest = Get-Content -Raw -LiteralPath (Join-Path $testRoot 'wp-content\mu-plugins\ssf-release-manifest.json') | ConvertFrom-Json
    $firstBuild = $firstManifest.build
    Assert-Equal 'Komponentlista delas upp' 2 $firstManifest.components.Count
    & (Join-Path $testRoot 'scripts\ssf-release-build.ps1') -Description 'Andra testbuild' | Out-Null
    $secondBuild = (Get-Content -Raw -LiteralPath (Join-Path $testRoot 'wp-content\mu-plugins\ssf-release-manifest.json') | ConvertFrom-Json).build
    Assert-Equal 'Två builds får olika ID' $false ($firstBuild -eq $secondBuild)

    foreach ($case in @(
        @{ Level = 'patch'; Expected = '1.4.3' },
        @{ Level = 'minor'; Expected = '1.5.0' },
        @{ Level = 'major'; Expected = '2.0.0' }
    )) {
        Write-FixtureManifest -Version '1.4.2'
        & (Join-Path $testRoot 'scripts\ssf-release-prepare.ps1') -Level $case.Level | Out-Null
        $prepared = Get-Content -Raw -LiteralPath (Join-Path $testRoot 'wp-content\mu-plugins\ssf-release-manifest.json') | ConvertFrom-Json
        Assert-Equal ("SemVer " + $case.Level) $case.Expected $prepared.version
        Assert-Equal ("Oförändrad build vid " + $case.Level) '20260904.9' $prepared.build
    }

    $php = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\mu-plugins\ssf-release-controls.php')
    $deploy = Get-Content -Raw -LiteralPath (Join-Path $repo 'scripts\ssf-release-deploy.ps1')
    Assert-Equal 'Miljö använder WordPress' $true ($php.Contains('wp_get_environment_type()'))
    Assert-Equal 'Ingen URL- eller sökvägsgissning' $false ($php.Contains('installation_environment'))
    Assert-Equal 'Deploymenthistorik finns' $true ($php.Contains("DEPLOYMENTS_OPTION = 'ssf_release_deployments'"))
    Assert-Equal 'Absolute FTP root preserved' $true ($deploy.Contains("if (`$remoteRootIsAbsolute) { '//' } else { '/' }"))
    Assert-Equal 'DEV RemoteRoot guard exists' $true ($deploy.Contains("`$Environment -eq 'development' -and -not [string]::IsNullOrWhiteSpace(`$RemoteRoot)"))
    Assert-Equal 'DEV RemoteRoot guard message exists' $true ($deploy.Contains('DEV FTP account is already rooted at the DEV WordPress document root. Do not specify RemoteRoot.'))
    Assert-Equal 'DEV FTP root listing exists' $true ($deploy.Contains('--ftp-pasv --list-only'))
    Assert-Equal 'DEV FTP root verifies wp-content directly' $true ($deploy.Contains("`$ftpRootListing -notcontains 'wp-content'"))
    Assert-Equal 'Nested ssfb.se is only reported' $true ($deploy.Contains("`$ftpRootListing -contains 'ssfb.se'"))
    Assert-Equal 'Production guard remains separate' $true ($deploy.Contains("if (`$Environment -eq 'production')"))

    $guardMessage = 'DEV FTP account is already rooted at the DEV WordPress document root. Do not specify RemoteRoot.'
    $devOmitted = Invoke-DeployGuardCase -Environment 'development' -RemoteRoot '' -SupplyRemoteRoot $false
    $devEmpty = Invoke-DeployGuardCase -Environment 'development' -RemoteRoot '' -SupplyRemoteRoot $true
    $devDev = Invoke-DeployGuardCase -Environment 'development' -RemoteRoot 'dev' -SupplyRemoteRoot $true
    $devDomain = Invoke-DeployGuardCase -Environment 'development' -RemoteRoot 'ssfb.se' -SupplyRemoteRoot $true
    $devNested = Invoke-DeployGuardCase -Environment 'development' -RemoteRoot 'ssfb.se/public_html/dev' -SupplyRemoteRoot $true
    $production = Invoke-DeployGuardCase -Environment 'production' -RemoteRoot 'dev' -SupplyRemoteRoot $true
    Assert-Equal 'DEV RemoteRoot omitted is allowed past guard' $false ($devOmitted -match [regex]::Escape($guardMessage))
    Assert-Equal 'DEV empty RemoteRoot is allowed past guard' $false ($devEmpty -match [regex]::Escape($guardMessage))
    Assert-Equal 'DEV RemoteRoot dev is blocked' $true ($devDev -match [regex]::Escape($guardMessage))
    Assert-Equal 'DEV RemoteRoot domain is blocked' $true ($devDomain -match [regex]::Escape($guardMessage))
    Assert-Equal 'DEV nested RemoteRoot is blocked' $true ($devNested -match [regex]::Escape($guardMessage))
    Assert-Equal 'Production RemoteRoot behavior unchanged' $false ($production -match [regex]::Escape($guardMessage))

    $scripts = @('ssf-release-build.ps1', 'ssf-release-prepare.ps1', 'ssf-release-deploy.ps1')
    foreach ($script in $scripts) {
        $tokens = $null
        $errors = $null
        [Management.Automation.Language.Parser]::ParseFile((Join-Path $repo "scripts\$script"), [ref]$tokens, [ref]$errors) | Out-Null
        Assert-Equal "$script syntax" 0 $errors.Count
    }

    $results | Format-Table -AutoSize
} finally {
    $tempRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath())
    $resolvedTestRoot = [IO.Path]::GetFullPath($testRoot)
    if ($resolvedTestRoot.StartsWith($tempRoot, [StringComparison]::OrdinalIgnoreCase) -and (Split-Path $resolvedTestRoot -Leaf) -like 'ssf-release-tests-*' -and (Test-Path -LiteralPath $resolvedTestRoot)) {
        Remove-Item -LiteralPath $resolvedTestRoot -Recurse -Force
    }
}
