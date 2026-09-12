<?php

namespace App\Models;

use App\Core\Model;

class CplMonthlySnapshot extends Model
{
    public function create(array $data): int
    {
        return $this->insert('cpl_monthly_snapshots', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('cpl_monthly_snapshots', $data, 'id', $id);
    }

    public function deleteForBatch(int $batchId): bool
    {
        return $this->query("DELETE FROM cpl_monthly_snapshots WHERE batch_id = ?", [$batchId])->rowCount() >= 0;
    }

    public function forBatch(int $batchId, ?string $validationStatus = null): array
    {
        $sql = "SELECT s.*, b.first_name, b.last_name, b.borrower_no, l.loan_no
                FROM cpl_monthly_snapshots s
                JOIN borrowers b ON b.id = s.borrower_id
                JOIN loans l ON l.id = s.loan_id
                WHERE s.batch_id = ?";
        $params = [$batchId];
        if ($validationStatus !== null) {
            $sql .= " AND s.validation_status = ?";
            $params[] = $validationStatus;
        }
        $sql .= " ORDER BY l.loan_no";
        return $this->all($sql, $params);
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT s.*, b.first_name, b.last_name, b.borrower_no, l.loan_no
             FROM cpl_monthly_snapshots s
             JOIN borrowers b ON b.id = s.borrower_id
             JOIN loans l ON l.id = s.loan_id
             WHERE s.id = ?",
            [$id]
        );
    }

    public function countsForBatch(int $batchId): array
    {
        $rows = $this->all(
            "SELECT validation_status, COUNT(*) AS c FROM cpl_monthly_snapshots WHERE batch_id = ? GROUP BY validation_status",
            [$batchId]
        );
        $counts = ['Pending' => 0, 'Valid' => 0, 'Warning' => 0, 'Blocking Error' => 0];
        foreach ($rows as $row) {
            $counts[$row['validation_status']] = (int) $row['c'];
        }
        return $counts;
    }
}
