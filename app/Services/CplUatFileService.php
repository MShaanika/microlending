<?php

namespace App\Services;

/**
 * Builds the exact text of a Creditinfo sign-off TEST file from a stored UAT
 * batch (cpl_batches.is_uat_test = 1), and decides whether that file may be
 * sent to Creditinfo from inside the app.
 *
 * One builder serves both the "Download Test File" button and the "Upload to
 * Creditinfo" button, so what is uploaded is what a person can download and
 * read first (a monthly header carries the day the file was built, so a file
 * downloaded yesterday differs from one built today in those eight digits
 * only). Pure: no database, no network.
 *
 * Why the upload guard is strict: a file sent to a credit bureau cannot be
 * taken back. Independent rules keep real borrowers out of a test upload:
 *   1. every record must carry one of the three National IDs Creditinfo
 *      approved for UAT (CplUatTestDataService::UAT_TEST_IDS) and an account
 *      number labelled UATTEST-...;
 *   2. beyond those fields, every record must equal, field for field, what
 *      CplUatTestDataService generates for the batch date; and
 *   3. the file type is always T702 (test), never L702 (live), whatever the
 *      configured submission environment says.
 * A file chosen from the computer is held to the strictest form of rule 2:
 * the whole file must equal the standard file for the date in its name.
 */
class CplUatFileService
{
    /** Creditinfo's "test" file type. Live files are L702 and are never built here. */
    public const FILE_TYPE = 'T702';

    /** Every synthetic account number starts with this -- see CplUatTestDataService. */
    public const ACCOUNT_PREFIX = 'UATTEST-';

    /** A file chosen from the computer is dated recently: nothing before this is a sign-off test file (and no junk batch is created for it). */
    private const EARLIEST_TEST_DATE = '2020-01-01';

    /** What to do when a chosen file is refused and cannot be fixed. */
    private const REMEDY = ' If you cannot fix it, use Generate on the sign-off page to make a new file and send that instead.';

    private const MONTHLY_LINE_LENGTH = CplRecordBuilder::RECORD_LENGTH;
    private const DAILY_LINE_LENGTH = CplRecordBuilder::RECORD_LENGTH + 18;

    private CplRecordBuilder $builder;
    private CplUatTestDataService $testData;

    public function __construct(?CplRecordBuilder $builder = null, ?CplUatTestDataService $testData = null)
    {
        $this->builder = $builder ?? new CplRecordBuilder();
        $this->testData = $testData ?? new CplUatTestDataService();
    }

    /**
     * The only shape of file name this app will ever upload as a test:
     * {supplier ref}_ALL_T702_{M|D}_{CCYYMMDD}_1_1.txt. Checked again at the
     * SFTP boundary, so a path or an L702 name can never reach the server.
     */
    public static function isSafeTestFilename(string $filename): bool
    {
        // The D modifier makes $ mean the very end, so a trailing newline cannot slip through.
        return (bool) preg_match('/^[A-Za-z0-9]{1,10}_ALL_' . self::FILE_TYPE . '_[MD]_\d{8}_1_1\.txt$/D', $filename);
    }

    /**
     * @param array<string,mixed> $batch cpl_batches row of a sign-off test batch
     * @return array{filename:string, content:string, kind:string, record_count:int}
     * @throws \RuntimeException when the stored test records are damaged
     */
    public function build(array $batch, string $supplierRef, string $tradingName): array
    {
        $records = $this->storedRecords($batch);
        $monthEnd = (string) ($batch['month_end'] ?? '');
        // A stray space saved with the setting must not leak into the file name as an underscore.
        $supplierRef = trim($supplierRef);
        $safeRef = preg_replace('/[^A-Za-z0-9_-]/', '_', $supplierRef ?: 'PENDING');
        $date = str_replace('-', '', $monthEnd);

        $lines = [];
        if (($batch['batch_type'] ?? '') === 'Daily') {
            foreach ($records as $record) {
                $lines[] = $this->builder->dailyRecord($record['fields'], $supplierRef, $monthEnd);
            }
            return [
                'filename' => $safeRef . '_ALL_' . self::FILE_TYPE . '_D_' . $date . '_1_1.txt',
                'content' => implode("\r\n", $lines),
                'kind' => 'Daily',
                'record_count' => count($lines),
            ];
        }

        foreach ($records as $record) {
            $lines[] = $this->builder->record($record['fields']);
        }
        $header = $this->builder->header($supplierRef, $monthEnd, $tradingName);
        $trailer = $this->builder->trailer(count($lines) + 2);
        return [
            'filename' => $safeRef . '_ALL_' . self::FILE_TYPE . '_M_' . $date . '_1_1.txt',
            'content' => implode("\r\n", array_merge([$header], $lines, [$trailer])),
            'kind' => 'Monthly',
            'record_count' => count($lines),
        ];
    }

