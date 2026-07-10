<?php

namespace App\Models;

use App\Core\Model;

class AssetCategory extends Model
{
    public function activeCategories(): array
    {
        return $this->all("SELECT * FROM asset_categories WHERE is_active = 1 ORDER BY category_name");
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM asset_categories WHERE id = ?", [$id]);
    }
}
