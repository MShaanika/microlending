<?php

namespace Tests\Unit;

use App\Services\ApplicationLoanBook;
use App\Services\CplRecordBuilder;
use App\Services\CplValidationService;
use App\Services\LegacyCplExtract;
use App\Services\LegacyCplMapper;
use App\Services\LoanFollowupCpl;
use App\Services\LoanFollowupFlow;
use PHPUnit\Framework\TestCase;

/** The loan questions: what is asked next, what each answer does, and how the answers become Creditinfo records. */
class LoanFollowupFlowTest extends TestCase
{
    private const NOW = '2026-11-17 09:00:00';

    private function f(array $over = []): array
    {
        return $over + ['status' => 'Open', 'approved' => null, 'plans_to_approve' => null, 'approved_amount' => null, 'disbursed' => null, 'disbursed_on' => null, 'term_months' => null,
            'pay_day' => null, 'ask_count' => 0, 'next_ask_at' => null];
    }

    private function app(): array
    {
        return ['id' => 1, 'application_no' => 'APP-1', 'applicant_first_name' => 'Maria', 'applicant_middle_name' => '', 'applicant_last_name' => 'Test', 'applicant_id_number' => '85051500123',
            'applicant_gender' => 'Female', 'applicant_phone' => '0811111111', 'applicant_address' => 'Erf 1 Test Street', 'employer_name' => 'E', 'gross_salary' => 9000, 'payment_day' => 25,
            'requested_amount' => 1000, 'requested_term_months' => 1, 'created_at' => '2026-10-30 09:00:00'];
    }

    private function key(array $f, array $inst = [], string $now = self::NOW): ?string
    {
        return (new LoanFollowupFlow())->nextQuestion($f, $inst, $now)['key'] ?? null;
    }

    private function inst(int $no, string $due, ?string $paid = null, ?string $next = null, ?string $answeredAt = null): array
    {
        return ['id' => $no, 'instalment_no' => $no, 'due_date' => $due, 'expected_amount' => 1320, 'paid_amount' => $paid, 'paid_on' => null, 'next_ask_at' => $next, 'answered_at' => $answeredAt];
    }

    public function testTheQuestionsComeInTheAgreedOrder(): void
    {
        $this->assertSame('approved', $this->key($this->f()));
        $this->assertSame('plans', $this->key($this->f(['approved' => 'No'])));
        $this->assertSame('amount', $this->key($this->f(['approved' => 'Yes'])));
        $this->assertSame('disbursed', $this->key($this->f(['approved' => 'Yes', 'approved_amount' => 1000])));
        $this->assertSame('payout', $this->key($this->f(['approved' => 'Yes', 'approved_amount' => 1000, 'disbursed' => 'Yes'])));
        $this->assertNull($this->key($this->f(['approved' => 'Yes', 'approved_amount' => 1000, 'disbursed' => 'Yes', 'disbursed_on' => '2026-10-30', 'pay_day' => 25])), 'nothing is due before the first instalment');
    }

    public function testNotSureAndNotYetAreAskedAgainOnlyWhenTheTimeHasCome(): void
    {
        $later = '2026-11-19 09:00:00';
        $this->assertNull($this->key($this->f(['next_ask_at' => $later])));
        $this->assertSame('approved', $this->key($this->f(['next_ask_at' => $later]), [], '2026-11-19 09:00:01'));
        $this->assertNull($this->key($this->f(['approved' => 'Yes', 'approved_amount' => 1000, 'disbursed' => 'No', 'next_ask_at' => $later])));
        $this->assertSame('disbursed', $this->key($this->f(['approved' => 'Yes', 'approved_amount' => 1000, 'disbursed' => 'No', 'next_ask_at' => $later]), [], '2026-11-20 00:00:00'));
        // not approved, "planning to approve: Yes" -> later it is asked whether it has been approved since
        $this->assertNull($this->key($this->f(['approved' => 'No', 'plans_to_approve' => 'Yes', 'next_ask_at' => $later])));
        $this->assertSame('approved', $this->key($this->f(['approved' => 'No', 'plans_to_approve' => 'Not sure', 'next_ask_at' => $later]), [], '2026-11-21 00:00:00'));
        // not approved and not planning to: never asked again
        $this->assertNull($this->key($this->f(['approved' => 'No', 'plans_to_approve' => 'No']), [], '2030-01-01 00:00:00'));
        $this->assertNull($this->key($this->f(['status' => 'Closed'])));
    }

