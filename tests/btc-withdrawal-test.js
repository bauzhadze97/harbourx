/* ============================================================================
   Bitcoin withdrawal flow
   ----------------------------------------------------------------------------
   Drives btc_withdrawals.php over HTTP: the AML gate, checksum validation of
   the destination, dust and balance limits, the send-max arithmetic, and that
   a submitted request actually debits the balance and files a transaction.

   Needs the app already running; BASE_URL overrides the default.
   ========================================================================== */

const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const fixture = require('./fixture');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8899';
const ROOT = path.resolve(__dirname, '..');
const CLIENT = { email: 'test.client@example.invalid', password: 'CiSmokeTest!2026' };
const GOOD = 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4';
const TYPO = 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t5';   // last char changed

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
  const ctx = await browser.newContext();
  const post = (data) => ctx.request.post(`${BASE}/btc_withdrawals.php`, { data });

  // --- signed out -----------------------------------------------------------
  const anon = await browser.newContext();
  const anonRes = await anon.request.post(`${BASE}/btc_withdrawals.php`, { data: { address: GOOD, amount: 0.01 } });
  check(anonRes.status() === 401, 'a signed-out visitor is refused', 'status ' + anonRes.status());

  await ctx.request.post(`${BASE}/login.php`, { data: CLIENT });

  // --- address validation ---------------------------------------------------
  let r = await post({ address: TYPO, amount: 0.01 });
  let body = await r.json();
  check(r.status() === 422 && body.field === 'address', 'a one-character typo is refused', body.message?.slice(0, 40));

  r = await post({ address: '', amount: 0.01 });
  check((await r.json()).field === 'address', 'an empty address is refused');

  r = await post({ address: 'definitely-not-an-address', amount: 0.01 });
  check((await r.json()).field === 'address', 'nonsense is refused');

  // The fixture's own deposit address — sending there is almost certainly a slip.
  const users = JSON.parse(fs.readFileSync(path.join(ROOT, 'data/users.json'), 'utf8'));
  r = await post({ address: users[0].btcWalletAddress, amount: 0.01 });
  body = await r.json();
  check(r.status() === 422 && /own deposit address/.test(body.message || ''), 'sending to its own deposit address is refused');

  // --- amount limits --------------------------------------------------------
  r = await post({ address: GOOD, amount: 0 });
  check((await r.json()).field === 'amount', 'zero is refused');

  r = await post({ address: GOOD, amount: 0.000001 });
  body = await r.json();
  check(r.status() === 422 && /dust/.test(body.message || ''), 'below the dust limit is refused', body.message?.slice(0, 48));

  r = await post({ address: GOOD, amount: 999 });
  body = await r.json();
  check(r.status() === 422 && /Not enough/.test(body.message || ''), 'more than the balance is refused');

  // The fee comes out on top, so balance-exactly must fail.
  r = await post({ address: GOOD, amount: 1.25 });
  check(r.status() === 422, 'the whole balance fails once the fee is added');

  // --- quote ----------------------------------------------------------------
  r = await post({ quote: true, address: GOOD, amount: 0.5 });
  const quote = await r.json();
  check(quote.success === true, 'quote returns');
  check(quote.amount === 0.5 && quote.networkFee === 0.00002, 'quote states amount and fee', JSON.stringify({ a: quote.amount, f: quote.networkFee }));
  check(Math.abs(quote.total - 0.50002) < 1e-9, 'total is amount plus fee', String(quote.total));
  check(Math.abs(quote.remaining - 0.74998) < 1e-9, 'remaining is balance minus total', String(quote.remaining));
  check(quote.addressKind === 'p2wpkh', 'quote reports the address kind', quote.addressKind);

  // send-max: fee comes out of the amount, not on top
  r = await post({ quote: true, address: GOOD, sendMax: true });
  const maxQuote = await r.json();
  check(Math.abs(maxQuote.amount - (1.25 - 0.00002)) < 1e-9, 'send-max takes the fee out of the amount', String(maxQuote.amount));
  check(Math.abs(maxQuote.remaining) < 1e-9, 'send-max leaves nothing behind', String(maxQuote.remaining));

  // --- submit ---------------------------------------------------------------
  r = await post({ address: GOOD, amount: 0.5 });
  const done = await r.json();
  check(done.success === true, 'the request is accepted', done.message);
  check(Math.abs(done.btc - 0.74998) < 1e-9, 'the balance is debited by amount plus fee', String(done.btc));

  const tx = done.transactions[0];
  check(tx.type === 'Bitcoin Withdrawal', 'a transaction is filed', tx.type);
  check(tx.status === 'In review', 'it is queued for review', tx.status);
  check(tx.btcWithdrawalAddress === GOOD, 'it records the destination');
  check(tx.amount === '-0.50000000 BTC', 'it records the amount', tx.amount);

  // and the balance really moved on disk
  const after = JSON.parse(fs.readFileSync(path.join(ROOT, 'data/users.json'), 'utf8'));
  check(Math.abs(after[0].btc - 0.74998) < 1e-9, 'the stored balance matches', String(after[0].btc));

  // --- per-client release fee -----------------------------------------------
  // The admin can require a fee before a withdrawal is released, and only the
  // admin can release it: the client used to tick "I have paid" and go through
  // on their own word, which asked the platform to believe the one party with a
  // reason to say it whether or not it was true. Now nothing moves until an
  // administrator marks the money received.
  fixture.seedUsers();
  const withFee = JSON.parse(fs.readFileSync(path.join(ROOT, 'data/users.json'), 'utf8'));
  withFee[0].withdrawalFeeRequired = true;
  withFee[0].withdrawalFeeAmount = 25;
  withFee[0].withdrawalFeePercent = 1;
  withFee[0].withdrawalFeeNote = 'Pay the release fee to the account on your invoice.';
  fs.writeFileSync(path.join(ROOT, 'data/users.json'), JSON.stringify(withFee, null, 4));

  const feeCtx = await browser.newContext();
  const feeUser = (await (await feeCtx.request.post(`${BASE}/login.php`, { data: CLIENT })).json()).user;
  const RATE = 145000;   // the fallback the page uses when the price feeds are unreachable

  r = await feeCtx.request.post(`${BASE}/btc_withdrawals.php`,
    { data: { address: GOOD, amount: 0.1, btcRate: RATE } });
  body = await r.json();
  check(r.status() === 422 && body.feeRequired === true && body.feeAwaitingPayment === true,
    'the endpoint holds the send until the fee is marked received');
  check(Math.abs(body.fee - (25 + 0.01 * 0.1 * RATE)) < 0.005, 'and quotes fixed plus percentage', String(body.fee));

  // The old release was a flag in the request body. Prove it is dead: a client
  // who sends it anyway gets the same refusal.
  r = await feeCtx.request.post(`${BASE}/btc_withdrawals.php`,
    { data: { address: GOOD, amount: 0.1, btcRate: RATE, feeAcknowledged: true, withdrawalFeePaid: true } });
  check(r.status() === 422 && (await r.json()).feeRequired === true,
    'and a client who claims to have paid is refused all the same');
  check(JSON.parse(fs.readFileSync(path.join(ROOT, 'data/users.json'), 'utf8'))[0].btc === 1.25,
    'no Bitcoin moved while the fee was outstanding');

  // --- the dialog shows it --------------------------------------------------
  const page = await feeCtx.newPage();
  await page.goto(`${BASE}/tests/blank.html`);
  await page.evaluate((u) => {
    localStorage.setItem('user', JSON.stringify(u));
    localStorage.setItem('hx-theme', 'dark');
  }, feeUser);
  await page.goto(`${BASE}/dashboard.html`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2200);

  await page.click('[data-open-btc-withdraw]');
  await page.waitForTimeout(400);
  check(await page.$eval('#btcWithdrawReleaseFeeRow', el => el.style.display === 'none'),
    'the fee row stays hidden until there is an amount');

  await page.fill('#btcWithdrawAmount', '0.1');
  await page.fill('#btcWithdrawAddress', GOOD);
  await page.waitForTimeout(1400);   // the quote is debounced

  const feeRowShown = await page.$eval('#btcWithdrawReleaseFeeRow', el => el.style.display !== 'none');
  check(feeRowShown, 'the release fee is shown in the summary');
  check(await page.$eval('#btcWithdrawFeeNote', el => el.style.display !== 'none'),
    'and the note explaining it is paid separately');

  const shownFee = await page.$eval('#btcWithdrawReleaseFee', el => el.textContent);
  const shownValue = await page.$eval('#btcWithdrawFiat', el => el.textContent);
  const num = (t) => Number(String(t).replace(/[^0-9.]/g, ''));
  check(Math.abs(num(shownFee) - (25 + 0.01 * num(shownValue))) < 0.02,
    'the figure is fixed plus a percentage of the value shown beside it',
    `${shownFee} on ${shownValue}`);

  // The Bitcoin total must not move: the fee is paid separately.
  check(await page.$eval('#btcWithdrawTotalSummary', el => el.textContent.trim()) === '0.10002000 BTC',
    'the BTC total debited is unchanged by it');

  // --- the notice is a full stop, not a step --------------------------------
  await page.click('#submitBtcWithdrawBtn');
  await page.waitForTimeout(700);
  check(await page.$eval('#feeModal', el => el.getAttribute('aria-hidden') === 'false'),
    'reviewing opens the fee notice');
  check((await page.$eval('#feeModalNote', el => el.textContent)).includes('invoice'),
    "the notice shows the administrator's own note");
  check((await page.$eval('#feeModalLead', el => el.textContent)).includes('Bitcoin send'),
    'and says Bitcoin send, not bank withdrawal');
  check(await page.$('#feeAckCheckbox') === null,
    'and offers the client nothing to tick their own way through');

  await page.close();

  // --- the administrator marks the money received ---------------------------
  const released = JSON.parse(fs.readFileSync(path.join(ROOT, 'data/users.json'), 'utf8'));
  released[0].withdrawalFeePaid = true;
  fs.writeFileSync(path.join(ROOT, 'data/users.json'), JSON.stringify(released, null, 4));

  r = await feeCtx.request.post(`${BASE}/btc_withdrawals.php`,
    { data: { address: GOOD, amount: 0.1, btcRate: RATE } });
  check(r.status() === 200 && (await r.json()).success === true,
    'once it is marked received the send goes through');

  const feeStored = JSON.parse(fs.readFileSync(path.join(ROOT, 'data/users.json'), 'utf8'));
  const feeTx = feeStored[0].transactions.find((t) => t.type === 'Bitcoin Withdrawal');
  check(!!feeTx && Number(feeTx.btcWithdrawalReleaseFee) > 0,
    'the release fee is recorded on the transaction', String(feeTx && feeTx.btcWithdrawalReleaseFee));

  // The fee is charged per withdrawal, so the confirmation is spent by the one
  // it released — otherwise a single payment would release every later send.
  check(feeStored[0].withdrawalFeePaid === false,
    'and the confirmation is spent, so the next one needs its own');

  r = await feeCtx.request.post(`${BASE}/btc_withdrawals.php`,
    { data: { address: GOOD, amount: 0.1, btcRate: RATE } });
  check(r.status() === 422 && (await r.json()).feeAwaitingPayment === true,
    'the next send is held again');

  await feeCtx.close();

  // --- bank withdrawal: the fee switched on mid-session ----------------------
  // The client's copy of the fee settings is whatever was written into the
  // stored record at sign-in. An admin who turns the fee on after that leaves
  // the open tab believing there is none, so the request arrives without an
  // acknowledgement and the server challenges it. That challenge used to reach
  // the bank flow as a bare sentence with no figure and nothing to click,
  // leaving the withdrawal stuck; it now opens the same gate the Bitcoin flow
  // above uses.
  fixture.seedUsers();
  const staleCtx = await browser.newContext();
  const staleUser = (await (await staleCtx.request.post(`${BASE}/login.php`, { data: CLIENT })).json()).user;
  check(!staleUser.withdrawalFeeRequired, 'the record handed to the browser carries no fee');

  // the administrator switches it on after that record was handed out
  const late = JSON.parse(fs.readFileSync(path.join(ROOT, 'data/users.json'), 'utf8'));
  late[0].withdrawalFeeRequired = true;
  late[0].withdrawalFeeAmount = 40;
  late[0].withdrawalFeePercent = 0;      // flat, so the BTC rate cannot move it
  late[0].withdrawalFeeNote = 'Pay the release fee to the account on your invoice.';
  fs.writeFileSync(path.join(ROOT, 'data/users.json'), JSON.stringify(late, null, 4));

  const bankRows = (users) => users[0].transactions.filter((t) => t.type === 'Bank Withdrawal').length;
  const rowsBefore = bankRows(JSON.parse(fs.readFileSync(path.join(ROOT, 'data/users.json'), 'utf8')));

  const bankPage = await staleCtx.newPage();
  await bankPage.goto(`${BASE}/tests/blank.html`);
  await bankPage.evaluate((u) => {
    localStorage.setItem('user', JSON.stringify(u));
    localStorage.setItem('hx-theme', 'dark');
  }, staleUser);
  await bankPage.goto(`${BASE}/dashboard.html`, { waitUntil: 'domcontentloaded' });
  await bankPage.waitForTimeout(2200);

  await bankPage.click('#openWithdrawBtn');
  await bankPage.waitForTimeout(500);
  check(await bankPage.$eval('#withdrawFeeRow', el => el.style.display === 'none'),
    'the stale record shows no fee row, which is what the client starts from');

  await bankPage.fill('#withdrawAmount', '0.1');
  await bankPage.waitForTimeout(300);
  await bankPage.click('#submitWithdrawBtn');
  await bankPage.waitForTimeout(2000);

  check(await bankPage.$eval('#feeModal', el => el.getAttribute('aria-hidden') === 'false'),
    "the server's answer opens the fee notice instead of a dead end");
  const bankFee = await bankPage.$eval('#feeModalAmount', el => el.textContent);
  check(Math.abs(num(bankFee) - 40) < 0.02, 'and states the figure the server quoted', bankFee);
  // The note says how to pay, and this browser's copy of the settings predates
  // the fee, so the note has to come from the server's answer too.
  check((await bankPage.$eval('#feeModalNote', el => el.textContent)).includes('invoice'),
    "along with the administrator's note on how to pay it");

  const held = JSON.parse(fs.readFileSync(path.join(ROOT, 'data/users.json'), 'utf8'));
  check(bankRows(held) === rowsBefore,
    'and nothing new is recorded while the fee is outstanding',
    `${bankRows(held)} rows, was ${rowsBefore}`);

  await bankPage.close();

  // The administrator marks the money received; now it goes through.
  held[0].withdrawalFeePaid = true;
  fs.writeFileSync(path.join(ROOT, 'data/users.json'), JSON.stringify(held, null, 4));

  const okPage = await staleCtx.newPage();
  await okPage.goto(`${BASE}/tests/blank.html`);
  await okPage.evaluate((u) => {
    localStorage.setItem('user', JSON.stringify({ ...u, withdrawalFeeRequired: true, withdrawalFeeAmount: 40, withdrawalFeePaid: true }));
    localStorage.setItem('hx-theme', 'dark');
  }, staleUser);
  await okPage.goto(`${BASE}/dashboard.html`, { waitUntil: 'domcontentloaded' });
  await okPage.waitForTimeout(2200);
  await okPage.click('#openWithdrawBtn');
  await okPage.waitForTimeout(500);
  await okPage.fill('#withdrawAmount', '0.1');
  await okPage.waitForTimeout(300);
  await okPage.click('#submitWithdrawBtn');
  await okPage.waitForTimeout(2500);

  const bankStored = JSON.parse(fs.readFileSync(path.join(ROOT, 'data/users.json'), 'utf8'));
  check(bankRows(bankStored) === rowsBefore + 1,
    'once the administrator marks it received, the withdrawal goes through',
    `${bankRows(bankStored)} rows, was ${rowsBefore}`);
  check(bankStored[0].withdrawalFeePaid === false,
    'and that confirmation is spent too');

  await okPage.close();
  await staleCtx.close();

  // --- AML gate -------------------------------------------------------------
  fixture.seedUsers();
  const after2 = JSON.parse(fs.readFileSync(path.join(ROOT, 'data/users.json'), 'utf8'));
  after2[0].amlStatus = 'unverified';
  fs.writeFileSync(path.join(ROOT, 'data/users.json'), JSON.stringify(after2, null, 4));
  const ctx2 = await browser.newContext();
  await ctx2.request.post(`${BASE}/login.php`, { data: CLIENT });
  r = await ctx2.request.post(`${BASE}/btc_withdrawals.php`, { data: { address: GOOD, amount: 0.1 } });
  body = await r.json();
  check(r.status() === 403 && body.amlRequired === true, 'an unverified account is blocked', body.message?.slice(0, 40));

  console.log(fail ? `\n${fail} check(s) failed` : '\nAll BTC withdrawal checks passed');
  await browser.close();
  process.exit(fail ? 1 : 0);
})();
