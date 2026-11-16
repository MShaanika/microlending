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
use App\Services\LegacyLoanBookReader;

require __DIR__ . '/../bootstrap/app.php';

$opts = getopt('', ['as-of:', 'supplier::', 'trading::']);
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

$s = $result['summary'];
$lines = [
    'Candidate monthly CPL file, as at ' . $s['as_of'] . ' (built ' . date('Y-m-d H:i') . ')',
    'Records in the file: ' . $s['sent'] . ' (of which with warnings: ' . $s['with_warnings'] . ')',
    '  active: ' . $s['by_status']['active'] . ', closed (C): ' . $s['by_status']['C'] . ', written off (W): ' . $s['by_status']['W'],
    'Accounts that cannot be sent (see exceptions.csv): ' . $s['blocked'],
    'Left out: ' . ($result['excluded'] === [] ? 'none' : json_encode($result['excluded'])),
    'Totals in the file (N$, whole dollars): opening ' . $s['opening_balance'] . ', current ' . $s['current_balance'] . ', overdue ' . $s['amount_overdue'] . ', accounts in arrears ' . $s['accounts_in_arrears'],
    'Arrears are an ESTIMATE (the old system keeps no instalment schedule): check about 30 loans against the old system before any live use.',
];
file_put_contents($dir . '/summary.txt', implode("\n", $lines) . "\n");
echo implode("\n", $lines) . "\nWritten to $dir\n";
