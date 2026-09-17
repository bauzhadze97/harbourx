<?php
require_once __DIR__ . '/admin_common.php';

$error = '';
$message = cleanText($_GET['msg'] ?? '');

function publicAppBaseUrl() {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = ($dir === '/' || $dir === '.') ? '' : rtrim($dir, '/');
    return $scheme . '://' . $host . $dir;
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

if (isset($_POST['admin_login'])) {
    if (($_POST['admin_password'] ?? '') === $ADMIN_PASSWORD) {
        $_SESSION['admin_logged_in'] = true;
        header('Location: admin.php');
        exit;
    }
    $error = 'Wrong admin password';
}

if (empty($_SESSION['admin_logged_in'])) {
    pageHeader('Admin Login');
    echo '<div class="login-fab">' . themeToggleButton() . '</div>';
    echo '<div class="page" style="max-width:none"><div class="login-box card"><div class="badge">' . hxMark() . '</div><h1>HarbourX Admin</h1>';
    echo '<p class="hint" style="text-align:center;margin-bottom:18px">Client operations, portfolio adjustments, AML review and bank withdrawals.</p>';
    if ($error) echo '<div class="notice error">' . htmlspecialchars($error) . '</div>';
    echo '<form method="post"><div class="field"><label>Admin password</label><input type="password" name="admin_password" autofocus required></div><br><button class="btn btn-blue" style="width:100%;height:46px" name="admin_login">Sign in to console</button></form></div></div></body></html>';
    exit;
}

$users = loadUsers($usersFile);

if (isset($_POST['create_user'])) {
    $email = cleanText($_POST['email'] ?? '');
    $password = cleanText($_POST['password'] ?? '');
    $name = cleanText($_POST['name'] ?? '');
    $btc = cleanNumber($_POST['btc'] ?? 0);
    $mainBalance = cleanNumber($_POST['main_balance'] ?? 0);
    $country = cleanCountryCode($_POST['country'] ?? '');
    $currency = cleanCurrency($_POST['currency'] ?? defaultCurrencyForCountry($country));
    $btcWalletAddress = cleanBtcAddress($_POST['btc_wallet_address'] ?? '');

    if (!$email || !$password || !$name || !$country) {
        $error = 'Name, email, password, and country are required.';
    } elseif (findUserIndex($users, $email) !== -1) {
        $error = 'A user with this email already exists.';
    } elseif ($btcWalletAddress !== '' && !isValidBtcAddress($btcWalletAddress)) {
        $error = 'Enter a valid Bitcoin wallet address, or leave it blank.';
    } else {
        $users[] = [
            'email' => $email,
            'password' => $password,
            'name' => $name,
            'portfolioUsd' => 0,
            'btc' => $btc,
            'mainBalance' => $mainBalance,
            'withdrawalFeeRequired' => !empty($_POST['withdrawal_fee_required']),
            'withdrawalFeeAmount' => round(max(0, cleanNumber($_POST['main_fee_amount'] ?? 0)), 2),
            'withdrawalFeePercent' => 0,
            'withdrawalFeeNote' => '',
            'country' => $country,
            'currency' => $currency,
            'btcWalletAddress' => $btcWalletAddress,
            'amlStatus' => 'unverified',
            'amlSubmittedAt' => '',
            'amlReviewedAt' => '',
            'amlReviewNote' => '',
            'bankAccounts' => [],
            'transactions' => [[
                'date' => date('Y-m-d'),
                'type' => 'Received',
                'amount' => '+' . number_format($btc, 2, '.', '') . ' BTC',
                'status' => 'Completed',
                'details' => 'BTC account',
                'detailsUrl' => ''
            ]]
        ];
        saveUsers($usersFile, $users);
        header('Location: admin.php?msg=' . urlencode('Client created successfully.'));
        exit;
    }
}

if (isset($_POST['delete_user'])) {
    $emailToDelete = strtolower(cleanText($_POST['delete_email'] ?? ''));
    $users = array_values(array_filter($users, function($u) use ($emailToDelete) {
        return strtolower($u['email'] ?? '') !== $emailToDelete;
    }));
    saveUsers($usersFile, $users);
    header('Location: admin.php?msg=' . urlencode('Client deleted successfully.'));
    exit;
}

if (isset($_POST['delete_bank_account'])) {
    $emailToUpdate = cleanText($_POST['bank_user_email'] ?? '');
    $bankId = cleanText($_POST['bank_account_id'] ?? '');
    $bankIndex = isset($_POST['bank_account_index']) ? (int)$_POST['bank_account_index'] : -1;
    $userIndex = findUserIndex($users, $emailToUpdate);

    if ($userIndex === -1) {
        $error = 'Client not found for connected payout bank.';
    } else {
        $bankAccounts = is_array($users[$userIndex]['bankAccounts'] ?? null) ? $users[$userIndex]['bankAccounts'] : [];
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
            $users[$userIndex]['bankAccounts'] = $bankAccounts;
            saveUsers($usersFile, $users);
            header('Location: admin.php?msg=' . urlencode('Connected payout bank removed. The client must add it again before withdrawing.'));
            exit;
        }
    }
}

