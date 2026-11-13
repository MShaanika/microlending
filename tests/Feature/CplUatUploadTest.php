<?php

namespace Tests\Feature;

use App\Core\Database;
use App\Models\CplBatch;
use App\Models\CplSetting;
use App\Models\CreditinfoPublicDefaultSetting;
use App\Services\CplSftpService;
use App\Services\CplSftpUncertainException;
use App\Services\CplUatBatchService;
use App\Services\CplUatFileService;
use App\Services\CplUatTestDataService;
use App\Services\CplUatUploadService;
use App\Services\CplValidationService;
use PHPUnit\Framework\TestCase;

/**
 * The "Upload to Creditinfo" flow for CPL sign-off test files.
 *
 * Runs against the local dev database (see tests/bootstrap.php) -- which on a
 * developer machine can hold the REAL Creditinfo SFTP host and a real secret.
 * So nothing here ever uses the stored settings or opens a real connection:
 * the settings are fakes (host sftp.test.invalid, which can never resolve) and
 * every upload goes through an injected fake connection. Test batches use
 * reserved past dates (2019 and 2020) that no real sign-off data uses, and are deleted
 * afterwards. (They must be past dates: a test file dated in the future is
 * refused.)
 */
class CplUatUploadTest extends TestCase
{
    private const MONTHLY_END = '2019-11-30';
    private const DAILY_END = '2019-11-29';
    private const CHOSEN_MONTHLY_END = '2020-12-31';
    private const CHOSEN_DAILY_DATE = '2020-12-30';
    private const FUTURE_MONTHLY_END = '2099-12-31';

    private CplUatUploadFakeSftp $fake;
    /** @var array<int, array{0:string,1:int}> */
    private array $connects = [];
    private int $userId = 0;
    private ?string $previousErrorLog = null;
    private string $errorLogFile = '';

    protected function setUp(): void
    {
        $db = Database::connection();
        $this->userId = (int) $db->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        if ($this->userId === 0) {
            $this->markTestSkipped('No user in the local database to record the upload against.');
        }
        $this->purgeTestBatches();
        $this->fake = new CplUatUploadFakeSftp();
        $this->connects = [];
        $this->errorLogFile = sys_get_temp_dir() . '/cpl_uat_upload_test_' . uniqid() . '.log';
        $previous = ini_set('error_log', $this->errorLogFile);
        $this->previousErrorLog = $previous === false ? null : (string) $previous;
    }

    protected function tearDown(): void
    {
        $this->purgeTestBatches();
        if ($this->previousErrorLog !== null) {
            ini_set('error_log', $this->previousErrorLog);
        }
        if (is_file($this->errorLogFile)) {
            @unlink($this->errorLogFile);
        }
    }

    private function purgeTestBatches(): void
    {
        // The reserved 2019 dates these tests use, and 2099 (the future-dated batch test). None of them can hold real data.
        $dates = "'" . implode("', '", [self::MONTHLY_END, self::DAILY_END, self::CHOSEN_MONTHLY_END, self::CHOSEN_DAILY_DATE]) . "'";
        Database::connection()->exec("DELETE FROM cpl_batches WHERE month_end IN ($dates) OR month_end >= '2099-01-01'");
    }

    // ---- helpers -----------------------------------------------------------

    /** The connector handed to the service: records the host/port asked for and returns the fake. */
    private function connector(): \Closure
    {
        return function (string $host, int $port): CplUatUploadFakeSftp {
            $this->connects[] = [$host, $port];
            return $this->fake;
        };
    }

    private function sftpService(array $cpl = [], array $publicDefaults = []): CplSftpService
    {
        return new CplSftpService(new CplBatch(), $this->cplSettings($cpl), $this->publicDefaultSettings($publicDefaults));
    }

    private function cplSettings(array $overrides = []): CplUatUploadFakeCplSetting
    {
        return new CplUatUploadFakeCplSetting(array_merge([
            'supplier_reference_number' => 'NA02629',
            'trading_name' => 'Solid Desert Cash Loan',
            'recipient' => 'ALL',
            'submission_environment' => 'production',
            'sftp_submission_enabled' => 'off',
            'sftp_directory' => '',
        ], $overrides));
    }

    private function publicDefaultSettings(array $overrides = []): CplUatUploadFakePublicDefaultSetting
    {
        $secret = array_key_exists('__secret', $overrides) ? $overrides['__secret'] : 'test-password';
        unset($overrides['__secret']);
        return new CplUatUploadFakePublicDefaultSetting(array_merge([
            'sftp_enabled' => 'on',
            'sftp_host' => 'sftp.test.invalid',
            'sftp_port' => '22',
            'sftp_username' => 'test-user',
            'sftp_outbound_directory' => 'In',
        ], $overrides), $secret);
    }

    private function uploadService(array $cpl = [], array $publicDefaults = [], ?CplBatch $batches = null): CplUatUploadService
    {
        $batches = $batches ?? new CplBatch();
        $cplSettings = $this->cplSettings($cpl);
        return new CplUatUploadService(
            $batches,
            $cplSettings,
            new CplUatFileService(),
            new CplSftpService($batches, $cplSettings, $this->publicDefaultSettings($publicDefaults))
        );
    }

    /** Inserts a sign-off test batch shaped like the one the generate buttons store, and returns it as read back. */
    private function seedBatch(string $type = 'Monthly', array $overrides = [], ?string $date = null): array
    {
        $monthEnd = $date ?? ($type === 'Daily' ? self::DAILY_END : self::MONTHLY_END);
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

        $batches = new CplBatch();
        $id = $batches->create(array_merge([
            'batch_type' => $type,
            'is_uat_test' => 1,
            'month_end' => $monthEnd,
            'status' => 'Ready for Review',
            'total_records' => count($records),
            'blocking_error_count' => $blocking,
            'warning_count' => 0,
            'generated_by' => null,
            'generated_at' => date('Y-m-d H:i:s'),
            'test_records' => json_encode($records),
        ], $overrides));

        return $batches->find($id);
    }

