<?php

namespace App\Services;

use App\Core\Database;

/**
 * Maps Solid Desert's own data (borrowers/loans/schedules/payments) onto
 * CPLv1.1 field names -- see CplRecordBuilder for the byte-position
 * formatting engine and CPLv1-1.pdf (Account Type P field spec, pp.200-210,
 * and its Status Code table, pp.207-208) for every rule referenced below.
 * Only account types M (term_months == 1) and P (everything else) are
 * built -- see the approved plan; every other account type is out of scope.
 *
 * Known first-pass simplifications, flagged for review before the first
 * live bureau submission (not before -- there is no bureau credential yet
 * to submit to):
 * - loanBalances() reads the LIVE loan_schedules paid/due columns, not a
 *   reconstruction of what was paid strictly as-of $monthEnd. Correct for
 *   generating the extract promptly after each month end (the intended use);
 *   wrong for a late/backfilled run against a month that has since had more
 *   payments posted.
 * - statusCode() only detects Written Off, Completed (Closed/Early
 *   Settlement). Disputed (D), Terms Extended (E), Cooling-Off Settlement
 *   (V) and Deceased (Z) have no equivalent tracked anywhere in this system
 *   yet and are never emitted -- apply those manually if/when they occur.
 */
class CplExporter
{
    /** Status codes whose financial fields must be zeroed per the spec (p.206). */
    private const ZERO_BALANCE_STATUS_CODES = ['C', 'V', 'X', 'T'];

    private CplRecordBuilder $builder;

    public function __construct()
    {
        $this->builder = new CplRecordBuilder();
    }

    /**
     * NA ID numbers are 13 digits; Non-NA IDs are anything else. Fields 2
     * and 3 are mutually exclusive -- see demographic field rules, p.28.
     * Full Home Affairs check-digit validation is explicitly deferred.
     */
    private function isNaId(?string $idNumber): bool
    {
        return $idNumber !== null && preg_match('/^\d{13}$/', $idNumber) === 1;
    }

    /**
     * $row is one joined loans+borrowers+borrower_employment record, as
     * produced by buildMonthly()'s query -- kept as a single flat row
     * rather than nested arrays since that's the shape the data already
     * comes back in.
     */
    public function borrowerFields(array $row): array
    {
        $idNumber = $row['id_number'] ?? '';
        $isNa = $this->isNaId($idNumber);

        $gender = match ($row['gender'] ?? null) {
            'Male' => 'M',
            'Female' => 'F',
            default => '',
        };

        return [
            'non_na_id' => $isNa ? '' : $idNumber,
            'na_id' => $isNa ? $idNumber : '',
            'gender' => $gender,
            'date_of_birth' => $row['date_of_birth'] ?? null,
            'surname' => $row['last_name'] ?? '',
            'title' => $row['title'] ?? '',
            'forename1' => $row['first_name'] ?? '',
            'forename2' => $row['middle_name'] ?? '',
            'residential_line1' => $row['residential_line1'] ?? '',
            'residential_line2' => $row['residential_line2'] ?? '',
            'residential_line3' => $row['residential_line3'] ?? '',
            'residential_line4' => $row['residential_line4'] ?? '',
            'residential_postal_code' => $row['residential_postal_code'] ?? '',
            'owner_tenant' => $row['residential_ownership'] ?? '',
            'postal_line1' => $row['postal_line1'] ?? '',
            'postal_line2' => $row['postal_line2'] ?? '',
            'postal_line3' => $row['postal_line3'] ?? '',
            'postal_line4' => $row['postal_line4'] ?? '',
            'postal_postal_code' => $row['postal_postal_code'] ?? '',
            'ownership_type' => $row['ownership_type'] ?? '00',
            'cellular_telephone' => $row['phone'] ?? '',
            'work_telephone' => $row['employer_phone'] ?? '',
            'employer_detail' => $row['employer_name'] ?? '',
            'income' => isset($row['net_salary']) ? (int) round((float) $row['net_salary']) : 0,
            'income_frequency' => $row['income_frequency'] ?? '',
            'occupation' => $row['job_title'] ?? '',
        ];
    }

