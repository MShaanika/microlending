<?php

namespace App\Models;

use App\Core\Model;

class RecruitmentChecklistItem extends Model
{
    private const SORTABLE = [
        'checklist' => 'ch.name',
        'task' => 'i.task_name',
        'due_day' => 'i.due_day',
        'status' => 'i.status',
    ];

    /** @return array{rows: array, total: int, totalPages: int} */
    public function paginated(string $search = '', string $sort = 'checklist', string $dir = 'asc', int $page = 1, int $perPage = 10): array
    {
        $where = '';
        $params = [];
        if ($search !== '') {
            $where = ' WHERE i.task_name LIKE ? OR ch.name LIKE ?';
            $like = '%' . $search . '%';
            array_push($params, $like, $like);
        }
        $total = (int) $this->scalar(
            "SELECT COUNT(*) FROM recruitment_checklist_items i LEFT JOIN recruitment_onboarding_checklists ch ON ch.id = i.checklist_id" . $where,
            $params
        );
        $orderCol = self::SORTABLE[$sort] ?? 'ch.name';
        $orderDir = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->query(
            "SELECT i.*, ch.name AS checklist_name FROM recruitment_checklist_items i
             LEFT JOIN recruitment_onboarding_checklists ch ON ch.id = i.checklist_id{$where}
             ORDER BY {$orderCol} {$orderDir} LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

    public function forChecklist(int $checklistId): array
    {
        return $this->query(
            "SELECT * FROM recruitment_checklist_items WHERE checklist_id = ? ORDER BY due_day IS NULL, due_day, id",
            [$checklistId]
        )->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM recruitment_checklist_items WHERE id = ?", [$id]);
    }

    public function create(array $data): int
    {
        return $this->insert('recruitment_checklist_items', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('recruitment_checklist_items', $data, 'id', $id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM recruitment_checklist_items WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
