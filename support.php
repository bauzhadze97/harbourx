<?php
/**
 * Support tickets.
 *
 * Client actions (JSON POST, all require a live client session):
 *   list    the signed-in client's tickets, newest first
 *   create  open a ticket
 *   reply   add a message to one of their own tickets
 *   close   close one of their own tickets
 *
 * Tickets live in data/tickets.json, beside the client records, and carry only
 * what the client typed. Staff replies are written by the admin console through
 * the same file.
 */

$secureCookie = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'httponly' => true,
    'secure' => $secureCookie,
    'samesite' => 'Lax'
]);
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');

const HX_TICKET_FILE = __DIR__ . '/data/tickets.json';
const HX_SUBJECT_MAX = 120;
const HX_BODY_MAX = 4000;
const HX_TICKETS_PER_CLIENT = 50;

const HX_TOPICS = [
    'account' => 'Account and access',
    'verification' => 'Identity verification',
    'deposit' => 'Deposits',
    'withdrawal' => 'Withdrawals and payouts',
    'conversion' => 'Converting crypto',
    'security' => 'Security concern',
    'other' => 'Something else'
];

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

/** Trim, cap and strip control characters. Output is escaped at render time. */
function clean_text($value, int $max): string
{
    $text = trim((string)$value);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $max);
    }
    return substr($text, 0, $max);
}

function load_tickets(): array
{
    if (!file_exists(HX_TICKET_FILE)) return [];
    $data = json_decode((string)file_get_contents(HX_TICKET_FILE), true);
    return is_array($data) ? $data : [];
}

function save_tickets(array $tickets): void
{
    $dir = dirname(HX_TICKET_FILE);
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $json = json_encode(array_values($tickets), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false || file_put_contents(HX_TICKET_FILE, $json, LOCK_EX) === false) {
        respond(500, ['success' => false, 'message' => 'Unable to save the ticket.']);
    }
}

/** A short, human-quotable reference: HX-8F3K2Q. */
function new_reference(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // no I/O/0/1
    $out = '';
    for ($i = 0; $i < 6; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return 'HX-' . $out;
}

$email = strtolower((string)($_SESSION['client_email'] ?? ''));
if ($email === '') {
    respond(401, ['success' => false, 'message' => 'Sign in again to reach support.']);
}

$input = json_decode((string)file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];
$action = strtolower(trim((string)($input['action'] ?? 'list')));

$tickets = load_tickets();

/** Only ever hand back this client's own tickets. */
$mine = array_values(array_filter($tickets, static function ($ticket) use ($email) {
    return strtolower((string)($ticket['email'] ?? '')) === $email;
}));
usort($mine, static fn($a, $b) => strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? '')));

/* -------------------------------------------------------------------- list */
if ($action === 'list') {
    respond(200, ['success' => true, 'tickets' => $mine, 'topics' => HX_TOPICS]);
}

/* ------------------------------------------------------------------ create */
if ($action === 'create') {
    $subject = clean_text($input['subject'] ?? '', HX_SUBJECT_MAX);
    $body = clean_text($input['body'] ?? '', HX_BODY_MAX);
    $topic = (string)($input['topic'] ?? 'other');
    if (!array_key_exists($topic, HX_TOPICS)) $topic = 'other';

    if (mb_strlen($subject) < 4) {
        respond(422, ['success' => false, 'message' => 'Give the ticket a subject of at least 4 characters.']);
    }
    if (mb_strlen($body) < 10) {
        respond(422, ['success' => false, 'message' => 'Describe the problem in a little more detail.']);
    }
    if (count($mine) >= HX_TICKETS_PER_CLIENT) {
        respond(429, ['success' => false, 'message' => 'You have reached the open ticket limit. Close one first.']);
    }

    $now = gmdate('c');
    $ticket = [
        'id' => bin2hex(random_bytes(8)),
        'reference' => new_reference(),
        'email' => $email,
        'topic' => $topic,
        'topicLabel' => HX_TOPICS[$topic],
        'subject' => $subject,
        'status' => 'open',
        'createdAt' => $now,
        'updatedAt' => $now,
        'messages' => [[
            'from' => 'client',
            'body' => $body,
            'at' => $now
        ]]
    ];

    $tickets[] = $ticket;
    save_tickets($tickets);

    respond(200, [
        'success' => true,
        'message' => 'Ticket ' . $ticket['reference'] . ' opened. Support replies by email and here.',
        'ticket' => $ticket
    ]);
}

/* ------------------------------------------------------- reply / close ---- */
if ($action === 'reply' || $action === 'close') {
    $id = (string)($input['id'] ?? '');
    $found = null;
    foreach ($tickets as $i => $ticket) {
        // The ownership check is what stops one client touching another's ticket.
        if ((string)($ticket['id'] ?? '') === $id
            && strtolower((string)($ticket['email'] ?? '')) === $email) {
            $found = $i;
            break;
        }
    }
    if ($found === null) {
        respond(404, ['success' => false, 'message' => 'Ticket not found.']);
    }

    if ($action === 'close') {
        $tickets[$found]['status'] = 'closed';
        $tickets[$found]['updatedAt'] = gmdate('c');
        save_tickets($tickets);
        respond(200, ['success' => true, 'message' => 'Ticket closed.', 'ticket' => $tickets[$found]]);
    }

    $body = clean_text($input['body'] ?? '', HX_BODY_MAX);
    if (mb_strlen($body) < 2) {
        respond(422, ['success' => false, 'message' => 'Write a reply first.']);
    }

    $tickets[$found]['messages'][] = ['from' => 'client', 'body' => $body, 'at' => gmdate('c')];
    $tickets[$found]['status'] = 'open';
    $tickets[$found]['updatedAt'] = gmdate('c');
    save_tickets($tickets);

    respond(200, ['success' => true, 'message' => 'Reply sent.', 'ticket' => $tickets[$found]]);
}

respond(400, ['success' => false, 'message' => 'Unknown action.']);
