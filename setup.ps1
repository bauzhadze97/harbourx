<#
.SYNOPSIS
  One-command setup for HarbourX on Windows.

.DESCRIPTION
  Installs Git if it is missing, fetches the project, then starts it — going
  through WSL when Windows refuses to run PHP directly, which is what happens on
  a machine with Smart App Control or a WDAC policy.

  Safe to re-run: every step is checked before it is taken.

.EXAMPLE
  .\setup.ps1
  .\setup.ps1 -Path C:\dev\harbourx -Port 3000
#>

[CmdletBinding()]
param(
    [string]$Path = "$env:USERPROFILE\harbourx",
    [int]$Port = 8000,
    # Skip the PHP hunt and go straight to WSL.
    [switch]$UseWsl
)

$ErrorActionPreference = 'Stop'

$RepoUrl = 'https://github.com/bauzhadze97/harbourx.git'
$Branch  = 'claude/optimize-modernize-animations-hmb3qf'

function Write-Step($text) { Write-Host "  $text" -ForegroundColor Cyan }
function Write-Warn($text) { Write-Host "  $text" -ForegroundColor Yellow }
function Write-Ok($text)   { Write-Host "  $text" -ForegroundColor Green }

Write-Host ''
Write-Host '  HarbourX — setup' -ForegroundColor White
Write-Host '  ----------------' -ForegroundColor DarkGray

# --- 1. Git -----------------------------------------------------------------
$git = Get-Command git -ErrorAction SilentlyContinue
if (-not $git) {
    $winget = Get-Command winget -ErrorAction SilentlyContinue
    if (-not $winget) {
        Write-Warn 'Git is missing and winget is not available to install it.'
        Write-Host '  Install Git from https://git-scm.com/download/win and re-run.' -ForegroundColor DarkGray
        exit 1
    }
    Write-Step 'Installing Git…'
    # Git ships a signed installer into Program Files, so Application Control
    # policies let it through where the WinGet-unpacked PHP is refused.
    & winget install --id Git.Git --source winget --accept-package-agreements --accept-source-agreements --silent
    $env:Path = [Environment]::GetEnvironmentVariable('Path', 'Machine') + ';' +
                [Environment]::GetEnvironmentVariable('Path', 'User')
    $git = Get-Command git -ErrorAction SilentlyContinue
    if (-not $git) {
        Write-Warn 'Git installed, but this terminal has not picked it up yet.'
        Write-Host '  Close and reopen the terminal, then run this script again.' -ForegroundColor DarkGray
        exit 1
    }
}
Write-Ok "git $((& git --version) -replace 'git version ','')"

# --- 2. The project ---------------------------------------------------------
if (Test-Path -LiteralPath (Join-Path $Path '.git')) {
    Write-Step "Updating the clone in $Path"
    & git -C $Path fetch --quiet origin $Branch
    & git -C $Path checkout --quiet $Branch
    & git -C $Path pull --quiet origin $Branch
} else {
    Write-Step "Cloning into $Path"
    & git clone --quiet --branch $Branch $RepoUrl $Path
}
Set-Location -LiteralPath $Path

# --- 3. A data file to sign in against --------------------------------------
if (-not (Test-Path -LiteralPath 'data\users.json')) {
    New-Item -ItemType Directory -Force -Path 'data' | Out-Null
    Copy-Item -LiteralPath 'tests\fixtures\users.json' -Destination 'data\users.json'
    Write-Step 'Seeded data\users.json with the test account:'
    Write-Host '        test.client@example.invalid  /  CiSmokeTest!2026' -ForegroundColor Green
}

# --- 4. Can Windows run PHP at all? -----------------------------------------
function Find-Php {
    $onPath = Get-Command php -ErrorAction SilentlyContinue
    if ($onPath) { return $onPath.Source }
    foreach ($candidate in @(
        "$env:ProgramFiles\php\php.exe",
        "C:\php\php.exe",
        "C:\xampp\php\php.exe",
        "C:\laragon\bin\php\php.exe",
        "$env:LOCALAPPDATA\Microsoft\WinGet\Links\php.exe"
    )) {
        if (Test-Path -LiteralPath $candidate) { return $candidate }
    }
    foreach ($root in @('C:\xampp\php', 'C:\laragon\bin\php')) {
        if (Test-Path -LiteralPath $root) {
            $found = Get-ChildItem -Path $root -Recurse -Filter php.exe -ErrorAction SilentlyContinue |
                     Sort-Object FullName -Descending | Select-Object -First 1
            if ($found) { return $found.FullName }
        }
    }
    return $null
}

$phpWorks = $false
$php = $null
if (-not $UseWsl) {
    $php = Find-Php
    if ($php) {
        # Finding it is not the same as being allowed to run it.
        try {
            $probe = (& $php -r 'echo 1;' 2>$null)
            if ("$probe".Trim() -eq '1') { $phpWorks = $true }
        } catch {
            $phpWorks = $false
        }
    }
}

if ($phpWorks) {
    Write-Ok "php $((& $php -r 'echo PHP_VERSION;'))  ($php)"
    Write-Host ''
    Write-Step "Serving on http://localhost:$Port/"
    Write-Host '  Press Ctrl+C to stop.' -ForegroundColor DarkGray
    Write-Host ''
    Start-Process "http://localhost:$Port/" | Out-Null
    & $php -S "localhost:$Port" -t .
    exit $LASTEXITCODE
}

# --- 5. PHP is blocked or missing: go through WSL ---------------------------
if ($php) {
    Write-Warn 'PHP is installed but Windows will not run it (Application Control policy).'
} else {
    Write-Warn 'No usable PHP found on this machine.'
}
Write-Host '  Falling back to WSL, where that policy does not apply.' -ForegroundColor DarkGray
Write-Host ''

$wsl = Get-Command wsl -ErrorAction SilentlyContinue
if (-not $wsl) {
    Write-Warn 'WSL is not installed either.'
    Write-Host ''
    Write-Host '  Install it once, reboot, then run this script again:' -ForegroundColor White
    Write-Host '    wsl --install' -ForegroundColor Green
    Write-Host ''
    Write-Host '  Or, to look at the interface without PHP at all:' -ForegroundColor White
    Write-Host "    .\run-local.ps1 -Static" -ForegroundColor Green
    Write-Host ''
    exit 1
}

# Has a distribution actually been set up? `wsl -l -q` is empty until then.
$distros = (& wsl -l -q) 2>$null | Where-Object { $_ -and $_.Trim() }
if (-not $distros) {
    Write-Warn 'WSL is present but has no Linux distribution installed yet.'
    Write-Host '    wsl --install -d Ubuntu     # then reboot and re-run this script' -ForegroundColor Green
    Write-Host ''
    exit 1
}
Write-Ok "WSL distribution: $($distros[0])"

# Hand the whole job to setup.sh inside WSL, against this same folder.
$wslPath = (& wsl wslpath -a ($Path -replace '\\', '/')) 2>$null
if (-not $wslPath) {
    Write-Warn 'Could not map this folder into WSL.'
    exit 1
}
$wslPath = "$wslPath".Trim()

Write-Host ''
Write-Step "Running setup.sh inside WSL against $wslPath"
Write-Host '  You may be asked for your WSL password, to install php-cli.' -ForegroundColor DarkGray
Write-Host "  When it is up, open http://localhost:$Port/ in Windows." -ForegroundColor DarkGray
Write-Host ''

& wsl -e bash -lc "cd '$wslPath' && chmod +x setup.sh && ./setup.sh $Port"
exit $LASTEXITCODE
