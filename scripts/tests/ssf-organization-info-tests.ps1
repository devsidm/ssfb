[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$failures = [Collections.Generic.List[string]]::new()

function Read-RepoFile([string]$Path) { Get-Content -Raw -LiteralPath (Join-Path $repo $Path) }
function Assert-Contains([string]$Name, [string]$Content, [string]$Expected) { if (-not $Content.Contains($Expected)) { $failures.Add("$Name saknar: $Expected") } }
function Assert-True([string]$Name, [bool]$Condition) { if (-not $Condition) { $failures.Add($Name) } }

$organization = Read-RepoFile 'wp-content\mu-plugins\ssf-organization-info.php'
$email = Read-RepoFile 'wp-content\mu-plugins\ssf-email-template.php'
$site = Read-RepoFile 'wp-content\plugins\ssf-site-customizations\includes\organization.php'
$plugin = Read-RepoFile 'wp-content\plugins\ssf-site-customizations\ssf-site-customizations.php'
$footer = Read-RepoFile 'wp-content\themes\ssf\footer.php'
$siteStyles = Read-RepoFile 'wp-content\plugins\ssf-site-customizations\assets\css\ssf-site.css'
$themeStyles = Read-RepoFile 'wp-content\themes\ssf\style.css'

foreach ($value in @('Sveriges Segelfartygsförbund','https://ssfb.se','C/O HSX 031W','BILLO','10646','STOCKHOLM','8328008605','332-1908','1236400279','200 kr/år','500 kr/år per fartyg','1 500 kr/år per fartyg')) {
    Assert-Contains "Central organisationsuppgift $value" $organization $value
}
Assert-Contains 'Identifierare saneras som text' $organization 'sanitize_text_field'
Assert-Contains 'E-post använder central källa' $email 'SSF_Organization_Info::get()'
Assert-Contains 'E-post använder centrala adressrader' $email 'SSF_Organization_Info::address_lines()'
Assert-Contains 'Webbplugin laddar organisationsmodulen' $plugin "includes/organization.php"
Assert-Contains 'Medlemssidan får avgiftssektion' $site "'medlemskap' === `$slug"
Assert-Contains 'Förbundssidan får organisationssektion' $site "'forbundet' === `$slug"
Assert-Contains 'Stödmedlem anger namn' $site 'Som stödmedlem anger du ditt namn'
Assert-Contains 'Fartygsansökan anger nummer' $site 'anger du ansökningsnumret'
Assert-Contains 'Sidsektioner läggs till före shortcodes' $site "add_filter('the_content', 'ssf_site_append_managed_information', 9)"
Assert-Contains 'Sidfot använder central källa' $footer 'SSF_Organization_Info::get()'
Assert-Contains 'Webbadress är klickbar i sidfot' $footer "`$ssf_organization['website_url']"
Assert-Contains 'Avgifter staplas responsivt' $siteStyles '.ssf-fee-grid,'
Assert-Contains 'Sidfot staplas responsivt' $themeStyles 'grid-template-columns: 1fr;'
Assert-True 'Gammal sammanslagen adress finns inte kvar' (-not ((Read-RepoFile 'wp-content\mu-plugins\ssf-email-template.php').Contains('HSX 031W BILLO')))

if ($failures.Count) {
    $failures | ForEach-Object { Write-Error $_ }
    exit 1
}

Write-Host 'PASS: centrala organisationsuppgifter, medlemsavgifter, sidfot och informationssidor.'
