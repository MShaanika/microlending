<?php

namespace Tests\Unit;

use App\Services\CplValidationService;
use PHPUnit\Framework\TestCase;

class CplValidationServiceTest extends TestCase
{
    private CplValidationService $validator;

    protected function setUp(): void
    {
        $this->validator = new CplValidationService();
    }

    private function validRecord(array $overrides = []): array
    {
        return array_merge([
            'na_id' => '7408285107012',
            'non_na_id' => '',
            'date_of_birth' => '19740828',
            'surname' => 'Nangolo',
            'forename1' => 'Petrus',
            'account_no' => 'LN-260910-431B50',
            'type_of_account' => 'P',
            'date_account_opened' => '20260101',
            'opening_balance' => 5000,
            'current_balance' => 4500,
            'instalment_amount' => 900,
            'terms' => 6,
            'months_in_arrears' => 0,
            'amount_overdue' => 0,
            'income' => 8500,
            'income_frequency' => 'M',
            'residential_line1' => '12 Independence Ave',
            'postal_line1' => '',
            'home_telephone' => '',
            'cellular_telephone' => '0811234567',
            'work_telephone' => '',
            'employer_detail' => 'Solid Desert cc',
            'occupation' => 'Clerk',
            'loan_reason_code' => 'H',
            'status_code' => '',
        ], $overrides);
    }

    public function testFullyValidRecordProducesNoIssuesAtAll(): void
    {
        $issues = $this->validator->validate($this->validRecord());
        $this->assertSame([], $issues);
        $this->assertSame('Valid', $this->validator->overallStatus($issues));
    }

    public function testMissingBothIdFieldsIsBlocking(): void
    {
        $issues = $this->validator->validate($this->validRecord(['na_id' => '', 'non_na_id' => '']));
        $this->assertSame('Blocking Error', $this->validator->overallStatus($issues));
        $this->assertTrue($this->hasIssue($issues, 'BLOCKING', 'na_id'));
    }

    public function testNonNaIdWithoutDateOfBirthIsBlocking(): void
    {
        $issues = $this->validator->validate($this->validRecord(['na_id' => '', 'non_na_id' => 'ZW1234567', 'date_of_birth' => '']));
        $this->assertTrue($this->hasIssue($issues, 'BLOCKING', 'date_of_birth'));
    }

    public function testAccountTypeMTermsMustBeZero(): void
    {
        $issues = $this->validator->validate($this->validRecord(['type_of_account' => 'M', 'terms' => 1]));
        $this->assertTrue($this->hasIssue($issues, 'BLOCKING', 'terms'));
    }

    public function testAccountTypeMWithZeroTermsPassesTermsRule(): void
    {
        $issues = $this->validator->validate($this->validRecord(['type_of_account' => 'M', 'terms' => 0]));
        $this->assertFalse($this->hasIssue($issues, 'BLOCKING', 'terms'));
    }

    public function testAccountTypePTermsMustBeGreaterThanZero(): void
    {
        $issues = $this->validator->validate($this->validRecord(['type_of_account' => 'P', 'terms' => 0]));
        $this->assertTrue($this->hasIssue($issues, 'BLOCKING', 'terms'));
    }

    public function testMonthsInArrearsWithoutAmountOverdueIsBlocking(): void
    {
        $issues = $this->validator->validate($this->validRecord(['months_in_arrears' => 2, 'amount_overdue' => 0]));
        $this->assertTrue($this->hasIssue($issues, 'BLOCKING', 'amount_overdue'));
    }

    public function testCurrentBalanceAndInstalmentNotRequiredWhenStatusCodeSupplied(): void
    {
        $issues = $this->validator->validate($this->validRecord([
            'current_balance' => null, 'instalment_amount' => null, 'status_code' => 'C',
        ]));
        $this->assertFalse($this->hasIssue($issues, 'BLOCKING', 'current_balance'));
        $this->assertFalse($this->hasIssue($issues, 'BLOCKING', 'instalment_amount'));
    }

    public function testIncomeWithoutFrequencyIsAWarningNotBlocking(): void
    {
        $issues = $this->validator->validate($this->validRecord(['income' => 8500, 'income_frequency' => '']));
        $this->assertTrue($this->hasIssue($issues, 'WARNING', 'income_frequency'));
        $this->assertSame('Warning', $this->validator->overallStatus($issues));
    }

    public function testDefaultedLoanReasonCodeIsInformationOnly(): void
    {
        $issues = $this->validator->validate($this->validRecord(['loan_reason_code' => 'O']));
        $this->assertTrue($this->hasIssue($issues, 'INFORMATION', 'loan_reason_code'));
        $this->assertSame('Valid', $this->validator->overallStatus($issues));
    }

    public function testOverlongSurnameIsFlaggedAsATruncationWarning(): void
    {
        $issues = $this->validator->validate($this->validRecord(['surname' => str_repeat('X', 30)]));
        $this->assertTrue($this->hasIssue($issues, 'WARNING', 'surname'));
    }

    private function hasIssue(array $issues, string $severity, string $field): bool
    {
        foreach ($issues as $issue) {
            if ($issue['severity'] === $severity && $issue['field'] === $field) {
                return true;
            }
        }
        return false;
    }
}
