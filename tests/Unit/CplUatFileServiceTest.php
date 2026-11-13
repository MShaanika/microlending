<?php

namespace Tests\Unit;

use App\Services\CplRecordBuilder;
use App\Services\CplUatFileService;
use App\Services\CplUatTestDataService;
use App\Services\CplValidationService;
use PHPUnit\Framework\TestCase;

/**
 * CplUatFileService builds the sign-off TEST file (the same bytes for the
 * Download button and the Upload button) and decides whether it may be sent to
 * Creditinfo. An upload to a credit bureau cannot be taken back, so most of
 * these tests are refusals: each one tampers with one thing and expects the
 * guard to say no for that reason.
 */
class CplUatFileServiceTest extends TestCase
{
    private const SRN = 'NA02629';
    private const TRADING_NAME = 'Solid Desert Cash Loan';

    private CplUatFileService $service;

    protected function setUp(): void
    {
        $this->service = new CplUatFileService();
    }

    /** A batch row shaped like the one CplUatTestController::generateMonthly()/generateDaily() stores. */
    private function batch(string $type, array $overrides = [], ?string $date = null): array
    {
        $monthEnd = $date ?? ($type === 'Daily' ? '2026-11-10' : '2026-10-31');
        $data = new CplUatTestDataService();
        $validator = new CplValidationService();
        $fieldMaps = $type === 'Daily' ? $data->dailyTestRecords($monthEnd) : $data->monthlyTestRecords($monthEnd);

        $records = [];
        $blocking = 0;
        foreach ($fieldMaps as $fields) {
            $issues = $validator->validate($fields);
            $status = $validator->overallStatus($issues);
            $blocking += $status === 'Blocking Error' ? 1 : 0;
            $records[] = ['fields' => $fields, 'issues' => $issues, 'status' => $status];
        }

        return array_merge([
            'id' => 7,
            'batch_type' => $type,
            'is_uat_test' => 1,
            'month_end' => $monthEnd,
            'status' => 'Ready for Review',
            'total_records' => count($records),
            'blocking_error_count' => $blocking,
            'warning_count' => 0,
            'test_records' => json_encode($records),
        ], $overrides);
    }

    /** Same batch with one record's field map changed. */
    private function withRecordField(array $batch, int $index, string $field, string $value): array
    {
        $records = json_decode($batch['test_records'], true);
        $records[$index]['fields'][$field] = $value;
        $batch['test_records'] = json_encode($records);
        return $batch;
    }

    /** Same batch with its stored record list changed by $edit(array $records): array. */
    private function withRecords(array $batch, callable $edit): array
    {
        $batch['test_records'] = json_encode($edit(json_decode($batch['test_records'], true)));
        return $batch;
    }

    private function build(array $batch, string $ref = self::SRN): array
    {
        return $this->service->build($batch, $ref, self::TRADING_NAME);
    }

    /**
     * The code CplUatTestController::download() used before the shared builder existed,
     * kept here as the reference the refactor must match exactly.
     *
     * @return array{0:string,1:string} [filename, content]
     */
    private function legacyDownload(array $batch, string $rawRef, string $tradingName): array
    {
        $testRecords = json_decode($batch['test_records'], true) ?: [];
        $builder = new CplRecordBuilder();
        $fileType = 'T702';
        $supplierRef = preg_replace('/[^A-Za-z0-9_-]/', '_', $rawRef ?: 'PENDING');

        if ($batch['batch_type'] === 'Daily') {
            $lines = [];
            foreach ($testRecords as $record) {
                $lines[] = $builder->dailyRecord($record['fields'], $rawRef, $batch['month_end']);
            }
            return [$supplierRef . '_ALL_' . $fileType . '_D_' . str_replace('-', '', $batch['month_end']) . '_1_1.txt', implode("\r\n", $lines)];
        }

        $lines = [];
        foreach ($testRecords as $record) {
            $lines[] = $builder->record($record['fields']);
        }
        $header = $builder->header($rawRef, $batch['month_end'], $tradingName);
        $trailer = $builder->trailer(count($lines) + 2);
        return [$supplierRef . '_ALL_' . $fileType . '_M_' . str_replace('-', '', $batch['month_end']) . '_1_1.txt', implode("\r\n", array_merge([$header], $lines, [$trailer]))];
    }

