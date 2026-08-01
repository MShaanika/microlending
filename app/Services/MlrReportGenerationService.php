<?php

namespace App\Services;

use App\Core\Database;
use App\Models\MlrReportLine;
use App\Models\RegulatoryReport;
use App\Models\StatutoryCharge;

/**
 * Builds the consolidated "MLR Summarised Management Report" -- the client's
 * real NAMFISA quarterly filing shape (8 sections, several month-by-month),
 * distinct from the system's other 8 narrow regulatory_report_lines-based
 * report types. Persists into mlr_report_lines rather than
 * regulatory_report_lines, since the data (month-grouped, multi-section)
 * doesn't fit that flat table.
 *
 * "Quarterly Interest Income - Segment" (section 7), per the client's
 * written spec ("Total Loans Disbursed.docx"), is Net Interest Income =
 * total interest on that month's disbursed loans (section 1, flat 30% of
 * capital) minus the interest portion of that same month's bad debts
 * (section 5) -- not the GL-posting basis (credits to account 4010) used
 * earlier, which double-counted/omitted differently and didn't match the
 * client's own worked example (53,760 - 2,400 = 51,360).
 */
class MlrReportGenerationService
{
    private const ISSUED_STATUSES = "('Approved','Released','Active','Current','Completed','Written Off')";

    private const SIZE_BANDS = [
        '0 - N$10,000' => [0, 10000],
        'N$10,001 - N$20,000' => [10001, 20000],
        'N$20,001 - N$30,000' => [20001, 30000],
        'N$30,001 - N$40,000' => [30001, 40000],
        'N$40,001 - N$50,000' => [40001, 50000],
        'Above N$50,000' => [50001, 999999999.99],
    ];

    public static function generate(string $periodStart, string $periodEnd, int $userId, ?int $branchId = null): int
    {
        $months = self::monthsInRange($periodStart, $periodEnd);

        $disbursed = self::disbursedByMonth($months, $periodStart, $periodEnd, $branchId);
        $gender = self::genderBreakdown($periodStart, $periodEnd, $branchId);
        $size = self::sizeBreakdown($periodStart, $periodEnd, $branchId);
        $bookBalance = self::bookBalanceAsAt($periodEnd, $branchId);
        $writtenOff = self::writtenOffByMonth($months, $periodStart, $periodEnd, $branchId);
        $expenses = self::expensesByMonth($months, $periodStart, $periodEnd, $branchId);
        $interestIncome = self::netInterestIncomeByMonth($months, $disbursed, $writtenOff);
        $levy = self::leviesLessBadDebtsByMonth($months, $disbursed, $writtenOff);

        $lines = array_merge($disbursed, $gender, $size, $bookBalance, $writtenOff, $expenses, $interestIncome, $levy);

        $totals = [
            'total_loans' => (int) array_sum(array_column($disbursed, 'loan_count')),
            'total_principal' => round((float) array_sum(array_column($disbursed, 'capital_amount')), 2),
            'total_interest' => round((float) array_sum(array_column($interestIncome, 'total_amount')), 2),
            'total_bad_debts' => round((float) array_sum(array_column($writtenOff, 'total_amount')), 2),
            'total_namfisa_levy' => round((float) array_sum(array_column($levy, 'total_amount')), 2),
        ];

        $db = Database::connection();
        $reports = new RegulatoryReport();
        $reportLines = new MlrReportLine();

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
        $id = $db->query("SELECT id FROM regulatory_report_types WHERE report_code = 'MLR_SUMMARISED_QTR' LIMIT 1")->fetchColumn();
        if (!$id) {
            throw new \RuntimeException('MLR_SUMMARISED_QTR report type is not seeded.');
        }
        return (int) $id;
    }

    /**
     * @return array<int, array{month_key:string, month_label:string}>
     */
    private static function monthsInRange(string $start, string $end): array
    {
        $months = [];
        $cursor = new \DateTime(date('Y-m-01', strtotime($start)));
        $endCursor = new \DateTime(date('Y-m-01', strtotime($end)));
        while ($cursor <= $endCursor) {
            $months[] = ['month_key' => $cursor->format('Y-m'), 'month_label' => $cursor->format('F Y')];
            $cursor->modify('+1 month');
        }
        return $months;
    }

