<?php

namespace Tests\Feature;

use App\Core\Database;
use App\Models\CplBatch;
use App\Services\CplSnapshotService;
use App\Services\LegacyCplBatchService;
use PHPUnit\Framework\TestCase;

/** A candidate file from the old loan system becomes a normal CPL batch: frozen file, Draft while accounts are blocked, never overwritten once past review. */
class LegacyCplBatchTest extends TestCase
{
    private const MONTH = '2018-03-31';

    protected function setUp(): void
    {
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
    }

    private function purge(): void
    {
        Database::connection()->prepare('DELETE FROM cpl_batches WHERE month_end = ?')->execute([self::MONTH]);
    }

    private function result(int $blocked, string $file = "HDR\r\nREC\r\nTRL"): array
    {
        return [
            'file' => $file,
            'exceptions' => $blocked > 0 ? [['loan_id' => 7, 'borrower_id' => 3, 'account_no' => 'SDL0007', 'reasons' => ['na_id: missing'], 'notes' => ['no ID number'], 'name' => 'A Test', 'ref_no' => 'R7', 'phone' => '081']] : [],
            'summary' => ['as_of' => self::MONTH, 'sent' => 5, 'with_warnings' => 2, 'blocked' => $blocked],
        ];
    }

    private function row(): array
    {
        return (new CplBatch())->findByTypeAndMonth('Monthly', self::MONTH);
    }

    public function testBlockedAccountsKeepTheBatchInDraftWithTheFileFrozenAndExceptionsStored(): void
    {
        $id = (new LegacyCplBatchService())->store($this->result(1), self::MONTH, null);
        $b = $this->row();

        $this->assertSame($id, (int) $b['id']);
        $this->assertSame('SolidFin', $b['source']);
        $this->assertSame('Draft', $b['status']);
        $this->assertSame(5, (int) $b['total_records']);
        $this->assertSame(1, (int) $b['blocking_error_count']);
        $this->assertSame(2, (int) $b['warning_count']);
        $this->assertSame("HDR\r\nREC\r\nTRL", $b['file_content']);
        $this->assertSame('SDL0007', json_decode($b['legacy_exceptions'], true)[0]['account_no']);
    }

    public function testACleanBookIsReadyForReviewAndARebuildReplacesTheSameRow(): void
    {
        $svc = new LegacyCplBatchService();
        $first = $svc->store($this->result(1), self::MONTH, null);
        $second = $svc->store($this->result(0, 'NEW'), self::MONTH, null);

        $this->assertSame($first, $second);
        $b = $this->row();
        $this->assertSame('Ready for Review', $b['status']);
        $this->assertSame('NEW', $b['file_content']);
        $this->assertSame('[]', $b['legacy_exceptions']);
    }

    public function testABatchPastReviewIsNeverRebuilt(): void
    {
        $svc = new LegacyCplBatchService();
        $id = $svc->store($this->result(0), self::MONTH, null);
        (new CplBatch())->updateRecord($id, ['status' => 'Pending Approval']);

        $this->expectException(\RuntimeException::class);
        $svc->store($this->result(0, 'CHANGED'), self::MONTH, null);
    }

    public function testABatchFromAnotherSourceIsNeverReplacedByThisOne(): void
    {
        $svc = new LegacyCplBatchService();
        $svc->store($this->result(0, 'BOOK'), self::MONTH, null);

        try {
            $svc->store($this->result(0, 'APPLICATIONS'), self::MONTH, null, 'Built from the approved online applications');
            $this->fail('the old-system batch must not be replaced');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('different source', $e->getMessage());
        }
        $this->assertSame('BOOK', $this->row()['file_content']);

        $svc->store($this->result(0, 'BOOK2'), self::MONTH, null);
        $this->assertSame('BOOK2', $this->row()['file_content'], 'the same source may refresh its own batch');
    }

    public function testTheOriginLabelIsKeptWithTheBatch(): void
    {
        (new LegacyCplBatchService())->store($this->result(0), self::MONTH, null, 'Built from the approved online applications');

        $this->assertSame('Built from the approved online applications', json_decode($this->row()['legacy_summary'], true)['origin']);
    }

    public function testAnOwnLoansBatchForTheMonthIsNeverReplaced(): void
    {
        (new CplBatch())->create(['batch_type' => 'Monthly', 'month_end' => self::MONTH, 'status' => 'Draft']);

        $this->expectException(\RuntimeException::class);
        (new LegacyCplBatchService())->store($this->result(0), self::MONTH, null);
    }

    public function testTheOwnLoansGeneratorRefusesToTouchALegacyBatch(): void
    {
        (new LegacyCplBatchService())->store($this->result(0), self::MONTH, null);

        $this->expectException(\RuntimeException::class);
        (new CplSnapshotService())->generate(self::MONTH, null);
    }
}