    public function testAnswersChangeTheRightFields(): void
    {
        $flow = new LoanFollowupFlow();
        $app = $this->app();

        $r = $flow->answer($this->f(), 'approved', ['answer' => 'yes'], $app, self::NOW);
        $this->assertSame('Yes', $r['update']['approved']);

        $r = $flow->answer($this->f(), 'approved', ['answer' => 'Not sure'], $app, self::NOW);
        $this->assertSame('2026-11-19 09:00:00', $r['update']['next_ask_at']);
        $this->assertSame(1, $r['update']['ask_count']);

        $r = $flow->answer($this->f(['approved' => 'No']), 'plans', ['answer' => 'No'], $app, self::NOW);
        $this->assertSame('Closed', $r['update']['status']);

        $r = $flow->answer($this->f(['approved' => 'No']), 'plans', ['answer' => 'Yes'], $app, self::NOW);
        $this->assertSame('Yes', $r['update']['plans_to_approve']);
        $this->assertArrayNotHasKey('status', $r['update']);

        $r = $flow->answer($this->f(['approved' => 'Yes']), 'amount', ['amount' => '1,500', 'term' => '2'], $app, self::NOW);
        $this->assertSame(1500.0, $r['update']['approved_amount']);
        $this->assertSame(2, $r['update']['term_months']);

        $r = $flow->answer($this->f(['approved' => 'Yes', 'approved_amount' => 1500]), 'disbursed', ['answer' => 'No'], $app, self::NOW);
        $this->assertSame('No', $r['update']['disbursed']);
        $this->assertSame('2026-11-19 09:00:00', $r['update']['next_ask_at']);

        $r = $flow->answer($this->f(['approved' => 'Yes', 'approved_amount' => 1500, 'disbursed' => 'Yes']), 'payout', ['paid_out' => '2026-10-30', 'pay_day' => '25'], $app, self::NOW);
        $this->assertTrue($r['schedule']);
        $this->assertSame(25, $r['update']['pay_day']);
    }

