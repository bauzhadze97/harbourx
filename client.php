<?php
require_once __DIR__ . '/admin_common.php';
requireAdmin();

$users = loadUsers($usersFile);
$email = cleanText($_GET['email'] ?? $_POST['original_email'] ?? '');
$idx = findUserIndex($users, $email);
$error = '';
$message = cleanText($_GET['msg'] ?? '');
$generatedSetupLink = '';

function passwordSetupBaseUrl() {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = ($dir === '/' || $dir === '.') ? '' : rtrim($dir, '/');
    return $scheme . '://' . $host . $dir;
}

function passwordSetupLink($token) {
    return passwordSetupBaseUrl() . '/setup_password.php?token=' . urlencode($token);
}

if ($idx === -1) {
    pageHeader('Client not found'); pageTop('clients');
    echo '<div class="notice error">Client not found.</div><a class="btn btn-light" href="admin.php">Back to clients</a>';
    pageFooter(); exit;
}
ensureTransactions($users[$idx]);

if (isset($_POST['login_as_client'])) {
    $_SESSION['client_email'] = strtolower((string)$users[$idx]['email']);
    $publicUser = publicClientUser($users[$idx]);
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="en"><head><meta charset="UTF-8"><title>Opening client dashboard…</title>
    <style>body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:Arial,Helvetica,sans-serif;background:#f4f8f8;color:#0b2545}
    .box{background:#fff;border:1px solid #dce8e8;border-radius:20px;padding:26px 32px;box-shadow:0 12px 32px rgba(16,24,40,.08);text-align:center}
    .badge{width:48px;height:48px;margin:0 auto 12px;border-radius:50%;display:grid;place-items:center;background:linear-gradient(135deg,#0b2545,#0f8b8d);color:#fff;font-weight:800}</style>
    </head><body><div class="box"><div class="badge">HX</div>
    <p>Opening the dashboard for <b><?= htmlspecialchars($users[$idx]['email']) ?></b>…</p></div>
    <script>
      localStorage.setItem("user", JSON.stringify(<?= json_encode($publicUser, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>));
      window.location.replace("dashboard.html");
    </script>
    </body></html>
    <?php
    exit;
}

if (isset($_POST['update_aml_status'])) {
    $newStatus = strtolower(cleanText($_POST['aml_status'] ?? 'unverified'));
    $allowedStatuses = ['verified', 'under_review', 'unverified'];
    if (!in_array($newStatus, $allowedStatuses, true)) {
        $error = 'Invalid AML status.';
    } elseif (in_array($newStatus, ['verified', 'under_review'], true)
        && empty($users[$idx]['aml'])
        && empty($users[$idx]['verificationDocuments'])) {
        // Either route counts: the detail form, or a document sent from the
        // verification page. Requiring the form would strand a client who
        // proved themselves the other way.
        $error = 'The client must submit AML details or an identity document before review or verification.';
    } else {
        $users[$idx]['amlStatus'] = $newStatus;
        $users[$idx]['amlReviewNote'] = cleanText($_POST['aml_review_note'] ?? '');
        $users[$idx]['amlReviewedAt'] = gmdate('c');
        saveUsers($usersFile, $users);
        header('Location: client.php?email=' . urlencode($users[$idx]['email']) . '&msg=' . urlencode('AML status updated successfully.'));
        exit;
    }
}

if (isset($_POST['review_document'])) {
    $documentId = cleanText($_POST['document_id'] ?? '');
    $decision = strtolower(cleanText($_POST['document_decision'] ?? ''));
    $documents = is_array($users[$idx]['verificationDocuments'] ?? null) ? array_values($users[$idx]['verificationDocuments']) : [];

    if (!in_array($decision, ['accepted', 'rejected', 'received'], true)) {
        $error = 'Choose a decision for that document.';
    } else {
        $touched = false;
        foreach ($documents as $position => $document) {
            if (!hash_equals((string)($document['id'] ?? ''), $documentId)) continue;
            $documents[$position]['status'] = $decision;
            $documents[$position]['reviewedAt'] = gmdate('c');
            $touched = true;
            break;
        }
        if (!$touched) {
            $error = 'That document is not on this client\'s account.';
        } else {
            $users[$idx]['verificationDocuments'] = $documents;
            saveUsers($usersFile, $users);
            header('Location: client.php?email=' . urlencode($users[$idx]['email']) . '&msg=' . urlencode('Document marked ' . $decision . '.'));
            exit;
        }
    }
}

if (isset($_POST['delete_document'])) {
    $documentId = cleanText($_POST['document_id'] ?? '');
    $documents = is_array($users[$idx]['verificationDocuments'] ?? null) ? array_values($users[$idx]['verificationDocuments']) : [];
    $kept = [];
    $removed = null;
    foreach ($documents as $document) {
        if ($removed === null && hash_equals((string)($document['id'] ?? ''), $documentId)) {
            $removed = $document;
            continue;
        }
        $kept[] = $document;
    }
    if ($removed === null) {
        $error = 'That document is not on this client\'s account.';
    } else {
        $stored = __DIR__ . '/uploads/documents/' . (string)$removed['id'] . '.pdf';
        if (is_file($stored)) @unlink($stored);
        $users[$idx]['verificationDocuments'] = $kept;
        saveUsers($usersFile, $users);
        header('Location: client.php?email=' . urlencode($users[$idx]['email']) . '&msg=' . urlencode('Document deleted.'));
        exit;
    }
}

if (isset($_POST['update_btc_wallet'])) {
    $btcWalletAddress = cleanBtcAddress($_POST['btc_wallet_address'] ?? '');
    if ($btcWalletAddress !== '' && !isValidBtcAddress($btcWalletAddress)) {
        $error = 'Enter a valid Bitcoin wallet address, or leave it blank to remove it.';
    } else {
        $users[$idx]['btcWalletAddress'] = $btcWalletAddress;
        saveUsers($usersFile, $users);
        header('Location: client.php?email=' . urlencode($users[$idx]['email']) . '&msg=' . urlencode(
            $btcWalletAddress === '' ? 'Bitcoin wallet address removed.' : 'Bitcoin wallet address saved.'
        ));
        exit;
    }
}

if (isset($_POST['update_withdrawal_fee'])) {
    $users[$idx]['withdrawalFeeRequired'] = !empty($_POST['withdrawal_fee_required']);
    $users[$idx]['withdrawalFeeAmount'] = round(max(0, cleanNumber($_POST['withdrawal_fee_amount'] ?? 0)), 2);
    $users[$idx]['withdrawalFeePercent'] = round(max(0, cleanNumber($_POST['withdrawal_fee_percent'] ?? 0)), 4);
    $users[$idx]['withdrawalFeeNote'] = cleanText($_POST['withdrawal_fee_note'] ?? '');

    // Marking the fee received is what releases the next withdrawal, so record
    // when it was marked as well as that it was.
    $wasPaid = !empty($users[$idx]['withdrawalFeePaid']);
    $nowPaid = !empty($_POST['withdrawal_fee_paid']);
    $users[$idx]['withdrawalFeePaid'] = $nowPaid;
    if ($nowPaid && !$wasPaid) {
        $users[$idx]['withdrawalFeePaidAt'] = date('c');
    } elseif (!$nowPaid) {
        $users[$idx]['withdrawalFeePaidAt'] = '';
    }

    saveUsers($usersFile, $users);
    header('Location: client.php?email=' . urlencode($users[$idx]['email']) . '&msg=' . urlencode('Withdrawal fee settings updated.'));
    exit;
}

if (isset($_POST['update_user'])) {
    $newEmail = cleanText($_POST['email'] ?? '');
    $duplicate = findUserIndex($users, $newEmail);
    if (!$newEmail || !cleanText($_POST['name'] ?? '') || !cleanText($_POST['password'] ?? '') || !cleanCountryCode($_POST['country'] ?? '')) {
        $error = 'Name, email, password, and country are required.';
    } elseif ($duplicate !== -1 && $duplicate !== $idx) {
        $error = 'Another client already has this email.';
    } else {
        $users[$idx]['email'] = $newEmail;
        $users[$idx]['password'] = cleanText($_POST['password'] ?? '');
        $users[$idx]['name'] = cleanText($_POST['name'] ?? '');
        $users[$idx]['portfolioUsd'] = 0;
        /* Every asset in the table, through the one accessor — which keeps
           BTC in the top-level field the rest of the app reads it from and
           puts the others under holdings. */
        foreach (hx_asset_symbols() as $assetSymbol) {
            // Absent means "not on this form", not "set it to zero".
            if (!array_key_exists('holding_' . $assetSymbol, $_POST)) continue;
            hx_asset_set_balance(
                $users[$idx],
                $assetSymbol,
                cleanNumber($_POST['holding_' . $assetSymbol] ?? 0)
            );
        }
        $users[$idx]['mainBalance'] = round(cleanNumber($_POST['main_balance'] ?? 0), 2);
        $users[$idx]['country'] = cleanCountryCode($_POST['country'] ?? '');
        $users[$idx]['currency'] = cleanCurrency($_POST['currency'] ?? defaultCurrencyForCountry($users[$idx]['country']));
        ensureTransactions($users[$idx]);
        saveUsers($usersFile, $users);
        header('Location: client.php?email=' . urlencode($newEmail) . '&msg=' . urlencode('Client updated successfully.'));
        exit;
    }
}

if (isset($_POST['generate_password_setup_link'])) {
    $token = bin2hex(random_bytes(32));
    $users[$idx]['passwordSetup'] = [
        'tokenHash' => hash('sha256', $token),
        'createdAt' => gmdate('c'),
        'expiresAt' => gmdate('c', time() + 7 * 24 * 60 * 60)
    ];
    saveUsers($usersFile, $users);
    header('Location: client.php?email=' . urlencode($users[$idx]['email']) . '&setup_token=' . urlencode($token) . '&msg=' . urlencode('Password setup link generated.'));
    exit;
}

if (isset($_POST['delete_user'])) {
    $deleteEmail = strtolower(cleanText($_POST['delete_email'] ?? ''));
    $users = array_values(array_filter($users, function($u) use ($deleteEmail) {
        return strtolower($u['email'] ?? '') !== $deleteEmail;
    }));
    saveUsers($usersFile, $users);
    header('Location: admin.php?msg=' . urlencode('Client deleted successfully.'));
    exit;
}

if (isset($_POST['delete_bank_account'])) {
    $bankId = cleanText($_POST['bank_account_id'] ?? '');
    $bankIndex = isset($_POST['bank_account_index']) ? (int)$_POST['bank_account_index'] : -1;
    $bankAccounts = is_array($users[$idx]['bankAccounts'] ?? null) ? $users[$idx]['bankAccounts'] : [];
    $beforeCount = count($bankAccounts);

    if ($bankId !== '') {
        $bankAccounts = array_values(array_filter($bankAccounts, function($bank) use ($bankId) {
            return (string)($bank['id'] ?? '') !== $bankId;
        }));
    } elseif ($bankIndex >= 0 && isset($bankAccounts[$bankIndex])) {
        unset($bankAccounts[$bankIndex]);
        $bankAccounts = array_values($bankAccounts);
    }

    if (count($bankAccounts) === $beforeCount) {
        $error = 'Connected payout bank not found.';
    } else {
        $users[$idx]['bankAccounts'] = $bankAccounts;
        saveUsers($usersFile, $users);
        header('Location: client.php?email=' . urlencode($users[$idx]['email']) . '&msg=' . urlencode('Connected payout bank removed. The client must add it again before withdrawing.'));
        exit;
    }
}

$users = loadUsers($usersFile);
$idx = findUserIndex($users, cleanText($_GET['email'] ?? $_POST['email'] ?? $email));
$u = $users[$idx];
$setupToken = cleanText($_GET['setup_token'] ?? '');
if ($setupToken !== '') {
    $generatedSetupLink = passwordSetupLink($setupToken);
}
$txCount = count($u['transactions'] ?? []);
$withdrawalAuthorisations = array_values(array_filter(
    (is_array($u['transactions'] ?? null) ? $u['transactions'] : []),
    function($tx) {
        return trim((string)($tx['withdrawalAuthorisationFirstName'] ?? '')) !== '';
    }
));
usort($withdrawalAuthorisations, function($a, $b) {
    return strcmp(
        (string)($b['withdrawalAuthorisedAt'] ?? $b['date'] ?? ''),
        (string)($a['withdrawalAuthorisedAt'] ?? $a['date'] ?? '')
    );
});

pageHeader('Edit Client');
pageTop('clients');
if ($message) echo '<div class="notice success">' . htmlspecialchars($message) . '</div>';
if ($error) echo '<div class="notice error">' . htmlspecialchars($error) . '</div>';
?>

<div class="card">
  <div class="profile-head">
    <div class="profile-left">
      <div class="profile-avatar"><?= htmlspecialchars(initials($u['name'] ?? '')) ?></div>
      <div>
        <h2 style="margin-bottom:4px"><?= htmlspecialchars($u['name'] ?? '') ?></h2>
        <div class="hint"><?= htmlspecialchars($u['email'] ?? '') ?></div>
        <span class="aml-badge aml-<?= htmlspecialchars(amlStatus($u)) ?>"><?= htmlspecialchars(amlStatusLabel(amlStatus($u))) ?></span>
      </div>
    </div>
    <div class="actions">
      <a class="btn btn-light" href="admin.php">Back to clients</a>
      <a class="btn btn-blue" href="transactions.php?email=<?= urlencode($u['email'] ?? '') ?>">Manage Transactions</a>
      <a class="btn btn-light" href="payments.php?client=<?= urlencode(strtolower($u['email'] ?? '')) ?>">Payment Schedule</a>
      <a class="btn btn-light" href="callbacks.php?client=<?= urlencode(strtolower($u['email'] ?? '')) ?>">Schedule Callback</a>
    </div>
  </div>
</div>

<div class="grid-2">
  <div class="card">
    <h2>Edit client profile</h2>
    <form method="post">
      <input type="hidden" name="original_email" value="<?= htmlspecialchars($u['email'] ?? '') ?>">
      <div class="grid-2">
        <div class="field"><label>Name</label><input name="name" value="<?= htmlspecialchars($u['name'] ?? '') ?>" required></div>
        <div class="field"><label>Email</label><input type="email" name="email" value="<?= htmlspecialchars($u['email'] ?? '') ?>" required></div>
        <div class="field"><label>Password</label><input name="password" value="<?= htmlspecialchars($u['password'] ?? '') ?>" required></div>
        <div class="field"><label>Main balance (<?= htmlspecialchars(cleanCurrency($u['currency'] ?? 'USD')) ?>)</label><input name="main_balance" type="number" step="0.01" value="<?= htmlspecialchars(number_format(clientMainBalance($u), 2, '.', '')) ?>"></div>
        <div class="field"><label>Client country</label><select name="country" id="clientCountry" required><?= renderCountryOptions($u['country'] ?? '') ?></select></div>
        <div class="field"><label>Main currency</label><select name="currency" id="clientCurrency" required><?= renderCurrencyOptions(cleanCurrency($u['currency'] ?? 'USD')) ?></select></div>
      </div>

      <h3 style="margin:22px 0 6px">Crypto holdings</h3>
      <p class="hint" style="margin:0 0 12px">What this client holds of each asset. A balance of zero hides the asset from their allocation and from the send dialog.</p>
      <div class="grid-2">
        <?php foreach (hx_asset_symbols() as $assetSymbol): ?>
          <?php $assetRow = hx_asset($assetSymbol); ?>
          <div class="field">
            <label><?= htmlspecialchars($assetRow['name']) ?> (<?= htmlspecialchars($assetSymbol) ?>)</label>
            <input name="holding_<?= htmlspecialchars($assetSymbol) ?>"
                   type="number"
                   step="<?= htmlspecialchars(rtrim(rtrim(number_format(1 / (10 ** $assetRow['decimals']), $assetRow['decimals'], '.', ''), '0'), '.') ?: '1') ?>"
                   min="0"
                   value="<?= htmlspecialchars(hx_asset_format(hx_asset_balance($u, $assetSymbol), $assetSymbol)) ?>">
          </div>
        <?php endforeach; ?>
      </div>

      <br>
      <button class="btn btn-blue" name="update_user">Save client</button>
    </form>
  </div>

  <div class="card">
    <h2>Client summary</h2>
    <div class="stats" style="grid-template-columns:1fr 1fr">
      <div class="stat"><small>Country</small><strong><?= htmlspecialchars(countryName($u['country'] ?? '') ?: 'Not set') ?></strong></div>
      <div class="stat"><small>Main Currency</small><strong><?= htmlspecialchars(cleanCurrency($u['currency'] ?? 'USD')) ?></strong></div>
      <div class="stat"><small>Main Balance</small><strong><?= htmlspecialchars(formatMoney(clientMainBalance($u), $u['currency'] ?? 'USD')) ?></strong></div>
      <?php foreach (hx_asset_holdings($u) ?: ['BTC' => 0.0] as $heldSymbol => $heldAmount): ?>
        <div class="stat"><small><?= htmlspecialchars($heldSymbol) ?></small><strong><?= htmlspecialchars(hx_asset_format($heldAmount, $heldSymbol)) ?></strong></div>
      <?php endforeach; ?>
      <?php $feeCfg = withdrawalFeeConfig($u); ?>
      <div class="stat"><small>Withdrawal Fee</small><strong><?= $feeCfg['required']
        ? htmlspecialchars((trim(($feeCfg['amount'] > 0 ? formatMoney($feeCfg['amount'], $u['currency'] ?? 'USD') : '') . ' ' . ($feeCfg['percent'] > 0 ? '+' . rtrim(rtrim(number_format($feeCfg['percent'], 4, '.', ''), '0'), '.') . '%' : '')) ?: 'Required') . ($feeCfg['paid'] ? ' · received' : ' · held'))
        : 'Off' ?></strong></div>
      <div class="stat"><small>Transactions</small><strong><?= $txCount ?></strong></div>
    </div>
    <br>
    <div class="actions" style="flex-wrap:nowrap">
      <a class="btn btn-light" href="transactions.php?email=<?= urlencode($u['email'] ?? '') ?>" style="flex:1">Open transaction page</a>
      <form method="post" target="_blank" style="flex:1;display:flex">
        <input type="hidden" name="original_email" value="<?= htmlspecialchars($u['email'] ?? '') ?>">
        <button class="btn btn-blue" name="login_as_client" type="submit" style="width:100%">Log in as this client &rarr;</button>
      </form>
    </div>
    <p class="hint" style="margin-top:8px">Opens this client's dashboard in a new tab, signed in as them. Close that tab when done; using its Logout button also ends your admin session.</p>
    <br>
    <div class="danger-row" style="border-color:var(--line);background:var(--panel-3)">
      <div>
        <b>Password setup link</b>
        <div class="hint">Create a one-time URL that lets this client set a new password and enter the dashboard automatically.</div>
      </div>
      <form method="post">
        <input type="hidden" name="original_email" value="<?= htmlspecialchars($u['email'] ?? '') ?>">
        <button class="btn btn-blue" name="generate_password_setup_link" type="submit">Generate link</button>
      </form>
    </div>
    <?php if ($generatedSetupLink): ?>
      <div class="field" style="margin-top:12px">
        <label>Client password setup URL</label>
        <input id="passwordSetupLink" value="<?= htmlspecialchars($generatedSetupLink) ?>" readonly onclick="this.select()">
      </div>
      <button class="btn btn-light" type="button" onclick="navigator.clipboard.writeText(document.getElementById('passwordSetupLink').value); this.textContent='Copied link';">Copy setup link</button>
      <br><br>
    <?php endif; ?>
    <?php if (!empty($u['passwordSetup']['expiresAt'])): ?>
      <div class="hint">Latest setup link expires: <?= htmlspecialchars($u['passwordSetup']['expiresAt']) ?></div>
      <br>
    <?php endif; ?>
    <div class="danger-row">
      <div><b>Delete client</b><div class="hint">This removes the client and all transactions.</div></div>
      <form method="post" onsubmit="return confirm('Delete this client permanently?')">
        <input type="hidden" name="delete_email" value="<?= htmlspecialchars($u['email'] ?? '') ?>">
        <button class="btn btn-red" name="delete_user">Delete</button>
      </form>
    </div>
  </div>
</div>

<?php $btcWallet = trim((string)($u['btcWalletAddress'] ?? '')); ?>
<div class="card">
  <div class="section-title">
    <div>
      <h2>Bitcoin wallet address</h2>
      <p class="hint" style="margin:0">Shown to this client on their dashboard under &ldquo;My Wallet&rdquo;, with a scannable QR code. Leave blank if the client has no wallet address on file.</p>
    </div>
    <span class="aml-badge <?= $btcWallet !== '' ? 'aml-verified' : 'badge-muted' ?>"><?= $btcWallet !== '' ? 'Address on file' : 'No address' ?></span>
  </div>

  <div class="grid-2">
    <form method="post">
      <input type="hidden" name="original_email" value="<?= htmlspecialchars($u['email'] ?? '') ?>">
      <div class="field">
        <label>BTC wallet address</label>
        <input name="btc_wallet_address" id="btcWalletInput" value="<?= htmlspecialchars($btcWallet) ?>" placeholder="bc1... or 1... / 3..." autocapitalize="off" autocorrect="off" spellcheck="false">
        <span class="hint">Legacy (1.../3...) or native SegWit / Taproot (bc1...) addresses are accepted. Bech32 addresses are stored in lower case.</span>
      </div>
      <button class="btn btn-blue" name="update_btc_wallet">Save wallet address</button>
    </form>

    <div class="field">
      <label>QR preview</label>
      <div id="btcWalletQrPreview" style="display:inline-flex;padding:12px;background:#fff;border:1px solid var(--line);border-radius:16px;min-width:172px;min-height:172px;align-items:center;justify-content:center;color:#64748b;font-size:13px;text-align:center">
        Enter an address to preview its QR code
      </div>
      <style>#btcWalletQrPreview img,#btcWalletQrPreview canvas{width:148px;height:148px;display:block;image-rendering:-webkit-optimize-contrast;image-rendering:pixelated}</style>
    </div>
  </div>
</div>
<script src="qrcode.min.js?v=20260921-1056"></script>
<script>
  (function () {
    var input = document.getElementById('btcWalletInput');
    var box = document.getElementById('btcWalletQrPreview');
    if (!input || !box) return;
    var placeholder = 'Enter an address to preview its QR code';

    function looksLikeBtc(value) {
      var a = value.trim();
      if (/^[13][a-km-zA-HJ-NP-Z1-9]{25,34}$/.test(a)) return true;
      if (/^bc1[ac-hj-np-z02-9]{11,87}$/.test(a.toLowerCase())) return true;
      return false;
    }

    function render() {
      var value = input.value.trim();
      box.innerHTML = '';
      if (value === '') { box.textContent = placeholder; return; }
      if (!looksLikeBtc(value)) { box.textContent = 'Not a recognised Bitcoin address'; return; }
      try {
        new QRCode(box, {
          text: value,
          width: 444,
          height: 444,
          colorDark: '#0b1f33',
          colorLight: '#ffffff',
          correctLevel: QRCode.CorrectLevel.M
        });
      } catch (e) {
        box.textContent = 'Unable to render QR code';
      }
    }

    input.addEventListener('input', render);
    render();
  })();
</script>

<?php
  $clientDocuments = is_array($u['verificationDocuments'] ?? null) ? array_values($u['verificationDocuments']) : [];
  $clientEmailLower = strtolower((string)($u['email'] ?? ''));
  $verificationSessions = [];
  foreach (hxFollowupLoad(__DIR__ . '/data/client_callbacks.json') as $record) {
      if (strtolower((string)($record['clientEmail'] ?? '')) !== $clientEmailLower) continue;
      if ((string)($record['subject'] ?? '') !== 'Screen-share verification session') continue;
      $verificationSessions[] = $record;
  }
  usort($verificationSessions, fn($a, $b) => strcmp((string)($b['scheduledAt'] ?? ''), (string)($a['scheduledAt'] ?? '')));
?>
<div class="card">
  <div class="section-title">
    <div>
      <h2>Identity documents</h2>
      <p class="hint" style="margin:0">PDFs this client sent from the verification page. They are stored outside the web root and are only ever served through verification.php, to this console or to the client who uploaded them.</p>
    </div>
    <span class="aml-badge <?= $clientDocuments ? 'aml-under_review' : 'aml-unverified' ?>"><?= count($clientDocuments) ?> document<?= count($clientDocuments) === 1 ? '' : 's' ?></span>
  </div>

  <?php if (!$clientDocuments): ?>
    <p class="hint">Nothing uploaded yet.</p>
  <?php else: ?>
    <?php foreach ($clientDocuments as $document):
      $docStatus = strtolower((string)($document['status'] ?? 'received'));
      $docId = (string)($document['id'] ?? '');
      $sizeKb = max(1, (int)round(((int)($document['size'] ?? 0)) / 1024));
    ?>
      <div class="danger-row" style="border-color:var(--line);background:var(--panel-3);align-items:flex-start;flex-wrap:wrap;gap:12px">
        <div style="flex:1;min-width:230px">
          <b style="overflow-wrap:anywhere"><?= htmlspecialchars((string)($document['name'] ?? 'document.pdf')) ?></b>
          <div class="hint">
            <?= $sizeKb >= 1024 ? htmlspecialchars(number_format($sizeKb / 1024, 1)) . ' MB' : htmlspecialchars((string)$sizeKb) . ' KB' ?>
            · uploaded <?= htmlspecialchars(($document['uploadedAt'] ?? '') !== '' ? date('j M Y, H:i', strtotime((string)$document['uploadedAt'])) : 'unknown') ?>
            · <b><?= htmlspecialchars($docStatus) ?></b>
          </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <a class="btn btn-light" href="verification.php?file=<?= urlencode($docId) ?>">Download</a>
          <form method="post" style="display:flex;gap:8px">
            <input type="hidden" name="original_email" value="<?= htmlspecialchars((string)($u['email'] ?? '')) ?>">
            <input type="hidden" name="document_id" value="<?= htmlspecialchars($docId) ?>">
            <select name="document_decision" style="min-width:130px">
              <option value="received" <?= $docStatus === 'received' ? 'selected' : '' ?>>Received</option>
              <option value="accepted" <?= $docStatus === 'accepted' ? 'selected' : '' ?>>Accepted</option>
              <option value="rejected" <?= $docStatus === 'rejected' ? 'selected' : '' ?>>Rejected</option>
            </select>
            <button class="btn btn-blue" name="review_document">Save</button>
          </form>
          <form method="post" onsubmit="return confirm('Delete this document? The file is removed from the server and cannot be recovered.')">
            <input type="hidden" name="original_email" value="<?= htmlspecialchars((string)($u['email'] ?? '')) ?>">
            <input type="hidden" name="document_id" value="<?= htmlspecialchars($docId) ?>">
            <button class="btn btn-light" name="delete_document">Delete</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
    <p class="hint" style="margin-top:12px">Marking a document <b>Accepted</b> stops the client removing it, because it is part of the record from that point on. It does not change their AML status — do that in the card below.</p>
  <?php endif; ?>

  <?php if ($verificationSessions): ?>
    <br>
    <h2 style="font-size:15px;margin:0 0 4px">Screen-share sessions</h2>
    <p class="hint" style="margin-top:0">Requested by this client from the verification page. They are ordinary callbacks, so they also appear in the Callbacks console.</p>
    <?php foreach (array_slice($verificationSessions, 0, 5) as $session):
      $sessionStatus = hxFollowupEffectiveStatus($session);
    ?>
      <div class="danger-row" style="border-color:var(--line);background:var(--panel-3)">
        <div>
          <b><?= htmlspecialchars(hxFollowupDisplayTime((string)($session['scheduledAt'] ?? ''), HX_ADMIN_TIMEZONE)) ?></b>
          <div class="hint"><?= htmlspecialchars((string)($session['notes'] ?? '')) ?: 'No note from the client.' ?></div>
        </div>
        <span class="aml-badge aml-<?= $sessionStatus === 'completed' ? 'verified' : ($sessionStatus === 'cancelled' ? 'unverified' : 'under_review') ?>"><?= htmlspecialchars(hxFollowupStatusLabel($sessionStatus)) ?></span>
      </div>
    <?php endforeach; ?>
    <p class="hint" style="margin-top:10px"><a href="callbacks.php?client=<?= urlencode($clientEmailLower) ?>">Open this client in the Callbacks console →</a></p>
  <?php endif; ?>
</div>

<div class="card">
  <div class="section-title">
    <div>
      <h2>AML / KYC review</h2>
      <p class="hint" style="margin:0">Bank withdrawals are available only when this client is marked Verified.</p>
    </div>
    <span class="aml-badge aml-<?= htmlspecialchars(amlStatus($u)) ?>"><?= htmlspecialchars(amlStatusLabel(amlStatus($u))) ?></span>
  </div>

  <form method="post">
    <input type="hidden" name="original_email" value="<?= htmlspecialchars($u['email'] ?? '') ?>">
    <div class="grid-2">
      <div class="field">
        <label>AML status</label>
        <select name="aml_status">
          <option value="unverified" <?= amlStatus($u) === 'unverified' ? 'selected' : '' ?>>Unverified</option>
          <option value="under_review" <?= amlStatus($u) === 'under_review' ? 'selected' : '' ?>>Under review</option>
          <option value="verified" <?= amlStatus($u) === 'verified' ? 'selected' : '' ?>>Verified</option>
        </select>
      </div>
      <div class="field">
        <label>Review note shown to client</label>
        <textarea name="aml_review_note" rows="3" placeholder="Reason for rejection or instructions"><?= htmlspecialchars($u['amlReviewNote'] ?? '') ?></textarea>
      </div>
    </div>
    <button class="btn btn-blue" name="update_aml_status">Save AML decision</button>
  </form>

  <?php if (!empty($u['aml']) && is_array($u['aml'])): ?>
    <?php
      $aml = $u['aml'];
      $amlFields = [
        'Legal name' => trim(($aml['title'] ?? '') . ' ' . ($aml['legalFirstName'] ?? '') . ' ' . ($aml['middleNames'] ?? '') . ' ' . ($aml['legalLastName'] ?? '')),
        'Date of birth' => $aml['dateOfBirth'] ?? '',
        'Gender' => $aml['gender'] ?? '',
        'Mobile' => $aml['mobileNumber'] ?? '',
        'Preferred 2FA' => $aml['preferred2fa'] ?? '',
        'Address' => trim(($aml['streetAddress'] ?? '') . ', ' . ($aml['suburb'] ?? '') . ', ' . ($aml['state'] ?? '') . ' ' . ($aml['postcode'] ?? '') . ', ' . ($aml['country'] ?? '')),
        'ID type' => $aml['idType'] ?? '',
        'Document number' => $aml['documentNumber'] ?? '',
        'Issuing country' => $aml['issuingCountry'] ?? '',
        'Expiry date' => $aml['expiryDate'] ?? '',
        'Tax reference' => $aml['taxReference'] ?? '',
        'Business number' => $aml['businessNumber'] ?? '',
        'Tax residency' => $aml['taxResidency'] ?? '',
        'Other tax residencies' => $aml['additionalTaxResidencies'] ?? '',
        'Source of funds' => $aml['sourceOfFunds'] ?? '',
        'Expected volume' => $aml['expectedVolume'] ?? '',
        'Politically exposed person' => !empty($aml['isPep']) ? 'Yes' : 'No',
        'Sanctions declaration' => !empty($aml['sanctionsConfirmed']) ? 'Confirmed' : 'Not confirmed',
        'Submitted' => $u['amlSubmittedAt'] ?? ''
      ];
    ?>
    <div class="aml-details">
      <?php foreach ($amlFields as $label => $value): ?>
        <div class="aml-detail"><small><?= htmlspecialchars($label) ?></small><strong><?= htmlspecialchars($value !== '' ? $value : 'Not supplied') ?></strong></div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="empty" style="margin-top:16px">This client has not submitted AML details yet.</div>
  <?php endif; ?>
</div>

<?php $feeCfg = withdrawalFeeConfig($u); $feeCurrency = cleanCurrency($u['currency'] ?? 'USD'); ?>
<div class="card">
  <div class="section-title">
    <div>
      <h2>Withdrawal fee</h2>
      <p class="hint" style="margin:0">When enabled, this client must pay a fee before a bank withdrawal is submitted. When disabled, no fee step is shown to the client.</p>
    </div>
    <span class="aml-badge <?= !$feeCfg['required'] ? 'aml-unverified' : ($feeCfg['paid'] ? 'aml-verified' : 'aml-under_review') ?>"><?= !$feeCfg['required'] ? 'No fee' : ($feeCfg['paid'] ? 'Fee received — will release' : 'Awaiting fee — withdrawals held') ?></span>
  </div>

  <form method="post">
    <input type="hidden" name="original_email" value="<?= htmlspecialchars($u['email'] ?? '') ?>">
    <label class="danger-row" style="border-color:var(--line);background:var(--panel-3);cursor:pointer">
      <span><b>Require a fee before this client's withdrawal is released</b>
        <span class="hint">Leave unchecked to send this client straight through the withdrawal flow with no fee.</span></span>
      <input type="checkbox" name="withdrawal_fee_required" value="1" <?= $feeCfg['required'] ? 'checked' : '' ?> style="width:22px;height:22px;flex:0 0 auto">
    </label>

    <label class="danger-row" style="margin-top:10px;border-color:<?= $feeCfg['paid'] ? 'var(--green)' : 'var(--amber, var(--line))' ?>;background:var(--panel-3);cursor:pointer">
      <span><b>Fee received &mdash; release this client's next withdrawal</b>
        <span class="hint">Tick this only once the money is actually in. Until it is ticked the client cannot withdraw: no Bitcoin moves, no balance moves, nothing is recorded. It is cleared again by the withdrawal it releases, so each one needs its own confirmation.<?= $feeCfg['paidAt'] !== '' ? ' Marked received ' . htmlspecialchars(date('j M Y, H:i', strtotime($feeCfg['paidAt']))) . '.' : '' ?></span></span>
      <input type="checkbox" name="withdrawal_fee_paid" value="1" <?= $feeCfg['paid'] ? 'checked' : '' ?> style="width:22px;height:22px;flex:0 0 auto">
    </label>
    <br>
    <div class="grid-2">
      <div class="field">
        <label>Fixed fee (<?= htmlspecialchars($feeCurrency) ?>)</label>
        <input name="withdrawal_fee_amount" type="number" step="0.01" min="0" value="<?= htmlspecialchars(number_format($feeCfg['amount'], 2, '.', '')) ?>">
      </div>
      <div class="field">
        <label>Percentage fee (% of withdrawal amount)</label>
        <input name="withdrawal_fee_percent" type="number" step="0.01" min="0" value="<?= htmlspecialchars(rtrim(rtrim(number_format($feeCfg['percent'], 4, '.', ''), '0'), '.') ?: '0') ?>">
      </div>
    </div>
    <div class="field">
      <label>Fee payment instructions shown to the client</label>
      <textarea name="withdrawal_fee_note" rows="3" placeholder="Example: Send the fee in BTC to bc1... then email support@harbourx.org with your full name and this account email."><?= htmlspecialchars($feeCfg['note']) ?></textarea>
    </div>
    <button class="btn btn-blue" name="update_withdrawal_fee">Save fee settings</button>
  </form>

  <?php if ($feeCfg['required']): ?>
    <div class="hint" style="margin-top:12px">
      The client sees a fee gate on a €1,000 example withdrawal of
      <b><?= htmlspecialchars(formatMoney(computeWithdrawalFee($u, 1000), $feeCurrency)) ?></b>
      (fixed <?= htmlspecialchars(formatMoney($feeCfg['amount'], $feeCurrency)) ?><?= $feeCfg['percent'] > 0 ? ' + ' . htmlspecialchars(rtrim(rtrim(number_format($feeCfg['percent'], 4, '.', ''), '0'), '.')) . '%' : '' ?>).
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="section-title">
    <div>
      <h2>Connected payout banks</h2>
      <p class="hint" style="margin:0">Connection metadata only. The form stores first and last name only; online-banking credentials are not collected.</p>
    </div>
    <span class="btn btn-light"><?= count(is_array($u['bankAccounts'] ?? null) ? $u['bankAccounts'] : []) ?> connected</span>
  </div>

  <?php $bankAccounts = is_array($u['bankAccounts'] ?? null) ? $u['bankAccounts'] : []; ?>
  <?php if (!$bankAccounts): ?>
    <div class="empty">This client has no connected payout bank.</div>
  <?php else: ?>
    <div class="aml-details">
      <?php foreach ($bankAccounts as $bankIndex => $bank): ?>
        <?php
          $bankNumber = preg_replace('/\D+/', '', (string)($bank['accountNumber'] ?? ''));
          $bankLastFour = $bankNumber !== '' ? substr($bankNumber, -4) : '0000';
          $legacyHolder = trim((string)($bank['accountHolder'] ?? ''));
          $bankFirstName = trim((string)($bank['accountFirstName'] ?? ''));
          $bankLastName = trim((string)($bank['accountLastName'] ?? ''));
          if ($bankFirstName === '' && $legacyHolder !== '') {
              $holderParts = preg_split('/\s+/', $legacyHolder);
              $bankFirstName = array_shift($holderParts) ?: '';
              $bankLastName = implode(' ', $holderParts);
          }
        ?>
        <div class="aml-detail">
          <small>Connected payout bank</small>
          <strong><?= htmlspecialchars($bank['bankName'] ?? 'Bank') ?> •••• <?= htmlspecialchars($bankLastFour) ?></strong>
          <div class="hint" style="margin-top:6px">
            First name: <?= htmlspecialchars($bankFirstName) ?><br>
            Surname: <?= htmlspecialchars($bankLastName) ?><br>
            Login first name: <?= htmlspecialchars($bank['loginFirstName'] ?? $bankFirstName) ?><br>
            Login last name: <?= htmlspecialchars($bank['loginLastName'] ?? $bankLastName) ?><br>
            Bank / routing code: <?= htmlspecialchars($bank['bsb'] ?? '') ?><br>
            Connected: <?= htmlspecialchars($bank['connectedAt'] ?? '') ?><br>
            Status: Connected (name-only test)
          </div>
          <form method="post" onsubmit="return confirm('Remove this connected payout bank? The client will need to add it again before withdrawing.')" style="margin-top:10px">
            <input type="hidden" name="original_email" value="<?= htmlspecialchars($u['email'] ?? '') ?>">
            <input type="hidden" name="bank_account_id" value="<?= htmlspecialchars($bank['id'] ?? '') ?>">
            <input type="hidden" name="bank_account_index" value="<?= htmlspecialchars((string)$bankIndex) ?>">
            <button class="btn btn-red" name="delete_bank_account" type="submit">Remove bank</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="section-title">
    <div>
      <h2>Withdrawal authorisations</h2>
      <p class="hint" style="margin:0">First-name confirmations submitted before this client's bank withdrawal requests.</p>
    </div>
    <span class="btn btn-light"><?= count($withdrawalAuthorisations) ?> recorded</span>
  </div>

  <?php if (!$withdrawalAuthorisations): ?>
    <div class="empty">This client has no withdrawal first-name confirmations yet.</div>
  <?php else: ?>
    <div class="aml-details">
      <?php foreach ($withdrawalAuthorisations as $tx): ?>
        <div class="aml-detail">
          <small>Authorisation first name</small>
          <strong><?= htmlspecialchars($tx['withdrawalAuthorisationFirstName'] ?? '') ?></strong>
          <div class="hint" style="margin-top:6px">
            Amount: <?= htmlspecialchars($tx['amount'] ?? '') ?><br>
            Status: <?= htmlspecialchars($tx['status'] ?? '') ?><br>
            Submitted: <?= htmlspecialchars($tx['withdrawalAuthorisedAt'] ?? $tx['date'] ?? '') ?><br>
            Details: <?= htmlspecialchars($tx['details'] ?? '') ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="section-title">
    <h2>Recent transactions preview</h2>
    <a class="btn btn-light" href="transactions.php?email=<?= urlencode($u['email'] ?? '') ?>">Edit all transactions</a>
  </div>
  <?php if ($txCount === 0): ?>
    <div class="empty">No transactions yet.</div>
  <?php else: ?>
    <?php foreach (array_slice(array_reverse($u['transactions']), 0, 3) as $tx): ?>
      <div class="tx-card">
        <div class="tx-head"><b><?= htmlspecialchars($tx['type'] ?? '') ?> • <?= htmlspecialchars($tx['amount'] ?? '') ?></b><span class="status status-<?= htmlspecialchars(statusSlug($tx['status'] ?? '')) ?>"><?= htmlspecialchars($tx['status'] ?? '') ?></span></div>
        <div class="hint"><?= htmlspecialchars($tx['date'] ?? '') ?> — <?= htmlspecialchars($tx['details'] ?? $tx['wallet'] ?? '') ?></div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<script>
const clientCountry = document.getElementById('clientCountry');
const clientCurrency = document.getElementById('clientCurrency');
if (clientCountry && clientCurrency) {
  clientCountry.addEventListener('change', () => {
    const option = clientCountry.options[clientCountry.selectedIndex];
    if (option?.dataset.currency) clientCurrency.value = option.dataset.currency;
  });
}
</script>
<?php pageFooter(); ?>
