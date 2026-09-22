[CmdletBinding()]
param()
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$source = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-site-customizations\includes\external-news-authors.php')
$shortcodes = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-site-customizations\includes\shortcodes.php')
$theme = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\themes\ssf\functions.php')
$failures = [Collections.Generic.List[string]]::new()
function Assert-Contains([string] $name, [string] $value, [string] $sourceText = $source) { if (-not $sourceText.Contains($value)) { $failures.Add("$name saknar: $value") } }
function Assert-NotContains([string] $name, [string] $value) { if ($source.Contains($value)) { $failures.Add("$name innehåller otillåtet: $value") } }
Assert-Contains '256-bitars slump-token' 'random_bytes(32)'
Assert-Contains 'URL-säker token' "strtr(base64_encode(random_bytes(32)),'+/','-_')"
Assert-Contains 'Endast hash lagras' "hash('sha256',`$token)"
Assert-NotContains 'Rå token lagras inte som postmeta' 'SSF_EXTERNAL_NEWS_TOKEN'
Assert-Contains 'Utgångstid' 'SSF_EXTERNAL_NEWS_EXPIRES'
Assert-Contains 'Återkallelse kontrolleras' 'SSF_EXTERNAL_NEWS_REVOKED'
Assert-Contains 'Extern route utan wp-admin' "'^skriv-nyhet/([^/]+)/?$'"
Assert-Contains 'Noindex och no-cache' 'X-Robots-Tag: noindex, nofollow, noarchive'
Assert-Contains 'CSRF skydd' 'wp_verify_nonce'
Assert-Contains 'Extern kan inte publicera' "'pending':'draft'"
Assert-Contains 'Bildformat begränsas' "array('image/jpeg','image/png','image/webp')"
Assert-Contains 'Faktisk filtyp verifieras' 'wp_check_filetype_and_ext'
Assert-Contains 'Uppladdning kopplas till utkast' 'media_handle_sideload'
Assert-Contains 'Huvudbild sätts' 'set_post_thumbnail'
Assert-Contains 'Galleri sparas på utkast' 'SSF_EXTERNAL_NEWS_GALLERY'
Assert-Contains 'Mottagaradress sparas' 'SSF_EXTERNAL_NEWS_RECIPIENT'
Assert-Contains 'Centrala e-postmallen används' 'SSF_Email_Template::send'
Assert-Contains 'Omsändning finns' 'ssf_external_news_resend'
Assert-Contains 'Omsändning ersätter token' 'ssf_external_news_set_token'
Assert-Contains 'Förhandsgranskning finns' 'ssf_external_news_render_preview'
Assert-Contains 'Mobilförhandsgranskning finns' "'mobile'"
Assert-Contains 'Riktigt nyhetskort används' 'function ssf_site_render_news_card' $shortcodes
Assert-Contains 'Riktig artikelrendering används' 'function ssf_render_news_article' $theme
if ($failures.Count) { $failures | ForEach-Object { Write-Error $_ }; exit 1 }
Write-Host 'PASS: externa nyhetsinbjudningar, token-skydd, e-post, redigering och privata förhandsgranskningar.'
