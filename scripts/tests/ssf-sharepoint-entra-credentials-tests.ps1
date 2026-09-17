[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$failures = [Collections.Generic.List[string]]::new()

function Read-RepoFile([string]$Path) { Get-Content -Raw -Encoding UTF8 -LiteralPath (Join-Path $repo $Path) }
function Assert-Contains([string]$Name, [string]$Content, [string]$Expected) { if (-not $Content.Contains($Expected)) { $failures.Add("$Name saknar: $Expected") } }
function Assert-NotContains([string]$Name, [string]$Content, [string]$Expected) { if ($Content.Contains($Expected)) { $failures.Add("$Name innehåller otillåtet: $Expected") } }

$controller = Read-RepoFile 'wp-content\plugins\ssf-member-portal\includes\Modules\Motions\Admin\Controller.php'
$admin = Read-RepoFile 'wp-content\plugins\ssf-member-portal\includes\Integrations\Microsoft365\SharePointAdmin.php'
$configuration = Read-RepoFile 'wp-content\plugins\ssf-member-portal\includes\Integrations\Microsoft365\Configuration.php'
$authentication = Read-RepoFile 'wp-content\plugins\ssf-member-portal\includes\Integrations\Microsoft365\Authentication.php'
$login = Read-RepoFile 'wp-content\plugins\microsoft-id-login\microsoft-id-login.php'
$mailer = Read-RepoFile 'wp-content\plugins\ssf-office365-mailer\ssf-office365-mailer.php'
$javascript = Read-RepoFile 'wp-content\plugins\ssf-member-portal\assets\js\sharepoint-admin.js'

Assert-NotContains 'Old generic Entra box removed' $controller 'Konfigurera Microsoft Entra'
Assert-Contains 'SharePoint Entra section' $admin 'Microsoft Entra-app'
Assert-Contains 'Central tenant status' $admin 'Microsoft 365-tenant'
Assert-NotContains 'No editable SharePoint tenant field' $admin 'name="graph[tenant_id]"'
Assert-Contains 'Client ID remains editable when admin backed' $admin 'name="graph[client_id]"'
Assert-Contains 'Server Client ID is locked' $admin "disabled(empty(`$client_id['editable']))"
Assert-Contains 'Secret status only' $admin "'configured'"
Assert-Contains 'Replace secret flow' $admin 'Byt client secret'
Assert-Contains 'New secret is password field' $admin 'name="graph[client_secret]" value="" autocomplete="new-password"'
Assert-NotContains 'Stored secret never rendered in form' $admin "esc_attr((string) (`$secret['value']"
Assert-Contains 'Clear stored secret confirmation' $admin 'confirm_clear_client_secret'
Assert-Contains 'Clear uses existing behavior' $admin 'Configuration::save_admin($input)'
Assert-Contains 'Server override explained' $admin 'server_authoritative'
Assert-Contains 'Safe status helper exists' $configuration 'sharepoint_credentials_status'
Assert-Contains 'Existing encrypted storage remains' $configuration 'self::encrypt'
Assert-Contains 'Blank secret preserves existing' $configuration "if (! empty(`$input['client_secret']))"
Assert-Contains 'Existing clear behavior remains' $configuration "if (! empty(`$input['clear_client_secret']))"
Assert-Contains 'Authentication semantics unchanged' $authentication "'client_secret' => `$config['client_secret']"
Assert-Contains 'Login remains own credential integration' $login 'SSF_M365_LOGIN_CLIENT_SECRET'
Assert-Contains 'Mailer remains own credential integration' $mailer "'client_secret' => `$current['client_secret']"
Assert-NotContains 'No secret in SharePoint JS' $javascript 'client_secret'

if ($failures.Count) {
    $failures | ForEach-Object { Write-Error $_ }
    exit 1
}

Write-Host 'PASS: SharePoint Entra credential placement and secret-safety contracts.'
