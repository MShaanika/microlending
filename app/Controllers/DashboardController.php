<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\FixedAsset;

class DashboardController extends Controller
{
    public function index(): void
    {
        Auth::requireLogin();

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
        ]);
    }
}
