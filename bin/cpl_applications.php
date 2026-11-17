<?php

/**
 * Monthly CPL file for the loans of the applications received through THIS system, when the staff say which were approved:
 *
 *   php bin/cpl_applications.php --sheet [--status=Submitted] [--exclude-applicant="First Last"]
 *       writes storage/exports/cpl_applications_<today>/approvals_for_staff.xlsx: every application with what we know, and three yellow columns for the staff
 *       (approved? / amount / date paid out).
 *
 *   php bin/cpl_applications.php --build --as-of=2026-10-31 --answers=<the completed sheet> [--create-batch --user=<your user id>]
 *       reads the answers, builds the candidate monthly file (approved loans paid out up to the month end), the list of accounts that cannot be sent and the
 *       rows of the sheet that could not be used; with --create-batch the file is stored as a CPL batch for review and approval in Reports > CPL.
 *
 * It only READS the applications of this system's own database. Nothing is uploaded, and the candidate file is deliberately NOT named like a Creditinfo file.
 */

use App\Services\ApplicationApprovalSheet;
use App\Services\ApplicationLoanBook;
use App\Services\CplRecordBuilder;
use App\Services\CplValidationService;
use App\Services\LegacyCplBatchService;
use App\Services\LegacyCplExtract;
use App\Services\LegacyCplMapper;

require __DIR__ . '/../bootstrap/app.php';

$opts = getopt('', ['sheet', 'build', 'status:', 'exclude-applicant:', 'as-of:', 'answers:', 'create-batch', 'user:', 'supplier:', 'trading:']);
$excluded = (array) ($opts['exclude-applicant'] ?? []);
$pdo = App\Core\Database::connection();
$sheet = new ApplicationApprovalSheet();

if (isset($opts['sheet'])) {
    $apps = ApplicationLoanBook::fetch($pdo, (string) ($opts['status'] ?? 'Submitted'), $excluded);
    $dir = dirname(__DIR__) . '/storage/exports/cpl_applications_' . date('Y-m-d');
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
        fwrite(STDERR, "Could not create $dir\n");
        exit(1);
    }
    $sheet->write($apps, $dir . '/approvals_for_staff.xlsx', $opts['as-of'] ?? date('Y-m-d'));
    echo count($apps) . " applications written to $dir/approvals_for_staff.xlsx\n";
    exit(0);
}

if (!isset($opts['build'])) {
    fwrite(STDERR, "Use --sheet to make the staff sheet, or --build --as-of=<month end> --answers=<completed sheet>.\n");
    exit(1);
}

$asOf = (string) ($opts['as-of'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) || $asOf !== date('Y-m-t', (int) strtotime($asOf))) {
    fwrite(STDERR, "--as-of must be a month end, for example 2026-10-31\n");
    exit(1);
}
if ($asOf > date('Y-m-d')) {
    fwrite(STDERR, "The as-of date may not be in the future.\n");
    exit(1);
}
$answersPath = (string) ($opts['answers'] ?? '');
if ($answersPath === '') {
    fwrite(STDERR, "Use --answers=<path to the completed sheet>\n");
    exit(1);
}
try {
    $rows = $sheet->readFile($answersPath);
} catch (Throwable $e) {
    fwrite(STDERR, 'Could not read the sheet: ' . $e->getMessage() . "\n");
    exit(1);
}

$numbers = array_values(array_filter(array_map(static fn (array $r): string => trim((string) ($r['application_no'] ?? '')), $rows), static fn (string $n): bool => $n !== ''));
$byNo = [];
foreach (ApplicationLoanBook::fetch($pdo, null, $excluded, $numbers) as $a) {
    $byNo[$a['application_no']] = $a;
}
$parsed = $sheet->parse($rows, $byNo, $asOf, date('Y-m-d'));

$approved = [];
foreach ($parsed['approved'] as $no => $a) {
    $approved[] = ['application' => $byNo[$no], 'amount' => $a['amount'], 'paid_out' => $a['paid_out']];
}

