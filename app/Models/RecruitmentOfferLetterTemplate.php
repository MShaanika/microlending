<?php

namespace App\Models;

use App\Core\Model;

class RecruitmentOfferLetterTemplate extends Model
{
    public function allTemplates(): array
    {
        return $this->query("SELECT * FROM recruitment_offer_letter_templates ORDER BY name")->fetchAll();
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
