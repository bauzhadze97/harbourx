<?php
require_once __DIR__ . '/../payments_common.php';

function expectPayment($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

expectPayment(hxPaymentValidDate('2026-09-18'), 'valid ISO date accepted');
expectPayment(!hxPaymentValidDate('2026-02-30'), 'invalid calendar date rejected');
expectPayment(hxPaymentStoredStatus('unknown') === 'pending', 'unknown status becomes pending');

$today = '2026-09-18';
expectPayment(hxPaymentEffectiveStatus(['status' => 'pending', 'dueDate' => '2026-09-17'], $today) === 'overdue', 'past pending payment is overdue');
expectPayment(hxPaymentEffectiveStatus(['status' => 'pending', 'dueDate' => '2026-09-18'], $today) === 'pending', 'payment due today remains pending');
expectPayment(hxPaymentEffectiveStatus(['status' => 'paid', 'dueDate' => '2026-09-01'], $today) === 'paid', 'paid status wins over due date');

$tempFile = tempnam(sys_get_temp_dir(), 'hx-payments-');
$records = [[
    'id' => 'test-record',
    'clientEmail' => 'client@example.invalid',
    'amount' => 125.50,
    'currency' => 'USD',
    'dueDate' => '2026-10-01',
    'status' => 'pending'
]];
hxPaymentSave($tempFile, $records);
$loaded = hxPaymentLoad($tempFile);
expectPayment(count($loaded) === 1, 'saved record loads');
expectPayment(($loaded[0]['amount'] ?? null) === 125.50, 'amount is preserved');
expectPayment(hxPaymentFindIndex($loaded, 'test-record') === 0, 'record lookup works');
unlink($tempFile);

echo "Payment schedule tests passed.\n";