    // ---- the file itself ---------------------------------------------------

    /** @dataProvider typesAndReferences */
    public function testFileIsByteIdenticalToWhatTheOldDownloadBuilt(string $type, string $ref): void
    {
        $batch = $this->batch($type);
        [$legacyName, $legacyContent] = $this->legacyDownload($batch, $ref, self::TRADING_NAME);

        $file = $this->build($batch, $ref);

        $this->assertSame($legacyName, $file['filename']);
        $this->assertSame($legacyContent, $file['content']);
    }

    public function typesAndReferences(): array
    {
        return [
            'monthly, normal reference' => ['Monthly', 'NA02629'],
            'daily, normal reference' => ['Daily', 'NA02629'],
            'monthly, blank reference (PENDING)' => ['Monthly', ''],
            'daily, odd characters in the reference' => ['Daily', 'NA 026/29'],
        ];
    }

    public function testMonthlyFileIsHeaderThenRecordsThenTrailerOf700Characters(): void
    {
        $file = $this->build($this->batch('Monthly'));
        $lines = explode("\r\n", $file['content']);

        $this->assertCount(7, $lines, '5 records + header + trailer');
        $this->assertSame(5, $file['record_count']);
        $this->assertSame('Monthly', $file['kind']);
        foreach ($lines as $line) {
            $this->assertSame(700, strlen($line));
        }
        $this->assertSame('H', $lines[0][0]);
        $this->assertSame('T', $lines[6][0]);
        $this->assertStringStartsWith('T000000007', $lines[6], 'trailer counts the header and trailer too');
    }

    public function testDailyFileIsOneLinePerRecordOf718Characters(): void
    {
        $file = $this->build($this->batch('Daily'));
        $lines = explode("\r\n", $file['content']);

        $this->assertCount(2, $lines);
        $this->assertSame('Daily', $file['kind']);
        foreach ($lines as $line) {
            $this->assertSame(718, strlen($line));
        }
        $this->assertSame('R', $lines[0][0], 'registration');
        $this->assertSame('C', $lines[1][0], 'closure');
    }

    public function testFileNameIsAlwaysTheTestTypeNeverLive(): void
    {
        $this->assertSame('NA02629_ALL_T702_M_20261031_1_1.txt', $this->build($this->batch('Monthly'))['filename']);
        $this->assertSame('NA02629_ALL_T702_D_20261110_1_1.txt', $this->build($this->batch('Daily'))['filename']);
        $this->assertStringNotContainsString('L702', $this->build($this->batch('Monthly'))['filename']);
    }

    public function testADamagedStoredRecordFailsClearlyInsteadOfFatally(): void
    {
        $batch = $this->batch('Monthly', ['test_records' => json_encode([['status' => 'Valid']])]);
        $refusal = $this->refusal(fn () => $this->build($batch), 'A damaged record was built into a file.');
        $this->assertStringContainsString('damaged', $refusal->getMessage());
    }

    // ---- the file-name rule ------------------------------------------------

    /** @dataProvider fileNames */
    public function testSafeFileNameRule(string $name, bool $expected): void
    {
        $this->assertSame($expected, CplUatFileService::isSafeTestFilename($name), $name);
    }

