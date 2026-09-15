[CmdletBinding()]
param(
    [ValidateSet('dev')]
    [string]$Environment = 'dev',
    [string]$CookiePath = '',
    [switch]$Json,
    [switch]$DebugOutput,
    [switch]$Cleanup
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

. (Join-Path $PSScriptRoot 'lib\ssf-test-harness.ps1')

Import-SsfSecrets
$config = Get-SsfEnvironmentConfig -Environment $Environment
$baseUrl = [string]$config.wordpress.url
Test-SsfDevUrl -Url $baseUrl

$user = [Environment]::GetEnvironmentVariable('SSF_WP_USER', 'Process')
$password = [Environment]::GetEnvironmentVariable('SSF_WP_PASSWORD', 'Process')
$createdCookie = $false
if (-not $CookiePath) {
    $CookiePath = New-SsfCookiePath
    $createdCookie = $true
}

$result = [ordered]@{
    environment = $Environment.ToUpperInvariant()
    url = $baseUrl
    account = if ($user) { $user } else { '' }
    credentials = if ($user -and $password) { 'FOUND' } else { 'MISSING' }
    curl_login = 'NOT_TESTED'
    wp_admin = 'NOT_TESTED'
    cookie = if ($createdCookie) { 'CREATED' } else { 'REUSED' }
    cookie_path = if ($DebugOutput) { $CookiePath } else { '' }
    result = 'STOP'
}

try {
    if (-not $user -or -not $password) {
        if ($Json) {
            $result | ConvertTo-Json -Depth 4
        } else {
            Write-Host 'SSF WORDPRESS LOGIN'
            Write-Host ('Environment        {0}' -f $result.environment)
            Write-Host ('URL                {0}' -f $result.url)
            $accountLabel = if ($result.account) { $result.account } else { 'MISSING' }
            Write-Host ('Account            {0}' -f $accountLabel)
            Write-Host 'Credentials        MISSING'
            Write-Host 'Expected vars      SSF_WP_USER, SSF_WP_PASSWORD'
            Write-Host 'Result             STOP'
        }
        exit 2
    }

    $loginUrl = [string]$config.wordpress.login_url
    $adminUrl = [string]$config.wordpress.wp_admin_url
    $loginHtml = Join-Path ([IO.Path]::GetTempPath()) ('ssf-login-{0}.html' -f ([guid]::NewGuid().ToString('N')))
    $adminHtml = Join-Path ([IO.Path]::GetTempPath()) ('ssf-admin-{0}.html' -f ([guid]::NewGuid().ToString('N')))

    try {
        Invoke-SsfCurl -Arguments @('-sS','-L','-c',$CookiePath,'-b',$CookiePath,$loginUrl,'-o',$loginHtml) | Out-Null
        $effective = Invoke-SsfCurl -Arguments @(
            '-sS','-L','-c',$CookiePath,'-b',$CookiePath,
            '--data-urlencode', "log=$user",
            '--data-urlencode', "pwd=$password",
            '--data-urlencode', 'wp-submit=Logga in',
            '--data-urlencode', "redirect_to=$adminUrl",
            '--data-urlencode', 'testcookie=1',
            '--data-urlencode', 'rememberme=forever',
            $loginUrl,
            '-o', $adminHtml,
            '-w', '%{url_effective}'
        )
        $result.curl_login = 'PASS'
        $adminContent = Get-Content -Raw -Encoding UTF8 -LiteralPath $adminHtml
        $authenticated = ($effective -notmatch 'wp-login\.php') -and ($adminContent -match 'wp-admin|Dashboard|Adminpanel|wp-admin-bar')
        $result.wp_admin = ConvertTo-SsfStatus $authenticated
        $result.result = if ($authenticated) { 'PASS' } else { 'STOP' }
        if (-not $authenticated) {
            exit 3
        }
    } finally {
        foreach ($path in @($loginHtml, $adminHtml)) {
            if ((-not $DebugOutput) -and $path -and (Test-Path -LiteralPath $path)) {
                Remove-Item -LiteralPath $path -Force
            }
        }
    }

    if ($Json) {
        $result | ConvertTo-Json -Depth 4
    } else {
        Write-Host 'SSF WORDPRESS LOGIN'
        Write-Host ('Environment        {0}' -f $result.environment)
        Write-Host ('URL                {0}' -f $result.url)
        Write-Host ('Account            {0}' -f $result.account)
        Write-Host ('Credentials        {0}' -f $result.credentials)
        Write-Host ('curl login         {0}' -f $result.curl_login)
        Write-Host ('wp-admin           {0}' -f $result.wp_admin)
        Write-Host ('Cookie             {0}' -f $result.cookie)
        if ($DebugOutput) { Write-Host ('Cookie path        {0}' -f $CookiePath) }
        Write-Host ('Result             {0}' -f $result.result)
    }
} finally {
    if ($Cleanup -and $createdCookie) {
        Remove-SsfCookie -Path $CookiePath
    }
}
