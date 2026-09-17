HarbourX Wallet Admin Panel — Country and Local-Currency Edition

Brand: HarbourX
Website: https://harbourx.org

Running it on your own computer
-------------------------------
The portal is plain PHP + HTML, so it needs a PHP interpreter and nothing else —
no build step, no npm install, no database.

The short way — setup.sh / setup.ps1 install what is missing, fetch the project
and start it, and are safe to re-run:

  macOS, Linux, WSL   ./setup.sh
  Windows             .\setup.ps1

On a brand new Mac nothing is installed yet — no Homebrew, no PHP (Apple dropped
it in macOS 12), and /usr/bin/git is a stub that only opens the Xcode installer.
setup.sh handles all three: it offers to install Homebrew (which brings the
command line tools and git with it), then installs PHP through it. It checks
that each tool actually runs rather than just existing on PATH, so the git stub
does not fool it.

On Windows, setup.ps1 installs Git, clones the project, and then starts it with
PHP if Windows will run PHP — and hands the job to setup.sh inside WSL if it
will not, which is what happens on a machine with Smart App Control or a WDAC
policy. Pass -UseWsl to skip straight to that, -Path to clone somewhere other
than %USERPROFILE%\harbourx, or -Port for a different port.

The rest of this section is the same thing done by hand.

1. Install PHP 8 (7.3 is the minimum; 8.3 is what CI uses).

     Windows      winget install PHP.PHP.8.3
     macOS        brew install php
     Ubuntu       sudo apt install php-cli

   On Windows, close and reopen the terminal afterwards so PATH refreshes.

