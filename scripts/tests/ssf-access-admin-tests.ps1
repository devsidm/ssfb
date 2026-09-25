[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$access = Get-Content -Raw -Encoding UTF8 -LiteralPath (Join-Path $repo 'wp-content\mu-plugins\ssf-access-control.php')
$users = Get-Content -Raw -Encoding UTF8 -LiteralPath (Join-Path $repo 'wp-content\mu-plugins\ssf-user-admin.php')
$handler = Get-Content -Raw -Encoding UTF8 -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-medlemsprocess\includes\class-ssf-medlemsprocess-admin.php')
$login = Get-Content -Raw -Encoding UTF8 -LiteralPath (Join-Path $repo 'wp-content\plugins\microsoft-id-login\microsoft-id-login.php')
$central = Get-Content -Raw -Encoding UTF8 -LiteralPath (Join-Path $repo 'wp-content\mu-plugins\ssf-microsoft365-config.php')
$mailer = Get-Content -Raw -Encoding UTF8 -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-office365-mailer\ssf-office365-mailer.php')
$release = Get-Content -Raw -Encoding UTF8 -LiteralPath (Join-Path $repo 'config\deploy-components.json') | ConvertFrom-Json
$failures = [Collections.Generic.List[string]]::new()
function Require([string]$Name, [string]$Content, [string]$Snippet) {
    if (-not $Content.Contains($Snippet)) { $script:failures.Add("$Name missing: $Snippet") | Out-Null }
}

Require 'Existing permission storage retained' $access "_ssf_permission_groups"
Require 'Central capability grant' $access "add_filter('user_has_cap'"
Require 'Inactive capability denial' $access "if (! self::is_active((int) `$user->ID))"
Require 'Membership handler capability' $access "user_can(`$user_id, 'ssf_review_applications')"
Require 'No self deactivation' $access "`$target_user_id === `$actor_user_id"
Require 'Users admin nonce' $users "check_admin_referer('ssf_user_save_groups_"
Require 'Activation admin nonce' $users "check_admin_referer('ssf_user_set_active_"
Require 'Open case deactivation blocker' $users "self::open_assignments(`$user_id)"
Require 'Invitation backend reused' $users 'ssf_m365_create_invitation'
Require 'Existing handler retained' $handler 'handler_user_ids($assigned)'
Require 'New handler capability checked' $handler 'can_assign_handler($new_handler)'
Require 'Login rejects inactive user' $login 'SSF_Access_Control::is_active($user_id)'
Require 'Login diagnostics use runtime resolver' $login 'public_configuration_status()'
Require 'Central login status uses runtime resolver' $central 'SSF_Microsoft_ID_Login::instance()->public_configuration_status()'
Require 'Central mailer status uses runtime resolver' $central 'SSF_Office365_Mailer::instance()->public_configuration_status()'
Require 'Mailer status does not return secrets' $mailer 'public_configuration_status()'
foreach ($file in @('ssf-access-control.php', 'ssf-user-admin.php')) {
    if ($release.production.mu_files -notcontains $file) { $failures.Add("Release missing MU file: $file") | Out-Null }
}
if ($failures.Count) {
    $failures | ForEach-Object { Write-Error $_ }
    exit 1
}
Write-Output 'PASS: SSF users, permissions, handler selection and Microsoft status contracts.'
