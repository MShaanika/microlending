<?php

namespace App\Services;

use App\Core\Database;
use App\Models\CplSetting;

/**
 * Maps Solid Desert's own data (borrowers/loans/schedules/payments) onto
 * CPLv1.1 field names -- see CplRecordBuilder for the byte-position
 * formatting engine and CPLv1-1.pdf (Account Type M field spec, pp.168-178;
 * Account Type P field spec, pp.200-210; Status Code table, pp.41-43 and
 * 174-175/207-208; Payment Type & Priority table, p.47-48) for every rule
 * referenced below. Only account types M (one-month personal loan) and P
 * (personal loan, term > 1 month) are built -- every other account type is
 * out of scope.
 *
 * Known first-pass simplifications, flagged for review before the first
 * live bureau submission (not before -- there is no bureau credential yet
 * to submit to):
 * - loanBalances() reads the LIVE loan_schedules paid/due columns, not a
 *   reconstruction of what was paid strictly as-of $monthEnd. Correct for
 *   generating the extract promptly after each month end (the intended use);
 *   wrong for a late/backfilled run against a month that has since had more
 *   payments posted.
 * - statusCode() detects Written Off, Completed (Closed/Early Settlement),
 *   and Terms Extended (via loan_reschedules). Disputed (D), Cooling-Off
 *   Settlement (V), Paid-up Default (X) and Deceased (Z) have no equivalent
 *   tracked anywhere in this system yet and are never emitted -- apply those
 *   manually if/when they occur.
 * - A loan restructured more than once will only have its FIRST Terms
 *   Extended event correctly suppressed from resubmission -- see
 *   rescheduleTermsExtendedDate()'s docblock for why a second, later
 *   reschedule on the same loan is not currently distinguished from the
 *   first once already recorded in cpl_status_history.
 */
class CplExporter
{
    /** Status codes whose financial fields must be zeroed per the spec (p.41-43). */
    private const ZERO_BALANCE_STATUS_CODES = ['C', 'V', 'X', 'T'];

    private CplRecordBuilder $builder;
    private ?CplSetting $settings = null;

    public function __construct()
    {
        $this->builder = new CplRecordBuilder();
    }

    /**
     * Lazy on purpose: CplSetting extends Model, whose constructor opens a
     * DB connection immediately -- instantiating it eagerly here would make
     * even `new CplExporter()` require a database, breaking the ability to
     * unit-test pure methods like borrowerFields() in isolation the same
     * way CplRecordBuilder is tested.
     */
    private function settings(): CplSetting
    {
        return $this->settings ??= new CplSetting();
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
        $passportNo = $row['passport_no'] ?? '';
        $isNa = $this->isNaId($idNumber);
        // A non-NA applicant's identification lives in the dedicated
        // passport_no column -- id_number is only ever used as a fallback
        // if passport_no was never captured, so a real value is never
        // silently dropped.
        $nonNaId = $isNa ? '' : ($passportNo !== '' ? $passportNo : $idNumber);

        $gender = match ($row['gender'] ?? null) {
            'Male' => 'M',
            'Female' => 'F',
            default => '',
        };

        return [
            'non_na_id' => $nonNaId,
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
            'home_telephone' => $row['home_telephone'] ?? '',
            'cellular_telephone' => $row['phone'] ?? '',
            'work_telephone' => $row['employer_phone'] ?? '',
            'employer_detail' => $row['employer_name'] ?? '',
            // Field 50 is explicitly the consumer's GROSS income (CPLv1-1.pdf
            // p.34) -- net_salary was used here previously, which understated
            // every record; gross_salary is the correct source column.
            'income' => isset($row['gross_salary']) ? (int) round((float) $row['gross_salary']) : 0,
            'income_frequency' => $row['income_frequency'] ?? '',
            'occupation' => $row['job_title'] ?? '',
        ];
    }

    /**
     * M = one-month personal loan, P = personal loan over a term greater
     * than one month (CPLv1-1.pdf p.10). The codes themselves are read from
     * CplSetting so an admin can correct them without a deploy; whether the
     * mapping itself has been confirmed with the bureau is surfaced
     * separately via CplSetting::isAccountTypeMappingConfirmed() for the
     * export UI to warn on -- it does not block generation.
     */
    private function resolveAccountType(int $termMonths): string
    {
        return $termMonths === 1 ? $this->settings()->oneMonthAccountType() : $this->settings()->multiMonthAccountType();
    }

