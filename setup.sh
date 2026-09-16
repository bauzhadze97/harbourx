#!/usr/bin/env bash
#
# One-command setup for HarbourX on Linux, WSL or macOS.
#
# Installs what is missing (git, PHP), fetches the project if it is not already
# here, seeds a data file to sign in against, and starts the server.
#
#   curl -fsSL https://raw.githubusercontent.com/bauzhadze97/harbourx/claude/optimize-modernize-animations-hmb3qf/setup.sh | bash
#
# or, from a clone:
#
#   ./setup.sh              # http://localhost:8000
#   ./setup.sh 3000         # a different port
#
# Safe to re-run: everything it does is checked first.

set -euo pipefail

PORT="${1:-8000}"
REPO_URL="https://github.com/bauzhadze97/harbourx.git"
BRANCH="claude/optimize-modernize-animations-hmb3qf"
TARGET="${HARBOURX_DIR:-$HOME/harbourx}"

say()  { printf '  %s\n' "$1"; }
warn() { printf '  %s\n' "$1" >&2; }
die()  { printf '\n  %s\n\n' "$1" >&2; exit 1; }

printf '\n  HarbourX — setup\n  ----------------\n'

# --- 1. Work out how to install things -------------------------------------
INSTALL=""
if command -v apt-get >/dev/null 2>&1;   then INSTALL="sudo apt-get install -y"
elif command -v dnf >/dev/null 2>&1;     then INSTALL="sudo dnf install -y"
elif command -v pacman >/dev/null 2>&1;  then INSTALL="sudo pacman -S --noconfirm"
elif command -v brew >/dev/null 2>&1;    then INSTALL="brew install"
fi

need() {
  local cmd="$1" pkg="$2"
  if command -v "$cmd" >/dev/null 2>&1; then
    say "$cmd already installed ($(command -v "$cmd"))"
    return 0
  fi
  if [ -z "$INSTALL" ]; then
    die "$cmd is missing and I cannot tell how to install it here. Install $pkg and re-run."
  fi
  say "Installing $pkg…"
  if command -v apt-get >/dev/null 2>&1; then sudo apt-get update -qq || true; fi
  # shellcheck disable=SC2086
  $INSTALL $pkg >/dev/null || die "Could not install $pkg. Install it by hand and re-run."
  command -v "$cmd" >/dev/null 2>&1 || die "$pkg installed but $cmd is still not on PATH."
  say "$cmd installed"
}

need git git
if command -v php >/dev/null 2>&1; then
  say "php already installed ($(php -r 'echo PHP_VERSION;'))"
else
  # Debian/Ubuntu call it php-cli; Fedora and Homebrew just call it php.
  if command -v apt-get >/dev/null 2>&1; then need php php-cli; else need php php; fi
fi

# --- 2. Get the project -----------------------------------------------------
if [ -f "./login.php" ] && [ -f "./dashboard.html" ]; then
  TARGET="$(pwd)"
  say "Using the project in $TARGET"
elif [ -d "$TARGET/.git" ]; then
  say "Updating the clone in $TARGET"
  git -C "$TARGET" fetch --quiet origin "$BRANCH"
  git -C "$TARGET" checkout --quiet "$BRANCH"
  git -C "$TARGET" pull --quiet origin "$BRANCH"
else
  say "Cloning into $TARGET"
  git clone --quiet --branch "$BRANCH" "$REPO_URL" "$TARGET"
fi

cd "$TARGET"

# --- 3. Something to sign in against ---------------------------------------
# data/users.json holds real client details, so it is never in the repository.
if [ ! -f data/users.json ]; then
  mkdir -p data
  cp tests/fixtures/users.json data/users.json
  say "Seeded data/users.json with the demo account:"
  printf '        demo.client@example.invalid  /  CiSmokeTest!2026\n'
  say "To use real data instead, copy your own data/users.json over it."
else
  say "Using the existing data/users.json"
fi

# --- 4. Serve ---------------------------------------------------------------
URL="http://localhost:${PORT}/"
printf '\n'
say "Serving $TARGET on $URL"
printf '        client   %s\n' "$URL"
printf '        admin    http://localhost:%s/admin.php\n' "$PORT"
printf '        support  http://localhost:%s/support.html\n\n' "$PORT"
say 'Press Ctrl+C to stop.'
printf '\n'

if command -v xdg-open >/dev/null 2>&1; then xdg-open "$URL" >/dev/null 2>&1 &
elif command -v open >/dev/null 2>&1; then open "$URL" >/dev/null 2>&1 &
elif command -v wslview >/dev/null 2>&1; then wslview "$URL" >/dev/null 2>&1 & fi

# The dashboard fires several requests at once; give the server room to answer
# them in parallel rather than queueing.
PHP_CLI_SERVER_WORKERS=4 exec php -S "localhost:${PORT}" -t .
