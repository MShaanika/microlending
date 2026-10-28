<?php

namespace Tests\Feature;

use App\Core\Database;
use App\Models\DebitOrder;
use App\Models\DebitOrderSplitLegInstallmentPart;
use App\Models\Loan;
use App\Models\Payment;
use App\Services\CollexiaMandateLookupService;
use App\Services\DebitOrderCollectionPoster;
use App\Services\MandateCollectionCheck;
use PHPUnit\Framework\TestCase;

/**
 * ONE Collexia debit for an original loan and its top-up: the combined registration list, the family-wide live-order guard, the part-aware
 * paid-by-hand check, and -- the money path -- that a collection is split across the loan and its top-up (or posted on the top-up's own row)
 * instead of ever landing as the original's overpayment. Payment posting itself is stubbed here (it writes journals); real posting is covered by
 * PaymentSplitServiceTest and the manual end-to-end run. Throw-away rows, removed afterwards.
 */
class CombinedDebitOrderTest extends TestCase
{
    private \PDO $db;
    private array $loanIds = [];
    private ?int $debitOrderId = null;
    private int $borrowerId;
    private int $branchId;
    private int $productId;
    private int $planId;
    private int $parent;
    private int $child;
    private array $parentRows = [];
    private array $childRows = [];

    protected function setUp(): void
    {
        $this->db = Database::connection();
        $b = $this->db->query('SELECT id, branch_id FROM borrowers ORDER BY id LIMIT 1')->fetch();
        $p = $this->db->query('SELECT p.id AS product_id, l.id AS plan_id FROM loan_products p JOIN loan_plans l ON l.product_id = p.id ORDER BY p.id LIMIT 1')->fetch();
        $this->borrowerId = (int) $b['id'];
        $this->branchId = (int) $b['branch_id'];
        $this->productId = (int) $p['product_id'];
        $this->planId = (int) $p['plan_id'];

        // original: 2 instalments (30 Jan, 28 Feb 2027), top-up: 2 instalments on the same dates
        $this->parent = $this->loan('Active', null, ['2027-01-30', '2027-02-28'], 1000);
        $this->child = $this->loan('Active', $this->parent, ['2027-01-30', '2027-02-28'], 657.14);
        $this->parentRows = $this->rows($this->parent);
        $this->childRows = $this->rows($this->child);
    }

    protected function tearDown(): void
    {
        if ($this->debitOrderId) {
            $this->db->prepare('DELETE FROM debit_order_split_leg_installment_parts WHERE installment_id IN (SELECT li.id FROM debit_order_split_leg_installments li JOIN debit_order_split_legs sl ON sl.id = li.leg_id WHERE sl.debit_order_id = ?)')->execute([$this->debitOrderId]);
            $this->db->prepare('DELETE FROM debit_order_split_leg_installments WHERE leg_id IN (SELECT id FROM debit_order_split_legs WHERE debit_order_id = ?)')->execute([$this->debitOrderId]);
            $this->db->prepare('DELETE FROM debit_order_split_legs WHERE debit_order_id = ?')->execute([$this->debitOrderId]);
            $this->db->prepare('DELETE FROM debit_orders WHERE id = ?')->execute([$this->debitOrderId]);
        }
        foreach (array_reverse($this->loanIds) as $id) {
            $this->db->prepare('DELETE FROM loan_schedules WHERE loan_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM loans WHERE id = ?')->execute([$id]);
        }
    }

    private function loan(string $status, ?int $topupOf, array $dates, float $installment): int
    {
        $this->db->prepare(
            "INSERT INTO loans (branch_id, borrower_id, product_id, plan_id, loan_no, principal_amount, interest_rate, interest_recognition_method, term_months, installment_amount, total_payable, payment_day, loan_status, topup_of_loan_id, start_date, maturity_date)
             VALUES (?, ?, ?, ?, ?, 2000, 30, 'Upfront', ?, ?, ?, 30, ?, ?, '2026-10-30', '2027-06-30')"
        )->execute([$this->branchId, $this->borrowerId, $this->productId, $this->planId, 'PHPUNIT-' . uniqid(), count($dates), $installment, $installment * count($dates), $status, $topupOf]);
        $id = (int) $this->db->lastInsertId();
        $this->loanIds[] = $id;
        foreach ($dates as $i => $d) {
            $this->db->prepare(
                'INSERT INTO loan_schedules (loan_id, installment_no, due_date, principal_due, principal_paid, interest_due, interest_paid, total_due, total_paid, status)
                 VALUES (?, ?, ?, ?, 0, 0, 0, ?, 0, ?)'
            )->execute([$id, $i + 1, $d, $installment, $installment, 'Pending']);
        }
        return $id;
    }

