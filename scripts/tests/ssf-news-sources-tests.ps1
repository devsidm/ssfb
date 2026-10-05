[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$service = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-arbetsyta\includes\class-ssf-news-service.php')
$styles = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-arbetsyta\assets\workspace.css')
$failures = [Collections.Generic.List[string]]::new()

function Assert-True([string] $name, [bool] $condition) {
    if (-not $condition) { $failures.Add($name) }
}

Assert-True 'Extern artikel och bevakningskälla har skilda åtgärder' ($service.Contains('Lägg till extern artikel') -and $service.Contains('Lägg till bevakningskälla'))
Assert-True 'Omvärldsbevakning förklarar förslag och publicering' ($service.Contains('Träffar blir artikelförslag') -and $service.Contains('Ingenting publiceras automatiskt'))
Assert-True 'Registret har alla statusfilter' ($service.Contains("array('all' => 'Alla', 'active' => 'Aktiva', 'paused' => 'Pausade', 'problem' => 'Problem', 'priority' => 'Prioriterade')"))
Assert-True 'Registret kan söka namn och URL' ($service.Contains('source_search') -and $service.Contains('Sök bland bevakningskällor'))
Assert-True 'Källkort visar URL typ status och kontrollresultat' ($service.Contains('<dt>Webbplats</dt>') -and $service.Contains('<dt>Typ</dt>') -and $service.Contains('<dt>Senast kontrollerad</dt>') -and $service.Contains('<dt>Senaste resultat</dt>'))
Assert-True 'Skapa börjar med säker webbplatsanalys' ($service.Contains("'ssf_news_source_analyze' => 'handle_source_analyze'") -and $service.Contains('Analysera webbplats') -and $service.Contains('self::safe_fetch($url, 2097152)'))
Assert-True 'Analys upptäcker RSS och faller tillbaka till nyhetssida' ($service.Contains("array('application/rss+xml', 'application/atom+xml')") -and $service.Contains("`$discovery_url = self::normalize_url"))
Assert-True 'Källa kan skapas och redigeras i samma säkra modell' ($service.Contains("'ssf_news_source_save' => 'handle_source_save'") -and $service.Contains('wp_insert_post($args, true)') -and $service.Contains('wp_update_post($args, true)'))
Assert-True 'Redigering omfattar alla redaktionella inställningar' ($service.Contains('Aktiv bevakning') -and $service.Contains('Prioriterad') -and $service.Contains('Tillåt extern förhandsvisningsbild') -and $service.Contains('Extra nyckelord/ämnen'))
Assert-True 'Paus och återaktivering behåller källan' ($service.Contains('Pausa bevakning') -and $service.Contains('Aktivera bevakning') -and -not $service.Contains('wp_delete_post($id'))
Assert-True 'Manuell kontroll tillåter pausad källa' ($service.Contains('self::check_source($id, true)') -and $service.Contains('if (! $manual'))
Assert-True 'Schemat kontrollerar bara aktiva källor två gånger dagligen' ($service.Contains("'meta_key' => '_ssf_source_active', 'meta_value' => '1'") -and $service.Contains("'twicedaily', self::CRON_HOOK"))
Assert-True 'Kontrollresultat är begripliga' ($service.Contains('Problem vid kontroll') -and $service.Contains('Inga nya artikelförslag'))
Assert-True 'Träffar använder befintliga artikelförslag och dubblettskydd' ($service.Contains("`$item['origin'] = 'monitoring'") -and $service.Contains('self::create_suggestion($item)') -and $service.Contains("'_ssf_suggestion_dedup'"))
Assert-True 'Källregistret är responsivt' ($styles.Contains('.ssf-source-toolbar') -and $styles.Contains('.ssf-source-search>div{flex-direction:column}'))

Assert-True 'Sitemap discovery checks standard, news and post sitemaps' ($service.Contains("'sitemap.xml'") -and $service.Contains("'news-sitemap.xml'") -and $service.Contains("'post-sitemap.xml'") -and $service.Contains('self::sitemap_items'))
Assert-True 'Manual fallback tests an HTML news page before it can be saved' ($service.Contains("'ssf_news_source_test_page' => 'handle_source_test_page'") -and $service.Contains('Testa sidan') -and $service.Contains('self::discovery_items'))
Assert-True 'Source data stores a monitoring method and URL in the existing source model' ($service.Contains("_ssf_source_method") -and $service.Contains("_ssf_source_monitor_url"))

if ($failures.Count) {
    $failures | ForEach-Object { Write-Error "FAIL: $_" }
    exit 1
}

Write-Host 'PASS: språk, källregister, webbplatsanalys, pausning och manuell kontroll.'
