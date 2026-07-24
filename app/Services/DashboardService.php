<?php

namespace App\Services;

use App\Core\Database;
use App\Models\BankAccount;
use App\Models\JournalEntry;
use App\Models\Loan;
use PDO;

/**
 * Aggregate/summary queries for the staff dashboard. Same convention as
 * LoanReportService: static methods, raw SQL via Database::connection(),
 * no framework-level caching -- this app's data volumes don't need it.
 */
class DashboardService
{
    public static function kpis(): array
    {
        $db = Database::connection();
        $counts = (new Loan())->counts();

        $totalBorrowers = (int) $db->query("SELECT COUNT(*) FROM borrowers")->fetchColumn();
        $newBorrowersThisMonth = (int) $db->query(
            "SELECT COUNT(*) FROM borrowers WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
        )->fetchColumn();

        $collectedThisMonth = (float) $db->query(
            "SELECT COALESCE(SUM(amount_received),0) FROM payments
             WHERE status = 'Posted' AND payment_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
        )->fetchColumn();
        $collectedLastMonth = (float) $db->query(
            "SELECT COALESCE(SUM(amount_received),0) FROM payments
             WHERE status = 'Posted'
               AND payment_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')
               AND payment_date < DATE_FORMAT(CURDATE(), '%Y-%m-01')"
        )->fetchColumn();
        $collectedDeltaPct = $collectedLastMonth > 0
            ? round((($collectedThisMonth - $collectedLastMonth) / $collectedLastMonth) * 100, 1)
            : ($collectedThisMonth > 0 ? 100.0 : 0.0);

        $overdue = ArrearsService::overdueLoans(date('Y-m-d'));
        $arrearsValue = round((float) array_sum(array_column($overdue, 'outstanding_balance')), 2);
        $portfolioOutstanding = (float) $counts['principal_outstanding'];
        $parRatio = $portfolioOutstanding > 0 ? round($arrearsValue / $portfolioOutstanding * 100, 1) : 0.0;

        return [
            'total_borrowers' => $totalBorrowers,
            'new_borrowers_this_month' => $newBorrowersThisMonth,
            'active_loans' => $counts['active'],
            'portfolio_outstanding' => $portfolioOutstanding,
            'collected_this_month' => round($collectedThisMonth, 2),
            'collected_last_month' => round($collectedLastMonth, 2),
            'collected_delta_pct' => $collectedDeltaPct,
            'arrears_count' => count($overdue),
            'arrears_value' => $arrearsValue,
            'par_ratio' => $parRatio,
        ];
    }

    public static function loanStatusDistribution(): array
    {
        $db = Database::connection();
        return $db->query(
            "SELECT loan_status, COUNT(*) AS count FROM loans GROUP BY loan_status ORDER BY count DESC"
        )->fetchAll();
    }

    public static function disbursementVsCollectionTrend(int $months = 6): array
    {
        $db = Database::connection();
        $rows = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $monthStart = date('Y-m-01', strtotime("-{$i} month"));
            $monthEnd = date('Y-m-t', strtotime($monthStart));

            $dStmt = $db->prepare(
                "SELECT COALESCE(SUM(amount),0) FROM loan_disbursements
                 WHERE status = 'Disbursed' AND disbursement_date BETWEEN ? AND ?"
            );
            $dStmt->execute([$monthStart, $monthEnd]);

            $cStmt = $db->prepare(
                "SELECT COALESCE(SUM(amount_received),0) FROM payments
                 WHERE status = 'Posted' AND payment_date BETWEEN ? AND ?"
            );
            $cStmt->execute([$monthStart, $monthEnd]);

            $rows[] = [
                'label' => date('M Y', strtotime($monthStart)),
                'disbursed' => round((float) $dStmt->fetchColumn(), 2),
                'collected' => round((float) $cStmt->fetchColumn(), 2),
            ];
        }

        return $rows;
    }

    /**
     * Arrears grouped into ArrearsService's fixed aging buckets. 'Current'
     * never appears -- overdueLoans() only returns loans already overdue.
     */
    public static function arrearsAging(): array
    {
        $buckets = [];
        foreach (['1-30', '31-60', '61-90', '91-180', '180+'] as $b) {
            $buckets[$b] = ['bucket' => $b, 'count' => 0, 'value' => 0.0];
        }

        foreach (ArrearsService::overdueLoans(date('Y-m-d')) as $row) {
            $bucket = $row['aging_bucket'];
            if (!isset($buckets[$bucket])) {
                continue;
            }
            $buckets[$bucket]['count']++;
            $buckets[$bucket]['value'] += (float) $row['outstanding_balance'];
        }

        foreach ($buckets as &$b) {
            $b['value'] = round($b['value'], 2);
        }

        return array_values($buckets);
    }

    public static function cashPosition(): array
    {
        $bankAccounts = new BankAccount();
        $journal = new JournalEntry();
        $rows = [];

        foreach ($bankAccounts->allBankAccounts(true) as $b) {
            $rows[] = [
                'label' => $b['bank_name'] . ' - ' . $b['account_name'],
                'balance' => $journal->accountBalance((int) $b['account_id'], 'Debit'),
            ];
        }

        return $rows;
    }

    public static function topArrears(int $limit = 5): array
    {
        $overdue = ArrearsService::overdueLoans(date('Y-m-d'));
        usort($overdue, static fn ($a, $b) => $b['outstanding_balance'] <=> $a['outstanding_balance']);
        return array_slice($overdue, 0, $limit);
    }

    public static function upcomingDue(int $days = 7): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT ls.due_date, ls.total_due, ls.total_paid, l.id AS loan_id, l.loan_no,
                    CONCAT(b.first_name,' ',b.last_name) AS borrower_name
             FROM loan_schedules ls
             JOIN loans l ON l.id = ls.loan_id
             JOIN borrowers b ON b.id = l.borrower_id
             WHERE ls.status = 'Pending' AND ls.due_date BETWEEN ? AND ?
             ORDER BY ls.due_date ASC
             LIMIT 50"
        );
        $stmt->execute([date('Y-m-d'), date('Y-m-d', strtotime("+{$days} days"))]);
        return $stmt->fetchAll();
    }

    public static function promisesDueToday(): array
    {
        return (new \App\Models\PaymentPromise())->dueOn(date('Y-m-d'));
    }

    public static function recentActivity(int $limit = 8): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT al.action, al.module_name, al.description, al.created_at, u.name AS user_name
             FROM audit_logs al
             LEFT JOIN users u ON u.id = al.user_id
             ORDER BY al.id DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
