<#
.SYNOPSIS
  Runs the HarbourX portal on this computer.

.DESCRIPTION
  Starts PHP's built-in web server in this folder and opens the site.
  Nothing is installed and nothing leaves the machine — the app reads and writes
  data\users.json in place, exactly as it does on the real host.

  If data\users.json is missing (a fresh clone never has it — it holds real
  client details and is deliberately untracked) the script seeds the synthetic
  test account used by the test suite and prints its credentials.

.EXAMPLE
  .\run-local.ps1
  .\run-local.ps1 -Port 3000
#>

[CmdletBinding()]
param(
    [int]$Port = 8000,
    [switch]$NoBrowser,
    # Serve the folder with a static server instead of PHP. The interface
    # renders, but every PHP endpoint is dead — see tests\preview.html.
    [switch]$Static
)

$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot

function Write-Step($text) { Write-Host "  $text" -ForegroundColor Cyan }
function Write-Warn($text) { Write-Host "  $text" -ForegroundColor Yellow }

Write-Host ''
Write-Host '  HarbourX — local server' -ForegroundColor White
Write-Host '  ------------------------' -ForegroundColor DarkGray

# --- 0. Static mode ---------------------------------------------------------
if ($Static) {
    $url = "http://localhost:$Port/tests/preview.html"
    Write-Warn 'Static mode: the interface only. Sign-in, two-factor, tickets,'
    Write-Warn 'converting and withdrawing all need PHP and will not work.'
    Write-Host ''
    Write-Step "Open $url"
    Write-Host '  Note: a static server hands out .php files as plain text.' -ForegroundColor DarkGray
    Write-Host '  Keep this on localhost only.' -ForegroundColor DarkGray
    Write-Host ''

    if (-not $NoBrowser) { Start-Process $url | Out-Null }

    # Try each in turn. Windows ships a `python` stub that only opens the Store,
    # so a command existing is not proof it serves anything — check the version
    # first and move on if it does not answer.
    foreach ($candidate in @('py', 'python3', 'python')) {
        $found = Get-Command $candidate -ErrorAction SilentlyContinue
        if (-not $found) { continue }
        try {
            $probe = (& $found.Source -c 'print(1)' 2>$null)
        } catch {
            continue
        }
        if ("$probe".Trim() -ne '1') { continue }
        & $found.Source -m http.server $Port --bind 127.0.0.1
        exit $LASTEXITCODE
    }

    $npx = Get-Command npx -ErrorAction SilentlyContinue
    if ($npx) {
        & $npx.Source --yes serve --listen $Port .
        exit $LASTEXITCODE
    }

    Write-Warn 'No static server found. Install Python, or Node (for npx serve).'
    Write-Host '  Any static server works — VS Code''s Live Server extension will do.' -ForegroundColor DarkGray
    exit 1
}

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

# Finding php.exe is not the same as being allowed to run it: WinGet unpacks it
# into a user-writable folder, and Smart App Control / WDAC refuse to execute
# unsigned binaries from there. Try it once and report the real reason.
$version = $null
try {
    $version = (& $php -r 'echo PHP_VERSION;' 2>$null)
} catch {
    $blocked = $_.Exception.Message
}

if (-not $version) {
    Write-Host ''
    if ($blocked -match 'Application Control|blocked this file|not be run on this') {
        Write-Warn 'Windows blocked PHP from running (Application Control policy).'
        Write-Host ''
        Write-Host '  PHP is installed, but Windows will not execute it from the WinGet folder.' -ForegroundColor White
        Write-Host '  Pick whichever suits you:' -ForegroundColor White
        Write-Host ''
        Write-Host '   1. Run it under WSL (cleanest — real PHP, policy does not apply):' -ForegroundColor Green
        Write-Host '        wsl --install            # once, then reboot' -ForegroundColor DarkGray
        Write-Host '        wsl' -ForegroundColor DarkGray
        Write-Host '        sudo apt update && sudo apt install -y php-cli' -ForegroundColor DarkGray
        Write-Host "        cd /mnt/c/Users/$env:USERNAME/Desktop/worked/harbourx-new && ./run-local.sh" -ForegroundColor DarkGray
        Write-Host ''
        Write-Host '   2. Install PHP from a signed installer instead of WinGet:' -ForegroundColor Green
        Write-Host '        XAMPP    https://www.apachefriends.org/  (installs to C:\xampp)' -ForegroundColor DarkGray
        Write-Host '        Laragon  https://laragon.org/' -ForegroundColor DarkGray
        Write-Host '      This script finds both automatically. Re-run it afterwards.' -ForegroundColor DarkGray
        Write-Host ''
        Write-Host '   3. Just look at the interface, no PHP:' -ForegroundColor Green
        Write-Host '        .\run-local.ps1 -Static' -ForegroundColor DarkGray
        Write-Host ''
        Write-Host '  Turning Smart App Control off also works, but it cannot be turned back' -ForegroundColor DarkGray
        Write-Host '  on without resetting Windows — so try the options above first.' -ForegroundColor DarkGray
    } else {
        Write-Warn 'PHP was found but would not run.'
        if ($blocked) { Write-Host "  $blocked" -ForegroundColor DarkGray }
        Write-Host '  Try .\run-local.ps1 -Static to view the interface without PHP.' -ForegroundColor DarkGray
    }
    Write-Host ''
    exit 1
}

Write-Step "PHP $version  ($php)"

# --- 2. Make sure there is data to sign in with -----------------------------
if (-not (Test-Path -LiteralPath 'data\users.json')) {
    New-Item -ItemType Directory -Force -Path 'data' | Out-Null
    Copy-Item -LiteralPath 'tests\fixtures\users.json' -Destination 'data\users.json'
    Write-Step 'Seeded data\users.json with the test account:'
    Write-Host '        test.client@example.invalid  /  CiSmokeTest!2026' -ForegroundColor Green
    Write-Warn '      To use your real data instead, copy your own data\users.json over this one.'
} else {
    Write-Step 'Using the existing data\users.json'
}

# --- 3. Serve ---------------------------------------------------------------
$url = "http://localhost:$Port/"
Write-Step "Serving on $url"
Write-Host "        home     $url" -ForegroundColor DarkGray
Write-Host "        sign in  http://localhost:$Port/login.html" -ForegroundColor DarkGray
Write-Host "        admin    http://localhost:$Port/admin.php" -ForegroundColor DarkGray
Write-Host ''
Write-Host '  Press Ctrl+C to stop.' -ForegroundColor DarkGray
Write-Host ''

if (-not $NoBrowser) {
    Start-Process $url | Out-Null
}

& $php -S "localhost:$Port" -t .
