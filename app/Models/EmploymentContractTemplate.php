<?php

namespace App\Models;

use App\Core\Model;

class EmploymentContractTemplate extends Model
{
    public function allTemplates(): array
    {
        return $this->query("SELECT * FROM employment_contract_templates ORDER BY name")->fetchAll();
    }

    public function activeTemplates(): array
    {
        return $this->query("SELECT * FROM employment_contract_templates WHERE is_active = 1 ORDER BY name")->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM employment_contract_templates WHERE id = ?", [$id]);
    }

    public function create(array $data): int
    {
        return $this->insert('employment_contract_templates', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('employment_contract_templates', $data, 'id', $id);
    }
}