    private function reload(int $id): array
    {
        return (new CplBatch())->find($id);
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

    // ---- the SFTP step -----------------------------------------------------

    public function testUploadTestFileSendsTheExactBytesToTheSavedFolder(): void
    {
        $sftp = $this->sftpService();
        $content = "H...\r\nD...\r\nT...";

        $result = $sftp->uploadTestFile('NA02629_ALL_T702_M_20261031_1_1.txt', $content, $this->connector());

        $this->assertSame([['sftp.test.invalid', 22]], $this->connects);
        $this->assertSame([['test-user', 'test-password']], $this->fake->logins);
        $this->assertSame(['In/NA02629_ALL_T702_M_20261031_1_1.txt' => $content], $this->fake->puts);
        $this->assertSame(1, $this->fake->disconnects);
        $this->assertSame(['host' => 'sftp.test.invalid', 'remote_path' => 'In/NA02629_ALL_T702_M_20261031_1_1.txt', 'bytes' => strlen($content)], $result);
    }

    public function testATestUploadDoesNotNeedTheLiveCplSubmissionSwitch(): void
    {
        $sftp = $this->sftpService(['sftp_submission_enabled' => 'off']);

        $this->assertFalse($sftp->isReady(), 'the live-batch switch is off');
        $this->assertTrue($sftp->isTestUploadReady(), 'but a synthetic test upload only needs the SFTP connection');
    }

    public function testCplFolderOverrideWinsAndATrailingSlashIsTrimmed(): void
    {
        $result = $this->sftpService(['sftp_directory' => 'Custom/Inbound/'])
            ->uploadTestFile('NA02629_ALL_T702_D_20261110_1_1.txt', 'x', $this->connector());

        $this->assertSame('Custom/Inbound/NA02629_ALL_T702_D_20261110_1_1.txt', $result['remote_path']);
    }

    public function testNonStandardPortIsUsedAndShownInTheDestination(): void
    {
        $sftp = $this->sftpService([], ['sftp_port' => '2222']);
        $sftp->uploadTestFile('NA02629_ALL_T702_M_20261031_1_1.txt', 'x', $this->connector());

        $this->assertSame([['sftp.test.invalid', 2222]], $this->connects);
        $this->assertSame('sftp.test.invalid:2222 / In', $sftp->destinationLabel());
        $this->assertSame('sftp.test.invalid / In', $this->sftpService()->destinationLabel());
    }

    public function testDestinationLabelNeverShowsTheUsernameOrSecret(): void
    {
        $label = $this->sftpService()->destinationLabel();

        $this->assertStringNotContainsString('test-user', $label);
        $this->assertStringNotContainsString('test-password', $label);
    }

    /** @dataProvider unsafeNames */
    public function testUploadTestFileRefusesAnythingThatIsNotATestFileName(string $name): void
    {
        $refusal = $this->refusal(
            fn () => $this->sftpService()->uploadTestFile($name, 'x', $this->connector()),
            'A name that is not a test file name was accepted: ' . $name
        );
        $this->assertStringContainsString('not a sign-off test file name', $refusal->getMessage());
        $this->assertSame([], $this->connects, 'nothing may even connect');
        $this->assertSame([], $this->fake->puts);
    }

    public function unsafeNames(): array
    {
        return [
            'live file type' => ['NA02629_ALL_L702_M_20261031_1_1.txt'],
            'folder in the name' => ['../NA02629_ALL_T702_M_20261031_1_1.txt'],
            'deeper folder' => ['In/NA02629_ALL_T702_M_20261031_1_1.txt'],
            'encrypted name' => ['NA02629_ALL_T702_M_20261031_1_1.txt.pgp'],
            'trailing newline' => ["NA02629_ALL_T702_M_20261031_1_1.txt\n"],
        ];
    }

    public function testUploadTestFileRefusesWhenTheSftpConnectionIsNotReady(): void
    {
        foreach ([
            'switched off' => ['sftp_enabled' => 'off'],
            'no host' => ['sftp_host' => ''],
            'no username' => ['sftp_username' => ''],
            'no folder' => ['sftp_outbound_directory' => ''],
            'no password saved' => ['__secret' => null],
        ] as $label => $override) {
            $refusal = $this->refusal(
                fn () => $this->sftpService([], $override)->uploadTestFile('NA02629_ALL_T702_M_20261031_1_1.txt', 'x', $this->connector()),
                'Uploaded although the connection is not ready (' . $label . ').'
            );
            $this->assertStringContainsString('Public Defaults Settings', $refusal->getMessage(), $label);
        }
        $this->assertSame([], $this->connects);
    }

    public function testFailedLoginStopsBeforeAnythingIsSent(): void
    {
        $this->fake->loginOk = false;

        $refusal = $this->refusal(
            fn () => $this->sftpService()->uploadTestFile('NA02629_ALL_T702_M_20261031_1_1.txt', 'x', $this->connector()),
            'A failed login was ignored.'
        );
        $this->assertStringContainsString('login failed', $refusal->getMessage());
        $this->assertStringNotContainsString('test-password', $refusal->getMessage());
        $this->assertSame([], $this->fake->puts);
        $this->assertSame(1, $this->fake->disconnects);
    }

    public function testAConnectionErrorIsReportedWithTheServerAndPort(): void
    {
        $this->fake->loginThrows = new \RuntimeException('Connection timed out');

        $refusal = $this->refusal(
            fn () => $this->sftpService()->uploadTestFile('NA02629_ALL_T702_M_20261031_1_1.txt', 'x', $this->connector()),
            'A connection error was swallowed.'
        );
        $this->assertStringContainsString('Could not connect to sftp.test.invalid:22', $refusal->getMessage());
        $this->assertStringContainsString('Connection timed out', $refusal->getMessage());
        $this->assertSame([], $this->fake->puts);
        $this->assertSame(1, $this->fake->disconnects);
    }

    public function testAFailureToCloseTheConnectionDoesNotTurnASuccessfulUploadIntoAFailure(): void
    {
        $this->fake->disconnectThrows = true;

        $result = $this->sftpService()->uploadTestFile('NA02629_ALL_T702_M_20261031_1_1.txt', 'x', $this->connector());

        $this->assertSame('In/NA02629_ALL_T702_M_20261031_1_1.txt', $result['remote_path']);
        $this->assertCount(1, $this->fake->puts, 'the file went out; nobody should be told otherwise');
        $this->assertSame(1, $this->fake->disconnects);
    }

    public function testAFailureToCloseTheConnectionDoesNotHideTheRealError(): void
    {
        $this->fake->disconnectThrows = true;
        $this->fake->putOk = false;
        $this->fake->lastError = 'Permission denied';

        $refusal = $this->refusal(
            fn () => $this->sftpService()->uploadTestFile('NA02629_ALL_T702_M_20261031_1_1.txt', 'x', $this->connector()),
            'A failed write was reported as success.'
        );

        $this->assertStringContainsString('SFTP upload failed: Permission denied', $refusal->getMessage());
    }

    public function testAFailedWriteReportsTheServersReason(): void
    {
        $this->fake->putOk = false;
        $this->fake->lastError = 'Permission denied';

        $refusal = $this->refusal(
            fn () => $this->sftpService()->uploadTestFile('NA02629_ALL_T702_M_20261031_1_1.txt', 'x', $this->connector()),
            'A failed write was reported as success.'
        );
        $this->assertStringContainsString('SFTP upload failed: Permission denied', $refusal->getMessage());
        $this->assertSame(1, $this->fake->disconnects);
    }

    // ---- the whole flow ----------------------------------------------------

    public function testUploadSendsTheSameFileTheDownloadGivesAndMarksTheBatchAwaitingLoadReport(): void
    {
        $batch = $this->seedBatch('Monthly');

        $result = $this->uploadService()->upload((int) $batch['id'], $this->userId, $this->connector());

        $expected = (new CplUatFileService())->build($batch, 'NA02629', 'Solid Desert Cash Loan');
        $this->assertSame('NA02629_ALL_T702_M_20191130_1_1.txt', $result['filename']);
        $this->assertSame(['In/NA02629_ALL_T702_M_20191130_1_1.txt' => $expected['content']], $this->fake->puts);
        $this->assertSame(5, $result['record_count']);
        $this->assertTrue($result['recorded']);

        $after = $this->reload((int) $batch['id']);
        $this->assertSame('Awaiting Load Report', $after['submission_outcome'], 'sent is not accepted: only a person records Creditinfo\'s answer');
        $this->assertStringStartsWith(CplUatUploadService::NOTE_PREFIX, $after['submission_notes']);
        $this->assertStringContainsString('NA02629_ALL_T702_M_20191130_1_1.txt', $after['submission_notes']);
        $this->assertSame($this->userId, (int) $after['submission_recorded_by']);
        $this->assertNotEmpty($after['submission_recorded_at']);
        $this->assertSame('Ready for Review', $after['status'], 'a test batch is never promoted to Submitted');
    }

    public function testADailyBatchIsUploadedUnderItsDailyName(): void
    {
        $batch = $this->seedBatch('Daily');

        $result = $this->uploadService()->upload((int) $batch['id'], $this->userId, $this->connector());

        $this->assertSame('NA02629_ALL_T702_D_20191129_1_1.txt', $result['filename']);
        $this->assertSame(['In/NA02629_ALL_T702_D_20191129_1_1.txt'], array_keys($this->fake->puts));
        $this->assertSame(2, $result['record_count']);
    }

    /** @dataProvider tamperedBatches */
    public function testAnUploadThatBreaksARuleIsRefusedBeforeConnectingAndTheBatchStaysUntouched(callable $tamper, string $messagePart): void
    {
        $batch = $this->seedBatch('Monthly');
        $tamper((int) $batch['id']);
        $before = $this->reload((int) $batch['id']);

        $refusal = $this->refusal(
            fn () => $this->uploadService()->upload((int) $batch['id'], $this->userId, $this->connector()),
            'The upload went ahead although it breaks a rule (' . $messagePart . ').'
        );
        $this->assertStringContainsString($messagePart, $refusal->getMessage());

        $this->assertSame([], $this->connects, 'no connection may be opened');
        $this->assertSame([], $this->fake->puts);
        $after = $this->reload((int) $batch['id']);
        $this->assertNull($after['submission_outcome']);
        $this->assertSame($before['submission_notes'], $after['submission_notes']);
    }

    public function tamperedBatches(): array
    {
        $changeRecords = static function (int $id, callable $edit): void {
            $batches = new CplBatch();
            $records = json_decode($batches->find($id)['test_records'], true);
            $records = $edit($records);
            $batches->updateRecord($id, ['test_records' => json_encode($records)]);
        };

        return [
            'a real-looking National ID in a record' => [static function (int $id) use ($changeRecords): void {
                $changeRecords($id, static function (array $r): array {
                    $r[1]['fields']['na_id'] = '80010150090';
                    return $r;
                });
            }, 'does not use one of the National IDs Creditinfo approved'],
            'an account number that is not labelled as test data' => [static function (int $id) use ($changeRecords): void {
                $changeRecords($id, static function (array $r): array {
                    $r[0]['fields']['account_no'] = 'LN-2026-0001';
                    return $r;
                });
            }, 'not labelled as test data'],
            'a name that is not synthetic test data (IDs and labels still fine)' => [static function (int $id) use ($changeRecords): void {
                $changeRecords($id, static function (array $r): array {
                    $r[2]['fields']['surname'] = 'MUELLER';
                    return $r;
                });
            }, 'Record 3 is not the standard synthetic test record'],
            'blocking validation errors' => [static function (int $id): void {
                (new CplBatch())->updateRecord($id, ['blocking_error_count' => 2]);
            }, 'blocking validation errors'],
            'no records' => [static function (int $id): void {
                (new CplBatch())->updateRecord($id, ['test_records' => '[]']);
            }, 'no records'],
        ];
    }

    public function testABatchThatIsNotASignOffTestIsNeverUploaded(): void
    {
        $realBatch = $this->seedBatch('Monthly', ['is_uat_test' => 0]);

        $refusal = $this->refusal(
            fn () => $this->uploadService()->upload((int) $realBatch['id'], $this->userId, $this->connector()),
            'A non-test batch was uploaded.'
        );
        $this->assertSame('Test batch not found.', $refusal->getMessage());
        $this->assertSame([], $this->connects);

        $refusal = $this->refusal(
            fn () => $this->uploadService()->upload(999999999, $this->userId, $this->connector()),
            'A missing batch was uploaded.'
        );
        $this->assertSame('Test batch not found.', $refusal->getMessage());
        $this->assertSame([], $this->connects);
    }

    public function testWhenTheConnectionIsNotReadyNothingIsSentAndTheBatchStaysUntouched(): void
    {
        $batch = $this->seedBatch('Monthly');

        $refusal = $this->refusal(
            fn () => $this->uploadService([], ['sftp_enabled' => 'off'])->upload((int) $batch['id'], $this->userId, $this->connector()),
            'Uploaded with the SFTP connection switched off.'
        );
        $this->assertStringContainsString('Public Defaults Settings', $refusal->getMessage());

        $this->assertSame([], $this->connects);
        $this->assertNull($this->reload((int) $batch['id'])['submission_outcome']);
    }

    /** @dataProvider failingConnections */
    public function testAFailedUploadNeverMarksTheBatchAsSent(callable $breakIt, string $messagePart): void
    {
        $batch = $this->seedBatch('Monthly');
        $breakIt($this->fake);

        $refusal = $this->refusal(
            fn () => $this->uploadService()->upload((int) $batch['id'], $this->userId, $this->connector()),
            'A failed upload was treated as sent.'
        );
        $this->assertStringContainsString($messagePart, $refusal->getMessage(), 'it failed for the reason the case sets up');
        $this->assertCount(1, $this->connects, 'it got as far as the connection');

        $after = $this->reload((int) $batch['id']);
        $this->assertNull($after['submission_outcome']);
        $this->assertNull($after['submission_notes']);
        $this->assertNull($after['submission_recorded_at']);
    }

    public function failingConnections(): array
    {
        return [
            'wrong password' => [static function (CplUatUploadFakeSftp $f): void {
                $f->loginOk = false;
            }, 'login failed'],
            'server unreachable' => [static function (CplUatUploadFakeSftp $f): void {
                $f->loginThrows = new \RuntimeException('Connection refused');
            }, 'Could not connect to sftp.test.invalid:22'],
            'write refused' => [static function (CplUatUploadFakeSftp $f): void {
                $f->putOk = false;
                $f->lastError = 'Failure';
            }, 'SFTP upload failed: Failure'],
        ];
    }

    public function testAConnectionLostWhileSendingMarksTheBatchUnclearAndKeepsAnEarlierResult(): void
    {
        $batch = $this->seedBatch('Monthly', ['submission_outcome' => 'Rejected', 'submission_notes' => 'Rejected: ID 123 not found', 'submission_recorded_at' => date('Y-m-d H:i:s', time() - 3600)]);
        $this->fake->putThrows = new \RuntimeException('Timed out waiting for SFTP packet response');

        $refusal = $this->refusal(
            fn () => $this->uploadService()->upload((int) $batch['id'], $this->userId, $this->connector()),
            'A lost connection was treated as a clean failure.'
        );

        $this->assertInstanceOf(CplSftpUncertainException::class, $refusal);
        $after = $this->reload((int) $batch['id']);
        $this->assertSame('Awaiting Load Report', $after['submission_outcome']);
        $this->assertStringStartsWith(CplUatUploadService::UNCLEAR_PREFIX, $after['submission_notes']);
        $this->assertStringContainsString('Ask Creditinfo before sending it again', $after['submission_notes']);
        $this->assertStringContainsString('Earlier result: Rejected (Rejected: ID 123 not found).', $after['submission_notes']);

        $d = $this->uploadService()->describe($after);
        $this->assertSame($after['submission_notes'], $d['unclear_note']);
        $this->assertNull($d['uploaded_at'], 'an unclear attempt is not shown as an upload');
        $this->assertNull($d['hold'], 'and it does not start the resend window');
        $this->assertTrue($d['can_upload'], 'the person may still decide to send again, with the warning');
    }

    public function testAConnectionLostWhileSendingAChosenFileMarksItsBatchUnclearToo(): void
    {
        $file = $this->chosenFile('Daily');
        $this->fake->putThrows = new \RuntimeException('Connection closed');

        $refusal = $this->refusal(
            fn () => $this->uploadService()->uploadFile($file['filename'], $file['content'], $this->userId, $this->connector()),
            'A lost connection was treated as a clean failure.'
        );

        $this->assertInstanceOf(CplSftpUncertainException::class, $refusal);
        $this->assertSame(1, $this->countBatchesDated(self::CHOSEN_DAILY_DATE), 'the batch exists so that the page can say so');
        $batch = (new CplBatch())->findByTypeAndMonth('Daily', self::CHOSEN_DAILY_DATE, true);
        $this->assertStringStartsWith(CplUatUploadService::UNCLEAR_PREFIX, $batch['submission_notes']);
    }

    public function testMarkingTheBatchUnclearNeverHidesTheOriginalProblem(): void
    {
        $batch = $this->seedBatch('Monthly');
        $this->fake->putThrows = new \RuntimeException('Timed out');

        $refusal = $this->refusal(
            fn () => $this->uploadService([], [], new CplUatUploadThrowingBatch())->upload((int) $batch['id'], $this->userId, $this->connector()),
            'The original problem was lost.'
        );

        $this->assertInstanceOf(CplSftpUncertainException::class, $refusal, 'the caller still hears that the send may have worked');
        $this->assertStringContainsString('could not be marked', (string) file_get_contents($this->errorLogFile));
    }

    public function testAnEarlierRejectionReasonSurvivesSeveralReUploads(): void
    {
        $batch = $this->seedBatch('Monthly', ['submission_outcome' => 'Rejected', 'submission_notes' => 'Rejected: ID 123 not found', 'submission_recorded_at' => date('Y-m-d H:i:s', time() - 3600)]);
        $service = $this->uploadService();
        $service->upload((int) $batch['id'], $this->userId, $this->connector());
        $this->makeTheLastUploadOlder((int) $batch['id'], 600);
        $service->upload((int) $batch['id'], $this->userId, $this->connector());

        $notes = $this->reload((int) $batch['id'])['submission_notes'];

        $this->assertStringContainsString('Earlier result: Rejected (Rejected: ID 123 not found).', $notes, 'still there after the second upload');
        $this->assertStringNotContainsString('Earlier result: Awaiting Load Report', $notes);
        $this->assertSame(1, substr_count($notes, 'Earlier result:'));
    }

    public function testAnEarlierNoteWithAccentedLettersIsCutByCharactersNotBytes(): void
    {
        $note = 'a' . str_repeat('é', 400);   // 2-byte letters behind a 1-byte one: byte 300 falls inside a letter
        $batch = $this->seedBatch('Monthly', ['submission_outcome' => 'Rejected', 'submission_notes' => $note, 'submission_recorded_at' => date('Y-m-d H:i:s', time() - 3600)]);

        $result = $this->uploadService()->upload((int) $batch['id'], $this->userId, $this->connector());

        $this->assertTrue($result['recorded'], 'broken UTF-8 would have made the database refuse the note');
        $notes = $this->reload((int) $batch['id'])['submission_notes'];
        $this->assertTrue(mb_check_encoding($notes, 'UTF-8'));
        $this->assertStringContainsString('(a' . str_repeat('é', 299) . ')', $notes);
    }

    public function testAConnectionThatBreaksWhileSendingIsReportedAsUncertainNotAsNothingSent(): void
    {
        $this->fake->putThrows = new \RuntimeException('Timed out waiting for SFTP packet response');

        $refusal = $this->refusal(
            fn () => $this->sftpService()->uploadTestFile('NA02629_ALL_T702_M_20261031_1_1.txt', 'x', $this->connector()),
            'A connection lost while sending was reported as a plain failure.'
        );

        $this->assertInstanceOf(CplSftpUncertainException::class, $refusal, 'callers can tell it from a failure where nothing was sent');
        $this->assertStringContainsString('Timed out waiting for SFTP packet response', $refusal->getMessage());
        $this->assertStringContainsString('Check with Creditinfo before sending it again', $refusal->getMessage());
        $this->assertSame(1, $this->fake->disconnects);
    }

    public function testAFailureWhereNothingWasSentIsNotMarkedUncertain(): void
    {
        foreach ([
            'login refused' => static function (CplUatUploadFakeSftp $f): void { $f->loginOk = false; },
            'unreachable' => static function (CplUatUploadFakeSftp $f): void { $f->loginThrows = new \RuntimeException('Connection refused'); },
            'write refused' => static function (CplUatUploadFakeSftp $f): void { $f->putOk = false; },
        ] as $label => $breakIt) {
            $this->fake = new CplUatUploadFakeSftp();
            $breakIt($this->fake);
            $refusal = $this->refusal(
                fn () => $this->sftpService()->uploadTestFile('NA02629_ALL_T702_M_20261031_1_1.txt', 'x', $this->connector()),
                'A failure was swallowed (' . $label . ').'
            );
            $this->assertNotInstanceOf(CplSftpUncertainException::class, $refusal, $label);
        }
    }

    public function testUploadingAgainSendsAgainAndRefreshesTheNote(): void
    {
        $batch = $this->seedBatch('Monthly');
        $service = $this->uploadService();

        $service->upload((int) $batch['id'], $this->userId, $this->connector());
        $this->makeTheLastUploadOlder((int) $batch['id'], 600);
        $second = $service->upload((int) $batch['id'], $this->userId, $this->connector());

        $this->assertCount(2, $this->connects, 'a deliberate second upload goes through');
        $notes = $this->reload((int) $batch['id'])['submission_notes'];
        $this->assertStringContainsString('NA02629_ALL_T702_M_20191130_1_1.txt', $notes);
        $this->assertStringContainsString('Earlier result: Awaiting Load Report.', $notes, 'the earlier upload is not lost, and its long note is not copied');
        $this->assertSame(1, substr_count($notes, CplUatUploadService::NOTE_PREFIX), 'one upload note, not a growing pile');
        $this->assertTrue($second['recorded']);
    }

    /** Moves the recorded time of the last upload back, as if it had happened $seconds ago. */
    private function makeTheLastUploadOlder(int $batchId, int $seconds): void
    {
        (new CplBatch())->updateRecord($batchId, ['submission_recorded_at' => date('Y-m-d H:i:s', time() - $seconds)]);
    }

    public function testASecondUploadRightAfterTheFirstIsRefusedWhateverTheBrowserDid(): void
    {
        $batch = $this->seedBatch('Monthly');
        $service = $this->uploadService();
        $service->upload((int) $batch['id'], $this->userId, $this->connector());

        $refusal = $this->refusal(
            fn () => $service->upload((int) $batch['id'], $this->userId, $this->connector()),
            'A second upload within moments was sent.'
        );

        $this->assertStringContainsString('uploaded a moment ago', $refusal->getMessage());
        $this->assertStringContainsString('Wait a couple of minutes', $refusal->getMessage());
        $this->assertCount(1, $this->connects, 'only the first one connected');
        $this->assertCount(1, $this->fake->puts);
    }

    public function testTheResendWindowEndsAfterAFewMinutes(): void
    {
        $batch = $this->seedBatch('Monthly');
        $service = $this->uploadService();
        $service->upload((int) $batch['id'], $this->userId, $this->connector());

        $this->makeTheLastUploadOlder((int) $batch['id'], CplUatUploadService::RESEND_WINDOW_SECONDS - 5);
        $this->refusal(fn () => $service->upload((int) $batch['id'], $this->userId, $this->connector()), 'Sent again inside the window.');

        $this->makeTheLastUploadOlder((int) $batch['id'], CplUatUploadService::RESEND_WINDOW_SECONDS + 5);
        $service->upload((int) $batch['id'], $this->userId, $this->connector());

        $this->assertCount(2, $this->connects);
    }

    public function testAnAcceptedFileIsNeverSentAgainAndItsResultSurvives(): void
    {
        $batch = $this->seedBatch('Monthly', ['submission_outcome' => 'Accepted', 'submission_notes' => 'Load report ref 123', 'submission_recorded_at' => date('Y-m-d H:i:s', time() - 3600)]);

        $refusal = $this->refusal(
            fn () => $this->uploadService()->upload((int) $batch['id'], $this->userId, $this->connector()),
            'A file Creditinfo already accepted was sent again.'
        );

        $this->assertStringContainsString('already accepted', $refusal->getMessage());
        $this->assertSame([], $this->connects);
        $after = $this->reload((int) $batch['id']);
        $this->assertSame('Accepted', $after['submission_outcome']);
        $this->assertSame('Load report ref 123', $after['submission_notes']);

        $d = $this->uploadService()->describe($after);
        $this->assertFalse($d['can_upload']);
        $this->assertStringContainsString('already accepted', $d['problem']);
        $this->assertSame('accepted', $d['hold'], 'a deliberate wait, not an error');
        $this->assertSame('Accepted', $d['recorded_outcome']);
    }

    public function testAChosenFileForAnAcceptedBatchIsNotSentAgainEither(): void
    {
        $file = $this->chosenFile('Daily');
        $existing = (new CplUatBatchService())->generate('Daily', self::CHOSEN_DAILY_DATE, null)['id'];
        (new CplBatch())->updateRecord($existing, ['submission_outcome' => 'Accepted', 'submission_notes' => 'Load report ref 9', 'submission_recorded_at' => date('Y-m-d H:i:s', time() - 3600)]);

        $refusal = $this->refusal(
            fn () => $this->uploadService()->uploadFile($file['filename'], $file['content'], $this->userId, $this->connector()),
            'An accepted file was sent again from the dashboard.'
        );

        $this->assertStringContainsString('already accepted', $refusal->getMessage());
        $this->assertSame([], $this->connects);
        $this->assertSame('Accepted', $this->reload($existing)['submission_outcome']);
    }

    public function testASecondChosenUploadRightAfterTheFirstIsRefused(): void
    {
        $file = $this->chosenFile('Monthly');
        $service = $this->uploadService();
        $service->uploadFile($file['filename'], $file['content'], $this->userId, $this->connector());

        $refusal = $this->refusal(
            fn () => $service->uploadFile($file['filename'], $file['content'], $this->userId, $this->connector()),
            'A second chosen upload within moments was sent.'
        );

        $this->assertStringContainsString('uploaded a moment ago', $refusal->getMessage());
        $this->assertCount(1, $this->connects);
    }

    public function testARejectedFileCanBeSentAgainAndTheEarlierResultIsKeptInTheNote(): void
    {
        $batch = $this->seedBatch('Monthly', ['submission_outcome' => 'Rejected', 'submission_notes' => 'Rejected: ID 123 not found', 'submission_recorded_at' => date('Y-m-d H:i:s', time() - 3600)]);

        $this->uploadService()->upload((int) $batch['id'], $this->userId, $this->connector());

        $after = $this->reload((int) $batch['id']);
        $this->assertSame('Awaiting Load Report', $after['submission_outcome']);
        $this->assertStringStartsWith(CplUatUploadService::NOTE_PREFIX, $after['submission_notes']);
        $this->assertStringContainsString('Earlier result: Rejected (Rejected: ID 123 not found).', $after['submission_notes']);
    }

    // ---- dates and clashes -------------------------------------------------

    public function testATestFileDatedInTheFutureIsRefused(): void
    {
        $batch = $this->seedBatch('Monthly', [], self::FUTURE_MONTHLY_END);

        $refusal = $this->refusal(
            fn () => $this->uploadService()->upload((int) $batch['id'], $this->userId, $this->connector()),
            'A future-dated test file was uploaded.'
        );

        $this->assertStringContainsString('in the future', $refusal->getMessage());
        $this->assertSame([], $this->connects);
        $d = $this->uploadService()->describe($batch);
        $this->assertFalse($d['can_upload']);
        $this->assertStringContainsString('in the future', $d['problem']);
    }

    public function testAMonthlyTestFileThatIsNotDatedAMonthEndIsRefused(): void
    {
        $batch = $this->seedBatch('Monthly', [], '2019-11-29');

        $refusal = $this->refusal(
            fn () => $this->uploadService()->upload((int) $batch['id'], $this->userId, $this->connector()),
            'A monthly file not dated a month end was uploaded.'
        );

        $this->assertStringContainsString('last day of a month', $refusal->getMessage());
        $this->assertSame([], $this->connects);
    }

    /** A real monthly batch, as the monthly CPL screen creates them. */
    private function seedRealMonthlyBatch(string $status): int
    {
        return (new CplBatch())->create(['batch_type' => 'Monthly', 'is_uat_test' => 0, 'month_end' => self::MONTHLY_END, 'status' => $status]);
    }

    /** @dataProvider realStatusesThatClash */
    public function testATestFileIsNotSentOverARealMonthlyFileThatHasTheSameName(string $status): void
    {
        $this->seedRealMonthlyBatch($status);
        $batch = $this->seedBatch('Monthly');

        $refusal = $this->refusal(
            fn () => $this->uploadService(['submission_environment' => 'uat'])->upload((int) $batch['id'], $this->userId, $this->connector()),
            'A test file was sent over a real monthly file.'
        );

        $this->assertStringContainsString('real CPL monthly batch', $refusal->getMessage());
        $this->assertStringContainsString('would overwrite it', $refusal->getMessage());
        $this->assertSame([], $this->connects);
        $this->assertNull($this->reload((int) $batch['id'])['submission_outcome']);
    }

    public function realStatusesThatClash(): array
    {
        return ['approved' => ['Approved'], 'submitted' => ['Submitted']];
    }

    public function testThePageExplainsAClashWithARealMonthlyFileInsteadOfOfferingTheButton(): void
    {
        $this->seedRealMonthlyBatch('Submitted');
        $batch = $this->seedBatch('Monthly');

        $d = $this->uploadService(['submission_environment' => 'uat'])->describe($batch);

        $this->assertFalse($d['can_upload']);
        $this->assertStringContainsString('real CPL monthly batch', $d['problem']);
        $this->assertNull($d['hold'], 'a clash is a real problem, not a deliberate wait');

        $production = $this->uploadService(['submission_environment' => 'production'])->describe($batch);
        $this->assertTrue($production['can_upload'], 'in Production the real file has another name, so there is no clash');
    }

    public function testTheSameClashIsCaughtForAFileChosenFromTheComputer(): void
    {
        (new CplBatch())->create(['batch_type' => 'Monthly', 'is_uat_test' => 0, 'month_end' => self::CHOSEN_MONTHLY_END, 'status' => 'Approved']);
        $file = $this->chosenFile('Monthly');

        $refusal = $this->refusal(
            fn () => $this->uploadService(['submission_environment' => 'uat'])->uploadFile($file['filename'], $file['content'], $this->userId, $this->connector()),
            'A chosen test file was sent over a real monthly file.'
        );

        $this->assertStringContainsString('real CPL monthly batch', $refusal->getMessage());
        $this->assertSame([], $this->connects);
    }

    public function testNoClashWhenTheLiveFlowUsesLiveFileNamesOrTheRealBatchIsNotSentYet(): void
    {
        $realApproved = $this->seedRealMonthlyBatch('Approved');
        $batch = $this->seedBatch('Monthly');

        // Production environment: real files are L702, a different name from every test file.
        $this->uploadService(['submission_environment' => 'production'])->upload((int) $batch['id'], $this->userId, $this->connector());
        $this->assertCount(1, $this->connects);

        // Not production, but the real batch is only a draft: nothing of it has been sent.
        (new CplBatch())->updateRecord($realApproved, ['status' => 'Draft']);
        $this->makeTheLastUploadOlder((int) $batch['id'], 600);
        $this->uploadService(['submission_environment' => 'uat'])->upload((int) $batch['id'], $this->userId, $this->connector());
        $this->assertCount(2, $this->connects);
    }

    public function testADailyTestFileHasNoRealCounterpartToClashWith(): void
    {
        $this->seedRealMonthlyBatch('Approved');
        $batch = $this->seedBatch('Daily', [], self::MONTHLY_END);   // the very date the real monthly batch has

        $this->uploadService(['submission_environment' => 'uat'])->upload((int) $batch['id'], $this->userId, $this->connector());

        $this->assertCount(1, $this->connects, 'a daily file has a different name from any monthly file');
    }

    public function testAResultRecordedByHandDoesNotStartTheResendWindow(): void
    {
        $batch = $this->seedBatch('Monthly', [
            'submission_outcome' => 'Awaiting Load Report',
            'submission_notes' => 'Sent by hand with WinSCP',
            'submission_recorded_at' => date('Y-m-d H:i:s'),
        ]);

        $this->uploadService()->upload((int) $batch['id'], $this->userId, $this->connector());

        $this->assertCount(1, $this->connects, 'the window only follows an upload made by this app');
        $notes = $this->reload((int) $batch['id'])['submission_notes'];
        $this->assertStringContainsString('Earlier result: Awaiting Load Report (Sent by hand with WinSCP).', $notes);
    }

    public function testIfRecordingFailsAfterTheSendTheUploadIsStillReported(): void
    {
        $batch = $this->seedBatch('Monthly');
        $failingBatches = new CplUatUploadThrowingBatch();

        $result = $this->uploadService([], [], $failingBatches)->upload((int) $batch['id'], $this->userId, $this->connector());

        $this->assertCount(1, $this->fake->puts, 'the file did go out');
        $this->assertFalse($result['recorded'], 'and the caller is told it could not be written down');
        $this->assertNull($this->reload((int) $batch['id'])['submission_outcome']);
        $this->assertStringContainsString('could not be recorded on batch #' . $batch['id'], (string) file_get_contents($this->errorLogFile));
    }

    // ---- what the page shows -----------------------------------------------

    public function testDescribeOffersTheUploadWhenEverythingIsInOrder(): void
    {
        $batch = $this->seedBatch('Monthly');

        $d = $this->uploadService()->describe($batch);

        $this->assertTrue($d['can_upload']);
        $this->assertNull($d['problem']);
        $this->assertSame('NA02629_ALL_T702_M_20191130_1_1.txt', $d['filename']);
        $this->assertSame(5, $d['record_count']);
        $this->assertSame('sftp.test.invalid / In', $d['destination']);
        $this->assertNull($d['uploaded_at']);
        $this->assertSame('NA02629', $d['supplier_ref']);
    }

    public function testDescribeExplainsWhyTheButtonIsNotOffered(): void
    {
        $batch = $this->seedBatch('Monthly');

        $notReady = $this->uploadService([], ['sftp_enabled' => 'off'])->describe($batch);
        $this->assertFalse($notReady['can_upload']);
        $this->assertStringContainsString('Public Defaults Settings', $notReady['problem']);
        $this->assertSame('NA02629_ALL_T702_M_20191130_1_1.txt', $notReady['filename'], 'the file name is still shown');

        $noReference = $this->uploadService(['supplier_reference_number' => ''])->describe($batch);
        $this->assertFalse($noReference['can_upload']);
        $this->assertStringContainsString('Supplier Reference Number', $noReference['problem']);

        (new CplBatch())->updateRecord((int) $batch['id'], ['blocking_error_count' => 1]);
        $blocked = $this->uploadService()->describe($this->reload((int) $batch['id']));
        $this->assertFalse($blocked['can_upload']);
        $this->assertStringContainsString('blocking validation errors', $blocked['problem']);
    }

    public function testDescribeRemembersAnEarlierUploadAndNeverExposesCredentials(): void
    {
        $batch = $this->seedBatch('Daily');
        $service = $this->uploadService();
        $service->upload((int) $batch['id'], $this->userId, $this->connector());

        $d = $service->describe($this->reload((int) $batch['id']));

        $this->assertNotNull($d['uploaded_at']);
        $this->assertStringStartsWith(CplUatUploadService::NOTE_PREFIX, $d['uploaded_note']);
        $this->assertSame('Awaiting Load Report', $d['recorded_outcome']);
        $this->assertFalse($d['can_upload'], 'held back for a couple of minutes right after an upload');
        $this->assertStringContainsString('uploaded a moment ago', $d['problem']);
        $this->assertSame('recent', $d['hold'], 'a deliberate wait, not an error');
        $this->assertStringNotContainsString('test-password', json_encode($d));
        $this->assertStringNotContainsString('test-user', json_encode($d));

        $this->makeTheLastUploadOlder((int) $batch['id'], CplUatUploadService::RESEND_WINDOW_SECONDS + 30);
        $later = $service->describe($this->reload((int) $batch['id']));
        $this->assertTrue($later['can_upload'], 'and possible again afterwards');
        $this->assertNull($later['problem']);
    }

    // ---- a file chosen from the computer -------------------------------------------------

    /** A standard test file as this app builds it, for a past date (a chosen file must not be dated in the future). */
    private function chosenFile(string $type): array
    {
        $date = $type === 'Daily' ? self::CHOSEN_DAILY_DATE : self::CHOSEN_MONTHLY_END;
        $data = new CplUatTestDataService();
        $fieldMaps = $type === 'Daily' ? $data->dailyTestRecords($date) : $data->monthlyTestRecords($date);
        $batch = ['batch_type' => $type, 'month_end' => $date, 'test_records' => json_encode(array_map(static fn (array $f): array => ['fields' => $f], $fieldMaps))];

        return (new CplUatFileService())->build($batch, 'NA02629', 'Solid Desert Cash Loan');
    }

    private function countBatchesDated(string $date): int
    {
        return (int) Database::connection()->query("SELECT COUNT(*) FROM cpl_batches WHERE is_uat_test = 1 AND month_end = '" . $date . "'")->fetchColumn();
    }

    public function testAChosenStandardFileIsSentExactlyAsItIsAndRecordedOnAFreshBatch(): void
    {
        $file = $this->chosenFile('Monthly');

        $result = $this->uploadService()->uploadFile($file['filename'], $file['content'], $this->userId, $this->connector());

        $this->assertSame(['In/NA02629_ALL_T702_M_20201231_1_1.txt' => $file['content']], $this->fake->puts, 'the very bytes that were chosen');
        $this->assertTrue($result['recorded']);
        $this->assertSame(5, $result['record_count']);
        $batch = $this->reload((int) $result['batch_id']);
        $this->assertSame('Monthly', $batch['batch_type']);
        $this->assertSame(self::CHOSEN_MONTHLY_END, $batch['month_end']);
        $this->assertSame(1, (int) $batch['is_uat_test']);
        $this->assertSame(5, (int) $batch['total_records']);
        $this->assertSame('Awaiting Load Report', $batch['submission_outcome'], 'sent is not accepted');
        $this->assertStringStartsWith(CplUatUploadService::NOTE_PREFIX, $batch['submission_notes']);
        $this->assertStringContainsString('NA02629_ALL_T702_M_20201231_1_1.txt', $batch['submission_notes']);
        $this->assertStringContainsString('file chosen from the computer', $batch['submission_notes']);
        $this->assertSame($this->userId, (int) $batch['submission_recorded_by']);
    }

    public function testAChosenDailyFileIsSentUnderItsOwnNameToo(): void
    {
        $file = $this->chosenFile('Daily');

        $result = $this->uploadService()->uploadFile($file['filename'], $file['content'], $this->userId, $this->connector());

        $this->assertSame(['In/NA02629_ALL_T702_D_20201230_1_1.txt' => $file['content']], $this->fake->puts);
        $this->assertSame('Daily', $this->reload((int) $result['batch_id'])['batch_type']);
        $this->assertSame(2, $result['record_count']);
    }

    public function testAnExistingBatchIsUsedAsItIsNotRegenerated(): void
    {
        $file = $this->chosenFile('Daily');
        $existing = (new CplUatBatchService())->generate('Daily', self::CHOSEN_DAILY_DATE, null)['id'];
        (new CplBatch())->updateRecord($existing, ['warning_count' => 7]);

        $result = $this->uploadService()->uploadFile($file['filename'], $file['content'], $this->userId, $this->connector());

        $this->assertSame($existing, $result['batch_id']);
        $after = $this->reload($existing);
        $this->assertSame(7, (int) $after['warning_count'], 'the batch was not regenerated');
        $this->assertSame('Awaiting Load Report', $after['submission_outcome']);
        $this->assertSame(1, $this->countBatchesDated(self::CHOSEN_DAILY_DATE));
    }

    public function testAChosenFileThatIsNotTheStandardOneIsRefusedBeforeConnectingAndLeavesNoBatch(): void
    {
        $file = $this->chosenFile('Monthly');
        $content = $file['content'];
        $content[900] = $content[900] === 'Q' ? 'R' : 'Q';   // one character inside the first record

        $refusal = $this->refusal(
            fn () => $this->uploadService()->uploadFile($file['filename'], $content, $this->userId, $this->connector()),
            'A changed file was uploaded.'
        );

        $this->assertStringContainsString('Record 1 is not the standard test record', $refusal->getMessage());
        $this->assertSame([], $this->connects, 'no connection may be opened');
        $this->assertSame([], $this->fake->puts);
        $this->assertSame(0, $this->countBatchesDated(self::CHOSEN_MONTHLY_END), 'and no batch is created');
    }

    public function testAChosenFileIsRefusedWhenItsReferenceIsNotThisLendersOrNoneIsSet(): void
    {
        $file = $this->chosenFile('Monthly');

        $other = $this->refusal(
            fn () => $this->uploadService(['supplier_reference_number' => 'NA99999'])->uploadFile($file['filename'], $file['content'], $this->userId, $this->connector()),
            'A file for another supplier reference was uploaded.'
        );
        $this->assertStringContainsString('starts with NA02629', $other->getMessage());

        $none = $this->refusal(
            fn () => $this->uploadService(['supplier_reference_number' => ''])->uploadFile($file['filename'], $file['content'], $this->userId, $this->connector()),
            'A file was uploaded with no supplier reference configured.'
        );
        $this->assertStringContainsString('Supplier Reference Number', $none->getMessage());
        $this->assertSame([], $this->connects);
        $this->assertSame(0, $this->countBatchesDated(self::CHOSEN_MONTHLY_END));
    }

    public function testAChosenFileIsRefusedWhenTheConnectionIsNotReady(): void
    {
        $file = $this->chosenFile('Monthly');

        $refusal = $this->refusal(
            fn () => $this->uploadService([], ['sftp_enabled' => 'off'])->uploadFile($file['filename'], $file['content'], $this->userId, $this->connector()),
            'A chosen file was uploaded with the SFTP connection switched off.'
        );

        $this->assertStringContainsString('Public Defaults Settings', $refusal->getMessage());
        $this->assertSame([], $this->connects);
        $this->assertSame(0, $this->countBatchesDated(self::CHOSEN_MONTHLY_END));
    }

    /** @dataProvider failingConnections */
    public function testAFailedUploadOfAChosenFileLeavesNoBatchBehind(callable $breakIt, string $messagePart): void
    {
        $file = $this->chosenFile('Monthly');
        $breakIt($this->fake);

        $refusal = $this->refusal(
            fn () => $this->uploadService()->uploadFile($file['filename'], $file['content'], $this->userId, $this->connector()),
            'A failed upload was treated as sent.'
        );

        $this->assertStringContainsString($messagePart, $refusal->getMessage(), 'it failed for the reason the case sets up');
        $this->assertCount(1, $this->connects, 'it got as far as the connection');
        $this->assertSame(0, $this->countBatchesDated(self::CHOSEN_MONTHLY_END), 'nothing is recorded for a file that did not go out');
    }

    public function testIfRecordingFailsAfterAChosenFileWentOutTheUploadIsStillReported(): void
    {
        $file = $this->chosenFile('Monthly');

        $result = $this->uploadService([], [], new CplUatUploadThrowingBatch())->uploadFile($file['filename'], $file['content'], $this->userId, $this->connector());

        $this->assertCount(1, $this->fake->puts, 'the file did go out');
        $this->assertFalse($result['recorded'], 'and the caller is told it could not be written down');
        $this->assertStringContainsString('was uploaded but could not be recorded', (string) file_get_contents($this->errorLogFile));
    }

    public function testConnectionStatusForTheDashboard(): void
    {
        $ready = $this->uploadService()->connectionStatus();
        $this->assertTrue($ready['ready']);
        $this->assertNull($ready['problem']);
        $this->assertSame('sftp.test.invalid / In', $ready['destination']);
        $this->assertStringNotContainsString('test-password', json_encode($ready));
        $this->assertStringNotContainsString('test-user', json_encode($ready));

        $notReady = $this->uploadService([], ['sftp_enabled' => 'off'])->connectionStatus();
        $this->assertFalse($notReady['ready']);
        $this->assertStringContainsString('Public Defaults Settings', $notReady['problem']);
    }

    // ---- the shared batch service -------------------------------------------------------

    public function testGenerateStoresTheStandardValidatedRecords(): void
    {
        $out = (new CplUatBatchService())->generate('Monthly', self::CHOSEN_MONTHLY_END, $this->userId);

        $batch = $this->reload($out['id']);
        $stored = array_map(static fn (array $r): array => $r['fields'], json_decode($batch['test_records'], true));
        $this->assertSame(serialize((new CplUatTestDataService())->monthlyTestRecords(self::CHOSEN_MONTHLY_END)), serialize($stored));
        $this->assertSame(5, $out['records']);
        $this->assertSame(0, $out['blocking']);
        $this->assertSame('Ready for Review', $batch['status']);
        $this->assertSame(1, (int) $batch['is_uat_test']);
        $this->assertSame($this->userId, (int) $batch['generated_by']);
    }

    public function testGenerateWithoutAUserStoresNoGeneratorAndRejectsAnUnknownType(): void
    {
        $out = (new CplUatBatchService())->generate('Daily', self::CHOSEN_DAILY_DATE, 0);
        $this->assertNull($this->reload($out['id'])['generated_by']);

        $this->expectException(\InvalidArgumentException::class);
        (new CplUatBatchService())->generate('Weekly', self::CHOSEN_DAILY_DATE, null);
    }

    public function testRegeneratingRefreshesTheRecordsButKeepsWhatWasRecordedForTheBatch(): void
    {
        $service = new CplUatBatchService();
        $first = $service->generate('Daily', self::CHOSEN_DAILY_DATE, null)['id'];
        (new CplBatch())->updateRecord($first, ['submission_outcome' => 'Accepted', 'submission_notes' => 'Load report ref 123', 'generated_at' => '2000-01-01 00:00:00']);

        $second = $service->generate('Daily', self::CHOSEN_DAILY_DATE, null)['id'];

        $this->assertSame($first, $second, 'the same date has one batch');
        $after = $this->reload($first);
        $this->assertSame('Accepted', $after['submission_outcome']);
        $this->assertSame('Load report ref 123', $after['submission_notes']);
        $this->assertNotSame('2000-01-01 00:00:00', $after['generated_at'], 'the records were regenerated');
    }

    public function testEnsureReturnsAnExistingBatchUntouchedAndCreatesAMissingOne(): void
    {
        $service = new CplUatBatchService();
        $this->assertSame(0, $this->countBatchesDated(self::CHOSEN_MONTHLY_END));

        $created = $service->ensure('Monthly', self::CHOSEN_MONTHLY_END, null);
        $this->assertSame(1, $this->countBatchesDated(self::CHOSEN_MONTHLY_END));

        (new CplBatch())->updateRecord($created, ['warning_count' => 9]);
        $again = $service->ensure('Monthly', self::CHOSEN_MONTHLY_END, null);

        $this->assertSame($created, $again);
        $this->assertSame(9, (int) $this->reload($created)['warning_count'], 'an existing batch is not regenerated');
        $this->assertSame(1, $this->countBatchesDated(self::CHOSEN_MONTHLY_END));
    }

    public function testAnOutcomeRecordedByAPersonIsNotMistakenForAnUpload(): void
    {
        $batch = $this->seedBatch('Monthly', ['submission_outcome' => 'Accepted', 'submission_notes' => 'Load report ref 123', 'submission_recorded_at' => date('Y-m-d H:i:s')]);

        $d = $this->uploadService()->describe($this->reload((int) $batch['id']));

        $this->assertNull($d['uploaded_at']);
        $this->assertNull($d['uploaded_note']);
    }
}

/** Stand-in for phpseclib's SFTP: records what would have been sent, never touches a network. */
final class CplUatUploadFakeSftp
{
    /** @var array<int, array{0:string,1:?string}> */
    public array $logins = [];
    /** @var array<string,string> */
    public array $puts = [];
    public bool $loginOk = true;
    public ?\Throwable $loginThrows = null;
    public bool $putOk = true;
    public ?\Throwable $putThrows = null;
    public string $lastError = '';
    public int $disconnects = 0;
    public bool $disconnectThrows = false;

