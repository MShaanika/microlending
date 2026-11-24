<?php

namespace Tests\Unit;

use App\Services\CplAutoSelector;
use App\Services\CplRecordBuilder;
use App\Services\LoanFollowupFlow;
use PHPUnit\Framework\TestCase;

/**
 * What goes into the automatic daily and monthly Creditinfo files. Pure: no database, no clock, no network.
 */
class CplAutoSelectorTest extends TestCase
{
    private CplAutoSelector $selector;

    protected function setUp(): void
    {
        $this->selector = new CplAutoSelector();
    }

    /**
     * One Loan Questions entry: a loan of N$1000 over $months months, paid out on $paidOut, client paid on the 25th.
     * $paid = number of instalments paid in full (each on its due date).
     *
     * @return array<string,mixed>
     */
    private function entry(int $fid, string $paidOut = '2018-10-10', int $months = 2, int $paid = 0, array $followup = [], array $application = []): array
    {
        $schedule = (new LoanFollowupFlow())->schedule(1000.0, $months, $paidOut, 25);
        $instalments = [];
        $payments = [];
        foreach ($schedule as $i => $s) {
            $id = $fid * 100 + $s['instalment_no'];
            $isPaid = $i < $paid;
            $instalments[] = [
                'id' => $id, 'followup_id' => $fid, 'instalment_no' => $s['instalment_no'], 'due_date' => $s['due_date'],
                'expected_amount' => $s['expected_amount'],
                'paid_amount' => $isPaid ? $s['expected_amount'] : null,
                'paid_on' => $isPaid ? $s['due_date'] : null,
                'answered_at' => $isPaid ? $s['due_date'] . ' 09:00:00' : null,
                'next_ask_at' => null,
            ];
            if ($isPaid) {
                $payments[$id] = [['amount' => (string) $s['expected_amount'], 'paid_on' => $s['due_date']]];
            }
        }
        return [
            'followup' => $followup + [
                'id' => $fid, 'application_id' => $fid, 'status' => 'Open', 'approved' => 'Yes', 'disbursed' => 'Yes', 'disbursed_on' => $paidOut,
                'approved_amount' => '1000.00', 'term_months' => $months, 'pay_day' => 25,
            ],
            'application' => $application + [
                'id' => $fid, 'application_no' => 'APP-T' . $fid, 'applicant_first_name' => 'Tester' . $fid, 'applicant_middle_name' => '',
                'applicant_last_name' => 'Auto' . $fid, 'applicant_id_number' => '9001011234' . ($fid % 10), 'applicant_gender' => 'Male',
                'applicant_phone' => '0811234567', 'applicant_address' => '1 Test Street Windhoek', 'employer_name' => 'Test Employer',
                'gross_salary' => '12000', 'payment_day' => 25, 'requested_term_months' => $months,
            ],
            'instalments' => $instalments,
            'payments' => $payments,
        ];
    }

    private function kinds(array $selected): array
    {
        return array_map(static fn (array $r): string => $r['kind'] . ':' . $r['followup_id'], $selected['records']);
    }

    public function testNewlyPaidOutLoanIsRegisteredOnce(): void
    {
        $e = $this->entry(1);
        $first = $this->selector->daily([$e], [], '2018-10-11');
        $this->assertSame(['R:1'], $this->kinds($first));
        $this->assertSame([], $first['exceptions']);

        // once reported, never selected again
        $again = $this->selector->daily([$e], [CplAutoSelector::reportedKey(1, 'R') => true], '2018-10-12');
        $this->assertSame([], $again['records']);
    }

    public function testRegistrationRecordIsAValidDailyLine(): void
    {
        $r = $this->selector->daily([$this->entry(2)], [], '2018-10-11')['records'][0];
        $f = $r['fields'];
        $this->assertSame('R', $f['data']);
        $this->assertSame('APP-T2', $f['account_no'], 'the account number is the application number, as in the monthly file');
        $this->assertSame('P', $f['type_of_account']);
        $this->assertArrayNotHasKey('status_code', $f);
        $this->assertSame($f['opening_balance'], $f['current_balance'], 'nothing paid at registration');
        $line = (new CplRecordBuilder())->dailyRecord($f, 'NA02629', '2018-10-11');
        $this->assertSame(718, strlen($line));
        $this->assertSame('R', $line[0]);
        $this->assertSame('   NA02629', substr($line, 700, 10), 'the supplier reference number is right aligned');
        $this->assertSame('20181011', substr($line, 710, 8));
    }

