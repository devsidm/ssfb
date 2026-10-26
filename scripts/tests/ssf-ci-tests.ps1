[CmdletBinding()]
param(
    [switch]$SkipPhpRuntimeTests
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$failures = [Collections.Generic.List[string]]::new()
$engineName = if ($PSVersionTable.PSEdition -eq 'Core') { 'pwsh' } else { 'powershell.exe' }
$powerShellExecutable = (Get-Command $engineName -ErrorAction Stop).Source

# Keep this allowlist explicit. Live DEV flows, login helpers and deployment entry
# points must never become part of CI merely because a new test file is added.
$powerShellTests = @(
    'ssf-access-admin-tests.ps1',
    'ssf-admin-feedback-tests.ps1',
    'ssf-annual-meeting-metabox-tests.ps1',
    'ssf-antispam-tests.ps1',
    'ssf-archive-schema-reader-tests.ps1',
    'ssf-archive-target-folder-tests.ps1',
    'ssf-aspirant-email-tests.ps1',
    'ssf-dev-protection-tests.ps1',
    'ssf-email-template-tests.ps1',
    'ssf-external-news-authors-tests.ps1',
    'ssf-generic-folder-migration-tests.ps1',
    'ssf-membership-application-tests.ps1',
    'ssf-microsoft365-tenant-tests.ps1',
    'ssf-microsoft-login-tests.ps1',
    'ssf-news-layout-tests.ps1',
    'ssf-news-sources-tests.ps1',
    'ssf-news-status-tests.ps1',
    'ssf-organization-info-tests.ps1',
    'ssf-page-title-tests.ps1',
    'ssf-release-tests.ps1',
    'ssf-server-deploy-tests.ps1',
    'ssf-server-rollback-tests.ps1',
    'ssf-sharepoint-destination-tests.ps1',
    'ssf-sharepoint-entra-credentials-tests.ps1',
    'ssf-test-harness-tests.ps1',
    'ssf-workspace-title-tests.ps1'
)

$phpTests = @(
    'ssf-access-control-runtime-test.php',
    'ssf-aspirant-workflow-tests.php',
    'ssf-folder-migration-runtime-tests.php',
    'ssf-inspections-tests.php',
    'ssf-inspector-history-tests.php',
    'ssf-membership-inspection-tests.php',
    'ssf-membership-pdf-layout-test.php',
    'ssf-membership-template-library-tests.php',
    'ssf-membership-template-seed-tests.php',
    'ssf-news-tests.php',
    'ssf-payment-received-tests.php',
    'ssf-user-history-snapshot-test.php',
    'ssf-workspace-motion-tests.php',
    'ssf-workspace-tests.php'
)

$powerShellTestsRequiringPhp = @(
    'ssf-dev-protection-tests.ps1',
    'ssf-microsoft-login-tests.ps1',
    'ssf-server-deploy-tests.ps1'
)

function Invoke-TestProcess {
    param(
        [Parameter(Mandatory)]
        [string]$Name,
        [Parameter(Mandatory)]
        [string]$Executable,
        [Parameter(Mandatory)]
        [string[]]$Arguments
    )

    Write-Output "`n=== $Name ==="
    & $Executable @Arguments
    if ($LASTEXITCODE -ne 0) {
        $failures.Add("$Name exited with code $LASTEXITCODE") | Out-Null
    }
}

foreach ($test in $powerShellTests) {
    if ($SkipPhpRuntimeTests -and $powerShellTestsRequiringPhp -contains $test) {
        Write-Output "`n=== $test (skipped: PHP runtime unavailable) ==="
        continue
    }
    $path = Join-Path $PSScriptRoot $test
    if (-not (Test-Path -LiteralPath $path)) {
        $failures.Add("Missing PowerShell test: $test") | Out-Null
        continue
    }
    Invoke-TestProcess -Name $test -Executable $powerShellExecutable -Arguments @('-NoLogo', '-NoProfile', '-NonInteractive', '-File', $path)
}

if (-not $SkipPhpRuntimeTests) {
    $php = Get-Command php -ErrorAction SilentlyContinue
    if (-not $php) {
        $failures.Add('PHP is required for the offline runtime tests.') | Out-Null
    } else {
        foreach ($test in $phpTests) {
            $path = Join-Path $PSScriptRoot $test
            if (-not (Test-Path -LiteralPath $path)) {
                $failures.Add("Missing PHP test: $test") | Out-Null
                continue
            }
            Invoke-TestProcess -Name $test -Executable $php.Source -Arguments @($path)
        }
    }
}

if ($failures.Count) {
    $failures | ForEach-Object { Write-Error $_ }
    exit 1
}

Write-Output "`nPASS: SSF offline CI suite completed without live environment access."
