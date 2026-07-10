<?php

namespace App\Models;

use App\Core\Model;

class DocumentTemplate extends Model
{
    public function findByType(string $type): ?array
    {
        return $this->one("SELECT * FROM document_templates WHERE template_type = ? AND is_active = 1 ORDER BY id LIMIT 1", [$type]);
    }
}
