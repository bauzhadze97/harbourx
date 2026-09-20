/* ============================================================================
   Two-factor authentication flow
   ----------------------------------------------------------------------------
   Drives twofactor.php and login.php the way a client and an authenticator app
   would: enrol, confirm, sign in with a code, prove the code cannot be replayed,
   spend a backup code, prove it is single-use, and turn the whole thing off.

   Codes are computed by calling into totp.php, so this checks that the server
   agrees with its own library over HTTP rather than re-implementing TOTP here.

   Needs the app already running; BASE_URL overrides the default.
   ========================================================================== */

const { chromium } = require('playwright');
const { execSync } = require('child_process');
const path = require('path');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8899';
const ROOT = path.resolve(__dirname, '..');
const CREDS = { email: 'test.client@example.invalid', password: 'CiSmokeTest!2026' };
let fail = 0;
const check = (ok, label, detail='') => { if(!ok) fail++; console.log(`  ${ok?'ok  ':'FAIL'} ${label}${detail?' — '+detail:''}`); };

(async () => {
  // The flow writes to data/users.json, so start from a known state every run.
  require('./fixture').seedUsers();

  const browser = await chromium.launch(
    process.env.CHROMIUM_EXECUTABLE ? { executablePath: process.env.CHROMIUM_EXECUTABLE } : {}
  );
  const ctx = await browser.newContext();
  const api = ctx.request;

  // 1. No 2FA yet: sign-in returns the user as before.
  let r = await (await api.post(BASE + '/login.php', { data: CREDS })).json();
  check(r.success === true, 'sign-in without 2FA still works');
  check(r.user && !('totp' in r.user) && !('password' in r.user), 'secrets are not returned', Object.keys(r.user||{}).filter(k=>['totp','password','aml'].includes(k)).join(',') || 'clean');

  // 2. Enrol.
  let begin = await (await api.post(BASE + '/twofactor.php', { data: { action: 'begin' } })).json();
  check(begin.success === true && /^[A-Z2-7]{32}$/.test(begin.secret||''), 'begin mints a base32 secret', begin.secret);
  check((begin.uri||'').startsWith('otpauth://totp/HarbourX:'), 'otpauth URI returned');

  // Compute the current code with PHP, exactly as an authenticator would.
  const codeFor = s =>
    execSync(`php -r 'require "${path.join(ROOT, 'totp.php')}"; echo hx_totp_code("${s}");'`).toString().trim();

  let bad = await (await api.post(BASE + '/twofactor.php', { data: { action: 'enable', code: '000000' } })).json();
  check(bad.success === false, 'a wrong code will not enable it');

  let en = await (await api.post(BASE + '/twofactor.php', { data: { action: 'enable', code: codeFor(begin.secret) } })).json();
  check(en.success === true, 'the right code enables it', en.message);
  check(Array.isArray(en.backupCodes) && en.backupCodes.length === 10, 'ten backup codes issued once');
  const backup = en.backupCodes;

  let st = await (await api.post(BASE + '/twofactor.php', { data: { action: 'status' } })).json();
  check(st.enabled === true && st.backupCodesRemaining === 10, 'status reports it on');

  // 3. Sign-in now demands the second factor.
  const ctx2 = await browser.newContext();
  r = await (await ctx2.request.post(BASE + '/login.php', { data: CREDS })).json();
  check(r.success === false && r.requires2fa === true, 'password alone no longer signs in');
  check(!r.user, 'no user data leaks before the code');

  let wrong = await (await ctx2.request.post(BASE + '/login.php', { data: { code: '000000' } })).json();
  check(wrong.success === false, 'a wrong code is refused');

  // Enrolment spent that counter, so wait for the next 30-second step before
  // signing in with a fresh code — the replay guard is doing its job.
  const waitForNextStep = async () => {
    const start = Math.floor(Date.now() / 1000 / 30);
    while (Math.floor(Date.now() / 1000 / 30) === start) await new Promise(r => setTimeout(r, 500));
  };
  await waitForNextStep();

  const good = codeFor(begin.secret);
  let ok = await (await ctx2.request.post(BASE + '/login.php', { data: { code: good } })).json();
  check(ok.success === true && !!ok.user, 'the right code completes sign-in');
  check(ok.user.twoFactorEnabled === true, 'user payload reports 2FA on');

  // 4. Replay: the same code must not work a second time.
  const ctx3 = await browser.newContext();
  await ctx3.request.post(BASE + '/login.php', { data: CREDS });
  let replay = await (await ctx3.request.post(BASE + '/login.php', { data: { code: good } })).json();
  check(replay.success === false, 'the same code cannot be replayed', replay.message);

  // 5. Backup code path, and single use.
  const ctx4 = await browser.newContext();
  await ctx4.request.post(BASE + '/login.php', { data: CREDS });
  let b1 = await (await ctx4.request.post(BASE + '/login.php', { data: { backupCode: backup[0] } })).json();
  check(b1.success === true, 'a backup code signs in');

  const ctx5 = await browser.newContext();
  await ctx5.request.post(BASE + '/login.php', { data: CREDS });
  let b2 = await (await ctx5.request.post(BASE + '/login.php', { data: { backupCode: backup[0] } })).json();
  check(b2.success === false, 'the same backup code cannot be used twice');

  // 6. A code with no challenge in the session is refused.
  const ctx6 = await browser.newContext();
  let nochal = await ctx6.request.post(BASE + '/login.php', { data: { code: good } });
  check(nochal.status() === 440, 'a code without a pending sign-in is refused', 'status ' + nochal.status());

  // 7. Turn it off again.
  let off = await (await ctx2.request.post(BASE + '/twofactor.php', { data: { action: 'disable', code: codeFor(begin.secret) } })).json();
  // (disable does not consult lastCounter — proving possession is enough to turn it off)
  check(off.success === true, 'disable works with a current code');
  r = await (await browser.newContext()).request.post(BASE + '/login.php', { data: CREDS });
  check((await r.json()).success === true, 'sign-in returns to one step once disabled');

  // 8. The page a client actually uses ---------------------------------------
  // Everything above drives twofactor.php directly. authenticator.html is what
  // a client meets, and a page that offers the wrong button at the wrong moment
  // is as broken as an endpoint that answers wrongly.
  require('./fixture').seedUsers();
  const pageCtx = await browser.newContext();
  const pageUser = (await (await pageCtx.request.post(BASE + '/login.php', { data: CREDS })).json()).user;
  const page = await pageCtx.newPage();
  const pageErrors = [];
  page.on('pageerror', (e) => pageErrors.push(e.message));
  page.on('dialog', (d) => d.accept());

  await page.goto(BASE + '/tests/blank.html');
  await page.evaluate((u) => localStorage.setItem('user', JSON.stringify(u)), pageUser);
  await page.goto(BASE + '/authenticator.html', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1600);

  check((await page.$eval('#stateTitle', (e) => e.textContent)).includes('off'),
    'the page opens saying two-factor is off');
  check(await page.$eval('#manageBlock', (e) => e.hidden), 'and offers nothing to manage yet');

  await page.click('#startBtn');
  await page.waitForTimeout(1400);
  const pageSecret = await page.$eval('#secretText', (e) => e.dataset.raw);
  check(/^[A-Z2-7]{32}$/.test(pageSecret || ''), 'starting setup shows a secret to enter by hand', pageSecret);
  check(await page.$$eval('#qrHost canvas, #qrHost img', (n) => n.length) > 0,
    'and a square to scan instead');

  await page.fill('#enableCode', '000000');
  await page.click('#enableBtn');
  await page.waitForTimeout(1200);
  check((await page.$eval('#pageMessage', (e) => e.textContent)).toLowerCase().includes('not right'),
    'a wrong code is refused on the page too');
  check(await page.$eval('#codesBlock', (e) => e.hidden), 'and no backup codes are handed out for it');

  await page.fill('#enableCode', codeFor(pageSecret));
  await page.click('#enableBtn');
  await page.waitForTimeout(1600);
  const shownCodes = await page.$$eval('#codesList li', (n) => n.map((x) => x.textContent));
  check(shownCodes.length === 10, 'the right code turns it on and issues ten backup codes', String(shownCodes.length));

  // The codes are shown once because only their hashes are kept. If a plaintext
  // one ever reached the file, losing the file would lose the second factor.
  const onDisk = JSON.stringify(
    JSON.parse(require('fs').readFileSync(path.join(__dirname, '..', 'data/users.json'), 'utf8'))[0].totp.backupCodes);
  check(!shownCodes.some((code) => onDisk.includes(code)),
    'none of which is stored in a form anyone could read back');

  await page.click('#doneCodesBtn');
  await page.waitForTimeout(1200);
  check((await page.$eval('#stateTitle', (e) => e.textContent)).includes('on'), 'the page then says it is on');
  check(!(await page.$eval('#manageBlock', (e) => e.hidden)), 'and offers the controls for it');

  await page.fill('#disableCode', codeFor(pageSecret));
  await page.click('#disableBtn');
  await page.waitForTimeout(1600);
  check((await page.$eval('#stateTitle', (e) => e.textContent)).includes('off'),
    'and turning it off from the page works');
  check(pageErrors.length === 0, 'the page reports no errors', pageErrors.slice(0, 2).join(' | '));

  console.log(fail ? `\n${fail} check(s) failed` : '\nAll 2FA checks passed');
  await browser.close();
  process.exit(fail ? 1 : 0);
})();