    private static function fillMonths(array $months, array $rows, string $section, array $amountKeys): array
    {
        $bySrcKey = [];
        foreach ($rows as $row) {
            $bySrcKey[$row['month_key']] = $row;
        }

        $lines = [];
        foreach ($months as $m) {
            $row = $bySrcKey[$m['month_key']] ?? null;
            $line = [
                'section' => $section,
                'month_key' => $m['month_key'],
                'month_label' => $m['month_label'],
                'label' => $m['month_label'],
                'capital_amount' => 0.0,
                'interest_amount' => 0.0,
                'total_amount' => 0.0,
                'loan_count' => 0,
            ];
            foreach ($amountKeys as $srcKey => $destKey) {
                $line[$destKey] = $row ? round((float) ($row[$srcKey] ?? 0), 2) : 0.0;
            }
            $line['loan_count'] = $row ? (int) ($row['loan_count'] ?? 0) : 0;
            $lines[] = $line;
        }
        return $lines;
    }

    /**
     * Capital = actual tranche amounts (ld.amount), not the loan's full
     * principal_amount joined once per disbursement row -- a loan
     * disbursed in multiple tranches (e.g. a top-up) used to have its
     * whole principal counted once per tranche row, inflating the total.
     * Interest is derived as a flat 30% of that (now-correct) capital
     * figure rather than summed from loans.interest_amount, for the same
     * reason and to stay consistent with the confirmed Interest = Capital
     * x 30% formula. loan_count is COUNT(DISTINCT loan_id) so a loan with
     * two tranches in the same month counts once, not twice.
     */
    private static function disbursedByMonth(array $months, string $start, string $end, ?int $branchId = null): array
    {
        $db = Database::connection();
        $branchSql = $branchId !== null ? " AND l.branch_id = ?" : "";
        $params = [$start, $end];
        if ($branchId !== null) {
            $params[] = $branchId;
        }
        $stmt = $db->prepare(
            "SELECT DATE_FORMAT(ld.disbursement_date, '%Y-%m') AS month_key,
                    COALESCE(SUM(ld.amount), 0) AS capital_amount,
                    COUNT(DISTINCT ld.loan_id) AS loan_count
             FROM loan_disbursements ld
             JOIN loans l ON l.id = ld.loan_id
             WHERE ld.status = 'Disbursed' AND ld.disbursement_date BETWEEN ? AND ?{$branchSql}
             GROUP BY month_key"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['interest_amount'] = round((float) $row['capital_amount'] * 0.30, 2);
            $row['total_amount'] = round((float) $row['capital_amount'] + $row['interest_amount'], 2);
        }
        unset($row);

        return self::fillMonths($months, $rows, 'DISBURSED', [
            'capital_amount' => 'capital_amount',
            'interest_amount' => 'interest_amount',
            'total_amount' => 'total_amount',
        ]);
    }

    private static function genderBreakdown(string $start, string $end, ?int $branchId = null): array
    {
        $db = Database::connection();
        $branchSql = $branchId !== null ? " AND l.branch_id = ?" : "";
        $params = [$start, $end];
        if ($branchId !== null) {
            $params[] = $branchId;
        }
        $stmt = $db->prepare(
            "SELECT b.gender, COUNT(*) AS loan_count, COALESCE(SUM(l.principal_amount), 0) AS total_amount
             FROM loan_disbursements ld
             JOIN loans l ON l.id = ld.loan_id
             JOIN borrowers b ON b.id = l.borrower_id
             WHERE ld.status = 'Disbursed' AND ld.disbursement_date BETWEEN ? AND ?{$branchSql}
             GROUP BY b.gender"
        );
        $stmt->execute($params);

        $lines = [];
        foreach ($stmt->fetchAll() as $row) {
            $lines[] = [
                'section' => 'GENDER',
                'month_key' => null,
                'month_label' => null,
                'label' => $row['gender'] ?: 'Not specified',
                'capital_amount' => 0.0,
                'interest_amount' => 0.0,
                'total_amount' => round((float) $row['total_amount'], 2),
                'loan_count' => (int) $row['loan_count'],
            ];
        }
        return $lines;
    }

