<?php

namespace App\Models;

use App\Core\Model;

class DocumentTemplate extends Model
{
    public function findByType(string $type): ?array
    {
        return $this->one("SELECT * FROM document_templates WHERE template_type = ? AND is_active = 1 ORDER BY id LIMIT 1", [$type]);
    }

    /**
     * template_type is shared by more than one template (e.g. both
     * consolidation variants) -- use the specific template_code wherever
     * the caller actually knows which one it needs.
     */
    public function findByCode(string $code): ?array
    {
        return $this->one("SELECT * FROM document_templates WHERE template_code = ? AND is_active = 1 LIMIT 1", [$code]);
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT t.*, c.category_name FROM document_templates t
             LEFT JOIN document_template_categories c ON c.id = t.category_id
             WHERE t.id = ?",
            [$id]
        );
    }

    public function allTemplates(bool $activeOnly = false): array
    {
        $sql = "SELECT t.*, c.category_name FROM document_templates t
                LEFT JOIN document_template_categories c ON c.id = t.category_id";
        if ($activeOnly) {
            $sql .= " WHERE t.is_active = 1";
        }
        $sql .= " ORDER BY t.template_name";

        return $this->all($sql);
    }

    public function codeExists(string $code, ?int $excludeId = null): bool
    {
        if ($excludeId) {
            return (bool) $this->scalar("SELECT 1 FROM document_templates WHERE template_code = ? AND id != ?", [$code, $excludeId]);
        }
        return (bool) $this->scalar("SELECT 1 FROM document_templates WHERE template_code = ?", [$code]);
    }

    public function create(array $data): int
    {
        return $this->insert('document_templates', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('document_templates', $data, 'id', $id);
    }

    public function fields(int $templateId): array
    {
        return $this->all("SELECT * FROM document_template_fields WHERE template_id = ? ORDER BY id", [$templateId]);
    }

    public function findField(int $id): ?array
    {
        return $this->one("SELECT * FROM document_template_fields WHERE id = ?", [$id]);
    }

    public function addField(array $data): int
    {
        return $this->insert('document_template_fields', $data);
    }

    public function updateField(int $id, array $data): bool
    {
        return $this->update('document_template_fields', $data, 'id', $id);
    }

    public function deleteField(int $id): bool
    {
        return $this->query("DELETE FROM document_template_fields WHERE id = ?", [$id])->rowCount() > 0;
    }
}
