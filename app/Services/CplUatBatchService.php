<?php

namespace App\Services;

use App\Models\CplBatch;

/**
 * Creates (or refreshes) the stored sign-off TEST batch for a date: the
 * standard synthetic records from CplUatTestDataService, checked against the
 * CPLv1.1 spec by CplValidationService. Used by the two "Generate" buttons
 * and by the upload of a file chosen from the computer, which needs a batch
 * to record itself on so the sign-off dashboard shows it as submitted.
 */
class CplUatBatchService
{
    private CplBatch $batches;
    private CplUatTestDataService $testData;
    private CplValidationService $validator;

    public function __construct(?CplBatch $batches = null, ?CplUatTestDataService $testData = null, ?CplValidationService $validator = null)
    {
        $this->batches = $batches ?? new CplBatch();
        $this->testData = $testData ?? new CplUatTestDataService();
        $this->validator = $validator ?? new CplValidationService();
    }

    /**
     * Generates the standard test records for $date and stores them as the
     * sign-off batch of that type and date: a new row, or the existing row
     * for that date brought up to date. Never touches the submission result
     * columns, so regenerating does not erase what was recorded for it.
     *
     * @param string $type 'Daily' or 'Monthly'
     * @param string $date the transaction date (Daily) or the month end (Monthly), Y-m-d
     * @return array{id:int, records:int, blocking:int}
     */
    public function generate(string $type, string $date, ?int $userId): array
    {
        if (!in_array($type, ['Daily', 'Monthly'], true)) {
            throw new \InvalidArgumentException('A sign-off test batch is either Daily or Monthly.');
        }

        $fieldMaps = $type === 'Daily' ? $this->testData->dailyTestRecords($date) : $this->testData->monthlyTestRecords($date);
        $testRecords = [];
        $blocking = 0;
        $warnings = 0;
        foreach ($fieldMaps as $fields) {
            $issues = $this->validator->validate($fields);
            $status = $this->validator->overallStatus($issues);
            $blocking += $status === 'Blocking Error' ? 1 : 0;
            $warnings += $status === 'Warning' ? 1 : 0;
            $testRecords[] = ['fields' => $fields, 'issues' => $issues, 'status' => $status];
        }

        $data = [
            'batch_type' => $type,
            'is_uat_test' => 1,
            'month_end' => $date,
            'status' => $blocking > 0 ? 'Draft' : 'Ready for Review',
            'total_records' => count($testRecords),
            'blocking_error_count' => $blocking,
            'warning_count' => $warnings,
            'generated_by' => $userId !== null && $userId > 0 ? $userId : null,
            'generated_at' => date('Y-m-d H:i:s'),
            'test_records' => json_encode($testRecords),
        ];

        $existing = $this->batches->findByTypeAndMonth($type, $date, true);
        if ($existing) {
            $this->batches->updateRecord((int) $existing['id'], $data);
            $id = (int) $existing['id'];
        } else {
            $id = $this->batches->create($data);
        }

        return ['id' => $id, 'records' => count($testRecords), 'blocking' => $blocking];
    }

    /**
     * The sign-off batch for this type and date, generated first if there is
     * none yet. An existing batch is returned exactly as it is.
     */
    public function ensure(string $type, string $date, ?int $userId): int
    {
        $existing = $this->batches->findByTypeAndMonth($type, $date, true);
        if ($existing) {
            return (int) $existing['id'];
        }
        return $this->generate($type, $date, $userId)['id'];
    }
}