    public function testBadAnswersAreRefusedWithAMessage(): void
    {
        $flow = new LoanFollowupFlow();
        $app = $this->app();
        $bad = [
            ['approved', ['answer' => 'maybe']],
            ['amount', ['amount' => '4,50']],
            ['amount', ['amount' => '0']],
            ['amount', ['amount' => '1000', 'term' => '13']],
            ['payout', ['paid_out' => '2026-12-01', 'pay_day' => '25']],   // future
            ['payout', ['paid_out' => '2026-10-01', 'pay_day' => '25']],   // before the application
            ['payout', ['paid_out' => '2026-10-30', 'pay_day' => '40']],
            ['payout', ['paid_out' => '30/10/2026', 'pay_day' => '25']],
        ];
        foreach ($bad as [$q, $input]) {
            try {
                $flow->answer($this->f(), $q, $input, $app, self::NOW);
                $this->fail("$q " . json_encode($input) . ' should have been refused');
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testTheScheduleIsPricedLikeTheOldSystemAndFollowsThePayDay(): void
    {
        $s = (new LoanFollowupFlow())->schedule(1000, 3, '2026-10-30', 25);

        // 1000 + 5 + 10.30 = 1015.30; +30% = 1319.89; 3 instalments of 440
        $this->assertSame([['instalment_no' => 1, 'due_date' => '2026-11-25', 'expected_amount' => 440.0], ['instalment_no' => 2, 'due_date' => '2026-12-25', 'expected_amount' => 440.0], ['instalment_no' => 3, 'due_date' => '2027-01-25', 'expected_amount' => 440.0]], $s);
        $this->assertSame('2026-11-28', (new LoanFollowupFlow())->schedule(1000, 1, '2026-10-29', 30)[0]['due_date'], 'pay day 30 counts as the 28th');
        $this->assertSame('2026-11-15', (new LoanFollowupFlow())->schedule(1000, 1, '2026-10-20', 15)[0]['due_date'], 'any pay day of the month is allowed');
    }

    public function testAnInstalmentIsAskedTheDayAfterItFallsDueUntilItIsPaidInFull(): void
    {
        $f = $this->f(['approved' => 'Yes', 'approved_amount' => 1000, 'disbursed' => 'Yes', 'disbursed_on' => '2026-10-30', 'pay_day' => 25]);
        $this->assertNull($this->key($f, [$this->inst(1, '2026-11-17')], '2026-11-17 09:00:00'), 'on the due day itself nothing is asked');
        $this->assertSame('instalment', $this->key($f, [$this->inst(1, '2026-11-17')], '2026-11-18 00:00:00'));
        $this->assertNull($this->key($f, [$this->inst(1, '2026-11-17', '1320')], '2026-11-30 00:00:00'), 'paid in full');
        $this->assertNull($this->key($f, [$this->inst(1, '2026-11-17', '0', '2026-11-25 00:00:00', '2026-11-18')], '2026-11-20 00:00:00'), 'a "No" waits a week');
        $this->assertSame('instalment', $this->key($f, [$this->inst(1, '2026-11-17', '0', '2026-11-25 00:00:00', '2026-11-18')], '2026-11-26 00:00:00'));
    }

    public function testInstalmentAnswersAddPaymentsOneAtATime(): void
    {
        $flow = new LoanFollowupFlow();
        $i = $this->inst(1, '2026-11-17');

        $r = $flow->answerInstalment($i, ['answer' => 'Yes', 'amount' => '', 'paid_on' => '2026-11-17'], self::NOW, '2026-10-30');
        $this->assertSame(1320.0, $r['update']['paid_amount']);
        $this->assertNull($r['update']['next_ask_at']);
        $this->assertSame(['amount' => 1320.0, 'paid_on' => '2026-11-17'], $r['payment']);

        $r = $flow->answerInstalment($i, ['answer' => 'Partly', 'amount' => '500', 'paid_on' => '2026-11-17'], self::NOW, '2026-10-30');
        $this->assertSame(500.0, $r['update']['paid_amount']);
        $this->assertSame('2026-11-24 09:00:00', $r['update']['next_ask_at']);

        // a second payment is ADDED to the first: 500 + 820 completes the instalment, and "Yes" with no amount means "the rest"
        $part = $this->inst(1, '2026-11-17', '500');
        $r = $flow->answerInstalment($part, ['answer' => 'Yes', 'amount' => '', 'paid_on' => '2026-11-16'], self::NOW, '2026-10-30');
        $this->assertSame(820.0, $r['payment']['amount']);
        $this->assertSame(1320.0, $r['update']['paid_amount']);
        $this->assertNull($r['update']['next_ask_at']);
        $r = $flow->answerInstalment($part, ['answer' => 'Partly', 'amount' => '300', 'paid_on' => '2026-11-16'], self::NOW, '2026-10-30');
        $this->assertSame(800.0, $r['update']['paid_amount']);

        $r = $flow->answerInstalment($i, ['answer' => 'No'], self::NOW);
        $this->assertSame(0.0, $r['update']['paid_amount']);
        $this->assertSame('2026-11-24 09:00:00', $r['update']['next_ask_at']);
        $this->assertNull($r['payment']);

        foreach ([
            ['answer' => 'Partly', 'amount' => '1320', 'paid_on' => '2026-11-17'],        // not "partly"
            ['answer' => 'Yes', 'amount' => '100', 'paid_on' => '2026-11-17'],            // not in full
            ['answer' => 'Yes', 'paid_on' => '2026-12-30'],                               // future
            ['answer' => 'Yes', 'paid_on' => '2026-01-01'],                               // long before
            ['answer' => 'Yes', 'paid_on' => '2026-10-20'],                               // before the pay-out
            ['answer' => 'Sometimes'],
        ] as $input) {
            try {
                $flow->answerInstalment($i, $input, self::NOW, '2026-10-30');
                $this->fail(json_encode($input) . ' should have been refused');
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testTheAnswerDeadlineIsTheThirdOfTheNextMonth(): void
    {
        $this->assertSame('2026-12-03', (new LoanFollowupFlow())->answerByDate('2026-11-25'));
        $this->assertSame('2027-01-03', (new LoanFollowupFlow())->answerByDate('2026-12-25'));
    }

    // ---- from the answers to the Creditinfo records --------------------------------------------------------------------------

    private function entry(array $f, array $inst, array $payments = []): array
    {
        return ['followup' => $f + $this->f(), 'application' => $this->app(), 'instalments' => $inst, 'payments' => $payments];
    }

    private function paidOut(array $over = []): array
    {
        return $over + ['status' => 'Open', 'approved' => 'Yes', 'approved_amount' => '1000.00', 'disbursed' => 'Yes', 'disbursed_on' => '2026-10-30', 'pay_day' => 25, 'term_months' => 1];
    }

    private function monthly(array $entries, string $asOf, ?string $now = '2026-12-01 09:00:00'): array
    {
        $built = (new LoanFollowupCpl())->build($entries, $asOf, $now);
        $result = (new LegacyCplExtract($built['book'], new LegacyCplMapper(), new CplValidationService(), new CplRecordBuilder()))->monthly($asOf, 'NA02629', 'Test');
        return [$built, $result];
    }

    public function testAPaidOutLoanBecomesARecordAndLoansNotYetPaidOutOrNotApprovedDoNot(): void
    {
        [$built, $result] = $this->monthly([
            $this->entry($this->paidOut(), []),
            $this->entry($this->paidOut(['disbursed' => 'No', 'disbursed_on' => null]), []),
            $this->entry($this->paidOut(['approved' => 'No']), []),
            $this->entry($this->paidOut(['disbursed_on' => '2026-11-05']), []),
        ], '2026-10-31');

        $this->assertSame(1, $result['summary']['sent']);
        $this->assertSame([], $built['pending']);
    }

    public function testAnInstalmentDueByTheMonthEndWithNoAnswerKeepsTheLoanOutAndIsListedAsPending(): void
    {
        $due = $this->inst(1, '2026-11-25');
        [$built, $result] = $this->monthly([$this->entry($this->paidOut(['disbursed_on' => '2026-11-03']), [$due])], '2026-11-30');

        $this->assertSame(0, $result['summary']['sent']);
        $this->assertSame('APP-1', $built['pending'][0]['application_no']);
        $this->assertSame(1, $built['pending'][0]['instalment_no']);
    }

    public function testReportedPaymentsAreUsedAndAnAnsweredNoIsAnArrear(): void
    {
        $paid = $this->inst(1, '2026-11-25', '1320', null, '2026-11-26 10:00:00');
        $paid['id'] = 11;
        [, $ok] = $this->monthly([$this->entry($this->paidOut(['disbursed_on' => '2026-11-03']), [$paid], [11 => [['amount' => '1320', 'paid_on' => '2026-11-25']]])], '2026-11-30');
        $this->assertSame(1, $ok['summary']['sent']);
        $this->assertSame(0, $ok['details'][0]['amount_overdue']);
        $this->assertSame(0, $ok['details'][0]['current_balance'], 'paid in full: closed');

        $no = $this->inst(1, '2026-11-25', '0', '2026-12-03 00:00:00', '2026-11-26 10:00:00');
        [, $late] = $this->monthly([$this->entry($this->paidOut(['disbursed_on' => '2026-11-03']), [$no])], '2026-11-30');
        $this->assertSame(1, $late['summary']['sent']);
        // the instalment fell due only 5 days before the month end: the amount is overdue, but no whole month is in arrears yet (30 days needed)
        $this->assertSame(0, $late['details'][0]['months_in_arrears']);
        $this->assertGreaterThan(0, $late['details'][0]['amount_overdue']);
    }

    public function testOnlyPaymentsMadeByTheMonthEndCountEvenIfAToppingUpCameLater(): void
    {
        // 300 paid on 26 November, topped up to 1320 on 5 December: the November file must show 300, not nothing and not 1320
        $i = $this->inst(1, '2026-11-25', '1320', null, '2026-12-05 10:00:00');
        $i['id'] = 12;
        [, $r] = $this->monthly([$this->entry($this->paidOut(['disbursed_on' => '2026-11-03']), [$i], [12 => [['amount' => '300', 'paid_on' => '2026-11-26'], ['amount' => '1020', 'paid_on' => '2026-12-05']]])], '2026-11-30', '2026-12-06 09:00:00');

        $this->assertSame(1, $r['summary']['sent']);
        $this->assertSame(1320 - 300, $r['details'][0]['current_balance']);
        $this->assertSame(300, (int) round($r['details'][0]['paid']));
    }

    public function testAnUnpaidAnswerWhoseReAskTimeHasPassedHoldsTheLoanBackUntilItIsAnsweredAgain(): void
    {
        $no = $this->inst(1, '2026-11-25', '0', '2026-12-03 00:00:00', '2026-11-26 10:00:00');
        [$fresh] = $this->monthly([$this->entry($this->paidOut(['disbursed_on' => '2026-11-03']), [$no])], '2026-11-30', '2026-12-02 09:00:00');
        $this->assertSame([], $fresh['pending'], 'the "No" is still fresh');

        [$stale, $result] = $this->monthly([$this->entry($this->paidOut(['disbursed_on' => '2026-11-03']), [$no])], '2026-11-30', '2026-12-05 09:00:00');
        $this->assertSame('APP-1', $stale['pending'][0]['application_no']);
        $this->assertSame(0, $result['summary']['sent']);
    }
}
