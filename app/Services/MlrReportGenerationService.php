<?php

namespace App\Services;

use App\Core\Database;
use App\Models\AccountingAccount;
use App\Models\JournalEntry;
use App\Models\MlrReportLine;
use App\Models\RegulatoryReport;

/**
 * Builds the consolidated "MLR Summarised Management Report" -- the client's
 * real NAMFISA quarterly filing shape (8 sections, several month-by-month),
 * distinct from the system's other 8 narrow regulatory_report_lines-based
 * report types. Persists into mlr_report_lines rather than
 * regulatory_report_lines, since the data (month-grouped, multi-section)
 * doesn't fit that flat table.
 *
 * "Quarterly Interest Income - Segment" (section 7) uses the GL-posting
 * basis -- credits to Interest Income (account 4010) -- confirmed with the
 * client as distinct from the interest embedded in section 1's disbursement
 * figures.
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

    public static function generate(string $periodStart, string $periodEnd, int $userId): int
    {
        $months = self::monthsInRange($periodStart, $periodEnd);

        $disbursed = self::disbursedByMonth($months, $periodStart, $periodEnd);
        $gender = self::genderBreakdown($periodStart, $periodEnd);
        $size = self::sizeBreakdown($periodStart, $periodEnd);
        $bookBalance = self::bookBalanceAsAt($periodEnd);
        $writtenOff = self::writtenOffByMonth($months, $periodStart, $periodEnd);
        $expenses = self::expensesByMonth($months, $periodStart, $periodEnd);
        $interestIncome = self::interestIncomeByMonth($months, $periodStart, $periodEnd);
        $levy = self::leviesLessBadDebtsByMonth($months, $periodStart, $periodEnd, $writtenOff);

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

    private static function disbursedByMonth(array $months, string $start, string $end): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT DATE_FORMAT(ld.disbursement_date, '%Y-%m') AS month_key,
                    COALESCE(SUM(l.principal_amount), 0) AS capital_amount,
                    COALESCE(SUM(l.interest_amount), 0) AS interest_amount,
                    COALESCE(SUM(l.total_payable), 0) AS total_amount,
                    COUNT(*) AS loan_count
             FROM loan_disbursements ld
             JOIN loans l ON l.id = ld.loan_id
             WHERE ld.status = 'Disbursed' AND ld.disbursement_date BETWEEN ? AND ?
             GROUP BY month_key"
        );
        $stmt->execute([$start, $end]);

        return self::fillMonths($months, $stmt->fetchAll(), 'DISBURSED', [
            'capital_amount' => 'capital_amount',
            'interest_amount' => 'interest_amount',
            'total_amount' => 'total_amount',
        ]);
    }

    private static function genderBreakdown(string $start, string $end): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT b.gender, COUNT(*) AS loan_count, COALESCE(SUM(l.principal_amount), 0) AS total_amount
             FROM loan_disbursements ld
             JOIN loans l ON l.id = ld.loan_id
             JOIN borrowers b ON b.id = l.borrower_id
             WHERE ld.status = 'Disbursed' AND ld.disbursement_date BETWEEN ? AND ?
             GROUP BY b.gender"
        );
        $stmt->execute([$start, $end]);

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

    private static function sizeBreakdown(string $start, string $end): array
    {
        $db = Database::connection();
        $lines = [];

        foreach (self::SIZE_BANDS as $label => [$min, $max]) {
            $stmt = $db->prepare(
                "SELECT COUNT(*) AS loan_count, COALESCE(SUM(l.principal_amount), 0) AS total_amount
                 FROM loan_disbursements ld
                 JOIN loans l ON l.id = ld.loan_id
                 WHERE ld.status = 'Disbursed' AND ld.disbursement_date BETWEEN ? AND ?
                   AND l.principal_amount BETWEEN ? AND ?"
            );
            $stmt->execute([$start, $end, $min, $max]);
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
    private static function bookBalanceAsAt(string $periodEnd): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT COUNT(DISTINCT l.id) AS loan_count, COALESCE(SUM(ls.total_due - ls.total_paid), 0) AS total_amount
             FROM loans l
             JOIN loan_schedules ls ON ls.loan_id = l.id
             WHERE l.loan_status IN ('Active','Current','Released')
               AND ls.total_due > ls.total_paid
               AND EXISTS (
                   SELECT 1 FROM loan_disbursements ld
                   WHERE ld.loan_id = l.id AND ld.status = 'Disbursed' AND ld.disbursement_date <= ?
               )"
        );
        $stmt->execute([$periodEnd]);
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

    private static function writtenOffByMonth(array $months, string $start, string $end): array
    {
        $db = Database::connection();
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
             WHERE lw.status = 'Posted' AND lw.write_off_date BETWEEN ? AND ?
             GROUP BY month_key"
        );
        $stmt->execute([$start, $end]);
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

    private static function expensesByMonth(array $months, string $start, string $end): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT DATE_FORMAT(expense_date, '%Y-%m') AS month_key, COALESCE(SUM(total_amount), 0) AS total_amount
             FROM expenses WHERE status = 'Paid' AND expense_date BETWEEN ? AND ?
             GROUP BY month_key"
        );
        $stmt->execute([$start, $end]);

        return self::fillMonths($months, $stmt->fetchAll(), 'EXPENSES', ['total_amount' => 'total_amount']);
    }

    private static function interestIncomeByMonth(array $months, string $start, string $end): array
    {
        $accounts = new AccountingAccount();
        $accountId = $accounts->idByCode('4010');

        $rows = (new JournalEntry())->accountCreditsByMonth($accountId, $start, $end);

        return self::fillMonths($months, $rows, 'INTEREST_INCOME', ['total_amount' => 'total_amount']);
    }

    /**
     * Levy total per month, minus that same month's written-off capital
     * (section 5) as a net figure. Both raw components are kept on the row
     * (total_amount = levy, capital_amount = bad debts subtracted) rather
     * than pre-subtracted, so the net can be shown/recomputed transparently.
     */
    private static function leviesLessBadDebtsByMonth(array $months, string $start, string $end, array $writtenOff): array
    {
        $trend = RegulatoryReportService::namfisaLevySummary($start, $end)['trend'];
        $badDebtsByMonth = [];
        foreach ($writtenOff as $w) {
            $badDebtsByMonth[$w['month_key']] = $w['capital_amount'];
        }

        $levyLines = self::fillMonths($months, $trend, 'LEVY', ['total_amount' => 'total_amount']);
        foreach ($levyLines as &$line) {
            $line['capital_amount'] = round((float) ($badDebtsByMonth[$line['month_key']] ?? 0), 2);
        }
        unset($line);

        return $levyLines;
    }
}
