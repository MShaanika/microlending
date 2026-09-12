<?php

namespace App\Services;

use App\Models\CplBatch;
use App\Models\CplMonthlySnapshot;

/**
 * Orchestrates the "Month closes -> snapshot -> generate candidate batch ->
 * automatic validation" part of item 15's automation diagram. Produces (or
 * regenerates, while still Draft/Ready for Review) one cpl_monthly_snapshots
 * row per eligible loan for a cpl_batches row, each already validated via
 * CplValidationService -- staff never hand-build a file; they review this
 * batch's worklist and approve it (see CplBatchController).
 *
 * Snapshots are only ever regenerated while the batch has not yet been
 * submitted for approval -- once Pending Approval/Approved/Rejected/Submitted,
 * this refuses to touch them, matching item 10's "do not recalculate an old
 * submitted month from today's loan balance".
 */
class CplSnapshotService
{
    private const REGENERATABLE_STATUSES = ['Draft', 'Ready for Review'];

    private CplBatch $batches;
    private CplMonthlySnapshot $snapshots;
    private CplExporter $exporter;
    private CplValidationService $validator;

    public function __construct()
    {
        $this->batches = new CplBatch();
        $this->snapshots = new CplMonthlySnapshot();
        $this->exporter = new CplExporter();
        $this->validator = new CplValidationService();
    }

    /**
     * Finds (or creates) the Monthly batch for $monthEnd and (re)builds its
     * snapshots from current source data. Returns the batch id.
     *
     * @throws \RuntimeException if the batch has already moved past the
     *         regeneratable statuses -- callers must not attempt to
     *         re-snapshot an approved/submitted month.
     */
    public function generate(string $monthEnd, ?int $userId): int
    {
        $existing = $this->batches->findByTypeAndMonth('Monthly', $monthEnd);

        if ($existing && !in_array($existing['status'], self::REGENERATABLE_STATUSES, true)) {
            throw new \RuntimeException('This month\'s batch is already ' . $existing['status'] . ' and cannot be regenerated.');
        }

        $batchId = $existing['id'] ?? $this->batches->create([
            'batch_type' => 'Monthly',
            'month_end' => $monthEnd,
            'status' => 'Draft',
            'generated_by' => $userId,
            'generated_at' => date('Y-m-d H:i:s'),
        ]);

        if ($existing) {
            $this->snapshots->deleteForBatch($batchId);
            $this->batches->updateRecord($batchId, ['generated_by' => $userId, 'generated_at' => date('Y-m-d H:i:s')]);
        }

        $loans = $this->exporter->eligibleLoans($monthEnd);

        $blockingCount = 0;
        $warningCount = 0;

        foreach ($loans as $row) {
            // persistStatus=false: a batch can be regenerated multiple times
            // before approval, and re-snapshotting must never permanently
            // mark a status code as "already sent" until the batch is
            // actually approved (see approveAndBuildFile() below).
            $fields = $this->exporter->buildFields($row, $monthEnd, false);
            $issues = $this->validator->validate($fields);
            $status = $this->validator->overallStatus($issues);

            if ($status === 'Blocking Error') {
                $blockingCount++;
            } elseif ($status === 'Warning') {
                $warningCount++;
            }

            $this->snapshots->create([
                'batch_id' => $batchId,
                'loan_id' => (int) $row['id'],
                'borrower_id' => (int) $row['borrower_id'],
                'month_end' => $monthEnd,
                'field_data' => json_encode($fields),
                'validation_status' => $status,
                'validation_messages' => json_encode($issues),
            ]);
        }

        $this->batches->updateRecord($batchId, [
            'total_records' => count($loans),
            'blocking_error_count' => $blockingCount,
            'warning_count' => $warningCount,
            'status' => $blockingCount > 0 ? 'Draft' : 'Ready for Review',
        ]);

        return $batchId;
    }
}
