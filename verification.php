<?php
/**
 * Identity verification — documents and live sessions.
 *
 * Two ways for a client to prove who they are, and the endpoint behind both:
 *
 *   POST action=upload    a PDF document (passport, proof of address, bank
 *                         letter). Stored outside anything the web server will
 *                         serve, under a name the client does not choose.
 *   POST action=book      ask for a live screen-share session at a time they
 *                         pick. It lands in the same callbacks file the
 *                         operations console already works from, so it shows up
 *                         in the admin's list and notification centre with no
 *                         second place to look.
 *   POST action=cancel    withdraw that request.
 *   GET                   the client's own state: their documents and their
 *                         pending session.
 *   GET ?file=<id>        the document itself, to the client who uploaded it or
 *                         to a signed-in administrator. Nobody else, ever.
 *
 * A document here is a passport scan or a bank statement. It is the most
 * sensitive thing the platform holds, so the rules are deliberately narrow:
 * PDF only, checked three ways; a random filename; a directory the web server
 * is told to refuse; and every read through the session check below.
 */

require __DIR__ . '/followups_common.php';

$secureCookie = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'httponly' => true,
    'secure' => $secureCookie,
    'samesite' => 'Lax'
]);
session_start();

const HX_DOC_MAX_BYTES = 10 * 1024 * 1024;   // 10 MB
const HX_DOC_MAX_COUNT = 12;                 // per client
const HX_SESSION_SUBJECT = 'Screen-share verification session';

$usersFile = __DIR__ . '/data/users.json';
$callbacksFile = __DIR__ . '/data/client_callbacks.json';
$documentsDir = __DIR__ . '/uploads/documents';

function respond(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($payload);
    exit;
}

function loadUsers(string $file): array
{
    if (!file_exists($file)) return [];
    $users = json_decode((string)file_get_contents($file), true);
    return is_array($users) ? $users : [];
}

function saveUsers(string $file, array $users): void
{
    $json = json_encode(array_values($users), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false || file_put_contents($file, $json, LOCK_EX) === false) {
        respond(500, ['success' => false, 'message' => 'Unable to save your document.']);
    }
}

function findUserIndex(array $users, string $email): int
{
    foreach ($users as $index => $user) {
        if (strtolower((string)($user['email'] ?? '')) === $email) return $index;
    }
    return -1;
}

/** The documents on a record, always as a list. */
function documentsOf(array $user): array
{
    $documents = $user['verificationDocuments'] ?? [];
    return is_array($documents) ? array_values($documents) : [];
}

/** What a client is allowed to see about their own document. */
function publicDocument(array $document): array
{
    return [
        'id' => (string)($document['id'] ?? ''),
        'name' => (string)($document['name'] ?? 'document.pdf'),
        'size' => (int)($document['size'] ?? 0),
        'uploadedAt' => (string)($document['uploadedAt'] ?? ''),
        'status' => (string)($document['status'] ?? 'received')
    ];
}

/**
 * Keep the client's own filename for the admin to read, but never let it reach
 * the filesystem: the stored name is random, and this is only ever displayed.
 */
function displayName(string $original): string
{
    $name = basename(str_replace('\\', '/', $original));

    /* The extension comes off first and is put back at the end, so the stem is
       the only thing being cleaned. Do it the other way round and a name that
       sanitises down to nothing — a Georgian or Chinese filename, say — leaves
       the extension behind and arrives as "pdf.pdf". */
    $stem = preg_replace('/\.[A-Za-z0-9]{1,8}$/', '', $name) ?? $name;

    // Anything outside this set becomes a space rather than an underscore, so
    // "my passport (1).pdf" reads as "my passport 1.pdf" instead of landing on
    // the reviewer's screen wearing punctuation it did not ask for.
    $stem = preg_replace('/[^A-Za-z0-9 ._-]+/', ' ', $stem) ?? '';
    $stem = preg_replace('/\s+/', ' ', $stem) ?? '';
    $stem = trim($stem, ' ._-');
    if ($stem === '') $stem = 'document';

    $stem = function_exists('mb_substr') ? mb_substr($stem, 0, 116) : substr($stem, 0, 116);
    return $stem . '.pdf';
}

