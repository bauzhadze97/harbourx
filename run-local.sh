#!/usr/bin/env bash
#
# Runs the HarbourX portal on this computer (macOS / Linux).
#
# Starts PHP's built-in web server in this folder and opens the sign-in page.
# Nothing is installed and nothing leaves the machine — the app reads and writes
# data/users.json in place, exactly as it does on the real host.
#
# If data/users.json is missing (a fresh clone never has it — it holds real
# client details and is deliberately untracked) the script seeds the synthetic
# test account used by the test suite and prints its credentials.
#
#   ./run-local.sh            # http://localhost:8000
#   ./run-local.sh 3000       # a different port

set -euo pipefail
cd "$(dirname "$0")"

PORT="${1:-8000}"

printf '\n  HarbourX — local server\n  ------------------------\n'

# --- 1. Find PHP ------------------------------------------------------------
if ! command -v php >/dev/null 2>&1; then
  cat <<'MSG'

  PHP is not installed (or not on your PATH).

  Install it, then run this script again:

    macOS     brew install php
    Ubuntu    sudo apt install php-cli
    Fedora    sudo dnf install php-cli

MSG
  exit 1
fi

printf '  PHP %s  (%s)\n' "$(php -r 'echo PHP_VERSION;')" "$(command -v php)"

# --- 2. Make sure there is data to sign in with -----------------------------
if [ ! -f data/users.json ]; then
  mkdir -p data
  cp tests/fixtures/users.json data/users.json
  printf '  Seeded data/users.json with the test account:\n'
  printf '        test.client@example.invalid  /  CiSmokeTest!2026\n'
  printf '  To use your real data instead, copy your own data/users.json over this one.\n'
else
  printf '  Using the existing data/users.json\n'
fi

# --- 3. Serve ---------------------------------------------------------------
URL="http://localhost:${PORT}/"
printf '  Serving on %s\n' "$URL"
printf '        client   %s\n' "$URL"
printf '        admin    http://localhost:%s/admin.php\n\n' "$PORT"
printf '  Press Ctrl+C to stop.\n\n'

# Open a browser if this machine has one; a headless box simply skips it.
if command -v open >/dev/null 2>&1; then
  open "$URL" >/dev/null 2>&1 &
elif command -v xdg-open >/dev/null 2>&1; then
  xdg-open "$URL" >/dev/null 2>&1 &
fi

# PHP's built-in server is single-threaded unless told otherwise; the dashboard
# fires a few requests at once, so give it room to answer them in parallel.
PHP_CLI_SERVER_WORKERS=4 php -S "localhost:${PORT}" -t .
