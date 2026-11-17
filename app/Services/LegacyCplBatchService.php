<?php

namespace App\Services;

use App\Models\CplBatch;

/**
 * Stores the result of LegacyCplExtract::monthly() as a normal Monthly CPL batch (source SolidFin), so it goes through the same review, maker-checker approval
 * and SFTP submission screens as any other batch. The file is frozen at once; accounts that cannot be sent keep the batch in Draft so nothing incomplete
 * can be submitted for approval.
 */
final class LegacyCplBatchService
{
    private const REGENERATABLE = ['Draft', 'Ready for Review'];

    private CplBatch $batches;

    public function __construct(?CplBatch $batches = null)
    {
        $this->batches = $batches ?? new CplBatch();
    }

    /**
     * @param array<string,mixed> $result LegacyCplExtract::monthly() output
     * @throws \RuntimeException when this month already has a batch that must not be overwritten
     */
    public function store(array $result, string $monthEnd, ?int $userId, ?string $origin = null): int
    {
        $existing = $this->batches->findByTypeAndMonth('Monthly', $monthEnd);
        if ($existing && ($existing['source'] ?? 'Local') !== 'SolidFin') {
            throw new \RuntimeException('This month already has a batch built from this system\'s own loans; it will not be replaced.');
        }
        if ($existing) {
            $existingOrigin = json_decode((string) ($existing['legacy_summary'] ?? ''), true)['origin'] ?? null;
            if ($existingOrigin !== $origin) {
                throw new \RuntimeException('This month already has a batch built from a different source (' . ($existingOrigin ?? 'the old loan system') . '); it will not be replaced by this one.');
            }
        }
        if ($existing && !in_array($existing['status'], self::REGENERATABLE, true)) {
            throw new \RuntimeException('This month\'s batch is already ' . $existing['status'] . ' and cannot be rebuilt.');
        }

        $s = $result['summary'];
        if ($origin !== null) {
            $s['origin'] = $origin; // shown on the batch screen instead of the default "old loan system" label
        }
        $data = [
            'source' => 'SolidFin',
            'status' => (int) $s['blocked'] > 0 ? 'Draft' : 'Ready for Review',
            'total_records' => (int) $s['sent'],
            'blocking_error_count' => (int) $s['blocked'],
            'warning_count' => (int) $s['with_warnings'],
            'file_content' => $result['file'],
            'legacy_exceptions' => json_encode($result['exceptions']),
            'legacy_summary' => json_encode($s),
            'generated_by' => $userId,
            'generated_at' => date('Y-m-d H:i:s'),
        ];
        if ($existing) {
            $this->batches->updateRecord((int) $existing['id'], $data);
            return (int) $existing['id'];
        }
        return $this->batches->create($data + ['batch_type' => 'Monthly', 'month_end' => $monthEnd]);
    }
}
