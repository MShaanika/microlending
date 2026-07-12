<?php

namespace App\Models;

use App\Core\Model;

/**
 * Every document the system has generated, across every module (Letters,
 * Reschedule, Debit Order Cancellation, Application, Refund Claims) --
 * fulfilled either by DocumentGenerationService's template-merge engine or
 * by staff uploading a manually-prepared PDF (see LetterController::fulfill()).
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

    /**
     * Central list across every source_module (Staff/Reschedule/Debit Order
     * Cancellation/Application/Refund Claims), unlike paginated() above
     * (kept as-is for LetterController's Completion/Consolidation-themed
     * list) -- LEFT JOINs borrower/template so rows generated before a
     * borrower_id exists (e.g. application-stage documents) still show up.
     */
    public function paginatedAll(string $sourceModule = '', string $status = '', string $search = '', int $limit = 200): array
    {
        $sql = "SELECT g.*, t.template_name, t.template_type,
                       CONCAT(b.first_name,' ',b.last_name) AS borrower_name
                FROM generated_documents g
                LEFT JOIN document_templates t ON t.id = g.template_id
                LEFT JOIN borrowers b ON b.id = g.borrower_id
                WHERE 1=1";
        $params = [];

        if ($sourceModule !== '') {
            $sql .= " AND g.source_module = ?";
            $params[] = $sourceModule;
        }
        if ($status !== '') {
            $sql .= " AND g.status = ?";
            $params[] = $status;
        }
        if ($search !== '') {
            $sql .= " AND (g.document_no LIKE ? OR g.document_title LIKE ? OR b.first_name LIKE ? OR b.last_name LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $sql .= " ORDER BY g.id DESC LIMIT " . (int) $limit;

        return $this->all($sql, $params);
    }

    public function sourceModules(): array
    {
        return array_column(
            $this->all("SELECT DISTINCT source_module FROM generated_documents WHERE source_module IS NOT NULL ORDER BY source_module"),
            'source_module'
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