/* ------------------------------------------------------------ who is asking */

$clientEmail = strtolower(trim((string)($_SESSION['client_email'] ?? '')));
$isAdmin = !empty($_SESSION['admin_logged_in']);

/* ------------------------------------------------- serving a stored document
   The only route to a file in uploads/. It reads the record first and hands
   over nothing at all unless the session owns the document or belongs to an
   administrator — the path is never taken from the request, only the id. */

$requestedFile = trim((string)($_GET['file'] ?? ''));
if ($requestedFile !== '') {
    if ($clientEmail === '' && !$isAdmin) {
        respond(401, ['success' => false, 'message' => 'Sign in to view this document.']);
    }
    if (!preg_match('/^[a-f0-9]{32}$/', $requestedFile)) {
        respond(404, ['success' => false, 'message' => 'Document not found.']);
    }

    $users = loadUsers($usersFile);
    $found = null;
    foreach ($users as $user) {
        $owner = strtolower((string)($user['email'] ?? ''));
        if (!$isAdmin && $owner !== $clientEmail) continue;
        foreach (documentsOf($user) as $document) {
            if (hash_equals((string)($document['id'] ?? ''), $requestedFile)) {
                $found = $document;
                break 2;
            }
        }
    }
    if ($found === null) {
        respond(404, ['success' => false, 'message' => 'Document not found.']);
    }

    $path = $documentsDir . '/' . $requestedFile . '.pdf';
    if (!is_readable($path)) {
        respond(410, ['success' => false, 'message' => 'That document is no longer stored.']);
    }

    header('Content-Type: application/pdf');
    header('Content-Length: ' . (string)filesize($path));
    header('Content-Disposition: attachment; filename="' . displayName((string)($found['name'] ?? '')) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    readfile($path);
    exit;
}

/* --------------------------------------------------- everything else is JSON */

if ($clientEmail === '') {
    respond(401, ['success' => false, 'message' => 'Your session has expired. Sign in again.']);
}

$users = loadUsers($usersFile);
$index = findUserIndex($users, $clientEmail);
if ($index < 0) {
    respond(404, ['success' => false, 'message' => 'Account not found.']);
}

/** The session this client is currently waiting on, if any. */
function pendingSession(string $file, string $email): ?array
{
    $records = hxFollowupLoad($file);
    $latest = null;
    foreach ($records as $record) {
        if (strtolower((string)($record['clientEmail'] ?? '')) !== $email) continue;
        if ((string)($record['subject'] ?? '') !== HX_SESSION_SUBJECT) continue;
        if (hxFollowupStoredStatus($record['status'] ?? '') !== 'scheduled') continue;
        if ($latest === null || (string)$record['scheduledAt'] < (string)$latest['scheduledAt']) {
            $latest = $record;
        }
    }
    if ($latest === null) return null;
    return [
        'id' => (string)($latest['id'] ?? ''),
        'scheduledAt' => (string)($latest['scheduledAt'] ?? ''),
        'notes' => (string)($latest['notes'] ?? '')
    ];
}

function currentState(array $user, string $callbacksFile, string $email): array
{
    $status = strtolower((string)($user['amlStatus'] ?? 'unverified'));
    return [
        'success' => true,
        'amlStatus' => in_array($status, ['verified', 'under_review', 'unverified'], true) ? $status : 'unverified',
        'documents' => array_map('publicDocument', documentsOf($user)),
        'maxDocuments' => HX_DOC_MAX_COUNT,
        'maxBytes' => HX_DOC_MAX_BYTES,
        'session' => pendingSession($callbacksFile, $email)
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond(200, currentState($users[$index], $callbacksFile, $clientEmail));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$action = strtolower(trim((string)($_POST['action'] ?? '')));

/* ---------------------------------------------------------------- uploading */

if ($action === 'upload') {
    $file = $_FILES['document'] ?? null;

    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        respond(422, ['success' => false, 'message' => 'Choose a PDF to upload.']);
    }
    if (in_array((int)$file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        respond(413, ['success' => false, 'message' => 'That file is too large. The limit is 10 MB.']);
    }
    if ((int)$file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
        respond(422, ['success' => false, 'message' => 'That upload did not complete. Try again.']);
    }
    if ((int)$file['size'] <= 0) {
        respond(422, ['success' => false, 'message' => 'That file is empty.']);
    }
    if ((int)$file['size'] > HX_DOC_MAX_BYTES) {
        respond(413, ['success' => false, 'message' => 'That file is too large. The limit is 10 MB.']);
    }
    if (count(documentsOf($users[$index])) >= HX_DOC_MAX_COUNT) {
        respond(422, ['success' => false, 'message' => 'You have reached the limit of ' . HX_DOC_MAX_COUNT . ' documents. Remove one before adding another.']);
    }

    /* Three checks, because any one of them alone is a poor gate: the name says
       what the client called it, the browser's type says what their browser
       guessed, and the first bytes say what the file actually is. A PDF has to
       satisfy all three. */
    $tmp = (string)$file['tmp_name'];
    $extensionOk = strtolower((string)pathinfo((string)$file['name'], PATHINFO_EXTENSION)) === 'pdf';
    $detected = '';
    if (class_exists('finfo')) {
        $info = new finfo(FILEINFO_MIME_TYPE);
        $detected = (string)$info->file($tmp);
    }
    $typeOk = $detected === '' || $detected === 'application/pdf';
    $handle = fopen($tmp, 'rb');
    $magic = $handle ? (string)fread($handle, 5) : '';
    if ($handle) fclose($handle);
    $magicOk = $magic === '%PDF-';

    if (!$extensionOk || !$typeOk || !$magicOk) {
        respond(415, ['success' => false, 'message' => 'Only PDF documents are accepted. Export or scan to PDF and try again.']);
    }

    if (!is_dir($documentsDir) && !mkdir($documentsDir, 0700, true) && !is_dir($documentsDir)) {
        respond(500, ['success' => false, 'message' => 'Unable to store your document right now.']);
    }

    // The stored name comes from us, never from the request.
    $id = bin2hex(random_bytes(16));
    $destination = $documentsDir . '/' . $id . '.pdf';
    if (!move_uploaded_file($tmp, $destination)) {
        respond(500, ['success' => false, 'message' => 'Unable to store your document right now.']);
    }
    @chmod($destination, 0600);

    $record = [
        'id' => $id,
        'name' => displayName((string)$file['name']),
        'size' => (int)$file['size'],
        'uploadedAt' => gmdate('c'),
        'status' => 'received'
    ];
    $documents = documentsOf($users[$index]);
    $documents[] = $record;
    $users[$index]['verificationDocuments'] = $documents;

    // A document arriving is what moves an untouched account into review.
    if (strtolower((string)($users[$index]['amlStatus'] ?? 'unverified')) === 'unverified') {
        $users[$index]['amlStatus'] = 'under_review';
        $users[$index]['amlSubmittedAt'] = gmdate('c');
    }
    saveUsers($usersFile, $users);

    respond(200, array_merge(
        currentState($users[$index], $callbacksFile, $clientEmail),
        ['message' => 'Document received. It is with our compliance team.']
    ));
}

/* ----------------------------------------------------------------- removing */

if ($action === 'remove') {
    $id = trim((string)($_POST['id'] ?? ''));
    $documents = documentsOf($users[$index]);
    $kept = [];
    $removed = null;
    foreach ($documents as $document) {
        if ($removed === null && hash_equals((string)($document['id'] ?? ''), $id)) {
            $removed = $document;
            continue;
        }
        $kept[] = $document;
    }
    if ($removed === null) {
        respond(404, ['success' => false, 'message' => 'That document is not on your account.']);
    }
    // A document the compliance team has already accepted is part of the record.
    if (strtolower((string)($removed['status'] ?? 'received')) === 'accepted') {
        respond(409, ['success' => false, 'message' => 'That document has been accepted and can no longer be removed. Contact support if it was sent in error.']);
    }

    $path = $documentsDir . '/' . (string)$removed['id'] . '.pdf';
    if (is_file($path)) @unlink($path);
    $users[$index]['verificationDocuments'] = $kept;
    saveUsers($usersFile, $users);

    respond(200, array_merge(
        currentState($users[$index], $callbacksFile, $clientEmail),
        ['message' => 'Document removed.']
    ));
}

/* -------------------------------------------------------- booking a session */

if ($action === 'book') {
    $at = trim((string)($_POST['at'] ?? ''));
    $timezone = trim((string)($_POST['timezone'] ?? 'UTC'));
    $note = trim((string)($_POST['note'] ?? ''));

    try {
        new DateTimeZone($timezone);
    } catch (Exception $error) {
        $timezone = 'UTC';
    }

    $scheduledAt = hxFollowupInputToUtc($at, $timezone);
    if ($scheduledAt === '') {
        respond(422, ['success' => false, 'message' => 'Choose a date and time for the session.']);
    }
    if (strtotime($scheduledAt) < time() + 1800) {
        respond(422, ['success' => false, 'message' => 'Pick a time at least half an hour from now, so we can get someone to it.']);
    }
    if (strtotime($scheduledAt) > time() + 90 * 86400) {
        respond(422, ['success' => false, 'message' => 'Pick a time within the next 90 days.']);
    }
    if (pendingSession($callbacksFile, $clientEmail) !== null) {
        respond(409, ['success' => false, 'message' => 'You already have a session booked. Cancel it first if you need a different time.']);
    }

    $records = hxFollowupLoad($callbacksFile);
    $records[] = [
        'id' => bin2hex(random_bytes(12)),
        'createdAt' => gmdate('c'),
        'clientEmail' => $clientEmail,
        'scheduledAt' => $scheduledAt,
        'subject' => HX_SESSION_SUBJECT,
        'status' => 'scheduled',
        'notes' => trim('Requested by the client from the verification page. Client timezone: ' . $timezone . '. ' . $note),
        'outcome' => '',
        'completedAt' => '',
        'updatedAt' => gmdate('c')
    ];
    hxFollowupSave($callbacksFile, $records);

    respond(200, array_merge(
        currentState($users[$index], $callbacksFile, $clientEmail),
        ['message' => 'Session requested. We will email you the joining link before it starts.']
    ));
}

/* ---------------------------------------------------------------- cancelling */

if ($action === 'cancel') {
    $records = hxFollowupLoad($callbacksFile);
    $id = trim((string)($_POST['id'] ?? ''));
    $changed = false;
    foreach ($records as $position => $record) {
        if (strtolower((string)($record['clientEmail'] ?? '')) !== $clientEmail) continue;
        if ((string)($record['subject'] ?? '') !== HX_SESSION_SUBJECT) continue;
        if (!hash_equals((string)($record['id'] ?? ''), $id)) continue;
        $records[$position]['status'] = 'cancelled';
        $records[$position]['outcome'] = 'Cancelled by the client.';
        $records[$position]['updatedAt'] = gmdate('c');
        $changed = true;
        break;
    }
    if (!$changed) {
        respond(404, ['success' => false, 'message' => 'That session is not on your account.']);
    }
    hxFollowupSave($callbacksFile, $records);

    respond(200, array_merge(
        currentState($users[$index], $callbacksFile, $clientEmail),
        ['message' => 'Session cancelled.']
    ));
}

respond(400, ['success' => false, 'message' => 'Unknown action.']);
