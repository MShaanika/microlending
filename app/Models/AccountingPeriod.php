<?php

namespace App\Models;

use App\Core\Model;

class AccountingPeriod extends Model
{
    public function forFiscalYear(int $fiscalYearId): array
    {
        return $this->all("SELECT * FROM accounting_periods WHERE fiscal_year_id = ? ORDER BY start_date", [$fiscalYearId]);
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM accounting_periods WHERE id = ?", [$id]);
    }

    /**
     * The period covering a given date, if any. Used to auto-assign a
     * journal to its fiscal period and check whether that period is closed.
     */
    public function findByDate(string $date): ?array
    {
        return $this->one(
            "SELECT * FROM accounting_periods WHERE ? BETWEEN start_date AND end_date ORDER BY id DESC LIMIT 1",
            [$date]
        );
    }

    /**
     * Generate one period per calendar month covering the fiscal year's date
     * range (first/last period trimmed to the fiscal year's actual bounds).
     */
    public function generateForFiscalYear(int $fiscalYearId, string $startDate, string $endDate): void
    {
        $start = new \DateTimeImmutable($startDate);
        $end = new \DateTimeImmutable($endDate);
        $cursor = new \DateTimeImmutable($start->format('Y-m-01'));

        while ($cursor <= $end) {
            $periodStart = $cursor < $start ? $start : $cursor;
            $periodEnd = $cursor->modify('last day of this month');
            if ($periodEnd > $end) {
                $periodEnd = $end;
            }

            $this->insert('accounting_periods', [
                'fiscal_year_id' => $fiscalYearId,
                'period_name' => $cursor->format('F Y'),
                'start_date' => $periodStart->format('Y-m-d'),
                'end_date' => $periodEnd->format('Y-m-d'),
                'is_closed' => 0,
            ]);

            $cursor = $cursor->modify('+1 month');
        }
    }

    public function close(int $id, int $userId): bool
    {
        return $this->update('accounting_periods', [
            'is_closed' => 1,
            'closed_by' => $userId,
            'closed_at' => date('Y-m-d H:i:s'),
        ], 'id', $id);
    }

    public function reopen(int $id): bool
    {
        return $this->update('accounting_periods', [
            'is_closed' => 0,
            'closed_by' => null,
            'closed_at' => null,
        ], 'id', $id);
    }

    public function closeAllForFiscalYear(int $fiscalYearId, int $userId): void
    {
        $this->update('accounting_periods', [
            'is_closed' => 1,
            'closed_by' => $userId,
            'closed_at' => date('Y-m-d H:i:s'),
        ], 'fiscal_year_id', $fiscalYearId);
    }

    public function reopenAllForFiscalYear(int $fiscalYearId): void
    {
        $this->update('accounting_periods', [
            'is_closed' => 0,
            'closed_by' => null,
            'closed_at' => null,
        ], 'fiscal_year_id', $fiscalYearId);
    }
}
