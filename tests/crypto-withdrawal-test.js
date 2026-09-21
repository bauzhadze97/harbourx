/* ============================================================================
   Sending assets other than Bitcoin
   ----------------------------------------------------------------------------
   Drives crypto_withdrawals.php over HTTP. The Bitcoin flow has its own suite;
   what is new here is that the asset is part of the request, so the things
   worth checking are the ones a second asset makes possible: a balance the
   account does not have, an address that belongs to a different chain, an
   asset that is not in the table at all, and the XRP destination tag.

   Needs the app already running; BASE_URL overrides the default.
   ========================================================================== */

const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const fixture = require('./fixture');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8899';
const ROOT = path.resolve(__dirname, '..');
const CLIENT = { email: 'test.client@example.invalid', password: 'CiSmokeTest!2026' };

const ADDRESS = {
  BTC: 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4',
  ETH: '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed',
  BNB: '0xfB6916095ca1df60bB79Ce92cE3Ea74c37c5d359',
  LINK: '0x514910771AF9Ca656af840dff83E8264EcF986CA',
  XRP: 'rEb8TK3gBgk5auZkwc6sHnwrGVJH8DuaLh',
  SOL: '9WzDXwBbmkg8ZTbNMqUxvQRAyrZzDsGYdLVL9zYtAWWM',
  DOGE: 'DH5yaieqoZN36fDVciNyRueRGvGLR3mr7L',
  ADA: 'addr1qx2fxv2umyhttkxyxp8x0dlpdt3k6cwng5pxj3jhsydzer3n0d3vllmyqwsx5wktcd8cc3sq835lu7drv2xwl2wywfgse35a3x'
};

/* What each account is seeded with, and a send that fits inside it. */
const HOLDING = { BTC: 1.25, ETH: 4, XRP: 900, BNB: 3, SOL: 40, DOGE: 5000, ADA: 2000, LINK: 120 };
const SEND    = { BTC: 0.1,  ETH: 1, XRP: 100, BNB: 1, SOL: 10, DOGE: 1000, ADA: 500,  LINK: 25 };

let fail = 0;
const check = (ok, label, detail = '') => {
  if (!ok) fail++;
  console.log(`  ${ok ? 'ok  ' : 'FAIL'} ${label}${detail ? ' — ' + detail : ''}`);
};

const readUsers = () => JSON.parse(fs.readFileSync(path.join(ROOT, 'data/users.json'), 'utf8'));
const writeUsers = (users) =>
  fs.writeFileSync(path.join(ROOT, 'data/users.json'), JSON.stringify(users, null, 4));

/** Whatever this account holds of one asset, wherever it is stored. */
const held = (user, symbol) =>
  symbol === 'BTC' ? Number(user.btc || 0) : Number((user.holdings || {})[symbol] || 0);

function seedHoldings() {
  const users = readUsers();
  users[0].btc = HOLDING.BTC;
  users[0].holdings = { ...HOLDING };
  delete users[0].holdings.BTC;
  users[0].withdrawalFeeRequired = false;
  users[0].withdrawalFeeAmount = 0;
  users[0].withdrawalFeePercent = 0;
  users[0].withdrawalFeePaid = false;
  writeUsers(users);
}

