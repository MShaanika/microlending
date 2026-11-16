<?php

/**
 * READ-ONLY review run for the loan book still kept in the old loan system (SolidFin, the admin_area folder): builds the candidate monthly CPL file, the list of
 * accounts that cannot be sent and totals to check, into storage/exports/cpl_legacy_<date>/. It never uploads anything, never writes to either database, and the
 * candidate file is deliberately NOT named like a Creditinfo file.
 *
 *   LEGACY_DB_HOST=127.0.0.1 LEGACY_DB_PORT=3306 LEGACY_DB_NAME=... LEGACY_DB_USER=... LEGACY_DB_PASS=... php bin/cpl_legacy_extract.php --as-of=2026-10-31
 *
 * The connection details come from the environment only (never from a file in the repository, never printed).
 */

use App\Services\CplRecordBuilder;
use App\Services\CplValidationService;
use App\Services\LegacyCplExtract;
use App\Services\LegacyCplMapper;
use App\Services\LegacyIdFixList;
use App\Services\LegacyLoanBookReader;
use App\Services\LegacySpotCheck;

require __DIR__ . '/../bootstrap/app.php';

$opts = getopt('', ['as-of:', 'supplier::', 'trading::', 'exclude-applicant::', 'application-status::']);
$asOf = (string) ($opts['as-of'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) || $asOf !== date('Y-m-t', (int) strtotime($asOf))) {
    fwrite(STDERR, "--as-of must be a month end, for example 2026-10-31\n");
    exit(1);
}
if ($asOf > date('Y-m-d')) {
    fwrite(STDERR, "The as-of date may not be in the future.\n");
    exit(1);
}
foreach (['LEGACY_DB_NAME', 'LEGACY_DB_USER', 'LEGACY_DB_PASS'] as $need) {
    if (getenv($need) === false || (getenv($need) === '' && $need !== 'LEGACY_DB_PASS')) {
        fwrite(STDERR, "Set $need in the environment.\n");
        exit(1);
    }
}

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', getenv('LEGACY_DB_HOST') ?: '127.0.0.1', (int) (getenv('LEGACY_DB_PORT') ?: 3306), getenv('LEGACY_DB_NAME')),
    getenv('LEGACY_DB_USER'),
    getenv('LEGACY_DB_PASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$pdo->exec('SET SESSION TRANSACTION READ ONLY');

$settings = new App\Models\CplSetting();
$supplier = (string) ($opts['supplier'] ?? '') ?: $settings->supplierReferenceNumber();
$trading = (string) ($opts['trading'] ?? '') ?: $settings->tradingName();

$result = (new LegacyCplExtract(new LegacyLoanBookReader($pdo), new LegacyCplMapper(), new CplValidationService(), new CplRecordBuilder()))->monthly($asOf, $supplier, $trading);

$dir = dirname(__DIR__) . '/storage/exports/cpl_legacy_' . $asOf;
if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
    fwrite(STDERR, "Could not create $dir\n");
    exit(1);
}
file_put_contents($dir . '/CANDIDATE_NOT_FOR_UPLOAD_monthly_' . $asOf . '.txt', $result['file']);

$csv = fopen($dir . '/exceptions.csv', 'w');
fputcsv($csv, ['loan_id', 'borrower_id', 'account_no', 'why it cannot be sent', 'notes']);
foreach ($result['exceptions'] as $e) {
    fputcsv($csv, [$e['loan_id'], $e['borrower_id'], $e['account_no'], implode(' | ', $e['reasons']), implode(' | ', $e['notes'])]);
}
fclose($csv);

