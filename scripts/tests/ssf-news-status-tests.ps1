[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$service = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-arbetsyta\includes\class-ssf-news-service.php')
$failures = [Collections.Generic.List[string]]::new()

function Assert-True([string] $name, [bool] $condition) {
    if (-not $condition) { $failures.Add($name) }
}

Assert-True 'Artikelförslag behåller intern WordPress-status' ($service.Contains("'post_type' => self::SUGGESTION_TYPE, 'post_status' => 'private'"))
Assert-True 'Private visas som Ej publicerad' ($service.Contains("'private' => 'Ej publicerad'"))
Assert-True 'Publicerad status är oförändrad' ($service.Contains("'publish' => 'Publicerad'"))
Assert-True 'Utkaststatus är oförändrad' ($service.Contains("'draft' => 'Utkast'"))
Assert-True 'Artikelförslag har egen verksamhetsstatus' ($service.Contains("private static function suggestion_status_label(): string") -and $service.Contains("return 'Artikelförslag';"))
Assert-True 'Status visas separat i förslagskort' ($service.Contains("<span>Status: ' . esc_html(self::suggestion_status_label())"))
Assert-True 'Rå rubrik används utan WordPress-prefix' ($service.Contains("<h2>' . esc_html(`$post->post_title)"))
Assert-True 'WordPress-formaterad titel används inte i Arbetsytans nyhetsrenderer' (-not $service.Contains('get_the_title($post)') -and -not $service.Contains('get_the_title($item)'))

if ($failures.Count) {
    $failures | ForEach-Object { Write-Error "FAIL: $_" }
    exit 1
}

Write-Host 'PASS: rena nyhetsrubriker och separata svenska statusetiketter utan ändrad synlighet.'
