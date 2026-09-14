param(
    [string] $BaseUrl = 'https://ssfb.se/dev',
    [Parameter(Mandatory = $true)]
    [string] $Username,
    [Parameter(Mandatory = $true)]
    [string] $Password,
    [string] $TargetEmail = 'linde@kent.nu',
    [ValidateSet('all', 'membership', 'motion', 'contact')]
    [string] $Flow = 'all',
    [int] $WaitSeconds = 125
)

$ErrorActionPreference = 'Stop'

function Join-Url([string] $Base, [string] $Path) {
    return $Base.TrimEnd('/') + '/' + $Path.TrimStart('/')
}

function Decode-Html([string] $Value) {
    Add-Type -AssemblyName System.Web
    return [System.Web.HttpUtility]::HtmlDecode($Value)
}

function Get-InputAttributes([string] $Tag) {
    $attrs = @{}
    foreach ($match in [regex]::Matches($Tag, '([a-zA-Z0-9_\-:]+)\s*=\s*("([^"]*)"|''([^'']*)''|([^\s>]+))')) {
        $value = $match.Groups[3].Value
        if ($value -eq '') { $value = $match.Groups[4].Value }
        if ($value -eq '') { $value = $match.Groups[5].Value }
        $attrs[$match.Groups[1].Value.ToLowerInvariant()] = Decode-Html $value
    }
    return $attrs
}

function Get-HiddenFields([string] $Html) {
    $fields = [ordered]@{}
    foreach ($match in [regex]::Matches($Html, '<input\b[^>]*>', 'IgnoreCase')) {
        $attrs = Get-InputAttributes $match.Value
        if (-not $attrs.ContainsKey('name')) { continue }
        $type = if ($attrs.ContainsKey('type')) { $attrs['type'].ToLowerInvariant() } else { 'text' }
        if ($type -eq 'hidden') {
            $fields[$attrs['name']] = if ($attrs.ContainsKey('value')) { $attrs['value'] } else { '' }
        }
    }
    return $fields
}

function Invoke-CurlGet([string] $Url, [string] $CookieJar, [string] $OutFile) {
    & curl.exe -s -L -c $CookieJar -b $CookieJar $Url -o $OutFile | Out-Null
    return Get-Content $OutFile -Raw
}

function Invoke-CurlPostUrlEncoded([string] $Url, [object] $Body, [string] $CookieJar, [string] $OutFile) {
    $args = @('-s', '-L', '-c', $CookieJar, '-b', $CookieJar)
    foreach ($key in $Body.Keys) {
        $args += '--data-urlencode'
        $args += ($key + '=' + [string] $Body[$key])
    }
    $args += $Url
    $args += '-o'
    $args += $OutFile
    $args += '-w'
    $args += 'FINAL=%{url_effective}'
    $writeOut = & curl.exe @args
    return [pscustomobject]@{
        FinalUrl = ($writeOut -replace '^FINAL=', '').Trim()
        Content = Get-Content $OutFile -Raw
    }
}

function Invoke-CurlPostMultipart([string] $Url, [object] $Fields, [string] $FileField, [string] $FilePath, [string] $CookieJar, [string] $OutFile) {
    $args = @('-s', '-L', '-c', $CookieJar, '-b', $CookieJar)
    foreach ($key in $Fields.Keys) {
        $args += '-F'
        $args += ($key + '=' + [string] $Fields[$key])
    }
    $args += '-F'
    $args += ($FileField + '=@' + $FilePath + ';type=application/pdf')
    $args += $Url
    $args += '-o'
    $args += $OutFile
    $args += '-w'
    $args += 'FINAL=%{url_effective}'
    $writeOut = & curl.exe @args
    return [pscustomobject]@{
        FinalUrl = ($writeOut -replace '^FINAL=', '').Trim()
        Content = Get-Content $OutFile -Raw
    }
}

