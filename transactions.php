<?php
require_once __DIR__ . '/admin_common.php';
requireAdmin();

$users = loadUsers($usersFile);
$email = cleanText($_GET['email'] ?? $_POST['user_email'] ?? '');
$idx = findUserIndex($users, $email);
$error = '';
$message = cleanText($_GET['msg'] ?? '');

if ($idx === -1) {
    pageHeader('Transactions not found'); pageTop('clients');
    echo '<div class="notice error">Client not found.</div><a class="btn btn-light" href="admin.php">Back to clients</a>';
    pageFooter(); exit;
}
ensureTransactions($users[$idx]);

if (isset($_POST['add_transaction'])) {
    $users[$idx]['transactions'][] = [
        'date' => cleanText($_POST['tx_date'] ?? date('Y-m-d')),
        'type' => cleanText($_POST['tx_type'] ?? 'Received'),
        'amount' => cleanText($_POST['tx_amount'] ?? ''),
        'status' => cleanText($_POST['tx_status'] ?? 'Pending'),
        'details' => cleanText($_POST['tx_details'] ?? ''),
        'detailsUrl' => cleanText($_POST['tx_details_url'] ?? '')
    ];
    saveUsers($usersFile, $users);
    header('Location: transactions.php?email=' . urlencode($users[$idx]['email']) . '&msg=' . urlencode('Transaction added successfully.'));
    exit;
}

if (isset($_POST['update_transactions'])) {
    $newTransactions = [];
    $dates = $_POST['tx_date'] ?? [];
    $types = $_POST['tx_type'] ?? [];
    $amounts = $_POST['tx_amount'] ?? [];
    $statuses = $_POST['tx_status'] ?? [];
    $details = $_POST['tx_details'] ?? [];
    $detailsUrls = $_POST['tx_details_url'] ?? [];

    foreach ($dates as $i => $date) {
        if (!empty($_POST['delete_tx'][$i])) continue;
        $transaction = is_array($users[$idx]['transactions'][$i] ?? null) ? $users[$idx]['transactions'][$i] : [];
        $transaction['date'] = cleanText($date);
        $transaction['type'] = cleanText($types[$i] ?? '');
        $transaction['amount'] = cleanText($amounts[$i] ?? '');
        $transaction['status'] = cleanText($statuses[$i] ?? '');
        $transaction['details'] = cleanText($details[$i] ?? '');
        $transaction['detailsUrl'] = cleanText($detailsUrls[$i] ?? '');
        $newTransactions[] = $transaction;
    }

    $users[$idx]['transactions'] = $newTransactions;
    saveUsers($usersFile, $users);
    header('Location: transactions.php?email=' . urlencode($users[$idx]['email']) . '&msg=' . urlencode('Transactions updated successfully.'));
    exit;
}

$users = loadUsers($usersFile);
$idx = findUserIndex($users, $email);
$u = $users[$idx];
$transactions = $u['transactions'] ?? [];
$statuses = ['Completed','Pending','In review','Declined'];

pageHeader('Transactions');
pageTop('clients');
if ($message) echo '<div class="notice success">' . htmlspecialchars($message) . '</div>';
if ($error) echo '<div class="notice error">' . htmlspecialchars($error) . '</div>';
?>

<div class="card">
  <div class="profile-head">
    <div class="profile-left">
      <div class="profile-avatar"><?= htmlspecialchars(initials($u['name'] ?? '')) ?></div>
      <div>
        <h2 style="margin-bottom:4px">Transactions</h2>
        <div class="hint"><b><?= htmlspecialchars($u['name'] ?? '') ?></b> — <?= htmlspecialchars($u['email'] ?? '') ?></div>
      </div>
    </div>
    <div class="actions">
      <a class="btn btn-light" href="admin.php">Back to clients</a>
      <a class="btn btn-light" href="client.php?email=<?= urlencode($u['email'] ?? '') ?>">Edit client</a>
    </div>
  </div>
</div>

