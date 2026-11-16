<?php

namespace Tests\Unit;

use App\Services\CplValidationService;
use App\Services\LegacyCplMapper;
use PHPUnit\Framework\TestCase;

/**
 * The rules that turn a loan of the old loan system (SolidFin) into a CPL record. Amounts follow the old system's own formula; arrears are an estimate from
 * the first due month; a Namibian ID here has 11 digits.
 */
class LegacyCplMapperTest extends TestCase
{
    private const AS_OF = '2026-10-31';

    private function loan(array $o = []): array
    {
        return array_merge([
            'id' => 1234, 'amount' => 1000, 'months' => 3, 'interest_percentage' => 30, 'status' => 2, 'date_released' => '2026-08-20 10:00:00',
            'date_written_off' => '0000-00-00', 'firstname' => 'Maria', 'middlename' => '', 'lastname' => 'Test', 'id_number' => '85051500123', 'identity' => '',
            'gender' => 'Female', 'contact_no' => '081 234 5678', 'address' => 'Erf 12 Test Street Katutura Windhoek', 'company_name' => 'Test Employer',
            'gross_salary' => '9500.50', 'payment_day' => '25',
        ], $o);
    }

    private function map(array $loan, array $payments = [], ?string $firstDue = '2026-09-00', string $asOf = self::AS_OF): array
    {
        return (new LegacyCplMapper())->map($loan, $payments, $firstDue, $asOf);
    }

    private function blocking(array $fields): array
    {
        return array_column(array_filter((new CplValidationService())->validate($fields), static fn ($i) => $i['severity'] === 'BLOCKING'), 'field');
    }

    public function testTheAmountsFollowTheOldSystemsFormula(): void
    {
        $f = $this->map($this->loan())['fields'];

        // initial = 1000 + 5 + 10.30 = 1015.30; final = 1015.30 * 1.30 = 1319.89
        $this->assertSame(1320, $f['opening_balance']);
        $this->assertSame(1320, $f['current_balance'], 'nothing repaid yet');
        $this->assertSame(440, $f['instalment_amount'], '1319.89 / 3 rounded up');
        $this->assertSame('P', $f['type_of_account']);
        $this->assertSame(3, $f['terms']);
        $this->assertSame('20260820', $f['date_account_opened']);
    }

    public function testRepaymentsUpToTheAsOfDateReduceTheBalanceAndLaterOnesAreIgnored(): void
    {
        $r = $this->map($this->loan(), [
            ['amount' => '440.00', 'date' => '2026-09-25 09:00:00'],
            ['amount' => '440.00', 'date' => '2026-10-25 09:00:00'],
            ['amount' => '440.00', 'date' => '2026-11-03 09:00:00'],
        ]);

        $this->assertSame(440, $r['fields']['current_balance'], '1319.89 - 880 = 439.89');
        $this->assertSame('20261025', $r['fields']['date_of_last_payment']);
        $this->assertSame(0, $r['fields']['amount_overdue'], 'two instalments were due and two were paid');
    }

    public function testArrearsAreWorkedOutFromTheFirstDueMonthAndLabelledAnEstimate(): void
    {
        $r = $this->map($this->loan(), [['amount' => '440.00', 'date' => '2026-09-25 09:00:00']]);

        // due 25 Sep and 25 Oct: 879.93 expected, 440 paid
        $this->assertSame(440, $r['fields']['amount_overdue']);
        $this->assertSame(1, $r['fields']['months_in_arrears']);
        $this->assertContains('arrears are an estimate (the old system keeps no instalment schedule)', $r['notes']);
    }

    public function testNothingIsOverdueBeforeTheFirstInstalmentFalls(): void
    {
        $r = $this->map($this->loan(['date_released' => '2026-10-20 10:00:00']), [], '2026-11-00');

        $this->assertSame(0, $r['fields']['amount_overdue']);
        $this->assertSame(0, $r['fields']['months_in_arrears']);
    }

    public function testAMissingOrBrokenFirstDueDateGivesNilArrearsAndSaysSo(): void
    {
        foreach ([null, '', '0000-00-00', '2026-13-00'] as $firstDue) {
            $r = $this->map($this->loan(), [], $firstDue);
            $this->assertSame(0, $r['fields']['amount_overdue']);
            $this->assertContains('no usable first due date: arrears could not be worked out and are reported as nil', $r['notes']);
        }
    }

