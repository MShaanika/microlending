<?php

namespace App\Models;

use App\Core\Model;

class RecruitmentOnboardingChecklist extends Model
{
    private const SORTABLE = ['name' => 'name', 'status' => 'status', 'created_at' => 'created_at'];

    public function allChecklists(): array
    {
        return $this->query("SELECT * FROM recruitment_onboarding_checklists ORDER BY name")->fetchAll();
    }

    /** @return array{rows: array, total: int, totalPages: int} */
    public function paginated(string $search = '', string $sort = 'name', string $dir = 'asc', int $page = 1, int $perPage = 10): array
    {
        $where = '';
        $params = [];
        if ($search !== '') {
            $where = ' WHERE name LIKE ? OR description LIKE ?';
            $like = '%' . $search . '%';
            array_push($params, $like, $like);
        }
        $total = (int) $this->scalar("SELECT COUNT(*) FROM recruitment_onboarding_checklists" . $where, $params);
        $orderCol = self::SORTABLE[$sort] ?? 'name';
        $orderDir = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->query(
            "SELECT * FROM recruitment_onboarding_checklists{$where} ORDER BY {$orderCol} {$orderDir} LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
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
