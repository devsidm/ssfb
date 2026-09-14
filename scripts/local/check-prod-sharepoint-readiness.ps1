param(
    [string] $BaseUrl = 'https://ssfb.se/dev',
    [Parameter(Mandatory = $true)]
    [string] $Username,
    [Parameter(Mandatory = $true)]
    [string] $Password,
    [ValidateSet('all', 'annual_meetings', 'membership_applications')]
    [string] $Destination = 'all',
    [string] $ExpectedMembershipFolderId = '01R636G55IV3Z2XJ2S3VA2AGTD4SG6NTTX'
)

$ErrorActionPreference = 'Stop'

function Decode-Html([string] $Value) {
    Add-Type -AssemblyName System.Web
    return [System.Web.HttpUtility]::HtmlDecode($Value)
}

function U([int[]] $Codepoints) {
    return -join ($Codepoints | ForEach-Object { [char] $_ })
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

    foreach ($match in [regex]::Matches($Html, '<input\b[^>]*>', 'IgnoreCase')) {
        $attrs = Get-InputAttributes $match.Value
        $name = if ($attrs.ContainsKey('name')) { [string] $attrs['name'] } else { '' }
        $nameMatch = [regex]::Match($name, '^profile\[([^\]]+)\]$')
        if (-not $nameMatch.Success) { continue }
        $key = $nameMatch.Groups[1].Value
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
    $profilePath = Join-Path $env:TEMP ('ssfb-prod-sp-profile-' + [guid]::NewGuid().ToString('N') + '.json')
    try {
        [IO.File]::WriteAllText($profilePath, $json, [Text.UTF8Encoding]::new($false))
        & curl.exe -sS -L -b $CookieJar -c $CookieJar `
            --data-urlencode 'action=ssf_sharepoint_admin' `
            --data-urlencode "nonce=$Nonce" `
            --data-urlencode "operation=$Operation" `
            --data-urlencode "destination=$Destination" `
            --data-urlencode 'environment=production' `
            --data-urlencode "profile@$profilePath" `
            "$($BaseUrl.TrimEnd('/'))/wp-admin/admin-ajax.php" -o $OutFile
        $content = Get-Content -Raw -LiteralPath $OutFile
        try {
            return $content | ConvertFrom-Json
        } catch {
            throw "Admin AJAX returnerade inte JSON for $Destination/$Operation."
        }
    } finally {
        if (Test-Path -LiteralPath $profilePath) { Remove-Item -LiteralPath $profilePath -Force }
    }
}

function Test-Columns([string] $Destination, [object[]] $Columns, [object] $Profile) {
    $requirements = @()
    if ($Destination -eq 'membership_applications') {
        $requirements = @(
            @{ Key = 'number'; Type = 'text'; Choices = @() },
            @{ Key = 'vessel'; Type = 'text'; Choices = @() },
            @{ Key = 'route'; Type = 'choice'; Choices = @('Normalfallet', 'Mindre registrerat fartyg', 'Fartyg under restaurering', 'Nybyggt traditionsfartyg') },
            @{ Key = 'status'; Type = 'choice'; Choices = @('Inkommen', 'Under granskning', (U @(66, 101, 103, 228, 114, 32, 107, 111, 109, 112, 108, 101, 116, 116, 101, 114, 105, 110, 103)), (U @(86, 228, 110, 116, 97, 114, 32, 112, 229, 32, 107, 111, 109, 112, 108, 101, 116, 116, 101, 114, 105, 110, 103)), 'Inspektion ska bokas', 'Inspektion bokad', (U @(85, 110, 100, 101, 114, 32, 115, 108, 117, 116, 98, 101, 100, 246, 109, 110, 105, 110, 103)), (U @(71, 111, 100, 107, 228, 110, 100, 32, 115, 111, 109, 32, 97, 115, 112, 105, 114, 97, 110, 116)), 'Avslagen') },
            @{ Key = 'membership_status'; Type = 'choice'; Choices = @('Ej medlem', 'Aspirant', (U @(85, 112, 112, 102, 246, 108, 106, 110, 105, 110, 103)), 'Medlemsfartyg', 'Avslutad') },
            @{ Key = 'received'; Type = 'dateOnly'; Choices = @() },
            @{ Key = 'decision_date'; Type = 'dateOnly'; Choices = @() },
            @{ Key = 'aspirant_start'; Type = 'dateOnly'; Choices = @() },
            @{ Key = 'aspirant_review'; Type = 'dateOnly'; Choices = @() },
            @{ Key = 'wordpress_id'; Type = 'text'; Choices = @() }
        )
    } else {
        $requirements = @(
            @{ Key = 'wordpress_id'; Type = 'text'; Choices = @() },
            @{ Key = 'number'; Type = 'text'; Choices = @() },
            @{ Key = 'status'; Type = 'choice'; Choices = @('Inkommen', 'Under behandling', 'BegÃ¤r komplettering', 'FÃ¤rdigbehandlad av styrelsen', 'Till Ã¥rsmÃ¶tet', 'Beslutad pÃ¥ Ã¥rsmÃ¶tet', 'Avslutad') },
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
        $extraChoices = @()
        $choiceOrderOk = $true
        if ($column -and $req.Choices.Count -gt 0) {
            $found = @($column.choices | ForEach-Object { [string] $_ })
            $missingChoices = @($req.Choices | Where-Object { $found -notcontains $_ })
            $extraChoices = @($found | Where-Object { $req.Choices -notcontains $_ })
            $choiceOrderOk = $found.Count -eq $req.Choices.Count
            if ($choiceOrderOk) {
                for ($i = 0; $i -lt $req.Choices.Count; $i++) {
                    if ($found[$i] -ne $req.Choices[$i]) {
                        $choiceOrderOk = $false
                        break
                    }
                }
            }
        }
        $actualType = if ($column) { [string] $column.type } else { '' }
        $actualDateFormat = if ($column) { [string] $column.date_time_format } else { '' }
        $typeOk = if ([string] $req.Type -eq 'dateOnly') {
            [bool] ($column -and $actualType -eq 'dateTime' -and $actualDateFormat -eq 'dateOnly')
        } else {
            [bool] ($column -and $actualType -eq [string] $req.Type)
        }
        $results += [pscustomobject]@{
            Key = $req.Key
            Name = $name
            Found = [bool] $column
            Type = $actualType
            DateTimeFormat = $actualDateFormat
            ExpectedType = $req.Type
            TypeOk = $typeOk
            MissingChoices = $missingChoices
            ExtraChoices = $extraChoices
            ChoiceOrderOk = $choiceOrderOk
            ChoicesOk = [bool] ($missingChoices.Count -eq 0 -and $extraChoices.Count -eq 0 -and $choiceOrderOk)
            Ok = [bool] ($column -and $typeOk -and $missingChoices.Count -eq 0 -and $extraChoices.Count -eq 0 -and $choiceOrderOk)
        }
    }
    return $results
}

function Get-StepOk([object] $Diagnostics, [string] $Name) {
    return [bool] ($Diagnostics.success -and $Diagnostics.data.steps.$Name.ok)
}

function Get-ChoiceScore([object[]] $ColumnChecks, [string] $Key, [int] $ExpectedCount) {
    $check = @($ColumnChecks | Where-Object { $_.Key -eq $Key }) | Select-Object -First 1
    if (-not $check -or -not $check.Found) { return "0/$ExpectedCount" }
    $missing = @($check.MissingChoices).Count
    $extra = @($check.ExtraChoices).Count
    $score = $ExpectedCount - $missing
    if ($extra -gt 0 -or -not $check.ChoiceOrderOk) { $score = [Math]::Min($score, $ExpectedCount - 1) }
    if ($score -lt 0) { $score = 0 }
    return "$score/$ExpectedCount"
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
        $folderIdMatches = if ($destinationName -eq 'membership_applications' -and $ExpectedMembershipFolderId) {
            [bool] ($profile.folder_id -eq $ExpectedMembershipFolderId -and (Get-StepOk $diagnostics 'folder'))
        } else {
            [bool] (Get-StepOk $diagnostics 'folder')
        }
        $summary = [ordered]@{
            Site = if (Get-StepOk $diagnostics 'site') { 'PASS' } else { 'FAIL' }
            Drive = if (Get-StepOk $diagnostics 'drive') { 'PASS' } else { 'FAIL' }
            List = if (Get-StepOk $diagnostics 'list') { 'PASS' } else { 'FAIL' }
            BaseFolder = if ((Get-StepOk $diagnostics 'folder') -and $folderIdMatches) { 'PASS' } else { 'FAIL' }
            Columns = ('{0}/{1}' -f @($columnChecks | Where-Object { $_.Found -and $_.TypeOk }).Count, @($columnChecks).Count)
            ApplicationPath = Get-ChoiceScore $columnChecks 'route' 4
            ApplicationStatus = Get-ChoiceScore $columnChecks 'status' 9
            MembershipStatus = Get-ChoiceScore $columnChecks 'membership_status' 5
        }

        $results += [pscustomobject]@{
            Destination = $destinationName
            DiagnosticsOk = [bool] ($diagnostics.success -and $diagnostics.data.ok)
            Diagnostics = $diagnostics
            ColumnsOk = [bool] ($columnsResponse.success -and (@($columnChecks | Where-Object { -not $_.Ok }).Count -eq 0))
            ColumnChecks = $columnChecks
            ExpectedFolderId = $ExpectedMembershipFolderId
            FolderIdMatches = $folderIdMatches
            Summary = $summary
            ReadOnly = $true
        }
    }

    $overall = [bool] (@($results | Where-Object { -not ($_.DiagnosticsOk -and $_.ColumnsOk -and $_.FolderIdMatches) }).Count -eq 0)
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
