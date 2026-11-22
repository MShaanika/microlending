<?php

namespace App\Services;

use PDO;

/**
 * The loan applications received through the new system, and - once staff have said which ones were approved, for how much and when the money was paid
 * out - the loans they became, in the shape LegacyCplExtract/LegacyCplMapper expect.
 *
 * Assumptions (all shown to the owner, none hidden):
 *  - the loan is priced like every loan in the old loan system: 30% interest, N$5.00 fee and 1.03% levy (the mapper's formula);
 *  - the term is the term applied for;
 *  - no repayment has been made yet (the staff are not asked for payments), so this is only suitable for a month end shortly after the pay-out;
 *  - the account number is the application number, so staff can always trace the account.
 */
final class ApplicationLoanBook implements LegacyLoanSource
{
    public const INTEREST_PERCENTAGE = 30.0;

    /**
     * @param array<int, array{application: array<string,mixed>, amount: float, paid_out: string, pay_day?: int, months?: int, payments?: array<int,array{amount:string,date:string}>}> $approved
     */
    public function __construct(private array $approved)
    {
    }

    /**
     * Applications for the staff sheet (or, with $applicationNumbers, exactly those). Test applicants are left out by full name.
     *
     * @param string[] $excludedNames full names ("First Last") to leave out
     * @param string[]|null $applicationNumbers
     * @return array<int, array<string,mixed>>
     */
    public static function fetch(PDO $pdo, ?string $status, array $excludedNames = [], ?array $applicationNumbers = null): array
    {
        $sql = 'SELECT id, application_no, applicant_first_name, applicant_middle_name, applicant_last_name, applicant_id_number, applicant_gender, applicant_phone,
                       applicant_address, employer_name, gross_salary, payment_day, requested_amount, requested_term_months, status, created_at
                FROM loan_applications WHERE purged_at IS NULL';
        $params = [];
        if ($status !== null && $status !== '') {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }
        if ($applicationNumbers !== null) {
            if ($applicationNumbers === []) {
                return [];
            }
            $sql .= ' AND application_no IN (' . implode(',', array_fill(0, count($applicationNumbers), '?')) . ')';
            array_push($params, ...array_values($applicationNumbers));
        }
        $stmt = $pdo->prepare($sql . ' ORDER BY created_at, id');
        $stmt->execute($params);

        $excluded = array_map([self::class, 'normaliseName'], $excludedNames);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $full = self::normaliseName($row['applicant_first_name'] . ' ' . $row['applicant_last_name']);
            if ($full !== '' && in_array($full, $excluded, true)) {
                continue;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    public function loans(): array
    {
        $out = [];
        foreach ($this->approved as $a) {
            $app = $a['application'];
            $payDay = (int) ($a['pay_day'] ?? $app['payment_day'] ?? 0);
            $payDay = $payDay >= 1 && $payDay <= 31 ? min($payDay, 28) : 25;
            $out[] = [
                'loan' => [
                    'id' => (int) $app['id'],
                    'ref_no' => (string) $app['application_no'],
                    'account_no_override' => (string) $app['application_no'],
                    'borrower_id' => (int) $app['id'],
                    'amount' => $a['amount'],
                    'months' => max(1, (int) ($a['months'] ?? $app['requested_term_months'] ?? 1)),
                    'interest_percentage' => self::INTEREST_PERCENTAGE,
                    'status' => 2,
                    'date_released' => $a['paid_out'],
                    'date_written_off' => null,
                    'firstname' => (string) $app['applicant_first_name'],
                    'middlename' => (string) ($app['applicant_middle_name'] ?? ''),
                    'lastname' => (string) $app['applicant_last_name'],
                    'id_number' => (string) ($app['applicant_id_number'] ?? ''),
                    'identity' => '',
                    'gender' => (string) ($app['applicant_gender'] ?? ''),
                    'contact_no' => (string) ($app['applicant_phone'] ?? ''),
                    'address' => (string) ($app['applicant_address'] ?? ''),
                    'company_name' => (string) ($app['employer_name'] ?? ''),
                    'gross_salary' => (float) ($app['gross_salary'] ?? 0),
                    'payment_day' => $payDay,
                ],
                'payments' => $a['payments'] ?? [],
                'first_due' => substr(self::firstInstalmentDate($a['paid_out'], $payDay), 0, 8) . '00',
            ];
        }
        return $out;
    }

    /**
     * The date of the first instalment: the borrower's pay day (20, 25 or 30; 28 at most, exactly as LegacyCplMapper counts it), in the pay-out month when that
     * day is still ahead of the pay-out, otherwise in the next month.
     */
    public static function firstInstalmentDate(string $paidOut, int $payDay): string
    {
        $payDay = $payDay >= 1 && $payDay <= 31 ? min($payDay, 28) : 25;
        $month = (int) substr($paidOut, 5, 2);
        $year = (int) substr($paidOut, 0, 4);
        if ($payDay <= (int) substr($paidOut, 8, 2)) {
            $month++;
            if ($month > 12) {
                $month = 1;
                $year++;
            }
        }
        return sprintf('%04d-%02d-%02d', $year, $month, $payDay);
    }

    private static function normaliseName(string $name): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', $name)));
    }
}
