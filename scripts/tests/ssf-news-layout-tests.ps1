[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$renderer = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-site-customizations\includes\shortcodes.php')
$service = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-arbetsyta\includes\class-ssf-news-service.php')
$styles = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-site-customizations\assets\css\ssf-site.css')
$pageBootstrap = Get-Content -Raw -LiteralPath (Join-Path $repo 'scripts\wp-rest-create-pages.ps1')
$failures = [Collections.Generic.List[string]]::new()

function Assert-True([string] $name, [bool] $condition) {
    if (-not $condition) { $failures.Add($name) }
}

$ssfSection = $renderer.IndexOf("ssf_site_home_news_section('ssf'")
$mediaSection = $renderer.IndexOf("ssf_site_home_news_section('media'")
Assert-True 'Startsidan visar SSF före I medierna' ($ssfSection -ge 0 -and $mediaSection -gt $ssfSection)
Assert-True 'Startsidan begränsar varje sektion till sex poster' ($renderer.Contains('min(6, max(1, $count))') -and $service.Contains("'ssf_count' => `$integer('ssf_count', 6, 6)"))
Assert-True 'SSF och externa nyheter frågas efter med canonical typmetadata' ($renderer.Contains("ssf_site_news_meta_query(`$type)") -and $renderer.Contains("array('ship', 'media'), 'compare' => 'NOT IN'"))
Assert-True 'Tom I medierna-sektion utelämnas' ($renderer.Contains('if (! $query->posts)') -and $renderer.Contains("if (`$news_settings['show_media'])"))
Assert-True 'CTA för I medierna behåller filtret' ($renderer.Contains("add_query_arg('nyhetstyp', 'media'") -and $renderer.Contains('Visa allt i medierna'))
Assert-True 'Desktopkolumner är valbara 1–3' ($service.Contains('foreach (array(1, 2, 3) as $columns)') -and $styles.Contains('.ssf-news-grid.ssf-news-grid--columns-3'))
Assert-True 'Tablet har högst två kolumner' ($styles.Contains('@media (max-width: 900px)') -and $styles.Contains('grid-template-columns: repeat(2, minmax(0, 1fr));'))
Assert-True 'Mobil har en kolumn' ($styles.Contains('@media (max-width: 640px)') -and $styles.Contains('.ssf-news-grid.ssf-news-grid--columns-2,'))
Assert-True 'Befintliga fyra filter finns kvar' ($renderer.Contains("'all' => 'Alla', 'ssf' => 'SSF-nyheter', 'ship' => 'Från medlemsfartygen', 'media' => 'I medierna'"))
Assert-True 'Utvald visas endast i Alla' ($renderer.Contains("if (`$show_filters && 'all' === `$filter)"))
Assert-True 'Utvald dupliceras inte i senaste grid' ($renderer.Contains("`$exclude = `$featured ? array((int) `$featured[0]->ID)"))
Assert-True 'Endast en nyhet kan vara utvald' ($service.Contains('delete_post_meta((int) $featured_id, self::META_FEATURED)'))
Assert-True 'Watermark-fallbacken finns kvar' ($styles.Contains('ssf-news-card--text::before') -and $styles.Contains('ssf-logo.svg'))
Assert-True 'Tom extern sammanfattning utelämnas' ($renderer.Contains("`$summary = '' !== `$excerpt ? '<p>'"))

Assert-True 'Nyhetssidans bootstrap skapar inte gamla Latest Posts-blocket' (-not $pageBootstrap.Contains('<!-- wp:latest-posts'))
Assert-True 'Befintligt Latest Posts-block tas bara bort från den kanoniska nyhetssidan' ($renderer.Contains("'core/latest-posts' !== (`$block['blockName'] ?? '')") -and $renderer.Contains("! is_page('nyheter')") -and $renderer.Contains("add_filter('render_block', 'ssf_site_remove_legacy_news_latest_posts', 10, 2)"))
Assert-True 'Nyhetssidans SSF-renderer finns kvar' ($renderer.Contains('do_shortcode(''[ssf_news_cards filters="1"]'')'))

if ($failures.Count) {
    $failures | ForEach-Object { Write-Error "FAIL: $_" }
    exit 1
}

Write-Host 'PASS: startsidans nyhetssektioner, inställningar, filter, utvald nyhet och responsivt grid.'
