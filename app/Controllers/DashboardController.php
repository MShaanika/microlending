<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Models\Borrower;
use App\Models\DebitOrder;
use App\Models\HrmAttendance;
use App\Models\HrmEmployee;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\FixedAsset;
use App\Services\DashboardService;

class DashboardController extends Controller
{
    /** Hard scope -- null means unrestricted (Super Admin only). */
    private function scopeBranchId(): ?int
    {
        return Auth::isSuperAdmin() ? null : (Auth::branchId() ?? 0);
    }

    /** Same as scopeBranchId(), but Super Admin can additionally narrow via ?branch_id=, defaulting to all branches. */
    private function indexBranchId(): ?int
    {
        if (!Auth::isSuperAdmin()) {
            return Auth::branchId() ?? 0;
        }
        return !empty($_GET['branch_id']) ? (int) $_GET['branch_id'] : null;
    }

    public function index(): void
    {
        // Every logged-in user lands here after login (see AuthController::login()),
        // so this can't be gated behind the dashboard.view permission itself --
        // Auth::authorize()'s failure path redirects back to /dashboard, which
        // would loop forever for any role missing that permission. Individual
        // sensitive widgets below already have their own Auth::can() checks.
        Auth::requireLogin();

        $borrowers = new Borrower();
        $loans = new Loan();
        $payments = new Payment();
        $assets = new FixedAsset();
        $branches = new \App\Models\Branch();
        $debitOrders = new DebitOrder();

        $branchId = $this->indexBranchId();
        $loanCounts = $loans->counts($branchId);

        $myEmployee = (new HrmEmployee())->findByUserId((int) (Auth::user()['id'] ?? 0));
        $myAttendanceToday = $myEmployee ? (new HrmAttendance())->findForEmployeeDate((int) $myEmployee['id'], date('Y-m-d')) : null;

        $this->view('dashboard/index', [
            'myEmployee' => $myEmployee,
            'myAttendanceToday' => $myAttendanceToday,
            'title' => 'Dashboard',
            'branches' => Auth::isSuperAdmin() ? $branches->all() : [],
            'selectedBranchId' => $branchId,
            'stats' => [
                'total_borrowers' => $borrowers->count($branchId),
                'active_loans' => $loanCounts['active'],
                'total_collected' => $payments->totalCollected($branchId),
                'loans_in_arrears' => $loans->arrearsCount($branchId),
                'total_assets' => $assets->totals($branchId)['count'],
                'assets_nbv' => $assets->totals($branchId)['net_book_value'],
                'debit_orders_registered' => Auth::can('collections.debit_orders') ? $debitOrders->countBorrowersWithActiveDebitOrder($branchId) : 0,
                'debit_orders_missing' => Auth::can('collections.debit_orders') ? $debitOrders->countActiveLoansMissingDebitOrder($branchId) : 0,
            ],
            'kpis' => DashboardService::kpis($branchId),
            'loanStatusDistribution' => DashboardService::loanStatusDistribution($branchId),
            'disbursementVsCollectionTrend' => DashboardService::disbursementVsCollectionTrend(6, $branchId),
            'arrearsAging' => DashboardService::arrearsAging($branchId),
            'cashPosition' => DashboardService::cashPosition(),
            'topArrears' => DashboardService::topArrears(5, $branchId),
            'upcomingDue' => DashboardService::upcomingDue(7, $branchId),
            'promisesDueToday' => Auth::can('collections.arrears') ? DashboardService::promisesDueToday($branchId) : [],
            'socialAnalytics' => Auth::can('social_analytics.view') ? DashboardService::socialAnalyticsSummary() : [],
            // Admin-only: a full system report -- pending items across every
            // module needing action, plus the company-wide activity feed.
            'pendingApprovals' => Auth::can('admin.users') ? DashboardService::pendingApprovals($branchId) : [],
            'recentActivity' => Auth::can('admin.users') ? DashboardService::recentActivity(8) : [],
        ]);
    }
}
