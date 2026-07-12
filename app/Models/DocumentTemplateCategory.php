<?php

namespace App\Models;

use App\Core\Model;

class DocumentTemplateCategory extends Model
{
    public function allCategories(bool $activeOnly = false): array
    {
        $sql = "SELECT * FROM document_template_categories";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY category_name";

        return $this->all($sql);
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM document_template_categories WHERE id = ?", [$id]);
    }

    public function create(array $data): int
    {
        return $this->insert('document_template_categories', $data);
    }
}
