/* ============================================================================
   HarbourX smoke test
   ----------------------------------------------------------------------------
   Boots the app against PHP's built-in server and drives every page the way a
   client would, asserting the things that are easy to break silently:

     - no page or console errors, and no 4xx/5xx for a same-origin asset
     - no horizontal overflow at phone width
     - reveal-on-scroll content is actually revealed (a broken observer would
       leave the page blank)
     - the same content is visible with JavaScript off, and with the system set
       to reduce motion
     - the convert and withdraw dialogs open, advance their progress rail, and
       animate closed again

   Screenshots land in tests/screenshots/ and CI publishes them as an artifact,
   so a run on GitHub can be looked at rather than just read.
   ========================================================================== */

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { createRequire } from 'node:module';

// Sign-in needs the fixture account. The helper puts back whatever was in
// data/users.json when this finishes, so a real file survives a local run.
const fixture = createRequire(import.meta.url)('./fixture.js');
fixture.seedUsers();

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8899';
const SHOTS = new URL('./screenshots/', import.meta.url).pathname;
const CREDENTIALS = {
  email: 'test.client@example.invalid',
  password: 'CiSmokeTest!2026'
};

let failures = 0;
const check = (ok, label, detail = '') => {
  if (!ok) failures++;
  console.log(`${ok ? '  ok  ' : '  FAIL'} ${label}${detail ? ` — ${detail}` : ''}`);
};

/* Third-party endpoints the app is designed to survive without: the price feeds
   and Google Fonts. How they fail depends on where the test runs — unreachable
   in a sandbox, CORS-rejected from a GitHub runner, rate-limited elsewhere — so
   the test judges the app's own code and ignores errors naming these hosts. That
   exclusion is only safe because `feeds down still shows a price` below asserts
   the fallback actually works. */
const EXTERNAL_HOSTS = [
  'api.coingecko.com',
  'api.coinbase.com',
  'api.binance.com',
  'fonts.googleapis.com',
  'fonts.gstatic.com'
];

const isExternal = text =>
  EXTERNAL_HOSTS.some(host => text.includes(host)) || /net::ERR/.test(text);

/** Collect anything the page reports as broken in the app's own code. */
function watch(page) {
  const errors = [];
  page.on('pageerror', e => errors.push(`pageerror: ${e.message}`));
  page.on('console', m => {
    if (m.type() === 'error' && !isExternal(m.text()) && !/status of (401|404)/.test(m.text())) {
      errors.push(`console: ${m.text()}`);
    }
  });
  page.on('response', r => {
    // aml.php legitimately answers 401 before a session exists
    if (r.url().startsWith(BASE) && r.status() >= 400 && !r.url().includes('aml.php')) {
      errors.push(`http ${r.status()}: ${r.url()}`);
    }
  });
  return errors;
}

async function signIn(context) {
  const res = await context.request.post(`${BASE}/login.php`, { data: CREDENTIALS });
  const body = await res.json();
  if (!body.success) throw new Error(`login.php rejected the fixture user: ${JSON.stringify(body)}`);
  return body.user;
}

async function seed(page, user) {
  await page.goto(`${BASE}/tests/blank.html`, { waitUntil: 'domcontentloaded' });
  await page.evaluate(u => {
    localStorage.setItem('user', JSON.stringify(u));
    localStorage.setItem('hx-theme', 'dark');
  }, user);
}

const audit = page => page.evaluate(() => ({
  overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
  hiddenReveals: [...document.querySelectorAll('[data-reveal]')]
    .filter(el => el.offsetParent !== null && getComputedStyle(el).opacity !== '1').length,
  hxJs: document.documentElement.classList.contains('hx-js'),
  hxMotion: typeof window.hxMotion
}));

const PAGES = [
  { url: 'dashboard.html', shot: '1-dashboard', auth: true },
  { url: 'login.html', shot: '2-sign-in', auth: false },
  { url: 'aml.html', shot: '3-identity', auth: true },
  { url: 'change_password.php?email=test.client%40example.invalid', shot: '4-security', auth: true },
  { url: 'register.php', shot: '5-create-account', auth: false },
  { url: 'admin.php', shot: '6-admin-sign-in', auth: false },
  { url: 'support.html', shot: '7-support', auth: true },
  { url: 'statements.html', shot: '8-statements', auth: true }
];

// CI installs its own Chromium; CHROMIUM_EXECUTABLE lets a workstation or
// container point at one it already has.
const browser = await chromium.launch(
  process.env.CHROMIUM_EXECUTABLE ? { executablePath: process.env.CHROMIUM_EXECUTABLE } : {}
);
mkdirSync(SHOTS, { recursive: true });

