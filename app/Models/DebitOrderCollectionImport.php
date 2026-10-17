<?php

namespace App\Models;

use App\Core\Model;

class DebitOrderCollectionImport extends Model
{
    /**
     * $onlyWithResults hides a zero-row import by default -- Collexia's
     * Download Payments endpoint returns an empty responses array whenever
     * nothing new has posted since the last pull (the scheduled cron runs
     * 4x/day, see bin/download_collexia_payments.php), so most of these
     * are just noise from a pull that happened to find nothing. Still
     * creatable/visible on request (Show Empty Downloads), since a 0-row
     * CollexiaAPI import failing to appear at all could otherwise look
     * like the cron silently stopped running rather than "ran, found
     * nothing".
     */
    public function paginated(string $reportType = '', bool $onlyWithResults = true): array
    {
        $sql = "SELECT i.*, CONCAT(u.name) AS imported_by_name
                FROM debit_order_collection_imports i
                LEFT JOIN users u ON u.id = i.imported_by
                WHERE 1=1";
        $params = [];

        if ($reportType !== '') {
            $sql .= " AND i.report_type = ?";
            $params[] = $reportType;
        }
        if ($onlyWithResults) {
            $sql .= " AND i.total_rows > 0";
        }

        $sql .= " ORDER BY i.id DESC LIMIT 200";

        return $this->all($sql, $params);
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT i.*, CONCAT(u.name) AS imported_by_name
             FROM debit_order_collection_imports i
             LEFT JOIN users u ON u.id = i.imported_by
             WHERE i.id = ?",
            [$id]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('debit_order_collection_imports', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('debit_order_collection_imports', $data, 'id', $id);
    }
}
