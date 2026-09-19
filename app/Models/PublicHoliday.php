<?php

namespace App\Models;

use App\Core\Model;

/**
 * Namibian public holiday calendar for BusinessCalendarService.
 * Deliberately separate from HrmHoliday (HR's date-range, leave-aware
 * table) -- this is single calendar dates only, owned by the lending
 * side, with no leave/is_paid semantics attached.
 */
class PublicHoliday extends Model
{
    protected string $table = 'public_holidays';

    public function all_(): array
    {
        return $this->all("SELECT h.*, u.name AS created_by_name FROM public_holidays h LEFT JOIN users u ON u.id = h.created_by ORDER BY h.holiday_date");
    }

    public function forYear(int $year): array
    {
        return $this->all("SELECT h.*, u.name AS created_by_name FROM public_holidays h LEFT JOIN users u ON u.id = h.created_by WHERE h.year = ? ORDER BY h.holiday_date", [$year]);
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM public_holidays WHERE id = ?", [$id]);
    }

    /** Active, single-date lookup -- the one method BusinessCalendarService actually calls. */
    public function isActiveHoliday(string $ymd): bool
    {
        return (bool) $this->scalar("SELECT 1 FROM public_holidays WHERE holiday_date = ? AND is_active = 1", [$ymd]);
    }

    /** Every active holiday date in a range, for previous/nextBusinessDay's caller-side sanity checks and UI listing. */
    public function activeDatesInRange(string $fromYmd, string $toYmd): array
    {
        $rows = $this->all(
            "SELECT holiday_date FROM public_holidays WHERE is_active = 1 AND holiday_date BETWEEN ? AND ?",
            [$fromYmd, $toYmd]
        );
        return array_column($rows, 'holiday_date');
    }

    public function create(array $data): int
    {
        return $this->insert('public_holidays', $data);
    }

    public function updateRecord(int $id, array $data): void
    {
        $sets = [];
        $params = [];
        foreach ($data as $col => $val) {
            $sets[] = "$col = ?";
            $params[] = $val;
        }
        $params[] = $id;
        $this->query("UPDATE public_holidays SET " . implode(', ', $sets) . " WHERE id = ?", $params);
    }

    /**
     * Copy Previous Year -- shifts every active holiday from $fromYear
     * by +1 calendar year and inserts as inactive drafts (source =
     * CopiedDraft). Never activates automatically -- Easter-linked and
     * other non-fixed holidays move by more than a year and NEED human
     * correction before use. Skips a date that's already present for
     * the target year (idempotent re-run).
     */
    public function copyPreviousYear(int $fromYear, int $toYear, ?int $userId): int
    {
        $rows = $this->forYear($fromYear);
        $created = 0;
        foreach ($rows as $row) {
            if (!$row['is_active']) {
                continue;
            }
            $shifted = (new \DateTimeImmutable($row['holiday_date']))->modify('+1 year');
            $newDate = $shifted->format('Y-m-d');
            if ($this->scalar("SELECT 1 FROM public_holidays WHERE holiday_date = ?", [$newDate])) {
                continue; // already exists for the target year -- idempotent
            }
            $this->create([
                'holiday_date' => $newDate,
                'holiday_name' => $row['holiday_name'],
                'year' => $toYear,
                'is_active' => 0,
                'source' => 'CopiedDraft',
                'copied_from_year' => $fromYear,
                'created_by' => $userId,
            ]);
            $created++;
        }
        return $created;
    }
}
