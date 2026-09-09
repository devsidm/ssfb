[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$failures = [Collections.Generic.List[string]]::new()

function Assert-Contains {
    param([string]$Path, [string]$Needle, [string]$Label)
    $content = Get-Content -Raw -LiteralPath (Join-Path $repo $Path)
    if (-not $content.Contains($Needle)) { $failures.Add($Label) }
}

function Assert-Before {
    param([string]$Path, [string]$First, [string]$Second, [string]$Label)
    $content = Get-Content -Raw -LiteralPath (Join-Path $repo $Path)
    $firstIndex = $content.IndexOf($First, [StringComparison]::Ordinal)
    $secondIndex = $content.IndexOf($Second, [StringComparison]::Ordinal)
    if ($firstIndex -lt 0 -or $secondIndex -lt 0 -or $firstIndex -ge $secondIndex) { $failures.Add($Label) }
}

function Assert-BeforeAfter {
    param([string]$Path, [string]$Anchor, [string]$First, [string]$Second, [string]$Label)
    $content = Get-Content -Raw -LiteralPath (Join-Path $repo $Path)
    $anchorIndex = $content.IndexOf($Anchor, [StringComparison]::Ordinal)
    $firstIndex = if ($anchorIndex -ge 0) { $content.IndexOf($First, $anchorIndex, [StringComparison]::Ordinal) } else { -1 }
    $secondIndex = if ($anchorIndex -ge 0) { $content.IndexOf($Second, $anchorIndex, [StringComparison]::Ordinal) } else { -1 }
    if ($anchorIndex -lt 0 -or $firstIndex -lt 0 -or $secondIndex -lt 0 -or $firstIndex -ge $secondIndex) { $failures.Add($Label) }
}

$central = 'wp-content\mu-plugins\ssf-antispam.php'
Assert-Contains $central "private const TURNSTILE_ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify'" 'Siteverify endpoint saknas'
Assert-Contains $central "empty(`$_POST['ssf_antispam_website'])" 'Central honeypotvalidering saknas'
Assert-Contains $central 'hash_hmac(' 'Rate limit använder inte saltad hash'
Assert-Contains $central "'production' !== wp_get_environment_type()" 'Produktionsspärr för testnycklar saknas'
Assert-Contains $central "new WP_Error('turnstile_missing')" 'Saknad token blockeras inte'
Assert-Contains $central "new WP_Error('turnstile_action_mismatch')" 'Turnstile action kontrolleras inte'
Assert-Contains $central "new WP_Error('turnstile_hostname_mismatch')" 'Turnstile hostname kontrolleras inte'

Assert-BeforeAfter 'wp-content\plugins\ssf-site-customizations\includes\forms.php' 'function ssf_site_handle_contact' "SSF_Antispam::validate('contact')" 'wp_insert_post(' 'Kontakt sparar före antispam'
Assert-Before 'wp-content\plugins\ssf-medlemsprocess\includes\class-ssf-medlemsprocess-public.php' "SSF_Antispam::validate('membership_application')" 'SSF_Medlemsprocess_Application::create' 'Medlemsansökan sparar före antispam'
Assert-Before 'wp-content\plugins\ssf-member-portal\includes\Modules\Motions\Frontend\Controller.php' "SSF_Antispam::validate('motion')" '$this->service->submit' 'Motion behandlas före antispam'
Assert-Before 'wp-content\plugins\ssf-member-portal\includes\Modules\AnnualMeetings\Frontend.php' "SSF_Antispam::validate('annual_meeting_registration')" '$this->registrations->submit' 'Årsmötesanmälan behandlas före antispam'
Assert-Before 'wp-content\plugins\ssf-medlemsfartyg\includes\class-ssf-medlemsfartyg-public-form.php' "SSF_Antispam::validate('vessel_update')" '$this->handle_uploads' 'Fartygsfiler behandlas före antispam'

$renders = @(
    @('wp-content\plugins\ssf-site-customizations\includes\shortcodes.php', "render('contact')"),
    @('wp-content\plugins\ssf-medlemsprocess\templates\application-form.php', "render('membership_application')"),
    @('wp-content\plugins\ssf-member-portal\templates\motions\form.php', "render('motion')"),
    @('wp-content\plugins\ssf-member-portal\templates\annual-meetings\form.php', "render('annual_meeting_registration')")
)
foreach ($render in $renders) { Assert-Contains $render[0] $render[1] ("Widget saknas i " + $render[0]) }

if ($failures.Count -gt 0) {
    $failures | ForEach-Object { Write-Error $_ }
    exit 1
}

Write-Host 'SSF antispam static tests: PASS'
