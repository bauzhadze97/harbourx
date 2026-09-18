<?php
require_once __DIR__ . '/admin_common.php';
requireAdmin();

$callbacksFile = __DIR__ . '/data/client_callbacks.json';
$users = loadUsers($usersFile);
$records = hxFollowupLoad($callbacksFile);
$error = '';
$message = cleanText($_GET['msg'] ?? '');
$selectedClient = strtolower(cleanText($_GET['client'] ?? ''));
$selectedStatus = strtolower(cleanText($_GET['status'] ?? ''));
$allowedStatusFilters = ['upcoming', 'due', 'completed', 'cancelled'];
if ($selectedStatus !== '' && !in_array($selectedStatus, $allowedStatusFilters, true)) $selectedStatus = '';
$csrfToken = hxFollowupCsrfToken();
$clientLookup = hxAdminClientLookup($users);

function callbackRedirect($message, $clientEmail = '') {
    $query = ['msg' => $message];
    if ($clientEmail !== '') $query['client'] = $clientEmail;
    header('Location: callbacks.php?' . http_build_query($query));
    exit;
}

function callbackRecordFromPost($existing = []) {
    $status = hxFollowupStoredStatus($_POST['status'] ?? 'scheduled');
    $completedAt = (string)($existing['completedAt'] ?? '');
    if ($status === 'completed' && $completedAt === '') $completedAt = gmdate('c');
    if ($status !== 'completed') $completedAt = '';

    return array_merge($existing, [
        'clientEmail' => strtolower(cleanText($_POST['client_email'] ?? '')),
        'scheduledAt' => hxFollowupInputToUtc($_POST['callback_at'] ?? '', HX_ADMIN_TIMEZONE),
        'subject' => cleanText($_POST['subject'] ?? ''),
        'status' => $status,
        'notes' => cleanText($_POST['notes'] ?? ''),
        'outcome' => cleanText($_POST['outcome'] ?? ''),
        'completedAt' => $completedAt,
        'updatedAt' => gmdate('c')
    ]);
}

function callbackValidationError($record, $clientLookup) {
    if (!isset($clientLookup[strtolower((string)($record['clientEmail'] ?? ''))])) return 'Choose a valid client.';
    if (trim((string)($record['scheduledAt'] ?? '')) === '') return 'Choose a valid callback date and time.';
    if (trim((string)($record['subject'] ?? '')) === '') return 'Add a callback subject.';
    return '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hxFollowupCsrfValid($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Refresh the page and try again.';
    } elseif (isset($_POST['create_callback'])) {
        $record = callbackRecordFromPost([
            'id' => bin2hex(random_bytes(12)),
            'createdAt' => gmdate('c')
        ]);
        $error = callbackValidationError($record, $clientLookup);
        if ($error === '') {
            $records[] = $record;
            hxFollowupSave($callbacksFile, $records);
            callbackRedirect('Callback scheduled.', $record['clientEmail']);
        }
    } elseif (isset($_POST['update_callback'])) {
        $recordIndex = hxFollowupFindIndex($records, cleanText($_POST['callback_id'] ?? ''));
        if ($recordIndex === -1) {
            $error = 'Callback record not found.';
        } else {
            $record = callbackRecordFromPost($records[$recordIndex]);
            $error = callbackValidationError($record, $clientLookup);
            if ($error === '') {
                $records[$recordIndex] = $record;
                hxFollowupSave($callbacksFile, $records);
                callbackRedirect('Callback updated.', $record['clientEmail']);
            }
        }
    } elseif (isset($_POST['complete_callback'])) {
        $recordIndex = hxFollowupFindIndex($records, cleanText($_POST['callback_id'] ?? ''));
        if ($recordIndex === -1) {
            $error = 'Callback record not found.';
        } else {
            $records[$recordIndex]['status'] = 'completed';
            $records[$recordIndex]['outcome'] = cleanText($_POST['outcome'] ?? ($records[$recordIndex]['outcome'] ?? ''));
            $records[$recordIndex]['completedAt'] = gmdate('c');
            $records[$recordIndex]['updatedAt'] = gmdate('c');
            hxFollowupSave($callbacksFile, $records);
            callbackRedirect('Callback marked completed.', strtolower((string)($records[$recordIndex]['clientEmail'] ?? '')));
        }
    } elseif (isset($_POST['delete_callback'])) {
        $recordIndex = hxFollowupFindIndex($records, cleanText($_POST['callback_id'] ?? ''));
        if ($recordIndex === -1) {
            $error = 'Callback record not found.';
        } else {
            $clientEmail = strtolower((string)($records[$recordIndex]['clientEmail'] ?? ''));
            array_splice($records, $recordIndex, 1);
            hxFollowupSave($callbacksFile, $records);
            callbackRedirect('Callback deleted.', $clientEmail);
        }
    }
}

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$summary = ['upcoming' => 0, 'due' => 0, 'completed' => 0, 'cancelled' => 0];
foreach ($records as $record) {
    if ($selectedClient !== '' && strtolower((string)($record['clientEmail'] ?? '')) !== $selectedClient) continue;
    $status = hxFollowupEffectiveStatus($record, $now);
    $summary[$status] = ($summary[$status] ?? 0) + 1;
}