    private function rows(int $loanId): array
    {
        return $this->db->query("SELECT id, loan_id, installment_no, due_date FROM loan_schedules WHERE loan_id = $loanId ORDER BY installment_no")->fetchAll();
    }

    /** A live combined debit order: one lane, instalment 1 = both loans' first rows (combined), instalment 2 = both loans' second rows. */
    private function registerCombined(): array
    {
        $this->db->prepare(
            "INSERT INTO debit_orders (borrower_id, loan_id, debit_order_no, bank_name, account_number, debit_day, debit_amount, start_date, status, id_type, account_type, bank_code, no_of_days_tracking, split_enabled)
             VALUES (?, ?, ?, 'FNB', '123', 30, 1657.14, '2027-01-30', 'Active', 1, 1, '250655', 3, 1)"
        )->execute([$this->borrowerId, $this->parent, 'DO-TEST-' . uniqid()]);
        $this->debitOrderId = (int) $this->db->lastInsertId();
        $this->db->prepare("INSERT INTO debit_order_split_legs (debit_order_id, split_no, leg_amount, total_splits, collexia_api_status, collexia_api_contract_reference) VALUES (?, 1, 1657.14, 1, 'Registered', ?)")
            ->execute([$this->debitOrderId, 'PHPUNIT-REF-' . uniqid()]);
        $legId = (int) $this->db->lastInsertId();
        $parts = new DebitOrderSplitLegInstallmentPart();
        $installmentIds = [];
        foreach ([0, 1] as $i) {
            $this->db->prepare("INSERT INTO debit_order_split_leg_installments (leg_id, installment_no, schedule_id, planned_date, planned_amount) VALUES (?, ?, ?, ?, 1657.14)")
                ->execute([$legId, $i + 1, $this->parentRows[$i]['id'], $this->parentRows[$i]['due_date']]);
            $liId = (int) $this->db->lastInsertId();
            $installmentIds[] = $liId;
            $parts->add($liId, $this->parent, (int) $this->parentRows[$i]['id'], 1000.0);
            $parts->add($liId, $this->child, (int) $this->childRows[$i]['id'], 657.14);
        }
        return ['leg_id' => $legId, 'installment_ids' => $installmentIds];
    }

    public function testTheRegisterListHasOneRowPerDueDateWithTheCombinedAmount(): void
    {
        $tranches = (new Loan())->tranchesOf($this->parent);
        $rows = (new DebitOrder())->unpaidCombinedInstallments($tranches);

        $this->assertSame(['2027-01-30', '2027-02-28'], array_column($rows, 'due_date'));
        $this->assertSame([1657.14, 1657.14], array_column($rows, 'outstanding'), 'one debit for the original and the top-up together');
        $this->assertSame((int) $this->parentRows[0]['id'], $rows[0]['id'], 'the primary row is the original loan\'s');
        $this->assertSame([(int) $this->parentRows[0]['id'], (int) $this->childRows[0]['id']], array_column($rows[0]['parts'], 'schedule_id'), 'original first, then the top-up');
    }

    public function testOneLiveDebitOrderCoversTheWholeFamily(): void
    {
        $this->registerCombined();
        $orders = new DebitOrder();
        $this->assertNotNull($orders->liveForFamily($this->parent));
        $this->assertNotNull($orders->liveForFamily($this->child), 'a top-up cannot take a second debit order next to the original\'s');
        $this->assertSame($this->debitOrderId, (int) $orders->liveForFamily($this->child)['id']);
    }

    public function testATopUpCoveredByTheOriginalsDebitOrderIsNotMissingOne(): void
    {
        $orders = new DebitOrder();
        $before = array_column($orders->activeLoansMissingDebitOrder(), 'id');
        $this->assertContains((string) $this->child, array_map('strval', $before), 'with no debit order anywhere the top-up is listed as missing');

        $this->registerCombined();
        $after = array_map('strval', array_column($orders->activeLoansMissingDebitOrder(), 'id'));
        $this->assertNotContains((string) $this->child, $after, 'covered by the original loan\'s debit order');
    }

