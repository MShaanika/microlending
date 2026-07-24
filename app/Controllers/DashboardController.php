<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\FixedAsset;
use App\Services\DashboardService;

class DashboardController extends Controller
{
    public function index(): void
    {
        Auth::authorize('dashboard.view');

        $borrowers = new Borrower();
        $loans = new Loan();
        $payments = new Payment();
        $assets = new FixedAsset();

        $loanCounts = $loans->counts();

        $this->view('dashboard/index', [
            'title' => 'Dashboard',
            'stats' => [
                'total_borrowers' => $borrowers->count(),
                'active_loans' => $loanCounts['active'],
                'total_collected' => $payments->totalCollected(),
                'loans_in_arrears' => $loans->arrearsCount(),
                'total_assets' => $assets->totals()['count'],
                'assets_nbv' => $assets->totals()['net_book_value'],
            ],
            'kpis' => DashboardService::kpis(),
            'loanStatusDistribution' => DashboardService::loanStatusDistribution(),
            'disbursementVsCollectionTrend' => DashboardService::disbursementVsCollectionTrend(6),
            'arrearsAging' => DashboardService::arrearsAging(),
            'cashPosition' => DashboardService::cashPosition(),
            'topArrears' => DashboardService::topArrears(5),
            'upcomingDue' => DashboardService::upcomingDue(7),
            'promisesDueToday' => Auth::can('collections.arrears') ? DashboardService::promisesDueToday() : [],
            'recentActivity' => DashboardService::recentActivity(8),
        ]);
    }
}
