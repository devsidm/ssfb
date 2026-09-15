[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$file = Join-Path $repo 'wp-content\mu-plugins\ssf-dev-protection.php'
$loginProtectionFile = Join-Path $repo 'wp-content\mu-plugins\ssf-dev-login-protection.php'
$php = Get-Content -Raw -LiteralPath $file
$loginProtection = Get-Content -Raw -LiteralPath $loginProtectionFile
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
Assert-Contains 'Tokenvalidering lämnas till statuskontroller' "return in_array(`$request_path, array('ansokan-status', 'motion-status'), true);"

if (-not $loginProtection.Contains('ssf_dev_protection_is_public_status_route()')) {
    throw 'DEV login protection saknar public status route-undantag.'
}
$results.Add([pscustomobject]@{ Test = 'Extra DEV login protection respekterar statusrutter'; Result = 'PASS' })

$results | Format-Table -AutoSize
