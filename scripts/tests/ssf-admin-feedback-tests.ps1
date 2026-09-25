$ErrorActionPreference = 'Stop'

$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path

function Read-RepoFile([string]$Path) {
    Get-Content -Raw -Encoding UTF8 -LiteralPath (Join-Path $repo $Path)
}

function Assert-Contains([string]$Label, [string]$Text, [string]$Needle) {
    if (-not $Text.Contains($Needle)) { throw "FAIL: $Label" }
}

function Assert-NotContains([string]$Label, [string]$Text, [string]$Needle) {
    if ($Text.Contains($Needle)) { throw "FAIL: $Label" }
}

$feedback = Read-RepoFile 'wp-content\mu-plugins\ssf-admin-feedback.php'
$css = Read-RepoFile 'wp-content\mu-plugins\assets\ssf-admin-feedback.css'
$js = Read-RepoFile 'wp-content\mu-plugins\assets\ssf-admin-feedback.js'
$archive = Read-RepoFile 'wp-content\plugins\ssf-medlemsprocess\includes\class-ssf-medlemsprocess-archive-migration.php'
$tenant = Read-RepoFile 'wp-content\mu-plugins\ssf-microsoft365-config.php'
$login = Read-RepoFile 'wp-content\plugins\microsoft-id-login\microsoft-id-login.php'
$sharepoint = Read-RepoFile 'wp-content\plugins\ssf-member-portal\includes\Integrations\Microsoft365\SharePointAdmin.php'
$controller = Read-RepoFile 'wp-content\plugins\ssf-member-portal\includes\Modules\Motions\Admin\Controller.php'

Assert-Contains 'Safe section allowlist' $feedback "private const SECTIONS"
Assert-Contains 'Arbitrary section rejected' $feedback "return in_array(`$section, self::SECTIONS, true) ? `$section : '';"
Assert-Contains 'Requested POST section is validated' $feedback "self::safe_section(`$requested) ?: self::safe_section(`$fallback)"
Assert-Contains 'Redirect remains local admin URL' $feedback "admin_url('admin.php')"
Assert-NotContains 'No arbitrary redirect URL input' $feedback 'redirect_to'
Assert-Contains 'User-specific flash survives PRG' $feedback "set_transient(self::TRANSIENT_PREFIX . get_current_user_id()"
Assert-Contains 'Inline success and error component' $feedback 'render_inline'
Assert-Contains 'Global fixed toast component' $feedback 'render_toast'
Assert-Contains 'Polite status semantics' $feedback "'status'"
Assert-Contains 'Error alert semantics' $feedback "'alert'"
Assert-Contains 'Technical details disclosure' $feedback 'Visa tekniska detaljer'
Assert-Contains 'Token redaction' $feedback 'Bearer [REDACTED]'
Assert-Contains 'Sensitive field redaction' $feedback 'client_secret|access_token|password|authorization'
Assert-Contains 'Toast is viewport fixed' $css 'position:fixed'
Assert-Contains 'Sections account for admin bars' $css 'scroll-margin-top'
Assert-Contains 'Success toast autocloses' $feedback 'data-ssf-toast-autoclose="5000"'
Assert-Contains 'Toast can be dismissed' $js 'data-ssf-toast-dismiss'
Assert-Contains 'Repeated form submission is blocked' $js 'event.preventDefault()'
Assert-Contains 'Submit button gets progress state' $js "'Testar...'"

Assert-Contains 'Archive target has stable id' $archive 'id="archive-target"'
Assert-Contains 'Archive target action returns locally' $archive "'ssf_application_archive_create_target_folder' => 'archive-target'"
Assert-Contains 'Archive success renders inline' $archive "SSF_Admin_Feedback::render_inline('archive-target')"
Assert-Contains 'Archive errors use same redirect helper' $archive "SSF_Admin_Feedback::redirect('ssf-application-archive-migration'"
Assert-Contains 'Archive nonce checks remain' $archive 'check_admin_referer($nonce)'
Assert-Contains 'Archive capability checks remain' $archive "current_user_can('ssf_manage_application_settings')"

Assert-Contains 'Microsoft directory stable section' $tenant 'id="microsoft-directory"'
Assert-Contains 'Microsoft directory tab preserved' $tenant "array('m365_tab' => 'directory')"
Assert-Contains 'Microsoft Login stable section' $login 'id="microsoft-login"'
Assert-Contains 'Microsoft Login inline feedback' $login "render_inline('microsoft-login')"
Assert-Contains 'Microsoft Login nonce checks remain' $login "check_admin_referer('ssf_m365_save_settings')"
Assert-Contains 'Microsoft Login repeated redirects preserve section' $login "admin_section_url('microsoft-login')"
Assert-Contains 'SharePoint stable section' $sharepoint 'id="sharepoint"'
Assert-Contains 'SharePoint integration tab preserved' $sharepoint "'m365_tab' => 'sharepoint'"
Assert-Contains 'SharePoint destination state preserved' $sharepoint "'destination' => `$destination"
Assert-Contains 'SharePoint nonce checks remain' $sharepoint "check_admin_referer('ssf_save_sharepoint_destination_"
Assert-Contains 'Legacy Graph actions use safe feedback' $controller "SSF_Admin_Feedback::redirect('ssf-member-portal-microsoft365', 'sharepoint'"

Write-Host 'PASS: shared SSF admin feedback and return-to-section behavior.'
