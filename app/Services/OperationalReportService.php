<?php

namespace App\Services;

use App\Core\Database;
use App\Models\Loan;

/**
 * Day-to-day operational metrics for a given period (see ReportPeriod).
 * Same convention as LoanReportService: static methods, one prepared
 * statement per metric, period bound via BETWEEN ? AND ?.
 */
class OperationalReportService
{
    /**
     * Portfolio at risk, as of a single date (not period-bound like the
     * others -- PAR is a point-in-time snapshot), broken into
     * ArrearsService's aging buckets with each bucket's share of the total
     * outstanding portfolio.
     */
    public static function portfolioAtRisk(string $asOfDate): array
    {
        $totalOutstanding = (float) (new Loan())->counts()['principal_outstanding'];

        $buckets = [];
        foreach (['1-30', '31-60', '61-90', '91-180', '180+'] as $b) {
            $buckets[$b] = ['bucket' => $b, 'count' => 0, 'value' => 0.0];
        }

        foreach (ArrearsService::overdueLoans($asOfDate) as $row) {
            $bucket = $row['aging_bucket'];
            if (!isset($buckets[$bucket])) {
                continue;
            }
            $buckets[$bucket]['count']++;
            $buckets[$bucket]['value'] += (float) $row['outstanding_balance'];
        }

        foreach ($buckets as &$b) {
            $b['value'] = round($b['value'], 2);
            $b['pct_of_portfolio'] = $totalOutstanding > 0 ? round($b['value'] / $totalOutstanding * 100, 1) : 0.0;
        }

        return array_values($buckets);
    }

    /**
     * Collections efficiency for installments due within the period:
     * total due vs total actually paid against them (paid at any time, not
     * just within the period -- an installment due on the 25th and paid on
     * the 28th still counts as collected for that due-period), plus a
     * monthly trend for the chart.
     */
    public static function collectionsEfficiency(string $start, string $end): array
    {
        $db = Database::connection();

        $stmt = $db->prepare(
            "SELECT COALESCE(SUM(total_due),0) AS total_due, COALESCE(SUM(total_paid),0) AS total_collected
             FROM loan_schedules WHERE due_date BETWEEN ? AND ?"
        );
        $stmt->execute([$start, $end]);
        $totals = $stmt->fetch();
        $totalDue = (float) $totals['total_due'];
        $totalCollected = (float) $totals['total_collected'];

        $trendStmt = $db->prepare(
            "SELECT DATE_FORMAT(due_date, '%Y-%m') AS month_key, DATE_FORMAT(due_date, '%M %Y') AS month_label,
                    COALESCE(SUM(total_due),0) AS due, COALESCE(SUM(total_paid),0) AS collected
             FROM loan_schedules WHERE due_date BETWEEN ? AND ?
             GROUP BY month_key, month_label ORDER BY month_key"
        );
        $trendStmt->execute([$start, $end]);

        return [
            'total_due' => round($totalDue, 2),
            'total_collected' => round($totalCollected, 2),
            'collection_rate' => $totalDue > 0 ? round($totalCollected / $totalDue * 100, 1) : 0.0,
            'trend' => $trendStmt->fetchAll(),
        ];
    }

    public static function expenseSummary(string $start, string $end): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT ec.category_name, COUNT(*) AS expense_count, COALESCE(SUM(e.total_amount),0) AS total_amount
             FROM expenses e
             JOIN expense_categories ec ON ec.id = e.category_id
             WHERE e.status = 'Paid' AND e.expense_date BETWEEN ? AND ?
             GROUP BY ec.category_name
             ORDER BY total_amount DESC"
        );
        $stmt->execute([$start, $end]);
        return $stmt->fetchAll();
    }

    public static function debitOrderPerformance(string $start, string $end): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT dol.status, COUNT(*) AS line_count, COALESCE(SUM(dol.debit_amount),0) AS total_amount
             FROM debit_order_run_lines dol
             JOIN debit_order_runs dor ON dor.id = dol.run_id
             WHERE dor.run_date BETWEEN ? AND ?
             GROUP BY dol.status
             ORDER BY FIELD(dol.status, 'Successful','Posted','Pending','Failed','Returned')"
        );
        $stmt->execute([$start, $end]);
        return $stmt->fetchAll();
    }

    public static function loanMix(string $start, string $end): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT l.loan_type, COUNT(*) AS loan_count, COALESCE(SUM(l.principal_amount),0) AS total_amount
             FROM loans l
             JOIN loan_disbursements ld ON ld.loan_id = l.id AND ld.status = 'Disbursed'
             WHERE ld.disbursement_date BETWEEN ? AND ?
             GROUP BY l.loan_type
             ORDER BY total_amount DESC"
        );
        $stmt->execute([$start, $end]);
        return $stmt->fetchAll();
    }
}