    public function fileNames(): array
    {
        return [
            'monthly test' => ['NA02629_ALL_T702_M_20261031_1_1.txt', true],
            'daily test' => ['NA02629_ALL_T702_D_20261110_1_1.txt', true],
            'LIVE type' => ['NA02629_ALL_L702_M_20261031_1_1.txt', false],
            'pgp' => ['NA02629_ALL_T702_M_20261031_1_1.txt.pgp', false],
            'upper-case extension' => ['NA02629_ALL_T702_M_20261031_1_1.TXT', false],
            'folder in front' => ['In/NA02629_ALL_T702_M_20261031_1_1.txt', false],
            'parent folder' => ['../NA02629_ALL_T702_M_20261031_1_1.txt', false],
            'windows folder' => ['..\\NA02629_ALL_T702_M_20261031_1_1.txt', false],
            'trailing newline' => ["NA02629_ALL_T702_M_20261031_1_1.txt\n", false],
            'space in reference' => ['NA 02629_ALL_T702_M_20261031_1_1.txt', false],
            'underscore in reference' => ['NA_2629_ALL_T702_M_20261031_1_1.txt', false],
            'other frequency' => ['NA02629_ALL_T702_W_20261031_1_1.txt', false],
            'short date' => ['NA02629_ALL_T702_M_2026103_1_1.txt', false],
            'later sequence' => ['NA02629_ALL_T702_M_20261031_2_1.txt', false],
            'empty' => ['', false],
        ];
    }

    // ---- what may be uploaded ----------------------------------------------

    /** @dataProvider batchTypes */
    public function testAValidTestBatchIsUploadable(string $type): void
    {
        $batch = $this->batch($type);
        $this->service->assertUploadable($batch, self::SRN, $this->build($batch));
        $this->addToAssertionCount(1);
    }

    public function batchTypes(): array
    {
        return ['monthly' => ['Monthly'], 'daily' => ['Daily']];
    }

    public function testASurroundingSpaceInTheReferenceSettingIsTolerated(): void
    {
        $batch = $this->batch('Monthly');

        $file = $this->build($batch, ' NA02629 ');

        $this->assertSame('NA02629_ALL_T702_M_20261031_1_1.txt', $file['filename'], 'no underscore from the stray space');
        $this->assertSame($this->build($batch, 'NA02629')['content'], $file['content'], 'and the header carries the clean reference');
        $this->service->assertUploadable($batch, ' NA02629 ', $file);
        $this->addToAssertionCount(1);
    }

    // ---- a file chosen from the computer ------------------------------------

    private const TODAY = '2026-11-13';

    /**
     * A standard test file as this app builds it (monthly for 2026-10-31, daily for 2026-11-10). The monthly
     * header's creation date is set explicitly so the tests do not depend on the day they run.
     *
     * @return array{filename:string, content:string, kind:string, record_count:int}
     */
    private function standardFile(string $type, string $creation = '20261110', ?string $date = null): array
    {
        $file = $this->build($this->batch($type, [], $date));
        if ($type === 'Monthly') {
            $lines = explode("\r\n", $file['content']);
            $lines[0] = substr_replace($lines[0], $creation, 21, 8);
            $file['content'] = implode("\r\n", $lines);
        }
        return $file;
    }

    /** The same content with line $index changed by $edit(string): string. */
    private function withLine(string $content, int $index, callable $edit): string
    {
        $lines = explode("\r\n", $content);
        $lines[$index] = $edit($lines[$index]);
        return implode("\r\n", $lines);
    }

    private function inspect(string $filename, string $content, string $ref = self::SRN, string $tradingName = self::TRADING_NAME): array
    {
        return $this->service->inspectStandardFile($filename, $content, $ref, $tradingName, self::TODAY);
    }

    /** @dataProvider acceptedChosenFiles */
    public function testAStandardFileChosenFromTheComputerIsAcceptedAsItIs(string $type, string $creation, array $expected): void
    {
        $file = $this->standardFile($type, $creation);

        $this->assertSame($expected, $this->inspect($file['filename'], $file['content']));
    }

