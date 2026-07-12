<?php

namespace App\Models;

use App\Core\Model;

class RegulatoryReport extends Model
{
    public function paginated(string $typeCode = '', string $status = '', int $limit = 100): array
    {
        $sql = "SELECT r.*, t.report_code, t.report_name
                FROM regulatory_reports r
                JOIN regulatory_report_types t ON t.id = r.report_type_id
                WHERE 1=1";
        $params = [];

        if ($typeCode !== '') {
            $sql .= " AND t.report_code = ?";
            $params[] = $typeCode;
        }
        if ($status !== '') {
            $sql .= " AND r.status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY r.id DESC LIMIT " . (int) $limit;

        return $this->all($sql, $params);
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT r.*, t.report_code, t.report_name FROM regulatory_reports r
             JOIN regulatory_report_types t ON t.id = r.report_type_id
             WHERE r.id = ?",
            [$id]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('regulatory_reports', $data);
    }

    /**
     * Draft/Generated/Rejected only ever change the status column; Submitted
     * and Approved also stamp who/when, matching the columns the schema
     * actually has (there's no dedicated rejected_by/rejected_at pair).
     */
    public function updateStatus(int $id, string $status, int $userId): bool
    {
        $data = ['status' => $status];

        if ($status === 'Submitted') {
            $data['submitted_by'] = $userId;
            $data['submitted_at'] = date('Y-m-d H:i:s');
        } elseif ($status === 'Approved') {
            $data['approved_by'] = $userId;
            $data['approved_at'] = date('Y-m-d H:i:s');
        }

        return $this->update('regulatory_reports', $data, 'id', $id);
    }

    public function markExported(int $id, string $filePath): bool
    {
        return $this->update('regulatory_reports', ['file_path' => $filePath], 'id', $id);
    }

    public function logExport(int $reportId, string $exportNo, string $filePath, int $userId): void
    {
        $this->insert('regulatory_exports', [
            'regulatory_report_id' => $reportId,
            'export_no' => $exportNo,
            'export_type' => 'Excel',
            'file_path' => $filePath,
            'exported_by' => $userId,
        ]);
    }
}
