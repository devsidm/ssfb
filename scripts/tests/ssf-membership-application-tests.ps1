[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$failures = [Collections.Generic.List[string]]::new()

function Assert-Contains([string]$Name, [string]$Content, [string]$Expected) {
    if (-not $Content.Contains($Expected)) { $failures.Add("$Name saknar: $Expected") }
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
$statusPage = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-medlemsprocess\templates\status-page.php')

Assert-True 'Formuläret ska ha sex steg' (([regex]::Matches($form, 'data-application-step=')).Count -eq 6)
foreach ($route in @('normal', 'small_registered', 'restoration', 'new_traditional')) { Assert-Contains "Medlemsväg $route" $profile "'$route' => array(" }
Assert-Contains 'Gemensam Vessel Profile' $form 'SSF_Medlemsfartyg_Profile::render'
foreach ($field in @('_ssf_owner', '_ssf_build_country', '_ssf_call_sign', '_ssf_length_overall', '_ssf_gross_tonnage', '_ssf_original_rig', '_ssf_sail_material')) { Assert-Contains "Fartygsfält $field" $profile "'$field'" }
foreach ($field in @('applicant_street', 'applicant_postal_code', 'applicant_city')) { Assert-Contains "Ombudsfält $field" $public "'$field'" }
Assert-Contains 'DOCX i formuläret' $form 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
Assert-Contains 'DOCX på servern' $public "'docx'"
Assert-Contains 'Submit-idempotens' $public 'ssf_application_submit_'
Assert-True 'Antispam ska köras före Application::create' ($public.IndexOf("SSF_Antispam::validate('membership_application')") -lt $public.IndexOf('SSF_Medlemsprocess_Application::create($data)'))
Assert-Contains 'PDF-signatur' $pdf '%PDF-1.4'
Assert-Contains 'PDF-ansökningsnummer' $pdf 'Ansökningsnummer'
Assert-Contains 'PDF-SSF-identitet' $pdf '(SSF) Tj'
foreach ($folder in @("'Bilder'", "'Bilagor'")) { Assert-Contains "SharePoint-mapp $folder" $sharepoint $folder }
foreach ($meta in @('_ssf_sp_application_folder_id', '_ssf_sp_application_list_item_id', '_ssf_sp_pdf_drive_item_id', '_ssf_sp_pdf_list_item_id')) { Assert-Contains "Stabilt Graph-ID $meta" $sharepoint $meta }
Assert-Contains '30 minuters statussynk' $sharepoint '30 * MINUTE_IN_SECONDS'
Assert-Contains 'Direkt ListItem-läsning' $sharepoint "'/items/' . rawurlencode(`$list_item_id)"
Assert-Contains 'Extern kommentar' $configuration 'metadata_application_public_comment_field'
Assert-True 'Statussidan får inte läsa intern SharePoint-kommentar' (-not $statusPage.Contains('internal_comment'))
Assert-Contains 'Generellt statusmail' $emails "'status_updated'"
Assert-Contains 'Idempotent statustransition' $application 'if ($old_status === $status)'
Assert-Contains 'Initial status Inkommen' $application "'_ssf_process_status', 'received'"

& node --check (Join-Path $repo 'wp-content\plugins\ssf-medlemsprocess\assets\js\ssf-medlemsprocess.js')
if ($LASTEXITCODE -ne 0) { $failures.Add('JavaScript syntaxkontroll misslyckades') }

if ($failures.Count) {
    $failures | ForEach-Object { Write-Error $_ }
    exit 1
}

Write-Host 'PASS: medlemsansökans struktur, säkerhetsordning, PDF, SharePoint och statussynk.'
