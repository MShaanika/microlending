<?php

namespace App\Models;

use App\Core\Model;

class RecruitmentCustomQuestion extends Model
{
    public function allQuestions(): array
    {
        return $this->query("SELECT * FROM recruitment_custom_questions ORDER BY sort_order, id")->fetchAll();
    }

    public function activeQuestions(): array
    {
        return $this->query("SELECT * FROM recruitment_custom_questions WHERE is_active = 1 ORDER BY sort_order, id")->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM recruitment_custom_questions WHERE id = ?", [$id]);
    }

    public function findMany(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return $this->query(
            "SELECT * FROM recruitment_custom_questions WHERE id IN ($placeholders) ORDER BY sort_order, id",
            $ids
        )->fetchAll();
    }

    public function create(array $data): int
    {
        return $this->insert('recruitment_custom_questions', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('recruitment_custom_questions', $data, 'id', $id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM recruitment_custom_questions WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
