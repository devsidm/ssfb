[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$theme = Get-Content -Raw -Encoding UTF8 -LiteralPath (Join-Path $repo 'wp-content\themes\ssf\index.php')
$portal = Get-Content -Raw -Encoding UTF8 -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-medlemsprocess\templates\inspector-portal.php')

foreach ($shortcode in @(
    'ssf_application_form',
    'ssf_application_status',
    'ssf_inspector_portal',
    'ssf_membership_review_portal',
    'ssf_fartygsuppgifter_form',
    'ssf_medlemsfartyg',
    'ssf_member_vessels',
    'ssf_home',
    'ssf_stadgar',
    'ssf_contact_form',
    'ssf_member_portal_annual_meeting',
    'ssf_member_portal_annual_meeting_registration',
    'ssf_member_portal_motion_hub',
    'ssf_member_portal_motions',
    'ssf_member_portal_motion_status'
)) {
    if (-not $theme.Contains("'$shortcode'")) {
        throw "Temat måste känna igen sidans egen rubrik: $shortcode"
    }
}
if (-not $theme.Contains('has_shortcode($page_content, $shortcode)')) {
    throw 'Temat måste kontrollera kortkoderna i sidans innehåll.'
}
if ($theme.Contains("'ssf_member_portal_annual_meetings',")) {
    throw 'Årsmötesarkivet har ingen egen H1 och måste behålla sidans rubrik.'
}
if (-not $theme.Contains('! $content_has_own_title')) {
    throw 'Temat måste dölja sidrubriken när kortkoden har en egen.'
}
if (-not $portal.Contains('<h1>Mina inspektioner</h1>')) {
    throw 'Inspektionsportalens egen huvudrubrik saknas.'
}

Write-Host 'PASS: SSF-kortkodssidor får inte dubbla huvudrubriker.'