$visibleRecords = array_values(array_filter($records, function ($record) use ($selectedClient, $selectedStatus, $now) {
    if ($selectedClient !== '' && strtolower((string)($record['clientEmail'] ?? '')) !== $selectedClient) return false;
    if ($selectedStatus !== '' && hxFollowupEffectiveStatus($record, $now) !== $selectedStatus) return false;
    return true;
}));
usort($visibleRecords, function ($a, $b) use ($now) {
    $order = ['due' => 0, 'upcoming' => 1, 'completed' => 2, 'cancelled' => 3];
    $aStatus = hxFollowupEffectiveStatus($a, $now);
    $bStatus = hxFollowupEffectiveStatus($b, $now);
    if ($order[$aStatus] !== $order[$bStatus]) return $order[$aStatus] - $order[$bStatus];
    return strcmp((string)($a['scheduledAt'] ?? ''), (string)($b['scheduledAt'] ?? ''));
});

$selectedUser = $selectedClient !== '' ? ($clientLookup[$selectedClient] ?? null) : null;
$formClient = strtolower(cleanText($_POST['client_email'] ?? ($selectedUser['email'] ?? '')));
$defaultCallback = (new DateTimeImmutable('+1 hour', new DateTimeZone(HX_ADMIN_TIMEZONE)))->format('Y-m-d\TH:i');

pageHeader('Client callbacks');
pageTop('callbacks');
if ($message) echo '<div class="notice success">' . htmlspecialchars($message) . '</div>';
if ($error) echo '<div class="notice error">' . htmlspecialchars($error) . '</div>';
?>

<section class="card ops-hero">
  <div class="ops-hero-copy">
    <span class="ops-eyebrow">Follow-up desk</span>
    <h2><?= $selectedUser ? htmlspecialchars($selectedUser['name'] ?? '') . ' · Callbacks' : 'Client callbacks' ?></h2>
    <p>Plan the next conversation, see what needs attention now, and keep a clear outcome history.</p>
  </div>
  <div class="actions">
    <?php if ($selectedClient !== ''): ?><a class="btn btn-light" href="callbacks.php">All clients</a><?php endif; ?>
    <button class="btn btn-blue" type="button" data-panel-toggle="schedule-callback" aria-expanded="<?= $error ? 'true' : 'false' ?>">+ Schedule callback</button>
  </div>
  <div class="ops-metrics">
    <a class="ops-metric metric-danger<?= $selectedStatus === 'due' ? ' is-active' : '' ?>" href="callbacks.php?<?= http_build_query(array_filter(['client' => $selectedClient, 'status' => 'due'])) ?>"><span>Call due</span><strong><?= $summary['due'] ?></strong></a>
    <a class="ops-metric metric-pending<?= $selectedStatus === 'upcoming' ? ' is-active' : '' ?>" href="callbacks.php?<?= http_build_query(array_filter(['client' => $selectedClient, 'status' => 'upcoming'])) ?>"><span>Upcoming</span><strong><?= $summary['upcoming'] ?></strong></a>
    <a class="ops-metric metric-success<?= $selectedStatus === 'completed' ? ' is-active' : '' ?>" href="callbacks.php?<?= http_build_query(array_filter(['client' => $selectedClient, 'status' => 'completed'])) ?>"><span>Completed</span><strong><?= $summary['completed'] ?></strong></a>
    <a class="ops-metric metric-muted<?= $selectedStatus === 'cancelled' ? ' is-active' : '' ?>" href="callbacks.php?<?= http_build_query(array_filter(['client' => $selectedClient, 'status' => 'cancelled'])) ?>"><span>Cancelled</span><strong><?= $summary['cancelled'] ?></strong></a>
  </div>
