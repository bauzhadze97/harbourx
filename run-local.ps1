<#
.SYNOPSIS
  Runs the HarbourX portal on this computer.

.DESCRIPTION
  Starts PHP's built-in web server in this folder and opens the sign-in page.
  Nothing is installed and nothing leaves the machine — the app reads and writes
  data\users.json in place, exactly as it does on the real host.

  If data\users.json is missing (a fresh clone never has it — it holds real
  client details and is deliberately untracked) the script seeds the synthetic
  demo account used by the test suite and prints its credentials.

.EXAMPLE
  .\run-local.ps1
  .\run-local.ps1 -Port 3000
#>

[CmdletBinding()]
param(
    [int]$Port = 8000,
    [switch]$NoBrowser
)

$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot

function Write-Step($text) { Write-Host "  $text" -ForegroundColor Cyan }
function Write-Warn($text) { Write-Host "  $text" -ForegroundColor Yellow }

Write-Host ''
Write-Host '  HarbourX — local server' -ForegroundColor White
Write-Host '  ------------------------' -ForegroundColor DarkGray

# --- 1. Find PHP ------------------------------------------------------------
$php = $null
$onPath = Get-Command php -ErrorAction SilentlyContinue
if ($onPath) {
    $php = $onPath.Source
} else {
    # Common Windows install locations, newest first.
    $candidates = @(
        "$env:ProgramFiles\php\php.exe",
        "${env:ProgramFiles(x86)}\php\php.exe",
        "$env:LOCALAPPDATA\Microsoft\WinGet\Links\php.exe",
        "$env:USERPROFILE\scoop\shims\php.exe",
        "C:\php\php.exe",
        "C:\xampp\php\php.exe",
        "C:\laragon\bin\php\php.exe"
    )
    foreach ($candidate in $candidates) {
        if (Test-Path -LiteralPath $candidate) { $php = $candidate; break }
    }
    # XAMPP and Laragon keep versioned folders; take the newest.
    if (-not $php) {
        foreach ($root in @('C:\xampp\php', 'C:\laragon\bin\php')) {
            if (Test-Path -LiteralPath $root) {
                $found = Get-ChildItem -Path $root -Recurse -Filter php.exe -ErrorAction SilentlyContinue |
                         Sort-Object FullName -Descending | Select-Object -First 1
                if ($found) { $php = $found.FullName; break }
            }
        }
    }
}

if (-not $php) {
    Write-Host ''
    Write-Warn 'PHP is not installed (or not on your PATH).'
    Write-Host ''
    Write-Host '  Install it with one of these, then run this script again:' -ForegroundColor White
    Write-Host ''
    Write-Host '    winget install PHP.PHP.8.3' -ForegroundColor Green
    Write-Host '    scoop install php' -ForegroundColor Green
    Write-Host ''
    Write-Host '  Close and reopen your terminal afterwards so PATH refreshes.' -ForegroundColor DarkGray
    Write-Host '  Manual download: https://windows.php.net/download/' -ForegroundColor DarkGray
    Write-Host ''
    exit 1
}

$version = (& $php -r 'echo PHP_VERSION;' 2>$null)
Write-Step "PHP $version  ($php)"

# --- 2. Make sure there is data to sign in with -----------------------------
if (-not (Test-Path -LiteralPath 'data\users.json')) {
    New-Item -ItemType Directory -Force -Path 'data' | Out-Null
    Copy-Item -LiteralPath 'tests\fixtures\users.json' -Destination 'data\users.json'
    Write-Step 'Seeded data\users.json with the demo account:'
    Write-Host '        demo.client@example.invalid  /  CiSmokeTest!2026' -ForegroundColor Green
    Write-Warn '      To use your real data instead, copy your own data\users.json over this one.'
} else {
    Write-Step 'Using the existing data\users.json'
}

# --- 3. Serve ---------------------------------------------------------------
$url = "http://localhost:$Port/login.html"
Write-Step "Serving on http://localhost:$Port"
Write-Host "        client   $url" -ForegroundColor DarkGray
Write-Host "        admin    http://localhost:$Port/admin.php" -ForegroundColor DarkGray
Write-Host ''
Write-Host '  Press Ctrl+C to stop.' -ForegroundColor DarkGray
Write-Host ''

if (-not $NoBrowser) {
    Start-Process $url | Out-Null
}

& $php -S "localhost:$Port" -t .
