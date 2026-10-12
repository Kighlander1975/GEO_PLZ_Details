param(
    [Parameter(Mandatory = $true)][string] $ReleasePath,
    [string] $PythonExe = '',
    [string] $OutputDir = '',
    [string] $DisplayOverrides = ''
)

Clear-Host
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$Package = Split-Path -Parent $PSScriptRoot
$Builder = Join-Path $PSScriptRoot 'build_database.py'
if (-not $OutputDir) { $OutputDir = Join-Path $Package 'data' }

if (-not (Test-Path -LiteralPath $ReleasePath -PathType Container)) {
    throw "GEO release directory not found: $ReleasePath"
}
if (-not (Test-Path -LiteralPath $Builder -PathType Leaf)) {
    throw "GEO builder script missing: $Builder"
}

$Runner = $null
$Candidates = @()
if ($PythonExe) {
    $Candidates += [pscustomobject]@{ Command = $PythonExe; Prefix = @() }
}
else {
    $Candidates += [pscustomobject]@{ Command = 'py'; Prefix = @('-3') }
    $Candidates += [pscustomobject]@{ Command = 'python'; Prefix = @() }
    $Candidates += [pscustomobject]@{ Command = 'python3'; Prefix = @() }
}

foreach ($Candidate in $Candidates) {
    if (-not (Get-Command $Candidate.Command -ErrorAction SilentlyContinue)) {
        continue
    }
    try {
        $PythonCommand = [string]$Candidate.Command
        $PrefixArgs = [string[]]@($Candidate.Prefix)
        $Version = (& $PythonCommand @PrefixArgs --version 2>&1 | Out-String).Trim()
        if ($LASTEXITCODE -eq 0 -and $Version -match '^Python 3\.') {
            $Runner = $Candidate
            break
        }
    }
    catch {
        continue
    }
}
if ($null -eq $Runner) {
    throw 'Python 3 not found. Provide -PythonExe with an actual Python 3 executable path.'
}

Write-Host '=== GEO_PLZ_Details - Independent Database Build ==='
Write-Host "Release: $ReleasePath"
Write-Host "Output:  $OutputDir"
Write-Host "Python:  $($Runner.Command)"
Write-Host 'Mode: VERIFIED INPUT / NO-OVERWRITE / SAFE RESUME'
Write-Host ''

$Arguments = @($Builder, '--release', $ReleasePath, '--output', $OutputDir)
if ($DisplayOverrides) {
    $Arguments += @('--display-overrides', $DisplayOverrides)
}
$PythonCommand = [string]$Runner.Command
$PrefixArgs = [string[]]@($Runner.Prefix)
& $PythonCommand @PrefixArgs @Arguments
if ($LASTEXITCODE -ne 0) {
    throw "GEO database build failed with code $LASTEXITCODE"
}

Write-Host ''
Write-Host '=== PHP Runtime Check ==='
$PhpCommand = Get-Command php -ErrorAction SilentlyContinue
if ($null -eq $PhpCommand) {
    Write-Warning 'PHP CLI not in PATH. Database and SQL dump created; PHP API not tested.'
}
else {
    $Modules = @(& php -m)
    if ($Modules -notcontains 'pdo_sqlite') {
        Write-Warning 'PHP extension pdo_sqlite is not enabled; enable it before using the PHP catalog.'
    }
    else {
        $DbArgument = '--db=' + (Join-Path $OutputDir 'geo-plz-details.sqlite')
        & php (Join-Path $Package 'bin\geo-plz-details.php') $DbArgument check
        if ($LASTEXITCODE -ne 0) {
            throw "PHP GEO integrity verification failed: $LASTEXITCODE"
        }
    }
}
Write-Host ''
Write-Host '[PASS] Database build finished. No application or original release changed.'