    public function testArrearsMonthsNeverExceedTheTerm(): void
    {
        $r = $this->map($this->loan(['months' => 2]), [], '2025-01-00');

        $this->assertSame(2, $r['fields']['months_in_arrears']);
        $this->assertSame(1320, $r['fields']['amount_overdue']);
    }

    public function testAOneMonthLoanIsTypeMWithNoTerm(): void
    {
        $f = $this->map($this->loan(['months' => 1]))['fields'];

        $this->assertSame('M', $f['type_of_account']);
        $this->assertSame(0, $f['terms']);
    }

    // ---- what is left out -----------------------------------------------------------------------------------------------

    /** @dataProvider leftOut */
    public function testLoansThatDoNotBelongInThisMonthsFileAreLeftOutWithAReason(array $override, array $payments, string $reason): void
    {
        $r = $this->map($this->loan($override), $payments);

        $this->assertNull($r['fields']);
        $this->assertSame($reason, $r['excluded']);
    }

    public static function leftOut(): array
    {
        return [
            'still a request' => [['status' => 0], [], 'status is not a reportable one'],
            'denied' => [['status' => 4], [], 'status is not a reportable one'],
            'released after the as-of date' => [['date_released' => '2026-11-02 10:00:00'], [], 'released after the as-of date'],
            'no release date' => [['date_released' => '0000-00-00 00:00:00'], [], 'the loan has no release date'],
            'completed in an earlier month' => [['status' => 3], [['amount' => '1319.89', 'date' => '2026-09-10 10:00:00']], 'closed before this month'],
            'completed with no payment at all' => [['status' => 3], [], 'closed before this month'],
            'fully paid but still marked released, closed earlier' => [[], [['amount' => '1319.89', 'date' => '2026-09-25 10:00:00']], 'closed before this month'],
        ];
    }

    public function testALoanClosedThisMonthIsReportedAsClosedWithNothingOwing(): void
    {
        foreach ([3, 2] as $status) {   // marked completed, or fully paid but still marked released
            $f = $this->map($this->loan(['status' => $status]), [['amount' => '1319.89', 'date' => '2026-10-12 10:00:00']])['fields'];

            $this->assertSame('C', $f['status_code']);
            $this->assertSame('20261012', $f['status_date'], 'the day of the last payment');
            $this->assertSame(0, $f['current_balance']);
            $this->assertSame(0, $f['amount_overdue']);
            $this->assertSame(0, $f['months_in_arrears']);
        }
    }

    public function testAWrittenOffLoanIsReportedWithItsWriteOffDateAndStillHasAnInstalment(): void
    {
        $r = $this->map($this->loan(['status' => 5, 'date_written_off' => '2026-03-28']), [['amount' => '100.00', 'date' => '2026-01-10 10:00:00']]);

        $this->assertSame('W', $r['fields']['status_code']);
        $this->assertSame('20260328', $r['fields']['status_date']);
        $this->assertSame(1220, $r['fields']['current_balance'], 'what is still owing');
        $this->assertGreaterThan(0, $r['fields']['instalment_amount'], 'Creditinfo rule CUSTNAM074');
        $this->assertSame(0, $r['fields']['amount_overdue']);
    }

    public function testAMissingWriteOffDateFallsBackToTheAsOfDateAndSaysSo(): void
    {
        $r = $this->map($this->loan(['status' => 5, 'date_written_off' => '0000-00-00']));

        $this->assertSame('20261031', $r['fields']['status_date']);
        $this->assertContains('written off but the write-off date is missing: the as-of date was used', $r['notes']);
    }

    public function testALoanWrittenOffAfterTheAsOfDateIsStillAnOrdinaryLoanAtThatDate(): void
    {
        $f = $this->map($this->loan(['status' => 5, 'date_written_off' => '2026-12-01']))['fields'];

        $this->assertArrayNotHasKey('status_code', $f);
    }

    // ---- who it is --------------------------------------------------------------------------------------------------------

    public function testAnElevenDigitNamibianIdGivesTheDateOfBirth(): void
    {
        $f = $this->map($this->loan(['id_number' => '85051500123']))['fields'];

        $this->assertSame('85051500123', $f['na_id']);
        $this->assertSame('', $f['non_na_id']);
        $this->assertSame('19850515', $f['date_of_birth']);
    }