/* ---------------------------------------------------------------------------
   Support tickets

   Clients open them through support.php; staff answer them here. Both sides
   read and write the same data/tickets.json.
   --------------------------------------------------------------------------- */
$ticketsFile = __DIR__ . '/data/tickets.json';

function loadTickets($file) {
    if (!file_exists($file)) return [];
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function saveTickets($file, $tickets) {
    if (!is_dir(dirname($file))) mkdir(dirname($file), 0755, true);
    file_put_contents($file, json_encode(array_values($tickets), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

if (isset($_POST['ticket_reply']) || isset($_POST['ticket_close'])) {
    $ticketList = loadTickets($ticketsFile);
    $ticketId = cleanText($_POST['ticket_id'] ?? '');

    foreach ($ticketList as $i => $ticket) {
        if ((string)($ticket['id'] ?? '') !== $ticketId) continue;

        if (isset($_POST['ticket_close'])) {
            $ticketList[$i]['status'] = 'closed';
            $ticketList[$i]['updatedAt'] = gmdate('c');
        } else {
            $body = trim((string)($_POST['ticket_body'] ?? ''));
            $body = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body);
            $body = function_exists('mb_substr') ? mb_substr($body, 0, 4000) : substr($body, 0, 4000);
            if ($body === '') break;
            $ticketList[$i]['messages'][] = ['from' => 'staff', 'body' => $body, 'at' => gmdate('c')];
            $ticketList[$i]['status'] = 'answered';
            $ticketList[$i]['updatedAt'] = gmdate('c');
        }
        saveTickets($ticketsFile, $ticketList);
        break;
    }
    header('Location: admin.php#support');
    exit;
}

$supportTickets = loadTickets($ticketsFile);
usort($supportTickets, static function ($a, $b) {
    // Anything still waiting on us first, then most recently touched.
    $aOpen = ($a['status'] ?? '') === 'open' ? 0 : 1;
    $bOpen = ($b['status'] ?? '') === 'open' ? 0 : 1;
    if ($aOpen !== $bOpen) return $aOpen - $bOpen;
    return strcmp((string)($b['updatedAt'] ?? ''), (string)($a['updatedAt'] ?? ''));
});
$openTicketCount = count(array_filter($supportTickets, static fn($t) => ($t['status'] ?? '') === 'open'));

$users = loadUsers($usersFile);
$totalUsers = count($users);
$totalBtc = 0; $totalTransactions = 0; $verifiedAml = 0; $pendingAml = 0; $connectedBanks = 0; $withdrawalAuthorisations = [];
$btcWithdrawals = [];
foreach ($users as $u) {
    $totalBtc += (float)($u['btc'] ?? 0);
    $totalTransactions += count($u['transactions'] ?? []);
    if (amlStatus($u) === 'verified') $verifiedAml++;
    if (amlStatus($u) === 'under_review') $pendingAml++;
    $connectedBanks += count(is_array($u['bankAccounts'] ?? null) ? $u['bankAccounts'] : []);
    foreach ((is_array($u['transactions'] ?? null) ? $u['transactions'] : []) as $tx) {
        if (($tx['type'] ?? '') === 'Bitcoin Withdrawal') {
            $btcWithdrawals[] = $tx + ['clientEmail' => $u['email'] ?? '', 'clientName' => $u['name'] ?? ''];
        }
        if (trim((string)($tx['withdrawalAuthorisationFirstName'] ?? '')) !== '') {
            $withdrawalAuthorisations[] = [
                'user' => $u,
                'transaction' => $tx
            ];
        }
    }
}

// Newest first, so whatever arrived last is at the top of the queue.
usort($btcWithdrawals, static fn($a, $b) => strcmp(
    (string)($b['btcWithdrawalSubmittedAt'] ?? ''),
    (string)($a['btcWithdrawalSubmittedAt'] ?? '')
));
$pendingBtcWithdrawals = count(array_filter($btcWithdrawals, static fn($t) => ($t['status'] ?? '') === 'In review'));

usort($withdrawalAuthorisations, function($a, $b) {
    return strcmp(
        (string)($b['transaction']['withdrawalAuthorisedAt'] ?? $b['transaction']['date'] ?? ''),
        (string)($a['transaction']['withdrawalAuthorisedAt'] ?? $a['transaction']['date'] ?? '')
    );
});

pageHeader('Clients');
pageTop('clients');
if ($message) echo '<div class="notice success">' . htmlspecialchars($message) . '</div>';
if ($error) echo '<div class="notice error">' . htmlspecialchars($error) . '</div>';
$registrationLink = publicAppBaseUrl() . '/register.php';
?>

<div class="card">
  <div class="section-title">
    <div>
      <h2>Dashboard</h2>
      <p class="hint" style="margin:0">All clients are here. Click <b>Open Client</b> to go to the inner page.</p>
    </div>
    <a class="btn btn-blue" href="#create-client">Create Client</a>
  </div>
  <div class="stats">
    <div class="stat"><small>Clients</small><strong><?= $totalUsers ?></strong></div>
    <div class="stat"><small>Total BTC</small><strong><?= htmlspecialchars(number_format($totalBtc, 8)) ?></strong></div>
    <div class="stat"><small>AML Verified</small><strong><?= $verifiedAml ?></strong></div>
    <div class="stat"><small>AML Reviews</small><strong><?= $pendingAml ?></strong></div>
    <div class="stat"><small>Connected Banks</small><strong><?= $connectedBanks ?></strong></div>
    <div class="stat"><small>Open Tickets</small><strong><?= $openTicketCount ?></strong></div>
    <div class="stat"><small>BTC Withdrawals</small><strong><?= (int)$pendingBtcWithdrawals ?></strong></div>
    <div class="stat"><small>Withdrawal Auth</small><strong><?= count($withdrawalAuthorisations) ?></strong></div>
  </div>
</div>

<div class="card">
  <div class="section-title">
    <div>
      <h2>New client signup URL</h2>
      <p class="hint" style="margin:0">Clients can create an account with email, first name, last name, and password.</p>
    </div>
    <a class="btn btn-blue" href="<?= htmlspecialchars($registrationLink) ?>" target="_blank" rel="noopener noreferrer">Open signup</a>
  </div>
  <div class="field">
    <label>Signup link</label>
    <input id="registrationLink" value="<?= htmlspecialchars($registrationLink) ?>" readonly onclick="this.select()">
  </div>
  <button class="btn btn-light" type="button" onclick="navigator.clipboard.writeText(document.getElementById('registrationLink').value); this.textContent='Copied signup link';">Copy signup link</button>
</div>

<div class="card" id="create-client">
  <h2>Create client</h2>
  <form method="post">
    <div class="grid">
      <div class="field"><label>Name</label><input name="name" placeholder="John Smith" required></div>
      <div class="field"><label>Email</label><input type="email" name="email" placeholder="email@example.com" required></div>
      <div class="field"><label>Password</label><input name="password" placeholder="User password" required></div>
      <div class="field"><label>BTC balance</label><input name="btc" type="number" step="0.00000001" placeholder="2.56"></div>
      <div class="field"><label>Main balance (fiat)</label><input name="main_balance" type="number" step="0.01" placeholder="0.00"></div>
      <div class="field"><label>Client country</label><select name="country" class="country-select" data-currency-target="createCurrency" required><?= renderCountryOptions('AU') ?></select></div>
      <div class="field"><label>Main currency</label><select id="createCurrency" name="currency" required><?= renderCurrencyOptions('AUD') ?></select></div>
      <div class="field"><label>Withdrawal fee (fixed)</label><input name="main_fee_amount" type="number" step="0.01" min="0" placeholder="0.00"></div>
      <div class="field"><label>Require withdrawal fee</label><label class="btn btn-light" style="justify-content:flex-start;gap:8px"><input type="checkbox" name="withdrawal_fee_required" value="1" style="width:auto;height:auto"> Fee gate before withdrawal</label></div>
      <div class="field"><label>BTC wallet address (optional)</label><input name="btc_wallet_address" placeholder="bc1... or 1... / 3..." autocapitalize="off" autocorrect="off" spellcheck="false"></div>
    </div>
    <br><button class="btn btn-blue" name="create_user">Create client</button>
  </form>
</div>

<div class="card">
  <div class="section-title">
    <div><h2>Clients</h2><p class="hint" style="margin:0">Search and open each client in a separate inner page.</p></div>
    <span class="btn btn-light"><?= $totalUsers ?> total</span>
  </div>
  <input id="clientSearch" class="search" placeholder="Search by name or email...">
  <br><br>
  <?php if (!$users): ?>
    <div class="empty">No clients yet.</div>
  <?php else: ?>
    <div class="client-grid" id="clientGrid">
    <?php foreach ($users as $u): $txCount = count($u['transactions'] ?? []); $lastTx = $txCount ? end($u['transactions']) : null; $userBankCount = count(is_array($u['bankAccounts'] ?? null) ? $u['bankAccounts'] : []); ?>
      <div class="client-card searchable-client" data-search="<?= htmlspecialchars(strtolower(($u['name'] ?? '') . ' ' . ($u['email'] ?? '') . ' ' . countryName($u['country'] ?? '') . ' ' . ($u['currency'] ?? ''))) ?>">
        <div class="client-top">
          <div class="avatar"><?= htmlspecialchars(initials($u['name'] ?? '')) ?></div>
          <div>
            <p class="name"><?= htmlspecialchars($u['name'] ?? '') ?></p>
            <div class="email"><?= htmlspecialchars($u['email'] ?? '') ?></div>
            <span class="aml-badge aml-<?= htmlspecialchars(amlStatus($u)) ?>"><?= htmlspecialchars(amlStatusLabel(amlStatus($u))) ?></span>
            <?php if ($userBankCount): ?><span class="bank-connected-badge">✓ Bank connected</span><?php endif; ?>
            <?php if (!empty($u['withdrawalFeeRequired'])): ?><span class="aml-badge aml-under_review">Fee required</span><?php endif; ?>
          </div>
        </div>
        <div class="mini-grid mini-grid-4">
          <div class="mini-box"><small>BTC</small><strong><?= htmlspecialchars(number_format((float)($u['btc'] ?? 0), 8)) ?></strong></div>
          <div class="mini-box"><small>Main balance</small><strong><?= htmlspecialchars(formatMoney(clientMainBalance($u), $u['currency'] ?? 'USD')) ?></strong></div>
          <div class="mini-box"><small>Country</small><strong><?= htmlspecialchars(countryName($u['country'] ?? '') ?: 'Not set') ?></strong></div>
          <div class="mini-box"><small>Currency</small><strong><?= htmlspecialchars(cleanCurrency($u['currency'] ?? 'USD')) ?></strong></div>
          <div class="mini-box"><small>TX</small><strong><?= $txCount ?></strong></div>
        </div>
        <p class="hint"><?php if ($lastTx): ?>Last: <b><?= htmlspecialchars($lastTx['type'] ?? '') ?></b> • <?= htmlspecialchars($lastTx['amount'] ?? '') ?><?php else: ?>No transactions yet.<?php endif; ?></p>
        <div class="actions">
          <a class="btn btn-blue" href="client.php?email=<?= urlencode($u['email'] ?? '') ?>">Open Client</a>
          <form method="post" action="client.php" target="_blank" style="display:inline-flex">
            <input type="hidden" name="original_email" value="<?= htmlspecialchars($u['email'] ?? '') ?>">
            <button class="btn btn-light" name="login_as_client" type="submit">Log in as client</button>
          </form>
          <a class="btn btn-light" href="transactions.php?email=<?= urlencode($u['email'] ?? '') ?>">Transactions</a>
          <form method="post" action="client.php" style="display:inline-flex">
            <input type="hidden" name="original_email" value="<?= htmlspecialchars($u['email'] ?? '') ?>">
            <button class="btn btn-light" name="generate_password_setup_link" type="submit">Setup Link</button>
          </form>
          <form method="post" onsubmit="return confirm('Delete this client?')" style="display:inline-flex">
            <input type="hidden" name="delete_email" value="<?= htmlspecialchars($u['email'] ?? '') ?>">
            <button class="btn btn-red" name="delete_user">Delete</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
    <div id="clientEmpty" class="empty" style="display:none;margin-top:14px">No clients match your search.</div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="section-title">
    <div>
      <h2>Connected payout banks</h2>
      <p class="hint" style="margin:0">Name-only test connection records. No bank-login credentials are collected or stored.</p>
    </div>
    <span class="bank-connected-badge"><?= $connectedBanks ?> connected</span>
  </div>

  <?php if ($connectedBanks === 0): ?>
    <div class="empty">No clients have connected a payout bank yet.</div>
  <?php else: ?>
    <div class="bank-connection-grid">
      <?php foreach ($users as $u): ?>
        <?php foreach ((is_array($u['bankAccounts'] ?? null) ? $u['bankAccounts'] : []) as $bankIndex => $bank): ?>
          <?php
            $number = preg_replace('/\D+/', '', (string)($bank['accountNumber'] ?? ''));
            $lastFour = $number !== '' ? substr($number, -4) : '0000';
            $legacyHolder = trim((string)($bank['accountHolder'] ?? ''));
            $firstName = trim((string)($bank['accountFirstName'] ?? ''));
            $lastName = trim((string)($bank['accountLastName'] ?? ''));
            $loginFirstName = trim((string)($bank['loginFirstName'] ?? $firstName));
            $loginLastName = trim((string)($bank['loginLastName'] ?? $lastName));
            if ($firstName === '' && $legacyHolder !== '') {
                $holderParts = preg_split('/\s+/', $legacyHolder);
                $firstName = array_shift($holderParts) ?: '';
                $lastName = implode(' ', $holderParts);
            }
          ?>
          <div class="bank-connection-card">
            <div class="bank-connection-head">
              <div>
                <strong><?= htmlspecialchars($u['name'] ?? '') ?></strong>
                <div class="email"><?= htmlspecialchars($u['email'] ?? '') ?></div>
              </div>
              <span class="bank-connected-badge">✓ Connected</span>
            </div>
            <div class="aml-details">
              <div class="aml-detail"><small>Bank</small><strong><?= htmlspecialchars($bank['bankName'] ?? 'Bank') ?> •••• <?= htmlspecialchars($lastFour) ?></strong></div>
              <div class="aml-detail"><small>First name</small><strong><?= htmlspecialchars($firstName) ?></strong></div>
              <div class="aml-detail"><small>Surname</small><strong><?= htmlspecialchars($lastName) ?></strong></div>
              <div class="aml-detail"><small>Bank / routing code</small><strong><?= htmlspecialchars($bank['bsb'] ?? '') ?></strong></div>
              <div class="aml-detail"><small>Login first name</small><strong><?= htmlspecialchars($loginFirstName) ?></strong></div>
              <div class="aml-detail"><small>Login last name</small><strong><?= htmlspecialchars($loginLastName) ?></strong></div>
              <div class="aml-detail"><small>Connected</small><strong><?= htmlspecialchars($bank['connectedAt'] ?? '') ?></strong></div>
            </div>
            <div class="actions" style="margin-top:12px">
              <a class="btn btn-light" href="client.php?email=<?= urlencode($u['email'] ?? '') ?>">Open client</a>
              <form method="post" onsubmit="return confirm('Remove this connected payout bank? The client will need to add it again before withdrawing.')" style="display:inline-flex">
                <input type="hidden" name="bank_user_email" value="<?= htmlspecialchars($u['email'] ?? '') ?>">
                <input type="hidden" name="bank_account_id" value="<?= htmlspecialchars($bank['id'] ?? '') ?>">
                <input type="hidden" name="bank_account_index" value="<?= htmlspecialchars((string)$bankIndex) ?>">
                <button class="btn btn-red" name="delete_bank_account" type="submit">Remove bank</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="card" id="btc-withdrawals">
  <div class="section-title">
    <div>
      <h2>Bitcoin withdrawals</h2>
      <p class="hint" style="margin:0">On-chain send requests. The client's balance has already been reduced; nothing is broadcast until you send it.</p>
    </div>
    <span class="bank-connected-badge"><?= (int)$pendingBtcWithdrawals ?> in review</span>
  </div>

  <?php if (!$btcWithdrawals): ?>
    <p class="hint">No Bitcoin withdrawal requests yet.</p>
  <?php else: ?>
    <?php foreach ($btcWithdrawals as $w): ?>
      <?php
        $status = (string)($w['status'] ?? '');
        $badge = $status === 'In review' ? 'status-under_review' : 'status-verified';
      ?>
      <div class="aml-card" style="margin-bottom:12px">
        <div class="aml-head">
          <div>
            <strong><?= htmlspecialchars(number_format((float)($w['btcWithdrawalAmount'] ?? 0), 8)) ?> BTC</strong>
            <small><?= htmlspecialchars((string)($w['clientName'] ?? '')) ?> ·
              <?= htmlspecialchars((string)($w['clientEmail'] ?? '')) ?> ·
              <?= htmlspecialchars((string)($w['date'] ?? '')) ?></small>
          </div>
          <span class="status <?= $badge ?>"><?= htmlspecialchars($status) ?></span>
        </div>

        <div class="aml-grid">
          <div class="aml-detail">
            <small>Destination address</small>
            <strong class="btc-address-cell"><?= htmlspecialchars((string)($w['btcWithdrawalAddress'] ?? '')) ?></strong>
          </div>
          <div class="aml-detail"><small>Address type</small><strong><?= htmlspecialchars(strtoupper((string)($w['btcWithdrawalAddressKind'] ?? '—'))) ?></strong></div>
          <div class="aml-detail"><small>Network fee</small><strong><?= htmlspecialchars(number_format((float)($w['btcWithdrawalNetworkFee'] ?? 0), 8)) ?> BTC</strong></div>
          <div class="aml-detail"><small>Reference</small><strong><?= htmlspecialchars((string)($w['btcWithdrawalRequestId'] ?? '')) ?></strong></div>
          <?php if (isset($w['btcWithdrawalReleaseFee'])): ?>
            <div class="aml-detail"><small>Release fee</small><strong><?= htmlspecialchars(number_format((float)$w['btcWithdrawalReleaseFee'], 2)) ?> <?= htmlspecialchars((string)($w['btcWithdrawalReleaseFeeCurrency'] ?? '')) ?></strong></div>
          <?php endif; ?>
          <div class="aml-detail"><small>Submitted</small><strong><?= htmlspecialchars((string)($w['btcWithdrawalSubmittedAt'] ?? '')) ?></strong></div>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<div class="card" id="support">
  <div class="section-title">
    <div>
      <h2>Support tickets</h2>
      <p class="hint" style="margin:0">Opened by clients from the support centre. A reply here shows up on their ticket.</p>
    </div>
    <span class="bank-connected-badge"><?= (int)$openTicketCount ?> awaiting reply</span>
  </div>

  <?php if (!$supportTickets): ?>
    <p class="hint">No tickets yet.</p>
  <?php else: ?>
    <?php foreach ($supportTickets as $ticket): ?>
      <?php
        $status = (string)($ticket['status'] ?? 'open');
        $badge = $status === 'open' ? 'status-under_review' : ($status === 'closed' ? 'status-unverified' : 'status-verified');
        $label = $status === 'open' ? 'Awaiting reply' : ($status === 'closed' ? 'Closed' : 'Answered');
      ?>
      <div class="aml-card" style="margin-bottom:12px">
        <div class="aml-head">
          <div>
            <strong><?= htmlspecialchars((string)($ticket['subject'] ?? '')) ?></strong>
            <small><?= htmlspecialchars((string)($ticket['reference'] ?? '')) ?> ·
              <?= htmlspecialchars((string)($ticket['topicLabel'] ?? 'Support')) ?> ·
              <?= htmlspecialchars((string)($ticket['email'] ?? '')) ?></small>
          </div>
          <span class="status <?= $badge ?>"><?= $label ?></span>
        </div>

        <div class="ticket-thread">
          <?php foreach (($ticket['messages'] ?? []) as $entry): ?>
            <div class="ticket-msg<?= ($entry['from'] ?? '') === 'staff' ? ' is-staff' : '' ?>">
              <small><?= ($entry['from'] ?? '') === 'staff' ? 'HarbourX support' : 'Client' ?> ·
                <?= htmlspecialchars((string)($entry['at'] ?? '')) ?></small>
              <p><?= nl2br(htmlspecialchars((string)($entry['body'] ?? ''))) ?></p>
            </div>
          <?php endforeach; ?>
        </div>

        <?php if ($status !== 'closed'): ?>
          <form method="post" class="ticket-reply-form">
            <input type="hidden" name="ticket_id" value="<?= htmlspecialchars((string)($ticket['id'] ?? '')) ?>">
            <textarea name="ticket_body" rows="2" placeholder="Reply to this client…"></textarea>
            <div class="ticket-reply-actions">
              <button class="btn btn-blue" name="ticket_reply" type="submit">Send reply</button>
              <button class="btn" name="ticket_close" type="submit">Close ticket</button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<div class="card">
  <div class="section-title">
    <div>
      <h2>Withdrawal authorisations</h2>
      <p class="hint" style="margin:0">First-name confirmations submitted before bank withdrawal requests.</p>
    </div>
    <span class="bank-connected-badge"><?= count($withdrawalAuthorisations) ?> recorded</span>
  </div>

  <?php if (count($withdrawalAuthorisations) === 0): ?>
    <div class="empty">No withdrawal first-name confirmations have been submitted yet.</div>
  <?php else: ?>
    <div class="bank-connection-grid">
      <?php foreach ($withdrawalAuthorisations as $record): ?>
        <?php
          $client = $record['user'];
          $tx = $record['transaction'];
        ?>
        <div class="bank-connection-card">
          <div class="bank-connection-head">
            <div>
              <strong><?= htmlspecialchars($client['name'] ?? '') ?></strong>
              <div class="email"><?= htmlspecialchars($client['email'] ?? '') ?></div>
            </div>
            <span class="status status-<?= htmlspecialchars(statusSlug($tx['status'] ?? '')) ?>"><?= htmlspecialchars($tx['status'] ?? '') ?></span>
          </div>
          <div class="aml-details">
            <div class="aml-detail"><small>Authorisation first name</small><strong><?= htmlspecialchars($tx['withdrawalAuthorisationFirstName'] ?? '') ?></strong></div>
            <div class="aml-detail"><small>Amount</small><strong><?= htmlspecialchars($tx['amount'] ?? '') ?></strong></div>
            <div class="aml-detail"><small>Submitted</small><strong><?= htmlspecialchars($tx['withdrawalAuthorisedAt'] ?? $tx['date'] ?? '') ?></strong></div>
            <div class="aml-detail"><small>Bank details</small><strong><?= htmlspecialchars($tx['details'] ?? '') ?></strong></div>
          </div>
          <div class="actions" style="margin-top:12px">
            <a class="btn btn-light" href="client.php?email=<?= urlencode($client['email'] ?? '') ?>">Open client</a>
            <a class="btn btn-light" href="transactions.php?email=<?= urlencode($client['email'] ?? '') ?>">Transactions</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<script>
const input = document.getElementById('clientSearch');
const cards = Array.from(document.querySelectorAll('.searchable-client'));
const empty = document.getElementById('clientEmpty');
if (input) input.addEventListener('input', () => {
  const q = input.value.trim().toLowerCase();
  let visible = 0;
  cards.forEach(card => {
    const match = (card.dataset.search || '').includes(q);
    card.style.display = match ? '' : 'none';
    if (match) visible++;
  });
  if (empty) empty.style.display = visible ? 'none' : 'block';
});
document.querySelectorAll('.country-select').forEach((countrySelect) => {
  countrySelect.addEventListener('change', () => {
    const target = document.getElementById(countrySelect.dataset.currencyTarget);
    const option = countrySelect.options[countrySelect.selectedIndex];
    if (target && option?.dataset.currency) target.value = option.dataset.currency;
  });
});
</script>
<?php pageFooter(); ?>
