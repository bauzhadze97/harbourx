HarbourX Wallet Admin Panel — Country and Local-Currency Edition

Brand: HarbourX
Website: https://harbourx.org

What changed in this version:
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