    public function testLoanPaidOutInTheFutureIsNotRegisteredYet(): void
    {
        $this->assertSame([], $this->selector->daily([$this->entry(3, '2018-10-20')], [], '2018-10-19')['records']);
    }

    public function testCatchUpPicksUpEverythingMissedNotJustYesterday(): void
    {
        $entries = [$this->entry(1, '2018-10-01'), $this->entry(2, '2018-10-04'), $this->entry(3, '2018-10-09')];
        $this->assertSame(['R:1', 'R:2', 'R:3'], $this->kinds($this->selector->daily($entries, [], '2018-10-10')));
        // loan 1 was reported on an earlier day; the next file holds only what is still missing
        $this->assertSame(['R:2', 'R:3'], $this->kinds($this->selector->daily($entries, [CplAutoSelector::reportedKey(1, 'R') => true], '2018-10-10')));
    }

    public function testSinceDateLeavesOutEarlierLoansButNotTheirClosure(): void
    {
        $old = $this->entry(1, '2018-09-01', 1, 1); // paid out before the cut-off and already paid in full
        $new = $this->entry(2, '2018-10-05');
        $sel = $this->selector->daily([$old, $new], [], '2018-10-20', '2018-10-01');
        $this->assertSame(['R:2', 'C:1'], $this->kinds($sel));
    }

    public function testClosureOnlyWhenEveryInstalmentIsPaidInFull(): void
    {
        $none = $this->entry(1, '2018-10-10', 2, 0);
        $half = $this->entry(2, '2018-10-10', 2, 1);
        $all = $this->entry(3, '2018-10-10', 2, 2);
        $reportedR = [CplAutoSelector::reportedKey(1, 'R') => true, CplAutoSelector::reportedKey(2, 'R') => true, CplAutoSelector::reportedKey(3, 'R') => true];
        $sel = $this->selector->daily([$none, $half, $all], $reportedR, '2018-12-31');
        $this->assertSame(['C:3'], $this->kinds($sel));
        $c = $sel['records'][0]['fields'];
        $this->assertSame('C', $c['data']);
        $this->assertSame('C', $c['status_code']);
        $this->assertSame(0, $c['current_balance']);
        $this->assertSame(0, $c['months_in_arrears']);
        $this->assertSame('20181125', $c['status_date'], 'the closure is dated by the last payment');
    }

    public function testClosureIsNeverRepeated(): void
    {
        $all = $this->entry(3, '2018-10-10', 2, 2);
        $reported = [CplAutoSelector::reportedKey(3, 'R') => true, CplAutoSelector::reportedKey(3, 'C') => true];
        $this->assertSame([], $this->selector->daily([$all], $reported, '2018-12-31')['records']);
    }

    public function testLoanNeverRegisteredAndAlreadySettledGetsItsRBeforeItsCInTheSameFile(): void
    {
        $all = $this->entry(3, '2018-10-10', 2, 2);
        $other = $this->entry(4, '2018-12-01'); // a registration of a later date must not push the closure before any R
        $sel = $this->selector->daily([$all, $other], [], '2018-12-31');
        $this->assertSame(['R:3', 'R:4', 'C:3'], $this->kinds($sel), 'every R comes before every C');
    }

    public function testLoansThatMustNeverBeReported(): void
    {
        $closedByStaff = $this->entry(1, '2018-10-10', 2, 0, ['status' => 'Closed']);
        $notApproved = $this->entry(2, '2018-10-10', 2, 0, ['approved' => 'No']);
        $notPaidOut = $this->entry(3, '2018-10-10', 2, 0, ['disbursed' => 'No']);
        $noPayDay = $this->entry(4, '2018-10-10', 2, 0, ['pay_day' => null]);
        $excluded = $this->entry(5, '2018-10-10', 2, 0, [], ['applicant_first_name' => 'Pilot', 'applicant_last_name' => 'Tester']);
        $uatId = $this->entry(6, '2018-10-10', 2, 0, [], ['applicant_id_number' => '77082851070']);
        $uatName = $this->entry(7, '2018-10-10', 2, 0, [], ['applicant_last_name' => 'CREDITINFOUATTEST']);
        $uatAcc = $this->entry(8, '2018-10-10', 2, 0, [], ['application_no' => 'UATTEST-0001']);
        $good = $this->entry(9);

        $sel = $this->selector->daily([$closedByStaff, $notApproved, $notPaidOut, $noPayDay, $excluded, $uatId, $uatName, $uatAcc, $good], [], '2018-10-20', null, CplAutoSelector::parseExcludedNames("  pilot   TESTER \nsomeone else"));
        $this->assertSame(['R:9'], $this->kinds($sel));
        $this->assertSame([], $sel['exceptions']);
    }

