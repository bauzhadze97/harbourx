<?php
require_once __DIR__ . '/admin_common.php';
require_once __DIR__ . '/payments_common.php';
requireAdmin();

$paymentsFile = __DIR__ . '/data/payment_schedules.json';
$users = loadUsers($usersFile);
$records = hxPaymentLoad($paymentsFile);
$error = '';
$message = cleanText($_GET['msg'] ?? '');
$selectedClient = strtolower(cleanText($_GET['client'] ?? ''));
$selectedStatus = strtolower(cleanText($_GET['status'] ?? ''));
$allowedStatusFilters = ['pending', 'overdue', 'paid', 'missed'];
if ($selectedStatus !== '' && !in_array($selectedStatus, $allowedStatusFilters, true)) $selectedStatus = '';
$csrfToken = hxPaymentCsrfToken();

$clientLookup = [];
foreach ($users as $user) {
    $key = strtolower(cleanText($user['email'] ?? ''));
    if ($key !== '') $clientLookup[$key] = $user;
}

function paymentRedirect($message, $clientEmail = '') {
    $query = ['msg' => $message];
    if ($clientEmail !== '') $query['client'] = $clientEmail;
    header('Location: payments.php?' . http_build_query($query));
    exit;
}

function paymentRecordFromPost($existing = []) {
    $status = hxPaymentStoredStatus($_POST['status'] ?? 'pending');
    $paidAt = cleanText($_POST['paid_at'] ?? '');
    if ($status === 'paid' && $paidAt === '') $paidAt = date('Y-m-d');
    if ($status !== 'paid') $paidAt = '';

    return array_merge($existing, [
        'clientEmail' => strtolower(cleanText($_POST['client_email'] ?? '')),
        'amount' => round(max(0, cleanNumber($_POST['amount'] ?? 0)), 2),
        'currency' => cleanCurrency($_POST['currency'] ?? 'USD'),
        'dueDate' => cleanText($_POST['due_date'] ?? ''),
        'status' => $status,
        'paidAt' => $paidAt,
        'nonPaymentReason' => cleanText($_POST['non_payment_reason'] ?? ''),
        'notes' => cleanText($_POST['notes'] ?? ''),
        'updatedAt' => gmdate('c')
    ]);
}

function paymentValidationError($record, $clientLookup) {
    if (!isset($clientLookup[strtolower((string)($record['clientEmail'] ?? ''))])) return 'Choose a valid client.';
    if ((float)($record['amount'] ?? 0) <= 0) return 'Enter an amount greater than zero.';
    if (!hxPaymentValidDate($record['dueDate'] ?? '')) return 'Enter a valid due date.';
    if (($record['status'] ?? '') === 'paid' && !hxPaymentValidDate($record['paidAt'] ?? '')) return 'Enter a valid paid date.';
    if (($record['status'] ?? '') === 'missed' && trim((string)($record['nonPaymentReason'] ?? '')) === '') {
        return 'Add a reason when a payment is marked Not paid.';
    }
    return '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hxPaymentCsrfValid($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Refresh the page and try again.';
    } elseif (isset($_POST['create_payment'])) {
        $record = paymentRecordFromPost([
            'id' => bin2hex(random_bytes(12)),
            'createdAt' => gmdate('c')
        ]);
        $error = paymentValidationError($record, $clientLookup);
        if ($error === '') {
            $records[] = $record;
            hxPaymentSave($paymentsFile, $records);
            paymentRedirect('Payment schedule added.', $record['clientEmail']);
        }
    } elseif (isset($_POST['update_payment'])) {
        $recordIndex = hxPaymentFindIndex($records, cleanText($_POST['payment_id'] ?? ''));
        if ($recordIndex === -1) {
            $error = 'Payment record not found.';
        } else {
            $record = paymentRecordFromPost($records[$recordIndex]);
            $error = paymentValidationError($record, $clientLookup);
            if ($error === '') {
                $records[$recordIndex] = $record;
                hxPaymentSave($paymentsFile, $records);
                paymentRedirect('Payment record updated.', $record['clientEmail']);
            }
        }
    } elseif (isset($_POST['delete_payment'])) {
        $recordIndex = hxPaymentFindIndex($records, cleanText($_POST['payment_id'] ?? ''));
        if ($recordIndex === -1) {
            $error = 'Payment record not found.';
        } else {
            $clientEmail = strtolower((string)($records[$recordIndex]['clientEmail'] ?? ''));
            array_splice($records, $recordIndex, 1);
            hxPaymentSave($paymentsFile, $records);
            paymentRedirect('Payment record deleted.', $clientEmail);
        }
    }
}

