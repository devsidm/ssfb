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
$deploy = Read-RepoFile 'config\deploy-components.json'
$expectedOrganisation = 'Sveriges Segelfartygsf' + [char]0x00f6 + 'rbund'
$expectedOrganisationHeading = 'Microsoft 365 ' + [char]0x2013 + ' organisation'
$expectedInvalidTenant = 'Microsoft k' + [char]0x00e4 + 'nner inte igen Tenant ID:t'
$expectedLegacyPreserved = 'Inga ' + [char]0x00e4 + 'ldre v' + [char]0x00e4 + 'rden har tagits bort.'

Assert-Contains 'Central tenant service exists' $central 'final class SSF_Microsoft365_Config'
Assert-Contains 'Central tenant option exists' $central 'ssf_microsoft365_tenant_configuration'
Assert-Contains 'Central tenant service is deployable' $deploy 'ssf-microsoft365-config.php'
Assert-Contains 'Central tenant getter exists' $central 'public static function get_tenant_id()'
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
Assert-NotContains 'Login has no duplicate tenant input' $login 'name="profiles[<?php echo esc_attr($profile_key); ?>][tenant_id]"'
Assert-Contains 'Login Client ID remains integration specific' $login "'client_id' => 'SSF_M365_LOGIN_CLIENT_ID'"
Assert-Contains 'Login Client Secret remains integration specific' $login "'client_secret' => 'SSF_M365_LOGIN_CLIENT_SECRET'"
Assert-Contains 'Login still validates state' $login 'STATE_PREFIX . $state'
Assert-Contains 'Login still validates nonce' $login "`$claims['nonce']"
Assert-Contains 'Login still uses PKCE' $login "'code_challenge_method' => 'S256'"
Assert-Contains 'Login still validates tid' $login "`$claims['tid']"
Assert-Contains 'Login still validates oid' $login "empty(`$claims['oid'])"

Assert-Contains 'SharePoint runtime reads central tenant' $sharepoint 'SSF_Microsoft365_Config::get_tenant_id()'
Assert-Contains 'SharePoint token endpoint reads central authority' $authentication 'SSF_Microsoft365_Config::get_authority_url'
Assert-Contains 'SharePoint Client ID remains integration specific' $sharepoint "'client_id' => 'SSF_GRAPH_CLIENT_ID'"
Assert-Contains 'SharePoint Client Secret remains integration specific' $sharepoint "'client_secret' => 'SSF_GRAPH_CLIENT_SECRET'"
Assert-Contains 'SharePoint permissions remain Sites.Selected' $discovery "'grantedToIdentities'"
Assert-Contains 'SharePoint destinations remain unchanged' $destinations 'ssf_member_portal_sharepoint_destinations'

Assert-Contains 'Legacy SharePoint tenant candidate detected' $central 'SSF_GRAPH_TENANT_ID'
Assert-Contains 'Legacy Login tenant candidate detected' $central 'SSF_M365_LOGIN_TENANT_ID'
Assert-Contains 'Legacy tenant values are not deleted' $central $expectedLegacyPreserved
Assert-Contains 'Conflicting tenant values are detected' $central "'status' => 'conflict'"
Assert-Contains 'Central non-empty tenant is preserved' $central "'' !== (string) (`$settings['profiles'][`$environment]['tenant_id'] ?? '')"
Assert-Contains 'Microsoft 365 admin renders central section' $admin 'SSF_Microsoft365_Config::render_admin_section()'
Assert-NotContains 'SharePoint UI no longer edits tenant' $admin 'name="graph[tenant_id]"'

if ($failures.Count) {
    $failures | ForEach-Object { Write-Error $_ }
    exit 1
}

Write-Host 'PASS: central Microsoft 365 tenant configuration and integration boundaries.'
