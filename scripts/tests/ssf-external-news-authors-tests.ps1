[CmdletBinding()]
param()
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$source = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-site-customizations\includes\external-news-authors.php')
$failures = [Collections.Generic.List[string]]::new()
function Assert-Contains([string] $name, [string] $expected) { if (-not $source.Contains($expected)) { $failures.Add("$name saknar: $expected") } }
function Assert-NotContains([string] $name, [string] $unexpected) { if ($source.Contains($unexpected)) { $failures.Add("$name innehåller otillåtet: $unexpected") } }
Assert-Contains '256-bitars slump-token' 'random_bytes(32)'
Assert-Contains 'URL-säker token' "strtr(base64_encode(random_bytes(32)), '+/', '-_')"
Assert-Contains 'Endast hash lagras' "SSF_EXTERNAL_NEWS_HASH, hash('sha256', `$token)"
Assert-NotContains 'Rå token lagras inte som postmeta' "SSF_EXTERNAL_NEWS_TOKEN, `$token"
Assert-Contains 'Token är bunden till postens hash' "'meta_value' => hash('sha256', `$token)"
Assert-Contains 'Utgångstid är 14 dagar' '14 * DAY_IN_SECONDS'
Assert-Contains 'Återkallelse kontrolleras' 'SSF_EXTERNAL_NEWS_REVOKED'
Assert-Contains 'Extern route utan wp-admin' "'^skriv-nyhet/([^/]+)/?$'"
Assert-Contains 'Noindex' 'X-Robots-Tag: noindex, nofollow, noarchive'
Assert-Contains 'CSRF skydd' "wp_verify_nonce"
Assert-Contains 'Extern kan inte publicera' "'post_status' => 'submit' === `$intent ? 'pending' : 'draft'"
Assert-Contains 'Bildformat begränsas' "array('image/jpeg', 'image/png', 'image/webp')"
Assert-Contains 'Faktisk filtyp verifieras' 'wp_check_filetype_and_ext'
Assert-Contains 'Uppladdning kopplas till utkast' 'media_handle_sideload($file, $post_id)'
Assert-Contains 'Huvudbild sätts' 'set_post_thumbnail($post->ID, $image)'
Assert-Contains 'Galleri sparas på utkast' 'SSF_EXTERNAL_NEWS_GALLERY'
Assert-Contains 'Admin kan återkalla länk' 'ssf_external_news_revoke'
Assert-Contains 'Audit utan token' 'link_created'
if ($failures.Count) { $failures | ForEach-Object { Write-Error $_ }; exit 1 }
Write-Host 'PASS: extern nyhetslänk, scope, hash, utgång, återkallelse, upload- och publiceringsskydd.'