$today = date('Y-m-d');
$summary = ['pending' => 0, 'overdue' => 0, 'paid' => 0, 'missed' => 0];
$outstandingByCurrency = [];
foreach ($records as $record) {
    if ($selectedClient !== '' && strtolower((string)($record['clientEmail'] ?? '')) !== $selectedClient) continue;
    $status = hxPaymentEffectiveStatus($record, $today);
    $summary[$status]++;
    if (in_array($status, ['pending', 'overdue', 'missed'], true)) {
        $currency = cleanCurrency($record['currency'] ?? 'USD');
        $outstandingByCurrency[$currency] = ($outstandingByCurrency[$currency] ?? 0) + (float)($record['amount'] ?? 0);
    }
}
ksort($outstandingByCurrency);

$visibleRecords = array_values(array_filter($records, function ($record) use ($selectedClient, $selectedStatus, $today) {
    if ($selectedClient !== '' && strtolower((string)($record['clientEmail'] ?? '')) !== $selectedClient) return false;
    if ($selectedStatus !== '' && hxPaymentEffectiveStatus($record, $today) !== $selectedStatus) return false;
    return true;
}));
usort($visibleRecords, function ($a, $b) use ($today) {
    $order = ['overdue' => 0, 'pending' => 1, 'missed' => 2, 'paid' => 3];
    $aStatus = hxPaymentEffectiveStatus($a, $today);
    $bStatus = hxPaymentEffectiveStatus($b, $today);
    if ($order[$aStatus] !== $order[$bStatus]) return $order[$aStatus] - $order[$bStatus];
    return strcmp((string)($a['dueDate'] ?? ''), (string)($b['dueDate'] ?? ''));
});

$selectedUser = $selectedClient !== '' ? ($clientLookup[$selectedClient] ?? null) : null;
$formClient = strtolower(cleanText($_POST['client_email'] ?? ($selectedUser['email'] ?? '')));
$formCurrency = cleanCurrency($_POST['currency'] ?? ($selectedUser['currency'] ?? 'USD'));

pageHeader('Payment schedule');
pageTop('payments');
if ($message) echo '<div class="notice success">' . htmlspecialchars($message) . '</div>';
if ($error) echo '<div class="notice error">' . htmlspecialchars($error) . '</div>';
?>

