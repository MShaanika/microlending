<?php

namespace App\Models;

use App\Core\Model;

class RecruitmentOnboardingChecklist extends Model
{
    public function allChecklists(): array
    {
        return $this->query("SELECT * FROM recruitment_onboarding_checklists ORDER BY name")->fetchAll();
    }

    public function activeChecklists(): array
    {
        return $this->query("SELECT * FROM recruitment_onboarding_checklists WHERE status = 'Active' ORDER BY name")->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM recruitment_onboarding_checklists WHERE id = ?", [$id]);
    }

    public function create(array $data): int
    {
        return $this->insert('recruitment_onboarding_checklists', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('recruitment_onboarding_checklists', $data, 'id', $id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM recruitment_onboarding_checklists WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