    public function testBlockingValidationProblemBecomesAnExceptionNotARecord(): void
    {
        $noId = $this->entry(1, '2018-10-10', 2, 0, [], ['applicant_id_number' => '']);
        $good = $this->entry(2);
        $sel = $this->selector->daily([$noId, $good], [], '2018-10-20');
        $this->assertSame(['R:2'], $this->kinds($sel));
        $this->assertCount(1, $sel['exceptions']);
        $this->assertSame('APP-T1', $sel['exceptions'][0]['ref_no']);
        $this->assertStringContainsString('registration:', $sel['exceptions'][0]['reasons'][0]);
    }

    public function testSettledByStaffButPaymentsDoNotCoverTheLoanIsAnExceptionNotAClosure(): void
    {
        $e = $this->entry(1, '2018-10-10', 2, 2);
        // staff said "paid in full" for instalments that were set far too low: the money does not cover what is owed
        foreach ($e['instalments'] as $k => $i) {
            $e['instalments'][$k]['expected_amount'] = 100.0;
            $e['instalments'][$k]['paid_amount'] = 100.0;
            $e['payments'][$i['id']] = [['amount' => '100.00', 'paid_on' => $i['due_date']]];
        }
        $sel = $this->selector->daily([$e], [CplAutoSelector::reportedKey(1, 'R') => true], '2018-12-31');
        $this->assertSame([], $sel['records']);
        $this->assertCount(1, $sel['exceptions']);
        $this->assertStringContainsString('closure:', $sel['exceptions'][0]['reasons'][0]);
    }

    public function testMonthlyBookHoldsOpenLoansAndHoldsBackUnansweredInstalments(): void
    {
        // loan 1: paid out 2018-11-05, 3 months, instalment 1 (due 2018-11-25) paid -> in the file
        $ok = $this->entry(1, '2018-11-05', 3, 1);
        // loan 2: paid out 2018-10-05, instalment 1 (due 2018-10-25) paid, instalment 2 (due 2018-11-25) NOT answered -> held back, not guessed
        $pending = $this->entry(2, '2018-10-05', 3, 1);
        // loan 3: paid out in December: belongs to a later month
        $later = $this->entry(3, '2018-12-05', 3, 0);
        $built = $this->selector->monthly([$ok, $pending, $later], '2018-11-30', '2018-12-05 07:00:00', 'NA02629', 'Solid Desert Cash Loan');

        $this->assertSame([1], $built['followup_ids']);
        $this->assertSame('APP-T1', $built['application_numbers'][1]);
        $result = $built['result'];
        $this->assertSame(1, $result['records']);
        $this->assertCount(1, $built['pending']);
        $this->assertSame('APP-T2', $built['pending'][0]['application_no']);
        $this->assertCount(1, $result['exceptions']);
        $this->assertSame(1, $result['summary']['blocked']);
        $lines = explode("\r\n", $result['file']);
        $this->assertCount(3, $lines, 'header + 1 record + trailer');
        $this->assertSame('H', $lines[0][0]);
        $this->assertSame('D', $lines[1][0]);
        $this->assertSame('T', $lines[2][0]);
        $this->assertSame(700, strlen($lines[1]));
        $this->assertSame('APP-T1', rtrim(substr($lines[1], 47, 25)));
    }

    public function testMonthlyLeavesOutTestApplicantsAndUatData(): void
    {
        $uat = $this->entry(1, '2018-11-05', 3, 1, [], ['applicant_id_number' => '8005041272341']);
        $excluded = $this->entry(2, '2018-11-05', 3, 1, [], ['applicant_first_name' => 'Pilot', 'applicant_last_name' => 'Tester']);
        $built = $this->selector->monthly([$uat, $excluded], '2018-11-30', '2018-12-05 07:00:00', 'NA02629', 'X', ['pilot tester']);
        $this->assertSame([], $built['followup_ids']);
        $this->assertSame(0, $built['result']['records']);
    }

    public function testExcludedNamesAreParsedFromTheSetting(): void
    {
        $this->assertSame(['john smith', 'ann lee'], CplAutoSelector::parseExcludedNames("John   Smith\r\nANN LEE, john smith;\n"));
        $this->assertSame([], CplAutoSelector::parseExcludedNames(''));
    }
}
