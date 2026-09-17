<?php
/**
 * Statement amount parsing.
 *
 * Transaction amounts are free text written by whichever flow created them, so
 * a statement that totals them has to read every shape that exists in the data
 * and refuse to guess at the ones it cannot. These are the real formats, taken
 * from the client records.
 */

// Pull in just the parser, without running the endpoint's session/auth code.
$src = file_get_contents(__DIR__ . '/../statements.php');
$start = strpos($src, 'function parseAmount');
$end = strpos($src, '/* ------------------------------------------------------------------- auth */');
eval('?><?php ' . substr($src, $start, $end - $start));

$fail = 0;
function check($ok, $label, $detail = '') {
    global $fail;
    if (!$ok) $fail++;
    printf("  %s %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, $detail !== '' ? " — $detail" : '');
}
function near($a, $b) { return $a !== null && abs($a - $b) < 1e-9; }

/* ------------------------------------------------- shapes seen in the data */

$r = parseAmount('+2.56 BTC');
check($r['parsed'] && near($r['btc'], 2.56) && $r['fiat'] === null, 'received BTC', json_encode($r));

$r = parseAmount('+ 0.0361 BTC');
check($r['parsed'] && near($r['btc'], 0.0361), 'a space after the sign', json_encode($r));

$r = parseAmount('+0.0029');
check($r['parsed'] && near($r['btc'], 0.0029), 'a bare figure with no unit', json_encode($r));

$r = parseAmount('-A$1,000.00');
check($r['parsed'] && near($r['fiat'], -1000.0) && $r['btc'] === null, 'fiat out with a grouped thousand', json_encode($r));

$r = parseAmount('+A$1,200.00');
check($r['parsed'] && near($r['fiat'], 1200.0), 'fiat in', json_encode($r));

// A conversion names both sides: BTC leaves, cash arrives.
$r = parseAmount('-2.56000000 BTC -> A$286,617.60');
check($r['parsed'] && near($r['btc'], -2.56) && near($r['fiat'], 286617.60),
    'a conversion counts both sides', json_encode($r));

$r = parseAmount('-0.25000000 BTC -> €16,800.00');
check($r['parsed'] && near($r['btc'], -0.25) && near($r['fiat'], 16800.0) && $r['currency'] === '€',
    'a euro conversion', json_encode($r));

$r = parseAmount('-0.50000000 BTC -> A$48,250.50');
check($r['parsed'] && near($r['btc'], -0.5) && near($r['fiat'], 48250.50), 'the fixture conversion');

/* ----------------------------------------------- things it must not invent */

foreach (['', '   ', 'Pending', 'n/a', '--'] as $junk) {
    $r = parseAmount($junk);
    check(!$r['parsed'] && $r['btc'] === null && $r['fiat'] === null,
        'refuses to read ' . var_export($junk, true), json_encode($r));
}

/* ------------------------------------------------------------- directions */

check(parseAmount('-1.5 BTC')['btc'] < 0, 'a minus means out');
check(parseAmount('1.5 BTC')['btc'] > 0, 'no sign means in');

// Three-letter codes count as fiat, except BTC itself.
$r = parseAmount('-500.00 USD');
check($r['parsed'] && near($r['fiat'], -500.0) && $r['currency'] === 'USD', 'a three-letter code is fiat', json_encode($r));

/* -------------------------------------------------- grouping into periods */

$statements = buildStatements([
    ['date' => '2026-02-01', 'type' => 'Received', 'amount' => '+1.25 BTC', 'status' => 'Completed', 'details' => ''],
    ['date' => '2026-02-14', 'type' => 'BTC to AUD Conversion', 'amount' => '-0.50000000 BTC -> A$48,250.50', 'status' => 'Completed', 'details' => ''],
    ['date' => '2026-03-02', 'type' => 'Bank Withdrawal', 'amount' => '-A$1,000.00', 'status' => 'Pending', 'details' => ''],
    ['date' => '2026-03-09', 'type' => 'Declined', 'amount' => 'n/a', 'status' => 'Declined', 'details' => ''],
    ['date' => 'not-a-date', 'type' => 'Broken', 'amount' => '+1 BTC', 'status' => '', 'details' => ''],
]);

check(count($statements) === 2, 'one statement per month', count($statements) . ' periods');
check($statements[0]['period'] === '2026-03', 'newest period first', $statements[0]['period']);
check($statements[0]['label'] === 'March 2026', 'periods are named', $statements[0]['label']);
check($statements[0]['reference'] === 'HX-STMT-2026-03', 'and referenced');

$feb = $statements[1];
check(near($feb['btcIn'], 1.25), 'February BTC in', (string)$feb['btcIn']);
check(near($feb['btcOut'], 0.5), 'February BTC out', (string)$feb['btcOut']);
check(near($feb['fiatIn'], 48250.50), 'February cash in', (string)$feb['fiatIn']);
check($feb['unparsed'] === 0, 'nothing unreadable in February');

$mar = $statements[0];
check(near($mar['fiatOut'], 1000.0), 'March cash out', (string)$mar['fiatOut']);
check($mar['unparsed'] === 1, 'the unreadable one is counted, not guessed', (string)$mar['unparsed']);
check($mar['count'] === 2, 'but it still appears on the statement', (string)$mar['count']);

// A row with an unusable date cannot land in a month, so it is left out.
$all = array_sum(array_column($statements, 'count'));
check($all === 4, 'the undated row is excluded', "$all rows across periods");

echo $fail ? "\n$fail check(s) failed\n" : "\nAll statement checks passed\n";
exit($fail ? 1 : 0);
