/* ============================================================================
   Seeding the data directory for a test run
   ----------------------------------------------------------------------------
   The browser tests sign in and then write balances, tickets and 2FA secrets,
   so each one starts from the synthetic fixture rather than whatever is there.

   But "whatever is there" may be real client data — the README says to copy
   data/users.json in to work against it, and then says to run these tests.
   Seeding without putting it back destroys that file. So: keep the original,
   restore it however the process ends, and remove the seed again if there was
   no file to begin with.
   ========================================================================== */

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const LIVE = path.join(ROOT, 'data/users.json');
const FIXTURE = path.join(ROOT, 'tests/fixtures/users.json');

const pending = [];
let armed = false;
let done = false;

function restoreAll() {
  if (done) return;
  done = true;
  for (const { file, original } of pending) {
    try {
      if (original === null) fs.rmSync(file, { force: true });
      else fs.writeFileSync(file, original);
    } catch (e) {
      // Exiting anyway; say it rather than fail silently over someone's data.
      console.error(`  !! could not restore ${path.relative(ROOT, file)} — ${e.message}`);
    }
  }
}

function arm() {
  if (armed) return;
  armed = true;
  process.on('exit', restoreAll);
  for (const signal of ['SIGINT', 'SIGTERM']) {
    process.on(signal, () => { restoreAll(); process.exit(130); });
  }
}

/** Remember a data file as it is now and put it back when the run ends. */
function preserve(file) {
  arm();
  // Only the first snapshot is the caller's own file; a later one is whatever
  // an earlier seed left behind. A test that re-seeds mid-run would otherwise
  // have that written back over the original at exit.
  if (pending.some((entry) => entry.file === file)) return file;
  pending.push({ file, original: fs.existsSync(file) ? fs.readFileSync(file) : null });
  return file;
}

/** Start from the synthetic account, keeping whatever was there before. */
function seedUsers() {
  preserve(LIVE);
  fs.mkdirSync(path.dirname(LIVE), { recursive: true });
  fs.copyFileSync(FIXTURE, LIVE);
}

/** Start with no tickets, keeping whatever was there before. */
function clearTickets() {
  const file = path.join(ROOT, 'data/tickets.json');
  preserve(file);
  fs.rmSync(file, { force: true });
}

module.exports = { seedUsers, clearTickets, preserve, restoreAll, ROOT, LIVE, FIXTURE };
