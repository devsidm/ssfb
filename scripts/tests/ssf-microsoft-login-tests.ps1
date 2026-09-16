[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$pluginPath = Join-Path $repo 'wp-content\plugins\ssf-microsoft-login\ssf-microsoft-login.php'
$jsPath = Join-Path $repo 'wp-content\plugins\ssf-microsoft-login\assets\js\admin.js'
$docPath = Join-Path $repo 'docs\MICROSOFT-365-LOGIN-DEV.md'
$configPath = Join-Path $repo 'config\deploy-components.json'
$failures = [Collections.Generic.List[string]]::new()

function Fail([string]$Message) { $failures.Add($Message) | Out-Null }
function Assert-True([string]$Name, [bool]$Condition) { if (-not $Condition) { Fail $Name } }
function Assert-Contains([string]$Name, [string]$Content, [string]$Expected) { if (-not $Content.Contains($Expected)) { Fail "$Name missing: $Expected" } }
function Assert-NotContains([string]$Name, [string]$Content, [string]$Unexpected) { if ($Content.Contains($Unexpected)) { Fail "$Name contains forbidden text: $Unexpected" } }

$plugin = Get-Content -Raw -Encoding UTF8 -LiteralPath $pluginPath
$js = Get-Content -Raw -Encoding UTF8 -LiteralPath $jsPath
$doc = Get-Content -Raw -Encoding UTF8 -LiteralPath $docPath
$config = Get-Content -Raw -Encoding UTF8 -LiteralPath $configPath | ConvertFrom-Json

Assert-True 'Microsoft login plugin exists' (Test-Path -LiteralPath $pluginPath)
Assert-Contains 'Plugin header exists' $plugin 'Plugin Name: SSF Microsoft 365 Login'
Assert-Contains 'Initial plugin version' $plugin 'Version: 0.1.0'

Assert-Contains 'Disabled outside development' $plugin "'development' === wp_get_environment_type()"
Assert-Contains 'Explicit feature flag required' $plugin "SSF_M365_LOGIN_ENABLED"
Assert-Contains 'Tenant constant exists' $plugin "SSF_M365_LOGIN_TENANT_ID"
Assert-Contains 'Client ID constant exists' $plugin "SSF_M365_LOGIN_CLIENT_ID"
Assert-Contains 'Client secret constant exists' $plugin "SSF_M365_LOGIN_CLIENT_SECRET"
Assert-NotContains 'No SharePoint option reuse' $plugin 'ssf_member_portal_graph_configuration'
Assert-NotContains 'No SharePoint client constants' $plugin 'SSF_GRAPH_CLIENT_SECRET'

Assert-Contains 'Tenant specific authorize endpoint' $plugin "login.microsoftonline.com/"
Assert-Contains 'Authorization Code Flow response type' $plugin "'response_type' => 'code'"
Assert-Contains 'Authorization Code token grant' $plugin "'grant_type' => 'authorization_code'"
Assert-Contains 'Only identity scopes requested' $plugin "'scope' => 'openid profile email'"
Assert-NotContains 'No User.Read scope' $plugin 'User.Read'
Assert-NotContains 'No offline_access scope' $plugin 'offline_access'
Assert-NotContains 'No Sites permissions' $plugin 'Sites.'
Assert-NotContains 'No Files permissions' $plugin 'Files.'
Assert-NotContains 'No Mail permissions' $plugin 'Mail.'
Assert-Contains 'PKCE verifier stored' $plugin "'pkce_verifier'"
Assert-Contains 'PKCE challenge method S256' $plugin "'code_challenge_method' => 'S256'"
Assert-Contains 'State generated' $plugin '$state = $this->random_urlsafe(32)'
Assert-Contains 'Nonce generated' $plugin '$nonce = $this->random_urlsafe(32)'
Assert-Contains 'State transient one-time delete' $plugin 'delete_transient(self::STATE_PREFIX . $state)'
Assert-Contains 'Short transaction expiry' $plugin '10 * MINUTE_IN_SECONDS'
Assert-Contains 'Expired transaction rejected' $plugin 'time() - (int) ($transaction[''created''] ?? 0) > 10 * MINUTE_IN_SECONDS'

Assert-Contains 'ID token has three JWT parts' $plugin "3 !== count(`$parts)"
Assert-Contains 'RS256 required' $plugin "!== 'RS256'"
Assert-Contains 'JWKS discovery used' $plugin 'openid-configuration'
Assert-Contains 'JWKS URI used' $plugin '$metadata[''jwks_uri'']'
Assert-Contains 'OpenSSL signature verification' $plugin 'openssl_verify'
Assert-Contains 'Invalid signature rejected' $plugin 'ssf_m365_invalid_signature'
Assert-Contains 'Issuer validated' $plugin '$claims[''iss'']'
Assert-Contains 'Audience validated' $plugin '$claims[''aud'']'
Assert-Contains 'Expiration validated' $plugin '$claims[''exp'']'
Assert-Contains 'Not-before validated' $plugin '$claims[''nbf'']'
Assert-Contains 'Nonce validated' $plugin '$claims[''nonce'']'
Assert-Contains 'Tenant validated' $plugin '$claims[''tid'']'
Assert-Contains 'OID required' $plugin 'empty($claims[''oid''])'
Assert-Contains 'TID required' $plugin 'empty($claims[''tid''])'

Assert-Contains 'Uses tid user meta' $plugin "_ssf_m365_tid"
Assert-Contains 'Uses oid user meta' $plugin "_ssf_m365_oid"
Assert-Contains 'Maps by tid and oid' $plugin "'relation' => 'AND'"
Assert-Contains 'Logs mapped user in' $plugin 'wp_set_auth_cookie'
Assert-Contains 'Unmapped Microsoft user denied' $plugin 'inte kopplat till ett SSF-konto'
Assert-NotContains 'No automatic user creation' $plugin 'wp_create_user'
Assert-NotContains 'No user insert' $plugin 'wp_insert_user'
Assert-NotContains 'No role assignment' $plugin 'set_role'
Assert-NotContains 'Email not used in identity meta query' $plugin "'key' => 'user_email'"

Assert-Contains 'Account linking requires login' $plugin 'is_user_logged_in()'
Assert-Contains 'Linking requires nonce' $plugin "check_admin_referer('ssf_m365_link_start')"
Assert-Contains 'Linking preserves current user' $plugin 'get_current_user_id() !== $user_id'
Assert-Contains 'Duplicate identity blocked' $plugin 'redan kopplat till ett annat SSF-konto'
Assert-Contains 'Unlink requires nonce' $plugin "check_admin_referer('ssf_m365_unlink')"
Assert-Contains 'Unlink deletes tid' $plugin 'delete_user_meta(get_current_user_id(), self::META_TID)'
Assert-Contains 'Unlink deletes oid' $plugin 'delete_user_meta(get_current_user_id(), self::META_OID)'

Assert-Contains 'Login button rendered' $plugin 'Logga in med Microsoft 365'
Assert-Contains 'Login button hook preserves normal login form' $plugin "add_action('login_form'"
Assert-Contains 'Reusable login URL helper exists' $plugin 'public static function login_url'
Assert-Contains 'Safe redirect validation' $plugin 'wp_validate_redirect'
Assert-Contains 'Safe redirect execution' $plugin 'wp_safe_redirect'
Assert-Contains 'Clean callback rewrite' $plugin 'ssf-auth/microsoft/callback'
Assert-Contains 'Callback based on home_url' $plugin "home_url('/' . self::CALLBACK_PATH)"
Assert-Contains 'Rewrite flushed on activation' $plugin 'register_activation_hook'
Assert-NotContains 'No hardcoded dev path in plugin' $plugin 'ssfb.se/dev'

Assert-Contains 'Admin diagnostics page' $plugin 'Microsoft-inloggning'
Assert-Contains 'Metadata diagnostic' $plugin 'OpenID'
Assert-Contains 'JWKS diagnostic' $plugin 'JWKS'
Assert-Contains 'Secret displayed as configured only' $plugin "Client Secret"
Assert-Contains 'Backend settings option exists' $plugin 'ssf_microsoft_login_settings'
Assert-Contains 'Backend save action exists' $plugin 'ssf_m365_save_settings'
Assert-Contains 'Backend save action registered' $plugin "admin_post_ssf_m365_save_settings"
Assert-Contains 'Settings save requires login management capability' $plugin 'save_settings'
Assert-Contains 'Settings save requires nonce' $plugin "check_admin_referer('ssf_m365_save_settings')"
Assert-Contains 'Development profile exists' $plugin "'development'"
Assert-Contains 'Production profile exists' $plugin "'production'"
Assert-Contains 'Active profile selected from WP environment' $plugin 'active_profile_key'
Assert-Contains 'Production profile editable in UI' $plugin "__('Production'"
Assert-Contains 'Client ID editable in UI' $plugin 'Application ID / Client ID'
Assert-Contains 'Client secret password field' $plugin 'type="password"'
Assert-Contains 'Secret blank preserves existing value' $plugin 'Secret finns -'
Assert-Contains 'Secret can be cleared explicitly' $plugin 'clear_secret'
Assert-Contains 'Settings update does not autoload secrets' $plugin 'update_option(self::SETTINGS_OPTION, $current, false)'
Assert-Contains 'Server constants remain emergency override' $plugin 'SSF_M365_LOGIN_CLIENT_ID'
Assert-NotContains 'No token HTML output' $plugin 'id_token</'
Assert-NotContains 'No raw JWT logging' $plugin 'error_log($jwt'
Assert-NotContains 'No secret logging' $plugin 'error_log($this->config(''client_secret'')'

Assert-True 'Plugin is excluded from production plugin list' (-not (@($config.production.plugins) -contains 'ssf-microsoft-login'))
Assert-True 'Plugin is listed DEV-only' (@($config.plugin_policy.dev_only) -contains 'ssf-microsoft-login')
Assert-True 'Plugin is production-excluded' (@($config.excluded.plugins) -contains 'ssf-microsoft-login')
$devOnlyPolicy = @{}
@($config.plugin_policy.dev_only) | ForEach-Object { $devOnlyPolicy[$_] = $true }
$activeDevPilot = @{ name = 'ssf-microsoft-login'; status = 'active'; version = '0.1.0' }
$missingProdPilot = $null
$pilotClassification = if ($devOnlyPolicy.ContainsKey($activeDevPilot.name)) { 'DEV_ONLY_ALLOWED' } elseif ($activeDevPilot.status -eq 'active' -and -not $missingProdPilot) { 'DEV_ACTIVE_PROD_MISSING' } else { 'MATCH' }
Assert-True 'Active DEV pilot does not cause PROD copy/activate action' ($pilotClassification -eq 'DEV_ONLY_ALLOWED')

Assert-Contains 'Admin menu under SSF system' $plugin 'SSF_Admin_Navigation::ROOT'
Assert-Contains 'Admin submenu label' $plugin "__('Inloggning'"
Assert-Contains 'Manage login capability' $plugin 'ssf_manage_microsoft_login'
Assert-Contains 'Manage permission groups capability' $plugin 'ssf_manage_permission_groups'
Assert-Contains 'Admin assets loaded only on plugin page' $plugin "assets/js/admin.js"
Assert-Contains 'Callback copy button exists' $plugin 'data-ssf-copy="#ssf-m365-callback"'
Assert-Contains 'Own account card exists' $plugin 'render_own_account_card'
Assert-Contains 'Linked user list exists' $plugin 'render_users_and_permissions'
Assert-Contains 'Linked users filter exists' $plugin 'ssf_m365_filter'
Assert-Contains 'Admin unlink action exists' $plugin 'ssf_m365_admin_unlink'
Assert-Contains 'Admin unlink capability protected' $plugin 'can_manage_login()'
Assert-Contains 'Admin unlink nonce per user' $plugin 'ssf_m365_admin_unlink_'
Assert-Contains 'Admin unlink deletes tid' $plugin 'delete_user_meta($user_id, self::META_TID)'
Assert-Contains 'Admin unlink deletes oid' $plugin 'delete_user_meta($user_id, self::META_OID)'
Assert-Contains 'Admin unlink deletes stored email label' $plugin 'delete_user_meta($user_id, self::META_EMAIL)'
Assert-Contains 'Admin unlink does not alter groups notice' $plugin 'WordPress-behorigheter andrades inte'

Assert-Contains 'Technical connection test action' $plugin 'ssf_m365_test_config'
Assert-Contains 'Connection checks persisted' $plugin "self::TEST_PREFIX . 'config_'"
Assert-Contains 'Connection checks function exists' $plugin 'run_connection_checks'
Assert-Contains 'Connection test checks DEV environment' $plugin "'DEV-miljo'"
Assert-Contains 'Connection test checks issuer' $plugin "'Issuer matchar tenant'"
Assert-Contains 'Connection test checks JWKS' $plugin "'JWKS/signeringsnycklar'"
Assert-Contains 'Connection test states no Graph/SharePoint permissions needed' $plugin 'Inga Graph- eller SharePoint-behorigheter behovs'

Assert-Contains 'Real login test action' $plugin 'ssf_m365_test_login'
Assert-Contains 'Real login test mode started' $plugin "start_authorization('test'"
Assert-Contains 'Real login test callback branch' $plugin "'test' === (`$transaction['mode'] ?? '')"
Assert-Contains 'Real login test function exists' $plugin 'complete_real_login_test'
Assert-Contains 'Real login test stores transient' $plugin "self::TEST_PREFIX . 'login_'"
Assert-Contains 'Real login test verifies linked account' $plugin 'Microsoft-identitet matchar kopplat konto'
Assert-Contains 'Real login test records unchanged permissions' $plugin 'WordPress-behorigheter oforandrade'

Assert-Contains 'Permission group model exists' $plugin 'permission_groups'
Assert-Contains 'Permission groups stored in user meta' $plugin '_ssf_permission_groups'
Assert-Contains 'Permission groups granted by user_has_cap' $plugin "add_filter('user_has_cap'"
Assert-Contains 'Permission save action exists' $plugin 'ssf_save_permission_groups'
Assert-Contains 'Profile permission save exists' $plugin 'save_profile_groups'
Assert-Contains 'Permission audit option exists' $plugin 'ssf_microsoft_login_permission_audit'
Assert-Contains 'Permission audit records actor' $plugin "'actor_user_id'"
Assert-Contains 'Permission audit records target' $plugin "'target_user_id'"
Assert-Contains 'Permission audit records added' $plugin "'added'"
Assert-Contains 'Permission audit records removed' $plugin "'removed'"
Assert-Contains 'Permission audit records timestamp' $plugin "'timestamp'"
Assert-Contains 'Applications group includes review capability' $plugin 'ssf_review_applications'
Assert-Contains 'Applications group includes decision capability' $plugin 'ssf_decide_applications'
Assert-Contains 'Inspector group includes assigned applications capability' $plugin 'ssf_view_assigned_applications'
Assert-Contains 'Motion group includes motion capability' $plugin 'ssf_manage_motions'
Assert-Contains 'Annual meetings group includes annual meeting capability' $plugin 'manage_ssf_annual_meetings'
Assert-Contains 'System group includes release capability' $plugin 'manage_ssf_releases'
Assert-NotContains 'Permission groups do not assign administrator role' $plugin "set_role('administrator"
Assert-NotContains 'Permission groups do not grant install_plugins' $plugin "'install_plugins'"
Assert-NotContains 'Permission groups do not grant edit_plugins' $plugin "'edit_plugins'"
Assert-NotContains 'Permission groups do not grant create_users' $plugin "'create_users'"
Assert-NotContains 'Permission groups do not grant promote_users' $plugin "'promote_users'"
Assert-NotContains 'No WordPress role promotion' $plugin 'add_role('
Assert-NotContains 'No role assignment remains' $plugin 'set_role'
Assert-NotContains 'No Microsoft app roles are used' $plugin "`$claims['roles']"
Assert-NotContains 'No Entra group claim is used' $plugin "`$claims['groups']"
Assert-NotContains 'No directory roles are used' $plugin "`$claims['wids']"
Assert-NotContains 'No hasgroups claim is used' $plugin 'hasgroups'
Assert-NotContains 'No raw tid input field' $plugin 'name="tid"'
Assert-NotContains 'No raw oid input field' $plugin 'name="oid"'

Assert-Contains 'Stored email is display metadata only' $plugin 'claim_email'
Assert-Contains 'Login stores last login' $plugin 'META_LAST_LOGIN'
Assert-Contains 'Login stores display email only after tid oid match' $plugin 'update_user_meta($user_id, self::META_EMAIL'

Assert-Contains 'JS copy handler exists' $js 'data-ssf-copy'
Assert-Contains 'JS uses clipboard API' $js 'navigator.clipboard.writeText'
Assert-NotContains 'JS does not fetch remote endpoints' $js 'fetch('
Assert-NotContains 'JS does not expose secrets' $js 'client_secret'

Assert-Contains 'Documentation redirect URI' $doc 'https://ssfb.se/dev/ssf-auth/microsoft/callback/'
Assert-Contains 'Documentation no SharePoint permissions' $doc 'No SharePoint permissions'
Assert-Contains 'Documentation no app permissions' $doc 'Do not add Microsoft Graph application permissions'
Assert-Contains 'Documentation disable switch' $doc "SSF_M365_LOGIN_ENABLED"
Assert-Contains 'Documentation admin settings path' $doc 'SSF -> System -> Inloggning'
Assert-Contains 'Documentation backend profiles' $doc 'development'
Assert-Contains 'Documentation production profile' $doc 'production'
Assert-Contains 'Documentation secret preservation' $doc 'Leaving the secret field empty keeps the existing saved secret'
Assert-Contains 'Documentation permission model' $doc 'Authorization stays in WordPress'
Assert-Contains 'Documentation audit option' $doc 'ssf_microsoft_login_permission_audit'

if ($failures.Count) {
    $failures | ForEach-Object { Write-Error $_ }
    exit 1
}

Write-Host 'PASS: DEV Microsoft 365 login pilot static security tests.'
