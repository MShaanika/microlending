<?php

/**
 * Creditinfo Public Defaults, LISTING (ACTION = A): the staff fill in a template first, then it becomes the file Creditinfo accepts.
 *
 *   php bin/public_defaults_listing.php --template
 *       writes storage/exports/public_defaults_<today>/defaulters_to_list.xlsx: a blank template for the staff.
 *
 *   php bin/public_defaults_listing.php --build=<the completed template> [--env=test|live] [--supplier=SRN] [--branch=0001] [--date=2026-11-17]
 *       checks every row, writes the pipe-delimited 36-column file <SRN>_<T|L>_CCYYMMDD_HHMMSS.txt (CRLF) into storage/exports/public_defaults_<today>/ (T = test data by default,
 *       L only with --env=live, and a live file is refused while any row is rejected unless --allow-partial) and prints the rows that could not be used. --date is the transaction date (default today).
 *   --template never replaces an existing template unless --force.
 *
 * It reads and writes files only: no database, no SFTP. A person uploads the file themselves.
 */

use App\Services\CreditinfoPublicDefaultFileService;
use App\Services\PublicDefaultListingSheet;

require __DIR__ . '/../bootstrap/app.php';

$opts = getopt('', ['template', 'force', 'build:', 'env:', 'supplier:', 'branch:', 'date:', 'allow-partial']);
$sheet = new PublicDefaultListingSheet();

if (isset($opts['template'])) {
    $dir = dirname(__DIR__) . '/storage/exports/public_defaults_' . date('Y-m-d');
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
        fwrite(STDERR, "Could not create $dir\n");
        exit(1);
    }
    $target = $dir . '/defaulters_to_list.xlsx';
    if (is_file($target) && !isset($opts['force'])) {
        fwrite(STDERR, "$target already exists and may hold the staff's work: it was NOT replaced. Use --force only if you are sure.\n");
        exit(1);
    }
    $sheet->write($target, date('Y-m-d'));
    echo "Blank template written to $target (" . PublicDefaultListingSheet::ROWS . " rows)\n";
    exit(0);
}

$path = (string) ($opts['build'] ?? '');
if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "Use --template to make the template, or --build=<the completed template .xlsx>.\n");
    exit(1);
}
$env = (string) ($opts['env'] ?? 'test');
if (!in_array($env, ['test', 'live'], true)) {
    fwrite(STDERR, "--env must be test or live.\n");
    exit(1);
}
$supplier = (string) ($opts['supplier'] ?? 'SRN');
if (!preg_match('/^[A-Za-z0-9]{2,12}$/', $supplier)) {
    fwrite(STDERR, "--supplier must be letters and digits only (Creditinfo gave SRN).\n");
    exit(1);
}
$branch = (string) ($opts['branch'] ?? '');
$dateText = (string) ($opts['date'] ?? date('Y-m-d'));
$when = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateText);
if (!$when || $when->format('Y-m-d') !== $dateText || $dateText > date('Y-m-d')) {
    fwrite(STDERR, "--date must be a real date like 2026-11-17 and not in the future.\n");
    exit(1);
}

try {
    $rows = $sheet->readFile($path);
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
$result = $sheet->parse($rows, date('Y-m-d'), $branch);

echo count($result['records']) . ' row(s) ready, ' . count($result['rejected']) . " row(s) cannot be used yet.\n";
foreach ($result['rejected'] as $r) {
    echo '  REJECTED row ' . $r['row'] . ' (' . ($r['name'] ?: '-') . ', account ' . ($r['account_no'] ?: '-') . '): ' . $r['why'] . "\n";
}
foreach ($result['warnings'] as $w) {
    echo '  CHECK ' . ($w['row'] ? 'row ' . $w['row'] . ' ' : '') . '(' . $w['account_no'] . '): ' . $w['note'] . "\n";
}
if (!$result['records']) {
    echo "No file written: there is nothing usable in the sheet.\n";
    exit(2);
}

$partial = count($result['rejected']) > 0;
if ($env === 'live' && $partial && !isset($opts['allow-partial'])) {
    echo "No LIVE file written: " . count($result['rejected']) . " row(s) are rejected. Fix them in the sheet and build again (or add --allow-partial to list only the good rows now).\n";
    exit(3);
}

$files = new CreditinfoPublicDefaultFileService();
$lines = $sheet->build($result['records'], $supplier, $when, $files);
$name = $files->buildFilename($supplier, $env, new \DateTimeImmutable('now'));
// always the git-ignored export folder, whatever folder the sheet came from; a file is never replaced
$dir = dirname(__DIR__) . '/storage/exports/public_defaults_' . date('Y-m-d');
if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
    fwrite(STDERR, "Could not create $dir\n");
    exit(1);
}
$out = $dir . '/' . $name;
if (file_exists($out)) {
    fwrite(STDERR, "$out already exists: wait a second and build again. A file is never replaced.\n");
    exit(1);
}
if (file_put_contents($out, implode("\r\n", $lines) . "\r\n") === false) { // CRLF like Creditinfo's own sample and the batch service
    fwrite(STDERR, "Could not write $out\n");
    exit(1);
}
@chmod($out, 0640);
echo count($lines) . " line(s) written to $out (supplier $supplier, default branch " . ($branch !== '' ? $branch : 'none') . ", transaction date " . $when->format('Y-m-d') . ")\n";
if ($partial) {
    echo 'PARTIAL: ' . count($result['rejected']) . " row(s) are NOT in this file (listed above). Fix them and build them separately; never upload the same customer twice.\n";
}
echo $env === 'live'
    ? "This is a LIVE file (L). Check it, then upload it yourself to the Creditinfo In folder.\n"
    : "This is a TEST file (T). Creditinfo ignores it for scoring; use --env=live when the test is accepted.\n";
exit($partial ? 3 : 0);