    private static function sizeBreakdown(string $start, string $end, ?int $branchId = null): array
    {
        $db = Database::connection();
        $branchSql = $branchId !== null ? " AND l.branch_id = ?" : "";
        $lines = [];

        foreach (self::SIZE_BANDS as $label => [$min, $max]) {
            $params = [$start, $end, $min, $max];
            if ($branchId !== null) {
                $params[] = $branchId;
            }
            $stmt = $db->prepare(
                "SELECT COUNT(*) AS loan_count, COALESCE(SUM(l.principal_amount), 0) AS total_amount
                 FROM loan_disbursements ld
                 JOIN loans l ON l.id = ld.loan_id
                 WHERE ld.status = 'Disbursed' AND ld.disbursement_date BETWEEN ? AND ?
                   AND l.principal_amount BETWEEN ? AND ?{$branchSql}"
            );
            $stmt->execute($params);
            $row = $stmt->fetch();

            $lines[] = [
                'section' => 'SIZE',
                'month_key' => null,
                'month_label' => null,
                'label' => $label,
                'capital_amount' => 0.0,
                'interest_amount' => 0.0,
                'total_amount' => round((float) $row['total_amount'], 2),
                'loan_count' => (int) $row['loan_count'],
            ];
        }
        return $lines;
    }

    /**
     * Current outstanding balance for loans disbursed on or before the
     * quarter's end date -- NOT a true historical point-in-time
     * reconstruction (that would require replaying payment history up to
     * that exact date). Correct for a fully-closed past quarter; for the
     * most recent/still-open quarter it reflects today's live balance.
     */
    private static function bookBalanceAsAt(string $periodEnd, ?int $branchId = null): array
    {
        $db = Database::connection();
        $branchSql = $branchId !== null ? " AND l.branch_id = ?" : "";
        $params = [$periodEnd];
        if ($branchId !== null) {
            $params[] = $branchId;
        }
        $stmt = $db->prepare(
            "SELECT COUNT(DISTINCT l.id) AS loan_count, COALESCE(SUM(ls.total_due - ls.total_paid), 0) AS total_amount
             FROM loans l
             JOIN loan_schedules ls ON ls.loan_id = l.id
             WHERE l.loan_status IN ('Active','Current','Released')
               AND ls.total_due > ls.total_paid
               AND EXISTS (
                   SELECT 1 FROM loan_disbursements ld
                   WHERE ld.loan_id = l.id AND ld.status = 'Disbursed' AND ld.disbursement_date <= ?
               ){$branchSql}"
        );
        $stmt->execute($params);
        $row = $stmt->fetch();

        return [[
            'section' => 'BOOK_BALANCE',
            'month_key' => null,
            'month_label' => null,
            'label' => 'As at ' . $periodEnd,
            'capital_amount' => 0.0,
            'interest_amount' => 0.0,
            'total_amount' => round((float) $row['total_amount'], 2),
            'loan_count' => (int) $row['loan_count'],
        ]];
    }

    private static function writtenOffByMonth(array $months, string $start, string $end, ?int $branchId = null): array
    {
        $db = Database::connection();
        $branchSql = $branchId !== null ? " AND lw.branch_id = ?" : "";
        $params = [$start, $end];
        if ($branchId !== null) {
            $params[] = $branchId;
        }
        $stmt = $db->prepare(
            "SELECT DATE_FORMAT(lw.write_off_date, '%Y-%m') AS month_key,
                    COUNT(DISTINCT lw.id) AS loan_count,
                    COALESCE(SUM(sched.principal_outstanding), 0) AS capital_amount,
                    COALESCE(SUM(sched.interest_outstanding), 0) AS interest_amount
             FROM loan_write_offs lw
             JOIN (
                 SELECT loan_id,
                        SUM(principal_due - principal_paid) AS principal_outstanding,
                        SUM(interest_due - interest_paid) AS interest_outstanding
                 FROM loan_schedules
                 GROUP BY loan_id
             ) sched ON sched.loan_id = lw.loan_id
             WHERE lw.status = 'Posted' AND lw.write_off_date BETWEEN ? AND ?{$branchSql}
             GROUP BY month_key"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['total_amount'] = (float) $r['capital_amount'] + (float) $r['interest_amount'];
        }
        unset($r);

        return self::fillMonths($months, $rows, 'WRITTEN_OFF', [
            'capital_amount' => 'capital_amount',
            'interest_amount' => 'interest_amount',
            'total_amount' => 'total_amount',
        ]);
    }