</section>

<section class="card ops-create-panel" id="schedule-callback" data-open="<?= $error ? 'true' : 'false' ?>" <?= $error ? '' : 'hidden' ?>>
  <div class="section-title"><div><span class="ops-eyebrow">Quick schedule</span><h2>New callback</h2><p class="hint" style="margin:0">Times use <?= htmlspecialchars(HX_ADMIN_TIMEZONE) ?>.</p></div></div>
  <?php if (!$users): ?>
    <div class="empty">Create a client first, then schedule a callback.</div>
  <?php else: ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
      <div class="ops-form-grid">
        <div class="field ops-span-2"><label>Client</label><select name="client_email" required>
          <option value="">Choose client</option>
          <?php foreach ($users as $user): $email = strtolower((string)($user['email'] ?? '')); ?>
            <option value="<?= htmlspecialchars($email) ?>" <?= $formClient === $email ? 'selected' : '' ?>><?= htmlspecialchars(($user['name'] ?? '') . ' · ' . $email) ?></option>
          <?php endforeach; ?>
        </select></div>
        <div class="field"><label>Date and time</label><input type="datetime-local" name="callback_at" value="<?= htmlspecialchars($_POST['callback_at'] ?? $defaultCallback) ?>" required></div>
        <div class="field ops-span-2"><label>Reason</label><input name="subject" value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>" placeholder="Payment follow-up, account review…" required></div>
        <div class="field ops-span-2"><label>Preparation note <span>optional</span></label><input name="notes" value="<?= htmlspecialchars($_POST['notes'] ?? '') ?>" placeholder="What should you remember before calling?"></div>
      </div>
      <input type="hidden" name="status" value="scheduled">
      <div class="ops-form-actions"><button class="btn btn-blue" type="submit" name="create_callback">Schedule callback</button><button class="btn btn-light" type="button" data-panel-toggle="schedule-callback">Cancel</button></div>
    </form>
  <?php endif; ?>
</section>

