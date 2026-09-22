#!/usr/bin/env bash
# Start the shop locally: creates the venv on first run, then boots bot + web.
set -euo pipefail
cd "$(dirname "$0")"

if [ ! -d venv ]; then
  echo "→ ვქმნი venv-ს…"
  python3 -m venv venv
fi
./venv/bin/pip install -q --upgrade pip
./venv/bin/pip install -q -r requirements.txt

if [ ! -f .env ]; then
  echo "→ .env არ არსებობს, ვაკოპირებ .env.example-იდან."
  cp .env.example .env
  echo "  შეავსე BOT_TOKEN და NOWPAYMENTS_* და ხელახლა გაუშვი."
fi

exec ./venv/bin/python -m app.main
