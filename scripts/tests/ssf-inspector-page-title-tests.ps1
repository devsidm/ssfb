[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$theme = Get-Content -Raw -Encoding UTF8 -LiteralPath (Join-Path $repo 'wp-content\themes\ssf\index.php')
$portal = Get-Content -Raw -Encoding UTF8 -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-medlemsprocess\templates\inspector-portal.php')

if (-not $theme.Contains("has_shortcode(`$page_content, 'ssf_inspector_portal')")) {
    throw 'Temat måste känna igen inspektionsportalens egen rubrik.'
}
if (-not $theme.Contains('! $content_has_own_title')) {
    throw 'Temat måste dölja sidrubriken när kortkoden har en egen.'
}
if (-not $portal.Contains('<h1>Mina inspektioner</h1>')) {
    throw 'Inspektionsportalens egen huvudrubrik saknas.'
}

Write-Host 'PASS: Mina inspektioner har bara portalens huvudrubrik.'
