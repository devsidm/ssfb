[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$file = Join-Path $repo 'wp-content\mu-plugins\ssf-dev-protection.php'
$loginProtectionFile = Join-Path $repo 'wp-content\mu-plugins\ssf-dev-login-protection.php'
$php = Get-Content -Raw -LiteralPath $file
$loginProtection = Get-Content -Raw -LiteralPath $loginProtectionFile
$results = [Collections.Generic.List[object]]::new()

function Assert-Contains {
    param([string] $Name, [string] $Needle)
    if (-not $php.Contains($Needle)) {
        throw "$Name misslyckades. Saknar: $Needle"
    }
    $results.Add([pscustomobject]@{ Test = $Name; Result = 'PASS' })
}

Assert-Contains 'Statusroute-normalisering finns' 'ssf_dev_protection_normalize_public_status_path'
Assert-Contains 'Extern skribentrutt är uttryckligen anonym' 'skriv-nyhet/[A-Za-z0-9_-]{1,128}'
Assert-Contains 'Subdirectory-prefix hanteras' 'array_slice($parts, 1)'
Assert-Contains 'Ansokan-status kan normaliseras' "'ansokan-status'"
Assert-Contains 'Motion-status kan normaliseras' "'motion-status'"
Assert-Contains 'Tokenvalidering lämnas till statuskontroller' "if (in_array(`$request_path, array('ansokan-status', 'motion-status'), true))"

if (-not $loginProtection.Contains('ssf_dev_protection_is_public_status_route()')) {
    throw 'DEV login protection saknar public status route-undantag.'
}
$results.Add([pscustomobject]@{ Test = 'Extra DEV login protection respekterar statusrutter'; Result = 'PASS' })

foreach ($needle in @("home_url('/ssf-auth/microsoft/callback/')", "get_query_var('ssf_m365_login_callback')", 'if (ssf_dev_protection_is_microsoft_callback_route())')) {
    if (-not $php.Contains($needle)) {
        throw "DEV-skyddet saknar snÃ¤vt callback-undantag: $needle"
    }
}
if (-not $loginProtection.Contains('ssf_dev_protection_is_microsoft_callback_route()')) {
    throw 'Extra DEV login protection saknar Microsoft-callback-undantag.'
}
$results.Add([pscustomobject]@{ Test = 'BÃ¥da DEV-skydden slÃ¤pper igenom endast registrerad Microsoft-callback'; Result = 'PASS' })

$phpCommand = Get-Command php -ErrorAction SilentlyContinue
$phpPath = if ($phpCommand) { $phpCommand.Source } else { Join-Path ([IO.Path]::GetTempPath()) 'ssf-codex-php-8.5.10\php.exe' }
if (-not (Test-Path -LiteralPath $phpPath)) {
    throw 'PHP saknas; DEV callback-regressionstestet kan inte kÃ¶ras.'
}
$fixture = [IO.Path]::GetTempFileName()
try {
    $runtimeTest = @'
<?php
define('ABSPATH', __DIR__);
$hooks = array();
$queryVar = '1';
function add_action($hook, $callback, $priority = 10) { global $hooks; $hooks[$hook][$priority][] = $callback; }
function add_filter() {}
function wp_parse_url($url, $part) { return parse_url($url, $part); }
function home_url($path) { return 'https://example.test/dev' . $path; }
function get_query_var($name) { global $queryVar; return $queryVar; }
function wp_get_environment_type() { return 'development'; }
function is_user_logged_in() { return false; }
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function wp_doing_cron() { return false; }
function auth_redirect() { throw new RuntimeException('login required'); }
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
require $argv[1];
require $argv[2];
$_SERVER['REQUEST_URI'] = '/dev/ssf-auth/microsoft/callback/?code=example&state=example';
check(ssf_dev_protection_is_microsoft_callback_route(), 'valid callback must be public');
check(ssf_dev_protection_allows_anonymous_request(), 'first guard must allow callback');
foreach ($hooks['template_redirect'][10] as $callback) { $callback(); }
$queryVar = '';
check(!ssf_dev_protection_is_microsoft_callback_route(), 'callback without rewrite query var must be blocked');
$queryVar = '1';
$_SERVER['REQUEST_URI'] = '/dev/other-page/';
check(!ssf_dev_protection_allows_anonymous_request(), 'unrelated page must be protected');
try {
    foreach ($hooks['template_redirect'][10] as $callback) { $callback(); }
    throw new RuntimeException('second guard did not protect unrelated page');
} catch (RuntimeException $error) {
    check($error->getMessage() === 'login required', 'unexpected second guard outcome');
}
echo 'PASS: DEV callback guards allow only the registered Microsoft callback.';
'@
    [IO.File]::WriteAllText($fixture, $runtimeTest, [Text.UTF8Encoding]::new($false))
    $runtimeOutput = & $phpPath $fixture $file $loginProtectionFile 2>&1
    if ($LASTEXITCODE -ne 0) { throw "DEV callback runtime test failed: $runtimeOutput" }
    Write-Host $runtimeOutput
} finally {
    Remove-Item -LiteralPath $fixture -Force
}

$results | Format-Table -AutoSize