// Staff list 1: customers whose ID must be fixed, with a lead from the applications received through this system (their ID comes from the credit check).
// Test applications are left out: --exclude-applicant="First Last" (repeat the option for more), e.g. the test applicant of the pilot. --application-status=Submitted
// restricts the leads to applications in that status; by default every status counts.
$excluded = array_map(static fn ($n): string => strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $n))), (array) ($opts['exclude-applicant'] ?? []));
$statusFilter = (string) ($opts['application-status'] ?? '');
$sql = "SELECT applicant_id_number AS id_number, applicant_first_name AS first_name, applicant_last_name AS last_name, applicant_phone AS phone FROM loan_applications WHERE applicant_id_number IS NOT NULL AND applicant_id_number <> ''";
$params = [];
if ($statusFilter !== '') {
    $sql .= ' AND status = ?';
    $params[] = $statusFilter;
}
$stmt = App\Core\Database::connection()->prepare($sql);
$stmt->execute($params);
$applications = [];
$skippedTest = 0;
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $full = strtolower(trim((string) preg_replace('/\s+/', ' ', $row['first_name'] . ' ' . $row['last_name'])));
    if ($full !== '' && in_array($full, $excluded, true)) {
        $skippedTest++;
        continue;
    }
    $applications[] = $row;
}
$fixRows = (new LegacyIdFixList())->build($result['exceptions'], $applications);
$fix = fopen($dir . '/ids_to_fix.csv', 'w');
fputcsv($fix, ['borrower_id (old system)', 'name', 'phone', 'loan references', 'problem', 'suggested ID (confirm against the ID copy)', 'confidence', 'matched on']);
foreach ($fixRows as $r) {
    fputcsv($fix, [$r['borrower_id'], $r['name'], $r['phone'], $r['loans'], $r['problem'], $r['suggested_id'], $r['confidence'], $r['matched_with']]);
}
fclose($fix);
$withLead = count(array_filter($fixRows, static fn (array $r): bool => $r['confidence'] !== ''));

// Staff list 2: about 30 accounts to compare with the old system's own screens.
$sample = (new LegacySpotCheck())->sample($result['details'], 30, $asOf);
$spot = fopen($dir . '/spot_check.csv', 'w');
fputcsv($spot, ['loan_id', 'loan reference (find it in the old system)', 'borrower', 'type in file', 'amount lent', 'total owed at start', 'paid up to month end', 'opening balance', 'current balance', 'amount overdue', 'months in arrears', 'instalment', 'last payment', 'matches the old system? (yes/no)']);
foreach ($sample as $d) {
    fputcsv($spot, [$d['loan_id'], $d['ref_no'], $d['borrower_name'], $d['kind'], $d['amount_lent'], $d['total_owed_at_start'], $d['paid'], $d['opening_balance'], $d['current_balance'], $d['amount_overdue'], $d['months_in_arrears'], $d['instalment'], $d['last_payment'], '']);
}
fclose($spot);

$s = $result['summary'];
$lines = [
    'Candidate monthly CPL file, as at ' . $s['as_of'] . ' (built ' . date('Y-m-d H:i') . ')',
    'Records in the file: ' . $s['sent'] . ' (of which with warnings: ' . $s['with_warnings'] . ')',
    '  active: ' . $s['by_status']['active'] . ', closed (C): ' . $s['by_status']['C'] . ', written off (W): ' . $s['by_status']['W'],
    'Accounts that cannot be sent (see exceptions.csv): ' . $s['blocked'],
    'Left out: ' . ($result['excluded'] === [] ? 'none' : json_encode($result['excluded'])),
    'Totals in the file (N$, whole dollars): opening ' . $s['opening_balance'] . ', current ' . $s['current_balance'] . ', overdue ' . $s['amount_overdue'] . ', accounts in arrears ' . $s['accounts_in_arrears'],
    'Customers to fix: ' . count($fixRows) . ' (see ids_to_fix.csv; ' . $withLead . ' have a suggested ID from ' . count($applications) . ' applications received here' . ($skippedTest > 0 ? ', ' . $skippedTest . ' test application(s) left out' : '') . ')',
    'Spot check: ' . count($sample) . ' accounts in spot_check.csv to compare with the old system',
    'Arrears are an ESTIMATE (the old system keeps no instalment schedule): check about 30 loans against the old system before any live use.',
];
file_put_contents($dir . '/summary.txt', implode("\n", $lines) . "\n");
echo implode("\n", $lines) . "\nWritten to $dir\n";
