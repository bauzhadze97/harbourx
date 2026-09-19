<?php
require_once __DIR__ . '/admin_common.php';

$error = '';
$success = false;
$publicUser = null;
$email = strtolower(cleanText($_POST['email'] ?? ''));
$firstName = cleanText($_POST['first_name'] ?? '');
$lastName = cleanText($_POST['last_name'] ?? '');
$country = cleanCountryCode($_POST['country'] ?? 'AU') ?: 'AU';
$currency = cleanCurrency($_POST['currency'] ?? defaultCurrencyForCountry($country));

function publicRegisteredUser($user) {
    unset($user['password'], $user['aml'], $user['bankAccounts'], $user['passwordSetup']);
    $status = strtolower((string)($user['amlStatus'] ?? 'unverified'));
    $user['amlStatus'] = in_array($status, ['verified', 'under_review', 'unverified'], true)
        ? $status
        : 'unverified';
    return $user;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');
    $name = trim($firstName . ' ' . $lastName);
    $users = loadUsers($usersFile);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif ($firstName === '' || $lastName === '') {
        $error = 'First name and last name are required.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Password and confirmation do not match.';
    } elseif (findUserIndex($users, $email) !== -1) {
        $error = 'An account with this email already exists.';
    } else {
        $newUser = [
            'email' => $email,
            'password' => $password,
            'name' => $name,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'portfolioUsd' => 0,
            'btc' => 0,
            'mainBalance' => 0,
            'withdrawalFeeRequired' => false,
            'withdrawalFeeAmount' => 0,
            'withdrawalFeePercent' => 0,
            'withdrawalFeeNote' => '',
            'country' => $country,
            'currency' => $currency,
            'amlStatus' => 'unverified',
            'amlSubmittedAt' => '',
            'amlReviewedAt' => '',
            'amlReviewNote' => '',
            'bankAccounts' => [],
            'transactions' => []
        ];
        $users[] = $newUser;
        saveUsers($usersFile, $users);

        session_regenerate_id(true);
        $_SESSION['client_email'] = $email;
        $publicUser = publicRegisteredUser($newUser);
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="dark">
<title>HarbourX · Create account</title>
<script src="theme.js?v=20260919-1931"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@200..800&display=swap">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@200..800&display=swap" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@200..800&display=swap"></noscript>
<link rel="stylesheet" href="hx-motion.css?v=20260919-1931">
<link rel="stylesheet" href="auth.css?v=20260919-1931">
<script src="hx-motion.js?v=20260919-1931" defer></script>
</head>
<body>
<div class="card">
  <div class="brand">
    <div class="badge"><?= hxMark() ?></div>
    <h1>Create your account</h1>
    <p>Set up secure access to your HarbourX portfolio</p>
  </div>

  <?php if ($success): ?>
    <div class="notice success">Account created. Signing you in now...</div>
    <div class="email-box">Signed in as: <b><?= htmlspecialchars($publicUser['email'] ?? '') ?></b></div>
    <script>
      localStorage.setItem("user", JSON.stringify(<?= json_encode($publicUser, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>));
      setTimeout(() => window.location.replace("dashboard.html"), 700);
    </script>
  <?php else: ?>
    <?php if ($error): ?><div class="notice error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <p class="hint">Enter your details and choose a password. Your account will be created automatically.</p>
    <form method="post">
      <div class="field"><label>Email</label><input type="email" name="email" value="<?= htmlspecialchars($email) ?>" autocomplete="email" required></div>
      <div class="grid-2">
        <div class="field"><label>First name</label><input name="first_name" value="<?= htmlspecialchars($firstName) ?>" autocomplete="given-name" required></div>
        <div class="field"><label>Last name</label><input name="last_name" value="<?= htmlspecialchars($lastName) ?>" autocomplete="family-name" required></div>
      </div>
      <div class="grid-2">
        <div class="field"><label>Country</label><select id="registerCountry" name="country" required><?= renderCountryOptions($country) ?></select></div>
        <div class="field"><label>Currency</label><select id="registerCurrency" name="currency" required><?= renderCurrencyOptions($currency) ?></select></div>
      </div>
      <div class="field"><label>Password</label><input type="password" name="password" minlength="6" autocomplete="new-password" required></div>
      <div class="field"><label>Confirm password</label><input type="password" name="confirm_password" minlength="6" autocomplete="new-password" required></div>
      <button class="btn" type="submit">Create account and continue</button>
    </form>
    <a class="link" href="login.html">Already have an account?</a>
    <a class="link" href="./">&larr; Back to harbourx.org</a>
    <script>
      const registerCountry = document.getElementById('registerCountry');
      const registerCurrency = document.getElementById('registerCurrency');
      registerCountry.addEventListener('change', () => {
        const option = registerCountry.options[registerCountry.selectedIndex];
        if (option?.dataset.currency) registerCurrency.value = option.dataset.currency;
      });
    </script>
  <?php endif; ?>
</div>

<!-- Tidio live chat. Third-party and loaded async, so it never blocks the
     page: if code.tidio.co is slow or unreachable, everything here still
     renders and works exactly as it does without it. The widget floats
     bottom-right; the theme toggle sits top-right, so the two do not meet. -->
<script src="//code.tidio.co/1rfzzsxqktvftfto6ml44xvorkoducnj.js" async></script>
</body>
</html>
