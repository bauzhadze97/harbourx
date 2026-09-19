<?php
$usersFile = __DIR__ . '/data/users.json';

function loadUsers($file) {
    if (!file_exists($file)) return [];
    $json = file_get_contents($file);
    $users = json_decode($json, true);
    return is_array($users) ? $users : [];
}

function saveUsers($file, $users) {
    if (!is_dir(dirname($file))) mkdir(dirname($file), 0755, true);
    file_put_contents($file, json_encode(array_values($users), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function cleanText($value) { return trim((string)$value); }

function findUserIndex($users, $email) {
    $email = strtolower(trim((string)$email));
    foreach ($users as $i => $u) {
        if (strtolower($u['email'] ?? '') === $email) return $i;
    }
    return -1;
}

$error = '';
$message = '';
$email = cleanText($_POST['email'] ?? '');

// The two-factor card used to claim "Enabled" unconditionally. Read the real
// state so it tells the truth before any script runs.
require_once __DIR__ . '/totp.php';

$secureCookie = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params(['httponly' => true, 'secure' => $secureCookie, 'samesite' => 'Lax']);
session_start();

$sessionEmail = strtolower((string)($_SESSION['client_email'] ?? ''));
if ($email === '' && $sessionEmail !== '') $email = $sessionEmail;

$twoFactor = ['enabled' => false, 'backupCodes' => [], 'confirmedAt' => ''];
$signedIn = $sessionEmail !== '';
if ($signedIn) {
    $allUsers = loadUsers($usersFile);
    $meIndex = findUserIndex($allUsers, $sessionEmail);
    if ($meIndex !== -1) $twoFactor = hx_totp_state($allUsers[$meIndex]);
}
$twoFactorOn = !empty($twoFactor['enabled']);
$backupLeft = count($twoFactor['backupCodes'] ?? []);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $users = loadUsers($usersFile);
    $idx = findUserIndex($users, $email);
    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if ($idx === -1) {
        $error = 'User not found. Please log in again.';
    } elseif (($users[$idx]['password'] ?? '') !== $currentPassword) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($newPassword) < 12) {
        $error = 'New password must be at least 12 characters.';
    } elseif (!preg_match('/[a-z]/', $newPassword) || !preg_match('/[A-Z]/', $newPassword)) {
        $error = 'New password must include uppercase and lowercase letters.';
    } elseif (!preg_match('/\d/', $newPassword)) {
        $error = 'New password must include a number.';
    } elseif (!preg_match('/[^A-Za-z0-9]/', $newPassword)) {
        $error = 'New password must include a symbol.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'New password and confirmation do not match.';
    } else {
        $users[$idx]['password'] = $newPassword;
        saveUsers($usersFile, $users);
        $message = 'Password changed successfully.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<!-- A client's own workspace. Kept out of search results here as well as in
     robots.txt: robots.txt asks a crawler not to fetch the page, this tells one
     that reached it anyway — through a shared link, say — not to index it. -->
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="dark">
<title>HarbourX · Security</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@200..800&display=swap">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@200..800&display=swap" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@200..800&display=swap"></noscript>
<script src="theme.js?v=20260919-2018"></script>
<link rel="stylesheet" href="hx-motion.css?v=20260919-2018">
<link rel="stylesheet" href="portal.css?v=20260919-2018">
<script src="hx-motion.js?v=20260919-2018" defer></script>
<script src="qrcode.min.js?v=20260919-2018" defer></script>
</head>
<body>
<div class="portal-shell">
  <aside class="portal-sidebar" id="portalSidebar">
    <a class="portal-brand" href="dashboard.html"><svg viewBox="0 0 42 42" aria-hidden="true"><path d="M6 29 21 9l15 20"/><path d="m13 33 8-11 8 11"/></svg><strong>HarbourX</strong></a>
    <nav class="portal-nav" aria-label="Primary navigation">
      <a href="dashboard.html"><i>⌂</i>Overview</a><a href="dashboard.html#portfolio"><i>◔</i>Portfolio</a><a href="dashboard.html#transactions"><i>☷</i>Transactions</a><a href="dashboard.html" data-dashboard-action="deposit"><i>↓</i>Deposit</a><a href="dashboard.html" data-dashboard-action="withdraw"><i>↑</i>Withdraw</a><a href="statements.html"><i>▤</i>Statements</a><a href="verification.html"><i>✓</i>Verification</a><a class="active" href="change_password.php"><i>◇</i>Security</a><a href="support.html"><i>?</i>Support</a>
    </nav>
  </aside>

  <div class="portal-workspace">
    <header class="portal-topbar">
      <button class="portal-menu" id="portalMenu" type="button" aria-label="Open navigation">☰</button>
      <label class="portal-search"><span>⌕</span><input type="search" placeholder="Search security settings…"></label>
      <div class="portal-user"><span class="portal-avatar" id="userInitials">HX</span><div><strong id="userName">HarbourX client</strong><small>Protected account</small></div></div>
    </header>

    <main class="portal-main">
      <div class="portal-breadcrumb"><a href="dashboard.html">Settings</a> &nbsp;›&nbsp; Security</div>
      <div class="portal-heading"><div><h1>Security</h1><p>Manage your account security and keep your HarbourX portfolio safe.</p></div><a class="portal-back" href="dashboard.html">← Back to dashboard</a></div>

      <?php if ($message): ?><div class="notice success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="notice error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

      <div class="security-layout hx-stagger">
        <section class="portal-card password-card" data-reveal>
          <div class="security-title"><span class="security-icon">▣</span><div><h2>Change password</h2><p>Update the password used to access your HarbourX account.</p></div></div>
          <div class="security-warning">▲ Changing your password protects all future sign-ins.</div>
          <form method="post" id="passwordForm">
            <input type="hidden" name="email" id="email" value="<?= htmlspecialchars($email) ?>">
            <div class="password-field"><label for="currentPassword">Current password</label><input id="currentPassword" type="password" name="current_password" autocomplete="current-password" required><button class="password-toggle" type="button" data-toggle-password="currentPassword" aria-label="Show current password">◉</button></div>
            <div class="password-field"><label for="newPassword">New password</label><input id="newPassword" type="password" name="new_password" minlength="12" autocomplete="new-password" required><button class="password-toggle" type="button" data-toggle-password="newPassword" aria-label="Show new password">◉</button></div>
            <div class="strength-wrap"><div class="strength-bars"><i></i><i></i><i></i><i></i></div><div class="strength-label" id="strengthLabel">Enter a new password</div></div>
            <div class="requirements"><div class="requirement off" data-rule="length">At least 12 characters</div><div class="requirement off" data-rule="case">Contains uppercase and lowercase letters</div><div class="requirement off" data-rule="number">Contains a number</div><div class="requirement off" data-rule="symbol">Contains a symbol</div></div>
            <div class="password-field"><label for="confirmPassword">Confirm new password</label><input id="confirmPassword" type="password" name="confirm_password" minlength="12" autocomplete="new-password" required><button class="password-toggle" type="button" data-toggle-password="confirmPassword" aria-label="Show confirmation password">◉</button></div>
            <button class="submit-primary" type="submit" data-ripple>Update password</button>
          </form>
        </section>

        <div class="security-stack hx-stagger">
          <section class="portal-card security-side-card" id="twoFactorCard" data-reveal>
            <div class="security-side-head">
              <span class="security-icon">◇</span>
              <div><h2>Two-factor authentication</h2><p>A code from your phone, on top of your password.</p></div>
              <span class="status <?= $twoFactorOn ? 'status-verified' : 'status-unverified' ?>" id="tfaStatusBadge"><?= $twoFactorOn ? '● On' : '● Off' ?></span>
            </div>

            <div class="settings-list">
              <div class="settings-row">Authenticator app<strong id="tfaAppState"><?= $twoFactorOn ? 'Enabled' : 'Not set up' ?></strong></div>
              <div class="settings-row">Backup codes<strong id="tfaBackupState"><?= $twoFactorOn ? (int)$backupLeft . ' left' : '—' ?></strong></div>
            </div>

            <div class="tfa-message" id="tfaMessage" hidden></div>

            <!-- Enrolment. Hidden until asked for; the secret is minted per attempt
                 and lives in the session until a code confirms it. -->
            <div class="tfa-setup" id="tfaSetup" hidden>
              <p class="tfa-step">1. Scan this with Google Authenticator, Authy, 1Password or any TOTP app.</p>
              <div class="tfa-qr" id="tfaQr" aria-label="Enrolment QR code"></div>
              <p class="tfa-step">Can't scan? Enter this key by hand:</p>
              <code class="tfa-secret" id="tfaSecret"></code>
              <p class="tfa-step">2. Enter the 6-digit code it shows.</p>
              <div class="tfa-confirm">
                <input id="tfaCode" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="000000" autocomplete="one-time-code">
                <button class="submit-primary" type="button" id="tfaConfirmBtn" data-ripple>Turn on</button>
              </div>
            </div>

            <!-- Shown once, immediately after enrolment. Only hashes are stored. -->
            <div class="tfa-codes" id="tfaCodes" hidden>
              <strong>Save these backup codes</strong>
              <p>Each one signs you in once if you lose your phone. They are shown only now.</p>
              <ul id="tfaCodeList"></ul>
              <button class="wide-secondary" type="button" id="tfaCopyCodes" data-ripple>Copy codes</button>
            </div>

            <!-- Turning it off needs proof of possession, same as turning it on. -->
            <div class="tfa-confirm" id="tfaDisableRow" hidden>
              <input id="tfaDisableCode" type="text" inputmode="numeric" maxlength="14" placeholder="Code or backup code" autocomplete="one-time-code">
              <button class="submit-primary danger" type="button" id="tfaDisableConfirmBtn" data-ripple>Turn off</button>
            </div>

            <button class="wide-secondary" type="button" id="tfaPrimaryBtn" data-ripple><?= $twoFactorOn ? 'Turn off two-factor authentication' : 'Set up two-factor authentication' ?></button>
            <button class="wide-secondary" type="button" id="tfaNewCodesBtn" data-ripple <?= $twoFactorOn ? '' : 'hidden' ?>>Issue new backup codes</button>
          </section>

          <section class="portal-card security-side-card" data-reveal>
            <div class="security-side-head"><span class="security-icon">▱</span><div><h2>Active sessions</h2><p>Your active HarbourX sign-ins.</p></div><span class="security-count">1</span></div>
            <div class="settings-list"><div class="settings-row"><span>▰</span><div><b>Current browser</b><small id="sessionLocation">Active now · Current session</small></div><strong>Secure</strong></div></div>
            <button class="wide-secondary" type="button" data-ripple>Manage sessions</button>
          </section>

          <section class="portal-card security-side-card" data-reveal>
            <div class="security-side-head"><span class="security-icon">◷</span><div><h2>Recent security activity</h2><p>Your latest account activity.</p></div></div>
            <div class="activity-list">
              <?php if ($message): ?><div class="activity-row"><i></i><div><strong>Password changed</strong><small>Current browser</small></div><time>Just now</time></div><?php endif; ?>
              <div class="activity-row"><i></i><div><strong>Successful sign in</strong><small>Current browser</small></div><time>Today</time></div>
              <div class="activity-row"><i></i><div><strong>Two-factor authentication enabled</strong><small>Account protection</small></div><time>Active</time></div>
            </div>
          </section>
        </div>
      </div>

      <footer class="portal-footer"><strong>HarbourX</strong><nav><a href="#terms">Terms</a><a href="#privacy">Privacy</a><a href="#risk">Risk disclosure</a><a href="support.html">Support</a></nav></footer>
    </main>
  </div>
</div>

<script>
(function () {
  try {
    const rawUser = localStorage.getItem('user');
    if (!rawUser) {
      window.location.replace('login.html');
      return;
    }
    const user = JSON.parse(rawUser);
    if (!user || !user.email) {
      localStorage.removeItem('user');
      window.location.replace('login.html');
      return;
    }
    document.getElementById('email').value = user.email;
    const name = String(user.name || 'HarbourX client').trim();
    document.getElementById('userName').textContent = name;
    document.getElementById('userInitials').textContent = name.split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]).join('').toUpperCase() || 'HX';
  } catch (e) {
    localStorage.removeItem('user');
    window.location.replace('login.html');
  }
})();

const newPassword = document.getElementById('newPassword');
const strengthBars = [...document.querySelectorAll('.strength-bars i')];
const strengthLabel = document.getElementById('strengthLabel');
const rules = {
  length: (value) => value.length >= 12,
  case: (value) => /[a-z]/.test(value) && /[A-Z]/.test(value),
  number: (value) => /\d/.test(value),
  symbol: (value) => /[^A-Za-z0-9]/.test(value)
};

function updateStrength() {
  const value = newPassword.value;
  const passed = Object.entries(rules).filter(([name, test]) => {
    const ok = test(value);
    document.querySelector(`[data-rule="${name}"]`)?.classList.toggle('off', !ok);
    return ok;
  }).length;
  strengthBars.forEach((bar, index) => bar.classList.toggle('on', index < passed));
  strengthLabel.textContent = value ? ['Very weak', 'Weak', 'Good', 'Strong'][Math.max(0, passed - 1)] : 'Enter a new password';
}
newPassword.addEventListener('input', updateStrength);
document.querySelectorAll('[data-toggle-password]').forEach((button) => button.addEventListener('click', () => {
  const input = document.getElementById(button.dataset.togglePassword);
  input.type = input.type === 'password' ? 'text' : 'password';
  button.textContent = input.type === 'password' ? '◉' : '○';
}));
document.getElementById('portalMenu').addEventListener('click', () => document.body.classList.toggle('nav-open'));

/* ---------------------------------------------------------------------------
   Two-factor enrolment

   Every decision is the server's: twofactor.php mints the secret, verifies the
   code and issues the backup codes. This only draws what it says.
   --------------------------------------------------------------------------- */
(function () {
  const card = document.getElementById('twoFactorCard');
  if (!card) return;

  const el = (id) => document.getElementById(id);
  const setup = el('tfaSetup'), codesBox = el('tfaCodes'), codeList = el('tfaCodeList');
  const disableRow = el('tfaDisableRow'), message = el('tfaMessage');
  const primaryBtn = el('tfaPrimaryBtn'), newCodesBtn = el('tfaNewCodesBtn');
  const codeInput = el('tfaCode'), qrBox = el('tfaQr'), secretBox = el('tfaSecret');
  const statusBadge = el('tfaStatusBadge'), appState = el('tfaAppState'), backupState = el('tfaBackupState');

  let enabled = statusBadge.classList.contains('status-verified');
  let issuedCodes = [];

  function say(text, tone) {
    if (!text) { message.hidden = true; return; }
    message.hidden = false;
    message.textContent = text;
    message.setAttribute('data-tone', tone || 'success');
  }

  /** Disables a button and marks it busy for the length of an await. */
  async function whileBusy(button, work) {
    if (button) { button.disabled = true; button.setAttribute('aria-busy', 'true'); }
    try {
      return await work();
    } finally {
      if (button) { button.disabled = false; button.removeAttribute('aria-busy'); }
    }
  }

  async function call(action, extra) {
    const response = await fetch('twofactor.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign({ action }, extra || {}))
    });
    if (response.status === 401) {
      window.location.replace('login.html');
      throw new Error('signed out');
    }
    return response.json();
  }

  function paint() {
    statusBadge.textContent = enabled ? '● On' : '● Off';
    statusBadge.className = 'status ' + (enabled ? 'status-verified' : 'status-unverified');
    appState.textContent = enabled ? 'Enabled' : 'Not set up';
    primaryBtn.textContent = enabled ? 'Turn off two-factor authentication' : 'Set up two-factor authentication';
    newCodesBtn.hidden = !enabled;
  }

  function drawQr(uri) {
    qrBox.innerHTML = '';
    if (typeof window.QRCode === 'undefined') {
      // The library is deferred; if it has not landed, the typed key still works.
      qrBox.textContent = '';
      qrBox.hidden = true;
      return;
    }
    qrBox.hidden = false;
    new window.QRCode(qrBox, { text: uri, width: 156, height: 156, correctLevel: window.QRCode.CorrectLevel.M });
  }

  async function beginSetup() {
    say('');
    const result = await whileBusy(primaryBtn, () => call('begin'));
    if (!result.success) { say(result.message || 'Unable to start setup.', 'error'); return; }
    secretBox.textContent = result.secretGrouped || result.secret;
    drawQr(result.uri);
    setup.hidden = false;
    codesBox.hidden = true;
    codeInput.value = '';
    codeInput.focus();
  }

  async function confirmSetup() {
    const code = codeInput.value.trim();
    if (!/^\d{6}$/.test(code)) { say('Enter the 6 digits your app is showing.', 'error'); return; }
    const result = await whileBusy(el('tfaConfirmBtn'), () => call('enable', { code }));
    if (!result.success) {
      say(result.message || 'That code is not right.', 'error');
      if (window.hxMotion) window.hxMotion.shake(codeInput);
      codeInput.select();
      return;
    }

    enabled = true;
    paint();
    setup.hidden = true;
    issuedCodes = result.backupCodes || [];
    codeList.innerHTML = '';
    issuedCodes.forEach((c) => {
      const li = document.createElement('li');
      li.textContent = c;
      codeList.appendChild(li);
    });
    backupState.textContent = issuedCodes.length + ' left';
    codesBox.hidden = false;
    say(result.message || 'Two-factor authentication is on.', 'success');
  }

  async function disable() {
    const input = el('tfaDisableCode');
    const code = input.value.trim();
    if (!code) { say('Enter a current code, or one of your backup codes.', 'error'); return; }

    const result = await whileBusy(el('tfaDisableConfirmBtn'), () => call('disable', { code }));
    if (!result.success) {
      say(result.message || 'Unable to turn it off.', 'error');
      if (window.hxMotion) window.hxMotion.shake(input);
      return;
    }
    enabled = false;
    paint();
    disableRow.hidden = true;
    codesBox.hidden = true;
    input.value = '';
    backupState.textContent = '—';
    say(result.message || 'Two-factor authentication is off.', 'success');
  }
  primaryBtn.addEventListener('click', () => {
    if (enabled) {
      disableRow.hidden = !disableRow.hidden;
      if (!disableRow.hidden) el('tfaDisableCode').focus();
      return;
    }
    setup.hidden ? beginSetup() : (setup.hidden = true);
  });
  el('tfaConfirmBtn').addEventListener('click', confirmSetup);
  el('tfaDisableConfirmBtn').addEventListener('click', disable);
  codeInput.addEventListener('input', () => {
    codeInput.value = codeInput.value.replace(/\D/g, '').slice(0, 6);
    if (codeInput.value.length === 6) confirmSetup();
  });

  newCodesBtn.addEventListener('click', async () => {
    const code = window.prompt('Enter a current code from your authenticator app to issue new backup codes:');
    if (!code) return;
    const result = await whileBusy(newCodesBtn, () => call('codes', { code: code.trim() }));
    if (!result.success) { say(result.message || 'Unable to issue new codes.', 'error'); return; }
    issuedCodes = result.backupCodes || [];
    codeList.innerHTML = '';
    issuedCodes.forEach((c) => {
      const li = document.createElement('li');
      li.textContent = c;
      codeList.appendChild(li);
    });
    backupState.textContent = issuedCodes.length + ' left';
    codesBox.hidden = false;
    say(result.message || 'New backup codes issued.', 'success');
  });
  el('tfaCopyCodes').addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(issuedCodes.join('\n'));
      say('Backup codes copied.', 'success');
    } catch (e) {
      say('Copy failed — select them and copy by hand.', 'error');
    }
  });
})();
</script>
</body>
</html>
