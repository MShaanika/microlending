<?php

namespace App\Models;

use App\Core\Model;

class FixedAsset extends Model
{
    public function paginated(string $search = '', string $status = '', int $limit = 100): array
    {
        $sql = "SELECT a.*, c.category_name
                FROM fixed_assets a
                JOIN asset_categories c ON c.id = a.category_id
                WHERE 1=1";
        $params = [];

        if ($search !== '') {
            $sql .= " AND (a.asset_no LIKE ? OR a.asset_name LIKE ? OR a.serial_no LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }

        if ($status !== '') {
            $sql .= " AND a.status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY a.id DESC LIMIT " . (int) $limit;

        return $this->all($sql, $params);
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT a.*, c.category_name, c.asset_nature AS category_nature
             FROM fixed_assets a JOIN asset_categories c ON c.id = a.category_id
             WHERE a.id = ?",
            [$id]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('fixed_assets', $data);
    }

    public function updateFields(int $id, array $data): bool
    {
        return $this->update('fixed_assets', $data, 'id', $id);
    }

    public function insertScheduleRows(int $assetId, array $rows): void
    {
        foreach ($rows as $row) {
            $this->insert('asset_depreciation_schedules', [
                'asset_id' => $assetId,
                'period_no' => $row['period_no'],
                'period_date' => $row['period_date'],
                'opening_book_value' => $row['opening_book_value'],
                'depreciation_amount' => $row['depreciation_amount'],
                'closing_book_value' => $row['closing_book_value'],
                'status' => 'Pending',
            ]);
        }
    }

    public function schedule(int $assetId): array
    {
        return $this->all("SELECT * FROM asset_depreciation_schedules WHERE asset_id = ? ORDER BY period_no", [$assetId]);
    }

    public function nextPendingPeriod(int $assetId): ?array
    {
        return $this->one(
            "SELECT * FROM asset_depreciation_schedules WHERE asset_id = ? AND status = 'Pending' ORDER BY period_no LIMIT 1",
            [$assetId]
        );
    }

    public function markPeriodPosted(int $periodId, ?int $journalId, ?int $userId): void
    {
        $this->update('asset_depreciation_schedules', [
            'status' => 'Posted',
            'journal_id' => $journalId,
            'posted_by' => $userId,
            'posted_at' => date('Y-m-d H:i:s'),
        ], 'id', $periodId);
    }

    public function insertDisposal(array $data): int
    {
        return $this->insert('asset_disposals', $data);
    }

    /**
     * Post the next pending depreciation/amortization period for an asset:
     * writes a balanced journal entry (Debit expense / Credit accumulated
     * depreciation) and updates the asset's running accumulated depreciation
     * and net book value.
     *
     * @return array{period: array<string,mixed>, journal_id: int}|null null if nothing pending
     */
    public function depreciateNextPeriod(int $assetId, ?int $userId): ?array
    {
        $asset = $this->find($assetId);
        if (!$asset) {
            return null;
        }

        $period = $this->nextPendingPeriod($assetId);
        if (!$period) {
            return null;
        }

        $this->db->beginTransaction();

        try {
            $label = $asset['asset_nature'] === 'Intangible' ? 'Amortization' : 'Depreciation';

            $journalId = $this->insert('accounting_journal_entries', [
                'journal_no' => generate_reference('JNL'),
                'journal_date' => $period['period_date'],
                'source_module' => 'Fixed Assets',
                'source_table' => 'asset_depreciation_schedules',
                'source_id' => $period['id'],
                'reference_no' => $asset['asset_no'],
                'description' => "$label - {$asset['asset_name']} ({$asset['asset_no']}) - period {$period['period_no']}",
                'journal_type' => 'Automatic',
                'status' => 'Posted',
                'created_by' => $userId,
                'posted_by' => $userId,
                'posted_at' => date('Y-m-d H:i:s'),
            ]);

            if ($asset['depreciation_expense_account_id'] && $asset['accumulated_depreciation_account_id']) {
                $this->insert('accounting_journal_lines', [
                    'journal_id' => $journalId,
                    'account_id' => $asset['depreciation_expense_account_id'],
                    'description' => "$label expense - {$asset['asset_no']}",
                    'debit' => $period['depreciation_amount'],
                    'credit' => 0,
                ]);
                $this->insert('accounting_journal_lines', [
                    'journal_id' => $journalId,
                    'account_id' => $asset['accumulated_depreciation_account_id'],
                    'description' => "Accumulated $label - {$asset['asset_no']}",
                    'debit' => 0,
                    'credit' => $period['depreciation_amount'],
                ]);
            }

            $this->markPeriodPosted((int) $period['id'], $journalId, $userId);

            $newAccumulated = round((float) $asset['accumulated_depreciation'] + (float) $period['depreciation_amount'], 2);
            $newNbv = round((float) $asset['capitalized_cost'] - $newAccumulated, 2);
            $remainingPending = (int) $this->scalar(
                "SELECT COUNT(*) FROM asset_depreciation_schedules WHERE asset_id = ? AND status = 'Pending'",
                [$assetId]
            );

            $this->updateFields($assetId, [
                'accumulated_depreciation' => $newAccumulated,
                'net_book_value' => $newNbv,
                'status' => $remainingPending === 0 ? 'Fully Depreciated' : 'Active',
            ]);

            $this->db->commit();

            return ['period' => $period, 'journal_id' => $journalId];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function totals(): array
    {
        return [
            'count' => (int) $this->scalar("SELECT COUNT(*) FROM fixed_assets WHERE status != 'Disposed'"),
            'net_book_value' => (float) ($this->scalar("SELECT COALESCE(SUM(net_book_value),0) FROM fixed_assets WHERE status != 'Disposed'") ?: 0),
            'monthly_charge' => (float) ($this->scalar(
                "SELECT COALESCE(SUM(t.depreciation_amount),0) FROM (
                    SELECT s.depreciation_amount,
                           ROW_NUMBER() OVER (PARTITION BY s.asset_id ORDER BY s.period_no) AS rn
                    FROM asset_depreciation_schedules s WHERE s.status = 'Pending'
                 ) t WHERE t.rn = 1"
            ) ?: 0),
        ];
    }
}
