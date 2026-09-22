[CmdletBinding()]
param()
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$emails = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-medlemsprocess\includes\class-ssf-medlemsprocess-emails.php')
if (-not $emails.Contains("'approved_aspirant' === `$status")) { throw 'Aspirantmail saknar avgränsad statuskontroll.' }
if (-not $emails.Contains('välkmonne')) { throw 'Aspirantmail tar inte bort den oönskade välkomstfrasen.' }
Write-Host 'PASS: aspirantmail behåller handläggarkommentaren men tar bort Varmt Välkmonne.'
