param(
    [string] $BaseUrl = 'https://ssfb.se/dev',
    [Parameter(Mandatory = $true)]
    [string] $Username,
    [Parameter(Mandatory = $true)]
    [string] $Password,
    [ValidateSet('all', 'annual_meetings', 'membership_applications')]
    [string] $Destination = 'all'
)

$ErrorActionPreference = 'Stop'

function Decode-Html([string] $Value) {
    Add-Type -AssemblyName System.Web
    return [System.Web.HttpUtility]::HtmlDecode($Value)
}

function Get-InputAttributes([string] $Tag) {
    $attrs = @{}
    foreach ($match in [regex]::Matches($Tag, '([a-zA-Z0-9_\-\[\]]+)\s*=\s*("([^"]*)"|''([^'']*)''|([^\s>]+))')) {
        $value = $match.Groups[3].Value
        if ($value -eq '') { $value = $match.Groups[4].Value }
        if ($value -eq '') { $value = $match.Groups[5].Value }
        $attrs[$match.Groups[1].Value.ToLowerInvariant()] = Decode-Html $value
    }
    return $attrs
}

function Get-SharePointNonce([string] $Html) {
    $match = [regex]::Match($Html, 'ssfSharePointAdmin\s*=\s*\{[^}]*"nonce"\s*:\s*"([^"]+)"', 'Singleline')
    if (-not $match.Success) {
        $match = [regex]::Match($Html, "ssfSharePointAdmin\s*=\s*\{[^}]*nonce['""]?\s*[:=]\s*['""]([^'""]+)['""]", 'Singleline')
    }
    if (-not $match.Success) {
        throw 'Kunde inte hitta SharePoint admin nonce.'
    }
    return $match.Groups[1].Value
}

function Get-Profile([string] $Html) {
    $profile = [ordered]@{ metadata = [ordered]@{} }

    foreach ($match in [regex]::Matches($Html, '<input\b[^>]*name="profile\[([^"\]]+)\]"[^>]*>', 'IgnoreCase')) {
        $attrs = Get-InputAttributes $match.Value
        $key = $match.Groups[1].Value
        $profile[$key] = if ($attrs.ContainsKey('value')) { $attrs['value'] } else { '' }
    }

    foreach ($match in [regex]::Matches($Html, '<select\b[^>]*name="profile\[metadata\]\[([^"\]]+)\]"[^>]*>(.*?)</select>', 'IgnoreCase,Singleline')) {
        $key = $match.Groups[1].Value
        $selected = [regex]::Match($match.Groups[2].Value, '<option\b[^>]*selected[^>]*>', 'IgnoreCase')
        if (-not $selected.Success) {
            $selected = [regex]::Match($match.Groups[2].Value, '<option\b[^>]*>', 'IgnoreCase')
        }
        $attrs = if ($selected.Success) { Get-InputAttributes $selected.Value } else { @{} }
        $profile.metadata[$key] = if ($attrs.ContainsKey('value')) { $attrs['value'] } else { '' }
    }

    return $profile
}

