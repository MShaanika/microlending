<?php

namespace App\Services;

use App\Core\Database;
use App\Models\LoanDisbursementReportLine;
use App\Models\RegulatoryReport;
use App\Models\StatutoryCharge;

/**
 * Builds the "Loan Disbursement and Bad Debt Register" -- a monthly,
 * loan-level register matching the layout the client has historically kept
 * by hand in Excel: every loan disbursed that month, grouped into the
 * borrower's pay-date bucket (10th/15th/20th/25th/end of month), with a
 * gender-split borrowed amount, that loan's real interest, total
 * repayment, amount paid to date, and any bad debt written off against
 * that loan.
 *
 * Interest uses each loan's actual interest_amount, not a flat 30% of
 * capital -- confirmed against real data that the effective rate varies
 * per loan (20.22%-30.61% across a same-month sample), so a flat 30%
 * understates or overstates Total Repayment depending on the loan (e.g.
 * a 500 principal loan's real total repayable is 663.20, not the 660.15
 * a flat-30% calculation would give).
 *
 * Unlike the historical spreadsheet, this is generated fresh from live
 * data each time rather than reproducing specific past rows -- "Paid" and
 * "Bad Debt Written Off" reflect the loan's current state (as of
 * generation time), not a point-in-time snapshot as of the report month.
 */
class LoanDisbursementReportGenerationService
{
    private const SECTIONS = [
        10 => 'PAY_10',
        15 => 'PAY_15',
        20 => 'PAY_20',
        25 => 'PAY_25',
    ];
    private const EOM_SECTION = 'PAY_EOM';

