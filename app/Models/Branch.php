<?php

namespace App\Models;

use App\Core\Model;

class Branch extends Model
{
    public function all(string $sql = "SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name", array $params = []): array
    {
        return parent::all($sql, $params);
    }
}
