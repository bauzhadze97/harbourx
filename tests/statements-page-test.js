/* ============================================================================
   Statements page and PDF download
   ----------------------------------------------------------------------------
   Drives statements.html the way a client would: the period list, switching
   between periods, the derived figures, and downloading the PDF — checking the
   file that actually arrives is a PDF, not just that the link exists.

   Needs the app already running; BASE_URL overrides the default.
   ========================================================================== */

const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const fixture = require('./fixture');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8899';
const ROOT = path.resolve(__dirname, '..');
const CLIENT = { email: 'demo.client@example.invalid', password: 'CiSmokeTest!2026' };

let fail = 0;
const check = (ok, label, detail = '') => {
  if (!ok) fail++;
  console.log(`  ${ok ? 'ok  ' : 'FAIL'} ${label}${detail ? ' — ' + detail : ''}`);
};

(async () => {
  fixture.seedUsers();

  const browser = await chromium.launch(
    process.env.CHROMIUM_EXECUTABLE ? { executablePath: process.env.CHROMIUM_EXECUTABLE } : {}
  );
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 1100 }, acceptDownloads: true });

  // --- signed out ------------------------------------------------------------
  const anon = await browser.newContext();
  const anonRes = await anon.request.get(`${BASE}/statements.php?action=list`);
  check(anonRes.status() === 401, 'a signed-out visitor is refused', 'status ' + anonRes.status());

  const user = (await (await ctx.request.post(`${BASE}/login.php`, { data: CLIENT })).json()).user;

  // --- endpoint --------------------------------------------------------------
  const list = await (await ctx.request.get(`${BASE}/statements.php?action=list`)).json();
  check(list.success === true && list.statements.length === 2, 'two periods derived', String(list.statements?.length));
  check(list.statements[0].period === '2026-03', 'newest first', list.statements[0].period);

  const missing = await ctx.request.get(`${BASE}/statements.php?action=view&period=1999-01`);
  check(missing.status() === 404, 'an unknown period is 404', 'status ' + missing.status());

  // --- page ------------------------------------------------------------------
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));

  await page.goto(`${BASE}/tests/blank.html`);
  await page.evaluate(u => {
    localStorage.setItem('user', JSON.stringify(u));
    localStorage.setItem('hx-theme', 'dark');
  }, user);
  await page.goto(`${BASE}/statements.html`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2200);

  check(await page.$$eval('.statement-period', els => els.length) === 2, 'both periods listed');
  check(await page.$eval('#statementDetail', el => !el.hidden), 'the newest opens by default');
  check((await page.$eval('#detailPeriod', el => el.textContent)) === 'March 2026', 'showing March');

  await page.click('.statement-period:nth-child(2)');
  await page.waitForTimeout(900);
  check((await page.$eval('#detailPeriod', el => el.textContent)) === 'February 2026', 'switching period works');
  check(await page.$$eval('#detailRows .statement-row', els => els.length) === 2, 'February has two rows');

  const figures = await page.$$eval('.statement-figures div strong', els => els.map(e => e.textContent));
  check(figures[1] === '1.25 BTC', 'Bitcoin in is derived correctly', figures[1]);
  check(figures[2] === '0.5 BTC', 'Bitcoin out is derived correctly', figures[2]);
  check(figures[3] === 'A$48,250.50', 'cash in is derived correctly, in the account currency', figures[3]);

  // --- the download ----------------------------------------------------------
  const [download] = await Promise.all([
    page.waitForEvent('download'),
    page.click('#downloadPdf')
  ]);
  check(download.suggestedFilename() === 'HarbourX-statement-2026-02.pdf',
    'downloads with a sensible filename', download.suggestedFilename());

  const file = await download.path();
  const bytes = fs.readFileSync(file);
  check(bytes.slice(0, 8).toString() === '%PDF-1.4', 'the file really is a PDF', bytes.slice(0, 8).toString());
  check(bytes.length > 1500, 'and has content in it', bytes.length + ' bytes');
  check(bytes.includes('%%EOF'), 'it is terminated properly');
  // The title is a UTF-16BE text string, not WinAnsi — this is the encoding
  // that made viewers render the em dash as "Š".
  check(bytes.includes('/Title <FEFF'), 'the title is a UTF-16BE text string');

  check(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth) === 0,
    'no horizontal overflow');
  check(errors.length === 0, 'no page errors', errors.join(' | '));

  console.log(fail ? `\n${fail} check(s) failed` : '\nAll statement page checks passed');
  await browser.close();
  process.exit(fail ? 1 : 0);
})();
