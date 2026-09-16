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
<meta name="color-scheme" content="dark">
<title>HarbourX · Security</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Inter:wght@400..900&display=swap">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400..900&display=swap" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400..900&display=swap"></noscript>
<script src="theme.js"></script>
<link rel="stylesheet" href="hx-motion.css">
<link rel="stylesheet" href="portal.css">
<script src="hx-motion.js" defer></script>
</head>
<body>
<div class="portal-shell">
  <aside class="portal-sidebar" id="portalSidebar">
    <a class="portal-brand" href="dashboard.html"><svg viewBox="0 0 42 42" aria-hidden="true"><path d="M6 29 21 9l15 20"/><path d="m13 33 8-11 8 11"/></svg><strong>HarbourX</strong></a>
    <nav class="portal-nav" aria-label="Primary navigation">
      <a href="dashboard.html"><i>⌂</i>Overview</a><a href="dashboard.html#portfolio"><i>◔</i>Portfolio</a><a href="dashboard.html#transactions"><i>☷</i>Transactions</a><a href="dashboard.html" data-dashboard-action="deposit"><i>↓</i>Deposit</a><a href="dashboard.html" data-dashboard-action="withdraw"><i>↑</i>Withdraw</a><a href="dashboard.html#transactions"><i>▤</i>Statements</a><a class="active" href="change_password.php"><i>◇</i>Security</a><a href="mailto:support@harbourx.org"><i>?</i>Support</a>
    </nav>
    <div class="portal-rail-note"><strong>Demo account</strong><p>Security information on this page is provided for demonstration.</p></div>
  </aside>

  <div class="portal-workspace">
    <header class="portal-topbar">
      <button class="portal-menu" id="portalMenu" type="button" aria-label="Open navigation">☰</button>
      <label class="portal-search"><span>⌕</span><input type="search" placeholder="Search security settings…"></label>
      <span class="portal-demo-pill">ⓘ Demo account — sample data only.</span>
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
          <section class="portal-card security-side-card" data-reveal>
            <div class="security-side-head"><span class="security-icon">◇</span><div><h2>Two-factor authentication</h2><p>Add an extra layer of security.</p></div><span class="status status-verified">● Enabled</span></div>
            <div class="settings-list"><div class="settings-row">Authentication app<strong>Enabled</strong></div><div class="settings-row">Backup codes<strong>10 codes ›</strong></div></div>
            <button class="wide-secondary" type="button" data-ripple>Manage two-factor authentication</button>
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

      <footer class="portal-footer"><strong>HarbourX</strong><nav><a href="#terms">Terms</a><a href="#privacy">Privacy</a><a href="#risk">Risk disclosure</a><a href="mailto:support@harbourx.org">Support</a></nav></footer>
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
</script>
</body>
</html>
