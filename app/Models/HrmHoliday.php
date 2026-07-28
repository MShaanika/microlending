<?php

namespace App\Models;

use App\Core\Model;

class HrmHoliday extends Model
{
    public function allHolidays(?int $year = null): array
    {
        if ($year !== null) {
            return $this->query(
                "SELECT * FROM hrm_holidays WHERE YEAR(start_date) = ? ORDER BY start_date",
                [$year]
            )->fetchAll();
        }
        return $this->query("SELECT * FROM hrm_holidays ORDER BY start_date DESC")->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM hrm_holidays WHERE id = ?", [$id]);
    }

    public function create(array $data): int
    {
        return $this->insert('hrm_holidays', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('hrm_holidays', $data, 'id', $id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM hrm_holidays WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