<section class="card ops-list-card" data-ops-scope>
  <div class="ops-toolbar">
    <div><h2>Callbacks</h2><p class="hint"><span data-visible-count><?= count($visibleRecords) ?></span> shown<?= $selectedStatus ? ' · ' . htmlspecialchars(hxFollowupStatusLabel($selectedStatus)) : '' ?></p></div>
    <div class="ops-toolbar-controls">
      <label class="ops-search"><span>Search</span><input type="search" data-ops-search placeholder="Name, email, reason…"></label>
      <form method="get" class="payment-filter">
        <select name="client" aria-label="Filter by client"><option value="">All clients</option><?php foreach ($users as $user): $email = strtolower((string)($user['email'] ?? '')); ?><option value="<?= htmlspecialchars($email) ?>" <?= $selectedClient === $email ? 'selected' : '' ?>><?= htmlspecialchars($user['name'] ?? $email) ?></option><?php endforeach; ?></select>
        <select name="status" aria-label="Filter by status"><option value="">All statuses</option><?php foreach (['due', 'upcoming', 'completed', 'cancelled'] as $status): ?><option value="<?= $status ?>" <?= $selectedStatus === $status ? 'selected' : '' ?>><?= htmlspecialchars(hxFollowupStatusLabel($status)) ?></option><?php endforeach; ?></select>
        <button class="btn btn-light" type="submit">Apply</button>
      </form>
    </div>
  </div>

  <?php if (!$visibleRecords): ?>
    <div class="empty">No callbacks match this view.</div>
  <?php else: ?>
    <div class="ops-record-list">
      <?php foreach ($visibleRecords as $record):
        $email = strtolower((string)($record['clientEmail'] ?? ''));
        $client = $clientLookup[$email] ?? [];
        $status = hxFollowupEffectiveStatus($record, $now);
      ?>
        <article class="ops-record callback-<?= htmlspecialchars($status) ?>" data-ops-record data-search="<?= htmlspecialchars(strtolower(($client['name'] ?? '') . ' ' . $email . ' ' . ($record['subject'] ?? '') . ' ' . ($record['notes'] ?? '') . ' ' . ($record['outcome'] ?? '') . ' ' . $status)) ?>">
          <div class="ops-record-main">
            <div class="client-top ops-client" style="margin:0">
              <div class="avatar"><?= htmlspecialchars(initials($client['name'] ?? $email)) ?></div>
              <div><p class="name"><?= htmlspecialchars($client['name'] ?? 'Removed client') ?></p><div class="email"><?= htmlspecialchars($email) ?></div></div>
            </div>
            <div class="ops-record-value ops-call-time"><small>Call at</small><strong><?= htmlspecialchars(hxFollowupDisplayTime($record['scheduledAt'] ?? '', HX_ADMIN_TIMEZONE)) ?></strong><span><?= htmlspecialchars(HX_ADMIN_TIMEZONE) ?></span></div>
            <div class="ops-record-value"><small>Reason</small><strong><?= htmlspecialchars($record['subject'] ?? '') ?></strong></div>
            <span class="payment-status callback-status-<?= htmlspecialchars($status) ?>"><?= htmlspecialchars(hxFollowupStatusLabel($status)) ?></span>
          </div>
          <?php if (!empty($record['notes']) || !empty($record['outcome'])): ?>
            <div class="ops-record-context"><?php if (!empty($record['notes'])): ?><span><b>Prepare:</b> <?= htmlspecialchars($record['notes']) ?></span><?php endif; ?><?php if (!empty($record['outcome'])): ?><span><b>Outcome:</b> <?= htmlspecialchars($record['outcome']) ?></span><?php endif; ?></div>
          <?php endif; ?>
          <div class="ops-record-actions">
            <?php if (in_array($status, ['due', 'upcoming'], true)): ?>
              <form method="post" class="ops-complete-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>"><input type="hidden" name="callback_id" value="<?= htmlspecialchars($record['id'] ?? '') ?>"><input name="outcome" placeholder="Call outcome (optional)"><button class="btn btn-blue" type="submit" name="complete_callback">✓ Complete</button></form>
            <?php endif; ?>
            <a class="btn btn-light" href="client.php?email=<?= urlencode($email) ?>">Open client</a>
            <details class="ops-editor">
              <summary class="btn btn-light">Edit</summary>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="callback_id" value="<?= htmlspecialchars($record['id'] ?? '') ?>">
                <input type="hidden" name="client_email" value="<?= htmlspecialchars($email) ?>">
                <div class="ops-form-grid">
                  <div class="field"><label>Date and time</label><input type="datetime-local" name="callback_at" value="<?= htmlspecialchars(hxFollowupUtcToInput($record['scheduledAt'] ?? '', HX_ADMIN_TIMEZONE)) ?>" required></div>
                  <div class="field ops-span-2"><label>Reason</label><input name="subject" value="<?= htmlspecialchars($record['subject'] ?? '') ?>" required></div>
                  <div class="field"><label>Status</label><select name="status"><?php foreach (['scheduled' => 'Scheduled', 'completed' => 'Completed', 'cancelled' => 'Cancelled'] as $value => $label): ?><option value="<?= $value ?>" <?= ($record['status'] ?? '') === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
                  <div class="field ops-span-2"><label>Preparation note</label><input name="notes" value="<?= htmlspecialchars($record['notes'] ?? '') ?>"></div>
                  <div class="field ops-span-2"><label>Outcome</label><input name="outcome" value="<?= htmlspecialchars($record['outcome'] ?? '') ?>"></div>
                </div>
                <div class="actions"><button class="btn btn-blue" type="submit" name="update_callback">Save</button><button class="btn btn-red" type="submit" name="delete_callback" onclick="return confirm('Delete this callback?')">Delete</button></div>
              </form>
            </details>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
    <div class="empty" data-search-empty hidden>No visible callbacks match your search.</div>
  <?php endif; ?>
</section>

<?php pageFooter(); ?>