function Login-Dev([string] $BaseUrl, [string] $Username, [string] $Password, [string] $CookieJar, [string] $OutFile) {
    $loginUrl = Join-Url $BaseUrl 'wp-login.php'
    $adminUrl = Join-Url $BaseUrl 'wp-admin/'
    & curl.exe -s -L -c $CookieJar -b $CookieJar $loginUrl -o $OutFile | Out-Null
    $writeOut = & curl.exe -s -L -c $CookieJar -b $CookieJar `
        -d ('log=' + [System.Uri]::EscapeDataString($Username)) `
        -d ('pwd=' + [System.Uri]::EscapeDataString($Password)) `
        -d 'wp-submit=Log%20In' `
        -d ('redirect_to=' + [System.Uri]::EscapeDataString($adminUrl)) `
        -d 'testcookie=1' `
        $loginUrl -o $OutFile -w 'FINAL=%{url_effective}'
    $html = Get-Content $OutFile -Raw
    if ($html -match 'name="log"|wp-submit' -or $writeOut -notmatch '/wp-admin/?') {
        throw 'WordPress-inloggning misslyckades.'
    }
}

function New-MembershipBody([object] $Hidden, [hashtable] $Route, [string] $TargetEmail, [int] $Index, [string] $Stamp) {
    $body = [ordered]@{}
    foreach ($key in $Hidden.Keys) { $body[$key] = $Hidden[$key] }

    $label = $Route.Label
    $shipName = "DEV TEST $Index $label $Stamp"
    $body['website'] = ''
    $body['ssf_antispam_website'] = ''
    $body['applicant_first_name'] = 'Devtest'
    $body['applicant_last_name'] = $label
    $body['applicant_phone'] = '070-000 00 00'
    $body['applicant_organization'] = 'SSF Devtest'
    $body['applicant_street'] = 'Testgatan 1'
    $body['applicant_postal_code'] = '111 22'
    $body['applicant_city'] = 'Stockholm'
    $body['applicant_website'] = $BaseUrl
    $body['applicant_email'] = $TargetEmail
    $body['applicant_invoice_email'] = $TargetEmail
    $body['application_route'] = $Route.Key
    $body['post_title'] = $shipName
    $body['_ssf_previous_names'] = "Tidigare $shipName"
    $body['_ssf_build_year'] = $Route.BuildYear
    $body['_ssf_build_place'] = 'Stockholm'
    $body['_ssf_shipyard'] = 'Testvarvet'
    $body['_ssf_owner'] = 'SSF Devtest'
    $body['_ssf_build_country'] = 'Sverige'
    $body['_ssf_nationality'] = 'Svensk'
    $body['_ssf_home_port'] = 'Stockholm'
    $body['_ssf_call_sign'] = "DEV$Index"
    $body['tax_fartygstyp'] = 'Galeas'
    $body['_ssf_vessel_operation'] = 'leisure'
    $body['_ssf_rig'] = 'Gaffelrigg'
    $body['_ssf_hull_type'] = 'Deplacement'
    $body['_ssf_material'] = 'Tra'
    $body['_ssf_main_deck_length'] = $Route.Length
    $body['_ssf_length'] = $Route.Length
    $body['_ssf_length_overall'] = $Route.LengthOverall
    $body['_ssf_total_length_spars'] = $Route.TotalLength
    $body['_ssf_beam'] = $Route.Beam
    $body['_ssf_draft'] = '1.80'
    $body['_ssf_previous_use'] = 'Automatiskt testunderlag for tidigare anvandning i dev.'
    $body['_ssf_history'] = "Automatisk testansokan i dev for flodet $label. Underlaget ar skapat for att verifiera handlaggning, e-post och SharePoint-status."
    $body['_ssf_previous_home_ports'] = 'Stockholm'
    $body['_ssf_previous_owners'] = 'Testagare'
    $body['_ssf_professional_use'] = 'yes'
    $body['_ssf_professional_use_description'] = 'Anvand som seglande yrkesfartyg enligt testscenario.'
    $body['_ssf_masts'] = '2'
    $body['_ssf_original_rig'] = 'Gaffelrigg'
    $body['_ssf_sail_area'] = '120'
    $body['_ssf_rig_description'] = 'Testbeskrivning av rigg.'
    $body['_ssf_rig_period'] = 'historical'
    $body['_ssf_has_aux_engine'] = 'yes'
    $body['_ssf_engine'] = 'Testmotor'
    $body['post_excerpt'] = "Kort presentation for $shipName."
    $body['post_content'] = "Publik testbeskrivning for $shipName."
    $body['_ssf_today'] = 'Anvands for funktionstest i dev.'
    $body['_ssf_activity'] = 'Testverksamhet.'
    $body['_ssf_future'] = 'Fortsatt test av medlemsprocessen.'

    if ($Route.Key -eq 'small_registered') {
        $body['_ssf_registration_type'] = 'ship'
        $body['_ssf_registry_number'] = "DEV-REG-$Stamp-$Index"
        $body['_ssf_registration_country'] = 'Sverige'
        $body['_ssf_registered_confirmation'] = '1'
    }
    if ($Route.Key -eq 'restoration') {
        $body['_ssf_restoration_condition'] = 'Fartyget ar under restaurering enligt testscenario.'
        $body['_ssf_restoration_remaining'] = 'Slutlig dokumentation och mindre arbeten aterstar.'
        $body['_ssf_restoration_goal'] = 'Malet ar att aterfora fartyget som seglande traditionsfartyg.'
        $body['_ssf_preservation_plan'] = 'Planen beskriver bevarande, dokumentation och aterstallning i etapper.'
        $body['_ssf_restoration_timeline'] = 'Testad tidsplan med milstolpar.'
        $body['_ssf_restoration_documented'] = 'yes'
    }
    if ($Route.Key -eq 'new_traditional') {
        $body['_ssf_traditional_archetype'] = 'Aldre svenskt yrkessegelfartyg'
        $body['_ssf_traditional_traditions'] = 'Byggt enligt vedertagna traditioner for material, rigg och uttryck.'
        $body['_ssf_traditional_construction'] = 'Traditionell konstruktion med testunderlag for handlaggning.'
        $body['_ssf_traditional_rig'] = 'Gaffelrigg enligt historisk forbild.'
        $body['_ssf_traditional_reference'] = 'Historisk referens beskriven i testunderlaget.'
        $body['_ssf_designer'] = 'Devtest'
    }

    $body['confirm_accuracy'] = '1'
    $body['privacy_consent'] = '1'
    $body['upload_rights'] = '1'
    return $body
}

