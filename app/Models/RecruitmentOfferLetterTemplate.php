<?php

namespace App\Models;

use App\Core\Model;

class RecruitmentOfferLetterTemplate extends Model
{
    private const SORTABLE = ['name' => 'name', 'is_active' => 'is_active', 'created_at' => 'created_at'];

    public function allTemplates(): array
    {
        return $this->query("SELECT * FROM recruitment_offer_letter_templates ORDER BY name")->fetchAll();
    }

    /** @return array{rows: array, total: int, totalPages: int} */
    public function paginated(string $search = '', string $sort = 'name', string $dir = 'asc', int $page = 1, int $perPage = 10): array
    {
        $where = '';
        $params = [];
        if ($search !== '') {
            $where = ' WHERE name LIKE ?';
            $params[] = '%' . $search . '%';
        }
        $total = (int) $this->scalar("SELECT COUNT(*) FROM recruitment_offer_letter_templates" . $where, $params);
        $orderCol = self::SORTABLE[$sort] ?? 'name';
        $orderDir = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->query(
            "SELECT * FROM recruitment_offer_letter_templates{$where} ORDER BY {$orderCol} {$orderDir} LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

    public function activeTemplates(): array
    {
        return $this->query("SELECT * FROM recruitment_offer_letter_templates WHERE is_active = 1 ORDER BY name")->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM recruitment_offer_letter_templates WHERE id = ?", [$id]);
    }

    public function create(array $data): int
    {
        return $this->insert('recruitment_offer_letter_templates', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('recruitment_offer_letter_templates', $data, 'id', $id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM recruitment_offer_letter_templates WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }

    /** Replace {{placeholder}} tokens in the template content with values. */
    public static function replaceVariables(string $content, array $values): string
    {
        foreach ($values as $key => $value) {
            $content = str_replace('{{' . $key . '}}', (string) $value, $content);
        }
        return $content;
    }
}
