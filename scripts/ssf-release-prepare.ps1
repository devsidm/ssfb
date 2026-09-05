[CmdletBinding(DefaultParameterSetName = 'Level')]
param(
    [Parameter(Mandatory, ParameterSetName = 'Level')]
    [ValidateSet('patch', 'minor', 'major')]
    [string]$Level,
    [Parameter(Mandatory, ParameterSetName = 'Version')]
    [ValidatePattern('^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$')]
    [string]$Version,
    [string]$Notes = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$manifestPath = Join-Path $repo 'wp-content\mu-plugins\ssf-release-manifest.json'
if (-not (Test-Path -LiteralPath $manifestPath)) {
    throw 'Release-manifest saknas. Registrera först en DEV-build.'
}

$manifest = Get-Content -Raw -LiteralPath $manifestPath | ConvertFrom-Json
if (-not ([string]$manifest.build -match '^\d{8}\.\d+$')) {
    throw 'Release-manifestet saknar ett giltigt buildnummer.'
}

if ($PSCmdlet.ParameterSetName -eq 'Version') {
    $nextVersion = $Version
} else {
    $current = ([string]$manifest.version -split '-', 2)[0]
    if ($current -notmatch '^(\d+)\.(\d+)\.(\d+)$') {
        throw 'Manifestet saknar basversion. Kör kommandot med -Version 1.0.0 första gången.'
    }
    $major = [int]$Matches[1]
    $minor = [int]$Matches[2]
    $patch = [int]$Matches[3]
    switch ($Level) {
        'major' { $nextVersion = ($major + 1).ToString() + '.0.0' }
        'minor' { $nextVersion = $major.ToString() + '.' + ($minor + 1).ToString() + '.0' }
        default { $nextVersion = $major.ToString() + '.' + $minor.ToString() + '.' + ($patch + 1).ToString() }
    }
}

$manifest.version = $nextVersion
$manifest.status = 'prepared'
$manifest.prepared_at = [DateTime]::UtcNow.ToString('o')
if ($PSBoundParameters.ContainsKey('Notes')) { $manifest.notes = $Notes.Trim() }

$temporary = "$manifestPath.tmp"
$json = $manifest | ConvertTo-Json -Depth 6
[IO.File]::WriteAllText($temporary, $json + [Environment]::NewLine, [Text.UTF8Encoding]::new($false))
Move-Item -LiteralPath $temporary -Destination $manifestPath -Force

[pscustomobject]@{
    Version = $nextVersion
    Build = $manifest.build
    Status = 'prepared'
    PreparedAtUtc = $manifest.prepared_at
} | Format-List
