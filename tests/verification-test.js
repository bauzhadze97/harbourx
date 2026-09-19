/* ============================================================================
   HarbourX verification flow
   ----------------------------------------------------------------------------
   Identity documents are the most sensitive thing the platform stores, so most
   of what is checked here is what the endpoint refuses: a file that is not a
   PDF, a request from a signed-out browser, and one client reaching for
   another's document. The happy paths are checked too, but they are the easy
   half.
   ========================================================================== */

const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const fixture = require('./fixture.js');

const ROOT = path.join(__dirname, '..');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8899';
const CLIENT = { email: 'test.client@example.invalid', password: 'CiSmokeTest!2026' };
const OTHER = { email: 'second.client@example.invalid', password: 'CiSmokeTest!2026' };
const DOCS = path.join(ROOT, 'uploads/documents');

let fail = 0;
const check = (ok, label, detail = '') => {
  if (!ok) fail++;
  console.log(`${ok ? '  ok  ' : '  FAIL'} ${label}${detail ? ` — ${detail}` : ''}`);
};

const usersOnDisk = () => JSON.parse(fs.readFileSync(path.join(ROOT, 'data/users.json'), 'utf8'));
const storedFiles = () => (fs.existsSync(DOCS) ? fs.readdirSync(DOCS) : []);

/* Two files that both end in .pdf. Only one of them is one. */
const TMP = path.join(ROOT, 'tests/.tmp-verification');
fs.mkdirSync(TMP, { recursive: true });
const REAL_PDF = path.join(TMP, 'passport.pdf');
const FAKE_PDF = path.join(TMP, 'payload.pdf');
fs.writeFileSync(REAL_PDF, '%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n');
fs.writeFileSync(FAKE_PDF, '<?php echo "not a pdf"; ?>\n');

