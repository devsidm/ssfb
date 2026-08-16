param(
  [string]$BaseUrl = "https://ssfb.se/dev",
  [string]$Username = "ssfhosting"
)

$ErrorActionPreference = "Stop"

if (-not $env:SSF_WP_APP_PASSWORD) {
  throw "Set SSF_WP_APP_PASSWORD before running this script."
}

function Invoke-WpApi {
  param(
    [string]$Path,
    [string]$Method = "GET",
    [object]$Body = $null
  )

  $pair = "${Username}:$env:SSF_WP_APP_PASSWORD"
  $auth = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($pair))
  $headers = @{ Authorization = "Basic $auth" }
  $uri = "$BaseUrl/wp-json$Path"

  if ($Body) {
    Invoke-RestMethod -Uri $uri -Method $Method -Headers $headers -ContentType "application/json; charset=utf-8" -Body ($Body | ConvertTo-Json -Depth 20)
  } else {
    Invoke-RestMethod -Uri $uri -Method $Method -Headers $headers
  }
}

function Get-PageBySlug {
  param([string]$Slug)
  $matches = Invoke-WpApi "/wp/v2/pages?slug=$Slug&context=edit"
  if ($matches.Count -gt 0) {
    return $matches[0]
  }
  return $null
}

function Upsert-Page {
  param(
    [string]$Title,
    [string]$Slug,
    [string]$Content
  )

  $existing = Get-PageBySlug $Slug
  $body = @{
    title = $Title
    slug = $Slug
    content = $Content
    status = "publish"
  }

  if ($existing) {
    Write-Host "Updating /$Slug/"
    Invoke-WpApi "/wp/v2/pages/$($existing.id)" "POST" $body | Out-Null
    return $existing.id
  }

  Write-Host "Creating /$Slug/"
  $created = Invoke-WpApi "/wp/v2/pages" "POST" $body
  return $created.id
}

$homeId = Upsert-Page "Hem" "hem" "<!-- wp:shortcode -->[ssf_home]<!-- /wp:shortcode -->"

Upsert-Page "Om SSF" "om-ssf" @"
<!-- wp:heading --><h2>Forbundet for Sveriges seglande kulturarv</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Sveriges Segelfartygsforbund arbetar for att bevara, bruka och utveckla traditionella segelfartyg och det maritima kulturarvet i Sverige.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>SSF samlar fartygsombud, stodmedlemmar och andra engagerade personer som vill ge segelfartygen goda forutsattningar att fortsatt synas, segla och underhallas.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h2>Det har arbetar SSF med</h2><!-- /wp:heading -->
<!-- wp:list --><ul><li>Samverkan mellan fartyg, medlemmar och organisationer.</li><li>Kunskap om traditionella segelfartyg och deras villkor.</li><li>Stod for medlemsfartyg och fartygsombud.</li><li>Synlighet for Sveriges maritima kulturarv.</li></ul><!-- /wp:list -->
"@

Upsert-Page "Medlemskap" "medlemskap" @"
<!-- wp:heading --><h2>Tva satt att vara med i SSF</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>I SSF ar medlemmen en person. Du kan vara stodmedlem eller fartygsombud for ett eller flera fartyg som provas enligt forbundets stadgar.</p><!-- /wp:paragraph -->
<!-- wp:heading {"level":3} --><h3>Stodmedlem</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Som stodmedlem bidrar du till SSF:s arbete for att bevara och utveckla Sveriges seglande kulturarv. Avgift anges enligt aktuell avgift.</p><!-- /wp:paragraph -->
<!-- wp:heading {"level":3} --><h3>Fartygsombud</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Ett fartygsombud foretrader ett eller flera fartyg. Fartyget behover godkannas eller ga vidare till sarskild provning innan det kan anslutas.</p><!-- /wp:paragraph -->
<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/dev/ansokan/">Ansok som fartygsombud</a></div><!-- /wp:button --></div><!-- /wp:buttons -->
"@

Upsert-Page "Ansokan" "ansokan" "<!-- wp:paragraph --><p>Har kan du testa om ditt fartyg kan ga vidare till ansokan som aspirant. Resultatet ar en forhandsbedomning. SSF:s styrelse provar ansokan enligt stadgarna.</p><!-- /wp:paragraph --><!-- wp:shortcode -->[ssf_application_form]<!-- /wp:shortcode -->"

Upsert-Page "Medlemsfartyg" "medlemsfartyg" "<!-- wp:paragraph --><p>Har samlas SSF:s medlemsfartyg. Layouten ar forberedd for fartygsnamn, fartygstyp, bild, beskrivning och lank.</p><!-- /wp:paragraph --><!-- wp:shortcode -->[ssf_member_vessels]<!-- /wp:shortcode -->"

Upsert-Page "Stadgar" "stadgar" @"
<!-- wp:heading --><h2>Stadgar och dokument</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Har finns plats for SSF:s stadgar, PDF-dokument och relaterade resurser.</p><!-- /wp:paragraph -->
<!-- wp:list --><ul><li>Stadgar</li><li>Avgifter</li><li>Ansokningsunderlag</li><li>Relaterade dokument</li></ul><!-- /wp:list -->
"@

Upsert-Page "Nyheter" "nyheter" "<!-- wp:paragraph --><p>Nyheter, information och evenemang fran Sveriges Segelfartygsforbund.</p><!-- /wp:paragraph --><!-- wp:latest-posts {`"displayPostDate`":true,`"displayFeaturedImage`":true,`"featuredImageSizeSlug`":`"medium_large`"} /-->"

Upsert-Page "Kontakta oss" "kontakta-oss" "<!-- wp:paragraph --><p>Hor av dig om medlemskap, fartygsombud, ansokan eller om du vill veta mer om SSF:s arbete.</p><!-- /wp:paragraph --><!-- wp:shortcode -->[ssf_contact_form]<!-- /wp:shortcode -->"

Invoke-WpApi "/wp/v2/settings" "POST" @{
  title = "Sveriges Segelfartygsforbund - SSF"
  description = "SSF samlar Sveriges traditionella segelfartyg, fartygsombud och stodmedlemmar for att bevara, bruka och utveckla det svenska segelfartygsarvet."
  page_on_front = $homeId
  show_on_front = "page"
} | Out-Null

Write-Host "Pages created or updated. Set the WordPress menu manually if the REST menu endpoint is unavailable."
