<?php

namespace App\Models;

use App\Core\Model;

class Permission extends Model
{
    public function groupedByModule(): array
    {
        $rows = $this->all("SELECT * FROM permissions ORDER BY module_name, permission_name");
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['module_name']][] = $row;
        }
        return $grouped;
    }
}
