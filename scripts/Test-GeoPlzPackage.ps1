Clear-Host
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$Package = Split-Path -Parent $PSScriptRoot
Write-Host '=== GEO_PLZ_Details - Package Self Test ==='
Write-Host ''

$ComposerCmd = Get-Command composer -ErrorAction SilentlyContinue
if ($null -ne $ComposerCmd) {
    Write-Host '=== Composer manifest ==='
    Push-Location $Package
    try {
        & composer validate --strict --no-check-publish
        if ($LASTEXITCODE -ne 0) { throw 'Composer manifest validation failed.' }
    }
    finally { Pop-Location }
}
else {
    Write-Warning 'Composer not found; local Composer validation skipped.'
}

$PhpCmd = Get-Command php -ErrorAction SilentlyContinue
if ($null -eq $PhpCmd) {
    throw 'PHP CLI missing; PHP package cannot be tested.'
}

Write-Host ''
Write-Host '=== PHP syntax ==='
$PhpFiles = @(
    Get-ChildItem -LiteralPath (Join-Path $Package 'src') -Recurse -File -Filter '*.php'
    Get-ChildItem -LiteralPath (Join-Path $Package 'bin') -Recurse -File -Filter '*.php'
    Get-ChildItem -LiteralPath (Join-Path $Package 'tests') -Recurse -File -Filter '*.php'
)
foreach ($File in $PhpFiles) {
    & php -l $File.FullName
    if ($LASTEXITCODE -ne 0) { throw "PHP syntax error: $($File.FullName)" }
}

Write-Host ''
Write-Host '=== Installer detection ==='
& php (Join-Path $Package 'tests\test_installer_detection.php')
if ($LASTEXITCODE -ne 0) { throw 'PHP installer detection tests failed.' }

Write-Host ''
Write-Host '=== Python synthetic dataset / optional PHP integration ==='
$Runner = $null
foreach ($Candidate in @(
    [pscustomobject]@{ Command = 'py'; Prefix = @('-3') },
    [pscustomobject]@{ Command = 'python'; Prefix = @() },
    [pscustomobject]@{ Command = 'python3'; Prefix = @() }
)) {
    if (-not (Get-Command $Candidate.Command -ErrorAction SilentlyContinue)) { continue }
    try {
        $PythonCommand = [string]$Candidate.Command
        $PrefixArgs = [string[]]@($Candidate.Prefix)
        $Version = (& $PythonCommand @PrefixArgs --version 2>&1 | Out-String).Trim()
        if ($LASTEXITCODE -eq 0 -and $Version -match '^Python 3\.') {
            $Runner = $Candidate
            break
        }
    }
    catch { continue }
}
if ($null -eq $Runner) {
    Write-Warning 'Python 3 not found; synthetic import/SQLite builder tests not executed.'
}
else {
    $PythonCommand = [string]$Runner.Command
    $PrefixArgs = [string[]]@($Runner.Prefix)
    & $PythonCommand @PrefixArgs (Join-Path $Package 'tests\test_build.py')
    if ($LASTEXITCODE -ne 0) { throw 'Synthetic database/import tests failed.' }
}

Write-Host ''
Write-Host '=== Legacy identity and embedded data ==='
$Files = @(
    Get-ChildItem -LiteralPath $Package -Recurse -File -Force |
        Where-Object {
            $_.FullName -notmatch '[\\/]vendor[\\/]' -and
            $_.FullName -notmatch '[\\/]\.git[\\/]'
        }
)
foreach ($File in $Files) {
    if ($File.Extension -in @('.php','.md','.json','.py','.ps1','.yml','.sql','.txt')) {
        $Content = Get-Content -LiteralPath $File.FullName -Raw
        if ($Content -match '(?i)shk[-_ ]?momm') {
            throw "Forbidden legacy identity in: $($File.FullName)"
        }
    }
}
foreach ($Relative in @('data\geo-plz-details.sqlite', 'data\geo-plz-details.sql.gz')) {
    if (Test-Path -LiteralPath (Join-Path $Package $Relative)) {
        Write-Warning "Private generated data present (must remain untracked): $Relative"
    }
}

Write-Host ''
Write-Host '[PASS] All executed package checks passed.'