    public function login(string $username, ?string $password = null): bool
    {
        $this->logins[] = [$username, $password];
        if ($this->loginThrows !== null) {
            throw $this->loginThrows;
        }
        return $this->loginOk;
    }

    public function put(string $path, string $data): bool
    {
        if ($this->putThrows !== null) {
            throw $this->putThrows;
        }
        if (!$this->putOk) {
            return false;
        }
        $this->puts[$path] = $data;
        return true;
    }

    public function getLastSFTPError(): string
    {
        return $this->lastError;
    }

    public function disconnect(): void
    {
        $this->disconnects++;
        if ($this->disconnectThrows) {
            throw new \RuntimeException('socket already closed');
        }
    }
}

/** CPL settings that never read the stored (possibly real) values. */
final class CplUatUploadFakeCplSetting extends CplSetting
{
    private array $values;

    public function __construct(array $values)
    {
        parent::__construct();
        $this->values = $values;
    }

    public function get(string $key, string $default = ''): string
    {
        return array_key_exists($key, $this->values) ? (string) $this->values[$key] : $default;
    }
}

/** Public Defaults SFTP settings that never read the stored (possibly real) host, user or secret. */
final class CplUatUploadFakePublicDefaultSetting extends CreditinfoPublicDefaultSetting
{
    private array $values;
    private ?string $secret;

    public function __construct(array $values, ?string $secret)
    {
        parent::__construct();
        $this->values = $values;
        $this->secret = $secret;
    }

    public function get(string $key, string $default = ''): string
    {
        if ($key === 'sftp_secret') {
            return $this->secret === null ? '' : 'stored';
        }
        return array_key_exists($key, $this->values) ? (string) $this->values[$key] : $default;
    }

    public function getDecryptedSftpSecret(): ?string
    {
        return $this->secret;
    }
}

/** A batch model whose write fails, to prove an upload that already went out is still reported. */
final class CplUatUploadThrowingBatch extends CplBatch
{
    public function updateRecord(int $id, array $data): bool
    {
        throw new \RuntimeException('database went away');
    }
}