    public static function generate(string $periodStart, string $periodEnd, int $userId, ?int $branchId = null): int
    {
        $lines = self::loanLines($periodStart, $periodEnd, $branchId);
        $totalBorrowed = round((float) array_sum(array_column($lines, 'borrowed_amount')), 2);

        $totals = [
            'total_loans' => count($lines),
            'total_principal' => $totalBorrowed,
            'total_interest' => round((float) array_sum(array_column($lines, 'interest_amount')), 2),
            'total_bad_debts' => round((float) array_sum(array_column($lines, 'bad_debt_written_off')), 2),
            'total_expenditure' => self::expenditureForMonth($periodStart, $periodEnd, $branchId),
            'total_namfisa_levy' => self::levyDue($totalBorrowed, $periodEnd),
        ];

        $db = Database::connection();
        $reports = new RegulatoryReport();
        $reportLines = new LoanDisbursementReportLine();

        $db->beginTransaction();
        try {
            $reportId = $reports->create(array_merge($totals, [
                'report_type_id' => self::reportTypeId(),
                'branch_id' => $branchId,
                'report_no' => generate_reference('REG'),
                'report_period' => $periodStart . ' to ' . $periodEnd,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'status' => 'Generated',
                'generated_by' => $userId,
                'generated_at' => date('Y-m-d H:i:s'),
            ]));

            $reportLines->insertLines($reportId, $lines);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return $reportId;
    }

    private static function reportTypeId(): int
    {
        $db = Database::connection();
        $id = $db->query("SELECT id FROM regulatory_report_types WHERE report_code = 'LOAN_DISBURSEMENT_MTH' LIMIT 1")->fetchColumn();
        if (!$id) {
            throw new \RuntimeException('LOAN_DISBURSEMENT_MTH report type is not seeded.');
        }
        return (int) $id;
    }

    /**
     * "EXPENDITURE FOR MONTH" in the client's historical register --
     * matches MlrReportGenerationService::expensesByMonth()'s definition
     * (paid expenses in the period), just for this one month rather than
     * a 3-month quarter.
     */
    private static function expenditureForMonth(string $start, string $end, ?int $branchId = null): float
    {
        $db = Database::connection();
        $branchSql = $branchId !== null ? " AND branch_id = ?" : "";
        $params = [$start, $end];
        if ($branchId !== null) {
            $params[] = $branchId;
        }
        $stmt = $db->prepare(
            "SELECT COALESCE(SUM(total_amount), 0) FROM expenses WHERE status = 'Paid' AND expense_date BETWEEN ? AND ?{$branchSql}"
        );
        $stmt->execute($params);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * "LEVY DUE TO NAMFISA 1.03% OF DISBURSED LOANS" -- the rate as of the
     * period's end date (not "today"), same reasoning as MLR's month-by-
     * month levy calculation: a report generated later shouldn't have a
     * since-changed rate silently rewrite a past month's figure.
     */
    private static function levyDue(float $totalBorrowed, string $periodEnd): float
    {
        $rate = (new StatutoryCharge())->namfisaLevyRateAsOf($periodEnd);
        return round($totalBorrowed * ($rate / 100), 2);
    }

    /**
     * One row per loan disbursed in the period. amount = actual tranche
     * amount from loan_disbursements (not loans.principal_amount), so a
     * multi-tranche loan (e.g. a top-up) isn't double counted across rows
     * -- same reasoning as MlrReportGenerationService::disbursedByMonth().
     *
     * Interest and Total Repayment are recomputed per row from the row's
     * own borrowed amount using the standard pricing formula: basis =
     * borrowed + NAMFISA levy (% of borrowed, rate as of the disbursement
     * date) + duty stamp, interest = loan's flat rate applied to that full
     * basis, total = basis + interest. Per the client, every row with the
     * same borrowed amount must show the same Total Repayment in this
     * register (e.g. every N$500 at 30% = 663.19) -- copying/pro-rating
     * the loan's stored total_payable broke that, because loans booked
     * before the interest-basis fix carry interest on principal only
     * (500 -> 660.15), and a top-up tranche pro-rated the whole loan's
     * flat duty stamp (500 of 2500 -> 656.15).
     */
    private static function loanLines(string $start, string $end, ?int $branchId = null): array
    {
        $db = Database::connection();
        $branchSql = $branchId !== null ? " AND l.branch_id = ?" : "";
        $params = [$start, $end];
        if ($branchId !== null) {
            $params[] = $branchId;
        }
        $stmt = $db->prepare(
            "SELECT ld.loan_id, ld.borrower_id, ld.disbursement_date, ld.amount AS borrowed_amount,
                    l.payment_day, l.interest_rate,
                    b.borrower_no AS client_no, b.first_name, b.last_name AS surname,
                    b.id_number, b.phone AS contact_number, b.gender,
                    (SELECT COALESCE(SUM(ls.total_paid), 0) FROM loan_schedules ls WHERE ls.loan_id = ld.loan_id) AS paid_amount,
                    (SELECT COALESCE(SUM(lw.net_write_off_amount), 0) FROM loan_write_offs lw WHERE lw.loan_id = ld.loan_id AND lw.status = 'Posted') AS bad_debt_written_off,
                    (SELECT be.gross_salary FROM borrower_employment be WHERE be.borrower_id = ld.borrower_id AND be.is_current = 1 ORDER BY be.id DESC LIMIT 1) AS gross_salary
             FROM loan_disbursements ld
             JOIN loans l ON l.id = ld.loan_id
             JOIN borrowers b ON b.id = ld.borrower_id
             WHERE ld.status = 'Disbursed' AND ld.disbursement_date BETWEEN ? AND ?{$branchSql}
             ORDER BY ld.disbursement_date, ld.id"
        );
        $stmt->execute($params);

        $charges = new StatutoryCharge();
        $ratesByDate = [];

        $lines = [];
        foreach ($stmt->fetchAll() as $row) {
            $borrowed = round((float) $row['borrowed_amount'], 2);

            $date = $row['disbursement_date'];
            if (!isset($ratesByDate[$date])) {
                $ratesByDate[$date] = [
                    'levy_rate' => $charges->namfisaLevyRateAsOf($date),
                    'duty_stamp' => $charges->dutyStampAmountAsOf($date),
                ];
            }
            $levy = round($borrowed * ($ratesByDate[$date]['levy_rate'] / 100), 2);
            $basis = round($borrowed + $levy + $ratesByDate[$date]['duty_stamp'], 2);
            // Half-cent interest rounds DOWN, matching the client's own
            // register: 500 -> basis 510.15 -> 30% = 153.045 -> 153.04,
            // total 663.19 (their confirmed figure), not 663.20.
            $interest = round($basis * ((float) $row['interest_rate'] / 100), 2, PHP_ROUND_HALF_DOWN);
            $totalRepayment = round($basis + $interest, 2);

            $lines[] = [
                'section' => self::SECTIONS[(int) $row['payment_day']] ?? self::EOM_SECTION,
                'loan_id' => (int) $row['loan_id'],
                'borrower_id' => (int) $row['borrower_id'],
                'disbursement_date' => $row['disbursement_date'],
                'client_no' => $row['client_no'],
                'first_name' => $row['first_name'],
                'surname' => $row['surname'],
                'id_number' => $row['id_number'],
                'contact_number' => $row['contact_number'],
                'gross_salary' => round((float) ($row['gross_salary'] ?? 0), 2),
                'gender' => $row['gender'],
                'borrowed_amount' => $borrowed,
                'interest_amount' => $interest,
                'total_repayment' => $totalRepayment,
                'paid_amount' => round((float) $row['paid_amount'], 2),
                'bad_debt_written_off' => round((float) $row['bad_debt_written_off'], 2),
            ];
        }

        return $lines;
    }
}
