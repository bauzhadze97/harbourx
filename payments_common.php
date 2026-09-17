<?php

function hxPaymentLoad($file) {
    if (!file_exists($file)) return [];
    $records = json_decode((string)file_get_contents($file), true);
    return is_array($records) ? array_values($records) : [];
}

function hxPaymentSave($file, $records) {
    if (!is_dir(dirname($file))) mkdir(dirname($file), 0755, true);
    $json = json_encode(array_values($records), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($file, $json, LOCK_EX) === false) {
        throw new RuntimeException('Unable to save payment schedule data.');
    }
}

function hxPaymentFindIndex($records, $id) {
    foreach ($records as $index => $record) {
        if (hash_equals((string)($record['id'] ?? ''), (string)$id)) return $index;
    }
    return -1;
}

function hxPaymentValidDate($value) {
    $value = trim((string)$value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value;
}

function hxPaymentStoredStatus($value) {
    $value = strtolower(trim((string)$value));
    return in_array($value, ['pending', 'paid', 'missed'], true) ? $value : 'pending';
}

function hxPaymentEffectiveStatus($record, $today = null) {
    $stored = hxPaymentStoredStatus($record['status'] ?? 'pending');
    if ($stored !== 'pending') return $stored;
    $today = $today ?: date('Y-m-d');
    $dueDate = (string)($record['dueDate'] ?? '');
    return hxPaymentValidDate($dueDate) && $dueDate < $today ? 'overdue' : 'pending';
}

function hxPaymentStatusLabel($status) {
    if ($status === 'paid') return 'Paid';
    if ($status === 'missed') return 'Not paid';
    if ($status === 'overdue') return 'Overdue';
    return 'Pending';
}

function hxPaymentCsrfToken() {
    if (empty($_SESSION['payment_csrf'])) {
        $_SESSION['payment_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['payment_csrf'];
}

function hxPaymentCsrfValid($token) {
    return isset($_SESSION['payment_csrf'])
        && is_string($token)
        && hash_equals((string)$_SESSION['payment_csrf'], $token);
}