function Invoke-MembershipSmoke([string] $BaseUrl, [string] $TargetEmail, [string] $CookieJar, [string] $OutFile, [int] $WaitSeconds) {
    $routes = @(
        @{ Key = 'normal'; Label = 'Normalfallet'; Length = '13.20'; LengthOverall = '15.00'; TotalLength = '16.00'; Beam = '4.20'; BuildYear = '1926' },
        @{ Key = 'small_registered'; Label = 'Mindre registrerat fartyg'; Length = '9.80'; LengthOverall = '10.50'; TotalLength = '11.00'; Beam = '3.40'; BuildYear = '1934' },
        @{ Key = 'restoration'; Label = 'Fartyg under restaurering'; Length = '12.40'; LengthOverall = '13.30'; TotalLength = '14.00'; Beam = '3.90'; BuildYear = '1912' },
        @{ Key = 'new_traditional'; Label = 'Nybyggt traditionsfartyg'; Length = '14.20'; LengthOverall = '15.40'; TotalLength = '16.10'; Beam = '4.30'; BuildYear = '2024' }
    )
    $formUrl = Join-Url $BaseUrl 'ansokan/'
    $submitUrl = Join-Url $BaseUrl 'wp-admin/admin-post.php'
    $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
    $created = @()

    for ($i = 0; $i -lt $routes.Count; $i++) {
        $route = $routes[$i]
        $done = $false
        $attempt = 0
        while (-not $done -and $attempt -lt 4) {
            $attempt++
            Write-Host ("Membership: hamtar formular for {0}..." -f $route.Label)
            $html = Invoke-CurlGet $formUrl $CookieJar $OutFile
            $hidden = Get-HiddenFields $html
            if (-not $hidden.Contains('_wpnonce') -or -not $hidden.Contains('submission_key')) {
                throw "Kunde inte hitta nonce/submission_key for $($route.Label)."
            }
            $body = New-MembershipBody $hidden $route $TargetEmail ($i + 1) $stamp
            $response = Invoke-CurlPostUrlEncoded $submitUrl $body $CookieJar $OutFile
            if ($response.FinalUrl -match 'ssf_application_sent=1') {
                $created += [pscustomobject]@{
                    Flow = 'membership'
                    Route = $route.Key
                    Label = $route.Label
                    Mail = if ($response.FinalUrl -match 'ssf_mail=sent') { 'sent' } elseif ($response.FinalUrl -match 'ssf_mail=failed') { 'failed' } else { 'unknown' }
                    FinalUrl = $response.FinalUrl
                }
                Write-Host ("Membership: KLAR {0}" -f $route.Label)
                $done = $true
            } elseif ($response.Content -match 'For manga forsok|F.{0,2}r m.{0,2}nga f.{0,2}rs.{0,2}k') {
                Write-Host ("Membership: rate limit, vantar {0}s..." -f $WaitSeconds)
                Start-Sleep -Seconds $WaitSeconds
            } else {
                $plain = ($response.Content -replace '<[^>]+>', ' ' -replace '\s+', ' ').Trim()
                throw ("Membership misslyckades for {0}. URL: {1}. Svar: {2}" -f $route.Label, $response.FinalUrl, $plain.Substring(0, [Math]::Min(500, $plain.Length)))
            }
        }
        if (-not $done) { throw "Membership misslyckades for $($route.Label)." }
        if ($i -lt ($routes.Count - 1)) {
            Write-Host ("Membership: vantar {0}s pa formularsparren..." -f $WaitSeconds)
            Start-Sleep -Seconds $WaitSeconds
        }
    }
    return $created
}

