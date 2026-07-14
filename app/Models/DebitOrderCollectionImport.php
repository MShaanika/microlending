<?php

namespace App\Models;

use App\Core\Model;

class DebitOrderCollectionImport extends Model
{
    public function paginated(): array
    {
        return $this->all(
            "SELECT i.*, CONCAT(u.name) AS imported_by_name
             FROM debit_order_collection_imports i
             LEFT JOIN users u ON u.id = i.imported_by
             ORDER BY i.id DESC LIMIT 200"
        );
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT i.*, CONCAT(u.name) AS imported_by_name
             FROM debit_order_collection_imports i
             LEFT JOIN users u ON u.id = i.imported_by
             WHERE i.id = ?",
            [$id]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('debit_order_collection_imports', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('debit_order_collection_imports', $data, 'id', $id);
    }
}