    public function acceptedChosenFiles(): array
    {
        return [
            'monthly made on 10 Nov' => ['Monthly', '20261110', ['kind' => 'Monthly', 'date' => '2026-10-31', 'record_count' => 5, 'creation_date' => '2026-11-10']],
            'monthly made on the month end itself' => ['Monthly', '20261031', ['kind' => 'Monthly', 'date' => '2026-10-31', 'record_count' => 5, 'creation_date' => '2026-10-31']],
            'monthly made today' => ['Monthly', '20261113', ['kind' => 'Monthly', 'date' => '2026-10-31', 'record_count' => 5, 'creation_date' => '2026-11-13']],
            'daily' => ['Daily', '20261110', ['kind' => 'Daily', 'date' => '2026-11-10', 'record_count' => 2, 'creation_date' => null]],
        ];
    }

    public function testAStraySpaceInTheReferenceSettingIsTolerated(): void
    {
        $file = $this->standardFile('Monthly');

        $info = $this->inspect($file['filename'], $file['content'], ' NA02629 ');

        $this->assertSame('2026-10-31', $info['date'], 'a stray space in the setting is tolerated');
    }

    /** @dataProvider refusedChosenFiles */
    public function testAFileChosenFromTheComputerIsRefusedUnlessItIsExactlyTheStandardOne(callable $make, string $messagePart): void
    {
        [$filename, $content, $ref, $tradingName] = $make($this);

        $refusal = $this->refusal(
            fn () => $this->inspect($filename, $content, $ref, $tradingName),
            'A file that is not the standard test file was accepted (' . $messagePart . ').'
        );

        $this->assertStringContainsString($messagePart, $refusal->getMessage());
        $this->assertStringContainsString('Nothing was sent', $refusal->getMessage());
    }

    public function testARefusedFileThatCannotBeFixedIsToldWhatToDoInstead(): void
    {
        $file = $this->standardFile('Monthly');
        $tampered = $this->withLine($file['content'], 3, static fn (string $l): string => substr_replace($l, $l[100] === 'Z' ? 'Y' : 'Z', 100, 1));

        $refusal = $this->refusal(fn () => $this->inspect($file['filename'], $tampered), 'A changed file was accepted.');

        $this->assertStringContainsString('use Generate on the sign-off page to make a new file', $refusal->getMessage());
    }

