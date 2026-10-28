<?php

namespace Tests\Feature;

use App\Core\Database;
use App\Services\RescheduleService;
use PHPUnit\Framework\TestCase;

/**
 * Implementing a reschedule must number the new instalments straight after the rows the loan still holds, whatever the loan's
 * interest method. Regression for loan 8 (production, 2026-10-21): an Upfront loan with one Paid row and four Pending rows. An
 * Upfront loan keeps every row (its Pending rows are closed out in place, never deleted), but the new schedule was numbered
 * from the Paid/Partial count -- "Duplicate entry '8-2' for key 'unique_loan_installment'".
 *
 * Runs against the local dev database with its own throw-away loan, removed afterwards.
 */
class RescheduleNumberingTest extends TestCase
{
    private \PDO $db;
    private array $loanIds = [];
    private int $borrowerId;
    private int $branchId;
    private int $productId;
    private int $planId;
    private int $userId;

    protected function setUp(): void
    {
        $this->db = Database::connection();
        $b = $this->db->query('SELECT id, branch_id FROM borrowers ORDER BY id LIMIT 1')->fetch();
        $p = $this->db->query('SELECT p.id AS product_id, l.id AS plan_id FROM loan_products p JOIN loan_plans l ON l.product_id = p.id ORDER BY p.id LIMIT 1')->fetch();
        $this->borrowerId = (int) $b['id'];
        $this->branchId = (int) $b['branch_id'];
        $this->productId = (int) $p['product_id'];
        $this->planId = (int) $p['plan_id'];
        $this->userId = (int) $this->db->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
    }

    protected function tearDown(): void
    {
        foreach ($this->loanIds as $id) {
            foreach (['loan_status_history', 'loan_reschedule_schedules'] as $t) {
                $this->db->prepare("DELETE FROM $t WHERE loan_id = ?")->execute([$id]);
            }
            $this->db->prepare('DELETE FROM loan_reschedules WHERE loan_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM loan_schedules WHERE loan_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM loans WHERE id = ?')->execute([$id]);
        }
    }

    /** A 5-instalment loan: row 1 Paid, rows 2-5 Pending (row 2 optionally Partial). */
    private function makeLoan(string $method, bool $row2Partial = false): int
    {
        $this->db->prepare(
            "INSERT INTO loans (branch_id, borrower_id, product_id, plan_id, loan_no, principal_amount, interest_rate, interest_recognition_method, term_months, installment_amount, payment_day, loan_status, start_date, maturity_date)
             VALUES (?, ?, ?, ?, ?, 10000, 30, ?, 5, 2628.08, 30, 'Active', '2026-10-30', '2027-06-30')"
        )->execute([$this->branchId, $this->borrowerId, $this->productId, $this->planId, 'PHPUNIT-' . uniqid(), $method]);
        $loanId = (int) $this->db->lastInsertId();
        $this->loanIds[] = $loanId;

        $due = ['2027-02-26', '2027-03-30', '2027-04-30', '2027-05-30', '2027-06-30'];
        foreach ($due as $i => $date) {
            $no = $i + 1;
            $status = $no === 1 ? 'Paid' : (($row2Partial && $no === 2) ? 'Partial' : 'Pending');
            $paid = $no === 1 ? 2021.60 : (($row2Partial && $no === 2) ? 500.00 : 0);
            $this->db->prepare(
                'INSERT INTO loan_schedules (loan_id, installment_no, due_date, principal_due, principal_paid, interest_due, interest_paid, total_due, total_paid, status)
                 VALUES (?, ?, ?, 2021.60, ?, 606.48, ?, 2628.08, ?, ?)'
            )->execute([$loanId, $no, $date, min($paid, 2021.60), $no === 1 ? 606.48 : 0, $no === 1 ? 2628.08 : $paid, $status]);
        }
        return $loanId;
    }

    private function approvedReschedule(int $loanId, array $planRows, int $term = 10): array
    {
        $this->db->prepare(
            "INSERT INTO loan_reschedules (loan_id, borrower_id, branch_id, reschedule_no, request_date, effective_date, reason, status, new_term_months, new_installment_amount, new_payment_day, new_maturity_date)
             VALUES (?, ?, ?, ?, '2026-10-21', '2026-10-21', 'phpunit', 'Approved', ?, 856.23, 30, '2027-08-30')"
        )->execute([$loanId, $this->borrowerId, $this->branchId, 'RSC-PHPUNIT-' . uniqid(), $term]);
        $id = (int) $this->db->lastInsertId();
        return ['id' => $id, 'loan_id' => $loanId, 'reschedule_no' => 'RSC-PHPUNIT', 'new_installment_amount' => 856.23, 'new_payment_day' => 30, 'new_maturity_date' => '2027-08-30', 'loan_status' => 'Active'];
    }

    private function plan(int $loanId, int $term = 10): array
    {
        $loan = $this->db->query('SELECT * FROM loans WHERE id = ' . $loanId)->fetch();
        return RescheduleService::preview($loan, 'Reducing Balance', $term, 0.0, '2026-10-21')['rows'];
    }

