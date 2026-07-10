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
        $rate = $this->scalar(
            "SELECT levy_rate FROM namfisa_levy_settings
             WHERE is_active = 1 AND effective_from <= CURDATE() AND (effective_to IS NULL OR effective_to >= CURDATE())
             ORDER BY effective_from DESC LIMIT 1"
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
}
