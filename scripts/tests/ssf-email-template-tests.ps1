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
$sendMethod = [regex]::Match($template, '(?s)public static function send\(.*?(?=public static function render_admin_section)').Value
$headerMethod = [regex]::Match($template, '(?s)private static function render_header\(.*?(?=public static function render_text)').Value

foreach ($type in @('motion_received','motion_status','application_received','application_admin_notice','application_status','application_completion','annual_meeting_registration','annual_meeting_registration_updated','contact_confirmation','vessel_update_invitation','vessel_update_received','inspector_assignment')) {
    Assert-Contains "Malltyp $type" $template "'$type' =>"
}
foreach ($mapping in @(
    @('motion_received', "'category' => 'annual_meeting'"),
    @('motion_status', "'category' => 'annual_meeting'"),
    @('annual_meeting_registration', "'category' => 'annual_meeting'"),
    @('application_received', "'category' => 'membership'"),
    @('application_admin_notice', "'category' => 'membership'"),
    @('application_status', "'category' => 'membership'"),
    @('application_completion', "'category' => 'membership'"),
    @('inspector_assignment', "'category' => 'membership'"),
    @('contact_confirmation', "'category' => 'general'")
)) {
    $line = ($template -split "`n" | Where-Object { $_.Contains("'$($mapping[0])' =>") } | Select-Object -First 1)
    Assert-Contains "Kategori för $($mapping[0])" $line $mapping[1]
}
foreach ($value in @('Sveriges Segelfartygsförbund','role="presentation"','AltBody','[DEV] ','wp_enqueue_media','ssf_email_template_preview','ssf_email_template_test','SSF_Organization_Info::get()','SSF_Organization_Info::address_lines()')) {
    Assert-Contains "Central renderer $value" $template $value
}
foreach ($categoryValue in @("'annual_meeting'", "'membership'", "'general'", 'styrelsen@ssfb.se', 'medlem@ssfb.se', 'info@ssfb.se', 'Frågor om årsmötet?', 'Frågor om medlemskap?')) {
    Assert-Contains "Central kategori $categoryValue" $template $categoryValue
}
Assert-Contains 'HTML-sidfot har klickbar kontaktadress' $template 'href="mailto:<?php echo esc_attr($contact['
Assert-Contains 'Textsidfot använder samma kontakt' $template "`$contact['contact_email']"
Assert-Contains 'Reply-To sätts centralt' $template "`$headers[] = 'Reply-To: ' . `$contact['contact_email'];"
Assert-Contains 'Befintligt Reply-To bevaras' $template '$has_reply_to'
Assert-Contains 'Okänd typ använder allmän kategori' $template 'return self::GENERAL_CATEGORY;'
Assert-Contains 'Okänd typ loggas' $template 'email_template_unknown_type'
Assert-Contains 'Okänd typ kan skickas' $template "if (! is_email(`$recipient))"
Assert-NotContains 'Okänd typ blockeras inte i send' $sendMethod "! isset(self::templates()[`$template])"
Assert-Contains 'Admin visar e-postkategorier' $template 'E-postkategorier'
Assert-Contains 'Admin visar typmappning' $template '$types_by_category'
Assert-Contains 'Preview kan välja kategori' $template 'ssf-email-preview-category'
Assert-Contains 'Testmejl kan välja kategori' $template 'ssf-email-test-category'
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
Assert-Contains 'Central header-renderer' $template 'private static function render_header(array $brand): string'
Assert-Contains 'Alla HTML-mail använder header-renderern' $template 'echo self::render_header($brand)'
Assert-Contains 'Godkänd tagline' $template 'SVERIGES SEGLANDE KULTURARV'
Assert-NotContains 'Äldre tagline får inte användas' $template 'För en levande maritim kultur'
Assert-NotContains 'Mockupetikett får inte användas' $headerMethod 'Förslag 3'
Assert-Contains 'Header använder befintlig marinblå' $template "'primary_color' => '#12324a'"
Assert-Contains 'Outlook-kompatibel headerbakgrund' $headerMethod 'bgcolor="<?php echo esc_attr($brand['
Assert-Contains 'Tabellbaserad header' $headerMethod '<table role="presentation" width="100%"'
Assert-Contains 'Fysisk separatorcell' $headerMethod 'ssf-email-header-separator'
Assert-Contains 'Separator har egen bredd' $headerMethod 'width="1"'
Assert-Contains 'Logotypens HTML-bredd' $headerMethod 'width="145"'
Assert-Contains 'Logotypen behåller proportioner' $headerMethod 'height:auto'
Assert-Contains 'Tillgänglig alt-text' $headerMethod 'alt="Sveriges Segelfartygsförbund"'
Assert-Contains 'Organisationen är HTML-text' $headerMethod "header_line_2'"
Assert-Contains 'Tagline är HTML-text' $headerMethod "email_tagline'"
Assert-NotContains 'Ingen CSS-filterlogga' $headerMethod 'filter:'
Assert-Contains 'Mobil header staplas' $template '@media only screen and (max-width:480px)'
Assert-Contains 'Mobil separator döljs' $template '.ssf-email-header-separator{display:none!important'
Assert-Contains 'Originalbild används från Media Library' $template 'wp_get_attachment_url($logo_id)'
Assert-NotContains 'Ingen medium-thumbnail i headern' $template "wp_get_attachment_image_url(`$logo_id, 'medium')"
Assert-NotContains 'Ingen SVG-fallback i e-postmallen' $template 'ssf-logo.svg'
Assert-Contains 'Bild renderas endast med giltig URL' $headerMethod "if (`$brand['logo_url'])"
Assert-Contains 'Saknad logga loggas' $template 'email_header_logo_missing'
Assert-Contains 'Logovarning rensas även utan valt attachment' $template '} else {'
Assert-Contains 'Headertext saknar extra teckenavstånd' $headerMethod 'letter-spacing:0'
Assert-NotContains 'Headertext får inte ha extra teckenavstånd' $headerMethod 'letter-spacing:1px'
Assert-Contains 'Visuell identitet i admin' $template '<h3>Visuell identitet</h3>'
Assert-Contains 'Admin visar PNG-vägledning' $template 'Använd en vit PNG-logga med transparent bakgrund.'
Assert-Contains 'Admin visar rekommenderad Retina-bredd' $template 'Rekommenderad bredd minst 500 px'
Assert-Contains 'Admin väljer originalbild' (Read-RepoFile 'wp-content\mu-plugins\assets\ssf-email-template-admin.js') 'preview.src = attachment.url;'
Assert-Contains 'Årsmöte använder central mall' $annual 'SSF_Email_Template::send'
Assert-Contains 'Motion mottagen använder central mall' $motions "'motion_received'"
Assert-Contains 'Motion status använder central mall' $motions "'motion_status'"
Assert-Contains 'Ansökan använder central mall' $applications 'SSF_Email_Template::send'
Assert-Contains 'Adminansökan använder central HTML-mall' $applications "'application_admin_notice'"
Assert-Contains 'Adminansökan använder router' $applications "SSF_Email_Router::send_template_to_function('membership_application'"
Assert-Contains 'Adminansökan har WordPress-CTA' $applications "'Öppna ansökan i WordPress'"
Assert-Contains 'Adminansökan har idempotensflagga' $applications '_ssf_admin_new_application_notification_sent_at'
Assert-Contains 'Adminlänk genereras av WordPress-helper' $applications 'SSF_Medlemsprocess_Application::admin_url($application_id)'
Assert-Contains 'Adminnotis har ansökningsväg' $applications "'Ansökningsväg'"
Assert-Contains 'Adminnotis har medlemsstatus' $applications "'Medlemsstatus'"
Assert-NotContains 'Adminnotis får inte använda sökandetoken' ([regex]::Match($applications, '(?s)public function send_admin_notice.*?(?=public function send_status_email)').Value) 'status_link'
Assert-Contains 'Kontaktbekräftelse finns' $forms "'contact_confirmation'"
Assert-Contains 'Årsmötesfråga använder årsmöteskontakt' $forms "'category' => `$context ? 'annual_meeting' : 'general'"
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
