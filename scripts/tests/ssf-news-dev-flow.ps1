[CmdletBinding()]
param([switch]$ActivateOnly)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot '..\lib\ssf-test-harness.ps1')
Import-SsfSecrets
$repo = Get-SsfRepoRoot
$preflightScript = Join-Path $repo 'scripts\ssf-dev-preflight.ps1'
& $preflightScript -Json | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'DEV preflight must pass first.' }
$preflight = Read-SsfJson (Join-Path $repo 'artifacts\dev-preflight.json')
if ($preflight.result -ne 'PASS') { throw 'DEV preflight must pass first.' }
$baseUrl = [string](Get-SsfEnvironmentConfig -Environment dev).wordpress.url
Test-SsfDevUrl $baseUrl
$cookie = New-SsfCookiePath
$pageFile = Join-Path ([IO.Path]::GetTempPath()) ('ssf-news-dev-flow-' + $PID + '-' + [guid]::NewGuid().ToString('N') + '.html')
$artifactDir = Join-Path $repo 'artifacts\news-qa'
New-Item -ItemType Directory -Force -Path $artifactDir | Out-Null

function Request-NewsPage([string]$Path, [hashtable]$Data = @{}) {
    $args = @('-sS','--fail-with-body','--max-time','60','-L','-b',$cookie,'-c',$cookie,'-o',$pageFile,'-w','%{url_effective}')
    foreach ($key in $Data.Keys) { $args += @('--data-urlencode', ($key + '=' + [string]$Data[$key])) }
    $args += ($baseUrl.TrimEnd('/') + '/' + $Path.TrimStart('/'))
    $effective = & curl.exe @args
    $html = Get-Content -Raw -Encoding UTF8 -LiteralPath $pageFile
    if ($LASTEXITCODE -ne 0 -or $html -match 'Fatal error|Parse error|There has been a critical error') { throw ('DEV request failed: ' + $Path) }
    return @{ Html = $html; Url = [string]$effective }
}
function Nonce([string]$Html, [string]$Action) {
    foreach ($match in [regex]::Matches($Html, '(?s)<form\b.*?</form>')) {
        $form = $match.Value
        if ($form -notmatch ('name="action" value="' + [regex]::Escape($Action) + '"')) { continue }
        $value = [regex]::Match($form, 'name="_wpnonce" value="([^"]+)"').Groups[1].Value
        if ($value) { return $value }
    }
    throw ('Form nonce missing for action: ' + $Action)
}
function FormValue([string]$Html, [string]$Action, [string]$Name) {
    foreach ($match in [regex]::Matches($Html, '(?s)<form\b.*?</form>')) {
        $form = $match.Value
        if ($form -notmatch ('name="action" value="' + [regex]::Escape($Action) + '"')) { continue }
        $value = [regex]::Match($form, ('name="' + [regex]::Escape($Name) + '"[^>]*value="([^"]*)"')).Groups[1].Value
        if ($value) { return [Net.WebUtility]::HtmlDecode($value) }
    }
    throw ('Form value missing for action ' + $Action + ': ' + $Name)
}
function Assert-News([bool]$Condition, [string]$Description) {
    if (-not $Condition) { throw ('FAIL: ' + $Description) }
    Write-Output ('PASS: ' + $Description)
}

function Connect-NewsDev {
    & curl.exe -sS -c $cookie -o $pageFile ($baseUrl.TrimEnd('/') + '/wp-login.php')
    if ($LASTEXITCODE -ne 0) { throw 'Could not initialize the WordPress DEV login.' }
    & curl.exe -sS --fail-with-body --max-time 60 -L -b $cookie -c $cookie -o $pageFile `
        --data-urlencode ('log=' + [string]$env:SSF_WP_USER) `
        --data-urlencode ('pwd=' + [string]$env:SSF_WP_PASSWORD) `
        --data-urlencode 'wp-submit=Logga in' `
        --data-urlencode ('redirect_to=' + $baseUrl.TrimEnd('/') + '/wp-admin/plugins.php') `
        --data-urlencode 'testcookie=1' `
        ($baseUrl.TrimEnd('/') + '/wp-login.php')
    $html = Get-Content -Raw -Encoding UTF8 -LiteralPath $pageFile
    if ($LASTEXITCODE -ne 0 -or $html -notmatch 'data-plugin="ssf-arbetsyta/ssf-arbetsyta\.php"') {
        throw 'WordPress DEV login failed or the account cannot access plugins.'
    }
}

