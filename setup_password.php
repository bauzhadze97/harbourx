<?php
require_once __DIR__ . '/admin_common.php';

$error = '';
$success = false;
$token = cleanText($_GET['token'] ?? $_POST['token'] ?? '');
$matchedIndex = -1;
$matchedUser = null;
$publicUser = null;

function findPasswordSetupIndex($users, $token) {
    if ($token === '') return -1;
    $tokenHash = hash('sha256', $token);
    foreach ($users as $index => $user) {
        $setup = is_array($user['passwordSetup'] ?? null) ? $user['passwordSetup'] : [];
        $storedHash = (string)($setup['tokenHash'] ?? '');
        $expiresAt = (string)($setup['expiresAt'] ?? '');
        if ($storedHash === '' || !hash_equals($storedHash, $tokenHash)) continue;
        if ($expiresAt !== '' && strtotime($expiresAt) !== false && strtotime($expiresAt) < time()) return -1;
        return $index;
    }
    return -1;
}

// publicClientUser() is provided by admin_common.php

$users = loadUsers($usersFile);
$matchedIndex = findPasswordSetupIndex($users, $token);
if ($matchedIndex !== -1) {
    $matchedUser = $users[$matchedIndex];
}

if ($token === '') {
    $error = 'Password setup link is missing.';
} elseif ($matchedIndex === -1) {
    $error = 'This password setup link is invalid or expired.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if (strlen($newPassword) < 6) {
        $error = 'New password must be at least 6 characters.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'New password and confirmation do not match.';
    } else {
        $users[$matchedIndex]['password'] = $newPassword;
        unset($users[$matchedIndex]['passwordSetup']);
        saveUsers($usersFile, $users);

        session_regenerate_id(true);
        $_SESSION['client_email'] = strtolower((string)($users[$matchedIndex]['email'] ?? ''));
        $publicUser = publicClientUser($users[$matchedIndex]);
        $success = true;
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
<title>HarbourX · Set up password</title>
<script src="theme.js?v=20260919-1948"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@200..800&display=swap">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@200..800&display=swap" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@200..800&display=swap"></noscript>
<link rel="stylesheet" href="hx-motion.css?v=20260919-1948">
<link rel="stylesheet" href="auth.css?v=20260919-1948">
<script src="hx-motion.js?v=20260919-1948" defer></script>
</head>
<body>
<div class="card">
  <div class="brand">
    <div class="badge"><?= hxMark() ?></div>
    <h1>Set up your password</h1>
    <p>Secure access to your HarbourX portfolio</p>
  </div>

  <?php if ($success): ?>
    <div class="notice success">Password saved. Signing you in now...</div>
    <div class="email-box">Signed in as: <b><?= htmlspecialchars($publicUser['email'] ?? '') ?></b></div>
    <script>
      localStorage.setItem("user", JSON.stringify(<?= json_encode($publicUser, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>));
      setTimeout(() => window.location.replace("dashboard.html"), 700);
    </script>
  <?php else: ?>
    <?php if ($error): ?><div class="notice error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($matchedUser): ?>
      <div class="email-box">Account email: <b><?= htmlspecialchars($matchedUser['email'] ?? '') ?></b></div>
      <p class="hint">Create a new password for this account. After saving, you will be signed in automatically.</p>
      <form method="post">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <div class="field"><label>New password</label><input type="password" name="new_password" minlength="6" required></div>
        <div class="field"><label>Confirm new password</label><input type="password" name="confirm_password" minlength="6" required></div>
        <button class="btn" type="submit">Save password and continue</button>
      </form>
    <?php else: ?>
      <a class="link" href="login.html">Back to login</a>
    <?php endif; ?>
  <?php endif; ?>
</div>
</body>
</html>