    public function loanFields(array $row, string $monthEnd): array
    {
        $accountType = (int) $row['term_months'] === 1 ? 'M' : 'P';
        $balances = $this->loanBalances((int) $row['id'], $monthEnd);

        return [
            'branch_code' => $row['branch_code'] ?? '',
            'account_no' => $row['loan_no'] ?? '',
            'loan_reason_code' => $row['loan_reason_code'] ?? 'O',
            'type_of_account' => $accountType,
            'date_account_opened' => $row['start_date'] ?? null,
            'date_of_last_payment' => $this->lastPaymentDate((int) $row['id'], $monthEnd),
            'opening_balance' => (int) round((float) ($row['principal_amount'] ?? 0)),
            'current_balance' => $balances['current_balance'],
            'current_balance_indicator' => 'D',
            'amount_overdue' => $balances['amount_overdue'],
            'instalment_amount' => (int) round((float) ($row['installment_amount'] ?? 0)),
            'months_in_arrears' => $balances['months_in_arrears'],
            'repayment_frequency' => 3,
            'terms' => (int) ($row['term_months'] ?? 0),
        ];
    }

    /**
     * Current balance (capital owing including interest/fees as at month
     * end -- NOT ArrearsService::loanOutstanding(), which is cash-basis
     * principal-only), amount overdue (cumulative missed payments), and
     * months in arrears (one loan_schedules row = one month, since every
     * Solid Desert loan repays monthly) -- all computed fresh from
     * loan_schedules per the Account Type P Financial Field rules (p.206-207).
     */
    private function loanBalances(int $loanId, string $monthEnd): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT
                SUM(total_due - total_paid) AS current_balance,
                SUM(CASE WHEN due_date <= ? AND total_due > total_paid
                         THEN total_due - total_paid ELSE 0 END) AS amount_overdue,
                SUM(CASE WHEN due_date <= ? AND total_due > total_paid THEN 1 ELSE 0 END) AS months_in_arrears
             FROM loan_schedules
             WHERE loan_id = ?"
        );
        $stmt->execute([$monthEnd, $monthEnd, $loanId]);
        $row = $stmt->fetch() ?: [];

        return [
            'current_balance' => max(0, (int) round((float) ($row['current_balance'] ?? 0))),
            'amount_overdue' => max(0, (int) round((float) ($row['amount_overdue'] ?? 0))),
            // Field width is 2 digits; the spec says values over 9 are still
            // accepted (bureau just displays them as "9"), so only cap at
            // the field's own 99 ceiling, not at 9.
            'months_in_arrears' => min(99, (int) ($row['months_in_arrears'] ?? 0)),
        ];
    }

    private function lastPaymentDate(int $loanId, string $monthEnd): ?string
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT MAX(payment_date) FROM payments
             WHERE loan_id = ? AND status = 'Posted' AND payment_date <= ?"
        );
        $stmt->execute([$loanId, $monthEnd]);
        $date = $stmt->fetchColumn();
        return $date ?: null;
    }

    /**
     * A loan closed with its last schedule payment settled before
     * maturity_date counts as Early Settlement (T) rather than a plain
     * Closed (C) -- see the Status Code table, p.208.
     */
    private function settledEarly(array $row): bool
    {
        if (empty($row['maturity_date'])) {
            return false;
        }
        $db = Database::connection();
        $stmt = $db->prepare("SELECT MAX(paid_at) FROM loan_schedules WHERE loan_id = ?");
        $stmt->execute([$row['id']]);
        $lastPaidAt = $stmt->fetchColumn();
        return $lastPaidAt && strtotime($lastPaidAt) < strtotime($row['maturity_date']);
    }

    /**
     * A status is submitted once, in the month it occurs, then omitted
     * from every later monthly run unless it changes -- the Status Code
     * Process Rules (p.207). cpl_status_history tracks the last code
     * actually sent per loan so re-running the same month's export never
     * resends an unchanged status. Ongoing Active/Current loans get no
     * status code at all (field 38 stays blank); only a genuine
     * closed/written-off/settled transition returns one here.
     */
    public function statusCode(array $row, string $monthEnd): ?array
    {
        $status = $row['loan_status'] ?? '';

        $code = match (true) {
            $status === 'Written Off' => 'W',
            $status === 'Completed' && $this->settledEarly($row) => 'T',
            $status === 'Completed' => 'C',
            default => null,
        };

        if ($code === null) {
            return null;
        }

        $db = Database::connection();
        $stmt = $db->prepare("SELECT status_code FROM cpl_status_history WHERE loan_id = ?");
        $stmt->execute([(int) $row['id']]);
        if ($stmt->fetchColumn() === $code) {
            return null;
        }

        return ['status_code' => $code, 'status_date' => $monthEnd];
    }

    private function recordStatusSent(int $loanId, string $code, string $monthEnd): void
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "INSERT INTO cpl_status_history (loan_id, status_code, status_date, submitted_month_end)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE status_code = VALUES(status_code),
                 status_date = VALUES(status_date), submitted_month_end = VALUES(submitted_month_end)"
        );
        $stmt->execute([$loanId, $code, $monthEnd, $monthEnd]);
    }

    /**
     * Every loan that has had an account opened (Approved/Pending/Denied
     * loans never disbursed, so they're excluded entirely) and existed by
     * $monthEnd, built into one CRLF-joined CPLv1.1 monthly file: header,
     * one 700-char record per loan, trailer.
     */
    public function buildMonthly(string $monthEnd, string $supplierRef, string $tradingName): string
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT l.*, br.branch_code,
                    b.first_name, b.middle_name, b.last_name, b.gender, b.date_of_birth,
                    b.id_number, b.title, b.ownership_type, b.residential_ownership,
                    b.residential_line1, b.residential_line2, b.residential_line3, b.residential_line4,
                    b.residential_postal_code, b.postal_line1, b.postal_line2, b.postal_line3,
                    b.postal_line4, b.postal_postal_code, b.phone,
                    be.employer_name, be.employer_phone, be.job_title, be.net_salary, be.income_frequency
             FROM loans l
             JOIN branches br ON br.id = l.branch_id
             JOIN borrowers b ON b.id = l.borrower_id
             LEFT JOIN borrower_employment be ON be.borrower_id = b.id
             WHERE l.loan_status IN ('Active','Current','Completed','Written Off')
               AND l.start_date <= ?
             ORDER BY l.id"
        );
        $stmt->execute([$monthEnd]);
        $loans = $stmt->fetchAll();

        $lines = [];
        foreach ($loans as $row) {
            $fields = array_merge(
                ['data' => 'D'],
                $this->borrowerFields($row),
                $this->loanFields($row, $monthEnd)
            );

            $statusResult = $this->statusCode($row, $monthEnd);
            if ($statusResult !== null) {
                $fields = array_merge($fields, $statusResult);
                $this->recordStatusSent((int) $row['id'], $statusResult['status_code'], $monthEnd);

                if (in_array($statusResult['status_code'], self::ZERO_BALANCE_STATUS_CODES, true)) {
                    $fields['amount_overdue'] = 0;
                    $fields['instalment_amount'] = 0;
                    $fields['current_balance'] = 0;
                    $fields['months_in_arrears'] = 0;
                }
            }

            $lines[] = $this->builder->record($fields);
        }

        $header = $this->builder->header($supplierRef, $monthEnd, $tradingName);
        $trailer = $this->builder->trailer(count($lines) + 2);

        return implode("\r\n", array_merge([$header], $lines, [$trailer]));
    }
}