    /**
     * Throws when this file must not be sent to Creditinfo. The message is
     * written for the person who pressed the button.
     *
     * @param array<string,mixed> $batch cpl_batches row
     * @param array{filename:string, content:string, kind:string, record_count:int} $file result of build()
     * @throws \RuntimeException
     */
    public function assertUploadable(array $batch, string $supplierRef, array $file): void
    {
        if (empty($batch['is_uat_test'])) {
            throw new \RuntimeException('Only sign-off test files can be uploaded from this page, never a real submission.');
        }
        $type = (string) ($batch['batch_type'] ?? '');
        if (!in_array($type, ['Daily', 'Monthly'], true)) {
            throw new \RuntimeException('This test batch has an unknown type, so it cannot be uploaded.');
        }
        if ((int) ($batch['blocking_error_count'] ?? 0) > 0) {
            throw new \RuntimeException('This test file has blocking validation errors (see Validation Results below), so it cannot be sent. Generate a new one; if the errors come back, tell your developer.');
        }

        // A file is "as at" a date that has already happened, and a monthly one is dated a month end. (Test files
        // generated before this rule existed could carry the current month's end, which is a future date.)
        $monthEnd = (string) ($batch['month_end'] ?? '');
        if ($monthEnd > date('Y-m-d')) {
            throw new \RuntimeException('This test file is dated ' . $monthEnd . ', which is in the future, so it cannot be sent. Use Generate on the sign-off page to make a current ' . strtolower($type) . ' test file instead.');
        }
        if ($type === 'Monthly' && $monthEnd !== date('Y-m-t', (int) strtotime($monthEnd))) {
            throw new \RuntimeException('A monthly test file is dated the last day of a month, and ' . $monthEnd . ' is not. Generate the monthly test file again.');
        }

        $ref = trim($supplierRef);
        if (!preg_match('/^[A-Za-z0-9]{1,10}$/D', $ref)) {
            throw new \RuntimeException('Set the Supplier Reference Number (for example NA02629) in CPL Settings first. It forms the file name Creditinfo expects.');
        }

        $records = $this->storedRecords($batch);
        if ($records === []) {
            throw new \RuntimeException('This test batch has no records. Generate the test file again.');
        }
        foreach ($records as $i => $record) {
            $fields = $record['fields'];
            $label = 'Record ' . ($i + 1);
            if (!in_array((string) ($fields['na_id'] ?? ''), CplUatTestDataService::UAT_TEST_IDS, true)) {
                throw new \RuntimeException($label . ' does not use one of the National IDs Creditinfo approved for testing, so nothing was sent.');
            }
            if (trim((string) ($fields['non_na_id'] ?? '')) !== '') {
                throw new \RuntimeException($label . ' carries a non-Namibian ID, which a sign-off test file never has, so nothing was sent.');
            }
            if (!str_starts_with((string) ($fields['account_no'] ?? ''), self::ACCOUNT_PREFIX)) {
                throw new \RuntimeException($label . ' is not labelled as test data (' . self::ACCOUNT_PREFIX . '...), so nothing was sent.');
            }
        }

        // The checks above name the likely mistakes. This one covers every other field: each record must be
        // exactly what CplUatTestDataService generates for this batch's date, so a name, address, phone
        // number or amount that is not synthetic test data can never ride along in a test upload.
        $standard = $this->standardFieldMaps($type, (string) $batch['month_end']);
        if (count($standard) !== count($records)) {
            throw new \RuntimeException('This test batch does not hold the standard set of ' . count($standard) . ' test records, so nothing was sent. Generate the test file again.');
        }
        foreach ($records as $i => $record) {
            if (serialize($record['fields']) !== serialize($standard[$i])) {
                throw new \RuntimeException('Record ' . ($i + 1) . ' is not the standard synthetic test record, so nothing was sent. Generate the test file again.');
            }
        }

        $expectedName = $ref . '_ALL_' . self::FILE_TYPE . '_' . ($type === 'Daily' ? 'D' : 'M') . '_' . str_replace('-', '', (string) $batch['month_end']) . '_1_1.txt';
        if ($file['filename'] !== $expectedName || !self::isSafeTestFilename($file['filename'])) {
            throw new \RuntimeException('The file name is not the expected test file name, so nothing was sent.');
        }

        $lines = explode("\r\n", $file['content']);
        $expectedLines = $type === 'Daily' ? count($records) : count($records) + 2;
        $expectedLength = $type === 'Daily' ? self::DAILY_LINE_LENGTH : self::MONTHLY_LINE_LENGTH;
        if (count($lines) !== $expectedLines) {
            throw new \RuntimeException('The test file does not have the expected number of lines, so nothing was sent.');
        }
        foreach ($lines as $line) {
            if (strlen($line) !== $expectedLength) {
                throw new \RuntimeException('A line of the test file is not ' . $expectedLength . ' characters long, so nothing was sent.');
            }
        }
    }