    public function refusedChosenFiles(): array
    {
        $monthly = static fn (self $t): array => $t->standardFile('Monthly');
        $daily = static fn (self $t): array => $t->standardFile('Daily');

        return [
            'a live (L702) file name' => [static function (self $t) use ($monthly) {
                $f = $monthly($t);
                return [str_replace('T702', 'L702', $f['filename']), $f['content'], 'NA02629', self::TRADING_NAME];
            }, 'is not a sign-off test file name'],
            'a file name with a folder in it' => [static function (self $t) use ($monthly) {
                $f = $monthly($t);
                return ['../' . $f['filename'], $f['content'], 'NA02629', self::TRADING_NAME];
            }, 'is not a sign-off test file name'],
            'an encrypted file name' => [static function (self $t) use ($monthly) {
                $f = $monthly($t);
                return [$f['filename'] . '.pgp', $f['content'], 'NA02629', self::TRADING_NAME];
            }, 'is not a sign-off test file name'],
            'a file name with a line break in it' => [static function (self $t) use ($monthly) {
                $f = $monthly($t);
                return ["x\n" . $f['filename'], $f['content'], 'NA02629', self::TRADING_NAME];
            }, 'is not a sign-off test file name'],
            'another supplier reference in the file name' => [static function (self $t) use ($monthly) {
                $f = $monthly($t);
                return [str_replace('NA02629', 'NA99999', $f['filename']), $f['content'], 'NA02629', self::TRADING_NAME];
            }, 'starts with NA99999'],
            'no supplier reference configured' => [static function (self $t) use ($monthly) {
                $f = $monthly($t);
                return [$f['filename'], $f['content'], '', self::TRADING_NAME];
            }, 'Supplier Reference Number'],
            'a date that is not a real date' => [static function (self $t) use ($daily) {
                $f = $daily($t);
                return ['NA02629_ALL_T702_D_20261332_1_1.txt', $f['content'], 'NA02629', self::TRADING_NAME];
            }, 'is not a real date'],
            'a file dated long ago (otherwise a perfect standard file)' => [static function (self $t) {
                $f = $t->standardFile('Monthly', '20261110', '2019-12-31');
                return [$f['filename'], $f['content'], 'NA02629', self::TRADING_NAME];
            }, 'long ago'],
            'a file dated in year 1 (otherwise a perfect standard file)' => [static function (self $t) {
                $f = $t->standardFile('Daily', '20261110', '0001-01-31');
                return [$f['filename'], $f['content'], 'NA02629', self::TRADING_NAME];
            }, 'long ago'],
            'a browser renamed the second copy (1)' => [static function (self $t) use ($monthly) {
                $f = $monthly($t);
                return [str_replace('.txt', ' (1).txt', $f['filename']), $f['content'], 'NA02629', self::TRADING_NAME];
            }, 'added (1) to the name'],
            'a date in the future' => [static function (self $t) use ($daily) {
                $f = $daily($t);
                return ['NA02629_ALL_T702_D_20261201_1_1.txt', $f['content'], 'NA02629', self::TRADING_NAME];
            }, 'is in the future'],
            'a monthly file that is not dated a month end' => [static function (self $t) use ($monthly) {
                $f = $monthly($t);
                return ['NA02629_ALL_T702_M_20261030_1_1.txt', $f['content'], 'NA02629', self::TRADING_NAME];
            }, 'last day of a month'],
            'one character changed in a record' => [static function (self $t) use ($monthly) {
                $f = $monthly($t);
                return [$f['filename'], $t->withLine($f['content'], 3, static fn (string $l): string => substr_replace($l, $l[100] === 'Z' ? 'Y' : 'Z', 100, 1)), 'NA02629', self::TRADING_NAME];
            }, 'Record 3 is not the standard test record'],
            'a real-looking National ID in a daily record' => [static function (self $t) use ($daily) {
                $f = $daily($t);
                return [$f['filename'], str_replace('8005041272341', '8001015009087', $f['content']), 'NA02629', self::TRADING_NAME];
            }, 'is not the standard test record'],
            'the monthly content under a daily name' => [static function (self $t) use ($monthly) {
                $f = $monthly($t);
                return ['NA02629_ALL_T702_D_20261031_1_1.txt', $f['content'], 'NA02629', self::TRADING_NAME];
            }, 'lines'],
            'a daily file for another date than its name says' => [static function (self $t) use ($daily) {
                $f = $daily($t);
                return ['NA02629_ALL_T702_D_20261109_1_1.txt', $f['content'], 'NA02629', self::TRADING_NAME];
            }, 'Record 1 is not the standard test record'],
            'a trading name that differs from the setting' => [static function (self $t) use ($monthly) {
                $f = $monthly($t);
                return [$f['filename'], $f['content'], 'NA02629', 'Some Other Lender'];
            }, 'header line is not the standard one'],
            'a header carrying another supplier reference' => [static function (self $t) use ($monthly) {
                $f = $monthly($t);
                return [$f['filename'], $t->withLine($f['content'], 0, static fn (string $l): string => substr_replace($l, 'NA99999', 4, 7)), 'NA02629', self::TRADING_NAME];
            }, 'header line is not the standard one'],
            'a header created before the month it reports ended' => [static function (self $t) {
                $f = $t->standardFile('Monthly', '20261015');
                return [$f['filename'], $f['content'], 'NA02629', self::TRADING_NAME];
            }, 'header line is not the standard one'],
            'a header created in the future' => [static function (self $t) {
                $f = $t->standardFile('Monthly', '20991231');
                return [$f['filename'], $f['content'], 'NA02629', self::TRADING_NAME];
            }, 'header line is not the standard one'],
            'a header creation date that is not a date' => [static function (self $t) {
                $f = $t->standardFile('Monthly', '20261340');
                return [$f['filename'], $f['content'], 'NA02629', self::TRADING_NAME];
            }, 'header line is not the standard one'],
            'a changed trailer' => [static function (self $t) use ($monthly) {
                $f = $monthly($t);
                return [$f['filename'], $t->withLine($f['content'], 6, static fn (string $l): string => 'T000000009' . substr($l, 10)), 'NA02629', self::TRADING_NAME];
            }, 'trailer line is not the standard one'],
            'a missing record' => [static function (self $t) use ($monthly) {
                $f = $monthly($t);
                $lines = explode("\r\n", $f['content']);
                unset($lines[2]);
                return [$f['filename'], implode("\r\n", $lines), 'NA02629', self::TRADING_NAME];
            }, 'lines, but the standard monthly test file has 7'],
            'an extra blank line at the end' => [static function (self $t) use ($monthly) {
                $f = $monthly($t);
                return [$f['filename'], $f['content'] . "\r\n", 'NA02629', self::TRADING_NAME];
            }, 'extra blank line'],
            'plain (LF) line endings' => [static function (self $t) use ($monthly) {
                $f = $monthly($t);
                return [$f['filename'], str_replace("\r\n", "\n", $f['content']), 'NA02629', self::TRADING_NAME];
            }, 'plain line endings'],
            'an empty file' => [static function (self $t) use ($monthly) {
                $f = $monthly($t);
                return [$f['filename'], '', 'NA02629', self::TRADING_NAME];
            }, 'lines, but the standard monthly test file has 7'],
            'text with a byte order mark in front' => [static function (self $t) use ($daily) {
                $f = $daily($t);
                return [$f['filename'], "\xEF\xBB\xBF" . $f['content'], 'NA02629', self::TRADING_NAME];
            }, 'Record 1 is not the standard test record'],
        ];
    }