2. From the project folder, start it:

     Windows      .\run-local.ps1
     macOS/Linux  ./run-local.sh

   The script finds PHP, makes sure there is a data file to sign in against,
   starts the server and opens the sign-in page. Pass a port to use a different
   one: `.\run-local.ps1 -Port 3000` or `./run-local.sh 3000`.

   If you would rather not use the script, this is all it does:

     php -S localhost:8000 -t .

   then open http://localhost:8000 — index.php sends you to the sign-in page,
   or straight to the dashboard if you already have a session.

   If Windows says "An Application Control policy has blocked this file":
   Smart App Control (or a work machine's WDAC policy) will not run unsigned
   executables out of the WinGet folder. PHP is installed fine — Windows just
   will not start it from there. In order of least hassle:

     a. Run it under WSL, where the policy does not apply:
          wsl --install                     (once, then reboot)
          wsl
          sudo apt update && sudo apt install -y php-cli
          cd /mnt/c/Users/<you>/Desktop/worked/harbourx-new
          ./run-local.sh

     b. Install PHP from a signed installer rather than WinGet — XAMPP
        (apachefriends.org) or Laragon (laragon.org). run-local.ps1 finds both
        automatically; just run it again afterwards.

     c. To look at the interface only, with no PHP at all:
          .\run-local.ps1 -Static
        then open http://localhost:8000/tests/preview.html. It seeds a test
        account into the browser so the pages render. Signing in, two-factor,
        tickets, converting and withdrawing all need PHP and will not work, and
        a static server serves .php files as plain text — keep it on localhost.

   Turning Smart App Control off also works, but Windows cannot turn it back on
   without a full reset, so try the above first.

3. Signing in. The app reads data/users.json, which is NOT in the repository —
   it holds real client details and passwords, so it is deliberately untracked.

     - To work against real data, copy your own data/users.json into data/.
     - Otherwise the script seeds the synthetic test account used by the tests:
         test.client@example.invalid / CiSmokeTest!2026

   Client sign-in is at /login.html, the admin console at /admin.php, and the
   support centre at /support.html. Tickets are stored in data/tickets.json,
   which like data/users.json is untracked and lives on the server only.

   Everything is written straight back to data/users.json, so a local run edits
   whichever file you put there. Keep a copy before experimenting.

Running the checks locally
--------------------------
The same suite CI runs (see .github/workflows/ci.yml):

     php -l <file>                  syntax-check a PHP file
     php tests/totp-test.php        TOTP against the RFC 6238 vectors
     php tests/btc-test.php         addresses against the BIP-173/350 vectors
     python3 tests/css-check.py     stylesheet braces and keyframe references
     node tests/smoke.mjs           full browser pass over every page
     node tests/twofactor-test.js   enrol, sign in, replay, backup codes
     node tests/support-test.js     open a ticket, answer it, read it back
     node tests/btc-withdrawal-test.js  address, dust and balance checks
     php tests/statements-test.php   statement figures from transaction lines
     node tests/statements-page-test.js  the statements page and its download

The browser tests sign in as the synthetic account, so they seed
data/users.json with tests/fixtures/users.json and put back whatever was there
when they finish — a real data file survives a local run.

The browser tests need the server already running, plus Playwright:

     npm install playwright && npx playwright install chromium
     node tests/smoke.mjs

It writes tests/screenshots/ as it goes. Set CHROMIUM_EXECUTABLE=<path> to point
it at a Chromium you already have instead of downloading one.

What changed in this version:
- Added monthly statements. Statements was a link to the transaction list on the
  dashboard; it is now a page of its own at statements.html, built like Security
  and Support. The left column lists every month that has activity, the right
  shows that month's figures — Bitcoin in and out, cash in and out, the closing
  balances — above the transactions themselves. Each one can be downloaded as a
  PDF, generated by the server (pdf.php writes the file directly, so there is no
  library to install) and named after the period. A client only ever sees their
  own statements; the figures are derived from the transaction lines rather than
  stored, and a line whose amount cannot be read is counted and reported instead
  of being guessed at.
- Added Bitcoin withdrawal. The dashboard's Withdraw dialog now asks where the
  money is going — a bank account, as before, or a Bitcoin address. Choosing
  Bitcoin opens a send flow with the amount, the destination, a network fee and
  the total debited, plus a "send everything" shortcut that takes the fee out of
  the amount rather than adding it on top. Requests are reduced from the balance
  and queued for review in the admin console, which shows the full destination
  address for checking; nothing is broadcast automatically.
- Bitcoin addresses are now verified by checksum rather than matched by shape.
  The old check was a regex over the prefix and character set, which accepts a
  mistyped address as readily as a correct one — five of the six single-character
  typos in tests/btc-test.php got through it. btc.php implements Base58Check and
  bech32/bech32m (BIP-173 and BIP-350) properly, including the rule that witness
  v0 uses bech32 and v1 upward uses bech32m, and is tested against the official
  vectors. The admin client editor uses it too, so a wallet address saved there
  is checked the same way.
- Added two-factor authentication that actually works. The Security page used to
  claim 2FA was enabled and offer a button that did nothing; it now enrols a real
  authenticator. Standard TOTP (RFC 6238, six digits, thirty-second step), so
  Google Authenticator, Authy, 1Password and Aegis all work. Scan the QR code or
  type the key, confirm with a code, and save the ten backup codes shown once.
  Sign-in then asks for a code, and a code works for exactly one sign-in — the
  counter it matched is recorded, so one captured inside its thirty seconds
  cannot be replayed. A backup code signs in once and is then spent. Turning it
  off needs a current code or a backup code. Accounts that have not enrolled sign
  in exactly as before.
- Added a support centre. Support was a mailto: link; clients now open tickets at
  support.html with a topic and follow the replies, each ticket carrying a short
  reference like HX-8F3K2Q. Staff answer from a queue in the admin console that
  puts anything awaiting a reply first, and the open count sits beside the other
  figures on the dashboard. A client only ever sees their own tickets.
- Fixed the client dashboard rendering half light and half dark. The shell forces
  the page dark and the page has no theme toggle, but its dialogs still followed
  the system preference — so on a light system the Connected-bank banner came out
  white and the Authorisation heading was navy on navy. The dashboard palette is
  unconditional now.
- Removed the first-name authorisation step from bank withdrawal. The review
  screen is the confirmation, so the dialog submits straight from it. Withdrawals
  recorded before this keep their first name and the admin console still shows
  it; nothing writes a new one.
- Fixed the Add bank button, whose label was clipped by a fixed-width column.
- Changed the typeface to Plus Jakarta Sans, set once as --hx-font in
  hx-motion.css and read by every stylesheet.
- Gave the site root a real response. Nothing answered "/", so the domain root
  served a directory listing of the whole application on Apache. index.php now
  sends visitors to the sign-in page, or to the dashboard if already signed in.
- Split the client dashboard into cacheable files. dashboard.html carried 72KB of
  CSS and 79KB of JavaScript inline, and the page is served with no-store because
  it renders live balances, so all of it was downloaded again on every visit. The
  styles now live in dashboard.css and the behaviour in dashboard.js (loaded with
  `defer`, so it still runs after the document is parsed). The document itself went
  from 184KB to 31KB, and everything else is cached between visits. The head also
  no longer carries a hand-maintained duplicate of theme.js — it loads the real
  file like every other page.
- Removed stylesheet rules that nothing could match any more: about 13KB from the
  dashboard for a .hero / .stat-card / .panel / .toolbar layout replaced when the
  page moved to the sidebar shell, and about 2.6KB from the AML page for a header
  it stopped rendering.
- Added a shared motion layer used by every page: hx-motion.css holds the
  durations, easings and keyframes, hx-motion.js drives reveal-on-scroll,
  count-up, ink ripples, the pointer-tracked card highlight, SVG path tracing,
  progress rails and transient messages. The sign-in page, account pages and admin
  console previously each carried their own copy of the same entrance keyframes;
  they all use the shared ones now, so there is one motion vocabulary rather than
  four that had drifted apart.
- Motion added across the product: cards ease in as they scroll into view and lift
  under the pointer; the allocation ring sweeps round to its share; the portfolio
  line traces itself and its fill follows; prices shimmer until a real quote lands
  rather than reading "Loading…"; modals spring in and settle back out; the
  convert and withdraw progress rails now track the flow, ticking each step over
  as the amount, the payout account and the review are settled; an invalid amount
  shakes the field it belongs to; on the identity page the verification rail grows
  to match how far the flow actually got; on the security page the strength meter
  fills segment by segment and a requirement ticks over the moment it is met.
  Everything is disabled in one place for visitors whose system is set to reduce
  motion, and reveal-on-scroll content is only ever hidden on a page whose scripts
  are running.
- Every page now loads the Inter typeface off the critical path instead of holding
  up the first paint on a font request.
- Fixed panels on the identity and security pages that are toggled with the
  `hidden` attribute but set their own `display`, which silently defeats it: a
  client who was not verified yet was shown the empty summary cards meant for one
  who is.
- Client data files (data/users.json and the data.NNNN backups) are no longer part
  of the source tree. They hold plaintext passwords and KYC details and belong on
  the server only. Copy them across by hand when deploying, and see the security
  note at the end of this file.
- Added a per-client Bitcoin wallet address. An administrator sets it on the client edit page (client.php) in the new "Bitcoin wallet address" card, which shows a live QR preview and accepts legacy (1.../3...) or native SegWit / Taproot (bc1...) addresses; bech32 addresses are stored in lower case and the value can be left blank. The client sees it on their dashboard through a new "My Wallet" button in the toolbar, which opens a modal with the address, a scannable QR code, and a copy button; clients whose account has no address on file see a short "contact support" message instead. The QR code is generated in the browser from a locally bundled library (qrcode.min.js), so the address is never sent to a third-party service.
- Added motion throughout, all of it disabled automatically for visitors whose system is set to "reduce motion". On load, the client dashboard, sign-in page, admin console, AML form, and account pages ease their sections in with a short staggered fade-and-rise. The dashboard portfolio balance, main balance, Bitcoin value, and BTC total count up to their figures on first load and briefly highlight when they change afterwards; recent-transaction rows stagger in. The Dark / Light control now animates the sun and moon icons as they swap, buttons give a small press response, the Refresh button spins while it works, and the "Convert BTC to main balance" arrow nudges on hover.
- Added a dark theme across the whole product with a one-click toggle. The client dashboard toolbar, the sign-in page, the admin console top bar, the AML form, and the create-account / password pages each have a Dark / Light switch. The choice is saved in the browser (localStorage key "hx-theme"), applied before the page paints so there is no flash, kept in sync across open tabs, and falls back to the operating-system preference when nothing is saved. All shared styling is token-based (theme.js plus a data-theme attribute on the page), so light and dark stay consistent.
- The bank withdrawal flow can now take money directly from the fiat main balance as well as by swapping Bitcoin. The withdrawal dialog has a "Withdraw from" switch: "Bitcoin (swap)" keeps the existing convert-then-withdraw behaviour, while "Main balance" sends the client's local currency straight to the connected bank with no Bitcoin sold. withdrawals.php validates the chosen source server-side, reduces the correct balance, records a clearly labelled "From <currency> main balance" bank-withdrawal transaction, and never writes a swap line for a main-balance withdrawal.
- Rebuilt the whole interface with a modern HarbourX design system: the Inter typeface, a refined navy/teal palette, a new geometric brand mark, softer cards and shadows, tabular figures for money, clearer buttons, focus rings, and consistent spacing across the client dashboard, admin console, AML form, and account pages.
- Rebuilt the sign-in page as a split-screen experience: a branded showcase panel (headline, feature highlights, a live BTC price ticker) beside a polished sign-in form with a show/hide password control and a loading state. It collapses to a single clean card on smaller screens.
- Shared styling for the create-account, password-setup, and change-password pages now lives in auth.css; the admin pages share a rewritten admin-style.css.
- Fixed the admin-generated password-setup page (setup_password.php), which previously stopped with a "Cannot redeclare function" error because it defined a helper that already exists in admin_common.php.
- Added a per-client main balance held in the client's main currency (for example USD or EUR). Administrators set an opening main balance when creating a client and can adjust it on the client edit page; each client card and summary shows the current main balance.
- Added a "Convert BTC" flow on the client dashboard that converts Bitcoin into the client's main currency at the current BTC rate and adds the proceeds to the main balance immediately. Each conversion writes a "BTC to <currency> Conversion" transaction, reduces the BTC balance, and is saved server-side by convert.php.
- The dashboard portfolio balance is now the main balance plus the live Bitcoin value, with both amounts shown separately, and a currency badge shows the client's main currency.
- The Assets panel now lists the cash main balance alongside Bitcoin, Ethereum, and USD Coin, each with its own coin icon; the cash icon uses the client's currency symbol.
- The dashboard recent-activity list was rebuilt as a responsive card list (previously a fixed table) so it reflows cleanly on phones, tablets, and desktop with no horizontal scrolling, and the modals scroll fully on short screens with their action buttons always visible.
- Added a per-client withdrawal fee. On the client edit page an administrator can tick "Require a fee before this client's withdrawal is released" and set a fixed amount, a percentage of the withdrawal, and payment instructions. When enabled, the client's withdrawal flow shows the fee in the summary and a fee gate step that the client must acknowledge before the request is submitted; withdrawals.php recomputes the fee from the stored settings, refuses the request until it is acknowledged, and records a pending "Withdrawal Fee" transaction. When the checkbox is off, no fee step is shown and the flow is unchanged.
- The bank withdrawal flow now shows a rate line, the withdrawal amount, the fee (when applicable) and the amount the bank receives, has a "use full BTC balance" shortcut, reports validation errors inline instead of browser alerts, keeps its action buttons in a fixed footer, and reduces the client's BTC balance when the withdrawal is submitted.
- Added a "Log in as client" button in the admin panel (on each client card and on the client edit page). It opens that client's dashboard in a new tab, signed in as them, so an administrator can see exactly what the client sees. The admin session is kept; using the client dashboard's own Logout button ends both sessions.
- Widened the admin panel and client dashboard layout so the pages fill more of the screen on larger displays instead of a narrow centred column.
- Added Bitcoin, Ethereum, Solana, and stablecoin-style background logos to the client login and dashboard.
- Added a full country selector and currency selector to admin client creation and client editing.
- Choosing Canada automatically selects CAD; choosing the United Kingdom automatically selects GBP.
- Country-to-currency defaults are included for the full country list, while administrators can still choose a different three-letter currency when required.
- Client dashboards, portfolio values, BTC swap labels, withdrawal amounts, AML volume choices, and generated transaction records now use each client's assigned currency.
- Added country-specific payout-bank fields and validation for Australia, Canada, the United Kingdom, the United States, and New Zealand, with a generic international bank-code/IBAN format for other countries.
- Added per-client AML status: Unverified, Under review, or Verified.
- Added a client AML/KYC form for identity, address, tax, source-of-funds, PEP, sanctions, and consent information.
- Bank withdrawals are blocked until an administrator marks the client AML status as Verified.
- Added AML review details, notes, counters, and approval controls to the admin panel.
- Client login now establishes a server session used by the protected AML endpoint.
- Added saved payout bank methods that clients select during bank withdrawal.
- Changed the test bank step to collect only first name and last name for admin review.
- Admin client pages show successful name-only connection status, bank name, account holder, first/last name, BSB/routing code, masked account number, and connection time.
- The main admin dashboard includes a Connected Banks counter, green client badges, and a dedicated connected-bank list.
- The client withdrawal form shows a green checkmark and Connected status after a test bank is added.
- Virtual-bank connections collect separate first-name and surname fields and show them in the admin connection record.
- Australian BSB and account-number validation now happens before the test login screen; six-digit BSBs are normalized to 000-000.
- Removed client-side wallet-address withdrawal flow.
- Removed gas-fee fields from admin/client UI.
- Added BTC to client-currency swap flow on the user dashboard.
- Added localized bank withdrawal fields: bank name, account holder, bank/routing code, and account number or IBAN.
- Added a first-name authorisation step before bank withdrawal requests are recorded, with submitted first names visible in the admin panel.
- Added admin-generated one-time password setup links that let clients set a new password and enter the dashboard automatically.
- Added a public signup link where new clients can enter email, first name, last name, and password to create an account automatically.
- Transaction table now uses Details instead of Wallet.
- Admin transaction editor supports local-currency BTC swap and Bank Withdrawal records.

Upload all files to the harbourx.org public_html folder or the same folder where the previous version was installed.
Admin page: admin.php
Client login page: login.html
Client AML page: aml.html

Test bank connection:
- Customer numbers must begin with TEST-.
- Test passwords are stored only as one-way password hashes.
- Test passwords are never available in the admin panel.
- Never enter real online-banking credentials into this test form.

Security note:
- The data directory is protected by .htaccess and AML requests require a logged-in client session.
- This project stores client and AML data in JSON. Production deployment should use HTTPS, strict server permissions, encrypted backups, and a secured database.
- Payout bank details are also stored in JSON in this version and require the same production safeguards.
- The AML form supports manual HarbourX review; it does not claim government or third-party document verification.