    public function testTheCenturyOfTheBirthYearIsChosenSoTheDateIsNeverInTheFuture(): void
    {
        $this->assertSame('20050102', $this->map($this->loan(['id_number' => '05010200123']))['fields']['date_of_birth']);
        $this->assertSame('19990102', $this->map($this->loan(['id_number' => '99010200123']))['fields']['date_of_birth']);
    }

    public function testTheIdIsReadFromTheIdentityColumnWhenTheIdNumberIsEmptyAndSeparatorsAreIgnored(): void
    {
        $f = $this->map($this->loan(['id_number' => '', 'identity' => '850515 00123']))['fields'];

        $this->assertSame('85051500123', $f['na_id']);
    }

    /** @dataProvider badIdentities */
    public function testAnIdThatCannotBeUsedIsFlaggedAndBlockedByTheValidator(string $idNumber, string $identity, string $note, string $blockedField): void
    {
        $r = $this->map($this->loan(['id_number' => $idNumber, 'identity' => $identity]));

        $this->assertContains($note, $r['notes']);
        $this->assertContains($blockedField, $this->blocking($r['fields']));
    }

    public static function badIdentities(): array
    {
        return [
            'no ID at all' => ['', '', 'no ID number', 'na_id'],
            'a passport-like number' => ['N1234567', '', 'the ID number is not a Namibian 11-digit ID and no date of birth is known', 'date_of_birth'],
            'eleven digits but an impossible date' => ['85139900123', '', 'the ID number does not give a real date of birth', 'date_of_birth'],
        ];
    }

    public function testNamesAreTidiedAndMissingOnesBlockTheRecord(): void
    {
        $f = $this->map($this->loan(['firstname' => "  Maria\n Anna ", 'lastname' => '  Test  ']))['fields'];
        $this->assertSame('Maria Anna', $f['forename1']);
        $this->assertSame('Test', $f['surname']);

        $blocked = $this->blocking($this->map($this->loan(['firstname' => '', 'lastname' => '']))['fields']);
        $this->assertContains('surname', $blocked);
        $this->assertContains('forename1', $blocked);
    }

    public function testThePhoneKeepsDigitsOnlyAndTheAddressIsBrokenIntoWholeWords(): void
    {
        $f = $this->map($this->loan(['contact_no' => '+264 (81) 234-5678', 'address' => 'Erf 12 Test Street Katutura Windhoek Khomas Region Namibia']))['fields'];

        $this->assertSame('264812345678', $f['cellular_telephone']);
        $this->assertSame('Erf 12 Test Street', $f['residential_line1']);
        $this->assertSame('Katutura Windhoek Khomas', $f['residential_line2']);
        $this->assertSame('Region Namibia', $f['residential_line3']);
    }

    public function testAVeryLongSingleWordAddressIsCutNotLost(): void
    {
        $f = $this->map($this->loan(['address' => str_repeat('x', 60)]))['fields'];

        $this->assertSame(25, strlen($f['residential_line1']));
    }

    public function testIncomeIsTheGrossSalaryAsAWholeNumber(): void
    {
        $f = $this->map($this->loan(['gross_salary' => '9500.50']))['fields'];

        $this->assertSame(9501, $f['income']);
        $this->assertSame('M', $f['income_frequency']);
    }

    // ---- the account number ------------------------------------------------------------------------------------------------

    public function testTheAccountNumberComesFromTheInternalIdAndIsStable(): void
    {
        $this->assertSame('SDL0001234', $this->map($this->loan())['fields']['account_no']);
        $this->assertSame('SDL0001234', $this->map($this->loan(['ref_no' => 'SDLOAN0000001']))['fields']['account_no'], 'the typed reference (not unique in the old system) plays no part');
        $this->assertSame('SDL0004358', LegacyCplMapper::accountNumber('SDL', 4358));
        $this->assertSame('XX0000007', LegacyCplMapper::accountNumber('XX', 7));
    }

    public function testAGoodRecordPassesTheRealValidatorWithNothingBlocking(): void
    {
        $f = $this->map($this->loan(), [['amount' => '440.00', 'date' => '2026-09-25 09:00:00']])['fields'];
        $f['data'] = 'D';

        $this->assertSame([], $this->blocking($f));
    }
}