    public function testAHostileFileNameIsMadeSafeBeforeItIsShownInAMessage(): void
    {
        $refusal = $this->refusal(
            fn () => $this->inspect("evil\r\n<script>" . str_repeat('x', 2000) . '.txt', 'x'),
            'A hostile file name was accepted.'
        );

        $this->assertStringNotContainsString("\n", $refusal->getMessage());
        $this->assertStringNotContainsString("\r", $refusal->getMessage());
        $this->assertLessThan(500, strlen($refusal->getMessage()), 'a 2000-character name is cut short in the message');
    }

    /**
     * Runs $action and returns the RuntimeException it refuses with. PHPUnit's own failures are
     * RuntimeExceptions too, so a try { ...; fail() } catch (RuntimeException) would swallow its
     * own "it did not refuse" failure -- this helper cannot.
     */
    private function refusal(callable $action, string $failureMessage): \RuntimeException
    {
        try {
            $action();
        } catch (\PHPUnit\Framework\Exception $phpunitFailure) {
            throw $phpunitFailure;
        } catch (\RuntimeException $refusal) {
            return $refusal;
        }
        $this->fail($failureMessage);
    }

    /** @dataProvider refusals */
    public function testGuardRefuses(callable $tamper, string $messagePart): void
    {
        [$batch, $ref, $file] = $tamper($this);
        $refusal = $this->refusal(
            fn () => $this->service->assertUploadable($batch, $ref, $file),
            'The guard accepted a file it must refuse (' . $messagePart . ').'
        );
        $this->assertStringContainsString($messagePart, $refusal->getMessage());
    }