$settings = new App\Models\CplSetting();
$supplier = (string) ($opts['supplier'] ?? '') ?: $settings->supplierReferenceNumber();
$trading = (string) ($opts['trading'] ?? '') ?: $settings->tradingName();
$result = (new LegacyCplExtract(new ApplicationLoanBook($approved), new LegacyCplMapper(), new CplValidationService(), new CplRecordBuilder()))->monthly($asOf, $supplier, $trading);

$dir = dirname(__DIR__) . '/storage/exports/cpl_applications_' . $asOf;
if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
    fwrite(STDERR, "Could not create $dir\n");
    exit(1);
}
file_put_contents($dir . '/CANDIDATE_NOT_FOR_UPLOAD_monthly_' . $asOf . '.txt', $result['file']);

$csv = fopen($dir . '/exceptions.csv', 'w');
fputcsv($csv, ['application no', 'name', 'why it cannot be sent', 'notes']);
foreach ($result['exceptions'] as $e) {
    fputcsv($csv, [$e['ref_no'], $e['name'], implode(' | ', $e['reasons']), implode(' | ', $e['notes'])]);
}
fclose($csv);

@unlink($dir . '/answers_rejected.csv');
if ($parsed['rejected'] !== []) {
    $rej = fopen($dir . '/answers_rejected.csv', 'w');
    fputcsv($rej, ['sheet row', 'application no', 'why the answer was not used']);
    foreach ($parsed['rejected'] as $r) {
        fputcsv($rej, [$r['row'], $r['application_no'], $r['why']]);
    }
    fclose($rej);
}

$s = $result['summary'];
$inArrears = array_values(array_filter($result['details'], static fn (array $d): bool => $d['months_in_arrears'] > 0));
$lines = [
    'Candidate monthly CPL file for the approved applications, as at ' . $asOf . ' (built ' . date('Y-m-d H:i') . ')',
    'Sheet answers: approved ' . count($parsed['approved']) . ', not approved ' . $parsed['declined'] . ', not answered yet ' . $parsed['unanswered'] . ', unusable ' . count($parsed['rejected']) . ' (answers_rejected.csv)',
    'Approved but paid out after ' . $asOf . ' (left for a later month): ' . count($parsed['later']),
    'Records in the file: ' . $s['sent'] . ' (of which with warnings: ' . $s['with_warnings'] . ')',
    'Accounts that cannot be sent (see exceptions.csv): ' . $s['blocked'],
    'Totals in the file (N$, whole dollars): opening ' . $s['opening_balance'] . ', current ' . $s['current_balance'] . ', overdue ' . $s['amount_overdue'],
    'ASSUMED: 30% interest, N$5.00 fee and 1.03% levy as in the old system; term as applied for; NO repayments yet; account number = application number.',
];
if ($inArrears !== []) {
    $lines[] = 'CHECK: ' . count($inArrears) . ' account(s) show as in arrears only because no repayments are known: ' . implode(', ', array_column($inArrears, 'ref_no'));
}
foreach ($parsed['warnings'] as $w) {
    $lines[] = 'Note ' . $w['application_no'] . ': ' . $w['note'];
}

if (isset($opts['create-batch'])) {
    try {
        $batchId = (new LegacyCplBatchService())->store($result, $asOf, isset($opts['user']) ? (int) $opts['user'] : null, 'Built from the approved online applications');
    } catch (RuntimeException $e) {
        fwrite(STDERR, implode("\n", $lines) . "\nNOT stored as a CPL batch: " . $e->getMessage() . "\n");
        exit(1);
    }
    $lines[] = 'CPL batch #' . $batchId . ' created/refreshed for review under Reports > CPL (' . ($s['blocked'] > 0 ? 'Draft: ' . $s['blocked'] . ' accounts still cannot be sent' : 'Ready for Review') . ')';
}
file_put_contents($dir . '/summary.txt', implode("\n", $lines) . "\n");
echo implode("\n", $lines) . "\nWritten to $dir\n";
