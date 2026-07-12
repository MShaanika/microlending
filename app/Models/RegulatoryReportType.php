<?php

namespace App\Models;

use App\Core\Model;

class RegulatoryReportType extends Model
{
    public function allTypes(bool $activeOnly = false): array
    {
        $sql = "SELECT * FROM regulatory_report_types";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY report_name";

        return $this->all($sql);
    }

    public function findByCode(string $code): ?array
    {
        return $this->one("SELECT * FROM regulatory_report_types WHERE report_code = ?", [$code]);
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM regulatory_report_types WHERE id = ?", [$id]);
    }
}
