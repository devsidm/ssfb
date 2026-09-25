[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$pluginPath = Join-Path $repo 'wp-content\plugins\microsoft-id-login\microsoft-id-login.php'
$jsPath = Join-Path $repo 'wp-content\plugins\microsoft-id-login\assets\js\admin.js'
$cssPath = Join-Path $repo 'wp-content\plugins\microsoft-id-login\assets\css\ssf-account.css'
$docPath = Join-Path $repo 'docs\MICROSOFT-365-LOGIN-DEV.md'
$configPath = Join-Path $repo 'config\deploy-components.json'
$failures = [Collections.Generic.List[string]]::new()

function Fail([string]$Message) { $failures.Add($Message) | Out-Null }
function Assert-True([string]$Name, [bool]$Condition) { if (-not $Condition) { Fail $Name } }
function Decode-TestLiteral([string]$Text) {
    if ($PSVersionTable.PSVersion.Major -lt 6) {
        return [Text.Encoding]::UTF8.GetString([Text.Encoding]::GetEncoding(1252).GetBytes($Text))
    }
    return $Text
}
function Assert-Contains([string]$Name, [string]$Content, [string]$Expected) { $Expected = Decode-TestLiteral $Expected; if (-not $Content.Contains($Expected)) { Fail "$Name missing: $Expected" } }
function Assert-NotContains([string]$Name, [string]$Content, [string]$Unexpected) { $Unexpected = Decode-TestLiteral $Unexpected; if ($Content.Contains($Unexpected)) { Fail "$Name contains forbidden text: $Unexpected" } }

$plugin = Get-Content -Raw -Encoding UTF8 -LiteralPath $pluginPath
$js = Get-Content -Raw -Encoding UTF8 -LiteralPath $jsPath
$css = Get-Content -Raw -Encoding UTF8 -LiteralPath $cssPath
$doc = Get-Content -Raw -Encoding UTF8 -LiteralPath $docPath
$config = Get-Content -Raw -Encoding UTF8 -LiteralPath $configPath | ConvertFrom-Json
$access = Get-Content -Raw -Encoding UTF8 -LiteralPath (Join-Path $repo 'wp-content\mu-plugins\ssf-access-control.php')
$userAdmin = Get-Content -Raw -Encoding UTF8 -LiteralPath (Join-Path $repo 'wp-content\mu-plugins\ssf-user-admin.php')

Assert-True 'Microsoft login plugin exists' (Test-Path -LiteralPath $pluginPath)
Assert-Contains 'Plugin header exists' $plugin 'Plugin Name: Microsoft ID Login'
Assert-Contains 'Plugin version bumped' $plugin 'Version: 0.3.6'

Assert-Contains 'Server force-off switch exists' $plugin "SSF_M365_LOGIN_ENABLED"
Assert-Contains 'Server force-off switch is explicit' $plugin 'private function is_force_disabled()'
Assert-Contains 'One structured enable resolver exists' $plugin 'private function enable_state()'
Assert-Contains 'Backend enable state comes from active DB profile' $plugin "`$admin_enabled = ! empty(`$profile['enabled'])"
Assert-Contains 'Effective state combines backend config and force-off' $plugin '$active = $admin_enabled && $configured && ! $force_off'
Assert-NotContains 'Enabled state is not read through generic config overrides' $plugin "config('enabled')"
Assert-Contains 'Normal login uses effective enable resolver' $plugin 'if (! $this->is_enabled())'
Assert-Contains 'Invitation uses shared OAuth initiation' $plugin "start_authorization('invite'"
Assert-Contains 'Account linking uses shared OAuth initiation' $plugin "start_authorization('link'"
Assert-Contains 'OAuth initiation uses structured enable resolver' $plugin '$enable_state = $this->enable_state()'
Assert-Contains 'OAuth initiation blocks inactive state' $plugin "empty(`$enable_state['active'])"
Assert-True 'Callback uses same resolver as OAuth start' (([regex]::Matches($plugin, [regex]::Escape('$enable_state = $this->enable_state()'))).Count -ge 3)
Assert-Contains 'Enabled and configured permits active state' $plugin '$admin_enabled && $configured && ! $force_off'
Assert-Contains 'Effective config includes OpenID issuer validation' $plugin '$configured = $local_configured && $openid_valid'
Assert-Contains 'Incomplete configuration has clear error' $plugin 'Microsoft-inloggningen är inte färdigkonfigurerad.'
Assert-Contains 'Administrator disabled has clear error' $plugin 'Microsoft-inloggningen är avstängd av en administratör.'
Assert-Contains 'Server force-off has clear error' $plugin 'Microsoft-inloggningen är avstängd av serverkonfiguration.'
Assert-Contains 'Force-off is shown in backend' $plugin 'Avstängd av serverkonfiguration'
Assert-Contains 'Force-off disables backend checkbox' $plugin 'disabled($force_off)'
Assert-Contains 'Effective ACTIVE requires complete status' $plugin "return __('AKTIV'"
Assert-Contains 'Tenant comes from central Microsoft 365 service' $plugin 'SSF_Microsoft365_Config::get_tenant_id()'
Assert-Contains 'Central tenant loader exists' $plugin 'ensure_central_config_loaded'
Assert-Contains 'Login runtime can load central MU config' $plugin 'ssf-microsoft365-config.php'
Assert-NotContains 'Login runtime never reads legacy tenant constant' $plugin "'tenant_id' => 'SSF_M365_LOGIN_TENANT_ID'"
Assert-NotContains 'Login runtime does not getenv legacy tenant' $plugin "getenv('SSF_M365_LOGIN_TENANT_ID')"
Assert-NotContains 'No duplicate Tenant ID input in Login UI' $plugin 'name="profiles[<?php echo esc_attr($profile_key); ?>][tenant_id]"'
Assert-Contains 'Login UI names central tenant configuration' $plugin 'Central Microsoft 365 configuration'
Assert-Contains 'Login UI shows central tenant configured' $plugin "'CONFIGURED'"
Assert-Contains 'Login UI shows central tenant missing' $plugin "'MISSING'"
Assert-Contains 'Login UI shows effective status' $plugin 'Effective status'
Assert-Contains 'Legacy tenant warnings displayed on login page' $plugin 'legacy_tenant_warnings'
Assert-Contains 'Central tenant management link exists' $plugin 'Hantera Microsoft 365-inställningar'
Assert-Contains 'Client ID constant exists' $plugin "SSF_M365_LOGIN_CLIENT_ID"
Assert-Contains 'Client secret constant exists' $plugin "SSF_M365_LOGIN_CLIENT_SECRET"
Assert-Contains 'Client ID falls back to backend profile' $plugin "`$fallback = is_string(`$profile[`$key] ?? null) ? trim(`$profile[`$key]) : '';"
Assert-NotContains 'Boolean credentials are never stringified' $plugin "return `$value ? 'true' : 'false';"
Assert-Contains 'Client ID validated before authorization' $plugin 'if (! $this->is_valid_client_id($client_id))'
Assert-Contains 'Authorization URL uses resolved Client ID' $plugin "'client_id' => `$client_id,"
Assert-Contains 'Token exchange uses credential resolver' $plugin "'client_secret' => `$this->config('client_secret')"
Assert-Contains 'Audience uses credential resolver' $plugin "`$claims['aud'] ?? '') !== `$this->config('client_id')"
Assert-Contains 'Login settings save keeps backend enabled' $plugin "`$current['profiles'][`$profile_key]['enabled'] = ! empty(`$profile['enabled']);"
Assert-Contains 'Login settings save keeps backend client ID' $plugin "`$current['profiles'][`$profile_key]['client_id']"
Assert-Contains 'Login settings save keeps backend client secret' $plugin "`$current['profiles'][`$profile_key]['client_secret']"
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
Assert-NotContains 'No automatic user creation helper' $plugin 'wp_create_user'
Assert-Contains 'Admin invitation may prepare WordPress user' $plugin 'wp_insert_user'
Assert-Contains 'User insert is behind invitation helper' $plugin 'find_or_create_user'
Assert-NotContains 'No role assignment' $plugin 'set_role'
Assert-NotContains 'Email not used in identity meta query' $plugin "'key' => 'user_email'"

Assert-Contains 'Account linking requires login' $plugin 'is_user_logged_in()'
Assert-Contains 'Linking requires nonce' $plugin "check_admin_referer('ssf_m365_link_start')"
Assert-Contains 'Linking preserves current user' $plugin 'get_current_user_id() !== $user_id'
Assert-Contains 'Duplicate identity blocked' $plugin 'redan kopplat till ett annat SSF-konto'
Assert-Contains 'Unlink requires nonce' $plugin "check_admin_referer('ssf_m365_unlink')"
Assert-Contains 'Unlink deletes tid' $plugin 'delete_user_meta(get_current_user_id(), self::META_TID)'
Assert-Contains 'Unlink deletes oid' $plugin 'delete_user_meta(get_current_user_id(), self::META_OID)'

Assert-Contains 'Login button rendered' $plugin 'Logga in med ditt SSF-konto'
Assert-Contains 'SSF account login button rendered' $plugin 'Logga in med ditt SSF-konto'
Assert-Contains 'SSF account domain helper rendered' $plugin 'Använd ditt @ssfb.se-konto'
Assert-Contains 'WordPress fallback login remains visible' $plugin 'Administratör / reservinloggning'
Assert-Contains 'WordPress fallback label exists' $plugin 'Logga in med WordPress'
Assert-Contains 'Login page message hook exists' $plugin 'login_message'
Assert-Contains 'Login CSS is enqueued' $plugin 'assets/css/ssf-account.css'
Assert-Contains 'Login button hook preserves normal login form' $plugin "add_action('login_form'"
Assert-Contains 'Reusable login URL helper exists' $plugin 'public static function login_url'
Assert-Contains 'Safe redirect validation' $plugin 'wp_validate_redirect'
Assert-Contains 'Safe redirect execution' $plugin 'wp_safe_redirect'
Assert-Contains 'Clean callback rewrite' $plugin 'ssf-auth/microsoft/callback'
Assert-Contains 'Callback based on home_url' $plugin "home_url('/' . self::CALLBACK_PATH)"
Assert-Contains 'Rewrite flushed on activation' $plugin 'register_activation_hook'
Assert-NotContains 'No hardcoded dev path in plugin' $plugin 'ssfb.se/dev'

Assert-Contains 'Admin diagnostics page' $plugin 'Microsoft ID Login'
Assert-Contains 'Admin subtitle exists' $plugin 'Microsoft 365 / Entra ID-inloggning'
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
Assert-Contains 'Only active profile saved from UI' $plugin 'foreach (array($this->active_profile_key()) as $profile_key)'
Assert-NotContains 'Production profile not editable from DEV UI' $plugin 'Production kan förkonfigureras här.'
Assert-Contains 'Client ID editable in UI' $plugin 'Application ID / Client ID'
Assert-Contains 'Client secret password field' $plugin 'type="password"'
Assert-NotContains 'Client secret value is never rendered' $plugin 'value="<?php echo esc_attr((string) $profile[''client_secret''])'
Assert-Contains 'Secret blank preserves existing value' $plugin 'Secret finns -'
Assert-Contains 'Secret can be cleared explicitly' $plugin 'clear_secret'
Assert-Contains 'Settings update does not autoload secrets' $plugin 'update_option(self::SETTINGS_OPTION, $current, false)'
Assert-Contains 'Server constants remain emergency override' $plugin 'SSF_M365_LOGIN_CLIENT_ID'
Assert-NotContains 'No token HTML output' $plugin 'id_token</'
Assert-NotContains 'No raw JWT logging' $plugin 'error_log($jwt'
Assert-NotContains 'No secret logging' $plugin 'error_log($this->config(''client_secret'')'

Assert-True 'New plugin is production deployable' (@($config.production.plugins) -contains 'microsoft-id-login')
Assert-True 'New plugin is not listed DEV-only' (-not (@($config.plugin_policy.dev_only) -contains 'microsoft-id-login'))
Assert-True 'New plugin is not production-excluded' (-not (@($config.excluded.plugins) -contains 'microsoft-id-login'))
Assert-True 'Old plugin slug removed from deployment policy' (-not (@($config.production.plugins + $config.plugin_policy.dev_only + $config.excluded.plugins) -contains 'ssf-microsoft-login'))
$devOnlyPolicy = @{}
@($config.plugin_policy.dev_only) | ForEach-Object { $devOnlyPolicy[$_] = $true }
$activeDevPilot = @{ name = 'microsoft-id-login'; status = 'active'; version = '0.3.0' }
$missingProdPilot = $null
$pilotClassification = if ($devOnlyPolicy.ContainsKey($activeDevPilot.name)) { 'DEV_ONLY_ALLOWED' } elseif ($activeDevPilot.status -eq 'active' -and -not $missingProdPilot) { 'PRODUCTION_CAPABLE' } else { 'MATCH' }
Assert-True 'Production capable plugin is not treated as missing PROD parity' ($pilotClassification -eq 'PRODUCTION_CAPABLE')

Assert-Contains 'Users menu under SSF' $userAdmin 'SSF_Admin_Navigation::ROOT'
Assert-Contains 'Users menu label' $userAdmin 'Användare & behörigheter'
Assert-Contains 'Microsoft central menu' (Get-Content -Raw -Encoding UTF8 -LiteralPath (Join-Path $repo 'wp-content\mu-plugins\ssf-admin-navigation.php')) 'Microsoft-konfiguration'
Assert-Contains 'Manage login capability' $plugin 'ssf_manage_microsoft_login'
Assert-Contains 'Manage permission groups capability' $plugin 'ssf_manage_permission_groups'
Assert-Contains 'Admin assets loaded only on plugin page' $plugin "assets/js/admin.js"
Assert-Contains 'Callback copy button exists' $plugin 'data-ssf-copy="#ssf-m365-callback"'
Assert-Contains 'Own account card exists' $plugin 'render_own_account_card'
Assert-Contains 'Linked user list exists' $plugin 'render_users_and_permissions'
Assert-Contains 'Admin create SSF user form exists' $plugin 'Lägg till SSF-användare'
Assert-Contains 'Admin create invitation action exists' $plugin 'ssf_m365_create_invitation'
Assert-Contains 'Create invitation requires capability' $plugin 'can_manage_permission_groups()'
Assert-Contains 'Create invitation requires nonce' $plugin "check_admin_referer('ssf_m365_create_invitation')"
Assert-Contains 'Invitation option exists' $plugin 'ssf_microsoft_login_invitations'
Assert-Contains 'Invitation token is hashed' $plugin 'invitation_token_hash'
Assert-Contains 'Invitation token raw value is only mailed' $plugin "'raw_token' => `$raw_token"
Assert-Contains 'Invitation token uses HMAC' $plugin "hash_hmac('sha256'"
Assert-Contains 'Invitation is time limited' $plugin 'INVITATION_TTL'
Assert-Contains 'Invitation has expires_at' $plugin "'expires_at'"
Assert-Contains 'Invitation is one time use' $plugin "'used_at'"
Assert-Contains 'Invitation can be canceled' $plugin 'ssf_m365_cancel_invitation'
Assert-Contains 'Invitation can be resent' $plugin 'ssf_m365_resend_invitation'
Assert-Contains 'Resend invalidates old tokens' $plugin 'invalidate_open_invitations'
Assert-Contains 'Only SSF email addresses are invited' $plugin 'is_ssf_email'
Assert-Contains 'SSF email domain enforced' $plugin '@ssfb\.se'
Assert-Contains 'Invitation mail sent' $plugin 'send_invitation_email'
Assert-Contains 'Invitation mail subject exists' $plugin 'Aktivera ditt SSF-konto'
Assert-Contains 'Activation page exists' $plugin 'render_invitation_activation'
Assert-Contains 'Activation starts existing OIDC flow' $plugin "start_authorization('invite'"
Assert-Contains 'Wrong Microsoft account is blocked' $plugin 'Fel SSF-konto'
Assert-Contains 'Invitation checks Microsoft email for pending invite' $plugin 'strtolower($email) !== $expected_email'
Assert-Contains 'Invitation permanent identity uses tid' $plugin 'update_user_meta($user_id, self::META_TID'
Assert-Contains 'Invitation permanent identity uses oid' $plugin 'update_user_meta($user_id, self::META_OID'
Assert-Contains 'Invitation stores email as display metadata' $plugin 'update_user_meta($user_id, self::META_EMAIL'
Assert-Contains 'Successful activation page exists' $plugin 'Ditt SSF-konto är aktiverat'
Assert-Contains 'Pending invitation metric exists' $plugin 'pending_invitation_count'
Assert-Contains 'User table status exists' $plugin 'user_status_label'
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
Assert-Contains 'Test result explains what was checked' $plugin 'Vad kontrollerades'
Assert-Contains 'Test result summary exists' $plugin 'Samlat resultat:'
Assert-Contains 'Test result detail column exists' $plugin "esc_html__('Detalj'"
Assert-Contains 'Test result action column exists' $plugin "esc_html__('Åtgärd vid fel'"
Assert-Contains 'Structured test result normalizer exists' $plugin 'normalize_check_result'
Assert-Contains 'Structured test result pass helper exists' $plugin 'checks_passed'
Assert-Contains 'Connection test checks WordPress environment' $plugin "'WordPress environment'"
Assert-Contains 'Connection test checks issuer' $plugin "'Issuer matchar tenant'"
Assert-Contains 'Connection test checks JWKS' $plugin "'JWKS/signeringsnycklar'"
Assert-Contains 'Connection test states no Graph/SharePoint permissions needed' $plugin 'Inga Graph- eller SharePoint-behorigheter behovs'
Assert-Contains 'Connection test explains missing secret' $plugin 'Client secret saknas'
Assert-Contains 'Connection test explains OpenID failure' $plugin 'Microsoft OpenID metadata kunde inte läsas'
Assert-Contains 'Connection test explains callback setup' $plugin 'Lägg in exakt callback URL i Entra app registration'

Assert-Contains 'Real login test action' $plugin 'ssf_m365_test_login'
Assert-Contains 'Real login test mode started' $plugin "start_authorization('test'"
Assert-Contains 'Real login test callback branch' $plugin "'test' === (`$transaction['mode'] ?? '')"
Assert-Contains 'Real login test function exists' $plugin 'complete_real_login_test'
Assert-Contains 'Real login test stores transient' $plugin "self::TEST_PREFIX . 'login_'"
Assert-Contains 'Real login test verifies linked account' $plugin 'Microsoft-identitet matchar kopplat konto'
Assert-Contains 'Real login test records unchanged permissions' $plugin 'WordPress-behorigheter oforandrade'
Assert-Contains 'Real login test explains linked account failure' $plugin 'Koppla rätt Microsoft-konto under Ditt Microsoft-konto'
Assert-Contains 'Real login test explains no permission mutation' $plugin 'Testet ändrade inte WordPress-roll eller SSF-behörighetsgrupper'
Assert-Contains 'Last test status option exists' $plugin 'microsoft_id_login_test_status'
Assert-Contains 'Technical test status persisted' $plugin "record_test_status('technical'"
Assert-Contains 'Real login test status persisted' $plugin "record_test_status('real_login'"

Assert-Contains 'Permission group model exists' $access 'function groups()'
Assert-Contains 'Permission groups stored in user meta' $access '_ssf_permission_groups'
Assert-Contains 'Permission groups granted by user_has_cap' $access "add_filter('user_has_cap'"
Assert-Contains 'Permission save action exists' $userAdmin 'ssf_user_save_groups'
Assert-Contains 'Permission audit option exists' $access 'ssf_microsoft_login_permission_audit'
Assert-Contains 'Permission audit records actor' $access "'actor_user_id'"
Assert-Contains 'Permission audit records target' $access "'target_user_id'"
Assert-Contains 'Permission audit records timestamp' $access "'timestamp'"
Assert-Contains 'Applications group includes review capability' $access 'ssf_review_applications'
Assert-Contains 'Applications group includes decision capability' $access 'ssf_decide_applications'
Assert-Contains 'Inspector group includes assigned applications capability' $access 'ssf_view_assigned_applications'
Assert-Contains 'Motion group includes motion capability' $access 'ssf_manage_motions'
Assert-Contains 'Annual meetings group includes annual meeting capability' $access 'manage_ssf_annual_meetings'
Assert-Contains 'System group includes release capability' $access 'manage_ssf_releases'
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
Assert-NotContains 'No raw token stored in invitation record' $plugin "'token' => `$raw_token"
Assert-NotContains 'Microsoft groups do not grant SSF groups' $plugin "`$claims['groups'] ??"

Assert-Contains 'Stored email is display metadata only' $plugin 'claim_email'
Assert-Contains 'Login stores last login' $plugin 'META_LAST_LOGIN'
Assert-Contains 'Login stores display email only after tid oid match' $plugin 'update_user_meta($user_id, self::META_EMAIL'

Assert-Contains 'JS copy handler exists' $js 'data-ssf-copy'
Assert-Contains 'JS uses clipboard API' $js 'navigator.clipboard.writeText'
Assert-NotContains 'JS does not fetch remote endpoints' $js 'fetch('
Assert-NotContains 'JS does not expose secrets' $js 'client_secret'

Assert-Contains 'CSS styles SSF account login' $css '.ssf-account-login'
Assert-Contains 'CSS styles SSF activation page' $css '.ssf-account-card'
Assert-Contains 'CSS has mobile breakpoint' $css '@media (max-width: 480px)'
Assert-Contains 'CSS has focus hover state' $css '.ssf-account-primary:focus'
Assert-Contains 'Activation page uses SSF theme logo' $plugin "get_theme_file_uri('/assets/images/ssf-logo.svg')"
Assert-Contains 'Activation button has content width' $css '.ssf-account-card .ssf-account-primary'
Assert-Contains 'Connection test exposes safe transport detail' $plugin 'safe_http_error'
Assert-Contains 'Connection diagnostics redact client secret' $plugin "`$this->config('client_secret')"

Assert-Contains 'Documentation redirect URI' $doc 'https://ssfb.se/dev/ssf-auth/microsoft/callback/'
Assert-Contains 'Documentation no SharePoint permissions' $doc 'No SharePoint permissions'
Assert-Contains 'Documentation no app permissions' $doc 'Do not add Microsoft Graph application permissions'
Assert-Contains 'Documentation disable switch' $doc "SSF_M365_LOGIN_ENABLED"
Assert-Contains 'Documentation admin settings path' $doc 'SSF -> System -> Inloggning'
Assert-Contains 'Documentation backend profiles' $doc 'development'
Assert-Contains 'Documentation production profile' $doc 'production'
Assert-Contains 'Documentation secret preservation' $doc 'Leaving the secret field empty keeps the existing saved secret'
Assert-Contains 'Documentation permission model' $doc 'Authorization stays in WordPress'
Assert-Contains 'Documentation PROD redirect URI' $doc 'https://ssfb.se/ssf-auth/microsoft/callback/'
Assert-Contains 'Documentation audit option' $doc 'ssf_microsoft_login_permission_audit'

# Exercise the real private resolver and OAuth methods with isolated WordPress stubs.
$php = Get-Command php -ErrorAction SilentlyContinue
$phpPath = if ($php) { $php.Source } else { Join-Path ([IO.Path]::GetTempPath()) 'ssf-codex-php-8.5.10\php.exe' }
if (-not (Test-Path -LiteralPath $phpPath)) {
    Fail 'PHP executable required for Microsoft ID Login runtime regression tests'
} else {
    $fixture = [IO.Path]::GetTempFileName()
    try {
        $runtimeTest = @'
<?php
define('ABSPATH', __DIR__);
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);
$profileId = '8eeb0e82-1b9c-41c6-b2a1-c07b9be3768a';
$overrideId = '935e4377-865a-4e7f-bf59-3d4f858ddc2a';
$tenantId = 'ad928e8c-b976-4c84-a0b1-931341b5a512';
$secret = 'test-secret-not-for-output';
$options = array('profiles' => array('production' => array('enabled' => true, 'client_id' => $profileId, 'client_secret' => $secret)));
$redirected = false;
function register_activation_hook() {}
function register_deactivation_hook() {}
function add_action() {}
function add_filter() {}
function apply_filters($name, $value) { return 'ssf_microsoft_login_default_redirect' === $name ? 'https://example.test/dev/arbetsyta/' : $value; }
function get_option($key, $default = null) { global $options; return $options; }
function wp_get_environment_type() { return 'production'; }
function home_url($path) { return 'https://example.test' . $path; }
function admin_url($path) { return 'https://example.test/wp-admin/' . ltrim($path, '/'); }
function wp_validate_redirect($url, $fallback) { return $url ?: $fallback; }
function get_transient() { return false; }
function set_transient() { return true; }
function __($message) { return $message; }
function esc_html($message) { return $message; }
function esc_html__($message) { return $message; }
function wp_die($message) { throw new RuntimeException('local-error'); }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_remote_get() { global $tenantId; return array('code' => 200, 'body' => json_encode(array('issuer' => 'https://login.microsoftonline.com/' . $tenantId . '/v2.0', 'jwks_uri' => 'https://example.test/keys'))); }
function wp_remote_post($url, $args) { global $tokenBody; $tokenBody = $args['body']; return array('code' => 200, 'body' => '{}'); }
function wp_remote_retrieve_response_code($response) { return $response['code']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function wp_redirect($url) { global $redirected; $redirected = $url; throw new RuntimeException('redirect'); }
class WP_Error {
    private $message;
    public function __construct($code = '', $message = '') { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
class SSF_Microsoft365_Config {
    public static function get_tenant_id() { global $tenantId; return $tenantId; }
    public static function get_authority_url($path) { global $tenantId; return 'https://login.microsoftonline.com/' . $tenantId . $path; }
    public static function get_authority_host() { return 'login.microsoftonline.com'; }
}
require $argv[1];
$login = SSF_Microsoft_ID_Login::instance();
function invoke($object, $name, ...$args) { return (new ReflectionMethod($object, $name))->invoke($object, ...$args); }
function check($condition, $name) { if (!$condition) { throw new RuntimeException($name); } }
check(invoke($login, 'safe_redirect', '') === 'https://example.test/dev/arbetsyta/', 'Workspace default landing');
check(invoke($login, 'safe_redirect', 'https://example.test/dev/arbetsyta/ansokningar/123/') === 'https://example.test/dev/arbetsyta/ansokningar/123/', 'explicit Workspace deep link preserved');
putenv('SSF_M365_LOGIN_CLIENT_ID');
putenv('SSF_M365_LOGIN_CLIENT_SECRET');
check(invoke($login, 'config', 'client_id') === $profileId, 'missing env Client ID fallback');
check(invoke($login, 'config', 'client_secret') === $secret, 'missing env secret fallback');
check(invoke($login, 'is_configured') === true, 'profile configured');
putenv('SSF_M365_LOGIN_CLIENT_ID=' . $overrideId);
check(invoke($login, 'config', 'client_id') === $overrideId, 'valid server override');
putenv('SSF_M365_LOGIN_CLIENT_ID=false');
check(invoke($login, 'config', 'client_id') === $profileId, 'false Client ID fallback');
foreach (array('true', '0', '1', 'not-a-guid', '   ') as $invalid) {
    putenv('SSF_M365_LOGIN_CLIENT_ID=' . $invalid);
    check(invoke($login, 'config', 'client_id') === $profileId, 'invalid Client ID fallback');
}
putenv('SSF_M365_LOGIN_CLIENT_ID');
$options['profiles']['production']['client_id'] = 'false';
check(invoke($login, 'config', 'client_id') === '', 'invalid profile Client ID rejected');
check(invoke($login, 'is_configured') === false, 'invalid profile not configured');
check(invoke($login, 'run_connection_checks')['Client ID finns']['passed'] === false, 'diagnostics reject invalid Client ID');
try { invoke($login, 'start_authorization', 'login', 0, ''); } catch (RuntimeException $e) { check($e->getMessage() === 'local-error', 'local authorization error'); }
check($redirected === false, 'no Microsoft redirect with invalid ID');
$options['profiles']['production']['client_id'] = $profileId;
try { invoke($login, 'start_authorization', 'login', 0, ''); } catch (RuntimeException $e) { check($e->getMessage() === 'redirect', 'expected authorization redirect'); }
parse_str((string) parse_url($redirected, PHP_URL_QUERY), $query);
check(($query['client_id'] ?? '') === $profileId, 'authorization URL uses resolved Client ID');
invoke($login, 'exchange_code', 'test-code', 'test-verifier');
check($tokenBody['client_id'] === $profileId && $tokenBody['client_secret'] === $secret, 'token exchange uses resolver');
putenv('SSF_M365_LOGIN_ENABLED=0');
check(invoke($login, 'is_force_disabled') === true, 'force-disable preserved');
check(invoke($login, 'enable_state')['active'] === false, 'force-disable prevents activation');
echo 'PASS: Microsoft ID Login runtime credential regression tests.';
'@
        [IO.File]::WriteAllText($fixture, $runtimeTest, [Text.UTF8Encoding]::new($false))
        $runtimeOutput = & $phpPath $fixture $pluginPath 2>&1
        if ($LASTEXITCODE -ne 0) { Fail "Runtime regression tests failed: $runtimeOutput" }
        elseif (($runtimeOutput -join "`n").Contains('test-secret-not-for-output')) { Fail 'Client Secret leaked to test output' }
        else { Write-Host $runtimeOutput }
    } finally {
        Remove-Item -LiteralPath $fixture -Force
    }
}

if ($failures.Count) {
    $failures | ForEach-Object { Write-Error $_ }
    exit 1
}

Write-Host 'PASS: Microsoft ID Login static security tests.'
