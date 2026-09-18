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
$plugin = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-medlemsprocess\includes\class-ssf-medlemsprocess-plugin.php')
$portal = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-medlemsprocess\includes\class-ssf-medlemsprocess-portal.php')
$pdf = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-medlemsprocess\includes\class-ssf-medlemsprocess-pdf.php')
$sharepoint = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-medlemsprocess\includes\class-ssf-medlemsprocess-sharepoint.php')
$configuration = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-member-portal\includes\Integrations\Microsoft365\Configuration.php')
$emails = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-medlemsprocess\includes\class-ssf-medlemsprocess-emails.php')
$organization = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\mu-plugins\ssf-organization-info.php')
$statusPage = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-medlemsprocess\templates\status-page.php')
$styles = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-medlemsprocess\assets\css\ssf-medlemsprocess.css')
$portalStyles = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-medlemsprocess\assets\css\ssf-membership-portal.css')
$formScript = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-medlemsprocess\assets\js\ssf-medlemsprocess.js')
$portalScript = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-medlemsprocess\assets\js\ssf-membership-portal.js')
$admin = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-medlemsprocess\includes\class-ssf-medlemsprocess-admin.php')
$destinations = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-member-portal\includes\Integrations\Microsoft365\SharePointDestinations.php')
$inspector = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-medlemsprocess\includes\class-ssf-medlemsprocess-inspector.php')
$archiveMigration = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-medlemsprocess\includes\class-ssf-medlemsprocess-archive-migration.php')
$adminNavigation = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\mu-plugins\ssf-admin-navigation.php')