try {
Connect-NewsDev
$plugins = Request-NewsPage 'wp-admin/plugins.php'
$row = [regex]::Match($plugins.Html,'(?s)<tr[^>]*data-plugin="ssf-arbetsyta/ssf-arbetsyta.php".*?</tr>').Value
$activation = [regex]::Match($row,'href="([^"]*action=activate[^"]*)"').Groups[1].Value
if ($activation) {
    $activation = [Net.WebUtility]::HtmlDecode($activation)
    $uri = [Uri]::new([Uri]::new($baseUrl.TrimEnd('/') + '/wp-admin/plugins.php'), $activation)
    Assert-News ($uri.AbsoluteUri.StartsWith($baseUrl + '/wp-admin/')) 'activation targets DEV only'
    $result = Request-NewsPage ($uri.AbsolutePath.Substring('/dev/'.Length) + $uri.Query)
}
$workspace = Request-NewsPage 'arbetsyta/'
Assert-News ($workspace.Html -match 'ssf-workspace-main') 'workspace shell active'
$dashboard = Request-NewsPage 'arbetsyta/nyheter/'
Assert-News ($dashboard.Html -match 'ssf-news-heading') 'news dashboard is available'
if ($ActivateOnly) { return }

# Each run creates a dedicated DEV source. Existing workflow fixtures are not mutated.
$sourceStart = Request-NewsPage 'arbetsyta/nyheter/bevakning/?new=1'
$sourceForm = Request-NewsPage 'wp-admin/admin-post.php' @{
    action='ssf_news_source_analyze'; _wpnonce=(Nonce $sourceStart.Html 'ssf_news_source_analyze'); url='https://www.batliv.se/'
}
Assert-News ($sourceForm.Html -match 'ssf-workspace-confirmation' -and $sourceForm.Html -match 'ssf_news_source_save') 'monitoring source analysis reaches the save form'
$result = Request-NewsPage 'wp-admin/admin-post.php' @{
    action='ssf_news_source_save'; source_id='0'; _wpnonce=(Nonce $sourceForm.Html 'ssf_news_source_save')
    name=('Batliv DEV QA ' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
    base_url=(FormValue $sourceForm.Html 'ssf_news_source_save' 'base_url')
    monitor_url=(FormValue $sourceForm.Html 'ssf_news_source_save' 'monitor_url')
    method=(FormValue $sourceForm.Html 'ssf_news_source_save' 'method')
    keywords='ship,vessel,heritage,restoration,pilot'; active='1'; priority='1'; allow_preview='1'
}
Assert-News ($result.Html -match 'ssf-workspace-confirmation' -and $result.Html -match 'Batliv DEV QA') 'monitoring source saved in Workspace'
$sourceId = [regex]::Matches($result.Html,'nyheter/bevakning/(\d+)/') | ForEach-Object { [int]$_.Groups[1].Value } | Sort-Object -Descending | Select-Object -First 1
if (-not $sourceId) { throw 'Source ID missing.' }

$runToken = [guid]::NewGuid().ToString('N')
$articleUrl = 'https://www.batliv.se/feed/?ssf_dev_qa=' + $runToken
$manual = Request-NewsPage 'arbetsyta/nyheter/extern/'
$result = Request-NewsPage 'wp-admin/admin-post.php' @{ action='ssf_news_manual'; _wpnonce=(Nonce $manual.Html 'ssf_news_manual'); url=$articleUrl }
Assert-News ($result.Url -match '/nyheter/forslag/\d+/') 'manual Batliv URL creates a suggestion'
$suggestionId = [int][regex]::Match($result.Url,'/forslag/(\d+)/').Groups[1].Value
$suggestionNonce = Nonce $result.Html 'ssf_news_suggestion'
$result = Request-NewsPage 'wp-admin/admin-post.php' @{ action='ssf_news_suggestion'; _wpnonce=$suggestionNonce; suggestion_id=$suggestionId; intent='convert' }
Assert-News ($result.Url -match '/nyheter/medierna/\d+/') 'suggestion converts to the business editor'
$postId = [int][regex]::Match($result.Url,'/medierna/(\d+)/').Groups[1].Value
$saveNonce = Nonce $result.Html 'ssf_news_external_save'
$summary = 'SSF tipsar om Batlivs rapportering om aktuella fragor for sjofarten. Las den fullstandiga artikeln hos kallan.'
$save = @{ action='ssf_news_external_save'; _wpnonce=$saveNonce; post_id=$postId; mode='external'; title='DEV QA - Batliv i medierna'; summary=$summary; topics='Sjofart'; image_mode='external_preview'; intent='preview' }
$result = Request-NewsPage 'wp-admin/admin-post.php' $save
Assert-News ($result.Html -match 'ssf-news-card--media') 'saved draft preview uses the public card'
$save.intent='publish'
$result = Request-NewsPage 'wp-admin/admin-post.php' $save
Assert-News ($result.Html -match 'ssf-workspace-confirmation' -and $result.Html -match 'publicerad') 'publisher can publish without wp-admin editor'
$public = Request-NewsPage 'nyheter/?nyhetstyp=media'
Assert-News ($public.Html -match 'ssf-read-more--external' -and $public.Html -match 'ssf-news-card--media') 'public index shows external source and CTA'
$repeat = Request-NewsPage 'wp-admin/admin-post.php' @{ action='ssf_news_suggestion'; _wpnonce=$suggestionNonce; suggestion_id=$suggestionId; intent='convert' }
Assert-News ($repeat.Url -match ('/medierna/' + $postId + '/')) 'repeat conversion reuses canonical post'

$tipPage = Request-NewsPage 'arbetsyta/tipsa-om-artikel/'
$tipUrl = 'https://www.batliv.se/feed/?ssf_dev_tip_qa=' + $runToken
$tip = Request-NewsPage 'wp-admin/admin-post.php' @{ action='ssf_news_tip'; _wpnonce=(Nonce $tipPage.Html 'ssf_news_tip'); url=$tipUrl; comment='DEV QA member tip - same editorial queue.' }
Assert-News ($tip.Html -match 'ssf-workspace-confirmation') 'member tip form submits successfully'
$queue = Request-NewsPage 'arbetsyta/nyheter/forslag/?origin=member_tip'
Assert-News ($queue.Html -match 'Medlemstips' -and $queue.Html -match 'Granska') 'member tip reaches canonical queue'
$tipIds = [regex]::Matches($queue.Html,'nyheter/forslag/(\d+)/') | ForEach-Object { [int]$_.Groups[1].Value } | Sort-Object -Descending
$tipId = $tipIds | Select-Object -First 1
$review = Request-NewsPage ('arbetsyta/nyheter/forslag/' + $tipId + '/')
$dismiss = Request-NewsPage 'wp-admin/admin-post.php' @{ action='ssf_news_suggestion'; _wpnonce=(Nonce $review.Html 'ssf_news_suggestion'); suggestion_id=$tipId; intent='dismiss' }
Assert-News ($dismiss.Html -match 'inte relevant') 'editor dismisses member tip'
$duplicate = Request-NewsPage 'wp-admin/admin-post.php' @{ action='ssf_news_tip'; _wpnonce=(Nonce $tipPage.Html 'ssf_news_tip'); url=$tipUrl; comment='DEV QA duplicate' }
Assert-News ($duplicate.Html -match 'Artikeln finns redan') 'dismissed URL cannot become a fresh suggestion'

$sourceReview = Request-NewsPage 'arbetsyta/nyheter/bevakning/'
$sourceActionForm = [regex]::Matches($sourceReview.Html, '(?s)<form\b.*?</form>') | Where-Object {
    $_.Value -match ('name="source_id" value="' + $sourceId + '"')
} | Select-Object -First 1
if (-not $sourceActionForm) { throw ('Source action form missing for source: ' + $sourceId) }
$sourceNonce = Nonce $sourceActionForm.Value 'ssf_news_source_action'
$check = Request-NewsPage 'wp-admin/admin-post.php' @{ action='ssf_news_source_action'; _wpnonce=$sourceNonce; source_id=$sourceId; intent='check' }
Assert-News ($check.Html -match 'Kontrollen är klar|nya artikelförslag skapades|Kontrollen misslyckades') 'check now returns an understandable result'
@{ source_id=$sourceId; suggestion_id=$suggestionId; post_id=$postId; member_tip_id=$tipId; article_url=$articleUrl; post_title='DEV QA - Batliv i medierna' } | ConvertTo-Json | Set-Content -Encoding UTF8 -LiteralPath (Join-Path $artifactDir 'dev-flow-result.json')
} finally {
    Remove-SsfCookie -Path $cookie
    if (Test-Path -LiteralPath $pageFile) { Remove-Item -LiteralPath $pageFile -Force }
}
