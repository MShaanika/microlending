<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\JournalEntry;

class TrialBalanceController extends Controller
{
    private JournalEntry $journalEntries;

    public function __construct()
    {
        $this->journalEntries = new JournalEntry();
    }

    public function index(): void
    {
        Auth::requireLogin();
        $asOfDate = $_GET['as_of_date'] ?? date('Y-m-d');

        $rows = $this->journalEntries->trialBalance($asOfDate);
        $totalDebit = round(array_sum(array_column($rows, 'debit_balance')), 2);
        $totalCredit = round(array_sum(array_column($rows, 'credit_balance')), 2);

        $this->view('accounting/trial_balance/index', [
            'title' => 'Trial Balance',
            'rows' => $rows,
            'asOfDate' => $asOfDate,
            'totalDebit' => $totalDebit,
            'totalCredit' => $totalCredit,
        ]);
    }
}
