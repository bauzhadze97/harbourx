/* ============================================================================
   The MAX / "use everything" controls
   ----------------------------------------------------------------------------
   Every amount field in the portal has a shortcut that fills it with the whole
   balance. They are the controls people reach for first, and a shortcut that
   silently leaves the field empty is worse than no shortcut at all — so each
   one is pressed here and the field is read back.

   The balances are deliberately awkward: a holding small enough that JavaScript
   would print it in exponential notation, and one with more decimal places than
   the input's step allows. Both produce a value the number input refuses, and
   the field goes blank without saying why.
   ========================================================================== */

const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const fixture = require('./fixture.js');

const ROOT = path.join(__dirname, '..');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8899';
const CLIENT = { email: 'test.client@example.invalid', password: 'CiSmokeTest!2026' };
const ADDRESS = 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4';

let fail = 0;
const check = (ok, label, detail = '') => {
  if (!ok) fail++;
  console.log(`${ok ? '  ok  ' : '  FAIL'} ${label}${detail ? ` — ${detail}` : ''}`);
};

/* The balances each scenario runs against. */
const SCENARIOS = [
  { name: 'round balances', btc: 1.25, mainBalance: 48250.5 },
  { name: 'dust-sized holding', btc: 0.0000012, mainBalance: 7.5 },
  { name: 'long-tailed holding', btc: 0.30000000000000004, mainBalance: 1234.567 }
];

async function run(browser, scenario) {
  console.log(`\n  ${scenario.name} (btc=${scenario.btc}, balance=${scenario.mainBalance})`);

  const users = JSON.parse(fs.readFileSync(path.join(ROOT, 'tests/fixtures/users.json'), 'utf8'));
  users[0].btc = scenario.btc;
  users[0].mainBalance = scenario.mainBalance;
  fs.writeFileSync(path.join(ROOT, 'data/users.json'), JSON.stringify(users, null, 4));

  const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const user = (await (await ctx.request.post(`${BASE}/login.php`, { data: CLIENT })).json()).user;
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));

  await page.goto(`${BASE}/tests/blank.html`);
  await page.evaluate(u => localStorage.setItem('user', JSON.stringify(u)), user);
  await page.goto(`${BASE}/dashboard.html`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2200);

  /* A number input reports "" for anything it considers malformed, so an empty
     value after a press is the failure this test exists to catch. */
  const read = async id => page.evaluate(sel => {
    const el = document.getElementById(sel);
    return { value: el.value, valid: el.checkValidity(), number: Number(el.value) };
  }, id);

  const near = (a, b, tol = 1e-8) => Math.abs(a - b) <= tol;
  const tag = scenario.name;

  // ------------------------------------------------- bank dialog, cash source
  await page.click('#openWithdrawBtn');
  await page.waitForTimeout(400);
  await page.click('#withdrawDestinationSeg [data-destination="bank"]').catch(() => {});
  await page.click('[data-source="balance"]').catch(() => {});
  await page.waitForTimeout(250);
  await page.click('#withdrawMaxBtn');
  let r = await read('withdrawAmount');
  check(r.value !== '' && r.valid, `${tag}: bank MAX fills the cash amount`, `value="${r.value}"`);
  check(near(r.number, Math.floor(scenario.mainBalance * 100) / 100, 1e-9),
    `${tag}: bank MAX equals the cash balance, floored to the cent`, `${r.number}`);
  check(r.number <= scenario.mainBalance + 1e-9,
    `${tag}: bank MAX never asks for more than the balance`, `${r.number} vs ${scenario.mainBalance}`);

  // -------------------------------------------------- bank dialog, BTC source
  await page.click('[data-source="btc"]').catch(() => {});
  await page.waitForTimeout(250);
  await page.click('#withdrawMaxBtn');
  r = await read('withdrawAmount');
  check(r.value !== '' && r.valid, `${tag}: swap MAX fills the BTC amount`, `value="${r.value}"`);
  check(near(r.number, scenario.btc, 1e-8), `${tag}: swap MAX equals the BTC balance`, `${r.number}`);
  await page.keyboard.press('Escape');
  await page.waitForTimeout(300);

  // ------------------------------------------------------------ convert dialog
  await page.click('#openConvertBtn');
  await page.waitForTimeout(500);
  await page.click('#convertBalanceMaxBtn');
  r = await read('convertAmount');
  check(r.value !== '' && r.valid, `${tag}: convert panel MAX fills the amount`, `value="${r.value}"`);
  check(near(r.number, scenario.btc, 1e-8), `${tag}: convert panel MAX equals the BTC balance`, `${r.number}`);

  await page.fill('#convertAmount', '');
  await page.click('#convertMaxBtn');
  r = await read('convertAmount');
  check(r.value !== '' && r.valid, `${tag}: "use full BTC balance" fills the amount`, `value="${r.value}"`);
  await page.keyboard.press('Escape');
  await page.waitForTimeout(300);

  // -------------------------------------------------------- Bitcoin send dialog
  /* Pressing MAX before typing an address is the ordinary order of events:
     decide how much, then say where. The dialog has to answer either way. */
  const sendable = Math.floor((scenario.btc - 0.00002) * 1e8) / 1e8;

  await page.click('#openBtcWithdrawBtn');
  await page.waitForTimeout(500);
  await page.click('#btcWithdrawMaxBtn');
  await page.waitForTimeout(900);
  r = await read('btcWithdrawAmount');
  const note = async () => page.evaluate(() => {
    const el = document.getElementById('btcWithdrawMessage');
    return el.style.display === 'none' ? '' : el.textContent.trim();
  });

  if (sendable > 0) {
    check(r.value !== '' && r.valid, `${tag}: send MAX works before an address is entered`, `value="${r.value}"`);
    check(near(r.number, sendable, 1e-8),
      `${tag}: send MAX is the balance less the network fee`, `${r.number} vs ${sendable}`);
    check(/0\.00002/.test(await page.textContent('#btcWithdrawFeeSummary')),
      `${tag}: send MAX shows the network fee in the summary`);
  } else {
    /* Nothing is sendable, so the honest answer is a sentence, not a blank
       field and no explanation. */
    check(/network fee/i.test(await note()),
      `${tag}: send MAX says why a sub-fee balance cannot be sent`, `note="${await note()}"`);
  }

  await page.fill('#btcWithdrawAddress', ADDRESS);
  await page.waitForTimeout(900);
  await page.click('#btcWithdrawSendAllBtn');
  await page.waitForTimeout(1100);
  r = await read('btcWithdrawAmount');
  if (sendable > 0) {
    check(r.value !== '' && r.valid, `${tag}: "send everything" fills the amount`, `value="${r.value}"`);
    check(near(r.number, sendable, 1e-8),
      `${tag}: "send everything" is the balance less the network fee`, `${r.number} vs ${sendable}`);
  } else {
    check(/network fee/i.test(await note()),
      `${tag}: "send everything" says why nothing can be sent`, `note="${await note()}"`);
  }

  check(errors.length === 0, `${tag}: no page errors`, errors.join(' | '));
  await ctx.close();
}

(async () => {
  fixture.seedUsers();
  const browser = await chromium.launch(
    process.env.CHROMIUM_EXECUTABLE ? { executablePath: process.env.CHROMIUM_EXECUTABLE } : {}
  );
  try {
    for (const scenario of SCENARIOS) await run(browser, scenario);
  } finally {
    await browser.close();
  }
  console.log(fail ? `\n  ${fail} check(s) failed\n` : '\n  all MAX controls fill their field\n');
  process.exit(fail ? 1 : 0);
})();
