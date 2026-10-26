[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [ValidatePattern('^[0-9a-fA-F]{40,64}$')]
    [string]$BaseSha,

    [Parameter(Mandatory)]
    [ValidatePattern('^[0-9a-fA-F]{40,64}$')]
    [string]$HeadSha
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$testRoot = Join-Path $repo 'scripts\tests'
$selectedTests = [Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)

function Invoke-Checked {
    param(
        [Parameter(Mandatory)] [string]$Label,
        [Parameter(Mandatory)] [string]$Executable,
        [Parameter(Mandatory)] [string[]]$Arguments
    )

    Write-Output "::group::$Label"
    & $Executable @Arguments
    $status = $LASTEXITCODE
    Write-Output '::endgroup::'
    if ($status -ne 0) { throw "$Label failed with exit code $status." }
}

function Add-Test([string]$Name) {
    $selectedTests.Add($Name) | Out-Null
}

foreach ($revision in @($BaseSha, $HeadSha)) {
    & git -C $repo cat-file -e "$revision`^{commit}"
    if ($LASTEXITCODE -ne 0) { throw "Git revision is unavailable: $revision" }
}

Invoke-Checked -Label 'Whitespace errors' -Executable 'git' -Arguments @('-C', $repo, 'diff', '--check', $BaseSha, $HeadSha)

$changedFiles = @(& git -C $repo diff --name-only --diff-filter=ACMR $BaseSha $HeadSha)
if ($LASTEXITCODE -ne 0) { throw 'Could not determine changed files.' }
$changedFiles = @($changedFiles | Where-Object { $_ })

Write-Output 'Changed files:'
$changedFiles | ForEach-Object { Write-Output "  $_" }

foreach ($relativePath in $changedFiles) {
    $normalized = $relativePath -replace '\\', '/'
    $path = Join-Path $repo ($normalized -replace '/', [IO.Path]::DirectorySeparatorChar)
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { continue }

    switch -Regex ($normalized) {
        '\.php$' {
            Invoke-Checked -Label "PHP lint: $normalized" -Executable 'php' -Arguments @('-l', $path)
            break
        }
        '\.ps1$' {
            $tokens = $null
            $errors = $null
            [Management.Automation.Language.Parser]::ParseFile($path, [ref]$tokens, [ref]$errors) | Out-Null
            if ($errors.Count) { throw "PowerShell parse failed: $normalized`n$($errors -join [Environment]::NewLine)" }
            break
        }
        '\.sh$' {
            Invoke-Checked -Label "Bash syntax: $normalized" -Executable 'bash' -Arguments @('-n', $path)
            break
        }
        '\.(js|mjs)$' {
            Invoke-Checked -Label "JavaScript syntax: $normalized" -Executable 'node' -Arguments @('--check', $path)
            break
        }
        '\.json$' {
            Get-Content -Raw -Encoding UTF8 -LiteralPath $path | ConvertFrom-Json | Out-Null
            break
        }
    }

    if ($normalized -match '^(AGENTS\.md|config/deploy-components\.json|docs/(SERVER-DEPLOYMENT|github-development-workflow)\.md|scripts/deploy/|\.github/workflows/deploy-(dev|prod)\.yml)') {
        Add-Test 'ssf-server-deploy-tests.ps1'
        Add-Test 'ssf-server-rollback-tests.ps1'
    }
    if ($normalized -match '^(scripts/ssf-release-|wp-content/mu-plugins/ssf-release-(controls\.php|manifest\.json))') {
        Add-Test 'ssf-release-tests.ps1'
    }
    if ($normalized -match '^wp-content/plugins/microsoft-id-login/') {
        Add-Test 'ssf-microsoft-login-tests.ps1'
    }
    if ($normalized -match '^wp-content/plugins/ssf-arbetsyta/') {
        Add-Test 'ssf-workspace-title-tests.ps1'
        Add-Test 'ssf-workspace-tests.php'
    }
    if ($normalized -match '^wp-content/plugins/ssf-member-portal/') {
        Add-Test 'ssf-annual-meeting-metabox-tests.ps1'
        Add-Test 'ssf-workspace-motion-tests.php'
    }
    if ($normalized -match '^wp-content/plugins/ssf-medlemsprocess/') {
        Add-Test 'ssf-membership-application-tests.ps1'
        Add-Test 'ssf-aspirant-workflow-tests.php'
        Add-Test 'ssf-membership-inspection-tests.php'
    }
    if ($normalized -match '^wp-content/plugins/ssf-medlemsfartyg/') {
        Add-Test 'ssf-email-template-tests.ps1'
    }
    if ($normalized -match '^wp-content/(mu-plugins/ssf-(email|organization)|plugins/ssf-office365-mailer/)') {
        Add-Test 'ssf-email-template-tests.ps1'
    }

    if ($normalized -match '^scripts/tests/(.+\.(ps1|php|sh))$' -and $normalized -ne 'scripts/tests/ssf-news-dev-flow.ps1') {
        Add-Test $Matches[1]
    }
}

if ($selectedTests.Count -eq 0) {
    Write-Output 'No targeted regression test matched; syntax and changed-file checks are sufficient.'
} else {
    Write-Output 'Targeted tests:'
    foreach ($test in @($selectedTests | Sort-Object)) {
        Write-Output "  $test"
        $path = Join-Path $testRoot $test
        if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { throw "Targeted test is missing: $test" }
        switch ([IO.Path]::GetExtension($test).ToLowerInvariant()) {
            '.ps1' { Invoke-Checked -Label $test -Executable 'pwsh' -Arguments @('-NoLogo', '-NoProfile', '-NonInteractive', '-File', $path) }
            '.php' { Invoke-Checked -Label $test -Executable 'php' -Arguments @($path) }
            '.sh' { Invoke-Checked -Label $test -Executable 'bash' -Arguments @($path) }
            default { throw "Unsupported targeted test type: $test" }
        }
    }
}

Write-Output 'PASS: changed-file validation and targeted tests completed.'
