<?php

namespace App\Models;

use App\Core\Model;
use App\Support\AssetStatus;
use App\Support\ListFilter;

class FixedAsset extends Model
{
    /**
     * $branchId !== null shows that branch's assets plus any unassigned
     * (branch_id IS NULL) shared/company-wide asset -- an asset is only
     * ever hidden from a branch, never from everyone.
     *
     * $filter['from'] / ['to'] (inclusive Y-m-d, either may be missing) limit the list to assets acquired on those days, by purchase date.
     * Added after the other arguments because the financial statements call this positionally.
     */
    public function paginated(string $search = '', string $status = '', int $limit = 100, ?int $branchId = null, array $filter = []): array
    {
        $sql = "SELECT a.*, c.category_name
                FROM fixed_assets a
                JOIN asset_categories c ON c.id = a.category_id
                WHERE 1=1";
        $params = [];

        $sql .= ListFilter::searchSql(['a.asset_no', 'a.asset_name', 'a.serial_no'], $search, $params);

        if ($status !== '') {
            $sql .= " AND a.status = ?";
            $params[] = $status;
        }

        if ($branchId !== null) {
            $sql .= " AND (a.branch_id = ? OR a.branch_id IS NULL)";
            $params[] = $branchId;
        }

        $sql .= ListFilter::dateSql('a.purchase_date', $filter['from'] ?? null, $filter['to'] ?? null, $params);

        $sql .= " ORDER BY a.id DESC LIMIT " . (int) $limit;

        return $this->all($sql, $params);
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT a.*, c.category_name, c.asset_nature AS category_nature,
                    j.journal_no AS acquisition_journal_no,
                    dj.journal_no AS disposal_journal_no,
                    cu.name AS created_by_name, du.name AS disposed_by_name
             FROM fixed_assets a
             JOIN asset_categories c ON c.id = a.category_id
             LEFT JOIN accounting_journal_entries j ON j.id = a.journal_id
             LEFT JOIN asset_disposals d ON d.asset_id = a.id
             LEFT JOIN accounting_journal_entries dj ON dj.id = d.journal_id
             LEFT JOIN users cu ON cu.id = a.created_by
             LEFT JOIN users du ON du.id = d.disposed_by
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

    /** Only used to roll back a just-created asset whose acquisition journal failed to post. */
    public function deleteRecord(int $id): void
    {
        $this->query("DELETE FROM fixed_assets WHERE id = ?", [$id]);
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

            // Posting never overwrites a status a person set: an asset marked Under Maintenance or Damaged stays that way, last period
            // included (it is still owned and still shows in its own filter). Only an Active asset moves on to Fully Depreciated.
            $status = $asset['status'];
            if ($remainingPending === 0 && $status === AssetStatus::ACTIVE) {
                $status = AssetStatus::FULLY_DEPRECIATED;
            }

            $this->updateFields($assetId, [
                'accumulated_depreciation' => $newAccumulated,
                'net_book_value' => $newNbv,
                'status' => $status,
            ]);

            $this->db->commit();

            return ['period' => $period, 'journal_id' => $journalId];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Moves a still-owned asset to Active, Under Maintenance or Damaged. Choosing Active for an asset whose every depreciation period is already
     * posted gives Fully Depreciated, which is what it is; an asset with no schedule at all (a "No Depreciation" asset such as land) stays Active.
     * The caller has checked the asset exists, is still owned and that $to is a choosable status.
     *
     * @return string the status the asset has now
     */
    public function changeStatus(int $id, string $to): string
    {
        if ($to === AssetStatus::ACTIVE && $this->allPeriodsPosted($id)) {
            $to = AssetStatus::FULLY_DEPRECIATED;
        }
        $this->updateFields($id, ['status' => $to]);
        return $to;
    }

    /** True when the asset has a depreciation schedule and none of its periods is still pending. */
    private function allPeriodsPosted(int $assetId): bool
    {
        $row = $this->one(
            "SELECT COUNT(*) AS total, COALESCE(SUM(status = 'Pending'), 0) AS pending FROM asset_depreciation_schedules WHERE asset_id = ?",
            [$assetId]
        );
        return (int) ($row['total'] ?? 0) > 0 && (int) ($row['pending'] ?? 0) === 0;
    }

    public function totals(?int $branchId = null): array
    {
        $where = $branchId !== null ? " AND (branch_id = " . (int) $branchId . " OR branch_id IS NULL)" : "";
        $scheduleWhere = $branchId !== null ? " AND (a.branch_id = " . (int) $branchId . " OR a.branch_id IS NULL)" : "";
        return [
            'count' => (int) $this->scalar("SELECT COUNT(*) FROM fixed_assets WHERE status != 'Disposed'{$where}"),
            'net_book_value' => (float) ($this->scalar("SELECT COALESCE(SUM(net_book_value),0) FROM fixed_assets WHERE status != 'Disposed'{$where}") ?: 0),
            'monthly_charge' => (float) ($this->scalar(
                "SELECT COALESCE(SUM(t.depreciation_amount),0) FROM (
                    SELECT s.depreciation_amount,
                           ROW_NUMBER() OVER (PARTITION BY s.asset_id ORDER BY s.period_no) AS rn
                    FROM asset_depreciation_schedules s
                    JOIN fixed_assets a ON a.id = s.asset_id
                    WHERE s.status = 'Pending'{$scheduleWhere}
                 ) t WHERE t.rn = 1"
            ) ?: 0),
        ];
    }
}
