<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\AdjustmentJournal;
use App\Models\JournalEntry;

/**
 * Manual one-off fixes: always exactly one debit line + one credit line.
 * Draft entries are freely editable; Posted entries can only be reversed
 * (never edited in place), matching the standard journal lifecycle.
 */
class AdjustmentJournalController extends Controller
{
    private AdjustmentJournal $adjustments;
    private JournalEntry $journalEntries;
    private AccountingAccount $accounts;
    private AccountingJournal $journal;

    public function __construct()
    {
        $this->adjustments = new AdjustmentJournal();
        $this->journalEntries = new JournalEntry();
        $this->accounts = new AccountingAccount();
        $this->journal = new AccountingJournal();
    }

    public function index(): void
    {
        Auth::authorize('accounting.adjustment_journals');

        $filters = [
            'from_date' => $_GET['from_date'] ?? date('Y-m-01'),
            'to_date' => $_GET['to_date'] ?? date('Y-m-d'),
            'status' => trim((string) ($_GET['status'] ?? '')),
            'account_id' => (int) ($_GET['account_id'] ?? 0),
        ];

        $rows = $this->adjustments->paginated($filters);

        $this->view('accounting/adjustment_journals/index', [
            'title' => 'Adjustment Journals',
            'rows' => $rows,
            'totalDebit' => round((float) array_sum(array_column($rows, 'debit_amount')), 2),
            'totalCredit' => round((float) array_sum(array_column($rows, 'credit_amount')), 2),
            'filters' => $filters,
            'accounts' => $this->accounts->allAccounts(true),
            'statuses' => ['Draft', 'Posted', 'Reversed'],
        ]);
    }

    public function create(): void
    {
        Auth::authorize('accounting.adjustment_journals');
        $this->view('accounting/adjustment_journals/create', [
            'title' => 'New Adjustment Journal',
            'accounts' => $this->accounts->allAccounts(true),
            'old' => [],
            'error' => null,
        ]);
    }

    public function store(): void
    {
        Auth::authorize('accounting.adjustment_journals');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/adjustment-journals/create');
            return;
        }

        [$lines, $error] = $this->validateLines($_POST);

        if (!$error) {
            $journalDate = $_POST['journal_date'] ?: date('Y-m-d');
            $description = trim($_POST['description'] ?? '');
            $referenceNo = trim($_POST['reference_no'] ?? '');
            $action = $_POST['action'] ?? 'draft';
            $userId = Auth::user()['id'] ?? null;

            try {
                if ($action === 'post') {
                    $journalId = $this->journal->post(
                        'ADJUSTMENT_JOURNAL',
                        'manual',
                        null,
                        $referenceNo,
                        $description,
                        $lines,
                        $userId,
                        $journalDate,
                        'Adjustment'
                    );
                    Session::flash('success', 'Adjustment journal posted.');
                } else {
                    $journalId = $this->journal->saveDraft(
                        'ADJUSTMENT_JOURNAL',
                        'manual',
                        null,
                        $referenceNo,
                        $description,
                        $lines,
                        $userId,
                        $journalDate,
                        'Adjustment'
                    );
                    Session::flash('success', 'Adjustment journal saved as draft.');
                }
            } catch (\RuntimeException $e) {
                $error = $e->getMessage();
            }
        }

        if ($error) {
            $this->view('accounting/adjustment_journals/create', [
                'title' => 'New Adjustment Journal',
                'accounts' => $this->accounts->allAccounts(true),
                'old' => $_POST,
                'error' => $error,
            ]);
            return;
        }

