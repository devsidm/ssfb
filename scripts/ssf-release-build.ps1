[CmdletBinding()]
param(
    [string]$Description = '',
    [string]$Version = '',
    [string[]]$Components = @(),
    [ValidateSet('codex', 'manual', 'ci')]
    [string]$Source = 'codex'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$manifestPath = Join-Path $repo 'wp-content\mu-plugins\ssf-release-manifest.json'
$lockPath = Join-Path ([IO.Path]::GetTempPath()) ('ssf-release-' + [Convert]::ToBase64String(([Text.Encoding]::UTF8.GetBytes($repo))).Replace('/', '_').Replace('+', '-').TrimEnd('=') + '.lock')

function Get-ChangedComponents {
    param([string]$PreviousRevision)

    $paths = @()
    $revisionExists = $false
    if ($PreviousRevision) {
        git -C $repo cat-file -e "$PreviousRevision`^{commit}" 2>$null
        $revisionExists = $LASTEXITCODE -eq 0
    }
    if ($revisionExists) {
        $paths = @(git -C $repo diff --name-only "$PreviousRevision..HEAD")
    } else {
        $paths = @(git -C $repo diff-tree --no-commit-id --name-only -r HEAD)
    }

    $names = foreach ($path in $paths) {
        $normalized = $path -replace '\\', '/'
        if ($normalized -match '^wp-content/plugins/([^/]+)/') { $Matches[1]; continue }
        if ($normalized -match '^wp-content/themes/([^/]+)/') { $Matches[1]; continue }
        if ($normalized -match '^wp-content/mu-plugins/([^/]+)\.php$') { $Matches[1]; continue }
        if ($normalized -match '^scripts/') { 'release-tooling'; continue }
        if ($normalized -match '^docs/') { 'documentation'; continue }
    }
    return @($names | Where-Object { $_ } | Sort-Object -Unique)
}

$lock = $null
try {
    try {
        $lock = [IO.File]::Open($lockPath, [IO.FileMode]::OpenOrCreate, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None)
    } catch {
        throw 'En annan buildregistrering pågår. Försök igen när den är klar.'
    }

    $current = $null
    if (Test-Path -LiteralPath $manifestPath) {
        $current = Get-Content -Raw -LiteralPath $manifestPath | ConvertFrom-Json
    }

    $today = [DateTime]::UtcNow.ToString('yyyyMMdd')
    $sequence = 1
    if ($current -and ([string]$current.build) -match ('^' + $today + '\.(\d+)$')) {
        $sequence = [int]$Matches[1] + 1
    }
    $selectedVersion = if ($PSBoundParameters.ContainsKey('Version')) { $Version.Trim() } elseif ($current) { [string]$current.version } else { '' }
    if ($selectedVersion -and $selectedVersion -notmatch '^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*)?$') {
        throw 'Version måste följa Semantic Versioning.'
    }

    $revision = (git -C $repo rev-parse HEAD).Trim()
    if ($LASTEXITCODE -ne 0) { $revision = '' }
    $Components = @($Components | ForEach-Object { $_ -split ',' } | ForEach-Object { $_.Trim() } | Where-Object { $_ })
    if ($Components.Count -eq 0) {
        $previousRevision = if ($current) { [string]$current.source_revision } else { '' }
        $Components = @(Get-ChangedComponents -PreviousRevision $previousRevision)
    }

    $manifest = [ordered]@{
        schema_version = 1
        release_name = 'SSF Web'
        version = $selectedVersion
        build = "$today.$sequence"
        built_at = [DateTime]::UtcNow.ToString('o')
        source = $Source
        source_revision = $revision
        description = $Description.Trim()
        notes = if ($current) { [string]$current.notes } else { '' }
        components = @($Components | Where-Object { $_ } | Sort-Object -Unique)
        status = 'development'
        prepared_at = ''
    }

    $temporary = "$manifestPath.tmp"
    $json = $manifest | ConvertTo-Json -Depth 6
    [IO.File]::WriteAllText($temporary, $json + [Environment]::NewLine, [Text.UTF8Encoding]::new($false))
    Move-Item -LiteralPath $temporary -Destination $manifestPath -Force

    [pscustomobject]@{
        Environment = 'development'
        Version = if ($selectedVersion) { $selectedVersion } else { 'Ej förberedd' }
        Build = $manifest.build
        BuiltAtUtc = $manifest.built_at
        SourceRevision = $revision
        Components = $manifest.components -join ', '
    } | Format-List
} finally {
    if ($lock) { $lock.Dispose() }
}