    private static function expensesByMonth(array $months, string $start, string $end, ?int $branchId = null): array
    {
        $db = Database::connection();
        $branchSql = $branchId !== null ? " AND branch_id = ?" : "";
        $params = [$start, $end];
        if ($branchId !== null) {
            $params[] = $branchId;
        }
        $stmt = $db->prepare(
            "SELECT DATE_FORMAT(expense_date, '%Y-%m') AS month_key, COALESCE(SUM(total_amount), 0) AS total_amount
             FROM expenses WHERE status = 'Paid' AND expense_date BETWEEN ? AND ?{$branchSql}
             GROUP BY month_key"
        );
        $stmt->execute($params);

        return self::fillMonths($months, $stmt->fetchAll(), 'EXPENSES', ['total_amount' => 'total_amount']);
    }

    /**
     * Net Interest Income = interest on that month's disbursed loans minus
     * the interest portion of that month's bad debts. Both inputs are
     * already one row per month (via fillMonths), in the same order, so
     * this is a simple zip-and-subtract rather than another DB query.
     */
    private static function netInterestIncomeByMonth(array $months, array $disbursed, array $writtenOff): array
    {
        $writtenOffInterestByMonth = [];
        foreach ($writtenOff as $w) {
            $writtenOffInterestByMonth[$w['month_key']] = (float) $w['interest_amount'];
        }

        $lines = [];
        foreach ($months as $i => $m) {
            $disbursedInterest = (float) ($disbursed[$i]['interest_amount'] ?? 0);
            $badDebtInterest = $writtenOffInterestByMonth[$m['month_key']] ?? 0.0;

            $lines[] = [
                'section' => 'INTEREST_INCOME',
                'month_key' => $m['month_key'],
                'month_label' => $m['month_label'],
                'label' => $m['month_label'],
                'capital_amount' => 0.0,
                'interest_amount' => 0.0,
                'total_amount' => round($disbursedInterest - $badDebtInterest, 2),
                'loan_count' => 0,
            ];
        }

        return $lines;
    }

    /**
     * Per the client's written spec ("Total Loans Disbursed.docx"): Levy =
     * (Capital Disbursed − Bad Debt Capital) × the current NAMFISA levy
     * rate, i.e. computed fresh on net capital rather than summed from
     * actual recorded namfisa_levy_transactions rows -- confirmed with the
     * client after the two bases produced different figures for July 2026
     * (1,763.36 by formula vs 1,246.30 from recorded transactions).
     * capital_amount on the row is the bad-debt capital subtracted, kept
     * separate from total_amount (the net levy) so it stays inspectable.
     * interest_amount (otherwise unused for this section) holds the gross
     * levy on the full disbursed capital, before bad-debt exclusion -- so
     * a renderer showing Levy/Less Bad Debts/Net Payable can subtract in
     * matching levy-rate units instead of subtracting raw bad-debt capital
     * from an already-net levy figure.
     */
    private static function leviesLessBadDebtsByMonth(array $months, array $disbursed, array $writtenOff): array
    {
        $statutoryCharges = new StatutoryCharge();

        $disbursedCapitalByMonth = [];
        foreach ($disbursed as $d) {
            $disbursedCapitalByMonth[$d['month_key']] = (float) $d['capital_amount'];
        }
        $badDebtsByMonth = [];
        foreach ($writtenOff as $w) {
            $badDebtsByMonth[$w['month_key']] = (float) $w['capital_amount'];
        }

        $lines = [];
        foreach ($months as $m) {
            $netCapital = ($disbursedCapitalByMonth[$m['month_key']] ?? 0) - ($badDebtsByMonth[$m['month_key']] ?? 0);
            // The rate as of the last day of the month, not "today" -- a
            // quarter can span a rate change (e.g. 1.03% through July,
            // 1.25% from August), and each month's levy uses its own rate.
            $monthEnd = date('Y-m-t', strtotime($m['month_key'] . '-01'));
            $levyRate = $statutoryCharges->namfisaLevyRateAsOf($monthEnd);

            $lines[] = [
                'section' => 'LEVY',
                'month_key' => $m['month_key'],
                'month_label' => $m['month_label'],
                'label' => $m['month_label'],
                'capital_amount' => round($badDebtsByMonth[$m['month_key']] ?? 0, 2),
                'interest_amount' => round(($disbursedCapitalByMonth[$m['month_key']] ?? 0) * ($levyRate / 100), 2),
                'total_amount' => round($netCapital * ($levyRate / 100), 2),
                'loan_count' => 0,
            ];
        }

        return $lines;
    }
}
