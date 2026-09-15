[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$file = Join-Path $repo 'wp-content\mu-plugins\ssf-dev-protection.php'
$php = Get-Content -Raw -LiteralPath $file
$results = [Collections.Generic.List[object]]::new()

function Assert-Contains {
    param([string] $Name, [string] $Needle)
    if (-not $php.Contains($Needle)) {
        throw "$Name misslyckades. Saknar: $Needle"
    }
    $results.Add([pscustomobject]@{ Test = $Name; Result = 'PASS' })
}

Assert-Contains 'Statusroute-normalisering finns' 'ssf_dev_protection_normalize_public_status_path'
Assert-Contains 'Subdirectory-prefix hanteras' 'array_slice($parts, 1)'
Assert-Contains 'Ansokan-status kan normaliseras' "'ansokan-status'"
Assert-Contains 'Motion-status kan normaliseras' "'motion-status'"
Assert-Contains 'Tokenrutt bevarar minimilängd för ansökan' 'strlen($token) >= 24'
Assert-Contains 'Tokenrutt bevarar minimilängd för motion' ''' !== trim($motion) && strlen($token) >= 24'

$results | Format-Table -AutoSize
