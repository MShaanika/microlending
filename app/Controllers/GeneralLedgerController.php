<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\AccountingAccount;
use App\Models\JournalEntry;
use App\Services\GeneralLedgerExcelExporter;

/**
 * Per-account chronological ledger with running balance -- distinct from
 * the General Journal (chronological list of whole transactions). Account
 * selection is mandatory; unlike Cash Book this covers every GL account,
 * not just cash/bank ones.
 */
class GeneralLedgerController extends Controller
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
        Auth::authorize('accounting.journals');

        $allAccounts = $this->accounts->allAccounts(true);
        $accountId = (int) ($_GET['account_id'] ?? 0);
        $fromDate = $_GET['from_date'] ?? date('Y-m-01');
        $toDate = $_GET['to_date'] ?? date('Y-m-d');

        $account = null;
        $ledger = null;
        if ($accountId) {
            foreach ($allAccounts as $a) {
                if ((int) $a['id'] === $accountId) {
                    $account = $a;
                    break;
                }
            }
            if ($account) {
                $ledger = $this->journalEntries->cashBook($accountId, $fromDate, $toDate);
            }
        }

        $this->view('accounting/general_ledger/index', [
            'title' => 'General Ledger',
            'allAccounts' => $allAccounts,
            'accountId' => $accountId,
            'account' => $account,
            'fromDate' => $fromDate,
            'toDate' => $toDate,
            'ledger' => $ledger,
        ]);
    }

    public function exportExcel(): void
    {
        Auth::authorize('accounting.journals');

        $accountId = (int) ($_GET['account_id'] ?? 0);
        $fromDate = $_GET['from_date'] ?? date('Y-m-01');
        $toDate = $_GET['to_date'] ?? date('Y-m-d');
        $account = $accountId ? $this->accounts->find($accountId) : null;

        if (!$account) {
            $this->redirect('/accounting/general-ledger');
            return;
        }

        $ledger = $this->journalEntries->cashBook($accountId, $fromDate, $toDate);

        $spreadsheet = GeneralLedgerExcelExporter::build($account, $ledger, $fromDate, $toDate);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="General_Ledger_' . $account['account_code'] . '_' . $fromDate . '_to_' . $toDate . '.xlsx"');
        header('Cache-Control: max-age=0');
        GeneralLedgerExcelExporter::save($spreadsheet, 'php://output');
        exit;
    }
}