function New-TestPdf([string] $Path, [string] $Title) {
    $pdf = @"
%PDF-1.4
1 0 obj
<< /Type /Catalog /Pages 2 0 R >>
endobj
2 0 obj
<< /Type /Pages /Kids [3 0 R] /Count 1 >>
endobj
3 0 obj
<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>
endobj
4 0 obj
<< /Length 82 >>
stream
BT /F1 18 Tf 72 720 Td ($Title) Tj 0 -30 Td (Automatisk dev-testmotion.) Tj ET
endstream
endobj
5 0 obj
<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>
endobj
xref
0 6
0000000000 65535 f 
0000000009 00000 n 
0000000058 00000 n 
0000000115 00000 n 
0000000251 00000 n 
0000000384 00000 n 
trailer
<< /Size 6 /Root 1 0 R >>
startxref
454
%%EOF
"@
    Set-Content -LiteralPath $Path -Value $pdf -Encoding ASCII
}

function Invoke-MotionSmoke([string] $BaseUrl, [string] $TargetEmail, [string] $CookieJar, [string] $OutFile) {
    $formUrl = Join-Url $BaseUrl 'lamna-motion/'
    $submitUrl = Join-Url $BaseUrl 'wp-admin/admin-post.php'
    $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
    $html = Invoke-CurlGet $formUrl $CookieJar $OutFile
    if ($html -notmatch 'ssf_member_portal_submit_motion') {
        $plain = ($html -replace '<[^>]+>', ' ' -replace '\s+', ' ').Trim()
        throw ('Motionsformular saknas eller ar stangt. Svar: ' + $plain.Substring(0, [Math]::Min(500, $plain.Length)))
    }
    $hidden = Get-HiddenFields $html
    if (-not $hidden.Contains('ssf_member_portal_motion_nonce') -or -not $hidden.Contains('meeting_id')) {
        throw 'Kunde inte hitta motionsnonce eller meeting_id.'
    }

    $pdfPath = Join-Path $env:TEMP ('ssf-dev-motion-' + $stamp + '.pdf')
    New-TestPdf $pdfPath ('SSF dev-testmotion ' + $stamp)
    try {
        $fields = [ordered]@{}
        foreach ($key in $hidden.Keys) { $fields[$key] = $hidden[$key] }
        $fields['company'] = ''
        $fields['ssf_antispam_website'] = ''
        $fields['name'] = 'Devtest Motion'
        $fields['email'] = $TargetEmail
        $fields['phone'] = '070-000 00 00'
        $fields['title'] = 'DEV TEST motion ' + $stamp
        $fields['content'] = 'Automatisk testmotion i dev. Anvands for att verifiera formularet, dokumentbilaga, statuslank, e-post och eventuell SharePoint-ko.'
        $response = Invoke-CurlPostMultipart $submitUrl $fields 'ssf_motion_files[]' $pdfPath $CookieJar $OutFile
    } finally {
        if (Test-Path $pdfPath) { Remove-Item -LiteralPath $pdfPath -Force }
    }

    if ($response.FinalUrl -notmatch 'confirmation=1') {
        $plain = ($response.Content -replace '<[^>]+>', ' ' -replace '\s+', ' ').Trim()
        throw ('Motion misslyckades. URL: ' + $response.FinalUrl + '. Svar: ' + $plain.Substring(0, [Math]::Min(500, $plain.Length)))
    }
    $motion = ''
    if ($response.FinalUrl -match 'motion=([^&]+)') {
        $motion = [System.Uri]::UnescapeDataString($Matches[1])
    }
    return @([pscustomobject]@{
        Flow = 'motion'
        Route = 'motion'
        Label = 'Motion'
        MotionNumber = $motion
        Mail = 'submitted'
        FinalUrl = $response.FinalUrl
    })
}

