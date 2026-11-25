<?php

/**
 * ONE-OFF: undo the release posting of loans paid out through Collexia EnCr whose payout never completed
 * (released before loans waited for Collexia -- e.g. sandbox loan LN-261124-5735DD). See App\Services\EncrUnpostService.
 *
 *   php bin/encr_unpost_unpaid.php            DRY RUN (default): lists what it would do, changes nothing
 *   php bin/encr_unpost_unpaid.php --apply    reverses the postings and moves each loan to "Awaiting Payout"
 *   php bin/encr_unpost_unpaid.php --apply --loan=26   only that loan
 *
 * Requires database/encr_awaiting_payout.sql to have been applied. Refuses any loan with a payment, a charged
 * penalty, a live debit order, an earned/paid commission, a top-up, or a levy / stamp duty already submitted.
 * Take a database backup before --apply.
 */

require __DIR__ . '/../bootstrap/app.php';

use App\Services\CollexiaEncrDisbursementService;
use App\Services\EncrUnpostService;

$args = $argv ?? [];
$apply = in_array('--apply', $args, true);
$onlyLoan = null;
foreach ($args as $a) {
    if (str_starts_with($a, '--loan=')) {
        $onlyLoan = (int) substr($a, 7);
    }
}

if (!CollexiaEncrDisbursementService::schemaReady()) {
    fwrite(STDERR, "database/encr_awaiting_payout.sql has not been applied -- run it first. Nothing was changed.\n");
    exit(1);
}

$svc = new EncrUnpostService();
$candidates = array_values(array_filter($svc->candidates(), fn ($c) => $onlyLoan === null || (int) $c['loan_id'] === $onlyLoan));

echo ($apply ? 'APPLY' : 'DRY RUN (nothing is changed; add --apply to act)') . ': ' . count($candidates) . " loan(s) posted at release with an EnCr payout that is not Completed.\n";

$failed = 0;
foreach ($candidates as $c) {
    $plan = $svc->plan($c);
    printf("- loan #%d %s (%s), payout %s %s, N\$%s, journals to reverse: %s\n",
        $c['loan_id'], $c['loan_no'], $c['loan_status'], $c['encr_reference_no'], $c['encr_state'] ?? '?', number_format((float) $c['amount'], 2),
        $plan['journals'] ? implode(', ', array_map(fn ($j) => $j['journal_no'] . ' ' . $j['source_module'], $plan['journals'])) : 'none');
    if ($plan['periodic_interest'] > 0) {
        printf("    interest accrued by the periodic accrual, to be taken back with its own entry: N\$%s\n", number_format($plan['periodic_interest'], 2));
    }
    if (($c['encr_state'] ?? '') === 'Not Sent') {
        echo "    the payout will be marked \"may have been received\": use Check payout status before paying it another way\n";
    }
    if ($plan['refusals']) {
        echo '    REFUSED: ' . implode('; ', $plan['refusals']) . "\n";
        $failed++;
        continue;
    }
    if (!$apply) {
        echo "    would reverse the journals above and move the loan to Awaiting Payout\n";
        continue;
    }
    $r = $svc->unpost($c);
    echo '    ' . ($r['ok'] ? 'DONE: ' : 'NOT DONE: ') . $r['message'] . "\n";
    if (!$r['ok']) {
        $failed++;
    }
}

exit($failed > 0 ? 2 : 0);