    public function loanFields(array $row, string $monthEnd): array
    {
        $termMonths = (int) ($row['term_months'] ?? 0);
        $oneMonthType = $this->settings()->oneMonthAccountType();
        $accountType = $this->resolveAccountType($termMonths);
        $balances = $this->loanBalances((int) $row['id'], $monthEnd);

        return [
            'branch_code' => $row['branch_code'] ?? '',
            'account_no' => $row['loan_no'] ?? '',
            'loan_reason_code' => $row['loan_reason_code'] ?? 'O',
            'payment_type' => $this->paymentType($row),
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
            // Account type M's Terms field must always be supplied as 0000
            // (CPLv1-1.pdf p.175) -- a one-month loan reports no term, unlike
            // P where Terms must reflect the actual agreed term.
            'terms' => $accountType === $oneMonthType ? 0 : $termMonths,
        ];
    }

    /**
     * Payment Type (field 27) reflects the account's payment-method/state
     * indicator, using the priority hierarchy from the Payment Type &
     * Priority table (CPLv1-1.pdf p.47-48) -- only two non-default codes are
     * currently derivable from Solid Desert's own data:
     *   06 Debt Restructured (priority 3) -- once a reschedule has been
     *      Implemented on this loan, 06 is sent every month thereafter
     *      (spec: "the record may continue to be submitted with a payment
     *      type of 06 thereafter"), not just in the month of the change.
     *   07 Voluntary Debt Consolidation (priority 4) -- loans.loan_type =
     *      'Consolidation'.
     * 06 outranks 07 per the priority table (3 is a higher priority than 4)
     * when both would otherwise apply. Payroll Deduction (01), Deferred
     * Payment (02), Staff Account (03), Administration (04) and Judgement
     * Granted (05) have no equivalent tracked anywhere in this system and
     * are never emitted. Defaults to '00' (Other) rather than being left
     * blank, per the priority table's own lowest-priority default row.
     */
    private function paymentType(array $row): string
    {
        if ($this->hasImplementedReschedule((int) $row['id'])) {
            return '06';
        }
        if (($row['loan_type'] ?? '') === 'Consolidation') {
            return '07';
        }
        return '00';
    }

