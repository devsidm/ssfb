[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('list','show','verify')]
    [string]$Command = 'list',
    [Parameter(Position = 1)]
    [string]$Reference = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

. (Join-Path $PSScriptRoot 'lib\ssf-test-harness.ps1')

$repo = Get-SsfRepoRoot
$registry = Read-SsfJson (Join-Path $repo 'config\test-fixtures.json')
$fixtures = @($registry.fixtures)

if ($Command -eq 'list') {
    $fixtures | Select-Object `
        @{Name='TYPE';Expression={$_.type}},
        @{Name='REFERENCE';Expression={$_.reference}},
        @{Name='WP ID';Expression={$_.wp_id}},
        @{Name='LIST ITEM';Expression={$_.sharepoint_list_item_id}},
        @{Name='DRIVE ITEM';Expression={$_.sharepoint_drive_item_id}},
        @{Name='KNOWN STATE';Expression={$_.known_state}},
        @{Name='REUSE POLICY';Expression={$_.reuse_policy}} | Format-Table -AutoSize
    Write-Host ''
    Write-Host 'Known state is inventory data and must be verified live before mutation.'
    exit 0
}

if (-not $Reference) {
    throw 'Ange fixture-reference, t.ex. SSF-2026-0025 eller 2026-004.'
}

$matches = @($fixtures | Where-Object { $_.reference -eq $Reference -or [string]$_.wp_id -eq $Reference })
if ($matches.Count -eq 0) {
    throw "Fixture saknas: $Reference"
}

if ($matches.Count -gt 1) {
        Write-Host "Multiple fixtures match $Reference. Show all fixtures and use WP ID for a unique selection."
}

foreach ($fixture in $matches) {
    $fixture | ConvertTo-Json -Depth 6
    Write-Host 'Known state is inventory data and must be verified live before mutation.'
    if ($Command -eq 'verify') {
        Write-Host 'Live verify is not implemented in version 1 because it must use a read-only WordPress runtime endpoint. Run ssf-dev-preflight.ps1 first.'
    }
}
