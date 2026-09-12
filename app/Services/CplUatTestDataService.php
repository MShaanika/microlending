<?php

namespace App\Services;

/**
 * Builds synthetic CPL field maps for Creditinfo's sign-off testing --
 * NEVER a real borrower/loan row. Uses only the three National IDs
 * Creditinfo has approved for UAT (the same three CreditinfoUatTestCentreController
 * already uses for CBS search testing), so the same "do not use a real
 * production customer record" rule applies uniformly across both the CBS
 * and CPL sides of this integration.
 *
 * Every synthetic record is clearly labelled (surname/employer/account
 * number all say TEST) so it can never be mistaken for real data if it
 * were ever displayed or exported next to genuine records.
 */
class CplUatTestDataService
{
    public const UAT_TEST_IDS = ['77082851070', '8005041272341', '74082851070'];

    /**
     * One synthetic Account Type P (multi-month) record, Active, for the
     * CPL Monthly sign-off test file.
     */
    public function monthlyTestRecord(string $monthEnd): array
    {
        return [
            'data' => 'D',
            'na_id' => self::UAT_TEST_IDS[0],
            'non_na_id' => '',
            'gender' => 'M',
            'date_of_birth' => '19740828',
            'branch_code' => 'HO',
            'account_no' => 'UATTEST-MONTHLY-001',
            'surname' => 'CREDITINFOUATTEST',
            'title' => 'MR',
            'forename1' => 'Sign',
            'forename2' => 'Off',
            'residential_line1' => '1 UAT Test Street',
            'residential_line3' => 'Windhoek',
            'residential_postal_code' => '10001',
            'owner_tenant' => 'T',
            'ownership_type' => '00',
            'loan_reason_code' => 'O',
            'payment_type' => '00',
            'type_of_account' => 'P',
            'date_account_opened' => date('Y-m-d', strtotime('-6 months', strtotime($monthEnd))),
            'date_of_last_payment' => date('Y-m-d', strtotime('-1 month', strtotime($monthEnd))),
            'opening_balance' => 5000,
            'current_balance' => 3000,
            'current_balance_indicator' => 'D',
            'amount_overdue' => 0,
            'instalment_amount' => 900,
            'months_in_arrears' => 0,
            'repayment_frequency' => 3,
            'terms' => 6,
            'cellular_telephone' => '0811234567',
            'employer_detail' => 'Solid Desert UAT Test Employer',
            'income' => 8500,
            'income_frequency' => 'M',
            'occupation' => 'Tester',
        ];
    }

    /**
     * Two synthetic Daily records: a new Registration (Account Type M, one
     * month, using the second approved test ID) and a Closure of an
     * existing account (Account Type P, using the third approved test ID)
     * -- covering both event types the Daily Layout process rules require
     * (CPLv1-1.pdf p.20-21).
     */
    public function dailyTestRecords(string $transactionDate): array
    {
        $registration = [
            'data' => 'R',
            'na_id' => self::UAT_TEST_IDS[1],
            'non_na_id' => '',
            'gender' => 'F',
            'date_of_birth' => '19800504',
            'branch_code' => 'HO',
            'account_no' => 'UATTEST-DAILY-REG-001',
            'surname' => 'CREDITINFOUATTEST',
            'title' => 'MS',
            'forename1' => 'Daily',
            'forename2' => 'Registration',
            'residential_line1' => '2 UAT Test Street',
            'residential_line3' => 'Windhoek',
            'residential_postal_code' => '10001',
            'owner_tenant' => 'O',
            'ownership_type' => '00',
            'loan_reason_code' => 'O',
            'payment_type' => '00',
            'type_of_account' => 'M',
            'date_account_opened' => $transactionDate,
            'date_of_last_payment' => '',
            'opening_balance' => 1500,
            'current_balance' => 1500,
            'current_balance_indicator' => 'D',
            'amount_overdue' => 0,
            'instalment_amount' => 1500,
            'months_in_arrears' => 0,
            'repayment_frequency' => 3,
            'terms' => 0,
            'cellular_telephone' => '0817654321',
            'employer_detail' => 'Solid Desert UAT Test Employer',
            'income' => 6000,
            'income_frequency' => 'M',
            'occupation' => 'Tester',
        ];

        $closure = [
            'data' => 'C',
            'na_id' => self::UAT_TEST_IDS[2],
            'non_na_id' => '',
            'gender' => 'M',
            'date_of_birth' => '19740828',
            'branch_code' => 'HO',
            'account_no' => 'UATTEST-DAILY-CLOSE-001',
            'surname' => 'CREDITINFOUATTEST',
            'title' => 'MR',
            'forename1' => 'Daily',
            'forename2' => 'Closure',
            'residential_line1' => '3 UAT Test Street',
            'residential_line3' => 'Windhoek',
            'residential_postal_code' => '10001',
            'owner_tenant' => 'T',
            'ownership_type' => '00',
            'loan_reason_code' => 'O',
            'payment_type' => '00',
            'type_of_account' => 'P',
            'date_account_opened' => date('Y-m-d', strtotime('-6 months', strtotime($transactionDate))),
            'date_of_last_payment' => $transactionDate,
            'opening_balance' => 4000,
            'current_balance' => 0,
            'current_balance_indicator' => 'D',
            'amount_overdue' => 0,
            'instalment_amount' => 0,
            'months_in_arrears' => 0,
            'repayment_frequency' => 3,
            'terms' => 6,
            'status_code' => 'C',
            'status_date' => $transactionDate,
            'cellular_telephone' => '0812345678',
            'employer_detail' => 'Solid Desert UAT Test Employer',
            'income' => 7000,
            'income_frequency' => 'M',
            'occupation' => 'Tester',
        ];

        return [$registration, $closure];
    }
}
