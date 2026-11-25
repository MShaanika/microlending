<?php

namespace Tests\Unit;

use App\Services\CombinedLoanService;
use App\Services\LoanStatementService;
use App\Services\LoanStatementSummary;
use PHPUnit\Framework\TestCase;

/** The headline figures every statement output shows, and the check that they agree (no database). */
class LoanStatementSummaryTest extends TestCase
{
    private function loan(array $over = []): array
    {
        return $over + ['id' => 0, 'principal_amount' => 100.00, 'interest_amount' => 31.81, 'admin_fee' => 0, 'total_payable' => 137.84, 'loan_status' => 'Active'];
    }

    private function schedule(float $paid = 0.0, float $penalty = 0.0, float $fees = 0.0): array
    {
        return [[
            'installment_no' => 1, 'due_date' => '2026-11-30', 'opening_balance' => 106.03, 'principal_due' => 106.03, 'interest_due' => 31.81,
            'penalty_due' => $penalty, 'fees_due' => $fees, 'total_due' => round(137.84 + $penalty + $fees, 2), 'total_paid' => $paid, 'closing_balance' => 0.0, 'status' => 'Pending',
        ]];
    }

    private function ledger(float $closing, array $over = []): array
    {
        return $over + ['opening_balance' => 106.03, 'events' => [['type' => 'Disbursed']], 'closing_balance' => $closing, 'levy' => 1.03, 'stamp' => 5.00, 'paid_out' => true, 'disbursed_on' => '2026-11-24'];
    }

    public function testThePrincipalChargesAndOpeningBalanceAreWorkedOutOneWay(): void
    {
        $s = LoanStatementSummary::build($this->loan(), $this->schedule(), $this->ledger(137.84));
        $this->assertSame([100.0, 1.03, 5.0, 6.03, 106.03], [$s['principal'], $s['levy'], $s['stamp'], $s['charges'], $s['opening_balance']]);
        $this->assertSame(106.03, $s['schedule_principal'], 'the schedule principal includes the charges');
        $this->assertSame(137.84, $s['schedule_due']);
        $this->assertSame(137.84, $s['outstanding']);
        $this->assertSame([], $s['problems']);
    }

    public function testAfterAPartPaymentAndAPenaltyTheyStillAgree(): void
    {
        $s = LoanStatementSummary::build($this->loan(), $this->schedule(50.0, 10.0), $this->ledger(97.84));
        $this->assertSame(97.84, $s['outstanding']);
        $this->assertSame(10.0, $s['penalties']);
        $this->assertSame([], $s['problems'], 'a penalty is on both the schedule and the history, and is not part of total payable');
        $this->assertSame('Still to pay (Total Due - Paid): 97.84', LoanStatementSummary::stillToPayLine($s));
    }

    public function testEveryDisagreementIsReportedInPlainWords(): void
    {
        $s = LoanStatementSummary::build($this->loan(['total_payable' => 150.00]), $this->schedule(), $this->ledger(131.81));
        $this->assertCount(3, $s['problems']);
        $this->assertStringContainsString('The transaction history ends at 131.81 but the schedule says 137.84 is still to pay', $s['problems'][0]);
        $this->assertStringContainsString('does not add up to the total payable of 150.00', $s['problems'][1]);
        $this->assertStringContainsString('The schedule adds up to 137.84 (before penalties) but the total payable is 150.00', $s['problems'][2]);
    }

    public function testARescheduledLoanIsOnlyCheckedAgainstItsHistory(): void
    {
        $s = LoanStatementSummary::build($this->loan(['total_payable' => 150.00]), $this->schedule(), $this->ledger(137.84), true);
        $this->assertSame([], $s['problems']);
    }

    public function testTheAdminFeeIsPartOfTotalPayableAndTheNote(): void
    {
        $s = LoanStatementSummary::build($this->loan(['admin_fee' => 10.00, 'total_payable' => 147.84]), $this->schedule(0.0, 0.0, 10.0), $this->ledger(147.84));
        $this->assertSame([], $s['problems']);
        $this->assertStringContainsString('Fees is the admin fee (10.00)', LoanStatementSummary::scheduleNote($s));
    }

    public function testBeforeThePayoutNothingIsOwed(): void
    {
        $s = LoanStatementSummary::build($this->loan(['loan_status' => 'Awaiting Payout']), $this->schedule(), ['opening_balance' => 0.0, 'events' => [], 'closing_balance' => 0.0, 'levy' => 1.03, 'stamp' => 5.0, 'paid_out' => false, 'disbursed_on' => null]);
        $this->assertFalse($s['paid_out']);
        $this->assertTrue($s['awaiting_payout']);
        $this->assertSame(0.0, $s['outstanding']);
        $this->assertSame(106.03, $s['opening_balance']);
        $this->assertSame([], $s['problems'], 'an empty history is not a disagreement before the payout');
    }