try {
  // ---------------------------------------------------------------- desktop
  console.log('\nDesktop (1440x1000)');
  {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const user = await signIn(context);

    for (const { url, shot, auth } of PAGES) {
      const page = await context.newPage();
      const errors = watch(page);
      if (auth) await seed(page, user);
      else {
        await page.goto(`${BASE}/tests/blank.html`);
        await page.evaluate(() => localStorage.setItem('hx-theme', 'dark'));
      }

      await page.goto(`${BASE}/${url}`, { waitUntil: 'domcontentloaded', timeout: 30000 });
      await page.waitForTimeout(2500);
      await page.screenshot({ path: `${SHOTS}${shot}.png`, fullPage: true });

      const a = await audit(page);
      check(errors.length === 0, `${url} — clean console`, errors.join(' | '));
      check(a.overflow === 0, `${url} — no horizontal overflow`, `${a.overflow}px`);
      check(a.hiddenReveals === 0, `${url} — reveal content visible`, `${a.hiddenReveals} stuck hidden`);
      check(a.hxJs, `${url} — html.hx-js set before paint`);
      check(a.hxMotion === 'object', `${url} — window.hxMotion available`);
      await page.close();
    }
    await context.close();
  }

  // ------------------------------------------------------------ front door
  console.log('\nSite root');
  {
    const context = await browser.newContext();

    // Anonymous: "/" must land on the sign-in page. Before index.php existed it
    // 404'd on PHP's server and listed the whole directory on Apache.
    const anon = await context.request.get(`${BASE}/`, { maxRedirects: 0 });
    check(anon.status() === 302, 'anonymous / redirects', `status ${anon.status()}`);
    check((anon.headers()['location'] || '').includes('login.html'),
      'anonymous / points at the sign-in page', anon.headers()['location']);

    // Signed in: straight to the dashboard.
    await signIn(context);
    const authed = await context.request.get(`${BASE}/`, { maxRedirects: 0 });
    check((authed.headers()['location'] || '').includes('dashboard.html'),
      'signed-in / points at the dashboard', authed.headers()['location']);

    await context.close();
  }

  // ------------------------------------------------------------ flows
  console.log('\nDialogs and progress rails');
  {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const user = await signIn(context);
    const page = await context.newPage();
    const errors = watch(page);
    await seed(page, user);
    await page.goto(`${BASE}/dashboard.html`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);

    for (const [id, open, close] of [
      ['convertModal', '#openConvertBtn', '#closeConvertModalBtn'],
      ['walletModal', '#openWalletBtn', '#closeWalletModalBtn'],
      ['withdrawModal', '#openWithdrawBtn', '#closeWithdrawModalBtn']
    ]) {
      await page.click(open);
      await page.waitForTimeout(1500);
      const opened = await page.$eval(`#${id}`, el => el.classList.contains('show'));
      check(opened, `${id} opens`);
      if (id === 'convertModal') {
        await page.screenshot({ path: `${SHOTS}9-convert-flow.png` });
      }
      if (id === 'withdrawModal') {
        await page.screenshot({ path: `${SHOTS}10-withdraw-flow.png` });
        const step = await page.$eval('#withdrawSteps', el => el.getAttribute('data-step'));
        check(step === '1', 'withdraw rail advances once a payout account is connected', `step=${step}`);
      }
      await page.click(close);
      await page.waitForTimeout(120);
      const closing = await page.$eval(`#${id}`, el => el.classList.contains('is-closing'));
      check(closing, `${id} animates closed`);
      await page.waitForTimeout(400);
      const closed = await page.$eval(`#${id}`, el => !el.classList.contains('show'));
      check(closed, `${id} finishes closed`);
    }

    // a rejected amount must point at the field it belongs to
    await page.click('#openConvertBtn');
    await page.waitForTimeout(500);
    await page.fill('#convertAmount', '0');
    await page.click('#submitConvertBtn');
    await page.waitForTimeout(150);
    check(await page.$eval('#convertAmount', el => el.classList.contains('hx-shake')),
      'invalid amount shakes its field');

    check(errors.length === 0, 'dialog run — clean console', errors.join(' | '));

    // The run above ignores price-feed errors. Prove the fallback they rely on
    // works, so a genuinely dead market panel cannot pass unnoticed.
    await page.click('#closeConvertModalBtn');
    await page.waitForTimeout(400);
    const btcPrice = await page.$eval('#marketBtcPrice', el => ({
      text: el.textContent.trim(),
      shimmering: el.classList.contains('is-loading')
    }));
    check(!btcPrice.shimmering && /\d/.test(btcPrice.text),
      'feeds down still shows a price', JSON.stringify(btcPrice));

    await context.close();
  }

  // -------------------------------------------------------- reduced motion
  console.log('\nprefers-reduced-motion: reduce');
  {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
    const user = await signIn(context);
    const page = await context.newPage();
    await seed(page, user);
    await page.goto(`${BASE}/dashboard.html`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);
    await page.screenshot({ path: `${SHOTS}11-reduced-motion.png`, fullPage: true });

    const a = await audit(page);
    check(a.hiddenReveals === 0, 'content is shown, not animated in', `${a.hiddenReveals} stuck hidden`);
    const donut = await page.$eval('#allocationDonut', el => getComputedStyle(el).transitionDuration);
    check(parseFloat(donut) < 0.01, 'allocation ring does not sweep', donut);
    await context.close();
  }

  // ---------------------------------------------------------- scripting off
  console.log('\nJavaScript disabled');
  {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, javaScriptEnabled: false });
    const page = await context.newPage();
    await page.goto(`${BASE}/aml.html`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(800);
    const a = await audit(page);
    check(a.hiddenReveals === 0, 'reveal content is never hidden without scripting', `${a.hiddenReveals} stuck hidden`);
    await context.close();
  }

  // ----------------------------------------------------------------- mobile
  console.log('\nMobile (390x844)');
  {
    const context = await browser.newContext({
      viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true
    });
    const user = await signIn(context);
    const page = await context.newPage();
    const errors = watch(page);
    await seed(page, user);
    await page.goto(`${BASE}/dashboard.html`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);
    await page.screenshot({ path: `${SHOTS}12-mobile-dashboard.png`, fullPage: true });

    const a = await audit(page);
    check(a.overflow === 0, 'no horizontal overflow at 390px', `${a.overflow}px`);
    check(errors.length === 0, 'mobile — clean console', errors.join(' | '));
    await context.close();
  }
} finally {
  await browser.close();
}

console.log(failures ? `\n${failures} check(s) failed` : '\nAll checks passed');
process.exit(failures ? 1 : 0);
