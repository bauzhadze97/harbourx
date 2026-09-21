<?php
require_once __DIR__ . '/common.php';

$error = '';
$message = '';

if (isset($_GET['logout'])) {
    unset($_SESSION['admin_logged_in'], $_SESSION['hx_test_csrf']);
    header('Location: admin.php');
    exit;
}

if (isset($_POST['admin_login'])) {
    if (hash_equals((string)$ADMIN_PASSWORD, (string)($_POST['admin_password'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        header('Location: admin.php');
        exit;
    }
    $error = 'Wrong admin password.';
}

if (empty($_SESSION['admin_logged_in'])) {
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><title>HarbourX Test Admin</title><link rel="stylesheet" href="test.css"></head>
<body class="hx-test"><main class="login-wrap"><section class="login-panel"><div class="test-badge">SIMULATION</div><h1>Harbcoin Test Admin</h1><p>Use the HarbourX admin password. This environment is isolated from client balances.</p><?php if ($error): ?><div class="notice error"><?= htmlspecialchars($error) ?></div><?php endif; ?><form method="post"><label>Admin password<input name="admin_password" type="password" autocomplete="current-password" required autofocus></label><button class="primary" name="admin_login" value="1">Sign in</button></form></section></main></body></html><?php
    exit;
}

$state = hxTestLoadState();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['admin_login'])) {
    if (!hxTestValidCsrf($_POST['csrf'] ?? '')) {
        http_response_code(403);
        $error = 'This form expired. Refresh and try again.';
    } elseif (isset($_POST['save_base'])) {
        $base = (float)str_replace(',', '', (string)($_POST['base_price'] ?? '0'));
        if ($base < 0.01 || $base > 1000000000) {
            $error = 'Enter a base price between A$0.01 and A$1,000,000,000.';
        } else {
            $state['basePrice'] = round($base, 2);
            $state['updatedAt'] = gmdate('c');
            $state['updatedBy'] = 'admin';
            hxTestSaveState($state);
            $message = 'Base price updated.';
        }
    } elseif (isset($_POST['start_override'])) {
        $price = (float)str_replace(',', '', (string)($_POST['override_price'] ?? '0'));
        $minutes = (int)($_POST['duration_minutes'] ?? 10);
        if ($price < 0.01 || $price > 1000000000) {
            $error = 'Enter an override price between A$0.01 and A$1,000,000,000.';
        } elseif ($minutes < 1 || $minutes > 1440) {
            $error = 'Duration must be between 1 minute and 24 hours.';
        } else {
            $state['overridePrice'] = round($price, 2);
            $state['overrideUntil'] = time() + ($minutes * 60);
            $state['updatedAt'] = gmdate('c');
            $state['updatedBy'] = 'admin';
            hxTestSaveState($state);
            $message = 'Timed price override started.';
        }
    } elseif (isset($_POST['stop_override'])) {
        $state['overridePrice'] = 0;
        $state['overrideUntil'] = 0;
        $state['updatedAt'] = gmdate('c');
        $state['updatedBy'] = 'admin';
        hxTestSaveState($state);
        $message = 'Override stopped. Harbcoin is back at its base price.';
    }
    $state = hxTestLoadState();
}

$active = hxTestOverrideActive($state);
$current = hxTestCurrentPrice($state);
$remaining = $active ? max(0, $state['overrideUntil'] - time()) : 0;
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><title>Harbcoin Price Control</title><link rel="stylesheet" href="test.css"></head>
<body class="hx-test"><header class="topbar"><a class="brand" href="admin.php">HarbourX <span>TEST</span></a><nav><a href="index.php">Open client preview</a><a href="?logout=1">Sign out</a></nav></header>
<main class="admin-shell">
  <section class="hero"><div><div class="test-badge">SIMULATED ASSET</div><h1>Harbcoin price control</h1><p>Changes here affect only the protected test page. They never touch Bitcoin, market feeds or client balances.</p></div><div class="live-price"><small>Current HBC price</small><strong><?= hxTestMoney($current) ?></strong><span class="<?= $active ? 'override' : 'base' ?>"><?= $active ? 'Timed override active' : 'Base price' ?></span></div></section>
  <?php if ($error): ?><div class="notice error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if ($message): ?><div class="notice success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
  <div class="admin-grid">
    <section class="panel"><h2>Base price</h2><p>The price Harbcoin returns to when no timed override is running.</p><form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars(hxTestCsrf()) ?>"><label>Harbcoin price (AUD)<input name="base_price" type="number" min="0.01" max="1000000000" step="0.01" value="<?= htmlspecialchars(number_format((float)$state['basePrice'], 2, '.', '')) ?>" required></label><button class="secondary" name="save_base" value="1">Save base price</button></form></section>
    <section class="panel accent"><h2>Timed price override</h2><p>Raise or lower the simulated price, then return automatically to the base price.</p><form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars(hxTestCsrf()) ?>"><div class="form-grid"><label>Temporary price (AUD)<input name="override_price" type="number" min="0.01" max="1000000000" step="0.01" value="<?= htmlspecialchars(number_format($active ? (float)$state['overridePrice'] : $current * 1.25, 2, '.', '')) ?>" required></label><label>Duration<select name="duration_minutes"><option value="5">5 minutes</option><option value="10" selected>10 minutes</option><option value="30">30 minutes</option><option value="60">1 hour</option></select></label></div><button class="primary" name="start_override" value="1">Start timed override</button></form><?php if ($active): ?><form method="post" class="stop-form"><input type="hidden" name="csrf" value="<?= htmlspecialchars(hxTestCsrf()) ?>"><button class="danger" name="stop_override" value="1">Stop override now</button></form><?php endif; ?></section>
  </div>
  <section class="panel status-panel"><h2>Simulation status</h2><div class="status-grid"><div><small>Asset</small><strong>Harbcoin (HBC)</strong></div><div><small>Mode</small><strong><?= $active ? 'Temporary override' : 'Base price' ?></strong></div><div><small>Time remaining</small><strong id="countdown" data-seconds="<?= $remaining ?>"><?= $active ? gmdate('i:s', $remaining) : '—' ?></strong></div><div><small>Last updated</small><strong><?= htmlspecialchars((string)$state['updatedAt']) ?></strong></div></div></section>
</main><script>const c=document.getElementById('countdown');if(c){let n=Number(c.dataset.seconds||0);if(n>0){const timer=setInterval(()=>{n=Math.max(0,n-1);c.textContent=n?`${Math.floor(n/60)}:${String(n%60).padStart(2,'0')}`:'Returning to base price…';if(!n){clearInterval(timer);setTimeout(()=>location.reload(),600);}},1000);}}</script></body></html>
