[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$failures = [Collections.Generic.List[string]]::new()

function Assert-Contains([string]$Name, [string]$Content, [string]$Expected) {
    if (-not $Content.Contains($Expected)) { $failures.Add("$Name saknar: $Expected") }
}

function Assert-NotContains([string]$Name, [string]$Content, [string]$Unexpected) {
    if ($Content.Contains($Unexpected)) { $failures.Add("$Name innehåller otillåtet: $Unexpected") }
}

function Assert-True([string]$Name, [bool]$Condition) {
    if (-not $Condition) { $failures.Add($Name) }
}

$form = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-medlemsprocess\templates\application-form.php')
$profile = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-medlemsfartyg\includes\class-ssf-medlemsfartyg-profile.php')
$public = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-medlemsprocess\includes\class-ssf-medlemsprocess-public.php')
$application = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-medlemsprocess\includes\class-ssf-medlemsprocess-application.php')
$pdf = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-medlemsprocess\includes\class-ssf-medlemsprocess-pdf.php')
$sharepoint = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-medlemsprocess\includes\class-ssf-medlemsprocess-sharepoint.php')
$configuration = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-member-portal\includes\Integrations\Microsoft365\Configuration.php')
$emails = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-medlemsprocess\includes\class-ssf-medlemsprocess-emails.php')
$organization = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\mu-plugins\ssf-organization-info.php')
$statusPage = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-medlemsprocess\templates\status-page.php')
$styles = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-medlemsprocess\assets\css\ssf-medlemsprocess.css')
$formScript = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-medlemsprocess\assets\js\ssf-medlemsprocess.js')
$admin = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-medlemsprocess\includes\class-ssf-medlemsprocess-admin.php')
$destinations = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-member-portal\includes\Integrations\Microsoft365\SharePointDestinations.php')
$inspector = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-medlemsprocess\includes\class-ssf-medlemsprocess-inspector.php')

Assert-True 'Formuläret ska ha sex steg' (([regex]::Matches($form, 'data-application-step=')).Count -eq 6)
foreach ($route in @('normal', 'small_registered', 'restoration', 'new_traditional')) { Assert-Contains "Medlemsväg $route" $profile "'$route' => array(" }
Assert-Contains 'Gemensam Vessel Profile' $form 'SSF_Medlemsfartyg_Profile::render'
Assert-Contains 'Sektioner identifierar gemensamma fält' $profile '$section_has_shared_fields = false;'
Assert-Contains 'Gemensamma fält håller sektionen synlig för alla vägar' $profile 'if ($section_has_shared_fields) {'
Assert-Contains 'Gemensam obligatorisk historik' $profile "'_ssf_history' => self::field('Fartygets historia och nuvarande användning', 'textarea', 'history', true, true, `$all"
foreach ($routeField in @('_ssf_professional_use', '_ssf_registration_type', '_ssf_restoration_condition', '_ssf_traditional_archetype')) { Assert-Contains "Vägspecifikt fält $routeField" $profile "'$routeField' => self::field(" }
foreach ($field in @('_ssf_owner', '_ssf_build_country', '_ssf_call_sign', '_ssf_length_overall', '_ssf_gross_tonnage', '_ssf_original_rig', '_ssf_sail_material')) { Assert-Contains "Fartygsfält $field" $profile "'$field'" }
foreach ($field in @('applicant_first_name', 'applicant_last_name', 'applicant_street', 'applicant_postal_code', 'applicant_city', 'applicant_invoice_email')) { Assert-Contains "Ombudsfält $field" $public "'$field'" }
Assert-Contains 'Obligatorisk fartygskategori' $profile "'_ssf_vessel_operation' => self::field('Fartygskategori', 'select', 'basic', true"
Assert-Contains 'Fritidsfartyg kan väljas' $profile "'leisure' => 'Fritidsfartyg'"
Assert-Contains 'Handelsfartyg kan väljas' $profile "'commercial' => 'Handelsfartyg'"
Assert-Contains 'Manipulerad fartygskategori avvisas' $profile "'select' === `$field['type'] && `$value && ! array_key_exists"
Assert-Contains 'Fritidsfartygets årsavgift' $organization "'500 kr/år per fartyg'"
Assert-Contains 'Handelsfartygets årsavgift' $organization "'1 500 kr/år per fartyg'"
Assert-Contains 'Betalningens bankgiro' $organization "'332-1908'"
Assert-Contains 'Betalningens Swishnummer' $organization "'1236400279'"
Assert-Contains 'Ansökningsmejl använder centrala avgifter' $emails 'SSF_Organization_Info::membership_fees()'
Assert-Contains 'Ansökningsnummer anges vid betalning' $emails 'som meddelande i betalningen.'
Assert-Contains 'Kontaktperson som rubrik' $form '<legend>Kontaktperson</legend>'
Assert-Contains 'Postadress i formuläret' $form '<span>Postadress</span>'
Assert-Contains 'Dolda steg och knappar' $styles '.ssf-process-form [hidden] { display: none !important; }'
Assert-Contains 'Nästa validerar aktuellt steg' $formScript 'if (!validate(steps[index])) return;'
Assert-Contains 'Dolda vägfält avaktiveras' $formScript 'control.disabled = !visible;'
Assert-Contains 'Endast synliga vägkrav är obligatoriska' $formScript 'control.required = visible;'
Assert-Contains 'Nästa döljs på sista steget' $formScript 'next.hidden = index === steps.length - 1;'
Assert-Contains 'Skicka visas på sista steget' $formScript 'submit.hidden = index !== steps.length - 1;'
Assert-Contains 'Komplettering via statuslänk' $statusPage '$can_complete'
Assert-Contains 'Begärd komplettering går tillbaka till granskning' $public "in_array(`$current_status, array('needs_completion', 'awaiting_completion'), true)"
Assert-Contains 'DOCX i formuläret' $form 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
Assert-Contains 'DOCX på servern' $public "'docx'"
Assert-Contains 'Submit-idempotens' $public 'ssf_application_submit_'
Assert-True 'Antispam ska köras före Application::create' ($public.IndexOf("SSF_Antispam::validate('membership_application')") -lt $public.IndexOf('SSF_Medlemsprocess_Application::create($data)'))
Assert-Contains 'PDF-signatur' $pdf '%PDF-1.4'
Assert-Contains 'PDF-ansökningsnummer' $pdf 'Ansökningsnummer'
Assert-Contains 'PDF-SSF-identitet' $pdf '(SSF) Tj'
foreach ($folder in @("'Bilder'", "'Bilagor'")) { Assert-Contains "SharePoint-mapp $folder" $sharepoint $folder }
foreach ($meta in @('_ssf_sp_application_folder_id', '_ssf_sp_application_list_item_id', '_ssf_sp_pdf_drive_item_id', '_ssf_sp_pdf_list_item_id')) { Assert-Contains "Stabilt Graph-ID $meta" $sharepoint $meta }
foreach ($meta in @('_ssf_sp_site_id', '_ssf_sp_drive_id', '_ssf_sp_list_id')) {
    Assert-Contains ('Ärendets SharePoint-ID {0}' -f $meta) $sharepoint $meta
}
Assert-Contains '30 minuters statussynk' $sharepoint '30 * MINUTE_IN_SECONDS'
Assert-Contains 'Direkt ListItem-läsning' $sharepoint "'/items/' . rawurlencode(`$list_item_id)"
Assert-Contains 'Direkt ListItem-skrivning' $sharepoint "'/items/' . rawurlencode(`$list_item_id) . '/fields'"
Assert-Contains 'Extern kommentar' $configuration 'metadata_application_public_comment_field'
Assert-True 'Statussidan får inte läsa intern SharePoint-kommentar' (-not $statusPage.Contains('internal_comment'))
Assert-Contains 'Generellt statusmail' $emails "'status_updated'"
Assert-Contains 'Idempotent statustransition' $application 'if ($old_status === $status)'
Assert-Contains 'Initial status Inkommen' $application "'_ssf_process_status', 'received'"
Assert-Contains 'Initial medlemsstatus Ej medlem' $application "'_ssf_membership_status', 'not_member'"
Assert-Contains 'Adminlänk-helper finns' $application 'public static function admin_url(int $application_id): string'
Assert-Contains 'Adminlänk använder WordPress edit-länk' $application 'get_edit_post_link($application_id, '''')'
Assert-Contains 'Adminlänk fallback via admin_url' $application "admin_url('post.php?post='"
Assert-NotContains 'Adminlänk får inte hårdkoda DEV' $application 'https://ssfb.se/dev/wp-admin'
Assert-NotContains 'Adminlänk får inte hårdkoda PROD' $application 'https://ssfb.se/wp-admin'
Assert-NotContains 'Adminlänk får inte kräva nonce' ([regex]::Match($application, '(?s)public static function admin_url.*?(?=public static function add_history)').Value) 'nonce'

$applicationStatuses = @('Inkommen', 'Under granskning', 'Begär komplettering', 'Väntar på komplettering', 'Inspektion ska bokas', 'Inspektion bokad', 'Under slutbedömning', 'Godkänd som aspirant', 'Avslagen')
foreach ($statusLabel in $applicationStatuses) { Assert-Contains "Ansökningsstatus $statusLabel" $sharepoint "'$statusLabel'" }
$membershipStatuses = @('Ej medlem', 'Aspirant', 'Uppföljning', 'Medlemsfartyg', 'Avslutad')
foreach ($statusLabel in $membershipStatuses) { Assert-Contains "Medlemsstatus $statusLabel" $application "'$statusLabel'" }

foreach ($field in @('ApplicationNumber', 'VesselName', 'ApplicationPath', 'ApplicationStatus', 'MembershipStatus', 'ReceivedDate', 'DecisionDate', 'AspirantStartDate', 'AspirantReviewDate', 'WordPressApplicationID')) {
    Assert-Contains "SharePoint-fält $field" $destinations "'$field'"
}
foreach ($key in @('metadata_application_membership_status_field', 'metadata_application_decision_date_field', 'metadata_application_aspirant_start_field', 'metadata_application_aspirant_review_field')) {
    Assert-Contains "Konfigurationsnyckel $key" $configuration "'$key' =>"
}
$createColumnCall = '$this->request(''POST'', $this->list_base($list_id) . ''/columns'''
$updateColumnCall = '$this->request(''PATCH'', $this->list_base($list_id) . ''/columns/'''
Assert-Contains 'Medlemsprocessen kan skapa saknade SharePoint-kolumner via reparation' $sharepoint $createColumnCall
Assert-Contains 'Medlemsprocessen kan lägga till saknade SharePoint-val via reparation' $sharepoint $updateColumnCall
Assert-Contains 'Schemareparation kräver bekräftelse' $admin 'confirm_schema'
Assert-Contains 'Schemareparation har separat admin action' $admin 'ssf_repair_application_sharepoint_schema'
Assert-Contains 'Schemareparation anger manage-roll vid 403' $sharepoint "`$data['required_site_role'] = 'manage'"
Assert-Contains 'ListItem-ID sparas innan schemakontroll' $sharepoint 'update_post_meta($application_id, ''_ssf_sp_application_list_item_id'''
$metadataFunction = $sharepoint.IndexOf('private function set_folder_metadata')
$listItemSave = $sharepoint.IndexOf('update_post_meta($application_id, ''_ssf_sp_application_list_item_id''', $metadataFunction)
$schemaCheck = $sharepoint.IndexOf('$schema = $this->ensure_schema();', $metadataFunction)
Assert-True 'ListItem-ID ska sparas före schemakontrollen' ($listItemSave -ge 0 -and $schemaCheck -gt $listItemSave)

Assert-Contains 'Beslutsdatum krävs' $application "'_ssf_decision_date_required'"
Assert-Contains 'Aspirantåret är ett år' $application "modify('+1 year')"
Assert-Contains 'Förfallen aspirant går till uppföljning' $application 'set_membership_status((int) $application_id, ''follow_up'', ''system'')'
Assert-Contains 'Ordinarie medlemskap kräver aktivt beslut' $admin '''member_ship'' === $membership_decision'
Assert-Contains 'Medlemsfartyg skapas först efter medlemsbeslut' $admin 'create_member_ship($post_id)'
Assert-Contains 'Saknat SharePoint-beslutsdatum varnar' $sharepoint 'Beslutsdatum saknas.'
Assert-Contains 'SharePoint-godkännande läser DecisionDate' $sharepoint 'metadata_application_decision_date_field'
Assert-Contains 'Aspirantbeslut kompletteras bara från Ej medlem' $sharepoint "'not_member' === `$membership_before"
Assert-Contains 'Historik sparar källa' $application '''source'' => $extra[''source'']'
Assert-Contains 'Historik sparar från-status' $application '''from_status'' => $old_status'
Assert-Contains 'Historik sparar ändringstid' $application "'changed_at' => current_time('mysql')"
Assert-Contains 'Statussidan skiljer ansökningsstatus' $statusPage '<strong>Ansökningsstatus</strong>'
Assert-Contains 'Statussidan skiljer medlemsstatus' $statusPage '<strong>Medlemsstatus</strong>'
Assert-Contains 'Statussidan visar aspirantförklaring' $statusPage 'Aspirantperioden är ett år.'
Assert-Contains 'Aspirantmail visar startdatum' $emails "'Aspirant från'"
Assert-Contains 'Aspirantmail visar uppföljningsdatum' $emails "'Planerat uppföljningsdatum'"
Assert-Contains 'Separat aspirantvy' $admin 'ssf-medlemsprocess-aspirants'
Assert-Contains '60-dagarsmarkering' $admin 'Kommande inom 60 dagar'
Assert-Contains '30-dagarsvarning' $admin 'Åtgärd inom 30 dagar'
Assert-Contains 'Slutförd inspektion går till slutbedömning' $inspector "transition(`$application_id, 'awaiting_decision'"
Assert-True 'Inspektören använder inte äldre slutförd-status' (-not $inspector.Contains("transition(`$application_id, 'inspection_completed'"))
foreach ($step in @('Autentisering', 'Site access', 'Drive access', 'List access', 'Läs kolumner', 'Hitta ärendemapp', 'Läs mappmetadata', 'Skriv mappmetadata')) { Assert-Contains "Diagnostik $step" $sharepoint "'$step'" }
Assert-Contains 'Teknisk HTTP-detalj' $sharepoint "'http_status'"
Assert-Contains 'Teknisk Graph-felkod' $sharepoint "'graph_code'"

& node --check (Join-Path $repo 'wp-content\plugins\ssf-medlemsprocess\assets\js\ssf-medlemsprocess.js')
if ($LASTEXITCODE -ne 0) { $failures.Add('JavaScript syntaxkontroll misslyckades') }

if ($failures.Count) {
    $failures | ForEach-Object { Write-Error $_ }
    exit 1
}

Write-Host 'PASS: medlemsansökans struktur, säkerhetsordning, PDF, SharePoint och statussynk.'
