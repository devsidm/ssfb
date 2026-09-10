[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$failures = [Collections.Generic.List[string]]::new()

function Read-RepoFile([string]$Path) { Get-Content -Raw -LiteralPath (Join-Path $repo $Path) }
function Assert-Contains([string]$Name, [string]$Content, [string]$Expected) { if (-not $Content.Contains($Expected)) { $failures.Add("$Name saknar: $Expected") } }
function Assert-NotContains([string]$Name, [string]$Content, [string]$Unexpected) { if ($Content.Contains($Unexpected)) { $failures.Add("$Name innehåller otillåtet: $Unexpected") } }
function Assert-True([string]$Name, [bool]$Condition) { if (-not $Condition) { $failures.Add($Name) } }

$template = Read-RepoFile 'wp-content\mu-plugins\ssf-email-template.php'
$organization = Read-RepoFile 'wp-content\mu-plugins\ssf-organization-info.php'
$controller = Read-RepoFile 'wp-content\plugins\ssf-member-portal\includes\Modules\Motions\Admin\Controller.php'
$annual = Read-RepoFile 'wp-content\plugins\ssf-member-portal\includes\Modules\AnnualMeetings\RegistrationMailer.php'
$motions = Read-RepoFile 'wp-content\plugins\ssf-member-portal\includes\Modules\Motions\MotionMailer.php'
$applications = Read-RepoFile 'wp-content\plugins\ssf-medlemsprocess\includes\class-ssf-medlemsprocess-emails.php'
$forms = Read-RepoFile 'wp-content\plugins\ssf-site-customizations\includes\forms.php'
$vesselForm = Read-RepoFile 'wp-content\plugins\ssf-medlemsfartyg\includes\class-ssf-medlemsfartyg-public-form.php'
$vesselTokens = Read-RepoFile 'wp-content\plugins\ssf-medlemsfartyg\includes\class-ssf-medlemsfartyg-tokens.php'

foreach ($type in @('motion_received','motion_status','application_received','application_status','application_completion','annual_meeting_registration','annual_meeting_registration_updated','contact_confirmation','vessel_update_invitation','vessel_update_received','inspector_assignment')) {
    Assert-Contains "Malltyp $type" $template "'$type' =>"
}
foreach ($value in @('Sveriges Segelfartygsförbund','role="presentation"','AltBody','[DEV] ','wp_enqueue_media','ssf_email_template_preview','ssf_email_template_test','SSF_Organization_Info::get()','SSF_Organization_Info::address_lines()')) {
    Assert-Contains "Central renderer $value" $template $value
}
foreach ($paymentValue in @('500 kr/år per fartyg','332-1908','1236400279')) {
    Assert-Contains "Central betalningsuppgift $paymentValue" $organization $paymentValue
}
Assert-Contains 'Ansökningsmejlets betalningsrubrik' $template 'Viktigt om betalningen'
Assert-Contains 'Central HTML-escaping' $template 'nl2br(esc_html($paragraph))'
Assert-Contains 'Central URL-escaping' $template 'esc_url($button_url)'
Assert-NotContains 'Ingen generell dynamisk kommentar' $applications "variables['comment']"
Assert-Contains 'Endast publik statuskommentar' $applications "variables['public_status_comment']"
Assert-NotContains 'Ingen intern kommentarsfallback' $applications "public_status_comment'] ?? `$variables['admin_comment"
Assert-Contains 'Admin visar e-postdesign' $controller 'SSF_Email_Template::render_admin_section'
Assert-Contains 'Årsmöte använder central mall' $annual 'SSF_Email_Template::send'
Assert-Contains 'Motion mottagen använder central mall' $motions "'motion_received'"
Assert-Contains 'Motion status använder central mall' $motions "'motion_status'"
Assert-Contains 'Ansökan använder central mall' $applications 'SSF_Email_Template::send'
Assert-Contains 'Kontaktbekräftelse finns' $forms "'contact_confirmation'"
Assert-Contains 'Fartygskvittens finns' $vesselForm "'vessel_update_received'"
Assert-Contains 'Fartygsinbjudan finns' $vesselTokens "'vessel_update_invitation'"
Assert-True 'Separat årsmötesmall är borttagen' (-not (Test-Path -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-member-portal\templates\emails\annual-meeting-confirmation.php')))

$directMailFiles = @(rg -l '\bwp_mail\s*\(' (Join-Path $repo 'wp-content') --glob '*.php' --glob '!vendor/**')
$allowed = @(
    (Join-Path $repo 'wp-content\mu-plugins\ssf-email-router.php'),
    (Join-Path $repo 'wp-content\mu-plugins\ssf-email-template.php'),
    (Join-Path $repo 'wp-content\plugins\ssf-office365-mailer\ssf-office365-mailer.php')
)
foreach ($file in $directMailFiles) { Assert-True "Direkt wp_mail är inte centraliserad: $file" ($allowed -contains $file) }

& node --check (Join-Path $repo 'wp-content\mu-plugins\assets\ssf-email-template-admin.js')
if ($LASTEXITCODE -ne 0) { $failures.Add('JavaScript syntaxkontroll misslyckades') }

if ($failures.Count) {
    $failures | ForEach-Object { Write-Error $_ }
    exit 1
}

Write-Host 'PASS: central SSF-e-postmall, säker rendering, admin och externa mailflöden.'
