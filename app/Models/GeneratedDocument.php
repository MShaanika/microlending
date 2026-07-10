<?php

namespace App\Models;

use App\Core\Model;

/**
 * Letter requests (completion / consolidation letters). Staff fulfil these
 * by uploading the final prepared PDF -- there is no template-merge engine,
 * the seeded document_templates rows are descriptive only.
 */
class GeneratedDocument extends Model
{
    public function forBorrower(int $borrowerId): array
    {
        return $this->all(
            "SELECT g.*, t.template_name, t.template_type FROM generated_documents g
             JOIN document_templates t ON t.id = g.template_id
             WHERE g.borrower_id = ? ORDER BY g.id DESC",
            [$borrowerId]
        );
    }

    public function paginated(string $status = '', int $limit = 100): array
    {
        $sql = "SELECT g.*, t.template_name, t.template_type, CONCAT(b.first_name,' ',b.last_name) AS borrower_name
                FROM generated_documents g
                JOIN document_templates t ON t.id = g.template_id
                JOIN borrowers b ON b.id = g.borrower_id
                WHERE 1=1";
        $params = [];

        if ($status !== '') {
            $sql .= " AND g.status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY g.id DESC LIMIT " . (int) $limit;

        return $this->all($sql, $params);
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT g.*, t.template_name, t.template_type FROM generated_documents g
             JOIN document_templates t ON t.id = g.template_id WHERE g.id = ?",
            [$id]
        );
    }

    public function findForBorrower(int $id, int $borrowerId): ?array
    {
        return $this->one(
            "SELECT g.*, t.template_name, t.template_type FROM generated_documents g
             JOIN document_templates t ON t.id = g.template_id WHERE g.id = ? AND g.borrower_id = ?",
            [$id, $borrowerId]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('generated_documents', $data);
    }

    public function markFulfilled(int $id, string $filePath, int $staffId): bool
    {
        return $this->update('generated_documents', [
            'file_path' => $filePath,
            'status' => 'Generated',
            'generated_by' => $staffId,
        ], 'id', $id);
    }
}
