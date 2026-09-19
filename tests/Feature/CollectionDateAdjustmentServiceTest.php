<?php

namespace Tests\Feature;

use App\Core\Database;
use App\Models\PayCyclePolicy;
use App\Models\PublicHoliday;
use App\Services\BusinessCalendarService;
use App\Services\CollectionDateAdjustmentService;
use App\Services\PayCycleResolverService;
use PHPUnit\Framework\TestCase;

/**
 * Runs against the local dev database (same pattern as
 * ApprovalServiceTest) -- creates throwaway borrower/policy/holiday
 * fixtures, never touches real data, cleans up in tearDown().
 *
 * Reproduces the exact scenario from the request: a Government Payroll
 * policy (pay day 20, previous business day), 20 September 2026 falling
 * on a Sunday -> collection proposed for 18 September 2026 (Friday),
 * while the contractual due date stays 20 September untouched.
 */
class CollectionDateAdjustmentServiceTest extends TestCase
{
    private \PDO $db;
    private int $borrowerId;
    private int $branchId;
    private int $policyId;
    private array $extraPolicyIds = [];
    private array $holidayIds = [];

    protected function setUp(): void
    {
        $this->db = Database::connection();
        $this->branchId = (int) $this->db->query("SELECT id FROM branches LIMIT 1")->fetchColumn();

        $this->db->prepare(
            "INSERT INTO borrowers (branch_id, borrower_no, first_name, last_name, status) VALUES (?, ?, 'PHPUnit', 'Borrower', 'Approved')"
        )->execute([$this->branchId, 'PHPUNIT-CDA-' . uniqid()]);
        $this->borrowerId = (int) $this->db->lastInsertId();

        $this->policyId = (new PayCyclePolicy())->create([
            'policy_name' => 'PHPUnit Government Payroll',
            'normal_pay_day' => 20,
            'non_business_day_rule' => 'PREVIOUS_BUSINESS_DAY',
            'is_active' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->holidayIds as $id) {
            $this->db->prepare("DELETE FROM public_holidays WHERE id = ?")->execute([$id]);
        }
        // Borrower first -- it FK-references pay_cycle_policies, so the
        // policy can't be deleted while a borrower still points at it.
        $this->db->prepare("DELETE FROM borrowers WHERE id = ?")->execute([$this->borrowerId]);
        $this->db->prepare("DELETE FROM pay_cycle_policies WHERE id = ?")->execute([$this->policyId]);
        foreach ($this->extraPolicyIds as $id) {
            $this->db->prepare("DELETE FROM pay_cycle_policies WHERE id = ?")->execute([$id]);
        }
    }

    public function testResolverAppliesBorrowerOverrideBeforeAnyPolicy(): void
    {
        $this->db->prepare("UPDATE borrowers SET pay_day_override = 5, non_business_day_rule_override = 'NEXT_BUSINESS_DAY', pay_cycle_policy_id = ? WHERE id = ?")
            ->execute([$this->policyId, $this->borrowerId]);

        $resolved = (new PayCycleResolverService())->resolveFor($this->borrowerId, 20);

        $this->assertSame('BORROWER_OVERRIDE', $resolved['source']);
        $this->assertSame(5, $resolved['normal_pay_day']);
        $this->assertSame('NEXT_BUSINESS_DAY', $resolved['non_business_day_rule']);
    }

    public function testResolverFallsBackToAssignedPolicyWhenNoEmployerMappingOrOverride(): void
    {
        $this->db->prepare("UPDATE borrowers SET pay_cycle_policy_id = ? WHERE id = ?")->execute([$this->policyId, $this->borrowerId]);

        $resolved = (new PayCycleResolverService())->resolveFor($this->borrowerId, 20);

        $this->assertSame('BORROWER_ASSIGNED_POLICY', $resolved['source']);
        $this->assertSame(20, $resolved['normal_pay_day']);
    }

    public function testResolverReturnsNullWithNoOverrideNoEmployerNoAssignedPolicy(): void
    {
        $resolved = (new PayCycleResolverService())->resolveFor($this->borrowerId, 20);
        $this->assertNull($resolved);
    }

    /** The exact worked example from the request. */
    public function testGovernmentPayrollShiftsSundayPaydayToPreviousFriday(): void
    {
        $this->db->prepare("UPDATE borrowers SET pay_cycle_policy_id = ? WHERE id = ?")->execute([$this->policyId, $this->borrowerId]);

        $scheduleRow = ['due_date' => '2026-09-20'];
        $debitOrder = ['borrower_id' => $this->borrowerId, 'debit_day' => 20];

        $adjustment = (new CollectionDateAdjustmentService())->computeAdjustment($scheduleRow, $debitOrder, 20);

        $this->assertNotNull($adjustment);
        $this->assertSame('2026-09-20', $adjustment['contractual_due_date']);
        $this->assertSame('2026-09-20', $adjustment['normal_pay_date']);
        $this->assertSame('2026-09-18', $adjustment['adjusted_pay_date']);
        $this->assertSame('2026-09-18', $adjustment['proposed_collection_date']);
        $this->assertFalse($adjustment['crosses_period_boundary']);
        $this->assertSame('NONE', $adjustment['requires_manual_flag']);
    }

    public function testNoAdjustmentProducedWhenDebitDayAlreadyMatchesProposedDate(): void
    {
        $this->db->prepare("UPDATE borrowers SET pay_cycle_policy_id = ? WHERE id = ?")->execute([$this->policyId, $this->borrowerId]);

        // Staff already manually set debit_day so this cycle's natural
        // collection date is the 18th -- nothing left to propose.
        $scheduleRow = ['due_date' => '2026-09-20'];
        $debitOrder = ['borrower_id' => $this->borrowerId, 'debit_day' => 18];

        $adjustment = (new CollectionDateAdjustmentService())->computeAdjustment($scheduleRow, $debitOrder, 20);
        $this->assertNull($adjustment);
    }

    public function testCrossPeriodAdjustmentIsFlaggedWhenWalkCrossesMonthBoundary(): void
    {
        $janPolicyId = (new PayCyclePolicy())->create([
            'policy_name' => 'PHPUnit Jan 1 Policy',
            'normal_pay_day' => 1,
            'non_business_day_rule' => 'PREVIOUS_BUSINESS_DAY',
            'is_active' => 1,
        ]);
        $this->extraPolicyIds[] = $janPolicyId;
        $this->db->prepare("UPDATE borrowers SET pay_cycle_policy_id = ? WHERE id = ?")->execute([$janPolicyId, $this->borrowerId]);

        // Make 2027-01-01 (a Friday) itself a public holiday so the walk
        // is forced back into December.
        $holidayId = (new PublicHoliday())->create([
            'holiday_date' => '2027-01-01',
            'holiday_name' => 'PHPUnit New Year Test Holiday',
            'year' => 2027,
            'is_active' => 1,
            'source' => 'Manual',
        ]);
        $this->holidayIds[] = $holidayId;

        $scheduleRow = ['due_date' => '2027-01-01'];
        $debitOrder = ['borrower_id' => $this->borrowerId, 'debit_day' => 1];

        $adjustment = (new CollectionDateAdjustmentService())->computeAdjustment($scheduleRow, $debitOrder, 1);

        $this->assertNotNull($adjustment);
        $this->assertSame('2026-12-31', $adjustment['adjusted_pay_date']);
        $this->assertTrue($adjustment['crosses_period_boundary']);
        $this->assertSame('CROSS_PERIOD_ADJUSTMENT', $adjustment['requires_manual_flag']);
    }

    public function testBusinessCalendarServiceRespectsARealHolidayFixture(): void
    {
        $holidayId = (new PublicHoliday())->create([
            'holiday_date' => '2026-09-18',
            'holiday_name' => 'PHPUnit Fake Holiday',
            'year' => 2026,
            'is_active' => 1,
            'source' => 'Manual',
        ]);
        $this->holidayIds[] = $holidayId;

        $calendar = new BusinessCalendarService();
        // Friday 2026-09-18 is now also "closed" -- previous business day
        // from Sunday 2026-09-20 must skip past it to Thursday 2026-09-17.
        $result = $calendar->previousBusinessDay(new \DateTimeImmutable('2026-09-20'));
        $this->assertSame('2026-09-17', $result->format('Y-m-d'));
    }
}