    private function numbers(int $loanId): array
    {
        return array_map('intval', $this->db->query("SELECT installment_no FROM loan_schedules WHERE loan_id = $loanId ORDER BY installment_no")->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testAnUpfrontLoanKeepsEveryRowSoNewRowsAreNumberedAfterAllOfThem(): void
    {
        $loanId = $this->makeLoan('Upfront');
        $rows = $this->plan($loanId);
        $this->assertSame(6, $rows[0]['installment_no'], 'the preview already numbers after the 5 rows an Upfront loan keeps');
        $this->assertSame(5, RescheduleService::retainedInstallmentCount($loanId));
    }

    public function testImplementingOnAnUpfrontLoanDoesNotCollideEvenWithStaleNumbersFromTheApprovedPlan(): void
    {
        $loanId = $this->makeLoan('Upfront');
        $rows = $this->plan($loanId);
        // The plan stored at request time carried numbers from the OLD count (Paid/Partial only): 2..11. This is exactly loan 8.
        foreach ($rows as $i => &$row) {
            $row['installment_no'] = $i + 2;
        }
        unset($row);

        RescheduleService::implement($this->approvedReschedule($loanId, $rows), $rows, $this->userId);

        $this->assertSame(range(1, 15), $this->numbers($loanId), 'rows 1-5 kept (closed out) and the 10 new rows numbered 6-15, no gaps or duplicates');
        $this->assertSame(15, (int) $this->db->query("SELECT term_months FROM loans WHERE id = $loanId")->fetchColumn());

        $closed = $this->db->query("SELECT COUNT(*) FROM loan_schedules WHERE loan_id = $loanId AND installment_no BETWEEN 2 AND 5 AND status = 'Paid' AND total_due = total_paid")->fetchColumn();
        $this->assertSame(4, (int) $closed, 'the old Pending rows are closed out, owing nothing');
        $pending = (int) $this->db->query("SELECT COUNT(*) FROM loan_schedules WHERE loan_id = $loanId AND status = 'Pending'")->fetchColumn();
        $this->assertSame(10, $pending);
    }

    public function testImplementingOnAProgressiveLoanStillWorks(): void
    {
        $loanId = $this->makeLoan('Progressive');
        $rows = $this->plan($loanId);
        $this->assertSame(2, $rows[0]['installment_no'], 'Pending rows are deleted for a Progressive loan, so numbering resumes after the Paid row');

        RescheduleService::implement($this->approvedReschedule($loanId, $rows), $rows, $this->userId);

        $this->assertSame(range(1, 11), $this->numbers($loanId));
        $this->assertSame(11, (int) $this->db->query("SELECT term_months FROM loans WHERE id = $loanId")->fetchColumn());
    }

    public function testAPartiallyPaidRowKeepsItsSlotOnAProgressiveLoan(): void
    {
        $loanId = $this->makeLoan('Progressive', true);
        $rows = $this->plan($loanId);
        $this->assertSame(3, $rows[0]['installment_no'], 'rows 1 (Paid) and 2 (Partial) are retained');

        RescheduleService::implement($this->approvedReschedule($loanId, $rows), $rows, $this->userId);

        $this->assertSame(range(1, 12), $this->numbers($loanId));
    }

    public function testTotalOutstandingIsEverythingOwedWhilePrincipalIsOnlyThePrincipalPart(): void
    {
        $loanId = $this->makeLoan('Upfront');
        // Rows 2-5 each still owe 2,628.08 (principal 2,021.60 + interest 606.48); row 1 is fully paid.
        $this->assertSame(10512.32, RescheduleService::totalOutstanding($loanId), 'the whole amount owed');
        $this->assertSame(8086.40, RescheduleService::outstandingPrincipal($loanId), 'the principal part only, which the new schedule is recalculated on');
    }

    public function testWithNoWaiverTheNewPlanTotalsExactlyWhatIsOwedToday(): void
    {
        $loanId = $this->makeLoan('Upfront');
        $loan = $this->db->query('SELECT * FROM loans WHERE id = ' . $loanId)->fetch();
        $none = RescheduleService::preview($loan, 'Flat', 10, 0.0, '2026-10-21');
        $this->assertSame(10512.32, round(array_sum(array_column($none['rows'], 'total_due')), 2), 'N$8,086.40 principal + 30% flat interest = what is owed today');
        $waived = RescheduleService::preview($loan, 'Flat', 10, 1500.0, '2026-10-21');
        $this->assertSame(8562.32, round(array_sum(array_column($waived['rows'], 'total_due')), 2), 'a N$1,500 waiver is what produced the 8,562.32 shown on reschedule #2');
    }

    public function testTheEffectiveDateIsTheFirstDeductionDate(): void
    {
        $loanId = $this->makeLoan('Upfront');
        $loan = $this->db->query('SELECT * FROM loans WHERE id = ' . $loanId)->fetch();
        $rows = RescheduleService::preview($loan, 'Flat', 4, 0.0, '2026-11-05', 30)['rows'];
        $this->assertSame('2026-11-05', $rows[0]['due_date'], 'installment 1 falls on the effective date itself, not a month later');
        $this->assertSame(['2026-11-05', '2026-12-30', '2027-01-30', '2027-02-28'], array_column($rows, 'due_date'), 'the rest follow on the payment day');
    }
}
