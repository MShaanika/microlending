<?php

namespace App\Services;

use App\Core\Database;

/**
 * Portfolio breakdown reports for a given date range (see ReportPeriod for
 * how month/quarter/year selections become that range). Every method here
 * is a single query with the period bound via plain BETWEEN ? AND ? --
 * unlike the legacy version, there is no dynamic IN(...) clause building
 * or per-loan N+1 looping. Where this system already tracks something
 * precisely (principal/interest paid per installment via loan_schedules),
 * that real figure is used instead of the legacy's ratio-based estimate.
 */
class LoanReportService
{
    private const AMOUNT_BANDS = [
        '0 - N$10,000' => [0, 10000],
        'N$10,001 - N$20,000' => [10001, 20000],
        'N$20,001 - N$30,000' => [20001, 30000],
        'N$30,001 - N$40,000' => [30001, 40000],
        'N$40,001 - N$50,000' => [40001, 50000],
        'Above N$50,000' => [50001, 999999999.99],
    ];

    /** Loans that actually progressed past application -- the same set
     *  used for every "loans issued in period" breakdown below. */
    private const ISSUED_STATUSES = "('Approved','Released','Active','Current','Completed','Written Off')";

    public static function genderBreakdown(string $start, string $end): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT COALESCE(b.gender, 'Unknown') AS gender,
                    COUNT(*) AS loan_count,
                    COALESCE(SUM(l.principal_amount), 0) AS total_amount
             FROM loans l
             JOIN borrowers b ON b.id = l.borrower_id
             WHERE l.loan_status IN " . self::ISSUED_STATUSES . "
               AND DATE(l.created_at) BETWEEN ? AND ?
             GROUP BY b.gender
             ORDER BY b.gender"
        );
        $stmt->execute([$start, $end]);
        return $stmt->fetchAll();
    }

    public static function sizeBreakdown(string $start, string $end): array
    {
        $db = Database::connection();
        $rows = [];

        foreach (self::AMOUNT_BANDS as $label => [$min, $max]) {
            $stmt = $db->prepare(
                "SELECT COALESCE(SUM(l.principal_amount), 0) AS total_amount,
                        COALESCE(SUM(CASE WHEN b.gender = 'Male' THEN l.principal_amount ELSE 0 END), 0) AS male_amount,
                        COALESCE(SUM(CASE WHEN b.gender = 'Female' THEN l.principal_amount ELSE 0 END), 0) AS female_amount,
                        COUNT(CASE WHEN b.gender = 'Male' THEN 1 END) AS male_payers,
                        COUNT(CASE WHEN b.gender = 'Female' THEN 1 END) AS female_payers
                 FROM loans l
                 JOIN borrowers b ON b.id = l.borrower_id
                 WHERE l.loan_status IN " . self::ISSUED_STATUSES . "
                   AND DATE(l.created_at) BETWEEN ? AND ?
                   AND l.principal_amount BETWEEN ? AND ?"
            );
            $stmt->execute([$start, $end, $min, $max]);
            $row = $stmt->fetch();
            $row['label'] = $label;
            $rows[] = $row;
        }

        return $rows;
    }

    /** Salary lives on the borrower's current employment record, not the
     *  borrower row itself -- join through borrower_employment. */
    public static function salaryBreakdown(string $start, string $end): array
    {
        $db = Database::connection();
        $rows = [];

        foreach (self::AMOUNT_BANDS as $label => [$min, $max]) {
            $stmt = $db->prepare(
                "SELECT COALESCE(SUM(CASE WHEN b.gender = 'Male' THEN be.gross_salary ELSE 0 END), 0) AS male_salary,
                        COALESCE(SUM(CASE WHEN b.gender = 'Female' THEN be.gross_salary ELSE 0 END), 0) AS female_salary,
                        COUNT(DISTINCT CASE WHEN b.gender = 'Male' THEN b.id END) AS male_count,
                        COUNT(DISTINCT CASE WHEN b.gender = 'Female' THEN b.id END) AS female_count
                 FROM loans l
                 JOIN borrowers b ON b.id = l.borrower_id
                 JOIN borrower_employment be ON be.borrower_id = b.id AND be.is_current = 1
                 WHERE l.loan_status IN " . self::ISSUED_STATUSES . "
                   AND DATE(l.created_at) BETWEEN ? AND ?
                   AND be.gross_salary BETWEEN ? AND ?"
            );
            $stmt->execute([$start, $end, $min, $max]);
            $row = $stmt->fetch();
            $row['label'] = $label;
            $rows[] = $row;
        }

        return $rows;
    }

    public static function paymentGenderBreakdown(string $start, string $end): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT COALESCE(b.gender, 'Unknown') AS gender,
                    COUNT(*) AS payment_count,
                    COALESCE(SUM(p.amount_received), 0) AS total_amount
             FROM payments p
             JOIN borrowers b ON b.id = p.borrower_id
             WHERE p.status = 'Posted' AND p.payment_date BETWEEN ? AND ?
             GROUP BY b.gender
             ORDER BY b.gender"
        );
        $stmt->execute([$start, $end]);
        return $stmt->fetchAll();
    }

    public static function disbursementByMonth(string $start, string $end): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT DATE_FORMAT(disbursement_date, '%Y-%m') AS month_key,
                    DATE_FORMAT(disbursement_date, '%M %Y') AS month_label,
                    COALESCE(SUM(amount), 0) AS total_amount,
                    COUNT(*) AS disbursement_count
             FROM loan_disbursements
             WHERE status = 'Disbursed' AND disbursement_date BETWEEN ? AND ?
             GROUP BY month_key, month_label
             ORDER BY month_key"
        );
        $stmt->execute([$start, $end]);
        return $stmt->fetchAll();
    }

    /** Loans grouped by their plan term (in months), for loans actually
     *  disbursed in the period. */
    public static function installmentBreakdown(string $start, string $end): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT lp.months AS term_months,
                    COUNT(DISTINCT l.id) AS loan_count,
                    COALESCE(SUM(l.principal_amount), 0) AS total_amount
             FROM loans l
             JOIN loan_plans lp ON lp.id = l.plan_id
             JOIN loan_disbursements ld ON ld.loan_id = l.id AND ld.status = 'Disbursed'
             WHERE ld.disbursement_date BETWEEN ? AND ?
             GROUP BY lp.months
             ORDER BY lp.months"
        );
        $stmt->execute([$start, $end]);
        return $stmt->fetchAll();
    }

    /**
     * Real portfolio financial metrics for loans disbursed in the period,
     * drawn directly from loan_schedules' actual per-installment
     * principal/interest/penalty due & paid columns -- not the legacy's
     * ratio-based estimate off a single lump payments.amount total.
     */
    public static function financialMetrics(string $start, string $end): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT
                COALESCE(SUM(ls.principal_due - ls.principal_paid), 0) AS outstanding_principal,
                COALESCE(SUM(ls.principal_paid), 0) AS principal_received,
                COALESCE(SUM(ls.interest_paid), 0) AS interest_received,
                COALESCE(SUM(CASE WHEN l.loan_status = 'Completed' THEN ls.interest_paid ELSE 0 END), 0) AS interest_completed,
                COALESCE(SUM(ls.penalty_paid), 0) AS penalty_income,
                COUNT(DISTINCT CASE WHEN l.loan_status IN ('Active','Current') THEN l.id END) AS active_loans,
                COUNT(DISTINCT l.id) AS total_loans
             FROM loans l
             JOIN loan_disbursements ld ON ld.loan_id = l.id AND ld.status = 'Disbursed'
             JOIN loan_schedules ls ON ls.loan_id = l.id
             WHERE ld.disbursement_date BETWEEN ? AND ?"
        );
        $stmt->execute([$start, $end]);
        return $stmt->fetch() ?: [
            'outstanding_principal' => 0, 'principal_received' => 0, 'interest_received' => 0,
            'interest_completed' => 0, 'penalty_income' => 0, 'active_loans' => 0, 'total_loans' => 0,
        ];
    }

    /**
     * Point-in-time snapshot as of the end of the selected period, not a
     * count of things that happened during it (there's nothing to "issue"
     * about a loan status) -- "Active" is every currently outstanding loan
     * disbursed by that date, split into In Arrears vs. Current (on-track).
     * "In Arrears" loan IDs come from ArrearsService (already correctly
     * date-parameterized); the dollar figures are then computed here from
     * loan_schedules directly so both buckets share the same total_due -
     * total_paid basis as the "total" figure -- ArrearsService's own
     * outstanding_balance is narrower (principal + levy + stamp only, for
     * provisioning) and would silently misalign the two if reused here.
     */
    public static function activeLoanStatus(string $asOfDate): array
    {
        $db = Database::connection();
        $empty = [
            'total_count' => 0, 'total_outstanding' => 0.0,
            'arrears_count' => 0, 'arrears_outstanding' => 0.0,
            'current_count' => 0, 'current_outstanding' => 0.0,
        ];

        $stmt = $db->prepare(
            "SELECT DISTINCT l.id FROM loans l
             JOIN loan_disbursements ld ON ld.loan_id = l.id AND ld.status = 'Disbursed'
             WHERE l.loan_status IN ('Active', 'Current') AND ld.disbursement_date <= ?"
        );
        $stmt->execute([$asOfDate]);
        $activeLoanIds = array_map('intval', array_column($stmt->fetchAll(), 'id'));

        if (empty($activeLoanIds)) {
            return $empty;
        }

        $totalOutstanding = self::sumOutstanding($db, $activeLoanIds);

        $arrearsLoanIds = array_values(array_intersect(
            array_map('intval', array_column(ArrearsService::overdueLoans($asOfDate), 'loan_id')),
            $activeLoanIds
        ));
        $arrearsOutstanding = self::sumOutstanding($db, $arrearsLoanIds);

        return [
            'total_count' => count($activeLoanIds),
            'total_outstanding' => $totalOutstanding,
            'arrears_count' => count($arrearsLoanIds),
            'arrears_outstanding' => $arrearsOutstanding,
            'current_count' => count($activeLoanIds) - count($arrearsLoanIds),
            'current_outstanding' => round($totalOutstanding - $arrearsOutstanding, 2),
        ];
    }

    private static function sumOutstanding(\PDO $db, array $loanIds): float
    {
        if (empty($loanIds)) {
            return 0.0;
        }
        $placeholders = implode(',', array_fill(0, count($loanIds), '?'));
        $stmt = $db->prepare("SELECT COALESCE(SUM(total_due - total_paid), 0) FROM loan_schedules WHERE loan_id IN ($placeholders)");
        $stmt->execute($loanIds);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /** Loans identified as bad debt during the period (via a provisioning
     *  run), grouped by aging bucket. */
    public static function badDebtsBreakdown(string $start, string $end): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT aging_bucket, COUNT(*) AS loan_count, COALESCE(SUM(outstanding_balance), 0) AS total_outstanding
             FROM bad_debts
             WHERE identified_date BETWEEN ? AND ?
             GROUP BY aging_bucket
             ORDER BY FIELD(aging_bucket, '31-60', '61-90', '91-180', '180+')"
        );
        $stmt->execute([$start, $end]);
        return $stmt->fetchAll();
    }

    /** Recovery payments posted against written-off loans during the
     *  period, with whether that bad debt is now fully recovered. */
    public static function badDebtRecoveries(string $start, string $end): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT lr.recovery_date, lr.recovered_amount, l.loan_no,
                    CONCAT(b.first_name, ' ', b.last_name) AS borrower_name,
                    bd.status AS bad_debt_status
             FROM loan_recoveries lr
             JOIN loans l ON l.id = lr.loan_id
             JOIN borrowers b ON b.id = lr.borrower_id
             LEFT JOIN loan_write_offs wo ON wo.id = lr.write_off_id
             LEFT JOIN bad_debts bd ON bd.id = wo.bad_debt_id
             WHERE lr.status = 'Posted' AND lr.recovery_date BETWEEN ? AND ?
             ORDER BY lr.recovery_date"
        );
        $stmt->execute([$start, $end]);
        return $stmt->fetchAll();
    }
}