    public function refusals(): array
    {
        return [
            'a real (non-test) batch' => [static function (self $t) {
                $batch = $t->batch('Monthly', ['is_uat_test' => 0]);
                return [$batch, 'NA02629', $t->build($batch)];
            }, 'Only sign-off test files'],
            'an unknown batch type' => [static function (self $t) {
                $batch = $t->batch('Monthly', ['batch_type' => 'Weekly']);
                return [$batch, 'NA02629', $t->build($batch)];
            }, 'unknown type'],
            'blocking validation errors' => [static function (self $t) {
                $batch = $t->batch('Monthly', ['blocking_error_count' => 1]);
                return [$batch, 'NA02629', $t->build($batch)];
            }, 'blocking validation errors'],
            'no records ([])' => [static function (self $t) {
                $batch = $t->batch('Monthly', ['test_records' => '[]']);
                return [$batch, 'NA02629', $t->build($batch)];
            }, 'no records'],
            'no records (NULL)' => [static function (self $t) {
                $batch = $t->batch('Monthly', ['test_records' => null]);
                return [$batch, 'NA02629', $t->build($batch)];
            }, 'no records'],
            'unreadable records (not JSON)' => [static function (self $t) {
                $batch = $t->batch('Monthly', ['test_records' => 'not json']);
                return [$batch, 'NA02629', $t->build($batch)];
            }, 'no records'],
            'a National ID Creditinfo did not approve for testing' => [static function (self $t) {
                $batch = $t->withRecordField($t->batch('Monthly'), 2, 'na_id', '80010150090');
                return [$batch, 'NA02629', $t->build($batch)];
            }, 'Record 3 does not use one of the National IDs Creditinfo approved'],
            'a blank National ID' => [static function (self $t) {
                $batch = $t->withRecordField($t->batch('Daily'), 0, 'na_id', '');
                return [$batch, 'NA02629', $t->build($batch)];
            }, 'Record 1 does not use one of the National IDs'],
            'a non-Namibian ID on a test record' => [static function (self $t) {
                $batch = $t->withRecordField($t->batch('Monthly'), 0, 'non_na_id', 'P1234567');
                return [$batch, 'NA02629', $t->build($batch)];
            }, 'non-Namibian ID'],
            'an account number without the test label' => [static function (self $t) {
                $batch = $t->withRecordField($t->batch('Monthly'), 4, 'account_no', 'LN-2026-0042');
                return [$batch, 'NA02629', $t->build($batch)];
            }, 'Record 5 is not labelled as test data'],
            'a name changed in a record (the IDs and account numbers are still fine)' => [static function (self $t) {
                $batch = $t->withRecordField($t->batch('Monthly'), 3, 'surname', 'MUELLER');
                return [$batch, 'NA02629', $t->build($batch)];
            }, 'Record 4 is not the standard synthetic test record'],
            'a phone number changed in a daily record' => [static function (self $t) {
                $batch = $t->withRecordField($t->batch('Daily'), 0, 'cellular_telephone', '0811111111');
                return [$batch, 'NA02629', $t->build($batch)];
            }, 'Record 1 is not the standard synthetic test record'],
            'an amount changed in a record' => [static function (self $t) {
                $batch = $t->withRecordField($t->batch('Monthly'), 1, 'current_balance', '987654');
                return [$batch, 'NA02629', $t->build($batch)];
            }, 'Record 2 is not the standard synthetic test record'],
            'an extra record added (valid ID and label)' => [static function (self $t) {
                $batch = $t->withRecords($t->batch('Monthly'), static function (array $r): array {
                    $extra = $r[0];
                    $extra['fields']['account_no'] = 'UATTEST-MONTHLY-006';
                    $r[] = $extra;
                    return $r;
                });
                return [$batch, 'NA02629', $t->build($batch)];
            }, 'standard set of 5 test records'],
            'a record removed' => [static function (self $t) {
                $batch = $t->withRecords($t->batch('Monthly'), static function (array $r): array {
                    array_pop($r);
                    return $r;
                });
                return [$batch, 'NA02629', $t->build($batch)];
            }, 'standard set of 5 test records'],
            'records in another order' => [static function (self $t) {
                $batch = $t->withRecords($t->batch('Monthly'), static function (array $r): array {
                    [$r[0], $r[1]] = [$r[1], $r[0]];
                    return $r;
                });
                return [$batch, 'NA02629', $t->build($batch)];
            }, 'Record 1 is not the standard synthetic test record'],
            'a batch date that does not match its records' => [static function (self $t) {
                $batch = $t->batch('Monthly', ['month_end' => '2026-09-30']);
                return [$batch, 'NA02629', $t->build($batch)];
            }, 'Record 1 is not the standard synthetic test record'],
            'a monthly test file dated in the future (its records match that date)' => [static function (self $t) {
                $batch = $t->batch('Monthly', [], '2099-12-31');
                return [$batch, 'NA02629', $t->build($batch)];
            }, 'dated 2099-12-31, which is in the future'],
            'a daily test file dated in the future (its records match that date)' => [static function (self $t) {
                $batch = $t->batch('Daily', [], '2099-12-30');
                return [$batch, 'NA02629', $t->build($batch)];
            }, 'dated 2099-12-30, which is in the future'],
            'a monthly test file not dated a month end (its records match that date)' => [static function (self $t) {
                $batch = $t->batch('Monthly', [], '2026-10-30');
                return [$batch, 'NA02629', $t->build($batch)];
            }, 'last day of a month, and 2026-10-30 is not'],
            'a live (L702) file name' => [static function (self $t) {
                $batch = $t->batch('Monthly');
                $file = $t->build($batch);
                $file['filename'] = str_replace('T702', 'L702', $file['filename']);
                return [$batch, 'NA02629', $file];
            }, 'not the expected test file name'],
            'a file name for the other frequency' => [static function (self $t) {
                $batch = $t->batch('Monthly');
                $file = $t->build($batch);
                $file['filename'] = str_replace('_M_', '_D_', $file['filename']);
                return [$batch, 'NA02629', $file];
            }, 'not the expected test file name'],
            'a file name with a folder in it' => [static function (self $t) {
                $batch = $t->batch('Monthly');
                $file = $t->build($batch);
                $file['filename'] = '../' . $file['filename'];
                return [$batch, 'NA02629', $file];
            }, 'not the expected test file name'],
            'a file name from a different date than the batch' => [static function (self $t) {
                $batch = $t->batch('Monthly');
                $file = $t->build($batch);
                $file['filename'] = str_replace('20261031', '20261130', $file['filename']);
                return [$batch, 'NA02629', $file];
            }, 'not the expected test file name'],
            'a missing line' => [static function (self $t) {
                $batch = $t->batch('Monthly');
                $file = $t->build($batch);
                $lines = explode("\r\n", $file['content']);
                unset($lines[2]);
                $file['content'] = implode("\r\n", $lines);
                return [$batch, 'NA02629', $file];
            }, 'expected number of lines'],
            'a line of the wrong length' => [static function (self $t) {
                $batch = $t->batch('Daily');
                $file = $t->build($batch);
                $file['content'] = substr($file['content'], 0, -1);
                return [$batch, 'NA02629', $file];
            }, 'not 718 characters'],
            'no supplier reference' => [static function (self $t) {
                $batch = $t->batch('Monthly');
                return [$batch, '', $t->build($batch, '')];
            }, 'Supplier Reference Number'],
            'a supplier reference with a space' => [static function (self $t) {
                $batch = $t->batch('Monthly');
                return [$batch, 'NA 02629', $t->build($batch, 'NA 02629')];
            }, 'Supplier Reference Number'],
            'a supplier reference that is too long' => [static function (self $t) {
                $batch = $t->batch('Monthly');
                return [$batch, 'NA026290000X', $t->build($batch, 'NA026290000X')];
            }, 'Supplier Reference Number'],
        ];
    }
}
