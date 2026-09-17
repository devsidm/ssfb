[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$failures = [Collections.Generic.List[string]]::new()

function Read-RepoFile([string]$Path) { Get-Content -Raw -Encoding UTF8 -LiteralPath (Join-Path $repo $Path) }
function Assert-Contains([string]$Name, [string]$Content, [string]$Expected) { if (-not $Content.Contains($Expected)) { $failures.Add("$Name saknar: $Expected") } }
function Assert-NotContains([string]$Name, [string]$Content, [string]$Unexpected) { if ($Content.Contains($Unexpected)) { $failures.Add("$Name innehåller otillåtet: $Unexpected") } }

$central = Read-RepoFile 'wp-content\mu-plugins\ssf-microsoft365-config.php'
$login = Read-RepoFile 'wp-content\plugins\microsoft-id-login\microsoft-id-login.php'
$sharepoint = Read-RepoFile 'wp-content\plugins\ssf-member-portal\includes\Integrations\Microsoft365\Configuration.php'
$authentication = Read-RepoFile 'wp-content\plugins\ssf-member-portal\includes\Integrations\Microsoft365\Authentication.php'
$destinations = Read-RepoFile 'wp-content\plugins\ssf-member-portal\includes\Integrations\Microsoft365\SharePointDestinations.php'
$discovery = Read-RepoFile 'wp-content\plugins\ssf-member-portal\includes\Integrations\Microsoft365\SharePointDiscovery.php'
$admin = Read-RepoFile 'wp-content\plugins\ssf-member-portal\includes\Modules\Motions\Admin\Controller.php'
$sharepointAdmin = Read-RepoFile 'wp-content\plugins\ssf-member-portal\includes\Integrations\Microsoft365\SharePointAdmin.php'
$mailer = Read-RepoFile 'wp-content\plugins\ssf-office365-mailer\ssf-office365-mailer.php'
$deploy = Read-RepoFile 'config\deploy-components.json'
$expectedOrganisation = 'Sveriges Segelfartygsf' + [char]0x00f6 + 'rbund'
$expectedOrganisationHeading = 'Microsoft 365 ' + [char]0x2013 + ' organisation'
$expectedInvalidTenant = 'Microsoft k' + [char]0x00e4 + 'nner inte igen Tenant ID:t'
$expectedLegacyPreserved = 'Inga ' + [char]0x00e4 + 'ldre v' + [char]0x00e4 + 'rden har tagits bort.'
$expectedLegacyCentralWins = 'Runtime anv' + [char]0x00e4 + 'nder central Tenant ID; legacy-v' + [char]0x00e4 + 'rdet anv' + [char]0x00e4 + 'nds inte.'
$expectedLegacyUnused = 'Runtime anv' + [char]0x00e4 + 'nder inte legacy-v' + [char]0x00e4 + 'rdet.'

Assert-Contains 'Central tenant service exists' $central 'final class SSF_Microsoft365_Config'
Assert-Contains 'Central tenant option exists' $central 'ssf_microsoft365_tenant_configuration'
Assert-Contains 'Central tenant service is deployable' $deploy 'ssf-microsoft365-config.php'
Assert-Contains 'Central tenant getter exists' $central 'public static function get_tenant_id()'
Assert-NotContains 'Central tenant getter does not silently migrate legacy runtime values' $central "public static function get_tenant_id(): string`r`n    {`r`n        self::maybe_migrate_legacy_tenant();"
Assert-Contains 'Central primary domain getter exists' $central 'public static function get_primary_domain()'
Assert-Contains 'Central authority getter exists' $central 'public static function get_authority_host()'
Assert-Contains 'Central authority URL getter exists' $central 'public static function get_authority_url(string $path = '''')'
Assert-Contains 'Central configured resolver exists' $central 'public static function is_tenant_configured()'
Assert-Contains 'Expected organisation default exists' $central $expectedOrganisation
Assert-Contains 'Expected primary domain default exists' $central "'primary_domain' => 'ssfb.se'"
Assert-Contains 'Public cloud authority default exists' $central "'authority_host' => 'login.microsoftonline.com'"
Assert-Contains 'Authority server override is shown and locks UI' $central 'disabled($authority_override)'
Assert-NotContains 'Actual tenant ID is not hardcoded centrally' $central "'tenant_id' => 'ad928e8c-b976-4c84-a0b1-931341b5a512'"
Assert-Contains 'DEV and PROD use separate central profiles' $central "'production' === wp_get_environment_type() ? 'production' : 'development'"

Assert-Contains 'Central organisation admin section exists' $central $expectedOrganisationHeading
Assert-Contains 'Tenant test action exists' $central 'ssf_test_microsoft365_tenant'
foreach ($check in @('Tenant ID', 'OpenID discovery', 'Issuer', 'Authorization endpoint', 'Token endpoint', 'JWKS')) { Assert-Contains "Tenant check $check" $central "'label' => '$check'" }
Assert-Contains 'Invalid tenant diagnostic is safe and useful' $central "'invalid_tenant'"
Assert-Contains 'Invalid tenant Swedish explanation exists' $central $expectedInvalidTenant
Assert-NotContains 'Central page never renders client secret value' $central 'client_secret]</code>'

Assert-Contains 'Login runtime reads central tenant' $login 'SSF_Microsoft365_Config::get_tenant_id()'
Assert-Contains 'Login runtime reads central authority' $login 'SSF_Microsoft365_Config::get_authority_url($path)'
Assert-Contains 'Login runtime loads central MU config' $login 'ssf-microsoft365-config.php'
Assert-NotContains 'Login runtime has no legacy tenant constant mapping' $login "'tenant_id' => 'SSF_M365_LOGIN_TENANT_ID'"
Assert-NotContains 'Login has no duplicate tenant input' $login 'name="profiles[<?php echo esc_attr($profile_key); ?>][tenant_id]"'
Assert-Contains 'Login backend shows central tenant label' $login 'Central Microsoft 365 configuration'
Assert-Contains 'Login backend shows CONFIGURED state' $login "'CONFIGURED'"
Assert-Contains 'Login backend shows MISSING state' $login "'MISSING'"
Assert-Contains 'Login backend shows effective status' $login 'Effective status'
Assert-Contains 'Login Client ID remains integration specific' $login "'client_id' => 'SSF_M365_LOGIN_CLIENT_ID'"
Assert-Contains 'Login Client Secret remains integration specific' $login "'client_secret' => 'SSF_M365_LOGIN_CLIENT_SECRET'"
Assert-Contains 'Login Client ID can come from backend setting' $login "return is_string(`$profile[`$key] ?? null) ? trim((string) `$profile[`$key]) : '';"
Assert-Contains 'Login still validates state' $login 'STATE_PREFIX . $state'
Assert-Contains 'Login still validates nonce' $login "`$claims['nonce']"
Assert-Contains 'Login still uses PKCE' $login "'code_challenge_method' => 'S256'"
Assert-Contains 'Login still validates tid' $login "`$claims['tid']"
Assert-Contains 'Login still validates oid' $login "empty(`$claims['oid'])"

Assert-Contains 'SharePoint runtime reads central tenant' $sharepoint 'SSF_Microsoft365_Config::get_tenant_id()'
Assert-Contains 'SharePoint runtime loads central MU config' $sharepoint 'ssf-microsoft365-config.php'
Assert-Contains 'SharePoint token endpoint reads central authority' $authentication "Configuration::authority_url('/oauth2/v2.0/token')"
Assert-Contains 'SharePoint Client ID remains integration specific' $sharepoint "'client_id' => 'SSF_GRAPH_CLIENT_ID'"
Assert-Contains 'SharePoint Client Secret remains integration specific' $sharepoint "'client_secret' => 'SSF_GRAPH_CLIENT_SECRET'"
Assert-Contains 'SharePoint permissions remain Sites.Selected' $discovery "'grantedToIdentities'"
Assert-Contains 'SharePoint destinations remain unchanged' $destinations 'ssf_member_portal_sharepoint_destinations'
Assert-Contains 'Mailer runtime reads central tenant' $mailer 'SSF_Microsoft365_Config::get_tenant_id()'
Assert-Contains 'Mailer authority delegates centrally' $mailer 'SSF_Microsoft365_Config::get_authority_url($path)'
Assert-NotContains 'Mailer UI has no editable tenant' $mailer 'name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[tenant_id]"'
Assert-Contains 'Mailer Client ID remains integration specific' $mailer "'client_id' => sanitize_text_field"
Assert-Contains 'Mailer Client Secret remains integration specific' $mailer "'client_secret' => `$current['client_secret']"
Assert-NotContains 'Mailer secret value is never rendered' $mailer 'esc_attr($settings[''client_secret''])'

Assert-Contains 'Legacy SharePoint tenant candidate detected' $central 'SSF_GRAPH_TENANT_ID'
Assert-Contains 'Legacy Login tenant candidate detected' $central 'SSF_M365_LOGIN_TENANT_ID'
Assert-Contains 'Legacy tenant values are not deleted' $central $expectedLegacyPreserved
Assert-Contains 'Conflicting tenant values are detected' $central "'status' => 'conflict'"
Assert-Contains 'Legacy conflict warnings exist' $central 'public static function legacy_tenant_warnings()'
Assert-Contains 'Legacy conflict warning says central tenant wins' $central $expectedLegacyCentralWins
Assert-Contains 'Legacy missing-central warning says legacy unused' $central $expectedLegacyUnused
Assert-Contains 'Login renders legacy tenant warnings' $login 'legacy_tenant_warnings'
Assert-Contains 'Central non-empty tenant is preserved' $central "'' !== (string) (`$settings['profiles'][`$environment]['tenant_id'] ?? '')"
Assert-Contains 'Microsoft 365 admin renders central section' $admin 'SSF_Microsoft365_Config::render_admin_section()'
Assert-NotContains 'SharePoint UI no longer edits tenant' $admin 'name="graph[tenant_id]"'
foreach ($tab in @('overview', 'directory', 'integrations', 'diagnostics')) { Assert-Contains "Central tab $tab" $central "'$tab'" }
Assert-Contains 'Environment banner derives from WordPress' $central 'self::environment()'
Assert-Contains 'Login saves active environment only' $login 'foreach (array($this->active_profile_key()) as $profile_key)'
Assert-NotContains 'Login no longer offers production preconfiguration' $login 'Production kan förkonfigureras här.'
Assert-Contains 'SharePoint edits active environment only' $sharepointAdmin '$profile_environment = $current_environment;'
Assert-NotContains 'SharePoint has no environment selector tabs' $sharepointAdmin 'aria-label="Miljöprofil"'

if ($failures.Count) {
    $failures | ForEach-Object { Write-Error $_ }
    exit 1
}

Write-Host 'PASS: central Microsoft 365 tenant configuration and integration boundaries.'
