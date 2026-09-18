<?php
require_once __DIR__ . '/../admin_common.php';

function expectFollowup($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$local = '2026-09-18T15:30';
$utc = hxFollowupInputToUtc($local, 'Asia/Tbilisi');
expectFollowup($utc === '2026-09-18T11:30:00+00:00', 'Tbilisi time converts to UTC');
expectFollowup(hxFollowupUtcToInput($utc, 'Asia/Tbilisi') === $local, 'UTC converts back to the admin time');
expectFollowup(hxFollowupInputToUtc('2026-02-30T10:00', 'Asia/Tbilisi') === '', 'invalid date is rejected');

$now = new DateTimeImmutable('2026-09-18T12:00:00+00:00');
expectFollowup(hxFollowupEffectiveStatus(['status' => 'scheduled', 'scheduledAt' => '2026-09-18T11:30:00+00:00'], $now) === 'due', 'past callback is due');
expectFollowup(hxFollowupEffectiveStatus(['status' => 'scheduled', 'scheduledAt' => '2026-09-18T12:30:00+00:00'], $now) === 'upcoming', 'future callback is upcoming');
expectFollowup(hxFollowupEffectiveStatus(['status' => 'completed', 'scheduledAt' => '2026-09-18T11:30:00+00:00'], $now) === 'completed', 'completed status wins over time');

$paymentFile = tempnam(sys_get_temp_dir(), 'hx-payments-');
$callbackFile = tempnam(sys_get_temp_dir(), 'hx-callbacks-');
hxPaymentSave($paymentFile, [[
    'id' => 'late-payment',
    'clientEmail' => 'client@example.invalid',
    'amount' => 80,
    'currency' => 'USD',
    'dueDate' => '2020-01-01',
    'status' => 'pending',
    'updatedAt' => '2026-09-18T00:00:00Z'
]]);
hxFollowupSave($callbackFile, [[
    'id' => 'due-call',
    'clientEmail' => 'client@example.invalid',
    'scheduledAt' => '2020-01-01T10:00:00Z',
    'subject' => 'Payment follow-up',
    'status' => 'scheduled',
    'updatedAt' => '2026-09-18T00:00:00Z'
]]);
$snapshot = hxAdminAlertSnapshot($paymentFile, $callbackFile, [[
    'email' => 'client@example.invalid',
    'name' => 'Test Client'
]], 'Asia/Tbilisi');
expectFollowup($snapshot['paymentStats']['overdue'] === 1, 'dashboard counts overdue payments');
expectFollowup($snapshot['callbackStats']['due'] === 1, 'dashboard counts due callbacks');
expectFollowup($snapshot['count'] === 2, 'notification centre combines payment and callback alerts');
unlink($paymentFile);
unlink($callbackFile);

echo "Callback and notification tests passed.\n";