function Invoke-AdminAjax([string] $BaseUrl, [string] $CookieJar, [string] $Nonce, [string] $Destination, [string] $Operation, [object] $Profile, [string] $OutFile) {
    $json = $Profile | ConvertTo-Json -Depth 8 -Compress
    & curl.exe -sS -L -b $CookieJar -c $CookieJar `
        --data-urlencode 'action=ssf_sharepoint_admin' `
        --data-urlencode "nonce=$Nonce" `
        --data-urlencode "operation=$Operation" `
        --data-urlencode "destination=$Destination" `
        --data-urlencode 'environment=production' `
        --data-urlencode "profile=$json" `
        "$($BaseUrl.TrimEnd('/'))/wp-admin/admin-ajax.php" -o $OutFile
    $content = Get-Content -Raw -LiteralPath $OutFile
    try {
        return $content | ConvertFrom-Json
    } catch {
        throw "Admin AJAX returnerade inte JSON for $Destination/$Operation."
    }
}

function Test-Columns([string] $Destination, [object[]] $Columns, [object] $Profile) {
    $requirements = @()
    if ($Destination -eq 'membership_applications') {
        $requirements = @(
            @{ Key = 'number'; Type = 'text'; Choices = @() },
            @{ Key = 'vessel'; Type = 'text'; Choices = @() },
            @{ Key = 'route'; Type = 'choice'; Choices = @('Normalfallet', 'Mindre registrerat fartyg', 'Fartyg under restaurering', 'Nybyggt traditionsfartyg') },
            @{ Key = 'status'; Type = 'choice'; Choices = @('Inkommen', 'Under granskning', 'Begär komplettering', 'Väntar på komplettering', 'Inspektion ska bokas', 'Inspektion bokad', 'Under slutbedömning', 'Godkänd som aspirant', 'Avslagen') },
            @{ Key = 'membership_status'; Type = 'choice'; Choices = @('Ej medlem', 'Aspirant', 'Uppföljning', 'Medlemsfartyg', 'Avslutad') },
            @{ Key = 'received'; Type = 'dateTime'; Choices = @() },
            @{ Key = 'decision_date'; Type = 'dateTime'; Choices = @() },
            @{ Key = 'aspirant_start'; Type = 'dateTime'; Choices = @() },
            @{ Key = 'aspirant_review'; Type = 'dateTime'; Choices = @() },
            @{ Key = 'wordpress_id'; Type = 'text'; Choices = @() }
        )
    } else {
        $requirements = @(
            @{ Key = 'wordpress_id'; Type = 'text'; Choices = @() },
            @{ Key = 'number'; Type = 'text'; Choices = @() },
            @{ Key = 'status'; Type = 'choice'; Choices = @('Inkommen', 'Under behandling', 'Begär komplettering', 'Färdigbehandlad av styrelsen', 'Till årsmötet', 'Beslutad på årsmötet', 'Avslutad') },
            @{ Key = 'vessel'; Type = 'text'; Choices = @() },
            @{ Key = 'received'; Type = 'dateTime'; Choices = @() }
        )
    }

    $byName = @{}
    foreach ($column in $Columns) {
        $byName[[string] $column.name] = $column
    }

    $results = @()
    foreach ($req in $requirements) {
        $name = [string] $Profile.metadata.($req.Key)
        $column = if ($name -and $byName.ContainsKey($name)) { $byName[$name] } else { $null }
        $missingChoices = @()
        if ($column -and $req.Choices.Count -gt 0) {
            $found = @($column.choices | ForEach-Object { [string] $_ })
            $missingChoices = @($req.Choices | Where-Object { $found -notcontains $_ })
        }
        $results += [pscustomobject]@{
            Key = $req.Key
            Name = $name
            Found = [bool] $column
            Type = if ($column) { [string] $column.type } else { '' }
            ExpectedType = $req.Type
            TypeOk = [bool] ($column -and [string] $column.type -eq [string] $req.Type)
            MissingChoices = $missingChoices
            Ok = [bool] ($column -and [string] $column.type -eq [string] $req.Type -and $missingChoices.Count -eq 0)
        }
    }
    return $results
}

$destinations = if ($Destination -eq 'all') { @('annual_meetings', 'membership_applications') } else { @($Destination) }
$cookieJar = Join-Path $env:TEMP ('ssfb-prod-sp-cookies-' + [guid]::NewGuid().ToString('N') + '.txt')
$pagePath = Join-Path $env:TEMP ('ssfb-prod-sp-page-' + [guid]::NewGuid().ToString('N') + '.html')
$ajaxPath = Join-Path $env:TEMP ('ssfb-prod-sp-ajax-' + [guid]::NewGuid().ToString('N') + '.json')

try {
    $BaseUrl = $BaseUrl.TrimEnd('/')
    & curl.exe -sS -L -c $cookieJar -b $cookieJar "$BaseUrl/wp-login.php" -o $pagePath
    & curl.exe -sS -L -c $cookieJar -b $cookieJar `
        --data-urlencode "log=$Username" `
        --data-urlencode "pwd=$Password" `
        --data-urlencode 'wp-submit=Logga in' `
        --data-urlencode "redirect_to=$BaseUrl/wp-admin/admin.php?page=ssf-member-portal-microsoft365" `
        --data-urlencode 'testcookie=1' `
        "$BaseUrl/wp-login.php" -o $pagePath
    $loginHtml = Get-Content -Raw -LiteralPath $pagePath
    if ($loginHtml -match 'name="log"|wp-submit') {
        throw 'WordPress-inloggning misslyckades.'
    }

    $results = @()
    foreach ($destinationName in $destinations) {
        $url = "$BaseUrl/wp-admin/admin.php?page=ssf-member-portal-microsoft365&destination=$destinationName&profile_environment=production"
        & curl.exe -sS -L -b $cookieJar -c $cookieJar $url -o $pagePath
        $html = Get-Content -Raw -LiteralPath $pagePath
        $nonce = Get-SharePointNonce $html
        $profile = Get-Profile $html

        $diagnostics = Invoke-AdminAjax $BaseUrl $cookieJar $nonce $destinationName 'diagnostics' $profile $ajaxPath
        $columnsResponse = Invoke-AdminAjax $BaseUrl $cookieJar $nonce $destinationName 'columns' $profile $ajaxPath
        $columns = if ($columnsResponse.success) { @($columnsResponse.data) } else { @() }
        $columnChecks = if ($columnsResponse.success) { @(Test-Columns $destinationName $columns $profile) } else { @() }

        $results += [pscustomobject]@{
            Destination = $destinationName
            DiagnosticsOk = [bool] ($diagnostics.success -and $diagnostics.data.ok)
            Diagnostics = $diagnostics
            ColumnsOk = [bool] ($columnsResponse.success -and (@($columnChecks | Where-Object { -not $_.Ok }).Count -eq 0))
            ColumnChecks = $columnChecks
            ReadOnly = $true
        }
    }

    $overall = [bool] (@($results | Where-Object { -not ($_.DiagnosticsOk -and $_.ColumnsOk) }).Count -eq 0)
    [pscustomobject]@{
        Ok = $overall
        EnvironmentProfile = 'production'
        CheckedAt = (Get-Date).ToUniversalTime().ToString('o')
        Results = $results
    } | ConvertTo-Json -Depth 14

    if (-not $overall) {
        exit 2
    }
} finally {
    foreach ($path in @($cookieJar, $pagePath, $ajaxPath)) {
        if (Test-Path -LiteralPath $path) { Remove-Item -LiteralPath $path -Force }
    }
}
