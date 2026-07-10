<?php

namespace App\Models;

use App\Core\Model;

class Company extends Model
{
    public function primary(): ?array
    {
        return $this->one("SELECT * FROM companies WHERE is_active = 1 ORDER BY id LIMIT 1");
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('companies', $data, 'id', $id);
    }
}