function Invoke-ContactSmoke([string] $BaseUrl, [string] $TargetEmail, [string] $CookieJar, [string] $OutFile) {
    $formUrl = Join-Url $BaseUrl 'kontakta-oss/'
    $submitUrl = Join-Url $BaseUrl 'wp-admin/admin-post.php'
    $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
    $html = Invoke-CurlGet $formUrl $CookieJar $OutFile
    if ($html -notmatch 'ssf_contact') {
        throw 'Kontaktformular saknas.'
    }
    $hidden = Get-HiddenFields $html
    if (-not $hidden.Contains('ssf_contact_nonce')) {
        throw 'Kunde inte hitta kontaktnonce.'
    }
    $body = [ordered]@{}
    foreach ($key in $hidden.Keys) { $body[$key] = $hidden[$key] }
    $body['website'] = ''
    $body['ssf_antispam_website'] = ''
    $body['namn'] = 'Devtest Kontakt'
    $body['epost'] = $TargetEmail
    $body['telefon'] = '070-000 00 00'
    $body['amne'] = 'DEV TEST kontakt ' + $stamp
    $body['meddelande'] = 'Automatiskt testmeddelande i dev for kontaktformular, e-postrouter och kontaktpost.'
    $response = Invoke-CurlPostUrlEncoded $submitUrl $body $CookieJar $OutFile
    if ($response.FinalUrl -notmatch 'ssf_status=contact_sent') {
        $plain = ($response.Content -replace '<[^>]+>', ' ' -replace '\s+', ' ').Trim()
        throw ('Kontaktformular misslyckades. URL: ' + $response.FinalUrl + '. Svar: ' + $plain.Substring(0, [Math]::Min(500, $plain.Length)))
    }
    return @([pscustomobject]@{
        Flow = 'contact'
        Route = 'contact'
        Label = 'Kontaktformular'
        Mail = 'submitted'
        FinalUrl = $response.FinalUrl
    })
}

$cookieJar = Join-Path $env:TEMP ('ssfb-dev-cookies-' + [guid]::NewGuid().ToString('N') + '.txt')
$outFile = Join-Path $env:TEMP ('ssfb-dev-form-smoke-' + [guid]::NewGuid().ToString('N') + '.html')
$results = @()

try {
    Write-Host 'Loggar in pa dev...'
    Login-Dev $BaseUrl $Username $Password $cookieJar $outFile
    Write-Host 'Inloggad.'

    if ($Flow -in @('all', 'membership')) {
        $results += Invoke-MembershipSmoke $BaseUrl $TargetEmail $cookieJar $outFile $WaitSeconds
    }
    if ($Flow -in @('all', 'motion')) {
        $results += Invoke-MotionSmoke $BaseUrl $TargetEmail $cookieJar $outFile
    }
    if ($Flow -in @('all', 'contact')) {
        $results += Invoke-ContactSmoke $BaseUrl $TargetEmail $cookieJar $outFile
    }

    Write-Host 'RESULTAT_JSON_START'
    $results | ConvertTo-Json -Depth 5
    Write-Host 'RESULTAT_JSON_END'
} finally {
    if (Test-Path $cookieJar) { Remove-Item -LiteralPath $cookieJar -Force }
    if (Test-Path $outFile) { Remove-Item -LiteralPath $outFile -Force }
}
