<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\AccountingAccount;
use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\JournalEntry;
use App\Services\CashBookExcelExporter;

class CashBookController extends Controller
{
    private JournalEntry $journalEntries;
    private AccountingAccount $accounts;
    private BankAccount $bankAccounts;
    private BankReconciliation $reconciliation;

    public function __construct()
    {
        $this->journalEntries = new JournalEntry();
        $this->accounts = new AccountingAccount();
        $this->bankAccounts = new BankAccount();
        $this->reconciliation = new BankReconciliation();
    }

    public function index(): void
    {
        Auth::authorize('accounting.cashbook');

        $cashBankAccounts = $this->accounts->cashBankAccounts();
        $accountId = (int) ($_GET['account_id'] ?? ($cashBankAccounts[0]['id'] ?? 0));
        $fromDate = $_GET['from_date'] ?? date('Y-m-01');
        $toDate = $_GET['to_date'] ?? date('Y-m-d');
        $search = trim((string) ($_GET['q'] ?? ''));

        $cashBook = $accountId ? $this->journalEntries->cashBook($accountId, $fromDate, $toDate) : ['opening_balance' => 0, 'closing_balance' => 0, 'lines' => []];

        if ($search !== '') {
            $cashBook['lines'] = array_values(array_filter($cashBook['lines'], static function ($l) use ($search) {
                return stripos($l['description'] ?? '', $search) !== false || stripos($l['reference_no'] ?? '', $search) !== false;
            }));
        }

        $bankAccount = $accountId ? $this->bankAccounts->findByAccountId($accountId) : null;
        $lockedThrough = $bankAccount ? $this->reconciliation->lockedThrough((int) $bankAccount['id']) : null;

        $this->view('accounting/cash_book/index', [
            'title' => 'Cash Book',
            'cashBankAccounts' => $cashBankAccounts,
            'accountId' => $accountId,
            'fromDate' => $fromDate,
            'toDate' => $toDate,
            'search' => $search,
            'cashBook' => $cashBook,
            'bankAccount' => $bankAccount,
            'lockedThrough' => $lockedThrough,
        ]);
    }

    public function exportExcel(): void
    {
        Auth::authorize('accounting.cashbook');

        $accountId = (int) ($_GET['account_id'] ?? 0);
        $fromDate = $_GET['from_date'] ?? date('Y-m-01');
        $toDate = $_GET['to_date'] ?? date('Y-m-d');
        $account = $accountId ? $this->accounts->find($accountId) : [];

        $cashBook = $accountId ? $this->journalEntries->cashBook($accountId, $fromDate, $toDate) : ['opening_balance' => 0, 'closing_balance' => 0, 'lines' => []];

        $spreadsheet = CashBookExcelExporter::build($account ?? [], $cashBook, $fromDate, $toDate);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="CashBook_' . ($account['account_code'] ?? 'account') . '_' . $fromDate . '_to_' . $toDate . '.xlsx"');
        header('Cache-Control: max-age=0');
        CashBookExcelExporter::save($spreadsheet, 'php://output');
        exit;
    }
}
