[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$failures = [Collections.Generic.List[string]]::new()

function Read-RepoFile([string]$Path) { Get-Content -Raw -LiteralPath (Join-Path $repo $Path) }
function Assert-Contains([string]$Name, [string]$Content, [string]$Expected) { if (-not $Content.Contains($Expected)) { $failures.Add("$Name saknar: $Expected") } }
function Assert-NotContains([string]$Name, [string]$Content, [string]$Unexpected) { if ($Content.Contains($Unexpected)) { $failures.Add("$Name innehåller otillåtet: $Unexpected") } }
function Assert-True([string]$Name, [bool]$Condition) { if (-not $Condition) { $failures.Add($Name) } }

$destinations = Read-RepoFile 'wp-content\plugins\ssf-member-portal\includes\Integrations\Microsoft365\SharePointDestinations.php'
$discovery = Read-RepoFile 'wp-content\plugins\ssf-member-portal\includes\Integrations\Microsoft365\SharePointDiscovery.php'
$admin = Read-RepoFile 'wp-content\plugins\ssf-member-portal\includes\Integrations\Microsoft365\SharePointAdmin.php'
$configuration = Read-RepoFile 'wp-content\plugins\ssf-member-portal\includes\Integrations\Microsoft365\Configuration.php'
$authentication = Read-RepoFile 'wp-content\plugins\ssf-member-portal\includes\Integrations\Microsoft365\Authentication.php'
$graph = Read-RepoFile 'wp-content\plugins\ssf-member-portal\includes\Integrations\Microsoft365\GraphClient.php'
$membership = Read-RepoFile 'wp-content\plugins\ssf-medlemsprocess\includes\class-ssf-medlemsprocess-sharepoint.php'
$motions = Read-RepoFile 'wp-content\plugins\ssf-member-portal\includes\Integrations\Microsoft365\SharePoint.php'
$motionSchema = Read-RepoFile 'wp-content\plugins\ssf-member-portal\includes\Integrations\Microsoft365\MotionSchema.php'
$motionRuntime = Read-RepoFile 'wp-content\plugins\ssf-member-portal\includes\Modules\Motions\MotionSharePoint.php'
$motionModule = Read-RepoFile 'wp-content\plugins\ssf-member-portal\includes\Modules\Motions\Module.php'
$meetingRegistration = Read-RepoFile 'wp-content\plugins\ssf-member-portal\includes\Modules\AnnualMeetings\RegistrationService.php'
$controller = Read-RepoFile 'wp-content\plugins\ssf-member-portal\includes\Modules\Motions\Admin\Controller.php'
$javascript = Read-RepoFile 'wp-content\plugins\ssf-member-portal\assets\js\sharepoint-admin.js'
$system = Read-RepoFile 'wp-content\plugins\ssf-member-portal\includes\Core\Plugin.php'

Assert-Contains 'Central option' $destinations "ssf_member_portal_sharepoint_destinations"
foreach ($destination in @('annual_meetings', 'membership_applications')) { Assert-Contains "Destination $destination" $destinations "'$destination' => array(" }
Assert-Contains 'Miljö från WordPress' $destinations "wp_get_environment_type()"
Assert-NotContains 'Ingen URL-baserad miljö' $destinations "contains('/dev/')"
Assert-Contains 'Separat development-profil' $destinations "'development'"
Assert-Contains 'Separat production-profil' $destinations "'production'"
Assert-Contains 'Idempotent migration' $destinations "schema_version"
Assert-Contains 'Migration till aktiv miljö' $destinations '$environment = self::environment();'
Assert-Contains 'DEV-skrivspärr' $destinations 'write_allowed_for_profile'
Assert-Contains 'Central delegation' $configuration 'SharePointDestinations::value'
Assert-Contains 'Publikt destinations-API' $configuration 'public static function destination(string $destination): array'
Assert-Contains 'Discovery-list-ID sparas centralt' $configuration "SharePointDestinations::save_field('annual_meetings', 'list_id'"
Assert-Contains 'Autentisering kräver endast credentials' $authentication 'Configuration::credential_missing()'

foreach ($endpoint in @("'/sites/root?`$select", "'/drives?`$select", "'/root?`$select=id,name,sharepointIds", "'/columns?`$select", "'/children'", "':/content'")) {
    Assert-Contains "Graph discovery $endpoint" $discovery $endpoint
}
Assert-Contains 'Segmentvis path-encoding' $discovery "array_map('rawurlencode'"
Assert-Contains 'Write cleanup' $discovery "request('DELETE'"
Assert-Contains 'Sites.Selected grant' $discovery "'grantedToIdentities'"
Assert-Contains '403-förklaring' $discovery "403 => 'Appen är autentiserad"
Assert-Contains '429-förklaring' $discovery 'Microsoft Graph begränsar'

Assert-Contains 'Admin capability' $admin 'current_user_can(Capabilities::MANAGE)'
Assert-Contains 'Admin nonce' $admin "check_ajax_referer('ssf_sharepoint_admin'"
Assert-Contains 'Microsoft 365-sidan köar sina admin-assets från render' $admin '$this->enqueue('''');'
Assert-Contains 'SharePoint admin-JS köas' $admin "assets/js/sharepoint-admin.js"
Assert-Contains 'SharePoint admin nonce lokaliseras' $admin "wp_create_nonce('ssf_sharepoint_admin')"
Assert-Contains 'Discovery via befintlig klient' $controller 'new SharePointAdmin(new GraphClient(new Authentication()))'
Assert-NotContains 'Ingen gammal site-ID-ruta' $controller 'name="graph[site_id]"'
Assert-Contains 'Systemstatus destinationer' $system 'SharePointDestinations::health'
Assert-Contains 'Medlemsansökans DEV-skydd' $membership "SharePointDestinations::write_allowed('membership_applications')"
Assert-Contains 'Motioner använder central site' $motions "Configuration::value('site_id')"
Assert-Contains 'Motioner använder central drive' $motions "Configuration::value('drive_id')"
Assert-Contains 'Motioner använder central mapp' $motions "Configuration::value('annual_meeting_folder_id')"
Assert-Contains 'Statusschema sparar misslyckad kontroll' $motionSchema "'last_checked_at'"
Assert-Contains 'Statusschema read-only column check' $motionSchema 'site-scoped access for reading list columns'
Assert-Contains 'Statusschema blockerar runtime-reparation' $motionSchema 'sharepoint_schema_write_disabled'
Assert-Contains 'Admin visar manuell statuskolumnsväg' $controller 'Alternativ: skapa statuskolumnen manuellt i SharePoint'
Assert-Contains 'Motionsuppladdning finns kvar' $motionRuntime 'upload_motion_attachment'
Assert-Contains 'Motionsstatussynk finns kvar' $motions 'get_motion_status'
Assert-NotContains 'Ingen Power Automate-adminpanel' $controller 'Power Automate'
Assert-NotContains 'Ingen Power Automate-webhook' $motionModule 'PowerAutomateWebhook'
Assert-NotContains 'Ingen publik statuswebhook' $system "'ssf-motions/v1'"
Assert-True 'Power Automate-klassen är borttagen' (-not (Test-Path -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-member-portal\includes\Modules\Motions\PowerAutomateWebhook.php')))
Assert-Contains 'Årsmötesexport finns kvar' $meetingRegistration 'upload_registration_excel'
Assert-NotContains 'Inget secret i admin-JS' $javascript 'client_secret'
Assert-True 'Endast GraphClient ska anropa wp_remote_request för Graph' (([regex]::Matches($discovery, 'wp_remote_')).Count -eq 0 -and ([regex]::Matches($admin, 'wp_remote_')).Count -eq 0 -and $graph.Contains('wp_remote_request'))

& node --check (Join-Path $repo 'wp-content\plugins\ssf-member-portal\assets\js\sharepoint-admin.js')
if ($LASTEXITCODE -ne 0) { $failures.Add('JavaScript syntaxkontroll misslyckades') }

if ($failures.Count) {
    $failures | ForEach-Object { Write-Error $_ }
    exit 1
}

Write-Host 'PASS: central SharePoint destination, DEV/PROD, discovery, säkerhet och admin-UX.'
