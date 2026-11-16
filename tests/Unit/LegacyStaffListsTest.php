<?php

namespace Tests\Unit;

use App\Services\LegacyIdFixList;
use App\Services\LegacySpotCheck;
use PHPUnit\Framework\TestCase;

/**
 * The two lists for staff: customers whose ID must be fixed (with a lead from the new system's applications) and a sample of accounts to check by hand.
 */
class LegacyStaffListsTest extends TestCase
{
    private function exception(int $borrower, string $name, string $phone, string $ref, array $notes = ['no ID number']): array
    {
        return ['loan_id' => $borrower * 10, 'borrower_id' => $borrower, 'account_no' => 'SDL' . $borrower, 'reasons' => ['na_id: x'], 'notes' => $notes, 'name' => $name, 'ref_no' => $ref, 'phone' => $phone];
    }

    private function app(string $id, string $first, string $last, string $phone): array
    {
        return ['id_number' => $id, 'first_name' => $first, 'last_name' => $last, 'phone' => $phone];
    }

    public function testAPhoneAndSurnameMatchIsAHighConfidenceSuggestion(): void
    {
        $rows = (new LegacyIdFixList())->build(
            [$this->exception(1, 'Maria Test', '081 234 5678', 'SDLOAN1')],
            [$this->app('85051500123', 'Maria', 'TEST', '+264 81 234 5678')]
        );

        $this->assertCount(1, $rows);
        $this->assertSame('85051500123', $rows[0]['suggested_id']);
        $this->assertSame('high', $rows[0]['confidence']);
        $this->assertSame('phone number and surname', $rows[0]['matched_with']);
    }

    public function testAPhoneMatchAloneIsOnlyMediumAndASurnameMatchWinsOverIt(): void
    {
        $rows = (new LegacyIdFixList())->build(
            [$this->exception(1, 'Maria Test', '0812345678', 'A'), $this->exception(2, 'Paul Other', '0819999999', 'B')],
            [$this->app('11111111111', 'Someone', 'Else', '0812345678'), $this->app('85051500123', 'Maria', 'Test', '0812345678'), $this->app('22222222222', 'X', 'Y', '0819999999')]
        );
        $byId = array_column($rows, null, 'borrower_id');

        $this->assertSame('85051500123', $byId[1]['suggested_id'], 'two applications share the phone: the one with the same surname is chosen');
        $this->assertSame('high', $byId[1]['confidence']);
        $this->assertSame('22222222222', $byId[2]['suggested_id']);
        $this->assertSame('medium', $byId[2]['confidence']);
        $this->assertSame('phone number only', $byId[2]['matched_with']);
    }

    public function testFirstAndLastNameWithoutAPhoneMatchIsMedium(): void
    {
        $rows = (new LegacyIdFixList())->build(
            [$this->exception(1, 'Maria Test', '', 'A')],
            [$this->app('85051500123', 'maria', 'test', '0000000000')]
        );

        $this->assertSame('85051500123', $rows[0]['suggested_id']);
        $this->assertSame('medium', $rows[0]['confidence']);
    }

    public function testNoMatchGivesNoSuggestionAndSurnameAloneIsNotEnough(): void
    {
        $rows = (new LegacyIdFixList())->build(
            [$this->exception(1, 'Maria Test', '0812345678', 'A')],
            [$this->app('85051500123', 'Anna', 'Test', '0800000000'), $this->app('', 'Maria', 'Test', '0812345678')]
        );

        $this->assertSame('', $rows[0]['suggested_id']);
        $this->assertSame('', $rows[0]['confidence']);
    }

    public function testACustomerWithSeveralLoansIsOneRowAndShortPhonesNeverMatch(): void
    {
        $rows = (new LegacyIdFixList())->build(
            [$this->exception(1, 'Maria Test', '123', 'L1'), $this->exception(1, 'Maria Test', '123', 'L2', ['the ID number is not a Namibian 11-digit ID and no date of birth is known'])],
            [$this->app('85051500123', 'Other', 'Person', '123')]
        );

        $this->assertCount(1, $rows);
        $this->assertSame('L1 L2', $rows[0]['loans']);
        $this->assertSame('', $rows[0]['suggested_id'], 'a phone of fewer than nine digits is not a key');
        $this->assertStringContainsString('not a Namibian 11-digit ID', $rows[0]['problem']);
    }

    public function testRowsWithALeadComeFirst(): void
    {
        $rows = (new LegacyIdFixList())->build(
            [$this->exception(1, 'Zed Nolead', '0811111111', 'A'), $this->exception(2, 'Ann Lead', '0822222222', 'B')],
            [$this->app('85051500123', 'Ann', 'Lead', '0822222222')]
        );

        $this->assertSame([2, 1], array_column($rows, 'borrower_id'));
    }

    // ---- the sample ----------------------------------------------------------------------------------------------------

    private function details(): array
    {
        $d = [];
        foreach (['active, in arrears' => 100, 'active' => 100, 'C' => 60, 'W' => 7] as $kind => $n) {
            for ($i = 1; $i <= $n; $i++) {
                $d[] = ['loan_id' => count($d) + 1, 'kind' => $kind];
            }
        }
        return $d;
    }

    public function testTheSampleIsThirtyAccountsWeightedTowardsRiskAndSortedForReading(): void
    {
        $s = (new LegacySpotCheck())->sample($this->details());

        $this->assertCount(30, $s);
        $kinds = array_count_values(array_column($s, 'kind'));
        $this->assertSame(['active, in arrears' => 12, 'active' => 8, 'C' => 6, 'W' => 4], $kinds);
        $ids = array_column($s, 'loan_id');
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids);
    }

    public function testTheSameSeedGivesTheSameAccountsAndAnotherSeedGivesOthers(): void
    {
        $a = array_column((new LegacySpotCheck())->sample($this->details(), 30, 'x'), 'loan_id');

        $this->assertSame($a, array_column((new LegacySpotCheck())->sample($this->details(), 30, 'x'), 'loan_id'));
        $this->assertNotSame($a, array_column((new LegacySpotCheck())->sample($this->details(), 30, 'y'), 'loan_id'));
    }

    public function testASmallBookGivesEverythingItHasAndNeverMoreThanAsked(): void
    {
        $small = [['loan_id' => 1, 'kind' => 'W'], ['loan_id' => 2, 'kind' => 'active'], ['loan_id' => 3, 'kind' => 'C']];

        $this->assertCount(3, (new LegacySpotCheck())->sample($small, 30));
        $this->assertCount(2, (new LegacySpotCheck())->sample($small, 2));
        $this->assertSame([], (new LegacySpotCheck())->sample([], 30));
    }

    public function testAShortGroupIsMadeUpFromTheOthers(): void
    {
        $d = [];
        for ($i = 1; $i <= 40; $i++) {
            $d[] = ['loan_id' => $i, 'kind' => 'active'];
        }

        $this->assertCount(30, (new LegacySpotCheck())->sample($d, 30));
    }
}
