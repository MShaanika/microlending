<?php

namespace App\Models;

use App\Core\Model;

class RecruitmentChecklistItem extends Model
{
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
