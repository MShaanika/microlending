<?php

namespace Tests\Feature;

use App\Core\Database;
use App\Services\PayrollService;
use PHPUnit\Framework\TestCase;

/**
 * Runs against the local dev database -- see tests/bootstrap.php.
 *
 * PayrollService::run() processes every Active employee, not just a
 * test-created one -- running it for real against this dev DB's
 * existing employees would mutate real hrm_staff_loans balances
 * (StaffLoanRepayment subtracts from outstanding_balance directly;
 * that side effect doesn't undo itself just by deleting the payroll
 * row afterward). setUp() temporarily suspends any other Active
 * employees for the duration of the test and tearDown() restores them,
 * so only the one fresh test employee created here gets processed.
 */
class PayrollAccountingTest extends TestCase
{
    private array $suspendedEmployeeIds = [];
    private ?int $employeeId = null;
    private ?int $payrollId = null;

    protected function setUp(): void
    {
        $db = Database::connection();
        $this->suspendedEmployeeIds = $db->query("SELECT id FROM hrm_employees WHERE status = 'Active'")->fetchAll(\PDO::FETCH_COLUMN);
        if ($this->suspendedEmployeeIds) {
            $ids = implode(',', array_map('intval', $this->suspendedEmployeeIds));
            $db->exec("UPDATE hrm_employees SET status = 'Suspended' WHERE id IN ($ids)");
        }
    }

    protected function tearDown(): void
    {
        $db = Database::connection();

        if ($this->payrollId) {
            $journalId = $db->prepare("SELECT id FROM accounting_journal_entries WHERE source_table = 'hrm_payrolls' AND source_id = ?");
            $journalId->execute([$this->payrollId]);
            foreach ($journalId->fetchAll(\PDO::FETCH_COLUMN) as $jid) {
                $db->prepare('DELETE FROM accounting_journal_lines WHERE journal_id = ?')->execute([$jid]);
                $db->prepare('DELETE FROM accounting_journal_entries WHERE id = ?')->execute([$jid]);
            }
            $db->prepare('DELETE FROM hrm_payrolls WHERE id = ?')->execute([$this->payrollId]);
        }
        if ($this->employeeId) {
            $db->prepare('DELETE FROM hrm_employees WHERE id = ?')->execute([$this->employeeId]);
        }

        if ($this->suspendedEmployeeIds) {
            $ids = implode(',', array_map('intval', $this->suspendedEmployeeIds));
            $db->exec("UPDATE hrm_employees SET status = 'Active' WHERE id IN ($ids)");
        }
    }

    public function testCompletingAPayrollPostsABalancedJournalToTheGeneralLedger(): void
    {
        $db = Database::connection();

        $db->prepare(
            "INSERT INTO hrm_employees (employee_no, first_name, last_name, status, basic_salary)
             VALUES (?, 'PHPUnit', 'TestEmployee', 'Active', 6000.00)"
        )->execute(['PHPUNIT-EMP-' . uniqid()]);
        $this->employeeId = (int) $db->lastInsertId();

        $db->prepare(
            "INSERT INTO hrm_payrolls (title, payroll_frequency, pay_period_start, pay_period_end, pay_date, status)
             VALUES (?, 'Monthly', '2020-01-01', '2020-01-31', '2020-02-01', 'Draft')"
        )->execute(['PHPUnit Test Payroll ' . uniqid()]);
        $this->payrollId = (int) $db->lastInsertId();

        $result = PayrollService::run($this->payrollId, null);
        $this->assertSame(1, $result['new_entries']);

        $payroll = $db->prepare('SELECT * FROM hrm_payrolls WHERE id = ?');
        $payroll->execute([$this->payrollId]);
        $payroll = $payroll->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('Completed', $payroll['status']);
        $this->assertGreaterThan(0, (float) $payroll['total_gross_pay']);

        $stmt = $db->prepare("SELECT * FROM accounting_journal_entries WHERE source_table = 'hrm_payrolls' AND source_id = ?");
        $stmt->execute([$this->payrollId]);
        $journals = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $journals, 'Expected exactly one journal entry for this payroll.');
        $journal = $journals[0];
        $this->assertSame('Posted', $journal['status']);
        $this->assertSame('Automatic', $journal['journal_type']);
        $this->assertSame('PAYROLL_RUN', $journal['source_module']);

