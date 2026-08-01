<?php

namespace App\Models;

use App\Core\Model;

/**
 * NAMFISA levy and duty stamp: statutory charges the borrower repays but
 * the lender must remit to government. Rates/amounts are configurable via
 * namfisa_levy_settings / duty_stamp_settings; each loan gets its own
 * transaction row for audit trail and regulatory reporting.
 */
class StatutoryCharge extends Model
{
    public function currentNamfisaLevyRate(): float
    {
        return $this->namfisaLevyRateAsOf(date('Y-m-d'));
    }

    /**
     * The levy rate in effect on a given date -- used for month-by-month
     * regulatory reports, where "today's" rate would be wrong for a
     * quarter that spans a rate change.
     */
    public function namfisaLevyRateAsOf(string $date): float
    {
        $rate = $this->scalar(
            "SELECT levy_rate FROM namfisa_levy_settings
             WHERE is_active = 1 AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)
             ORDER BY effective_from DESC LIMIT 1",
            [$date, $date]
        );
        return (float) ($rate ?: 0);
    }

    public function currentDutyStampAmount(): float
    {
        $amount = $this->scalar(
            "SELECT stamp_amount FROM duty_stamp_settings
             WHERE is_active = 1 AND effective_from <= CURDATE() AND (effective_to IS NULL OR effective_to >= CURDATE())
             ORDER BY effective_from DESC LIMIT 1"
        );
        return (float) ($amount ?: 0);
    }

    public function findNamfisaLevyByLoan(int $loanId): ?array
    {
        return $this->one("SELECT * FROM namfisa_levy_transactions WHERE loan_id = ? LIMIT 1", [$loanId]);
    }

    public function findDutyStampByLoan(int $loanId): ?array
    {
        return $this->one("SELECT * FROM duty_stamp_transactions WHERE loan_id = ? LIMIT 1", [$loanId]);
    }

    public function recordNamfisaLevy(array $data): int
    {
        return $this->insert('namfisa_levy_transactions', $data);
    }

    public function recordDutyStamp(array $data): int
    {
        return $this->insert('duty_stamp_transactions', $data);
    }

    public function markNamfisaLevyPosted(int $loanId, int $journalId): bool
    {
        return $this->update('namfisa_levy_transactions', [
            'status' => 'Posted',
            'journal_id' => $journalId,
        ], 'loan_id', $loanId);
    }

    public function markDutyStampPosted(int $loanId, int $journalId): bool
    {
        return $this->update('duty_stamp_transactions', [
            'status' => 'Posted',
            'journal_id' => $journalId,
        ], 'loan_id', $loanId);
    }

    public function updateNamfisaLevy(int $loanId, array $data): bool
    {
        return $this->update('namfisa_levy_transactions', $data, 'loan_id', $loanId);
    }

    public function updateDutyStamp(int $loanId, array $data): bool
    {
        return $this->update('duty_stamp_transactions', $data, 'loan_id', $loanId);
    }

    public function allNamfisaSettings(): array
    {
        return $this->all("SELECT * FROM namfisa_levy_settings ORDER BY effective_from DESC");
    }

    public function allDutyStampSettings(): array
    {
        return $this->all("SELECT * FROM duty_stamp_settings ORDER BY effective_from DESC");
    }

    /**
     * Closes out the currently-open row (effective_to = new effective_from
     * minus a day) before inserting the new one, so currentNamfisaLevyRate()'s
     * date-range query keeps a clean, non-overlapping history. Deliberately
     * leaves is_active untouched -- flipping it off here would break that
     * query for a future-dated new rate, since between today and the new
     * row's effective_from neither row would satisfy
     * "is_active = 1 AND effective_from <= CURDATE()".
     */
    public function createNamfisaSetting(array $data): int
    {
        $this->query(
            "UPDATE namfisa_levy_settings SET effective_to = DATE_SUB(?, INTERVAL 1 DAY)
             WHERE is_active = 1 AND effective_to IS NULL",
            [$data['effective_from']]
        );
        return $this->insert('namfisa_levy_settings', $data);
    }

    public function createDutyStampSetting(array $data): int
    {
        $this->query(
            "UPDATE duty_stamp_settings SET effective_to = DATE_SUB(?, INTERVAL 1 DAY)
             WHERE is_active = 1 AND effective_to IS NULL",
            [$data['effective_from']]
        );
        return $this->insert('duty_stamp_settings', $data);
    }

    public function paginatedNamfisaTransactions(string $status = '', string $start = '', string $end = '', int $limit = 200, ?int $branchId = null): array
    {
        $sql = "SELECT nlt.*, l.loan_no, CONCAT(b.first_name,' ',b.last_name) AS borrower_name
                FROM namfisa_levy_transactions nlt
                JOIN loans l ON l.id = nlt.loan_id
                JOIN borrowers b ON b.id = nlt.borrower_id
                WHERE 1=1";
        $params = [];

        if ($status !== '') {
            $sql .= " AND nlt.status = ?";
            $params[] = $status;
        }
        if ($start !== '' && $end !== '') {
            $sql .= " AND nlt.levy_date BETWEEN ? AND ?";
            array_push($params, $start, $end);
        }
        if ($branchId !== null) {
            $sql .= " AND nlt.branch_id = ?";
            $params[] = $branchId;
        }

        $sql .= " ORDER BY nlt.levy_date DESC LIMIT " . (int) $limit;

        return $this->all($sql, $params);
    }

    public function paginatedDutyStampTransactions(string $status = '', string $start = '', string $end = '', int $limit = 200, ?int $branchId = null): array
    {
        $sql = "SELECT dst.*, l.loan_no, CONCAT(b.first_name,' ',b.last_name) AS borrower_name
                FROM duty_stamp_transactions dst
                JOIN loans l ON l.id = dst.loan_id
                JOIN borrowers b ON b.id = dst.borrower_id
                WHERE 1=1";
        $params = [];

        if ($status !== '') {
            $sql .= " AND dst.status = ?";
            $params[] = $status;
        }
        if ($start !== '' && $end !== '') {
            $sql .= " AND dst.stamp_date BETWEEN ? AND ?";
            array_push($params, $start, $end);
        }
        if ($branchId !== null) {
            $sql .= " AND dst.branch_id = ?";
            $params[] = $branchId;
        }

        $sql .= " ORDER BY dst.stamp_date DESC LIMIT " . (int) $limit;

        return $this->all($sql, $params);
    }

    /** $branchId restricts which of the given IDs can actually be updated -- a
     *  non-Super-Admin can't submit another branch's transactions by tampering
     *  with the posted id list. */
    public function markNamfisaSubmitted(array $ids, ?int $branchId = null): void
    {
        if (empty($ids)) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_map('intval', $ids);
        $branchSql = '';
        if ($branchId !== null) {
            $branchSql = " AND branch_id = ?";
            $params[] = $branchId;
        }
        $this->query("UPDATE namfisa_levy_transactions SET status = 'Submitted' WHERE id IN ($placeholders){$branchSql}", $params);
    }

    public function markDutyStampSubmitted(array $ids, ?int $branchId = null): void
    {
        if (empty($ids)) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_map('intval', $ids);
        $branchSql = '';
        if ($branchId !== null) {
            $branchSql = " AND branch_id = ?";
            $params[] = $branchId;
        }
        $this->query("UPDATE duty_stamp_transactions SET status = 'Submitted' WHERE id IN ($placeholders){$branchSql}", $params);
    }
}
