<?php

namespace Tests\Feature;

use App\Core\Database;
use App\Models\Loan;
use PHPUnit\Framework\TestCase;

/**
 * A released top-up is shown inside its original loan: one row in the Loans list (with combined amounts and arrears), and a combined summary
 * and schedule on the original's page. Nothing is stored or posted for this -- the figures come from each tranche's own schedule -- so the test
 * only needs throw-away loans, removed afterwards.
 */
class CombinedTopupViewTest extends TestCase
{
    private \PDO $db;
    private array $loanIds = [];
    private int $borrowerId;
    private int $branchId;
    private int $productId;
    private int $planId;

    protected function setUp(): void
    {
        $this->db = Database::connection();
        $b = $this->db->query('SELECT id, branch_id FROM borrowers ORDER BY id LIMIT 1')->fetch();
        $p = $this->db->query('SELECT p.id AS product_id, l.id AS plan_id FROM loan_products p JOIN loan_plans l ON l.product_id = p.id ORDER BY p.id LIMIT 1')->fetch();
        $this->borrowerId = (int) $b['id'];
        $this->branchId = (int) $b['branch_id'];
        $this->productId = (int) $p['product_id'];
        $this->planId = (int) $p['plan_id'];
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->loanIds) as $id) {
            $this->db->prepare('DELETE FROM loan_schedules WHERE loan_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM loans WHERE id = ?')->execute([$id]);
        }
    }

    private function loan(string $status, ?int $topupOf, float $principal, array $dueDates, float $paidFirst = 0.0): int
    {
        $this->db->prepare(
            "INSERT INTO loans (branch_id, borrower_id, product_id, plan_id, loan_no, principal_amount, interest_rate, interest_recognition_method, term_months, installment_amount, total_payable, payment_day, loan_status, topup_of_loan_id, start_date, maturity_date)
             VALUES (?, ?, ?, ?, ?, ?, 30, 'Upfront', ?, 1000, ?, 30, ?, ?, '2026-10-30', '2027-06-30')"
        )->execute([$this->branchId, $this->borrowerId, $this->productId, $this->planId, 'PHPUNIT-' . uniqid(), $principal, count($dueDates), $principal * 1.3, $status, $topupOf]);
        $id = (int) $this->db->lastInsertId();
        $this->loanIds[] = $id;
        foreach ($dueDates as $i => $date) {
            $paid = $i === 0 ? $paidFirst : 0.0;
            $this->db->prepare(
                'INSERT INTO loan_schedules (loan_id, installment_no, due_date, principal_due, principal_paid, interest_due, interest_paid, total_due, total_paid, status)
                 VALUES (?, ?, ?, 800, 0, 200, 0, 1000, ?, ?)'
            )->execute([$id, $i + 1, $date, $paid, $paid >= 1000 ? 'Paid' : 'Pending']);
        }
        return $id;
    }

    public function testTranchesAreSummedFromTheirOwnSchedules(): void
    {
        $parent = $this->loan('Active', null, 3000, ['2027-01-30', '2027-02-28'], 1000.0);
        $child = $this->loan('Active', $parent, 1000, ['2027-02-28', '2027-03-30']);
        $pending = $this->loan('Pending Approval', $parent, 500, ['2027-04-30']);

        $loans = new Loan();
        $tranches = $loans->tranchesOf($parent);
        $this->assertSame([$parent, $child], array_map('intval', array_column($tranches, 'id')), 'original first, only released top-ups, never a pending one');
        $this->assertSame(4000.0, round(array_sum(array_column($tranches, 'principal_amount')), 2));
        $this->assertSame(1000.0, round(array_sum(array_column($tranches, 'schedule_paid')), 2));
        $this->assertSame(3000.0, round(array_sum(array_column($tranches, 'schedule_due')) - array_sum(array_column($tranches, 'schedule_paid')), 2), 'combined outstanding');
        $this->assertSame([], $loans->tranchesOf($pending), 'a loan with no top-ups has no combined view');

        $dates = array_column($loans->combinedSchedule([$parent, $child]), 'due_date');
        $this->assertSame(['2027-01-30', '2027-02-28', '2027-02-28', '2027-03-30'], $dates, 'one list ordered by due date');
    }

    public function testTheListShowsOneRowPerOriginalLoanWithCombinedAmountsAndArrears(): void
    {
        $parent = $this->loan('Active', null, 3000, ['2099-01-30']);
        $child = $this->loan('Active', $parent, 1000, ['2020-01-30']); // overdue
        $loans = new Loan();

        $grouped = $loans->paginated('', '', 5000, null, true);
        $ids = array_map('intval', array_column($grouped, 'id'));
        $this->assertContains($parent, $ids);
        $this->assertNotContains($child, $ids, 'a released top-up is shown inside its original loan');
        $row = $grouped[array_search($parent, $ids, true)];
        $this->assertSame(1, (int) $row['topup_count']);
        $this->assertSame(4000.0, (float) $row['combined_principal']);
        $this->assertSame(1, (int) $row['is_in_arrears'], 'the original shows the top-up\'s arrears');

        $arrear = array_map('intval', array_column($loans->paginated('', 'Arrear', 5000, null, true), 'id'));
        $this->assertContains($parent, $arrear, 'the Arrear filter finds the original through its top-up');

        $ungrouped = array_map('intval', array_column($loans->paginated('', '', 5000), 'id'));
        $this->assertContains($child, $ungrouped, 'every other screen still sees one row per loan');

        $searched = array_map('intval', array_column($loans->paginated('PHPUNIT', '', 5000, null, true), 'id'));
        $this->assertContains($child, $searched, 'searching still finds a top-up by its number');
    }
}