(async () => {
  fixture.seedUsers();

  // A second account, so "another client's document" is a real situation and
  // not a hypothetical one.
  const users = usersOnDisk();
  users.push({ ...users[0], email: OTHER.email, name: 'Second Client', verificationDocuments: [] });
  fs.writeFileSync(path.join(ROOT, 'data/users.json'), JSON.stringify(users, null, 4));

  const before = storedFiles().length;
  const browser = await chromium.launch(
    process.env.CHROMIUM_EXECUTABLE ? { executablePath: process.env.CHROMIUM_EXECUTABLE } : {}
  );

  try {
    // ------------------------------------------------------ signed out
    const anon = await browser.newContext();
    let r = await anon.request.get(`${BASE}/verification.php`);
    check(r.status() === 401, 'a signed-out browser gets nothing back', `status ${r.status()}`);

    // ------------------------------------------------------ signed in
    const ctx = await browser.newContext();
    const user = (await (await ctx.request.post(`${BASE}/login.php`, { data: CLIENT })).json()).user;
    check(!!user, 'the fixture client signs in');

    r = await ctx.request.get(`${BASE}/verification.php`);
    let body = await r.json();
    check(r.status() === 200 && Array.isArray(body.documents), 'the state comes back as JSON');

    // ------------------------------------------- what is not a PDF is refused
    r = await ctx.request.post(`${BASE}/verification.php`, {
      multipart: { action: 'upload', document: { name: 'payload.pdf', mimeType: 'application/pdf', buffer: fs.readFileSync(FAKE_PDF) } }
    });
    check(r.status() === 415, 'a file that only claims to be a PDF is refused', `status ${r.status()}`);
    check(storedFiles().length === before, 'and nothing of it reaches the disk');

    // ---------------------------------------------------- a real one is kept
    r = await ctx.request.post(`${BASE}/verification.php`, {
      multipart: { action: 'upload', document: { name: 'my passport (1).pdf', mimeType: 'application/pdf', buffer: fs.readFileSync(REAL_PDF) } }
    });
    body = await r.json();
    check(r.status() === 200 && body.documents.length === 1, 'a real PDF is accepted', `status ${r.status()}`);

    const doc = body.documents[0];
    check(/^[a-f0-9]{32}$/.test(doc.id), 'it is stored under a name we chose, not the client', doc.id);
    check(fs.existsSync(path.join(DOCS, doc.id + '.pdf')), 'and the file is on disk under that name');
    check(!storedFiles().some(name => name.includes('passport')),
      "the client's own filename never reaches the filesystem", storedFiles().join(', '));
    check(doc.name === 'my passport 1.pdf', 'though it is kept, cleaned up, for the admin to read', doc.name);

    // A name that sanitises to nothing must not arrive as "pdf.pdf".
    r = await ctx.request.post(`${BASE}/verification.php`, {
      multipart: { action: 'upload', document: { name: 'პასპორტი.pdf', mimeType: 'application/pdf', buffer: fs.readFileSync(REAL_PDF) } }
    });
    const named = (await r.json()).documents.find(d => d.id !== doc.id);
    check(named && named.name === 'document.pdf',
      'a name in an alphabet we cannot keep falls back to something readable', named && named.name);
    await ctx.request.post(`${BASE}/verification.php`, { multipart: { action: 'remove', id: named.id } });

    // ------------------------------------------------- who may read it back
    r = await anon.request.get(`${BASE}/verification.php?file=${doc.id}`);
    check(r.status() === 401, 'a signed-out request for the file is refused', `status ${r.status()}`);

    const otherCtx = await browser.newContext();
    await otherCtx.request.post(`${BASE}/login.php`, { data: OTHER });
    r = await otherCtx.request.get(`${BASE}/verification.php?file=${doc.id}`);
    check(r.status() === 404, "another client cannot read someone else's document", `status ${r.status()}`);

    r = await ctx.request.get(`${BASE}/verification.php?file=${doc.id}`);
    check(r.status() === 200 && (r.headers()['content-type'] || '').includes('application/pdf'),
      'the client who uploaded it can', `status ${r.status()}`);
    check((r.headers()['content-disposition'] || '').includes('attachment'),
      'and it is served as a download, never rendered in place');

    // The id is the only thing taken from the request, and it is matched
    // against a pattern before it is ever joined to a path.
    for (const probe of ['../../data/users', '..%2f..%2fdata%2fusers', 'abc']) {
      r = await ctx.request.get(`${BASE}/verification.php?file=${probe}`);
      check(r.status() === 404, `a crafted file id is refused (${probe.slice(0, 18)})`, `status ${r.status()}`);
    }

    // ---------------------------------------------- the day and time picker
    // The picker builds the days and times in the browser and sends back the
    // local wall-clock string the endpoint parses. If the two ever disagree on
    // the format, a booking silently lands at the wrong time or not at all.
    const pickerPage = await ctx.newPage();
    await pickerPage.goto(`${BASE}/tests/blank.html`);
    await pickerPage.evaluate(u => localStorage.setItem('user', JSON.stringify(u)), user);
    await pickerPage.goto(`${BASE}/verification.html`, { waitUntil: 'domcontentloaded' });
    await pickerPage.waitForTimeout(1800);

    const dayCount = await pickerPage.$$eval('.picker-day', d => d.length);
    check(dayCount > 5, 'the picker offers a strip of days', `${dayCount} days`);
    check(await pickerPage.$eval('#bookSessionBtn', b => b.disabled),
      'and will not submit until a time is chosen');
    check(await pickerPage.$('input[type="datetime-local"]') === null,
      'the field people had to type a date into is gone');

    await pickerPage.$$eval('.picker-day', d => d[1].click());
    await pickerPage.waitForTimeout(300);
    const slots = await pickerPage.$$('.picker-slot');
    check(slots.length > 0, 'a day shows times to press', `${slots.length} slots`);
    await slots[2].click();
    await pickerPage.waitForTimeout(300);
    check(!(await pickerPage.$eval('#bookSessionBtn', b => b.disabled)),
      'choosing one enables the request');

    await pickerPage.click('#bookSessionBtn');
    await pickerPage.waitForTimeout(2000);
    check(!(await pickerPage.$eval('#sessionBooked', el => el.hidden)),
      'and the booking goes through from the page itself');

    // Tidy up so the checks below start from no session.
    const bookedId = (await (await ctx.request.get(`${BASE}/verification.php`)).json()).session.id;
    await ctx.request.post(`${BASE}/verification.php`, { multipart: { action: 'cancel', id: bookedId } });
    await pickerPage.close();

    // --------------------------------------------------------- the session
    const at = new Date(Date.now() + 26 * 3600 * 1000);
    const localValue = new Date(at.getTime() - at.getTimezoneOffset() * 60000).toISOString().slice(0, 16);

    r = await ctx.request.post(`${BASE}/verification.php`, {
      multipart: { action: 'book', at: localValue, timezone: 'UTC', note: 'Rather show you than post anything.' }
    });
    body = await r.json();
    check(r.status() === 200 && body.session && body.session.id, 'a session can be booked', `status ${r.status()}`);

    r = await ctx.request.post(`${BASE}/verification.php`, {
      multipart: { action: 'book', at: localValue, timezone: 'UTC', note: '' }
    });
    check(r.status() === 409, 'but not two at once', `status ${r.status()}`);

    const soon = new Date(Date.now() + 5 * 60 * 1000);
    r = await ctx.request.post(`${BASE}/verification.php`, {
      multipart: { action: 'book', at: new Date(soon.getTime() - soon.getTimezoneOffset() * 60000).toISOString().slice(0, 16), timezone: 'UTC', note: '' }
    });
    check(r.status() === 409 || r.status() === 422, 'and not one five minutes from now', `status ${r.status()}`);

    // It lands in the callbacks file the operations console already reads.
    const callbacks = JSON.parse(fs.readFileSync(path.join(ROOT, 'data/client_callbacks.json'), 'utf8'));
    check(callbacks.some(c => c.clientEmail === CLIENT.email && c.subject === 'Screen-share verification session'),
      'and it shows up as an ordinary callback for the admin');

    r = await ctx.request.post(`${BASE}/verification.php`, {
      multipart: { action: 'cancel', id: body.session ? body.session.id : '' }
    });
    body = await r.json();
    check(r.status() === 200 && body.session === null, 'the client can cancel it', `status ${r.status()}`);

    // --------------------------------------------------------- removing it
    r = await ctx.request.post(`${BASE}/verification.php`, { multipart: { action: 'remove', id: doc.id } });
    body = await r.json();
    check(r.status() === 200 && body.documents.length === 0, 'a document can be withdrawn before review');
    check(!fs.existsSync(path.join(DOCS, doc.id + '.pdf')), 'and the file goes with it');

    await anon.close();
    await otherCtx.close();
    await ctx.close();
  } finally {
    await browser.close();
    fs.rmSync(TMP, { recursive: true, force: true });
  }

  console.log(fail ? `\n${fail} check(s) failed` : '\nAll verification checks passed');
  process.exit(fail ? 1 : 0);
})();