    private function hasImplementedReschedule(int $loanId): bool
    {
        $db = Database::connection();
        $stmt = $db->prepare("SELECT 1 FROM loan_reschedules WHERE loan_id = ? AND status = 'Implemented' LIMIT 1");
        $stmt->execute([$loanId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Current balance (capital owing including interest/fees as at month
     * end -- NOT ArrearsService::loanOutstanding(), which is cash-basis
     * principal-only), amount overdue (cumulative missed payments), and
     * months in arrears (one loan_schedules row = one month, since every
     * Solid Desert loan repays monthly) -- all computed fresh from
     * loan_schedules per the Account Type P Financial Field rules (p.35-39).
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
     * Closed (C) -- see the Status Code table, p.42-43.
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
     * The effective date of the most recent Implemented reschedule on this
     * loan, as at $monthEnd -- used both to detect a Terms Extended (E)
     * event and as that event's status date.
     *
     * Known limitation: if a loan is restructured a SECOND time after its
     * first Terms Extended code has already been recorded in
     * cpl_status_history, this method returns the second reschedule's
     * (later) date, but statusCode() below only compares the CODE ('E')
     * against what was last sent -- not the date -- so the second
     * restructuring is silently suppressed as "already sent" rather than
     * reported as a new event. A loan restructured more than once needs
     * that second Terms Extended submitted manually until this is refined.
     */
    private function rescheduleTermsExtendedDate(array $row, string $monthEnd): ?string
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT effective_date FROM loan_reschedules
             WHERE loan_id = ? AND status = 'Implemented' AND effective_date <= ?
             ORDER BY effective_date DESC LIMIT 1"
        );
        $stmt->execute([$row['id'], $monthEnd]);
        $date = $stmt->fetchColumn();
        return $date ?: null;
    }

    /**
     * A status is submitted once, in the month it occurs, then omitted
     * from every later monthly run unless it changes -- the Status Code
     * Process Rules (p.40). cpl_status_history tracks the last code
     * actually sent per loan so re-running the same month's export never
     * resends an unchanged status. Ongoing Active/Current loans with no
     * qualifying event get no status code at all (field 38 stays blank).
     */
    public function statusCode(array $row, string $monthEnd): ?array
    {
        $status = $row['loan_status'] ?? '';
        $rescheduleDate = $this->rescheduleTermsExtendedDate($row, $monthEnd);

        $code = match (true) {
            $status === 'Written Off' => 'W',
            $status === 'Completed' && $this->settledEarly($row) => 'T',
            $status === 'Completed' => 'C',
            $rescheduleDate !== null => 'E',
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

        $statusDate = $code === 'E' ? $rescheduleDate : $monthEnd;

        return ['status_code' => $code, 'status_date' => $statusDate];
    }

    /**
     * Public so CplBatchController can call this at APPROVAL time for a
     * batch built via CplSnapshotService (which generates with
     * persistStatus=false, since a Draft/Ready-for-Review batch can be
     * regenerated more than once before approval -- only an approved batch
     * may permanently mark a status as sent).
     */
    public function recordStatusSent(int $loanId, string $code, string $monthEnd): void
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
     * Every loan eligible for the CPL extract as at $monthEnd -- the exact
     * same row shape borrowerFields()/loanFields()/statusCode() expect.
     * Extracted from buildMonthly() so CplSnapshotService can reuse the
     * identical eligibility rule and joined data when building an
     * inspectable, immutable snapshot instead of a straight-to-file export.
     */
    public function eligibleLoans(string $monthEnd): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT l.*, br.branch_code,
                    b.first_name, b.middle_name, b.last_name, b.gender, b.date_of_birth,
                    b.id_number, b.passport_no, b.title, b.ownership_type, b.residential_ownership,
                    b.residential_line1, b.residential_line2, b.residential_line3, b.residential_line4,
                    b.residential_postal_code, b.postal_line1, b.postal_line2, b.postal_line3,
                    b.postal_line4, b.postal_postal_code, b.phone, b.home_telephone,
                    be.employer_name, be.employer_phone, be.job_title, be.gross_salary, be.income_frequency
             FROM loans l
             JOIN branches br ON br.id = l.branch_id
             JOIN borrowers b ON b.id = l.borrower_id
             LEFT JOIN borrower_employment be ON be.borrower_id = b.id
             WHERE l.loan_status IN ('Active','Current','Completed','Written Off')
               AND l.start_date <= ?
             ORDER BY l.id"
        );
        $stmt->execute([$monthEnd]);
        return $stmt->fetchAll();
    }

    /**
     * The full CPL field map for one loan row, including any qualifying
     * status-code event -- shared by buildMonthly() (writes straight to a
     * file) and CplSnapshotService (freezes it into cpl_monthly_snapshots
     * first). $persistStatus controls whether a detected status event is
     * recorded in cpl_status_history -- buildMonthly() always does (it's
     * the actual export); CplSnapshotService passes false while only
     * previewing/revalidating a not-yet-approved batch, so re-snapshotting
     * before approval never permanently marks a status as "already sent".
     */
    public function buildFields(array $row, string $monthEnd, bool $persistStatus = true): array
    {
        $fields = array_merge(
            ['data' => 'D'],
            $this->borrowerFields($row),
            $this->loanFields($row, $monthEnd)
        );

        $statusResult = $this->statusCode($row, $monthEnd);
        if ($statusResult !== null) {
            $fields = array_merge($fields, $statusResult);
            if ($persistStatus) {
                $this->recordStatusSent((int) $row['id'], $statusResult['status_code'], $monthEnd);
            }

            if (in_array($statusResult['status_code'], self::ZERO_BALANCE_STATUS_CODES, true)) {
                $fields['amount_overdue'] = 0;
                $fields['instalment_amount'] = 0;
                $fields['current_balance'] = 0;
                $fields['months_in_arrears'] = 0;
            }
        }

        return $fields;
    }

    public function buildMonthly(string $monthEnd, ?string $supplierRef = null, ?string $tradingName = null): string
    {
        $supplierRef = $supplierRef ?: $this->settings()->supplierReferenceNumber();
        $tradingName = $tradingName ?: $this->settings()->tradingName();

        $loans = $this->eligibleLoans($monthEnd);

        $lines = [];
        foreach ($loans as $row) {
            $fields = $this->buildFields($row, $monthEnd, true);
            $lines[] = $this->builder->record($fields);
        }

        $header = $this->builder->header($supplierRef, $monthEnd, $tradingName);
        $trailer = $this->builder->trailer(count($lines) + 2);

        return implode("\r\n", array_merge([$header], $lines, [$trailer]));
    }
}
