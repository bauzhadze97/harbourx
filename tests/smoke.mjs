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

/* Third-party endpoints the app is designed to survive without: the price feeds,
   Google Fonts and the Tidio chat widget. How they fail depends on where the
   test runs — unreachable in a sandbox, CORS-rejected from a GitHub runner,
   rate-limited elsewhere — so the test judges the app's own code and ignores
   errors naming these hosts. That exclusion is only safe because `feeds down
   still shows a price` below asserts the price fallback actually works, and
   because the chat widget is loaded async and owns no page content: a page that
   renders correctly here renders correctly whether or not Tidio answers.
   Matching is by substring, so the bare domains cover every subdomain the
   widget reaches for. */
const EXTERNAL_HOSTS = [
  'api.coingecko.com',
  'api.coinbase.com',
  'api.binance.com',
  'fonts.googleapis.com',
  'fonts.gstatic.com',
  'tidio.co',
  'tidiochat.com'
];

const isExternal = text =>
  EXTERNAL_HOSTS.some(host => text.includes(host)) || /net::ERR/.test(text);

/* Chromium logs a subresource that would not load as "Failed to load resource:
   the server responded with a status of NNN", and that line names no URL. From
   the console alone a third party's outage — a blocked chat widget, a
   rate-limited price feed — is indistinguishable from the app's own broken
   asset, so judging it here means either missing real failures or inventing
   them whenever a network is unfriendly. The response handler below is the one
   that knows the URL, and it flags every same-origin failure; this line is
   redundant for those and misleading for the rest. */
const isResourceFailure = text => /Failed to load resource/.test(text);

