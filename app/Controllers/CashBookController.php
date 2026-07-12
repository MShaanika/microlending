<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\AccountingAccount;
use App\Models\JournalEntry;

class CashBookController extends Controller
{
    private JournalEntry $journalEntries;
    private AccountingAccount $accounts;

    public function __construct()
    {
        $this->journalEntries = new JournalEntry();
        $this->accounts = new AccountingAccount();
    }

    public function index(): void
    {
        Auth::authorize('accounting.cashbook');

        $cashBankAccounts = $this->accounts->cashBankAccounts();
        $accountId = (int) ($_GET['account_id'] ?? ($cashBankAccounts[0]['id'] ?? 0));
        $fromDate = $_GET['from_date'] ?? date('Y-m-01');
        $toDate = $_GET['to_date'] ?? date('Y-m-d');

        $cashBook = $accountId ? $this->journalEntries->cashBook($accountId, $fromDate, $toDate) : ['opening_balance' => 0, 'closing_balance' => 0, 'lines' => []];

        $this->view('accounting/cash_book/index', [
            'title' => 'Cash Book',
            'cashBankAccounts' => $cashBankAccounts,
            'accountId' => $accountId,
            'fromDate' => $fromDate,
            'toDate' => $toDate,
            'cashBook' => $cashBook,
        ]);
    }
}
