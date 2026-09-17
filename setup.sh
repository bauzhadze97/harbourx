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

# Existing on PATH is not the same as working. macOS ships a git at /usr/bin/git
# that is only a stub: run it without the Xcode command line tools and it pops a
# GUI installer instead of doing anything.
runs() {
  command -v "$1" >/dev/null 2>&1 && "$@" >/dev/null 2>&1
}

IS_MAC=0
[ "$(uname -s)" = "Darwin" ] && IS_MAC=1

# On macOS everything comes from Homebrew, which a new machine does not have.
# Installing it also installs the command line tools, and so git.
ensure_brew() {
  if command -v brew >/dev/null 2>&1; then return 0; fi

  # It may be installed but not on PATH yet — Apple Silicon and Intel differ.
  for candidate in /opt/homebrew/bin/brew /usr/local/bin/brew; do
    if [ -x "$candidate" ]; then
      eval "$("$candidate" shellenv)"
      say "Found Homebrew at $candidate"
      return 0
    fi
  done

  warn ''
  warn 'Homebrew is not installed. It is how macOS gets git and PHP, and'
  warn 'installing it also installs the Xcode command line tools.'
  warn ''
  warn 'This asks for your Mac password and takes a few minutes.'
  printf '  Install Homebrew now? [y/N] '
  read -r reply </dev/tty || reply=""
  case "$reply" in
    [Yy]*) ;;
    *) die "Install it yourself with the command at https://brew.sh then re-run this script." ;;
  esac

  /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"     || die "Homebrew install failed. See https://brew.sh and re-run this script afterwards."

  for candidate in /opt/homebrew/bin/brew /usr/local/bin/brew; do
    [ -x "$candidate" ] && eval "$("$candidate" shellenv)" && break
  done
  command -v brew >/dev/null 2>&1 || die "Homebrew installed but 'brew' is still not on PATH. Open a new terminal and re-run."
  say 'Homebrew installed'
}

if [ "$IS_MAC" = "1" ]; then ensure_brew; fi

INSTALL=""
if command -v brew >/dev/null 2>&1;      then INSTALL="brew install"
elif command -v apt-get >/dev/null 2>&1; then INSTALL="sudo apt-get install -y"
elif command -v dnf >/dev/null 2>&1;     then INSTALL="sudo dnf install -y"
elif command -v pacman >/dev/null 2>&1;  then INSTALL="sudo pacman -S --noconfirm"
fi

need() {
  local cmd="$1" pkg="$2" probe="$3"
  # shellcheck disable=SC2086
  if runs "$cmd" $probe; then
    say "$cmd already installed ($(command -v "$cmd"))"
    return 0
  fi
  if [ -z "$INSTALL" ]; then
    die "$cmd is missing and I cannot tell how to install it here. Install $pkg and re-run."
  fi
  say "Installing $pkg… (this can take a few minutes)"
  if command -v apt-get >/dev/null 2>&1 && [ "$INSTALL" != "brew install" ]; then
    sudo apt-get update -qq || true
  fi
  # shellcheck disable=SC2086
  $INSTALL $pkg || die "Could not install $pkg. Install it by hand and re-run."
  # shellcheck disable=SC2086
  runs "$cmd" $probe || die "$pkg installed but $cmd still will not run. Open a new terminal and re-run."
  say "$cmd installed"
}

need git git --version

# Debian and Ubuntu call the interpreter php-cli; Homebrew and Fedora call it php.
if command -v apt-get >/dev/null 2>&1 && [ "$INSTALL" != "brew install" ]; then
  need php php-cli --version
else
  need php php --version
fi
say "php $(php -r 'echo PHP_VERSION;')"

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
