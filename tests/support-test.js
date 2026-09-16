/* ============================================================================
   Support centre
   ----------------------------------------------------------------------------
   Opens a ticket as a client, answers it as staff through the admin console,
   and checks the client sees the reply. Also checks the ownership boundary:
   one client must not be able to read or touch another's ticket.

   Needs the app already running; BASE_URL overrides the default.
   ========================================================================== */

const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8899';
const ROOT = path.resolve(__dirname, '..');
const CLIENT = { email: 'demo.client@example.invalid', password: 'CiSmokeTest!2026' };
const ADMIN_PASSWORD = 'Genesis2050!';

let fail = 0;
const check = (ok, label, detail = '') => {
  if (!ok) fail++;
  console.log(`  ${ok ? 'ok  ' : 'FAIL'} ${label}${detail ? ' — ' + detail : ''}`);
};

(async () => {
  fs.copyFileSync(path.join(ROOT, 'tests/fixtures/users.json'), path.join(ROOT, 'data/users.json'));
  const ticketsFile = path.join(ROOT, 'data/tickets.json');
  if (fs.existsSync(ticketsFile)) fs.unlinkSync(ticketsFile);

  const browser = await chromium.launch(
    process.env.CHROMIUM_EXECUTABLE ? { executablePath: process.env.CHROMIUM_EXECUTABLE } : {}
  );

  // ---------------------------------------------------------------- client
  const client = await browser.newContext();
  await client.request.post(`${BASE}/login.php`, { data: CLIENT });

  let r = await (await client.request.post(`${BASE}/support.php`, { data: { action: 'list' } })).json();
  check(r.success === true && Array.isArray(r.tickets) && r.tickets.length === 0, 'starts with no tickets');
  check(r.topics && Object.keys(r.topics).length > 3, 'topic list offered');

  let bad = await client.request.post(`${BASE}/support.php`, {
    data: { action: 'create', topic: 'other', subject: 'hi', body: 'short' }
  });
  check(bad.status() === 422, 'a too-short ticket is refused', 'status ' + bad.status());

  const created = await (await client.request.post(`${BASE}/support.php`, {
    data: {
      action: 'create', topic: 'withdrawal',
      subject: 'Withdrawal still in review',
      body: 'My bank withdrawal from Tuesday is still showing as in review. Could you check it?'
    }
  })).json();
  check(created.success === true, 'ticket opens', created.message);
  check(/^HX-[A-Z2-9]{6}$/.test(created.ticket.reference), 'gets a quotable reference', created.ticket.reference);
  check(created.ticket.status === 'open' && created.ticket.messages.length === 1, 'opens with one message');
  const ticketId = created.ticket.id;

  // ----------------------------------------------------------- another client
  // Ownership: a second signed-in client must not see or touch this ticket.
  const other = await browser.newContext();
  const users = JSON.parse(fs.readFileSync(path.join(ROOT, 'data/users.json'), 'utf8'));
  users.push(Object.assign({}, users[0], { email: 'other.client@example.invalid', name: 'Other Client', totp: null }));
  fs.writeFileSync(path.join(ROOT, 'data/users.json'), JSON.stringify(users, null, 4));
  await other.request.post(`${BASE}/login.php`, { data: { email: 'other.client@example.invalid', password: CLIENT.password } });

  const otherList = await (await other.request.post(`${BASE}/support.php`, { data: { action: 'list' } })).json();
  check(otherList.tickets.length === 0, 'another client sees none of it');
  const steal = await other.request.post(`${BASE}/support.php`, { data: { action: 'reply', id: ticketId, body: 'let me in' } });
  check(steal.status() === 404, 'another client cannot reply to it', 'status ' + steal.status());
  const stealClose = await other.request.post(`${BASE}/support.php`, { data: { action: 'close', id: ticketId } });
  check(stealClose.status() === 404, 'another client cannot close it', 'status ' + stealClose.status());

  // -------------------------------------------------------------- anonymous
  const anon = await browser.newContext();
  const anonRes = await anon.request.post(`${BASE}/support.php`, { data: { action: 'list' } });
  check(anonRes.status() === 401, 'a signed-out visitor is refused', 'status ' + anonRes.status());

  // ------------------------------------------------------------------ staff
  const admin = await browser.newContext();
  const adminPage = await admin.newPage();
  await adminPage.goto(`${BASE}/admin.php`, { waitUntil: 'domcontentloaded' });
  await adminPage.fill('input[name="admin_password"]', ADMIN_PASSWORD);
  await adminPage.press('input[name="admin_password"]', 'Enter');
  await adminPage.waitForTimeout(1200);

  const queued = await adminPage.$$eval('#support .aml-card', els => els.length);
  check(queued === 1, 'the ticket reaches the admin queue', queued + ' card(s)');

  await adminPage.fill('#support textarea[name="ticket_body"]',
    'Checked it — the payout cleared our side this morning and should land tomorrow.');
  await adminPage.click('#support button[name="ticket_reply"]');
  await adminPage.waitForTimeout(1200);

  // ------------------------------------------------------- back to the client
  const after = await (await client.request.post(`${BASE}/support.php`, { data: { action: 'list' } })).json();
  const ticket = after.tickets[0];
  check(ticket.messages.length === 2, 'the client sees the reply', ticket.messages.length + ' messages');
  check(ticket.messages[1].from === 'staff', 'it is marked as staff');
  check(ticket.status === 'answered', 'status moves to answered', ticket.status);

  const closed = await (await client.request.post(`${BASE}/support.php`, { data: { action: 'close', id: ticketId } })).json();
  check(closed.success === true && closed.ticket.status === 'closed', 'the client can close it');

  console.log(fail ? `\n${fail} check(s) failed` : '\nAll support checks passed');
  await browser.close();
  process.exit(fail ? 1 : 0);
})();
