#!/usr/bin/env bash
#
# Stamps every local stylesheet and script reference with a version.
#
# Why this exists
# ---------------
# A browser that has already seen home.css will happily keep showing you the
# copy it has. The .htaccess asks it not to, and most of the time it listens —
# but "most of the time" is how you end up reloading a page five times, opening
# a private window, and concluding the deploy failed when it did not.
#
# A version in the URL takes the question away. home.css?v=20260919-2312 is a
# different address from home.css?v=20260918-1102, and nothing can serve you a
# cached copy of an address it has never fetched. Not the browser, not a proxy,
# not a CDN.
#
#   ./bump-version.sh              # stamps with the current UTC timestamp
#   ./bump-version.sh 2026-09-19   # or a version you choose
#
# Run it before you deploy. Then deploy. That is the whole routine — a normal
# refresh is enough for whoever visits next.
#
# Only local files are touched. Google Fonts, the Tidio widget and anything
# else with a scheme or a leading // is left exactly as it is: they are not
# ours to version, and their own URLs already change when they need to.

set -euo pipefail
cd "$(dirname "$0")"

VERSION="${1:-$(date -u +%Y%m%d-%H%M)}"

# Reject anything that would not survive a URL, so a typo cannot quietly
# produce a broken href on every page at once.
if ! printf '%s' "$VERSION" | grep -qE '^[A-Za-z0-9._-]+$'; then
  printf '\n  "%s" will not do as a version — letters, digits, dot, dash and underscore only.\n\n' "$VERSION" >&2
  exit 1
fi

printf '\n  Stamping assets with v=%s\n  ------------------------------------\n' "$VERSION"

changed=0
for file in *.html *.php; do
  [ -f "$file" ] || continue

  before=$(cat "$file")

  # href="name.css" / href="name.css?v=old"  ->  href="name.css?v=NEW"
  # The character class excludes : and / so an absolute or protocol-relative
  # URL never matches — only a bare filename sitting next to the page.
  perl -0pi -e "s{(href=\")([A-Za-z0-9._-]+\.css)(\?v=[A-Za-z0-9._-]*)?(\")}{\$1\$2?v=$VERSION\$4}g" "$file"
  perl -0pi -e "s{(src=\")([A-Za-z0-9._-]+\.js)(\?v=[A-Za-z0-9._-]*)?(\")}{\$1\$2?v=$VERSION\$4}g" "$file"

  if [ "$before" != "$(cat "$file")" ]; then
    n=$(grep -o "?v=$VERSION" "$file" | wc -l | tr -d ' ')
    printf '  %-24s %s reference(s)\n' "$file" "$n"
    changed=$((changed + 1))
  fi
done

printf '  ------------------------------------\n  %s file(s) updated.\n\n' "$changed"