    public function testACombinedCollectionIsSplitAcrossTheOriginalAndTheTopUp(): void
    {
        $ids = $this->registerCombined();
        $leg = $this->db->query('SELECT * FROM debit_order_split_legs WHERE id = ' . $ids['leg_id'])->fetch();
        $mandate = (new CollexiaMandateLookupService())->resolve($leg['collexia_api_contract_reference']);
        $this->assertNotNull($mandate);

        $spy = new class extends Payment {
            public array $calls = [];
            public function recordCollectionParts(array $parts, float $amount, array $meta): array
            {
                $this->calls[] = ['parts' => $parts, 'amount' => $amount];
                return [11 => 1];
            }
            public function recordAndAllocateToScheduleId(array $loan, int $scheduleId, float $amount, array $meta): int
            {
                $this->calls[] = ['single' => $scheduleId];
                return 2;
            }
        };
        $poster = new DebitOrderCollectionPoster($spy, new Loan(), new CollexiaMandateLookupService());
        $paymentId = $poster->post($mandate, 1, 1657.14, ['payment_date' => '2027-01-30', 'user_id' => 1]);

        $this->assertSame(1, $paymentId);
        $this->assertCount(1, $spy->calls);
        $this->assertSame(1657.14, $spy->calls[0]['amount']);
        $this->assertSame([$this->parent, $this->child], array_map('intval', array_column($spy->calls[0]['parts'], 'loan_id')), 'both loans, original first');
        $this->assertArrayNotHasKey('single', $spy->calls[0], 'never posted as a single row of the original loan');
    }

    public function testARowThatBelongsToATopUpIsPostedOnTheTopUpNotAsOverpaymentOnTheOriginal(): void
    {
        $ids = $this->registerCombined();
        // a date where ONLY the top-up owes: an ordinary (single-part) lane instalment aimed at the top-up's row
        $this->db->prepare("DELETE FROM debit_order_split_leg_installment_parts WHERE installment_id = ?")->execute([$ids['installment_ids'][1]]);
        $this->db->prepare("UPDATE debit_order_split_leg_installments SET schedule_id = ? WHERE id = ?")->execute([$this->childRows[1]['id'], $ids['installment_ids'][1]]);
        $leg = $this->db->query('SELECT * FROM debit_order_split_legs WHERE id = ' . $ids['leg_id'])->fetch();
        $mandate = (new CollexiaMandateLookupService())->resolve($leg['collexia_api_contract_reference']);

        $spy = new class extends Payment {
            public array $calls = [];
            public function recordAndAllocateToScheduleId(array $loan, int $scheduleId, float $amount, array $meta): int
            {
                $this->calls[] = ['loan' => (int) $loan['id'], 'schedule' => $scheduleId];
                return 5;
            }
        };
        $paymentId = (new DebitOrderCollectionPoster($spy, new Loan(), new CollexiaMandateLookupService()))->post($mandate, 2, 657.14, ['payment_date' => '2027-02-28', 'user_id' => 1]);

        $this->assertSame(5, $paymentId);
        $this->assertSame([['loan' => $this->child, 'schedule' => (int) $this->childRows[1]['id']]], $spy->calls, 'posted on the top-up\'s own loan');
    }

    public function testACombinedInstalmentIsOnlyPaidWhenEveryPartIsPaid(): void
    {
        $this->registerCombined();
        $check = new MandateCollectionCheck();

        // the original's first row is paid by hand, the top-up's is not
        $this->db->prepare("UPDATE loan_schedules SET total_paid = total_due, status = 'Paid' WHERE id = ?")->execute([$this->parentRows[0]['id']]);
        $this->db->prepare("UPDATE debit_orders SET collexia_api_status = 'Registered' WHERE id = ?")->execute([$this->debitOrderId]);
        $result = $check->forLoan($this->parent, '2026-12-01');
        $this->assertNotEmpty($result['upcoming'], 'the live combined mandate is seen');
        $this->assertSame([], $result['paidButScheduled'], 'one part still owes, so the combined debit must NOT be stopped');

        // now the top-up's first row is paid too
        $this->db->prepare("UPDATE loan_schedules SET total_paid = total_due, status = 'Paid' WHERE id = ?")->execute([$this->childRows[0]['id']]);
        $result = $check->forLoan($this->parent, '2026-12-01');
        $this->assertCount(1, $result['paidButScheduled'], 'every part paid: the first combined debit is paid but still scheduled');
        $this->assertSame('2027-01-30', $result['paidButScheduled'][0]['collects_on']);

        // and the same answer from the top-up's own page
        $fromChild = $check->forLoan($this->child, '2026-12-01');
        $this->assertCount(1, $fromChild['paidButScheduled']);
    }
}