    /**
     * Decides whether a file chosen from the computer -- one made earlier and
     * kept, for example, in the Downloads folder -- may be sent to Creditinfo
     * exactly as it is.
     *
     * The rule is exactness: the file must be the standard sign-off test file
     * for the date in its name, byte for byte, as this app builds it for the
     * configured Supplier Reference Number and trading name. The one thing
     * allowed to differ is the file creation date in a monthly header
     * (positions 22-29), because a file kept from an earlier day carries that
     * day; it must be a real date, not before the month end and not after
     * today. So nothing but the approved synthetic test records can pass, and
     * the bytes that pass are the bytes that are sent.
     *
     * @return array{kind:string, date:string, record_count:int, creation_date:?string}
     * @throws \RuntimeException with a message written for the person who chose the file
     */
    public function inspectStandardFile(string $filename, string $content, string $supplierRef, string $tradingName, ?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $ref = trim($supplierRef);
        if (!preg_match('/^[A-Za-z0-9]{1,10}$/D', $ref)) {
            throw new \RuntimeException('Set the Supplier Reference Number (for example NA02629) in CPL Settings first. It forms the file name Creditinfo expects. Nothing was sent.');
        }

        if (!preg_match('/^([A-Za-z0-9]{1,10})_ALL_' . self::FILE_TYPE . '_([MD])_(\d{4})(\d{2})(\d{2})_1_1\.txt$/D', $filename, $m)) {
            throw new \RuntimeException('"' . $this->printable($filename) . '" is not a sign-off test file name. A test file is named like ' . $ref . '_ALL_' . self::FILE_TYPE . '_M_20261031_1_1.txt (monthly) or ' . $ref . '_ALL_' . self::FILE_TYPE . '_D_20261110_1_1.txt (daily). If your browser added (1) to the name, choose the original file or remove the (1). Nothing was sent.');
        }
        if ($m[1] !== $ref) {
            throw new \RuntimeException('This file name starts with ' . $m[1] . ', but the Supplier Reference Number in CPL Settings is ' . $ref . '. Nothing was sent.');
        }
        $kind = $m[2] === 'D' ? 'Daily' : 'Monthly';
        $date = $m[3] . '-' . $m[4] . '-' . $m[5];
        if (!checkdate((int) $m[4], (int) $m[5], (int) $m[3])) {
            throw new \RuntimeException('The date in the file name (' . $m[3] . $m[4] . $m[5] . ') is not a real date. Nothing was sent.');
        }
        if ($date > $today) {
            throw new \RuntimeException('The date in the file name (' . $date . ') is in the future. Nothing was sent.');
        }
        if ($date < self::EARLIEST_TEST_DATE) {
            throw new \RuntimeException('The date in the file name (' . $date . ') is long ago; a sign-off test file is dated recently. Nothing was sent.');
        }
        if ($kind === 'Monthly' && $date !== date('Y-m-t', strtotime($date))) {
            throw new \RuntimeException('A monthly file is dated the last day of a month, and ' . $date . ' is not. Nothing was sent.');
        }

        $standardRecords = array_map(
            static fn (array $fields): array => ['fields' => $fields],
            $this->standardFieldMaps($kind, $date)
        );
        $expected = $this->build(['batch_type' => $kind, 'month_end' => $date, 'test_records' => json_encode($standardRecords)], $ref, $tradingName);
        $expectedLines = explode("\r\n", $expected['content']);

        // A monthly header carries the day the file was created. A file kept from an earlier day has that day,
        // so take it from the file -- but only a real date between the month end and today.
        $creationDate = null;
        if ($kind === 'Monthly') {
            $firstLine = explode("\r\n", $content, 2)[0];
            $digits = strlen($firstLine) === self::MONTHLY_LINE_LENGTH ? substr($firstLine, 21, 8) : '';
            if (preg_match('/^(\d{4})(\d{2})(\d{2})$/D', $digits, $c) && checkdate((int) $c[2], (int) $c[3], (int) $c[1])) {
                $candidate = $c[1] . '-' . $c[2] . '-' . $c[3];
                if ($candidate >= $date && $candidate <= $today) {
                    $creationDate = $candidate;
                    $expectedLines[0] = substr_replace($expectedLines[0], $digits, 21, 8);
                }
            }
        }

        if ($content !== implode("\r\n", $expectedLines)) {
            throw new \RuntimeException($this->describeDifference($kind, $content, $expectedLines) . self::REMEDY);
        }

        return ['kind' => $kind, 'date' => $date, 'record_count' => count($standardRecords), 'creation_date' => $creationDate];
    }