        $lines = $db->prepare('SELECT l.*, a.account_code FROM accounting_journal_lines l JOIN accounting_accounts a ON a.id = l.account_id WHERE l.journal_id = ?');
        $lines->execute([$journal['id']]);
        $lines = $lines->fetchAll(\PDO::FETCH_ASSOC);

        $totalDebit = array_sum(array_column($lines, 'debit'));
        $totalCredit = array_sum(array_column($lines, 'credit'));
        $this->assertEqualsWithDelta($totalDebit, $totalCredit, 0.01, 'Journal must balance.');
        $this->assertEqualsWithDelta((float) $payroll['total_gross_pay'], $totalDebit, 0.01);

        $salaryExpenseLine = current(array_filter($lines, fn ($l) => $l['account_code'] === '5040'));
        $this->assertNotFalse($salaryExpenseLine, 'Expected a 5040 Salary Expenses debit line.');
        $this->assertEqualsWithDelta((float) $payroll['total_gross_pay'], (float) $salaryExpenseLine['debit'], 0.01);
    }

    public function testZeroSalaryEmployeeNeverProducesAnEmptyJournalEntry(): void
    {
        $db = Database::connection();

        $db->prepare(
            "INSERT INTO hrm_employees (employee_no, first_name, last_name, status, basic_salary)
             VALUES (?, 'PHPUnit', 'ZeroSalary', 'Active', 0.00)"
        )->execute(['PHPUNIT-EMP-' . uniqid()]);
        $this->employeeId = (int) $db->lastInsertId();

        $db->prepare(
            "INSERT INTO hrm_payrolls (title, payroll_frequency, pay_period_start, pay_period_end, pay_date, status)
             VALUES (?, 'Monthly', '2020-03-01', '2020-03-31', '2020-04-01', 'Draft')"
        )->execute(['PHPUnit Test Payroll Zero ' . uniqid()]);
        $this->payrollId = (int) $db->lastInsertId();

        $result = PayrollService::run($this->payrollId, null);
        $this->assertSame(1, $result['new_entries']);

        $stmt = $db->prepare("SELECT COUNT(*) FROM accounting_journal_entries WHERE source_table = 'hrm_payrolls' AND source_id = ?");
        $stmt->execute([$this->payrollId]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'A payroll with zero total gross pay must not post an empty journal entry.');
    }

    public function testRunningPayrollTwiceNeverPostsASecondJournal(): void
    {
        $db = Database::connection();

        $db->prepare(
            "INSERT INTO hrm_employees (employee_no, first_name, last_name, status, basic_salary)
             VALUES (?, 'PHPUnit', 'TestEmployee2', 'Active', 5000.00)"
        )->execute(['PHPUNIT-EMP-' . uniqid()]);
        $this->employeeId = (int) $db->lastInsertId();

        $db->prepare(
            "INSERT INTO hrm_payrolls (title, payroll_frequency, pay_period_start, pay_period_end, pay_date, status)
             VALUES (?, 'Monthly', '2020-02-01', '2020-02-29', '2020-03-01', 'Draft')"
        )->execute(['PHPUnit Test Payroll 2 ' . uniqid()]);
        $this->payrollId = (int) $db->lastInsertId();

        PayrollService::run($this->payrollId, null);
        // Second call is what a direct-service re-run looks like (the
        // controller itself blocks this once Completed via a UI-layer
        // check, but postPayrollAccounting()'s own idempotency guard is
        // what actually prevents a double-post here, not the controller).
        PayrollService::run($this->payrollId, null);

        $stmt = $db->prepare("SELECT COUNT(*) FROM accounting_journal_entries WHERE source_table = 'hrm_payrolls' AND source_id = ?");
        $stmt->execute([$this->payrollId]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }
}
