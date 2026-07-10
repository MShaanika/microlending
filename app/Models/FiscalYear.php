<?php

namespace App\Models;

use App\Core\Model;

class FiscalYear extends Model
{
    public function allYears(): array
    {
        return $this->all("SELECT * FROM accounting_fiscal_years ORDER BY start_date DESC");
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM accounting_fiscal_years WHERE id = ?", [$id]);
    }

    public function nameExists(string $financialYear, ?int $excludeId = null): bool
    {
        if ($excludeId) {
            return (bool) $this->scalar("SELECT 1 FROM accounting_fiscal_years WHERE financial_year = ? AND id != ?", [$financialYear, $excludeId]);
        }
        return (bool) $this->scalar("SELECT 1 FROM accounting_fiscal_years WHERE financial_year = ?", [$financialYear]);
    }

    public function overlaps(string $startDate, string $endDate, ?int $excludeId = null): bool
    {
        $sql = "SELECT id FROM accounting_fiscal_years
                WHERE (? BETWEEN start_date AND end_date OR ? BETWEEN start_date AND end_date
                       OR start_date BETWEEN ? AND ? OR end_date BETWEEN ? AND ?)";
        $params = [$startDate, $endDate, $startDate, $endDate, $startDate, $endDate];

        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        return (bool) $this->scalar($sql . " LIMIT 1", $params);
    }

    public function create(array $data): int
    {
        return $this->insert('accounting_fiscal_years', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('accounting_fiscal_years', $data, 'id', $id);
    }

    public function setStatus(int $id, string $status): bool
    {
        return $this->update('accounting_fiscal_years', ['status' => $status], 'id', $id);
    }
}
