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
    if ($status === 'paid' && $paidAt === '') {
        $paidAt = (new DateTimeImmutable('now', new DateTimeZone(HX_ADMIN_TIMEZONE)))->format('Y-m-d');
    }
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
    } elseif (isset($_POST['mark_payment_paid'])) {
        $recordIndex = hxPaymentFindIndex($records, cleanText($_POST['payment_id'] ?? ''));
        if ($recordIndex === -1) {
            $error = 'Payment record not found.';
        } else {
            $records[$recordIndex]['status'] = 'paid';
            $records[$recordIndex]['paidAt'] = (new DateTimeImmutable('now', new DateTimeZone(HX_ADMIN_TIMEZONE)))->format('Y-m-d');
            $records[$recordIndex]['updatedAt'] = gmdate('c');
            hxPaymentSave($paymentsFile, $records);
            paymentRedirect('Payment marked paid.', strtolower((string)($records[$recordIndex]['clientEmail'] ?? '')));
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

$today = (new DateTimeImmutable('now', new DateTimeZone(HX_ADMIN_TIMEZONE)))->format('Y-m-d');
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

function paymentDueHint($dueDate, $today) {
    if (!hxPaymentValidDate($dueDate) || !hxPaymentValidDate($today)) return '';
    $due = new DateTimeImmutable($dueDate);
    $current = new DateTimeImmutable($today);
    $days = (int)$current->diff($due)->format('%r%a');
    if ($days === 0) return 'Due today';
    if ($days === 1) return 'Due tomorrow';
    if ($days > 1) return 'Due in ' . $days . ' days';
    if ($days === -1) return '1 day overdue';
    return abs($days) . ' days overdue';
}

pageHeader('Payment schedule');
pageTop('payments');
if ($message) echo '<div class="notice success">' . htmlspecialchars($message) . '</div>';
if ($error) echo '<div class="notice error">' . htmlspecialchars($error) . '</div>';
?>

<section class="card ops-hero">
  <div class="ops-hero-copy">
    <span class="ops-eyebrow">Money in</span>
    <h2><?= $selectedUser ? htmlspecialchars($selectedUser['name'] ?? '') . ' · Payments' : 'Payment schedule' ?></h2>
    <p>See what is due, follow up late payments, and close paid items quickly.</p>
  </div>
  <div class="actions">
    <?php if ($selectedClient !== ''): ?><a class="btn btn-light" href="payments.php">All clients</a><?php endif; ?>
    <button class="btn btn-blue" type="button" data-panel-toggle="add-payment" aria-expanded="<?= $error ? 'true' : 'false' ?>">+ New payment</button>
  </div>
  <div class="ops-metrics">
    <a class="ops-metric metric-pending<?= $selectedStatus === 'pending' ? ' is-active' : '' ?>" href="payments.php?<?= http_build_query(array_filter(['client' => $selectedClient, 'status' => 'pending'])) ?>"><span>Pending</span><strong><?= $summary['pending'] ?></strong></a>
    <a class="ops-metric metric-danger<?= $selectedStatus === 'overdue' ? ' is-active' : '' ?>" href="payments.php?<?= http_build_query(array_filter(['client' => $selectedClient, 'status' => 'overdue'])) ?>"><span>Overdue</span><strong><?= $summary['overdue'] ?></strong></a>
    <a class="ops-metric metric-success<?= $selectedStatus === 'paid' ? ' is-active' : '' ?>" href="payments.php?<?= http_build_query(array_filter(['client' => $selectedClient, 'status' => 'paid'])) ?>"><span>Paid</span><strong><?= $summary['paid'] ?></strong></a>
    <a class="ops-metric metric-muted<?= $selectedStatus === 'missed' ? ' is-active' : '' ?>" href="payments.php?<?= http_build_query(array_filter(['client' => $selectedClient, 'status' => 'missed'])) ?>"><span>Not paid</span><strong><?= $summary['missed'] ?></strong></a>
  </div>
  <?php if ($outstandingByCurrency): ?>
    <div class="ops-total"><span>Outstanding</span><?php foreach ($outstandingByCurrency as $currency => $amount): ?><b><?= htmlspecialchars(formatMoney($amount, $currency)) ?> <?= htmlspecialchars($currency) ?></b><?php endforeach; ?></div>
  <?php endif; ?>
</section>

<section class="card ops-create-panel" id="add-payment" data-open="<?= $error ? 'true' : 'false' ?>" <?= $error ? '' : 'hidden' ?>>
  <div class="section-title"><div><span class="ops-eyebrow">Quick entry</span><h2>Add payment</h2><p class="hint" style="margin:0">Only the four essentials are required.</p></div></div>
  <?php if (!$users): ?>
    <div class="empty">Create a client first, then add their payment schedule.</div>
  <?php else: ?>
    <form method="post" data-payment-form>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
      <div class="ops-form-grid">
        <div class="field ops-span-2"><label>Client</label><select name="client_email" id="paymentClient" required>
          <option value="">Choose client</option>
          <?php foreach ($users as $user): $email = strtolower((string)($user['email'] ?? '')); ?>
            <option value="<?= htmlspecialchars($email) ?>" data-currency="<?= htmlspecialchars(cleanCurrency($user['currency'] ?? 'USD')) ?>" <?= $formClient === $email ? 'selected' : '' ?>><?= htmlspecialchars(($user['name'] ?? '') . ' · ' . $email) ?></option>
          <?php endforeach; ?>
        </select></div>
        <div class="field"><label>Amount</label><input type="number" min="0.01" step="0.01" name="amount" value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>" placeholder="0.00" required></div>
        <div class="field"><label>Currency</label><select name="currency" id="paymentCurrency" required><?= renderCurrencyOptions($formCurrency) ?></select></div>
        <div class="field"><label>Due date</label><input type="date" name="due_date" value="<?= htmlspecialchars($_POST['due_date'] ?? '') ?>" required></div>
        <div class="field ops-span-2"><label>Internal note <span>optional</span></label><input name="notes" value="<?= htmlspecialchars($_POST['notes'] ?? '') ?>" placeholder="Short note for your team"></div>
      </div>
      <details class="ops-advanced">
        <summary>More options</summary>
        <div class="ops-form-grid">
          <div class="field"><label>Starting status</label><select name="status" data-payment-status>
            <?php $postedStatus = hxPaymentStoredStatus($_POST['status'] ?? 'pending'); ?>
            <option value="pending" <?= $postedStatus === 'pending' ? 'selected' : '' ?>>Pending</option><option value="paid" <?= $postedStatus === 'paid' ? 'selected' : '' ?>>Paid</option><option value="missed" <?= $postedStatus === 'missed' ? 'selected' : '' ?>>Not paid</option>
          </select></div>
          <div class="field" data-show-for="paid"><label>Paid date</label><input type="date" name="paid_at" value="<?= htmlspecialchars($_POST['paid_at'] ?? '') ?>"></div>
          <div class="field ops-span-2" data-show-for="missed" data-required-for="missed"><label>Reason not paid</label><input name="non_payment_reason" value="<?= htmlspecialchars($_POST['non_payment_reason'] ?? '') ?>" placeholder="Client explanation or follow-up note"></div>
        </div>
      </details>
      <div class="ops-form-actions"><button class="btn btn-blue" type="submit" name="create_payment">Save payment</button><button class="btn btn-light" type="button" data-panel-toggle="add-payment">Cancel</button></div>
    </form>
  <?php endif; ?>
</section>

<section class="card ops-list-card" data-ops-scope>
  <div class="ops-toolbar">
    <div><h2>Payments</h2><p class="hint"><span data-visible-count><?= count($visibleRecords) ?></span> shown<?= $selectedStatus ? ' · ' . htmlspecialchars(hxPaymentStatusLabel($selectedStatus)) : '' ?></p></div>
    <div class="ops-toolbar-controls">
      <label class="ops-search"><span>Search</span><input type="search" data-ops-search placeholder="Name, email, amount…"></label>
      <form method="get" class="payment-filter">
        <select name="client" aria-label="Filter by client"><option value="">All clients</option><?php foreach ($users as $user): $email = strtolower((string)($user['email'] ?? '')); ?><option value="<?= htmlspecialchars($email) ?>" <?= $selectedClient === $email ? 'selected' : '' ?>><?= htmlspecialchars($user['name'] ?? $email) ?></option><?php endforeach; ?></select>
        <select name="status" aria-label="Filter by status"><option value="">All statuses</option><?php foreach (['pending', 'overdue', 'paid', 'missed'] as $status): ?><option value="<?= $status ?>" <?= $selectedStatus === $status ? 'selected' : '' ?>><?= htmlspecialchars(hxPaymentStatusLabel($status)) ?></option><?php endforeach; ?></select>
        <button class="btn btn-light" type="submit">Apply</button>
      </form>
    </div>
  </div>

  <?php if (!$visibleRecords): ?>
    <div class="empty">No payment records match this view.</div>
  <?php else: ?>
    <div class="ops-record-list">
      <?php foreach ($visibleRecords as $record):
        $email = strtolower((string)($record['clientEmail'] ?? ''));
        $client = $clientLookup[$email] ?? [];
        $status = hxPaymentEffectiveStatus($record, $today);
        $amountLabel = formatMoney($record['amount'] ?? 0, $record['currency'] ?? 'USD') . ' ' . cleanCurrency($record['currency'] ?? 'USD');
      ?>
        <article class="ops-record payment-<?= htmlspecialchars($status) ?>" data-ops-record data-search="<?= htmlspecialchars(strtolower(($client['name'] ?? '') . ' ' . $email . ' ' . $amountLabel . ' ' . $status . ' ' . ($record['notes'] ?? '') . ' ' . ($record['nonPaymentReason'] ?? ''))) ?>">
          <div class="ops-record-main">
            <div class="client-top ops-client" style="margin:0">
              <div class="avatar"><?= htmlspecialchars(initials($client['name'] ?? $email)) ?></div>
              <div><p class="name"><?= htmlspecialchars($client['name'] ?? 'Removed client') ?></p><div class="email"><?= htmlspecialchars($email) ?></div></div>
            </div>
            <div class="ops-record-value"><small>Amount</small><strong><?= htmlspecialchars($amountLabel) ?></strong></div>
            <div class="ops-record-value"><small>Due</small><strong><?= htmlspecialchars((new DateTimeImmutable($record['dueDate']))->format('M j, Y')) ?></strong><span><?= htmlspecialchars(paymentDueHint($record['dueDate'], $today)) ?></span></div>
            <span class="payment-status payment-status-<?= htmlspecialchars($status) ?>"><?= htmlspecialchars(hxPaymentStatusLabel($status)) ?></span>
          </div>
          <?php if (!empty($record['nonPaymentReason']) || !empty($record['notes']) || !empty($record['paidAt'])): ?>
            <div class="ops-record-context">
              <?php if (!empty($record['nonPaymentReason'])): ?><span><b>Reason:</b> <?= htmlspecialchars($record['nonPaymentReason']) ?></span><?php endif; ?>
              <?php if (!empty($record['paidAt'])): ?><span><b>Paid:</b> <?= htmlspecialchars($record['paidAt']) ?></span><?php endif; ?>
              <?php if (!empty($record['notes'])): ?><span><b>Note:</b> <?= htmlspecialchars($record['notes']) ?></span><?php endif; ?>
            </div>
          <?php endif; ?>
          <div class="ops-record-actions">
            <?php if ($status !== 'paid'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>"><input type="hidden" name="payment_id" value="<?= htmlspecialchars($record['id'] ?? '') ?>"><button class="btn btn-blue" type="submit" name="mark_payment_paid">✓ Mark paid</button></form><?php endif; ?>
            <a class="btn btn-light" href="callbacks.php?client=<?= urlencode($email) ?>#schedule-callback">Schedule callback</a>
            <details class="ops-editor">
              <summary class="btn btn-light">Edit</summary>
              <form method="post" data-payment-form>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="payment_id" value="<?= htmlspecialchars($record['id'] ?? '') ?>">
                <input type="hidden" name="client_email" value="<?= htmlspecialchars($email) ?>">
                <div class="ops-form-grid">
                  <div class="field"><label>Amount</label><input type="number" min="0.01" step="0.01" name="amount" value="<?= htmlspecialchars(number_format((float)($record['amount'] ?? 0), 2, '.', '')) ?>" required></div>
                  <div class="field"><label>Currency</label><select name="currency"><?= renderCurrencyOptions(cleanCurrency($record['currency'] ?? 'USD')) ?></select></div>
                  <div class="field"><label>Due date</label><input type="date" name="due_date" value="<?= htmlspecialchars($record['dueDate'] ?? '') ?>" required></div>
                  <div class="field"><label>Status</label><select name="status" data-payment-status><?php foreach (['pending' => 'Pending', 'paid' => 'Paid', 'missed' => 'Not paid'] as $value => $label): ?><option value="<?= $value ?>" <?= ($record['status'] ?? '') === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
                  <div class="field" data-show-for="paid"><label>Paid date</label><input type="date" name="paid_at" value="<?= htmlspecialchars($record['paidAt'] ?? '') ?>"></div>
                  <div class="field ops-span-2" data-show-for="missed" data-required-for="missed"><label>Reason not paid</label><input name="non_payment_reason" value="<?= htmlspecialchars($record['nonPaymentReason'] ?? '') ?>" placeholder="Required for Not paid"></div>
                  <div class="field ops-span-2"><label>Internal note</label><input name="notes" value="<?= htmlspecialchars($record['notes'] ?? '') ?>"></div>
                </div>
                <div class="actions"><button class="btn btn-blue" type="submit" name="update_payment">Save</button><a class="btn btn-light" href="client.php?email=<?= urlencode($email) ?>">Open client</a><button class="btn btn-red" type="submit" name="delete_payment" onclick="return confirm('Delete this payment record?')">Delete</button></div>
              </form>
            </details>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
    <div class="empty" data-search-empty hidden>No visible payments match your search.</div>
  <?php endif; ?>
</section>

<script>
const clientSelect = document.getElementById('paymentClient');
const currencySelect = document.getElementById('paymentCurrency');
if (clientSelect && currencySelect) clientSelect.addEventListener('change', () => {
  const option = clientSelect.options[clientSelect.selectedIndex];
  if (option && option.dataset.currency) currencySelect.value = option.dataset.currency;
});
</script>
<?php pageFooter(); ?>
