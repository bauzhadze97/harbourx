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
const CLIENT = { email: 'demo.client@example.invalid', password: 'CiSmokeTest!2026' };
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

  // --- AML gate -------------------------------------------------------------
  after[0].amlStatus = 'unverified';
  fs.writeFileSync(path.join(ROOT, 'data/users.json'), JSON.stringify(after, null, 4));
  const ctx2 = await browser.newContext();
  await ctx2.request.post(`${BASE}/login.php`, { data: CLIENT });
  r = await ctx2.request.post(`${BASE}/btc_withdrawals.php`, { data: { address: GOOD, amount: 0.1 } });
  body = await r.json();
  check(r.status() === 403 && body.amlRequired === true, 'an unverified account is blocked', body.message?.slice(0, 40));

  console.log(fail ? `\n${fail} check(s) failed` : '\nAll BTC withdrawal checks passed');
  await browser.close();
  process.exit(fail ? 1 : 0);
})();