<div class="card">
  <h2>Add new transaction</h2>
  <p class="hint">The client will see these transactions on their dashboard. Use the client currency, for example: <b>+2.56 BTC</b>, <b>C$500.00</b>, <b>£500.00</b>, or <b>-0.25000000 BTC → C$25,000.00</b>.</p>
  <form method="post">
    <input type="hidden" name="user_email" value="<?= htmlspecialchars($u['email'] ?? '') ?>">
    <div class="grid">
      <div class="field"><label>Date</label><input type="date" name="tx_date" value="<?= date('Y-m-d') ?>" required></div>
      <div class="field"><label>Type</label><input name="tx_type" placeholder="Received / BTC to CAD Swap / Bank Withdrawal" required></div>
      <div class="field"><label>Amount</label><input name="tx_amount" placeholder="+2.56 BTC" required></div>
      <div class="field"><label>Status</label><select name="tx_status"><?php foreach($statuses as $s): ?><option><?= htmlspecialchars($s) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Details</label><input name="tx_details" placeholder="Bank withdrawal / BTC account / rate details"></div>
      <div class="field"><label>Details URL optional</label><input name="tx_details_url" placeholder="https://..."></div>
    </div>
    <br><button class="btn btn-blue" name="add_transaction">Add transaction</button>
  </form>
</div>

<div class="card">
  <div class="section-title">
    <div><h2>Edit transactions</h2><p class="hint" style="margin:0">Edit each transaction below. Tick delete and save to remove one.</p></div>
    <span class="btn btn-light"><?= count($transactions) ?> transaction<?= count($transactions) === 1 ? '' : 's' ?></span>
  </div>

  <form method="post">
    <input type="hidden" name="user_email" value="<?= htmlspecialchars($u['email'] ?? '') ?>">
    <?php if (count($transactions) === 0): ?>
      <div class="empty">No transactions yet.</div>
    <?php else: ?>
      <?php foreach ($transactions as $i => $tx): ?>
        <div class="tx-card">
          <div class="tx-head">
            <b>Transaction #<?= $i + 1 ?></b>
            <span class="status status-<?= htmlspecialchars(statusSlug($tx['status'] ?? '')) ?>"><?= htmlspecialchars($tx['status'] ?? '') ?></span>
          </div>
          <div class="grid-2">
            <div class="field"><label>Date</label><input type="date" name="tx_date[<?= $i ?>]" value="<?= htmlspecialchars($tx['date'] ?? '') ?>"></div>
            <div class="field"><label>Type</label><input name="tx_type[<?= $i ?>]" value="<?= htmlspecialchars($tx['type'] ?? '') ?>"></div>
            <div class="field"><label>Amount</label><input name="tx_amount[<?= $i ?>]" value="<?= htmlspecialchars($tx['amount'] ?? '') ?>"></div>
            <div class="field"><label>Status</label><select name="tx_status[<?= $i ?>]">
              <?php foreach($statuses as $s): ?><option value="<?= htmlspecialchars($s) ?>" <?= (($tx['status'] ?? '') === $s) ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option><?php endforeach; ?>
            </select></div>
            <div class="field"><label>Details</label><input name="tx_details[<?= $i ?>]" value="<?= htmlspecialchars($tx['details'] ?? $tx['wallet'] ?? '') ?>"></div>
            <div class="field"><label>Details URL</label><input name="tx_details_url[<?= $i ?>]" value="<?= htmlspecialchars($tx['detailsUrl'] ?? $tx['walletUrl'] ?? '') ?>"></div>
          </div>
          <?php if (trim((string)($tx['withdrawalAuthorisationFirstName'] ?? '')) !== ''): ?>
            <div class="aml-details">
              <div class="aml-detail"><small>Authorisation first name</small><strong><?= htmlspecialchars($tx['withdrawalAuthorisationFirstName'] ?? '') ?></strong></div>
              <div class="aml-detail"><small>Authorised at</small><strong><?= htmlspecialchars($tx['withdrawalAuthorisedAt'] ?? '') ?></strong></div>
              <div class="aml-detail"><small>Request ID</small><strong><?= htmlspecialchars($tx['withdrawalRequestId'] ?? '') ?></strong></div>
            </div>
          <?php endif; ?>
          <br>
          <label class="btn btn-red"><input type="checkbox" name="delete_tx[<?= $i ?>]" value="1" style="width:auto;height:auto"> Delete this transaction</label>
        </div>
      <?php endforeach; ?>
      <button class="btn btn-orange" name="update_transactions">Save all transaction changes</button>
    <?php endif; ?>
  </form>
</div>

<?php pageFooter(); ?>
