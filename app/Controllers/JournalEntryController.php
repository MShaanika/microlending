<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\JournalEntry;

class JournalEntryController extends Controller
{
    private JournalEntry $journalEntries;
    private AccountingAccount $accounts;
    private AccountingJournal $journal;

    public function __construct()
    {
        $this->journalEntries = new JournalEntry();
        $this->accounts = new AccountingAccount();
        $this->journal = new AccountingJournal();
    }

    private const STATUSES = ['Draft', 'Posted', 'Reversed', 'Cancelled'];

    public function index(): void
    {
        Auth::authorize('accounting.journals');
        $search = trim((string) ($_GET['q'] ?? ''));
        $fromDate = $_GET['from_date'] ?? date('Y-m-01');
        $toDate = $_GET['to_date'] ?? date('Y-m-d');
        $status = trim((string) ($_GET['status'] ?? ''));

        $lines = $this->journalEntries->journalLines($fromDate, $toDate, $status, $search);

        $this->view('accounting/journals/index', [
            'title' => 'General Journal',
            'lines' => $lines,
            'totalDebit' => round(array_sum(array_column($lines, 'debit')), 2),
            'totalCredit' => round(array_sum(array_column($lines, 'credit')), 2),
            'search' => $search,
            'fromDate' => $fromDate,
            'toDate' => $toDate,
            'status' => $status,
            'statuses' => self::STATUSES,
        ]);
    }

    public function exportExcel(): void
    {
        Auth::authorize('accounting.journals');
        $search = trim((string) ($_GET['q'] ?? ''));
        $fromDate = $_GET['from_date'] ?? date('Y-m-01');
        $toDate = $_GET['to_date'] ?? date('Y-m-d');
        $status = trim((string) ($_GET['status'] ?? ''));

        $lines = $this->journalEntries->journalLines($fromDate, $toDate, $status, $search);

        $spreadsheet = \App\Services\GeneralJournalExcelExporter::build($lines, $fromDate, $toDate);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="General_Journal_' . $fromDate . '_to_' . $toDate . '.xlsx"');
        header('Cache-Control: max-age=0');
        \App\Services\GeneralJournalExcelExporter::save($spreadsheet, 'php://output');
        exit;
    }

    public function show(string $id): void
    {
        Auth::authorize('accounting.journals');
        $journal = $this->journalEntries->find((int) $id);

        if (!$journal) {
            Session::flash('error', 'Journal entry not found.');
            $this->redirect('/accounting/journals');
        }

        $this->view('accounting/journals/show', [
            'title' => 'Journal ' . $journal['journal_no'],
            'journal' => $journal,
            'lines' => $this->journalEntries->lines((int) $id),
        ]);
    }

    public function create(): void
    {
        Auth::authorize('accounting.journals');
        $this->view('accounting/journals/create', [
            'title' => 'New Manual Journal',
            'accounts' => $this->accounts->allAccounts(true),
            'old' => [],
            'error' => null,
        ]);
    }

    public function store(): void
    {
        Auth::authorize('accounting.journals');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/journals/create');
        }

        $journalDate = $_POST['journal_date'] ?: date('Y-m-d');
        $referenceNo = trim($_POST['reference_no'] ?? '');
        $description = trim($_POST['description'] ?? '');

        $accountIds = $_POST['account_id'] ?? [];
        $debits = $_POST['debit'] ?? [];
        $credits = $_POST['credit'] ?? [];
        $lineDescriptions = $_POST['line_description'] ?? [];

        $error = null;

        if ($description === '') {
            $error = 'Description is required.';
        } elseif (count(array_filter($accountIds, fn ($v) => $v !== '')) < 2) {
            $error = 'A journal needs at least two lines with an account selected.';
        }

        $lines = [];
        if (!$error) {
            foreach ($accountIds as $i => $accountId) {
                if ($accountId === '') {
                    continue;
                }
                $debit = ($debits[$i] ?? '') !== '' ? (float) $debits[$i] : 0.0;
                $credit = ($credits[$i] ?? '') !== '' ? (float) $credits[$i] : 0.0;

                if ($debit > 0 && $credit > 0) {
                    $error = 'A line cannot have both a debit and a credit.';
                    break;
                }
                if ($debit <= 0 && $credit <= 0) {
                    $error = 'Each line must have either a debit or a credit greater than zero.';
                    break;
                }

                $lines[] = [
                    'account_id' => (int) $accountId,
                    'debit' => $debit,
                    'credit' => $credit,
                    'description' => trim($lineDescriptions[$i] ?? '') ?: $description,
                ];
            }
        }

        if (!$error) {
            $totalDebit = round(array_sum(array_column($lines, 'debit')), 2);
            $totalCredit = round(array_sum(array_column($lines, 'credit')), 2);
            if ($totalDebit !== $totalCredit) {
                $error = "Journal is not balanced. Debit: $totalDebit, Credit: $totalCredit.";
            }
        }

        if ($error) {
            $this->view('accounting/journals/create', [
                'title' => 'New Manual Journal',
                'accounts' => $this->accounts->allAccounts(true),
                'old' => $_POST,
                'error' => $error,
            ]);
            return;
        }

        try {
            $journalId = $this->journal->post(
                'MANUAL_JOURNAL',
                'manual',
                null,
                $referenceNo,
                $description,
                $lines,
                Auth::user()['id'] ?? null,
                $journalDate,
                'Manual'
            );
        } catch (\RuntimeException $e) {
            $this->view('accounting/journals/create', [
                'title' => 'New Manual Journal',
                'accounts' => $this->accounts->allAccounts(true),
                'old' => $_POST,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        Audit::log('Create', 'Accounting', 'Posted manual journal #' . $journalId . ': ' . $description);
        Session::flash('success', 'Manual journal posted.');
        $this->redirect('/accounting/journals/' . $journalId);
    }

    public function reverse(string $id): void
    {
        Auth::authorize('accounting.journals');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/journals/' . $id);
        }

        try {
            $reversalId = $this->journal->reverse($id, Auth::user()['id'] ?? null);
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/accounting/journals/' . $id);
        }

        Audit::log('Reverse', 'Accounting', 'Reversed journal #' . $id . ' via new journal #' . $reversalId);
        Session::flash('success', 'Journal reversed.');
        $this->redirect('/accounting/journals/' . $reversalId);
    }
}
