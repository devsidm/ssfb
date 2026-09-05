[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [ValidateSet('development', 'production')]
    [string]$Environment,
    [Parameter(Mandatory)]
    [string]$BaseUrl,
    [Parameter(Mandatory)]
    [string]$FtpHost,
    [Parameter(Mandatory)]
    [string]$FtpUser,
    [string]$RemoteRoot = '',
    [string[]]$SmokePaths = @('/', '/ansokan/', '/kontakta-oss/', '/arsmoten/'),
    [switch]$BackupConfirmed
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$manifestPath = Join-Path $repo 'wp-content\mu-plugins\ssf-release-manifest.json'
$ftpPassword = [string]$env:SSF_FTP_PASSWORD
$wpUser = [string]$env:SSF_WP_USER
$wpPassword = [string]$env:SSF_WP_PASSWORD

if (-not $ftpPassword) { throw 'SSF_FTP_PASSWORD saknas i den aktuella processen.' }
if (-not $wpUser -or -not $wpPassword) { throw 'SSF_WP_USER och SSF_WP_PASSWORD krävs för verifiering efter deployment.' }
if (-not (Test-Path -LiteralPath $manifestPath)) { throw 'Release-manifest saknas. Registrera först en build.' }

$manifest = Get-Content -Raw -LiteralPath $manifestPath | ConvertFrom-Json
if (([string]$manifest.build) -notmatch '^\d{8}\.\d+$') { throw 'Manifestet innehåller ett ogiltigt buildnummer.' }
if ($Environment -eq 'production') {
    if (-not $BackupConfirmed) { throw 'Production kräver -BackupConfirmed efter verifierad extern backup.' }
    if ([string]$manifest.status -ne 'prepared') { throw 'Production kräver att builden först har förberetts som release.' }
    if (-not [string]$manifest.version) { throw 'Production kräver ett beslutat versionsnummer.' }
}

$dirty = @(git -C $repo status --porcelain)
if ($dirty.Count -gt 0) { throw 'Git-trädet måste vara rent före deployment. Committera build-manifestet först.' }

$trackedFiles = @(git -C $repo ls-files -- 'wp-content')
if ($trackedFiles.Count -eq 0) { throw 'Inga versionsstyrda wp-content-filer hittades.' }

$BaseUrl = $BaseUrl.TrimEnd('/')
$RemoteRoot = $RemoteRoot.Trim('/')
$cookiePath = [IO.Path]::GetTempFileName()
$pagePath = [IO.Path]::GetTempFileName()
$expectedBuild = [string]$manifest.build
$authenticated = $false

function Get-AdminNonce {
    param([string]$Html, [string]$Action)
    return [regex]::Match($Html, ('(?s)name="action" value="' + [regex]::Escape($Action) + '".*?name="_wpnonce" value="([^"]+)"')).Groups[1].Value
}

function Register-Failure {
    param([string]$Reason)
    if (-not $authenticated) { return }
    & curl.exe -sS -L -b $cookiePath -o $pagePath "$BaseUrl/wp-admin/admin.php?page=ssf-release"
    $html = Get-Content -Raw -LiteralPath $pagePath
    $nonce = Get-AdminNonce -Html $html -Action 'ssf_fail_release_deployment'
    if ($nonce) {
        & curl.exe -sS -L -b $cookiePath -o $pagePath --data-urlencode 'action=ssf_fail_release_deployment' --data-urlencode "_wpnonce=$nonce" --data-urlencode "expected_build=$expectedBuild" --data-urlencode "reason=$Reason" "$BaseUrl/wp-admin/admin-post.php"
    }
}

try {
    foreach ($file in $trackedFiles) {
        $local = Join-Path $repo ($file -replace '/', [IO.Path]::DirectorySeparatorChar)
        if (-not (Test-Path -LiteralPath $local)) { throw "Versionsstyrd fil saknas lokalt: $file" }
        $remotePath = if ($RemoteRoot) { "$RemoteRoot/$file" } else { $file }
        $url = 'ftp://' + $FtpHost.TrimEnd('/') + '/' + $remotePath
        & curl.exe -sS --fail --ftp-pasv --ftp-create-dirs -u ($FtpUser + ':' + $ftpPassword) -T $local $url
        if ($LASTEXITCODE -ne 0) { throw "FTP-uppladdning misslyckades: $file" }
    }

    & curl.exe -sS -c $cookiePath -o $pagePath "$BaseUrl/wp-login.php"
    & curl.exe -sS -L -b $cookiePath -c $cookiePath -o $pagePath --data-urlencode "log=$wpUser" --data-urlencode "pwd=$wpPassword" --data-urlencode 'wp-submit=Logga in' --data-urlencode "redirect_to=$BaseUrl/wp-admin/admin.php?page=ssf-release" --data-urlencode 'testcookie=1' "$BaseUrl/wp-login.php"
    $loginHtml = Get-Content -Raw -LiteralPath $pagePath
    $authenticated = $loginHtml -match 'ssf_verify_release_deployment'
    if (-not $authenticated) { throw 'WordPress-inloggningen misslyckades; deploymenten kan inte verifieras.' }

    foreach ($path in $SmokePaths) {
        $url = $BaseUrl + '/' + $path.TrimStart('/')
        $status = & curl.exe -sS -L -b $cookiePath -o $pagePath -w '%{http_code}' $url
        $html = Get-Content -Raw -LiteralPath $pagePath
        if ($status -ne '200' -or $html -match 'Fatal error|Parse error|There has been a critical error') {
            throw "Smoke test misslyckades för $url"
        }
    }

    & curl.exe -sS -L -b $cookiePath -o $pagePath "$BaseUrl/wp-admin/admin.php?page=ssf-release"
    $releaseHtml = Get-Content -Raw -LiteralPath $pagePath
    $expectedLabel = if ($Environment -eq 'production') { 'Production' } else { 'Development' }
    if ($releaseHtml -notmatch [regex]::Escape($expectedLabel)) {
        throw 'Miljöverifieringen misslyckades. wp-config.php har inte ändrats.'
    }
    if ($releaseHtml -notmatch [regex]::Escape($expectedBuild)) {
        throw 'Buildverifieringen misslyckades.'
    }

    $nonce = Get-AdminNonce -Html $releaseHtml -Action 'ssf_verify_release_deployment'
    if (-not $nonce) { throw 'Verifieringsnonce saknas på Release-sidan.' }
    & curl.exe -sS -L -b $cookiePath -o $pagePath --data-urlencode 'action=ssf_verify_release_deployment' --data-urlencode "_wpnonce=$nonce" --data-urlencode "expected_build=$expectedBuild" "$BaseUrl/wp-admin/admin-post.php"
    $verifiedHtml = Get-Content -Raw -LiteralPath $pagePath
    if ($verifiedHtml -notmatch 'deploymenten är markerad som lyckad') {
        throw 'WordPress bekräftade inte en lyckad deployment.'
    }

    [pscustomobject]@{
        Environment = $Environment
        Version = if ([string]$manifest.version) { [string]$manifest.version } else { 'Ej förberedd' }
        Build = $expectedBuild
        Deployment = 'SUCCESS'
        FilesUploaded = $trackedFiles.Count
        SmokeTests = $SmokePaths.Count
    } | Format-List
} catch {
    Register-Failure -Reason $_.Exception.Message
    throw
} finally {
    foreach ($path in @($cookiePath, $pagePath)) {
        if (Test-Path -LiteralPath $path) { Remove-Item -LiteralPath $path -Force }
    }
}