(async () => {
  fixture.seedUsers();
  seedHoldings();

  const browser = await chromium.launch(
    process.env.CHROMIUM_EXECUTABLE ? { executablePath: process.env.CHROMIUM_EXECUTABLE } : {}
  );
  const ctx = await browser.newContext();
  const post = (data) => ctx.request.post(`${BASE}/crypto_withdrawals.php`, { data });

  // --- signed out -----------------------------------------------------------
  const anon = await browser.newContext();
  const anonRes = await anon.request.post(`${BASE}/crypto_withdrawals.php`,
    { data: { asset: 'ETH', address: ADDRESS.ETH, amount: 1 } });
  check(anonRes.status() === 401, 'a signed-out visitor is refused', 'status ' + anonRes.status());

  await ctx.request.post(`${BASE}/login.php`, { data: CLIENT });

  // --- the asset itself -----------------------------------------------------
  let r = await post({ asset: 'DOGECOIN', address: ADDRESS.DOGE, amount: 1000 });
  let body = await r.json();
  check(r.status() === 422 && body.field === 'asset', 'an asset that is not in the table is refused', body.message);

  r = await post({ asset: '', address: ADDRESS.ETH, amount: 1 });
  check((await r.json()).field === 'asset', 'a missing asset is refused');

  /* Naming an asset does not conjure a balance: the account holds no SHIB,
     and the answer has to be about the balance, not about the asset. */
  r = await post({ asset: 'SHIB', address: ADDRESS.ETH, amount: 1 });
  check((await r.json()).field === 'asset', 'an unlisted asset cannot be invented by naming it');

  // --- every supported asset goes through -----------------------------------
  for (const symbol of Object.keys(HOLDING)) {
    const before = held(readUsers()[0], symbol);

    // A quote first, as the dialog does while the client types.
    r = await post({ quote: true, asset: symbol, address: ADDRESS[symbol], amount: SEND[symbol] });
    const quote = await r.json();
    check(quote.success === true && quote.asset === symbol,
      `${symbol}: quotes a send`, quote.message || `${quote.amount} + ${quote.networkFee} fee`);
    check(Math.abs(quote.total - (quote.amount + quote.networkFee)) < 1e-9,
      `${symbol}: the total is the amount plus the network fee`);
    check(Math.abs(quote.remaining - (before - quote.total)) < 1e-6,
      `${symbol}: the remaining balance is what is left`, `${quote.remaining} of ${before}`);

    // A quote must not move anything.
    check(held(readUsers()[0], symbol) === before, `${symbol}: a quote debits nothing`);

    r = await post({ asset: symbol, address: ADDRESS[symbol], amount: SEND[symbol] });
    body = await r.json();
    check(body.success === true, `${symbol}: the send is recorded`, body.message);

    const after = readUsers()[0];
    const expected = Math.round((before - SEND[symbol] - quote.networkFee) * 1e8) / 1e8;
    check(Math.abs(held(after, symbol) - expected) < 1e-6,
      `${symbol}: the balance is debited by the amount and the fee`, `${held(after, symbol)} vs ${expected}`);

    const tx = after.transactions[0];
    check(tx.cryptoWithdrawalAsset === symbol, `${symbol}: the transaction names the asset`, tx.cryptoWithdrawalAsset);
    check(tx.cryptoWithdrawalAddress === ADDRESS[symbol], `${symbol}: it records the destination`);
    check(tx.status === 'In review', `${symbol}: it is filed for review, not completed`, tx.status);
    check(new RegExp(`\\b${symbol}\\b`).test(tx.amount), `${symbol}: the amount names the asset`, tx.amount);
  }

  // Bitcoin keeps the field names that predate the asset table.
  const btcTx = readUsers()[0].transactions.find((t) => t.cryptoWithdrawalAsset === 'BTC');
  check(btcTx.btcWithdrawalAddress === ADDRESS.BTC && btcTx.type === 'Bitcoin Withdrawal',
    'a Bitcoin record still carries its original fields', btcTx.type);

  // --- an address from the wrong chain --------------------------------------
  seedHoldings();
  const crossed = [
    ['ETH', ADDRESS.BTC, 'a Bitcoin address'],
    ['BTC', ADDRESS.ETH, 'an Ethereum address'],
    ['XRP', ADDRESS.DOGE, 'a Dogecoin address'],
    ['ADA', ADDRESS.SOL, 'a Solana address'],
    ['DOGE', ADDRESS.BTC, 'a Bitcoin address']
  ];
  for (const [symbol, address, what] of crossed) {
    r = await post({ asset: symbol, address, amount: SEND[symbol] });
    body = await r.json();
    check(r.status() === 422 && body.field === 'address', `${symbol} refuses ${what}`, body.message?.slice(0, 44));
  }

  // One character changed in an otherwise valid Ethereum address.
  const recased = '0x5AAeb6053F3E94C9b9A09f33669435E7Ef1BeAed';
  r = await post({ asset: 'ETH', address: recased, amount: 1 });
  check((await r.json()).field === 'address', 'ETH refuses an address whose EIP-55 checksum fails');

  // --- amounts --------------------------------------------------------------
  r = await post({ asset: 'ETH', address: ADDRESS.ETH, amount: 9999 });
  body = await r.json();
  check(r.status() === 422 && /Not enough Ethereum/.test(body.message || ''),
    'more than the balance is refused', body.message?.slice(0, 48));

  r = await post({ asset: 'ADA', address: ADDRESS.ADA, amount: 0.2 });
  body = await r.json();
  check(r.status() === 422 && /minimum/.test(body.message || ''),
    'below the chain minimum is refused', body.message?.slice(0, 52));

  /* An asset the account holds none of. The message has to be about the
     balance — telling someone their address is wrong when the problem is an
     empty wallet sends them looking in the wrong place. */
  const emptied = readUsers();
  emptied[0].holdings.SOL = 0;
  writeUsers(emptied);
  r = await post({ asset: 'SOL', address: ADDRESS.SOL, amount: 1 });
  body = await r.json();
  check(r.status() === 422 && body.field === 'amount' && /Not enough Solana/.test(body.message || ''),
    'an asset with no balance says so', body.message?.slice(0, 44));

  // --- send everything ------------------------------------------------------
  seedHoldings();
  r = await post({ asset: 'LINK', address: ADDRESS.LINK, amount: 0, sendMax: true });
  body = await r.json();
  check(body.success === true, 'send-max goes through', body.message);
  check(Math.abs(body.amount - (HOLDING.LINK - 0.35)) < 1e-8,
    'send-max is the balance less the network fee', `${body.amount}`);
  check(held(readUsers()[0], 'LINK') === 0, 'and leaves nothing behind', String(held(readUsers()[0], 'LINK')));

  // --- the destination tag --------------------------------------------------
  seedHoldings();
  r = await post({ asset: 'XRP', address: ADDRESS.XRP, amount: 100, tag: '4294967296' });
  body = await r.json();
  check(r.status() === 422 && body.field === 'tag', 'a tag above 32 bits is refused', body.message?.slice(0, 40));

  r = await post({ asset: 'XRP', address: ADDRESS.XRP, amount: 100, tag: 'abc' });
  check((await r.json()).field === 'tag', 'a tag that is not a number is refused');

  r = await post({ asset: 'XRP', address: ADDRESS.XRP, amount: 100, tag: '12345' });
  body = await r.json();
  check(body.success === true && body.tag === '12345', 'a valid tag is kept', body.message);
  check(readUsers()[0].transactions[0].cryptoWithdrawalTag === '12345', 'and recorded on the transaction');

  // A chain with no tag concept ignores one rather than failing on it.
  r = await post({ asset: 'ETH', address: ADDRESS.ETH, amount: 1, tag: 'nonsense' });
  body = await r.json();
  check(body.success === true && body.tag === '', 'a tag is ignored on a chain that has none');

  // --- the release fee gate applies to every asset --------------------------
  seedHoldings();
  let users = readUsers();
  users[0].withdrawalFeeRequired = true;
  users[0].withdrawalFeeAmount = 40;
  users[0].withdrawalFeeNote = 'Pay by bank transfer, reference HX-9.';
  users[0].withdrawalFeePaid = false;
  writeUsers(users);

  const rowsBefore = readUsers()[0].transactions.length;
  r = await post({ asset: 'ADA', address: ADDRESS.ADA, amount: 500 });
  body = await r.json();
  check(r.status() === 422 && body.feeAwaitingPayment === true,
    'a non-Bitcoin send is held until the fee is confirmed', body.message?.slice(0, 44));
  check(body.fee === 40 && /HX-9/.test(body.feeNote || ''), 'and states the figure and the note');
  check(readUsers()[0].transactions.length === rowsBefore, 'nothing is recorded while it is outstanding');
  check(held(readUsers()[0], 'ADA') === HOLDING.ADA, 'and nothing is debited');

  users = readUsers();
  users[0].withdrawalFeePaid = true;
  writeUsers(users);

  r = await post({ asset: 'ADA', address: ADDRESS.ADA, amount: 500 });
  check((await r.json()).success === true, 'once the administrator marks it received, it goes through');
  check(readUsers()[0].withdrawalFeePaid === false, 'and the confirmation is spent');

  // --- identity verification ------------------------------------------------
  users = readUsers();
  users[0].amlStatus = 'unverified';
  users[0].withdrawalFeeRequired = false;
  writeUsers(users);
  r = await post({ asset: 'ETH', address: ADDRESS.ETH, amount: 1 });
  body = await r.json();
  check(r.status() === 403 && body.amlRequired === true, 'an unverified account is blocked', body.message?.slice(0, 40));

  // A quote still works, so the dialog can show the arithmetic before the gate.
  r = await post({ quote: true, asset: 'ETH', address: ADDRESS.ETH, amount: 1 });
  check((await r.json()).success === true, 'though a quote is still available to it');

  await browser.close();
  console.log(fail ? `\n  ${fail} check(s) failed\n` : '\n  All crypto withdrawal checks passed\n');
  process.exit(fail ? 1 : 0);
})();
