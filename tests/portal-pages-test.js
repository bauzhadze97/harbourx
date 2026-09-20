/* ============================================================================
   HarbourX portfolio and transactions pages
   ----------------------------------------------------------------------------
   Both pages do their arithmetic in the browser over the stored record, so the
   things worth checking are the sums (does the allocation add up to the total
   it claims) and the controls (does filtering actually filter, does the export
   carry what is on screen rather than everything).
   ========================================================================== */

const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const fixture = require('./fixture.js');

const ROOT = path.join(__dirname, '..');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8899';
const CLIENT = { email: 'test.client@example.invalid', password: 'CiSmokeTest!2026' };

let fail = 0;
const check = (ok, label, detail = '') => {
  if (!ok) fail++;
  console.log(`${ok ? '  ok  ' : '  FAIL'} ${label}${detail ? ` — ${detail}` : ''}`);
};
const num = t => Number(String(t).replace(/[^0-9.-]/g, ''));

(async () => {
  fixture.seedUsers();

  /* Enough rows to page, and one whose detail starts with "=" — a spreadsheet
     would run that as a formula if the export handed it over unescaped. */
  const users = JSON.parse(fs.readFileSync(path.join(ROOT, 'data/users.json'), 'utf8'));
  const extra = [];
  for (let i = 0; i < 30; i++) {
    extra.push({
      date: `2026-0${(i % 9) + 1}-${String((i % 27) + 1).padStart(2, '0')}`,
      type: i % 2 ? 'Received' : 'Bank Withdrawal',
      amount: i % 2 ? `+0.0${i + 1} BTC` : `-A$${(i + 1) * 10}.00`,
      status: i % 3 ? 'Completed' : 'Pending',
      details: i === 0 ? '=cmd|calc' : `Synthetic row ${i}`,
      detailsUrl: ''
    });
  }
  users[0].transactions = users[0].transactions.concat(extra);
  fs.writeFileSync(path.join(ROOT, 'data/users.json'), JSON.stringify(users, null, 4));

  const browser = await chromium.launch(
    process.env.CHROMIUM_EXECUTABLE ? { executablePath: process.env.CHROMIUM_EXECUTABLE } : {}
  );

  try {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 }, acceptDownloads: true });
    const user = (await (await ctx.request.post(`${BASE}/login.php`, { data: CLIENT })).json()).user;
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));

    await page.goto(`${BASE}/tests/blank.html`);
    await page.evaluate(u => localStorage.setItem('user', JSON.stringify(u)), user);

    // ------------------------------------------------------------ portfolio
    await page.goto(`${BASE}/portfolio.html`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);

    const pf = await page.evaluate(() => ({
      total: document.getElementById('pfTotal').textContent,
      btc: document.getElementById('pfBtc').textContent,
      cash: document.getElementById('pfCash').textContent,
      rate: document.getElementById('pfRate').textContent,
      holdings: [...document.querySelectorAll('.holding')].length,
      shares: [...document.querySelectorAll('.holding-value em')].map(e => parseFloat(e.textContent)),
      widths: [...document.querySelectorAll('.alloc-bar i')].map(e => parseFloat(e.style.width)),
      history: [...document.querySelectorAll('#pfHistory li')].length
    }));

    check(pf.holdings === 2, 'the portfolio breaks the account into its holdings', `${pf.holdings}`);
    check(Math.abs(pf.shares.reduce((a, b) => a + b, 0) - 100) < 0.2,
      'whose shares add up to the whole', pf.shares.join(' + '));
    check(Math.abs(pf.widths.reduce((a, b) => a + b, 0) - 100) < 0.2,
      'and the allocation bar is drawn to the same split', pf.widths.join(' + '));

    // total = bitcoin priced at the shown rate + the cash balance
    const expected = num(pf.btc) * num(pf.rate) + num(pf.cash);
    check(Math.abs(num(pf.total) - expected) < 1,
      'the total is the holdings priced and added', `${pf.total} vs ${expected.toFixed(2)}`);
    check(pf.history === 5, 'and the account history is summarised beside it', `${pf.history} lines`);

    // --------------------------------------------------------- transactions
    await page.goto(`${BASE}/transactions.html`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2000);

    const total = users[0].transactions.length;
    const firstPage = await page.$$eval('.tx-row', r => r.length);
    check(firstPage === 25, 'the history pages at 25 rows', `${firstPage}`);
    check((await page.$eval('#txRange', e => e.textContent)).includes(`of ${total}`),
      'and says how many there are in total', await page.$eval('#txRange', e => e.textContent));

    await page.click('#txNext');
    await page.waitForTimeout(400);
    check(await page.$$eval('.tx-row', r => r.length) === total - 25, 'the next page holds the rest');
    check(await page.$eval('#txNext', b => b.disabled), 'and there is no page after it');
    await page.click('#txPrev');
    await page.waitForTimeout(400);

    // filtering
    await page.selectOption('#txType', 'Received');
    await page.waitForTimeout(400);
    const received = await page.$$eval('.tx-row .tx-type', r => r.map(e => e.textContent));
    check(received.length > 0 && received.every(t => t === 'Received'),
      'filtering by type leaves only that type', `${received.length} rows`);

    await page.selectOption('#txType', '');
    await page.fill('#txSearch', 'synthetic row 7');
    await page.waitForTimeout(500);
    check(await page.$$eval('.tx-row', r => r.length) === 1, 'search narrows to the one row');

    await page.click('#txClear');
    await page.waitForTimeout(400);
    check(await page.$$eval('.tx-row', r => r.length) === 25, 'clearing puts them all back');

    // a date range, with ISO strings compared as dates
    await page.fill('#txFrom', '2026-02-01');
    await page.fill('#txTo', '2026-02-28');
    await page.waitForTimeout(500);
    const inRange = await page.$$eval('.tx-row .tx-date', r => r.map(e => e.textContent));
    check(inRange.length > 0 && inRange.every(d => d.includes('Feb')),
      'a date range keeps only that range', `${inRange.length} rows`);

    // the figures describe what is filtered, not the whole account
    check(num(await page.$eval('#figCount', e => e.textContent)) === inRange.length,
      'and the figures above describe the filtered set');

    // --------------------------------------------------------------- export
    const download = page.waitForEvent('download');
    await page.click('#txExport');
    const file = await download;
    const csvPath = path.join(ROOT, 'tests/.tmp-export.csv');
    await file.saveAs(csvPath);
    const csv = fs.readFileSync(csvPath, 'utf8');
    fs.unlinkSync(csvPath);

    const lines = csv.trim().split(/\r?\n/);
    check(lines.length === inRange.length + 1,
      'the export carries the filtered rows, not the whole history', `${lines.length - 1} rows + header`);
    check(lines[0].startsWith('"Date","Type"'), 'with a header row');

    await page.click('#txClear');
    await page.waitForTimeout(400);
    const download2 = page.waitForEvent('download');
    await page.click('#txExport');
    const all = await download2;
    await all.saveAs(csvPath);
    const fullCsv = fs.readFileSync(csvPath, 'utf8');
    fs.unlinkSync(csvPath);

    check(!/,"=cmd\|calc"/.test(fullCsv),
      'a detail that looks like a formula is not handed to the spreadsheet as one');
    check(/'=cmd\|calc/.test(fullCsv), 'it is quoted out instead');

    check(errors.length === 0, 'neither page reports an error', errors.slice(0, 2).join(' | '));
    await ctx.close();
  } finally {
    await browser.close();
    fixture.seedUsers();
  }

  console.log(fail ? `\n${fail} check(s) failed` : '\nAll portfolio and transaction checks passed');
  process.exit(fail ? 1 : 0);
})();
