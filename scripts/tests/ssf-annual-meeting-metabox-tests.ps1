[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$module = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-member-portal\includes\Modules\AnnualMeetings\Module.php')
$script = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-member-portal\assets\js\annual-meetings-admin.js')
$styles = Get-Content -Raw -LiteralPath (Join-Path $repo 'wp-content\plugins\ssf-member-portal\assets\css\annual-meetings-admin.css')
$failures = [Collections.Generic.List[string]]::new()

function Assert-True([string] $name, [bool] $condition) {
    if (-not $condition) { $failures.Add($name) }
}

function Repair-MetaBoxOrder([hashtable] $order) {
    $editor = 'ssf-member-portal-meeting'
    foreach ($context in @('normal', 'advanced', 'side')) {
        if (-not $order.ContainsKey($context) -or $order[$context] -isnot [string]) { continue }
        $order[$context] = (($order[$context] -split ',').Trim() | Where-Object { $_ -and $_ -ne $editor }) -join ','
    }
    $normal = if ($order.ContainsKey('normal')) { @($order.normal -split ',' | Where-Object { $_ }) } else { @() }
    $order.normal = (@($editor) + $normal | Select-Object -Unique) -join ','
    return $order
}

$legacy = Repair-MetaBoxOrder @{
    normal = 'postexcerpt,ssf-member-portal-meeting-status'
    advanced = 'slugdiv'
    side = 'submitdiv,ssf-member-portal-meeting,postimagediv'
}
$default = Repair-MetaBoxOrder @{
    normal = 'ssf-member-portal-meeting,postexcerpt'
    side = 'submitdiv,postimagediv'
}

Assert-True 'Editor registreras i normal med high priority' ($module.Contains("add_meta_box(self::EDITOR_META_BOX_ID, __('Redigera årsmötet', 'ssf-member-portal'), array(`$this, 'render_meta_box'), self::POST_TYPE, 'normal', 'high')"))
Assert-True 'Korrigeringen är begränsad till årsmötesskärmens user option' ($module.Contains("get_user_option_meta-box-order_' . self::POST_TYPE"))
Assert-True 'Befintlig PROD-layout flyttar editorn till normal' ($legacy.normal.Split(',')[0] -eq 'ssf-member-portal-meeting' -and $legacy.side -eq 'submitdiv,postimagediv')
Assert-True 'Ny standardlayout behåller exakt en editor i normal' ((@($default.normal.Split(',') | Where-Object { $_ -eq 'ssf-member-portal-meeting' })).Count -eq 1)
Assert-True 'Publicera och utvald bild ligger kvar i side' ($legacy.side -eq 'submitdiv,postimagediv')
Assert-True 'Andra normal- och advanced-boxar behålls' ($legacy.normal -eq 'ssf-member-portal-meeting,postexcerpt,ssf-member-portal-meeting-status' -and $legacy.advanced -eq 'slugdiv')
Assert-True 'Just editorboxens draghandtag låses' ($script.Contains("#ssf-member-portal-meeting > .postbox-header") -and $script.Contains("sortable('option', 'cancel'"))
Assert-True 'Ingen global sidebar-breddhack finns' (-not ($styles -match '#postbox-container-1\s*\{[^}]*width\s*:\s*100%'))
Assert-True 'Responsiva containergränser finns kvar' ($styles.Contains('@container (max-width: 760px)') -and $styles.Contains('@container (max-width: 520px)'))

if ($failures.Count) {
    $failures | ForEach-Object { Write-Error "FAIL: $_" }
    exit 1
}

Write-Host 'PASS: Årsmöteseditorn stannar i huvudkolumnen utan att andra metaboxar flyttas.'
