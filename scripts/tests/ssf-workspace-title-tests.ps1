[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$workspace = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-arbetsyta\includes\class-ssf-workspace.php')
$services = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-arbetsyta\includes\class-ssf-workspace-services.php')
$inspector = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-medlemsprocess\includes\class-ssf-medlemsprocess-inspector.php')
$news = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-arbetsyta\includes\class-ssf-news-service.php')
$failures = [Collections.Generic.List[string]]::new()

function Assert-True([string] $name, [bool] $condition) {
    if (-not $condition) { $failures.Add($name) }
}

Assert-True 'Workspace använder rå posttitel' ($workspace.Contains('public static function display_title') -and $workspace.Contains('return $record ? (string) $record->post_title :'))
Assert-True 'Workspace ändrar inte WordPress globala titelformat' (-not $workspace.Contains('private_title_format') -and -not $services.Contains('private_title_format'))
Assert-True 'Mina uppgifter använder Workspace-titeln för interna ansökningar och inspektioner' ($services.Contains("SSF_Workspace::display_title(`$id)") -and -not $services.Contains("'title' => get_the_title(`$id)"))
Assert-True 'Inspektionsportalen använder Workspace-titeln i Workspace' ($inspector.Contains('return SSF_Workspace::display_title($application_id);') -and $inspector.Contains("strpos((string) get_query_var('ssf_workspace_path'), 'inspektioner')"))
Assert-True 'Tilldelad inspektion är separat verksamhetsstatus' ($services.Contains("'description' => 'Tilldelad inspektion'"))
Assert-True 'Artikelförslag har separat verksamhetsstatus' ($news.Contains('suggestion_status_label') -and $news.Contains("return 'Artikelförslag';"))
Assert-True 'Nyhetsrubriker använder rå posttitel' ($news.Contains('esc_html($post->post_title)') -and -not $news.Contains('get_the_title($post)'))
Assert-True 'Privata förslag och inspektionsposter behåller sina tekniska statusar' ($news.Contains("'post_status' => 'private'") -and $services.Contains("'post_status' => 'private'"))

if ($failures.Count) {
    $failures | ForEach-Object { Write-Error "FAIL: $_" }
    exit 1
}

Write-Host 'PASS: Workspace-titlar är rena och verksamhetsstatus visas separat.'