/** Collect anything the page reports as broken in the app's own code. */
function watch(page) {
  const errors = [];
  page.on('pageerror', e => errors.push(`pageerror: ${e.message}`));
  page.on('console', m => {
    if (m.type() === 'error' && !isExternal(m.text()) && !isResourceFailure(m.text())) {
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

/* Walk the page from top to bottom and back, a screen at a time. The reveal
   observer only fires for what has been in the viewport, so on a page taller
   than a couple of screens — the public home page especially — auditing
   without this would only ever judge the first screenful. */
async function scrollThrough(page) {
  await page.evaluate(async () => {
    const step = Math.round(window.innerHeight * 0.8);
    const end = document.documentElement.scrollHeight;
    const wait = () => new Promise(r => setTimeout(r, 120));
    // behavior:'instant' matters: the public home page sets
    // `scroll-behavior: smooth` for its in-page links, and a smooth scroll
    // retargeted every 120ms never actually arrives anywhere.
    for (let y = 0; y < end; y += step) {
      window.scrollTo({ top: y, behavior: 'instant' });
      await wait();
    }
    window.scrollTo({ top: 0, behavior: 'instant' });
    await wait();
  });

  /* A reveal takes --hx-dur-slow plus its stagger delay to finish. Auditing
     the moment the last scroll step lands catches whatever was released on it
     mid-transition and reads that as hidden. Wait for them to settle — and
     only wait: if one really is stuck, the audit below is what says so. */
  await page.waitForFunction(
    () => [...document.querySelectorAll('[data-reveal]')]
      .every(el => el.offsetParent === null || getComputedStyle(el).opacity === '1'),
    null,
    { timeout: 4000 }
  ).catch(() => {});
}

const audit = page => page.evaluate(() => ({
  overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
  hiddenReveals: [...document.querySelectorAll('[data-reveal]')]
    .filter(el => el.offsetParent !== null && getComputedStyle(el).opacity !== '1').length,
  hxJs: document.documentElement.classList.contains('hx-js'),
  hxMotion: typeof window.hxMotion,

  /* Our own stylesheets and scripts carry a ?v= stamp, so a browser holding
     yesterday's copy of one is asking for an address it has never fetched and
     gets the new file. bump-version.sh sets them; this catches the asset that
     gets added later without one, which is invisible until someone is told to
     open a private window to see their own deploy. Third-party URLs are not
     ours to stamp and are skipped. */
  unversioned: [...document.querySelectorAll('link[rel="stylesheet"], script[src]')]
    .map(el => el.getAttribute('href') || el.getAttribute('src') || '')
    .filter(url => url && !/^(https?:)?\/\//.test(url) && !url.includes('?v='))
}));

const PAGES = [
  { url: 'home.html', shot: '0-home', auth: false },
  { url: 'dashboard.html', shot: '1-dashboard', auth: true },
  { url: 'login.html', shot: '2-sign-in', auth: false },
  { url: 'aml.html', shot: '3-identity', auth: true },
  { url: 'change_password.php?email=test.client%40example.invalid', shot: '4-security', auth: true },
  { url: 'register.php', shot: '5-create-account', auth: false },
  { url: 'admin.php', shot: '6-admin-sign-in', auth: false },
  { url: 'support.html', shot: '7-support', auth: true },
  { url: 'statements.html', shot: '8-statements', auth: true },
  { url: 'verification.html', shot: '16-verification', auth: true },
  { url: 'portfolio.html', shot: '17-portfolio', auth: true },
  { url: 'transactions.html', shot: '18-transactions', auth: true },
  { url: 'authenticator.html', shot: '19-authenticator', auth: true },
  { url: 'terms.html', shot: '13-terms', auth: false },
  { url: 'privacy.html', shot: '14-privacy', auth: false },
  { url: 'complaints.html', shot: '15-complaints', auth: false }
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
      await scrollThrough(page);
      await page.screenshot({ path: `${SHOTS}${shot}.png`, fullPage: true });

      const a = await audit(page);
      check(errors.length === 0, `${url} — clean console`, errors.join(' | '));
      check(a.overflow === 0, `${url} — no horizontal overflow`, `${a.overflow}px`);
      check(a.hiddenReveals === 0, `${url} — reveal content visible`, `${a.hiddenReveals} stuck hidden`);
      check(a.hxJs, `${url} — html.hx-js set before paint`);
      check(a.hxMotion === 'object', `${url} — window.hxMotion available`);
      check(a.unversioned.length === 0, `${url} — every local asset is version-stamped`,
        a.unversioned.join(', '));
      await page.close();
    }
    await context.close();
  }

  // ------------------------------------------------------------ front door
  console.log('\nSite root');
  {
    const context = await browser.newContext();

    // Anonymous: "/" is the public home page, served in place rather than
    // redirected to, so the marketing site has one URL and not two. Before
    // index.php existed the root 404'd on PHP's server and listed the whole
    // directory on Apache.
    const anon = await context.request.get(`${BASE}/`, { maxRedirects: 0 });
    check(anon.status() === 200, 'anonymous / serves a page', `status ${anon.status()}`);
    const anonBody = await anon.text();
    check(anonBody.includes('id="siteHead"'),
      'anonymous / is the public home page');
    check(anonBody.includes('href="login.html"'),
      'the home page offers a way in to sign-in');

    // Signed in: straight to the dashboard.
    await signIn(context);
    const authed = await context.request.get(`${BASE}/`, { maxRedirects: 0 });
    check((authed.headers()['location'] || '').includes('dashboard.html'),
      'signed-in / points at the dashboard', authed.headers()['location']);

    await context.close();
  }

  // -------------------------------------------------- the public site's SEO
  console.log('\nSearch and sharing');
  {
    const context = await browser.newContext();

    /* robots.txt and the sitemap have to be served, not just committed — a
       404 here is invisible until a crawler finds it. */
    for (const [file, must] of [
      ['robots.txt', 'Sitemap: https://harbourx.org/sitemap.xml'],
      ['sitemap.xml', '<loc>https://harbourx.org/</loc>']
    ]) {
      const r = await context.request.get(`${BASE}/${file}`);
      const body = await r.text();
      check(r.status() === 200 && body.includes(must), `${file} is served and points the right way`,
        `status ${r.status()}`);
    }

    // The share image is referenced absolutely, so check the file it names.
    const img = await context.request.get(`${BASE}/assets/og-image.jpg`);
    check(img.status() === 200 && Number(img.headers()['content-length'] || 1) > 1000,
      'the social share image exists', `status ${img.status()}`);

    /* The FAQ answers a search engine is shown come from a JSON-LD block that
       repeats the page's own FAQ section. Two copies of the same words drift,
       and the structured-data copy drifts silently — nobody reads it. Assert
       they match, so editing one without the other fails here instead. */
    const page = await context.newPage();
    await page.goto(`${BASE}/home.html`, { waitUntil: 'domcontentloaded' });
    const seo = await page.evaluate(() => {
      const blocks = [...document.querySelectorAll('script[type="application/ld+json"]')]
        .map(el => JSON.parse(el.textContent));
      const faqBlock = blocks.find(b => b['@type'] === 'FAQPage');
      return {
        types: blocks.map(b => b['@type']),
        structured: (faqBlock ? faqBlock.mainEntity : []).map(q => q.name),
        onPage: [...document.querySelectorAll('.faq summary')].map(el => el.textContent.trim()),
        title: document.title,
        canonical: document.querySelector('link[rel=canonical]')?.href || '',
        description: document.querySelector('meta[name=description]')?.content || ''
      };
    });

    check(seo.types.includes('FinancialService') && seo.types.includes('FAQPage'),
      'the home page carries structured data', seo.types.join(', '));
    check(seo.onPage.length > 0 && JSON.stringify(seo.structured) === JSON.stringify(seo.onPage),
      'and its FAQ block matches the questions on the page',
      `${seo.structured.length} structured vs ${seo.onPage.length} on page`);
    check(seo.title.length > 10 && seo.title.length <= 70, 'the title is a usable length', `${seo.title.length} chars`);
    check(seo.description.length > 50 && seo.description.length <= 165,
      'and the description is too', `${seo.description.length} chars`);
    check(seo.canonical.endsWith('harbourx.org/'), 'the canonical URL is the site root', seo.canonical);

    await page.close();

    // Nothing behind the sign-in should be inviting a crawler in.
    for (const url of ['dashboard.html', 'statements.html', 'aml.html', 'support.html']) {
      const r = await context.request.get(`${BASE}/${url}`);
      check((await r.text()).includes('name="robots" content="noindex'),
        `${url} asks not to be indexed`);
    }

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
    const band = await page.$$eval('.hx-coin-tile', tiles => tiles.map(tile => ({
      symbol: tile.querySelector('.c-name small')?.textContent.trim(),
      price: tile.querySelector('.c-price')?.textContent.trim(),
      change: tile.querySelector('.hx-market-change')?.textContent.trim()
    })));
    const btcTile = band.find(tile => tile.symbol === 'BTC');
    check(!!btcTile && /\d/.test(btcTile.price || ''),
      'feeds down still shows a price', JSON.stringify(btcTile));
    check(band.length >= 8, 'every supported coin has a tile', `${band.length} tiles`);
    /* With no feed there is no 24-hour move to report, and the tile has to say
       that rather than print a number nobody measured. */
    check(band.every(tile => tile.change && (/%/.test(tile.change) || /not measured/i.test(tile.change))),
      'a coin with no measured move says so', JSON.stringify(band[0]));

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
