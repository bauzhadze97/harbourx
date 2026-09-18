<?php

function hxAdminClientLookup($users) {
    $lookup = [];
    foreach ($users as $user) {
        $email = strtolower(trim((string)($user['email'] ?? '')));
        if ($email !== '') $lookup[$email] = $user;
    }
    return $lookup;
}

function hxAdminAlertSnapshot($paymentsFile, $callbacksFile, $users, $timezone) {
    $payments = hxPaymentLoad($paymentsFile);
    $callbacks = hxFollowupLoad($callbacksFile);
    $clients = hxAdminClientLookup($users);
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $todayLocal = $now->setTimezone(new DateTimeZone($timezone))->format('Y-m-d');

    $paymentStats = ['pending' => 0, 'overdue' => 0, 'paid' => 0, 'missed' => 0];
    $callbackStats = ['upcoming' => 0, 'due' => 0, 'today' => 0, 'completed' => 0, 'cancelled' => 0];
    $items = [];

    foreach ($payments as $record) {
        $status = hxPaymentEffectiveStatus($record, $todayLocal);
        $paymentStats[$status] = ($paymentStats[$status] ?? 0) + 1;
        if (!in_array($status, ['overdue', 'missed'], true)) continue;

        $email = strtolower((string)($record['clientEmail'] ?? ''));
        $name = (string)($clients[$email]['name'] ?? $email ?: 'Unknown client');
        $amount = formatMoney($record['amount'] ?? 0, $record['currency'] ?? 'USD') . ' ' . cleanCurrency($record['currency'] ?? 'USD');
        $reason = trim((string)($record['nonPaymentReason'] ?? ''));
        $items[] = [
            'kind' => 'payment',
            'level' => 'danger',
            'title' => $status === 'missed' ? 'Payment not received' : 'Payment overdue',
            'detail' => $name . ' · ' . $amount . ' · due ' . (string)($record['dueDate'] ?? '') . ($reason !== '' ? ' · ' . $reason : ''),
            'href' => 'payments.php?' . http_build_query(['client' => $email, 'status' => $status]),
            'sortAt' => (string)($record['dueDate'] ?? ''),
            'signature' => (string)($record['id'] ?? '') . ':' . $status . ':' . (string)($record['updatedAt'] ?? '')
        ];
    }

    foreach ($callbacks as $record) {
        $status = hxFollowupEffectiveStatus($record, $now);
        $callbackStats[$status] = ($callbackStats[$status] ?? 0) + 1;
        $localInput = hxFollowupUtcToInput($record['scheduledAt'] ?? '', $timezone);
        if (substr($localInput, 0, 10) === $todayLocal && $status !== 'cancelled') $callbackStats['today']++;
        if ($status !== 'due') continue;

        $email = strtolower((string)($record['clientEmail'] ?? ''));
        $name = (string)($clients[$email]['name'] ?? $email ?: 'Unknown client');
        $items[] = [
            'kind' => 'callback',
            'level' => 'warning',
            'title' => 'Client callback due',
            'detail' => $name . ' · ' . hxFollowupDisplayTime($record['scheduledAt'] ?? '', $timezone) . ' · ' . (string)($record['subject'] ?? 'Follow up'),
            'href' => 'callbacks.php?' . http_build_query(['client' => $email, 'status' => 'due']),
            'sortAt' => (string)($record['scheduledAt'] ?? ''),
            'signature' => (string)($record['id'] ?? '') . ':due:' . (string)($record['updatedAt'] ?? '')
        ];
    }

    usort($items, static function ($a, $b) {
        return strcmp((string)($a['sortAt'] ?? ''), (string)($b['sortAt'] ?? ''));
    });

    $parts = [];
    $paymentAlertCount = $paymentStats['overdue'] + $paymentStats['missed'];
    if ($paymentAlertCount) {
        $parts[] = $paymentAlertCount === 1
            ? '1 payment needs attention'
            : $paymentAlertCount . ' payments need attention';
    }
    if ($callbackStats['due']) $parts[] = $callbackStats['due'] . ' callback' . ($callbackStats['due'] === 1 ? '' : 's') . ' due';
    $signatureParts = array_map(static fn($item) => $item['signature'], $items);

    return [
        'count' => count($items),
        'body' => $parts ? implode(' · ', $parts) : 'No urgent admin alerts.',
        'signature' => hash('sha256', implode('|', $signatureParts)),
        'paymentStats' => $paymentStats,
        'callbackStats' => $callbackStats,
        'items' => $items
    ];
}
