[CmdletBinding()]
param()

Set-StrictMode -Version Latest

function Get-SsfRepoRoot {
    $root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
    return $root
}

function Read-SsfJson {
    param([Parameter(Mandatory)][string]$Path)
    return Get-Content -Raw -Encoding UTF8 -LiteralPath $Path | ConvertFrom-Json
}

function Get-SsfEnvironmentConfig {
    param([ValidateSet('dev','prod')][string]$Environment = 'dev')
    $repo = Get-SsfRepoRoot
    $config = Read-SsfJson (Join-Path $repo 'config\environments.json')
    return $config.$Environment
}

function Import-SsfEnvFile {
    param([string]$Path)
    if (-not $Path -or -not (Test-Path -LiteralPath $Path)) {
        return
    }

    foreach ($line in Get-Content -Encoding UTF8 -LiteralPath $Path) {
        $trimmed = $line.Trim()
        if (-not $trimmed -or $trimmed.StartsWith('#')) {
            continue
        }
        $parts = $trimmed -split '=', 2
        if ($parts.Count -ne 2) {
            continue
        }
        $name = $parts[0].Trim()
        $value = $parts[1]
        if (-not $name -or [Environment]::GetEnvironmentVariable($name, 'Process')) {
            continue
        }
        [Environment]::SetEnvironmentVariable($name, $value, 'Process')
    }
}

function Import-SsfSecrets {
    $explicit = [Environment]::GetEnvironmentVariable('SSF_SECRETS_FILE', 'Process')
    if ($explicit) {
        Import-SsfEnvFile -Path $explicit
    }

    $userProfile = [Environment]::GetFolderPath('UserProfile')
    if ($userProfile) {
        Import-SsfEnvFile -Path (Join-Path $userProfile '.ssf\ssf-dev-test.env')
    }
}

function Get-SsfCredentialStatus {
    param([string[]]$Names)
    $result = [ordered]@{}
    foreach ($name in $Names) {
        $result[$name] = if ([Environment]::GetEnvironmentVariable($name, 'Process')) { 'FOUND' } else { 'MISSING' }
    }
    return [pscustomobject]$result
}

function Assert-SsfCurl {
    $cmd = Get-Command curl.exe -ErrorAction SilentlyContinue
    if (-not $cmd) {
        throw 'curl.exe is missing. SSF WordPress HTTP tests must use curl.exe.'
    }
}

function New-SsfCookiePath {
    $name = 'ssf-wp-dev-{0}-{1}.cookie' -f $PID, ([guid]::NewGuid().ToString('N'))
    return Join-Path ([IO.Path]::GetTempPath()) $name
}

function Remove-SsfCookie {
    param([string]$Path)
    if ($Path -and (Test-Path -LiteralPath $Path)) {
        Remove-Item -LiteralPath $Path -Force
    }
}

function Invoke-SsfCurl {
    param(
        [Parameter(Mandatory)][string[]]$Arguments,
        [switch]$IgnoreExitCode
    )
    Assert-SsfCurl
    $output = & curl.exe @Arguments
    $exit = $LASTEXITCODE
    if ($exit -ne 0 -and -not $IgnoreExitCode) {
        throw "curl.exe misslyckades med exitkod $exit."
    }
    return $output
}

function Test-SsfDevUrl {
    param([Parameter(Mandatory)][string]$Url)
    $uri = [Uri]$Url
    if ($uri.Host -ne 'ssfb.se') {
        throw "Wrong host: $($uri.Host). Only ssfb.se is allowed for the DEV harness."
    }
    if ($uri.AbsoluteUri.TrimEnd('/') -eq 'https://ssfb.se') {
        throw 'PROD URL detected. The DEV harness refuses to run against https://ssfb.se without /dev.'
    }
    if (-not $uri.AbsolutePath.TrimEnd('/').Equals('/dev', [StringComparison]::OrdinalIgnoreCase)) {
        throw "DEV URL must be exactly https://ssfb.se/dev. Got: $Url"
    }
}

function ConvertTo-SsfStatus {
    param([bool]$Condition)
    if ($Condition) { 'PASS' } else { 'FAIL' }
}