    /** Says what is wrong with a file that is not the standard test file, as plainly as the evidence allows. */
    private function describeDifference(string $kind, string $content, array $expectedLines): string
    {
        $expectedContent = implode("\r\n", $expectedLines);
        if ($content === $expectedContent . "\r\n" || $content === $expectedContent . "\n") {
            return 'The file ends with an extra blank line. Choose the file exactly as it was made, without opening and saving it again. Nothing was sent.';
        }
        if (str_contains($content, "\n") && !str_contains($content, "\r\n")) {
            return 'The file uses plain line endings instead of Windows ones, which happens when it is opened and saved again. Choose the file exactly as it was made. Nothing was sent.';
        }

        $actualLines = explode("\r\n", $content);
        if (count($actualLines) !== count($expectedLines)) {
            return 'The file has ' . count($actualLines) . ' lines, but the standard ' . strtolower($kind) . ' test file has ' . count($expectedLines) . '. Nothing was sent.';
        }
        foreach ($expectedLines as $i => $line) {
            if ($actualLines[$i] === $line) {
                continue;
            }
            if ($kind === 'Monthly' && $i === 0) {
                return 'The header line is not the standard one. Check the Supplier Reference Number and trading name in CPL Settings, and that the dates in the file are right. Nothing was sent.';
            }
            if ($kind === 'Monthly' && $i === count($expectedLines) - 1) {
                return 'The trailer line is not the standard one. Nothing was sent.';
            }
            $recordNumber = $kind === 'Monthly' ? $i : $i + 1;
            return 'Record ' . $recordNumber . ' is not the standard test record, so the file was changed or was made for another date. Nothing was sent.';
        }
        return 'This is not the standard ' . strtolower($kind) . ' test file for its date. Nothing was sent.';
    }

    /** The synthetic field maps CplUatTestDataService generates for a batch type and date: the standard every sign-off test file is held to. */
    private function standardFieldMaps(string $type, string $date): array
    {
        return $type === 'Daily' ? $this->testData->dailyTestRecords($date) : $this->testData->monthlyTestRecords($date);
    }

    /** A file name made safe to show in a message: printable ASCII only, and not absurdly long. */
    private function printable(string $text): string
    {
        return substr((string) preg_replace('/[^\x20-\x7E]/', '?', $text), 0, 80);
    }

    /**
     * @return array<int, array{fields: array<string,mixed>}>
     * @throws \RuntimeException when a stored record has no field map
     */
    private function storedRecords(array $batch): array
    {
        $decoded = json_decode((string) ($batch['test_records'] ?? ''), true);
        if (!is_array($decoded)) {
            return [];
        }
        $records = [];
        foreach (array_values($decoded) as $record) {
            if (!is_array($record) || !isset($record['fields']) || !is_array($record['fields'])) {
                throw new \RuntimeException('The stored test records are damaged. Generate the test file again.');
            }
            $records[] = $record;
        }
        return $records;
    }
}