        Audit::log('Create', 'Accounting', 'Created adjustment journal #' . $journalId);
        $this->redirect('/accounting/adjustment-journals/' . $journalId);
    }

    public function show(string $id): void
    {
        Auth::authorize('accounting.adjustment_journals');
        $journal = $this->findAdjustment((int) $id);
        if (!$journal) {
            return;
        }

        $this->view('accounting/adjustment_journals/show', [
            'title' => $journal['journal_no'],
            'journal' => $journal,
            'lines' => $this->journalEntries->lines((int) $id),
        ]);
    }

    public function edit(string $id): void
    {
        Auth::authorize('accounting.adjustment_journals');
        $journal = $this->findAdjustment((int) $id);
        if (!$journal) {
            return;
        }
        if ($journal['status'] !== 'Draft') {
            Session::flash('error', 'Only Draft journals can be edited.');
            $this->redirect('/accounting/adjustment-journals/' . $id);
            return;
        }

        $lines = $this->journalEntries->lines((int) $id);
        $debitLine = null;
        $creditLine = null;
        foreach ($lines as $l) {
            if ((float) $l['debit'] > 0) {
                $debitLine = $l;
            } elseif ((float) $l['credit'] > 0) {
                $creditLine = $l;
            }
        }

        $this->view('accounting/adjustment_journals/edit', [
            'title' => 'Edit ' . $journal['journal_no'],
            'journal' => $journal,
            'accounts' => $this->accounts->allAccounts(true),
            'old' => [
                'journal_date' => $journal['journal_date'],
                'description' => $journal['description'],
                'reference_no' => $journal['reference_no'],
                'debit_account_id' => $debitLine['account_id'] ?? '',
                'credit_account_id' => $creditLine['account_id'] ?? '',
                'amount' => $debitLine['debit'] ?? '',
            ],
            'error' => null,
        ]);
    }

    public function update(string $id): void
    {
        Auth::authorize('accounting.adjustment_journals');
        $id = (int) $id;
        $journal = $this->findAdjustment($id);
        if (!$journal) {
            return;
        }

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/adjustment-journals/' . $id . '/edit');
            return;
        }

        [$lines, $error] = $this->validateLines($_POST);

        if (!$error) {
            try {
                $this->journal->updateDraft(
                    $id,
                    $_POST['journal_date'] ?: date('Y-m-d'),
                    trim($_POST['description'] ?? ''),
                    $lines
                );
            } catch (\RuntimeException $e) {
                $error = $e->getMessage();
            }
        }

        if ($error) {
            $this->view('accounting/adjustment_journals/edit', [
                'title' => 'Edit ' . $journal['journal_no'],
                'journal' => $journal,
                'accounts' => $this->accounts->allAccounts(true),
                'old' => $_POST,
                'error' => $error,
            ]);
            return;
        }

        Audit::log('Update', 'Accounting', 'Updated adjustment journal #' . $id);
        Session::flash('success', 'Adjustment journal updated.');
        $this->redirect('/accounting/adjustment-journals/' . $id);
    }

    public function post(string $id): void
    {
        Auth::authorize('accounting.adjustment_journals');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/adjustment-journals/' . $id);
            return;
        }

        try {
            $this->journal->postDraft($id, Auth::user()['id'] ?? null);
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/accounting/adjustment-journals/' . $id);
            return;
        }

        Audit::log('Post', 'Accounting', 'Posted adjustment journal #' . $id);
        Session::flash('success', 'Adjustment journal posted.');
        $this->redirect('/accounting/adjustment-journals/' . $id);
    }

    public function reverse(string $id): void
    {
        Auth::authorize('accounting.adjustment_journals');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/adjustment-journals/' . $id);
            return;
        }

        try {
            $reversalId = $this->journal->reverse($id, Auth::user()['id'] ?? null, Auth::can('accounting.reconciliation_override'));
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/accounting/adjustment-journals/' . $id);
            return;
        }

        Audit::log('Reverse', 'Accounting', 'Reversed adjustment journal #' . $id . ' via new journal #' . $reversalId);
        Session::flash('success', 'Adjustment journal reversed.');
        $this->redirect('/accounting/adjustment-journals/' . $id);
    }

    private function findAdjustment(int $id): ?array
    {
        $journal = $this->journalEntries->find($id);
        if (!$journal || $journal['journal_type'] !== 'Adjustment') {
            Session::flash('error', 'Adjustment journal not found.');
            $this->redirect('/accounting/adjustment-journals');
            return null;
        }
        return $journal;
    }

    /**
     * @return array{0: array<int, array{account_id:int, debit:float, credit:float}>, 1: ?string}
     */
    private function validateLines(array $post): array
    {
        $debitAccountId = (int) ($post['debit_account_id'] ?? 0);
        $creditAccountId = (int) ($post['credit_account_id'] ?? 0);
        $amount = (float) ($post['amount'] ?? 0);
        $description = trim($post['description'] ?? '');

        if ($description === '') {
            return [[], 'Description is required.'];
        }
        if (!$debitAccountId || !$creditAccountId) {
            return [[], 'Select both a debit account and a credit account.'];
        }
        if ($debitAccountId === $creditAccountId) {
            return [[], 'Debit and credit accounts must be different.'];
        }
        if ($amount <= 0) {
            return [[], 'Amount must be greater than zero.'];
        }

        return [[
            ['account_id' => $debitAccountId, 'debit' => $amount, 'credit' => 0.0, 'description' => $description],
            ['account_id' => $creditAccountId, 'debit' => 0.0, 'credit' => $amount, 'description' => $description],
        ], null];
    }
}