<div class="card">
  <div class="section-title">
    <div>
      <h2><?= $selectedUser ? htmlspecialchars($selectedUser['name'] ?? '') . ' · Payment schedule' : 'Client payment schedule' ?></h2>
      <p class="hint" style="margin:0">Track due dates, payment status, and the reason a payment was not received.</p>
    </div>
    <?php if ($selectedClient !== ''): ?><a class="btn btn-light" href="payments.php">View all clients</a><?php endif; ?>
  </div>
  <div class="stats payment-stats">
    <a class="stat" href="payments.php?<?= http_build_query(array_filter(['client' => $selectedClient, 'status' => 'pending'])) ?>"><small>Pending</small><strong><?= $summary['pending'] ?></strong></a>
    <a class="stat" href="payments.php?<?= http_build_query(array_filter(['client' => $selectedClient, 'status' => 'overdue'])) ?>"><small>Overdue</small><strong><?= $summary['overdue'] ?></strong></a>
    <a class="stat" href="payments.php?<?= http_build_query(array_filter(['client' => $selectedClient, 'status' => 'paid'])) ?>"><small>Paid</small><strong><?= $summary['paid'] ?></strong></a>
    <a class="stat" href="payments.php?<?= http_build_query(array_filter(['client' => $selectedClient, 'status' => 'missed'])) ?>"><small>Not paid</small><strong><?= $summary['missed'] ?></strong></a>
  </div>
  <?php if ($outstandingByCurrency): ?>
    <div class="outstanding-strip"><b>Outstanding:</b>
      <?php foreach ($outstandingByCurrency as $currency => $amount): ?>
        <span><?= htmlspecialchars(formatMoney($amount, $currency)) ?> <?= htmlspecialchars($currency) ?></span>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="card" id="add-payment">
  <div class="section-title"><div><h2>Add scheduled payment</h2><p class="hint" style="margin:0">Create a due item for an existing HarbourX client.</p></div></div>
  <?php if (!$users): ?>
    <div class="empty">Create a client first, then add their payment schedule.</div>
  <?php else: ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
      <div class="grid payment-form-grid">
        <div class="field"><label>Client</label><select name="client_email" id="paymentClient" required>
          <option value="">Choose client</option>
          <?php foreach ($users as $user): $email = strtolower((string)($user['email'] ?? '')); ?>
            <option value="<?= htmlspecialchars($email) ?>" data-currency="<?= htmlspecialchars(cleanCurrency($user['currency'] ?? 'USD')) ?>" <?= $formClient === $email ? 'selected' : '' ?>><?= htmlspecialchars(($user['name'] ?? '') . ' · ' . $email) ?></option>
          <?php endforeach; ?>
        </select></div>
        <div class="field"><label>Amount</label><input type="number" min="0.01" step="0.01" name="amount" value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>" placeholder="0.00" required></div>
        <div class="field"><label>Currency</label><select name="currency" id="paymentCurrency" required><?= renderCurrencyOptions($formCurrency) ?></select></div>
        <div class="field"><label>Due date</label><input type="date" name="due_date" value="<?= htmlspecialchars($_POST['due_date'] ?? '') ?>" required></div>
        <div class="field"><label>Status</label><select name="status" id="newPaymentStatus">
          <option value="pending">Pending</option><option value="paid">Paid</option><option value="missed">Not paid</option>
        </select></div>
        <div class="field"><label>Paid date</label><input type="date" name="paid_at" id="newPaidAt"></div>
        <div class="field payment-reason-field"><label>Reason if not paid</label><input name="non_payment_reason" placeholder="Client explanation or follow-up note"></div>
        <div class="field"><label>Internal notes</label><input name="notes" placeholder="Optional private note"></div>
      </div>
      <br><button class="btn btn-blue" type="submit" name="create_payment">Add payment</button>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <div class="section-title">
    <div><h2>Payments</h2><p class="hint" style="margin:0"><?= count($visibleRecords) ?> record<?= count($visibleRecords) === 1 ? '' : 's' ?> shown<?= $selectedStatus ? ' · ' . htmlspecialchars(hxPaymentStatusLabel($selectedStatus)) : '' ?></p></div>
    <form method="get" class="payment-filter">
      <select name="client"><option value="">All clients</option><?php foreach ($users as $user): $email = strtolower((string)($user['email'] ?? '')); ?><option value="<?= htmlspecialchars($email) ?>" <?= $selectedClient === $email ? 'selected' : '' ?>><?= htmlspecialchars($user['name'] ?? $email) ?></option><?php endforeach; ?></select>
      <select name="status"><option value="">All statuses</option><?php foreach (['pending', 'overdue', 'paid', 'missed'] as $status): ?><option value="<?= $status ?>" <?= $selectedStatus === $status ? 'selected' : '' ?>><?= htmlspecialchars(hxPaymentStatusLabel($status)) ?></option><?php endforeach; ?></select>
      <button class="btn btn-light" type="submit">Filter</button>
    </form>
  </div>

  <?php if (!$visibleRecords): ?>
    <div class="empty">No payment records match this view.</div>
  <?php else: ?>
    <div class="payment-list">
      <?php foreach ($visibleRecords as $record):
        $email = strtolower((string)($record['clientEmail'] ?? ''));
        $client = $clientLookup[$email] ?? [];
        $status = hxPaymentEffectiveStatus($record, $today);
      ?>
        <article class="payment-card payment-<?= htmlspecialchars($status) ?>">
          <div class="payment-card-head">
            <div class="client-top" style="margin:0">
              <div class="avatar"><?= htmlspecialchars(initials($client['name'] ?? $email)) ?></div>
              <div><p class="name"><?= htmlspecialchars($client['name'] ?? 'Removed client') ?></p><div class="email"><?= htmlspecialchars($email) ?></div></div>
            </div>
            <span class="payment-status payment-status-<?= htmlspecialchars($status) ?>"><?= htmlspecialchars(hxPaymentStatusLabel($status)) ?></span>
          </div>
          <div class="payment-summary-grid">
            <div><small>Amount</small><strong><?= htmlspecialchars(formatMoney($record['amount'] ?? 0, $record['currency'] ?? 'USD')) ?> <?= htmlspecialchars(cleanCurrency($record['currency'] ?? 'USD')) ?></strong></div>
            <div><small>Due date</small><strong><?= htmlspecialchars($record['dueDate'] ?? '') ?></strong></div>
            <div><small>Paid date</small><strong><?= htmlspecialchars($record['paidAt'] ?: '—') ?></strong></div>
            <div><small>Reason not paid</small><strong><?= htmlspecialchars($record['nonPaymentReason'] ?: '—') ?></strong></div>
          </div>
          <?php if (!empty($record['notes'])): ?><p class="payment-note"><b>Internal note:</b> <?= htmlspecialchars($record['notes']) ?></p><?php endif; ?>
          <details class="payment-editor">
            <summary>Edit payment</summary>
            <form method="post">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
              <input type="hidden" name="payment_id" value="<?= htmlspecialchars($record['id'] ?? '') ?>">
              <input type="hidden" name="client_email" value="<?= htmlspecialchars($email) ?>">
              <div class="grid payment-form-grid">
                <div class="field"><label>Amount</label><input type="number" min="0.01" step="0.01" name="amount" value="<?= htmlspecialchars(number_format((float)($record['amount'] ?? 0), 2, '.', '')) ?>" required></div>
                <div class="field"><label>Currency</label><select name="currency"><?= renderCurrencyOptions(cleanCurrency($record['currency'] ?? 'USD')) ?></select></div>
                <div class="field"><label>Due date</label><input type="date" name="due_date" value="<?= htmlspecialchars($record['dueDate'] ?? '') ?>" required></div>
                <div class="field"><label>Status</label><select name="status">
                  <?php foreach (['pending' => 'Pending', 'paid' => 'Paid', 'missed' => 'Not paid'] as $value => $label): ?><option value="<?= $value ?>" <?= ($record['status'] ?? '') === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
                </select></div>
                <div class="field"><label>Paid date</label><input type="date" name="paid_at" value="<?= htmlspecialchars($record['paidAt'] ?? '') ?>"></div>
                <div class="field"><label>Reason if not paid</label><input name="non_payment_reason" value="<?= htmlspecialchars($record['nonPaymentReason'] ?? '') ?>" placeholder="Required for Not paid"></div>
                <div class="field"><label>Internal notes</label><input name="notes" value="<?= htmlspecialchars($record['notes'] ?? '') ?>"></div>
              </div>
              <div class="actions" style="margin-top:14px">
                <button class="btn btn-blue" type="submit" name="update_payment">Save changes</button>
                <button class="btn btn-red" type="submit" name="delete_payment" onclick="return confirm('Delete this payment record?')">Delete record</button>
                <a class="btn btn-light" href="client.php?email=<?= urlencode($email) ?>">Open client</a>
              </div>
            </form>
          </details>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<script>
const clientSelect = document.getElementById('paymentClient');
const currencySelect = document.getElementById('paymentCurrency');
if (clientSelect && currencySelect) clientSelect.addEventListener('change', () => {
  const option = clientSelect.options[clientSelect.selectedIndex];
  if (option && option.dataset.currency) currencySelect.value = option.dataset.currency;
});
</script>
<?php pageFooter(); ?>
