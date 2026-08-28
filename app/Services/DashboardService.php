<?php

namespace App\Services;

use App\Core\Database;
use App\Models\BankAccount;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\SocialAnalyticsMetric;
use App\Models\SocialAnalyticsSetting;
use PDO;

/**
 * Aggregate/summary queries for the staff dashboard. Same convention as
 * LoanReportService: static methods, raw SQL via Database::connection(),
 * no framework-level caching -- this app's data volumes don't need it.
 */
class DashboardService
{
    public static function kpis(?int $branchId = null): array
    {
        $db = Database::connection();
        $counts = (new Loan())->counts($branchId);

        $borrowersWhere = $branchId !== null ? " AND branch_id = " . (int) $branchId : "";
        $totalBorrowers = (int) $db->query("SELECT COUNT(*) FROM borrowers WHERE 1=1{$borrowersWhere}")->fetchColumn();
        $newBorrowersThisMonth = (int) $db->query(
            "SELECT COUNT(*) FROM borrowers WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01'){$borrowersWhere}"
        )->fetchColumn();

        $paymentsWhere = $branchId !== null ? " AND branch_id = " . (int) $branchId : "";
        $collectedThisMonth = (float) $db->query(
            "SELECT COALESCE(SUM(amount_received),0) FROM payments
             WHERE status = 'Posted' AND payment_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01'){$paymentsWhere}"
        )->fetchColumn();
        $collectedLastMonth = (float) $db->query(
            "SELECT COALESCE(SUM(amount_received),0) FROM payments
             WHERE status = 'Posted'
               AND payment_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')
               AND payment_date < DATE_FORMAT(CURDATE(), '%Y-%m-01'){$paymentsWhere}"
        )->fetchColumn();
        $collectedDeltaPct = $collectedLastMonth > 0
            ? round((($collectedThisMonth - $collectedLastMonth) / $collectedLastMonth) * 100, 1)
            : ($collectedThisMonth > 0 ? 100.0 : 0.0);

        $overdue = ArrearsService::overdueLoans(date('Y-m-d'));
        if ($branchId !== null) {
            $overdue = array_values(array_filter($overdue, fn ($row) => (int) $row['branch_id'] === $branchId));
        }
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

    public static function loanStatusDistribution(?int $branchId = null): array
    {
        $db = Database::connection();
        $where = $branchId !== null ? " WHERE branch_id = " . (int) $branchId : "";
        return $db->query(
            "SELECT loan_status, COUNT(*) AS count FROM loans{$where} GROUP BY loan_status ORDER BY count DESC"
        )->fetchAll();
    }

    public static function disbursementVsCollectionTrend(int $months = 6, ?int $branchId = null): array
    {
        $db = Database::connection();
        $rows = [];
        $branchParam = $branchId !== null ? [$branchId] : [];
        $disbursementBranchSql = $branchId !== null ? " AND l.branch_id = ?" : "";
        $paymentBranchSql = $branchId !== null ? " AND branch_id = ?" : "";

        for ($i = $months - 1; $i >= 0; $i--) {
            $monthStart = date('Y-m-01', strtotime("-{$i} month"));
            $monthEnd = date('Y-m-t', strtotime($monthStart));

            $dStmt = $db->prepare(
                "SELECT COALESCE(SUM(ld.amount),0) FROM loan_disbursements ld
                 JOIN loans l ON l.id = ld.loan_id
                 WHERE ld.status = 'Disbursed' AND ld.disbursement_date BETWEEN ? AND ?{$disbursementBranchSql}"
            );
            $dStmt->execute(array_merge([$monthStart, $monthEnd], $branchParam));

            $cStmt = $db->prepare(
                "SELECT COALESCE(SUM(amount_received),0) FROM payments
                 WHERE status = 'Posted' AND payment_date BETWEEN ? AND ?{$paymentBranchSql}"
            );
            $cStmt->execute(array_merge([$monthStart, $monthEnd], $branchParam));

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
    public static function arrearsAging(?int $branchId = null): array
    {
        $buckets = [];
        foreach (['1-29', '30-59', '60-89', '90+'] as $b) {
            $buckets[$b] = ['bucket' => $b, 'count' => 0, 'value' => 0.0];
        }

        foreach (ArrearsService::overdueLoans(date('Y-m-d')) as $row) {
            if ($branchId !== null && (int) $row['branch_id'] !== $branchId) {
                continue;
            }
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

    /**
     * Bank balances come from the General Ledger, which has no branch
     * attribution (accounting_journal_entries has no branch_id) -- stays
     * company-wide for every viewer until GL branch-scoping is designed.
     */
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

    public static function topArrears(int $limit = 5, ?int $branchId = null): array
    {
        $overdue = ArrearsService::overdueLoans(date('Y-m-d'));
        if ($branchId !== null) {
            $overdue = array_values(array_filter($overdue, fn ($row) => (int) $row['branch_id'] === $branchId));
        }
        usort($overdue, static fn ($a, $b) => $b['outstanding_balance'] <=> $a['outstanding_balance']);
        return array_slice($overdue, 0, $limit);
    }

    public static function upcomingDue(int $days = 7, ?int $branchId = null): array
    {
        $db = Database::connection();
        $branchSql = $branchId !== null ? " AND l.branch_id = ?" : "";
        $params = [date('Y-m-d'), date('Y-m-d', strtotime("+{$days} days"))];
        if ($branchId !== null) {
            $params[] = $branchId;
        }
        $stmt = $db->prepare(
            "SELECT ls.due_date, ls.total_due, ls.total_paid, l.id AS loan_id, l.loan_no,
                    CONCAT(b.first_name,' ',b.last_name) AS borrower_name
             FROM loan_schedules ls
             JOIN loans l ON l.id = ls.loan_id
             JOIN borrowers b ON b.id = l.borrower_id
             WHERE ls.status = 'Pending' AND ls.due_date BETWEEN ? AND ?{$branchSql}
             ORDER BY ls.due_date ASC
             LIMIT 50"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function promisesDueToday(?int $branchId = null): array
    {
        return (new \App\Models\PaymentPromise())->dueOn(date('Y-m-d'), $branchId);
    }

    /**
     * One row per enabled platform, with its headline metric (metric_1)
     * trend for a mini chart and the latest-vs-previous-entry delta.
     * Platforms with fewer than 2 logged entries still show their latest
     * value but omit the trend (nothing to chart yet). Social platforms are
     * company-wide accounts, not branch-specific, so this stays unscoped.
     */
    public static function socialAnalyticsSummary(): array
    {
        $settingsModel = new SocialAnalyticsSetting();
        $metricsModel = new SocialAnalyticsMetric();
        $rows = [];

        foreach ($settingsModel->enabledPlatforms() as $platform) {
            $trend = $metricsModel->trend((int) $platform['id'], 12);
            $latest = end($trend) ?: null;
            $previous = count($trend) >= 2 ? $trend[count($trend) - 2] : null;

            $deltaPct = 0.0;
            if ($latest && $previous && (float) $previous['metric_1'] > 0) {
                $deltaPct = round(
                    (((float) $latest['metric_1'] - (float) $previous['metric_1']) / (float) $previous['metric_1']) * 100,
                    1
                );
            }

            $rows[] = [
                'platform' => $platform['platform'],
                'display_name' => $platform['display_name'],
                'metric_1_label' => $platform['metric_1_label'],
                'latest_value' => $latest ? (float) $latest['metric_1'] : null,
                'latest_date' => $latest ? $latest['entry_date'] : null,
                'delta_pct' => $deltaPct,
                'trend' => $trend,
            ];
        }

        return $rows;
    }

    /** audit_logs has no branch attribution, so this stays company-wide. */
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

    /**
     * Admin-only "what's waiting on you" summary -- one row per module that
     * has a pending/needs-review status, with a count and a link straight to
     * that filtered list. hrm_leave_applications and debit_order_cancellations
     * have no branch_id of their own, so those two branch-scope via a join
     * (employee's branch, loan's branch) instead of a direct column.
     */
    public static function pendingApprovals(?int $branchId = null): array
    {
        $db = Database::connection();
        $branchClause = fn(string $column) => $branchId !== null ? " AND {$column} = " . (int) $branchId : "";

        $items = [
            [
                'label' => 'Loan Applications',
                'url' => url('/applications?status=Submitted'),
                'count' => (int) $db->query(
                    "SELECT COUNT(*) FROM loan_applications WHERE status IN ('Submitted','Screening','Documents Required')" . $branchClause('branch_id')
                )->fetchColumn(),
            ],
            [
                'label' => 'Expenses',
                'url' => url('/expenses?status=' . urlencode('Pending Approval')),
                'count' => (int) $db->query(
                    "SELECT COUNT(*) FROM expenses WHERE status = 'Pending Approval'" . $branchClause('branch_id')
                )->fetchColumn(),
            ],
            [
                'label' => 'Leave Requests',
                'url' => url('/hrm/leave-applications?status=Pending'),
                'count' => (int) $db->query(
                    "SELECT COUNT(*) FROM hrm_leave_applications la
                     JOIN hrm_employees e ON e.id = la.employee_id
                     WHERE la.status = 'Pending'" . $branchClause('e.branch_id')
                )->fetchColumn(),
            ],
            [
                'label' => 'Support Tickets',
                'url' => url('/tickets?status=Open'),
                'count' => (int) $db->query(
                    "SELECT COUNT(*) FROM support_tickets WHERE status = 'Open'" . $branchClause('branch_id')
                )->fetchColumn(),
            ],
            [
                'label' => 'Refund Claims',
                'url' => url('/refund-claims?status=Pending'),
                'count' => (int) $db->query(
                    "SELECT COUNT(*) FROM refund_claims WHERE status IN ('Pending','Under Review')" . $branchClause('branch_id')
                )->fetchColumn(),
            ],
            [
                'label' => 'Portal Loan Requests',
                'url' => url('/loan-requests?status=Pending'),
                'count' => (int) $db->query(
                    "SELECT COUNT(*) FROM loan_requests WHERE status = 'Pending'" . $branchClause('branch_id')
                )->fetchColumn(),
            ],
            [
                'label' => 'Debit Order Cancellations',
                'url' => url('/debit-order-cancellations?status=Pending'),
                'count' => (int) $db->query(
                    "SELECT COUNT(*) FROM debit_order_cancellations doc
                     JOIN loans l ON l.id = doc.loan_id
                     WHERE doc.status = 'Pending'" . $branchClause('l.branch_id')
                )->fetchColumn(),
            ],
        ];

        return $items;
    }
}