Assert-True 'Formuläret ska ha sex steg' (([regex]::Matches($form, 'data-application-step=')).Count -eq 6)
Assert-Contains 'Ansökningsformuläret har egen H1' $form '<h1>Ansök om medlemskap för fartyg</h1>'
Assert-NotContains 'Ansökningsformuläret ska inte rendera SSF-eyebrow' $form 'ssf-process-eyebrow'
Assert-Contains 'Ansökans sidtitel uppdateras' $plugin "'ansokan' => array('Ansökan fartyg', '[ssf_application_form]')"
Assert-Contains 'Temat döljer sidtitel när ansökningsformuläret har egen H1' (Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\themes\ssf\index.php')) "has_shortcode(`$page_content, 'ssf_application_form')"
foreach ($route in @('normal', 'small_registered', 'restoration', 'new_traditional')) { Assert-Contains "Medlemsväg $route" $profile "'$route' => array(" }
foreach ($category in @('Kategori 1', 'Kategori 2', 'Kategori 3', 'Kategori 4')) { Assert-Contains "Synlig ansökningskategori $category" $form $category }
foreach ($categorySubtitle in @('Seglande yrkesfartyg som uppfyller måttkraven', 'Mindre registrerat fartyg', 'Fartyg under restaurering', 'Nybyggt traditionsfartyg')) { Assert-Contains "Synlig kategoriunderrubrik $categorySubtitle" $form $categorySubtitle }
Assert-Contains 'Kategori 2 beskriver måttkrav mot kategori 1' $form 'ett eller båda måttkraven i kategori 1'
foreach ($categoryAction in @('Välj kategori 1', 'Välj kategori 2', 'Välj kategori 3', 'Välj kategori 4')) { Assert-Contains "Synlig kategoriknapp $categoryAction" $form $categoryAction }
Assert-NotContains 'Ansökningskort ska inte rendera Läs mer' $form 'Läs mer'
Assert-NotContains 'Ansökningskort ska inte rendera Läs mindre' $form 'Läs mindre'
Assert-NotContains 'Ansökningskort ska inte rendera details-expandering' $form '<details>'
Assert-Contains 'Kategorikort har separat underrubrik' $form "'subtitle' =>"
Assert-Contains 'Kategorikort renderar underrubrik' $form 'ssf-route-card__subtitle'
Assert-Contains 'Kategorikort har rubrikstil' $styles '.ssf-route-card__title'
Assert-Contains 'Kategorikort har underrubrikstil' $styles '.ssf-route-card__subtitle'
foreach ($applicationPath in @('Normalfallet', 'Mindre registrerat fartyg', 'Fartyg under restaurering', 'Nybyggt traditionsfartyg')) { Assert-Contains "Internt ApplicationPath-värde $applicationPath" $profile $applicationPath }
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
Assert-Contains 'Frontend handläggningslänk-helper finns' $application 'public static function review_url(int $application_id): string'
Assert-Contains 'Frontend handläggningslänk används av adminmail' $emails 'SSF_Medlemsprocess_Application::review_url($application_id)'
Assert-NotContains 'Adminlänk får inte hårdkoda DEV' $application 'https://ssfb.se/dev/wp-admin'
Assert-NotContains 'Adminlänk får inte hårdkoda PROD' $application 'https://ssfb.se/wp-admin'
Assert-NotContains 'Adminlänk får inte kräva nonce' ([regex]::Match($application, '(?s)public static function admin_url.*?(?=public static function add_history)').Value) 'nonce'
Assert-NotContains 'Adminmailet ska inte primärt länka till wp-admin' ([regex]::Match($emails, '(?s)public function send_admin_notice.*?public function send_status_email').Value) 'SSF_Medlemsprocess_Application::admin_url'
Assert-Contains 'Adminmail CTA granskar ansökan' $emails "'button_label' => 'Granska ansökan'"

Assert-Contains 'Portal klass laddas' $plugin "'portal'"
Assert-Contains 'Portal sida installeras under medlemskap' $plugin "get_page_by_path('medlemskap/handlaggning')"
Assert-Contains 'Portal shortcode finns' $portal "add_shortcode('ssf_membership_review_portal'"
Assert-Contains 'Portal kräver inloggning' $portal 'is_user_logged_in()'
Assert-Contains 'Portal använder capability' $portal "current_user_can('ssf_view_applications')"
Assert-Contains 'Portal noindex' $portal 'noindex'
Assert-Contains 'Portal har Kanban' $portal 'render_kanban'
Assert-Contains 'Portal har aspirantvy' $portal 'render_aspirants'
Assert-Contains 'Portal använder befintlig statusmotor' $portal 'SSF_Medlemsprocess_Application::transition'
Assert-Contains 'Portal skyddar concurrency' $portal '$expected_status !== $current_status'
Assert-Contains 'Portal synkar SharePoint status' $portal 'sharepoint->push_status'
Assert-Contains 'Portal route för ärende' $portal "medlemskap/handlaggning/([^/]+)"
Assert-Contains 'Portal CSS laddas' $plugin 'ssf-membership-portal.css'
Assert-Contains 'Portal JS laddas' $plugin 'ssf-membership-portal.js'
Assert-Contains 'Portal DnD ändrar inte status direkt' $portalScript 'portal_message=drag_opened'
Assert-NotContains 'Portal JS får inte posta status vid drag' $portalScript 'fetch('
Assert-Contains 'Portal visuella statuschips' $portalStyles '.ssf-status-chip'

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
Assert-NotContains 'Medlemsprocessen får inte skapa SharePoint-kolumner i runtime' $sharepoint $createColumnCall
Assert-NotContains 'Medlemsprocessen får inte patcha SharePoint-val i runtime' $sharepoint $updateColumnCall
Assert-NotContains 'Schemaflödet får inte be om godkännande för runtime-reparation' $admin 'confirm_schema'
Assert-Contains 'Schemareparation har separat admin action' $admin 'ssf_repair_application_sharepoint_schema'
Assert-Contains 'Schemareparation blockerar med manuell åtgärd' $sharepoint 'manual_action_required'
Assert-Contains 'Status kan skrivas trots ofärdigt metadata-schema' $sharepoint "schema_field_ok(`$schema, 'application_status')"
Assert-Contains 'Ofärdigt schema lämnar varning efter statuspatch' $sharepoint 'return is_wp_error($schema) && ! is_wp_error($result) ? $schema : $result'
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

Assert-Contains 'Arkivflytt klass laddas' $plugin "'archive-migration'"
Assert-Contains 'Arkivflytt adminmeny finns' $admin 'ssf-application-archive-migration'
Assert-Contains 'Arkivflytt visas på ärendet' $admin 'render_application_box($post->ID)'
Assert-Contains 'Arkivflytt använder server-till-server Graph' $archiveMigration 'GraphClient(new \SSF\MemberPortal\Integrations\Microsoft365\Authentication())'
Assert-NotContains 'Arkivflytt får inte använda Microsoft ID Login-token' $archiveMigration 'microsoft-id-login'
Assert-Contains 'Arkivflytt har separat målkonfiguration' $archiveMigration "ssf_medlemsprocess_archive_migration"
Assert-Contains 'Arkivflytt mål är styrelsens SharePoint' $archiveMigration 'https://tradtionsfartyg.sharepoint.com/sites/styrelsen9'
Assert-Contains 'Arkivflytt målparent Medlemskap' $archiveMigration "'parent_folder_path' => 'General/Medlemskap'"
Assert-Contains 'Arkivflytt har separat slutmappsnamn' $archiveMigration "'destination_folder_name'"
Assert-Contains 'Arkivflytt kan slå upp site via URL' $archiveMigration 'site_lookup_path'
Assert-Contains 'Arkivflytt kan slå upp bibliotek via namn' $archiveMigration '/drives?$select=id,name,webUrl'
Assert-Contains 'Arkivflytt kan slå upp list-ID via drive' $archiveMigration '/list?$select=id,displayName,webUrl'
Assert-Contains 'Arkivflytt kan slå upp mapp via path' $archiveMigration '/root:/'
Assert-Contains 'Cutover sparar verifierad upplöst profil' $archiveMigration 'resolve_target(true)'
Assert-Contains 'Arkivflytt har guidad källväljare' $archiveMigration 'Välj källa'
Assert-Contains 'Arkivflytt har begripligt fel för saknad målmapp' $archiveMigration 'Målmappen hittades inte'
Assert-Contains 'Arkivflytt säger att katalogstruktur inte skapas automatiskt' $archiveMigration 'Verktyget skapar inte katalogstruktur, kolumner eller Choice-värden automatiskt'
Assert-Contains 'Arkivflytt kräver capability' $archiveMigration "current_user_can('ssf_manage_application_settings')"
Assert-Contains 'Arkivflytt kräver nonce' $archiveMigration 'check_admin_referer($nonce)'
Assert-Contains 'Readiness kontrollerar metadata' $archiveMigration '$this->metadata($target)'
Assert-Contains 'Arkivflytt inventerar fullständigt kolumnschema' $archiveMigration '$expand=sourceColumn'
Assert-NotContains 'Arkivflytt skickar inte ogiltig multiChoice-facet' $archiveMigration 'choice,multiChoice,number'
Assert-Contains 'Arkivflytt bevarar Choice displayAs' $archiveMigration "'displayAs' => `$display_as"
Assert-Contains 'Arkivflytt blockerar tvetydigt Choice-schema' $archiveMigration "`$schema_status = 'AMBIGUOUS'"
Assert-Contains 'Arkivflytt jämför på internt namn' $archiveMigration 'internal_name'
Assert-Contains 'Arkivflytt kan skapa saknade kolumner' $archiveMigration "`$this->request('POST', `$this->columns_path(`$target), `$payload)"
Assert-Contains 'Arkivflytt läser tillbaka skapad kolumn' $archiveMigration 'Read the created column back from SharePoint'
Assert-Contains 'Arkivflytt markerar konflikter för manuell kontroll' $archiveMigration 'CONFLICT - MANUELL KONTROLL KRÄVS'
Assert-Contains 'Arkivflytt upptäcker internt namnmismatch' $archiveMigration 'INTERNAL-NAME MISMATCH'
Assert-Contains 'Arkivflytt migrerar Choice-schema' $archiveMigration "'choice', 'multiChoice'"
Assert-Contains 'Komplexa kolumner blockeras' $archiveMigration "`$status = 'UNSUPPORTED'"
Assert-Contains 'Schema gate före datamigrering' $archiveMigration "get_option(self::SCHEMA_SYNC_OPTION, array())['verified']"
Assert-Contains 'Testärende gate före batch' $archiveMigration 'migrera och verifiera ett testärende först'
Assert-Contains 'Batch är begränsad' $archiveMigration '$count >= 5'
Assert-Contains 'Batch kan pausas' $archiveMigration "'paused'"
Assert-Contains 'Batch kan försöka fel igen' $archiveMigration "'retry'"
Assert-Contains 'Förhandsvisning skriver inte' $archiveMigration "'mode' => 'FÖRHANDSVISNING'"
Assert-Contains 'Förhandsvisning inspekterar faktisk källdata' $archiveMigration 'inspect_source_data'
Assert-Contains 'Förhandsvisning läser källfiler rekursivt' $archiveMigration 'count_source_files'
Assert-Contains 'Extra källfiler upptäcks' $archiveMigration 'EXTRA FILER'
Assert-Contains 'Avstämning kan exporteras' $archiveMigration 'Exportera avstämningsrapport'
foreach ($step in @('Graph authentication', 'Site access', 'Library access', 'Folder access', 'Create temporary test folder', 'Create small temporary test file', 'Write representative metadata using the migrated schema', 'Read file back', 'Read metadata back', 'Compare values', 'Delete test file', 'Delete test folder')) { Assert-Contains "Migreringsskrivtest $step" $archiveMigration $step }
Assert-Contains 'Skrivtest skapar temporär SSF-mapp' $archiveMigration 'SSF-TEST-'
Assert-Contains 'Skrivtest tar bort testmapp' $archiveMigration '$this->request(''DELETE'''
Assert-Contains 'Migrering blockerad utan readiness' $archiveMigration 'MIGRERING BLOCKERAD'
Assert-Contains 'Copy-before-switch sparar gamla referenser' $archiveMigration '_ssf_sp_migration_old_refs'
Assert-Contains 'Aktiva referenser byts efter verifiering' $archiveMigration '$verified = $this->verify_items'
Assert-True 'Verifiering ska ske före Site ID byts' ($archiveMigration.IndexOf('$verified = $this->verify_items') -lt $archiveMigration.IndexOf("update_post_meta(`$application_id, '_ssf_sp_site_id'"))
Assert-Contains 'Gamla arkivet markeras bevarat' $archiveMigration 'Gamla arkivet'
Assert-Contains 'Återställning raderar inga filer' $archiveMigration 'Inga filer raderades'
Assert-NotContains 'Arkivflytt får inte ändra e-postmottagare medlem' $archiveMigration 'medlem@ssfb.se'
Assert-NotContains 'Arkivflytt får inte ändra e-postmottagare styrelsen' $archiveMigration 'styrelsen@ssfb.se'
Assert-NotContains 'Arkivflytt får inte skicka e-post' $archiveMigration 'wp_mail('
Assert-NotContains 'Arkivflytt får inte ändra statusövergångar' $archiveMigration 'transition('
Assert-Contains 'Cutover är explicit' $archiveMigration 'Använd nya katalogen för nya medlemsansökningar'
Assert-Contains 'Gamla ärenden byter inte automatiskt vid cutover' $archiveMigration 'Befintliga ärenden byter inte automatiskt'

Assert-Contains 'Archive migration appears in System tabs' $adminNavigation "'ssf-application-archive-migration' => array('label' => 'Flytta kataloger'"
Assert-Contains 'Archive migration renders System tabs' $archiveMigration "render_system_tabs('ssf-application-archive-migration')"
Assert-True 'Archive migration has one H1 per selectable flow' (([regex]::Matches($archiveMigration, '<h1[ >]')).Count -eq 2)
Assert-Contains 'Archive migration uses compact admin steps' $archiveMigration 'ssf-archive-step__heading'

& node --check (Join-Path $repo 'wp-content\plugins\ssf-medlemsprocess\assets\js\ssf-medlemsprocess.js')
if ($LASTEXITCODE -ne 0) { $failures.Add('JavaScript syntaxkontroll misslyckades') }
& node --check (Join-Path $repo 'wp-content\plugins\ssf-medlemsprocess\assets\js\ssf-membership-portal.js')
if ($LASTEXITCODE -ne 0) { $failures.Add('Portalens JavaScript syntaxkontroll misslyckades') }

if ($failures.Count) {
    $failures | ForEach-Object { Write-Error $_ }
    exit 1
}

Write-Host 'PASS: medlemsansökans struktur, säkerhetsordning, PDF, SharePoint och statussynk.'