    public function testBeforeThePayoutNothingSaysItWasPaidOut(): void
    {
        $s = LoanStatementSummary::build($this->loan(['loan_status' => 'Awaiting Payout']), $this->schedule(), ['opening_balance' => 0.0, 'events' => [], 'closing_balance' => 0.0, 'levy' => 1.03, 'stamp' => 5.0, 'paid_out' => false, 'disbursed_on' => null]);
        $this->assertSame('Principal (to be paid out)', $s['principal_label']);
        $this->assertSame('+ NAMFISA levy (added when paid out)', $s['levy_label']);
        $this->assertSame('+ Stamp duty (added when paid out)', $s['stamp_label']);
        $this->assertSame('Opening balance (once paid out)', $s['opening_label']);
        $note = LoanStatementSummary::scheduleNote($s);
        $this->assertStringContainsString('will be added to the 100.00 when it is paid out', $note);
        $this->assertStringContainsString('Nothing has been paid out or charged yet', $note);
        $this->assertStringNotContainsString('100.00 paid out', $note);

        $paid = LoanStatementSummary::build($this->loan(), $this->schedule(), $this->ledger(137.84));
        $this->assertSame('Principal (paid out)', $paid['principal_label']);
        $this->assertSame('Opening balance', $paid['opening_label']);
        $this->assertStringContainsString('added to the 100.00 paid out', LoanStatementSummary::scheduleNote($paid));
    }

    public function testWithoutAnAdminFeeTheNoteDoesNotMentionOne(): void
    {
        $s = LoanStatementSummary::build($this->loan(), $this->schedule(), $this->ledger(137.84));
        $this->assertStringNotContainsString('admin fee', LoanStatementSummary::scheduleNote($s));
    }

    public function testARescheduledLoansPrintedSumStillAddsUp(): void
    {
        // interest_amount stays 31.81 but the reschedule reset total payable to 160.00
        $s = LoanStatementSummary::build($this->loan(['total_payable' => 160.00]), $this->schedule(), $this->ledger(137.84), true);
        $this->assertSame('+ Interest (as rescheduled)', $s['interest_label']);
        $this->assertSame(53.97, $s['interest']);
        $this->assertSame($s['total_payable'], round($s['opening_balance'] + $s['interest'] + $s['admin_fee'], 2), 'opening balance + interest (+ admin fee) = total payable');
        $this->assertStringContainsString('This loan was rescheduled', LoanStatementSummary::scheduleNote($s));

        $plain = LoanStatementSummary::build($this->loan(), $this->schedule(), $this->ledger(137.84));
        $this->assertSame('+ Interest', $plain['interest_label']);
        $this->assertSame(31.81, $plain['interest']);
        $this->assertStringNotContainsString('rescheduled', LoanStatementSummary::scheduleNote($plain));
    }

    public function testTheHistoryPutsTheChargesRightAfterThePayout(): void
    {
        $events = [
            ['date' => '2026-11-24', 'type' => 'Interest'], ['date' => '2026-11-24', 'type' => 'Payment'], ['date' => '2026-11-24', 'type' => 'Charge'],
            ['date' => '2026-11-24', 'type' => 'Penalty'], ['date' => '2026-11-24', 'type' => 'Disbursed'], ['date' => '2026-11-23', 'type' => 'Fee'],
        ];
        usort($events, [LoanStatementService::class, 'compareEvents']);
        $this->assertSame(['Fee', 'Disbursed', 'Charge', 'Interest', 'Penalty', 'Payment'], array_column($events, 'type'));
    }

    public function testACombinedLoanAddsUpTheChargesOfEveryPart(): void
    {
        $tranches = [['id' => 1, 'loan_no' => 'LN-A'], ['id' => 2, 'loan_no' => 'LN-B']];
        $merged = CombinedLoanService::mergeLedgers($tranches, [
            1 => ['opening_balance' => 106.03, 'events' => [], 'closing_balance' => 0.0, 'levy' => 1.03, 'stamp' => 5.0, 'paid_out' => true, 'disbursed_on' => '2026-11-24'],
            2 => ['opening_balance' => 51.5, 'events' => [], 'closing_balance' => 0.0, 'levy' => 0.5, 'stamp' => 0.0, 'paid_out' => true, 'disbursed_on' => '2026-11-20'],
        ]);
        $this->assertSame(1.53, $merged['levy']);
        $this->assertSame(5.0, $merged['stamp']);
        $this->assertTrue($merged['paid_out']);
        $this->assertSame('2026-11-20', $merged['disbursed_on'], 'the first payout');
    }
}
