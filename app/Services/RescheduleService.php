<?php

namespace App\Services;

use App\Core\Database;
use App\Models\Loan;
use App\Models\LoanRescheduleSchedule;

/**
 * Recalculates a loan's remaining amortization schedule against a new term
 * (and optionally a waived amount), then swaps it into loan_schedules once
 * approved. Reuses LoanScheduleService::generate() unchanged -- a reschedule
 * is just "generate a fresh schedule from the outstanding balance instead of
 * the original principal," which that method already supports by passing
 * namfisaLevyRate=0/dutyStampAmount=0 (those statutory charges were already
 * raised in full on the original loan and are not re-charged/re-interested
 * on reschedule). Any portion of that original levy/duty stamp the borrower
 * had not yet paid (normally still sitting on installment #1) is carried
 * forward as a flat addition onto the new schedule's first installment --
 * see uncollectedStatutoryCharges() -- so it isn't silently dropped when
 * that unpaid installment is replaced.
 */
class RescheduleService
{
    /**
     * Sum of namfisa_levy_due/duty_stamp_due not yet collected across the
     * loan's not-yet-fully-paid installments -- i.e. the portion that will
     * be lost if those rows are deleted/closed by implement() without being
     * re-added to the new schedule.
     *
     * @return array{levy: float, stamp: float}
     */
    private static function uncollectedStatutoryCharges(int $loanId): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT COALESCE(SUM(namfisa_levy_due - namfisa_levy_paid), 0) AS levy,
                    COALESCE(SUM(duty_stamp_due - duty_stamp_paid), 0) AS stamp
             FROM loan_schedules WHERE loan_id = ? AND status != 'Paid'"
        );
        $stmt->execute([$loanId]);
        $row = $stmt->fetch();

        return [
            'levy' => round((float) $row['levy'], 2),
            'stamp' => round((float) $row['stamp'], 2),
        ];
    }

    /**
     * Sum of principal still owed across every not-yet-fully-paid
     * installment, regardless of whether it's overdue yet. Deliberately not
     * ArrearsService::loanOutstanding(), which only returns a non-zero
     * figure for loans that are already overdue -- a reschedule must also
     * work for a loan that's still on time (e.g. a borrower asking for
     * smaller installments before they fall behind).
     */
    public static function outstandingPrincipal(int $loanId): float
    {
        $db = Database::connection();
        $stmt = $db->prepare("SELECT COALESCE(SUM(principal_due - principal_paid), 0) FROM loan_schedules WHERE loan_id = ? AND status != 'Paid'");
        $stmt->execute([$loanId]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Count of installment_no slots that stay occupied by the loan's
     * existing rows -- both already-Paid rows AND Partial rows (which
     * implement() closes out in place rather than deleting, so they keep
     * their installment_no). The new schedule must be numbered starting
     * after this count, or it collides with unique_loan_installment.
     */
    public static function retainedInstallmentCount(int $loanId): int
    {
        $db = Database::connection();
        $stmt = $db->prepare("SELECT COUNT(*) FROM loan_schedules WHERE loan_id = ? AND status IN ('Paid', 'Partial')");
        $stmt->execute([$loanId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array{rows: array, new_installment_amount: float, new_maturity_date: string, outstanding_balance: float}
     */
    public static function preview(array $loan, string $interestMethod, int $newTermMonths, float $waivedAmount, string $effectiveDate, ?int $paymentDay = null): array
    {
        $outstanding = self::outstandingPrincipal((int) $loan['id']);
        $basis = max(0, round($outstanding - $waivedAmount, 2));

        $schedule = LoanScheduleService::generate(
            $basis,
            $newTermMonths,
            (float) $loan['interest_rate'],
            0,
            $interestMethod,
            $effectiveDate,
            0,
            0,
            $paymentDay ?? (isset($loan['payment_day']) ? (int) $loan['payment_day'] : null)
        );

        $startingInstallmentNo = self::retainedInstallmentCount((int) $loan['id']) + 1;
        foreach ($schedule['rows'] as &$row) {
            $row['installment_no'] = $startingInstallmentNo++;
        }
        unset($row);

        $carried = self::uncollectedStatutoryCharges((int) $loan['id']);
        if (($carried['levy'] > 0 || $carried['stamp'] > 0) && !empty($schedule['rows'])) {
            $schedule['rows'][0]['namfisa_levy_due'] = round($schedule['rows'][0]['namfisa_levy_due'] + $carried['levy'], 2);
            $schedule['rows'][0]['duty_stamp_due'] = round($schedule['rows'][0]['duty_stamp_due'] + $carried['stamp'], 2);
            $schedule['rows'][0]['total_due'] = round($schedule['rows'][0]['total_due'] + $carried['levy'] + $carried['stamp'], 2);
        }

        return [
            'rows' => $schedule['rows'],
            'new_installment_amount' => $schedule['installment_amount'],
            'new_maturity_date' => end($schedule['rows'])['due_date'],
            'outstanding_balance' => $outstanding,
        ];
    }

    /**
     * Swaps the loan's remaining schedule for the approved reschedule plan.
     * Known simplification: applies the figures exactly as approved, even
     * if a payment posted between Approve and Implement shifted the true
     * outstanding balance -- staff should implement promptly after approval.
     */
    public static function implement(array $reschedule, array $newRows, int $userId): void
    {
        $db = Database::connection();
        $loanModel = new Loan();
        $rescheduleSchedules = new LoanRescheduleSchedule();
        $loanId = (int) $reschedule['loan_id'];

        $db->beginTransaction();

        try {
            $existing = $db->prepare("SELECT * FROM loan_schedules WHERE loan_id = ? AND status IN ('Pending','Partial')");
            $existing->execute([$loanId]);
            $rows = $existing->fetchAll();

            foreach ($rows as $row) {
                if ($row['status'] === 'Pending') {
                    $del = $db->prepare("DELETE FROM loan_schedules WHERE id = ?");
                    $del->execute([$row['id']]);
                } else {
                    // Partial: payment_allocations reference this row, so it
                    // is closed out in place rather than deleted -- setting
                    // total_due = total_paid removes it from every existing
                    // "outstanding" query without touching those queries.
                    $upd = $db->prepare(
                        "UPDATE loan_schedules SET principal_due = principal_paid, interest_due = interest_paid,
                         fees_due = fees_paid, namfisa_levy_due = namfisa_levy_paid, duty_stamp_due = duty_stamp_paid,
                         total_due = total_paid, status = 'Paid', paid_at = ? WHERE id = ?"
                    );
                    $upd->execute([date('Y-m-d H:i:s'), $row['id']]);
                }
            }

            $loanModel->insertScheduleRows($loanId, $newRows);
            $rescheduleSchedules->activateForReschedule((int) $reschedule['id']);

            // total_payable must reflect the schedule as it now stands --
            // retained Paid/closed-Partial rows plus the freshly inserted
            // rows (which already carry forward any previously-uncollected
            // levy/duty stamp, see preview()) -- rather than the stale
            // pre-reschedule figure, which would otherwise still include
            // interest/charges no longer present in the new schedule.
            $totalStmt = $db->prepare("SELECT COALESCE(SUM(total_due), 0) FROM loan_schedules WHERE loan_id = ?");
            $totalStmt->execute([$loanId]);
            $newTotalPayable = round((float) $totalStmt->fetchColumn(), 2);

            $loanModel->updateFields($loanId, [
                'term_months' => count($newRows) + self::retainedInstallmentCount($loanId),
                'installment_amount' => $reschedule['new_installment_amount'],
                'payment_day' => $reschedule['new_payment_day'] ?: null,
                'maturity_date' => $reschedule['new_maturity_date'],
                'total_payable' => $newTotalPayable,
            ]);
            // loans.loan_status is unaffected by a reschedule (still Active/Current) --
            // loan_status_history.new_status is free-text, not FK'd to that enum, so
            // this is purely an audit note, not a lifecycle transition.
            $loanModel->logStatus($loanId, $reschedule['loan_status'] ?? null, 'Rescheduled', $userId, 'Rescheduled via ' . $reschedule['reschedule_no'] . '.');

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
