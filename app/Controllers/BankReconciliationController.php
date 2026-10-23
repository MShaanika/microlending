<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\BankStatementLine;
use App\Services\BankStatementCsvParser;
use App\Services\BankStatementPdfReader;
use App\Support\ReconciliationPeriod;

class BankReconciliationController extends Controller
{
    private const ALLOWED_CSV_EXTENSIONS = ['csv', 'pdf'];
    private const MAX_CSV_SIZE = 5 * 1024 * 1024; // 5MB

    private BankAccount $bankAccounts;
    private BankStatementLine $statementLines;
    private BankReconciliation $reconciliation;
    private AccountingJournal $journal;
    private AccountingAccount $accounts;

    public function __construct()
    {
        $this->bankAccounts = new BankAccount();
        $this->statementLines = new BankStatementLine();
        $this->reconciliation = new BankReconciliation();
        $this->journal = new AccountingJournal();
        $this->accounts = new AccountingAccount();
    }

    public function index(): void
    {
        Auth::authorize('accounting.bank_reconciliation');

        $bankAccounts = $this->bankAccounts->allBankAccounts(true);
        $bankAccountId = (int) ($_GET['bank_account_id'] ?? ($bankAccounts[0]['id'] ?? 0));
        $bankAccount = $bankAccountId ? $this->bankAccounts->find($bankAccountId) : null;
        $period = ReconciliationPeriod::resolve($_GET, date('Y-m-d'));
        if ($period['error']) {
            Session::flash('error', $period['error']);
        }
        $fromDate = $period['from'];
        $toDate = $period['to'];
        $includeEarlier = !empty($_GET['include_earlier']);
        $showOnlyUnmatched = !empty($_GET['unmatched_only']);

        $summary = $bankAccount ? $this->reconciliation->summary($bankAccountId, (int) $bankAccount['account_id'], $toDate, $fromDate) : null;
        $lockedThrough = $bankAccount ? $this->reconciliation->lockedThrough($bankAccountId) : null;

        $comparison = [];
        if ($bankAccount) {
            // Only transactions inside the chosen period are listed; "include earlier items" also lists older ones still open.
            $comparison = $this->reconciliation->comparisonTable(
                $bankAccountId,
                (int) $bankAccount['account_id'],
                $toDate,
                $lockedThrough['statement_date'] ?? null,
                $includeEarlier ? null : $fromDate
            );
            if ($showOnlyUnmatched) {
                $comparison = array_values(array_filter($comparison, static fn ($r) => $r['status'] !== 'Matched'));
            }
        }

        $this->view('accounting/bank_reconciliation/index', [
            'title' => 'Bank Reconciliation',
            'bankAccounts' => $bankAccounts,
            'bankAccountId' => $bankAccountId,
            'bankAccount' => $bankAccount,
            'asOfDate' => $toDate,
            'fromDate' => $fromDate,
            'toDate' => $toDate,
            'includeEarlier' => $includeEarlier,
            'showOnlyUnmatched' => $showOnlyUnmatched,
            'summary' => $summary,
            'comparison' => $comparison,
            'lockedThrough' => $lockedThrough,
            'completionHistory' => $bankAccount ? $this->reconciliation->completionHistory($bankAccountId) : [],
            'unmatchedJournalLines' => $bankAccount ? $this->reconciliation->unmatchedJournalLines((int) $bankAccount['account_id']) : [],
            'accounts' => $this->accounts->allAccounts(true),
            'canOverride' => Auth::can('accounting.reconciliation_override'),
        ]);
    }

    public function history(): void
    {
        Auth::authorize('accounting.bank_reconciliation');

        $filters = [
            'bank_account_id' => (int) ($_GET['bank_account_id'] ?? 0),
            'from_date' => trim($_GET['from_date'] ?? ''),
            'to_date' => trim($_GET['to_date'] ?? ''),
            'matched_by' => (int) ($_GET['matched_by'] ?? 0),
        ];

        $matches = $this->reconciliation->matchHistory(array_filter($filters));

        $totalAmount = 0.0;
        foreach ($matches as $m) {
            $totalAmount += (float) $m['matched_amount'];
        }

        $this->view('accounting/bank_reconciliation/history', [
            'title' => 'Bank Reconciliation History',
            'bankAccounts' => $this->bankAccounts->allBankAccounts(true),
            'matchers' => $this->reconciliation->matchers(),
            'filters' => $filters,
            'matches' => $matches,
            'totalMatches' => count($matches),
            'totalAmount' => $totalAmount,
            'lastMatchDate' => $matches[0]['reconciled_at'] ?? null,
        ]);
    }

    public function complete(): void
    {
        Auth::authorize('accounting.bank_reconciliation');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/bank-reconciliation');
            return;
        }

        $bankAccountId = (int) ($_POST['bank_account_id'] ?? 0);
        $asOfDate = ReconciliationPeriod::resolve($_POST, date('Y-m-d'))['to'];
        $bankAccount = $this->bankAccounts->find($bankAccountId);

        if (!$bankAccount) {
            Session::flash('error', 'Bank account not found.');
            $this->redirect('/accounting/bank-reconciliation');
            return;
        }

        $summary = $this->reconciliation->summary($bankAccountId, (int) $bankAccount['account_id'], $asOfDate, ReconciliationPeriod::resolve($_POST, date('Y-m-d'))['from']);
        if ($summary['difference'] === null || abs($summary['difference']) > 0.01) {
            Session::flash('error', 'Reconciliation cannot be completed while there is a difference. Match or adjust every item first.');
            $this->redirect('/accounting/bank-reconciliation?bank_account_id=' . $bankAccountId . $this->periodQuery());
            return;
        }

        $this->reconciliation->complete($bankAccountId, $asOfDate, Auth::user()['id'] ?? null);

        Audit::log('Complete', 'Accounting', "Completed bank reconciliation for {$bankAccount['bank_name']} - {$bankAccount['account_name']} as at {$asOfDate}");
        Session::flash('success', 'Reconciliation completed. Transactions on or before ' . $asOfDate . ' are now locked.');
        $this->redirect('/accounting/bank-reconciliation?bank_account_id=' . $bankAccountId . $this->periodQuery());
    }

    public function reopen(): void
    {
        Auth::authorize('accounting.reconciliation_override');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/bank-reconciliation');
            return;
        }

        $bankAccountId = (int) ($_POST['bank_account_id'] ?? 0);
        $bankAccount = $this->bankAccounts->find($bankAccountId);
        if (!$bankAccount) {
            Session::flash('error', 'Bank account not found.');
            $this->redirect('/accounting/bank-reconciliation');
            return;
        }

        $reopened = $this->reconciliation->reopen($bankAccountId, Auth::user()['id'] ?? null);
        if (!$reopened) {
            Session::flash('error', 'This account has no completed reconciliation to reopen.');
            $this->redirect('/accounting/bank-reconciliation?bank_account_id=' . $bankAccountId . $this->periodQuery());
            return;
        }

        Audit::log('Reopen', 'Accounting', "Reopened bank reconciliation for {$bankAccount['bank_name']} - {$bankAccount['account_name']} (admin override)");
        Session::flash('success', 'Reconciliation reopened. Locked transactions can be reversed again.');
        $this->redirect('/accounting/bank-reconciliation?bank_account_id=' . $bankAccountId . $this->periodQuery());
    }

    /** The From/To period (and display switches) sent with a form, as a query-string tail for redirecting back to the same view. */
    private function periodQuery(): string
    {
        return ReconciliationPeriod::query($_POST, date('Y-m-d'));
    }

    public function importForm(): void
    {
        Auth::authorize('accounting.bank_reconciliation');
        $bankAccounts = $this->bankAccounts->allBankAccounts(true);
        $bankAccountId = (int) ($_GET['bank_account_id'] ?? ($bankAccounts[0]['id'] ?? 0));

        $this->view('accounting/bank_reconciliation/import', [
            'title' => 'Import Bank Statement',
            'bankAccounts' => $bankAccounts,
            'bankAccountId' => $bankAccountId,
            'errors' => [],
        ]);
    }

    public function import(): void
    {
        Auth::authorize('accounting.bank_reconciliation');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/bank-reconciliation/import');
            return;
        }

        $bankAccountId = (int) ($_POST['bank_account_id'] ?? 0);
        $bankAccount = $bankAccountId ? $this->bankAccounts->find($bankAccountId) : null;
        $file = $_FILES['statement'] ?? null;

        if (!$bankAccount) {
            Session::flash('error', 'Select a bank account.');
            $this->redirect('/accounting/bank-reconciliation/import');
            return;
        }

        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
            Session::flash('error', 'Choose a CSV or PDF statement to import.');
            $this->redirect('/accounting/bank-reconciliation/import?bank_account_id=' . $bankAccountId);
            return;
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            Session::flash('error', 'Upload failed. Please try again.');
            $this->redirect('/accounting/bank-reconciliation/import?bank_account_id=' . $bankAccountId);
            return;
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($file['size'] > ($ext === 'pdf' ? BankStatementPdfReader::MAX_BYTES : self::MAX_CSV_SIZE)) {
            Session::flash('error', 'File is too large (max ' . ($ext === 'pdf' ? '10MB' : '5MB') . ').');
            $this->redirect('/accounting/bank-reconciliation/import?bank_account_id=' . $bankAccountId);
            return;
        }
        if (!in_array($ext, self::ALLOWED_CSV_EXTENSIONS, true)) {
            Session::flash('error', 'Only CSV or PDF files are accepted.');
            $this->redirect('/accounting/bank-reconciliation/import?bank_account_id=' . $bankAccountId);
            return;
        }

        if ($ext === 'pdf') {
            $this->startPdfImport($file['tmp_name'], $bankAccountId);
            return;
        }

        $result = BankStatementCsvParser::parse($file['tmp_name']);

        if (empty($result['rows']) && !empty($result['errors'])) {
            Session::flash('error', 'Import failed: ' . implode(' ', $result['errors']));
            $this->redirect('/accounting/bank-reconciliation/import?bank_account_id=' . $bankAccountId);
            return;
        }

        $saved = $this->saveRows($bankAccountId, $bankAccount, $result['rows']);

        $message = "Imported {$saved['imported']} line(s), auto-matched {$saved['matched']}.";
        if ($saved['skipped'] > 0) {
            $message .= " Skipped {$saved['skipped']} duplicate(s) already imported.";
        }
        if (!empty($result['errors'])) {
            $message .= ' ' . count($result['errors']) . ' row(s) had errors and were skipped.';
        }
        Session::flash('success', $message);
        $this->redirect('/accounting/bank-reconciliation?bank_account_id=' . $bankAccountId . $this->periodQuery());
    }

    /**
     * Saves statement rows (CSV or reviewed PDF) for one bank account: duplicates are skipped, the rest imported, then auto-matched.
     *
     * @return array{imported: int, skipped: int, matched: int}
     */
    private function saveRows(int $bankAccountId, array $bankAccount, array $rows, string $source = 'CSV'): array
    {
        $userId = Auth::user()['id'] ?? null;
        $imported = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            if ($this->statementLines->referenceExists($bankAccountId, $row['transaction_date'], (string) $row['reference_no'], $row['money_in'], $row['money_out'])) {
                $skipped++;
                continue;
            }
            $this->statementLines->create([
                'bank_account_id' => $bankAccountId,
                'transaction_date' => $row['transaction_date'],
                'reference_no' => $row['reference_no'] ?: null,
                'description' => $row['description'] ?: null,
                'money_in' => $row['money_in'],
                'money_out' => $row['money_out'],
                'balance' => $row['balance'],
                'reconciled' => 0,
                'imported_by' => $userId,
            ]);
            $imported++;
        }

        $matched = $this->reconciliation->autoMatch($bankAccountId, (int) $bankAccount['account_id'], $userId);

        Audit::log('Create', 'Accounting', "Imported $imported bank statement line(s) from $source for {$bankAccount['bank_name']} - {$bankAccount['account_name']} ($skipped duplicate(s) skipped, $matched auto-matched)");

        return ['imported' => $imported, 'skipped' => $skipped, 'matched' => $matched];
    }

    /** Reads the PDF and shows what was found; nothing is saved until the person confirms it on the review page. */
    private function startPdfImport(string $tmpPath, int $bankAccountId): void
    {
        $read = BankStatementPdfReader::read($tmpPath);
        if ($read['rows'] === []) {
            Session::flash('error', 'This PDF could not be imported. ' . implode(' ', array_slice($read['errors'], 0, 3)) . ' You can import a CSV export of the statement instead.');
            $this->redirect('/accounting/bank-reconciliation/import?bank_account_id=' . $bankAccountId);
            return;
        }

        $token = BankStatementPdfReader::savePreview($read + ['bank_account_id' => $bankAccountId, 'user_id' => (int) (Auth::user()['id'] ?? 0)]);
        $this->redirect('/accounting/bank-reconciliation/import/review?token=' . $token);
    }

    public function importReview(): void
    {
        Auth::authorize('accounting.bank_reconciliation');
        $preview = BankStatementPdfReader::loadPreview((string) ($_GET['token'] ?? ''), (int) (Auth::user()['id'] ?? 0));
        $bankAccount = $preview ? $this->bankAccounts->find((int) $preview['bank_account_id']) : null;
        if (!$preview || !$bankAccount) {
            Session::flash('error', 'That import has expired. Please upload the statement again.');
            $this->redirect('/accounting/bank-reconciliation/import');
            return;
        }

        $this->view('accounting/bank_reconciliation/import_review', [
            'title' => 'Review Bank Statement',
            'token' => (string) $_GET['token'],
            'preview' => $preview,
            'bankAccount' => $bankAccount,
        ]);
    }

    public function importConfirm(): void
    {
        Auth::authorize('accounting.bank_reconciliation');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/bank-reconciliation/import');
            return;
        }

        $token = (string) ($_POST['token'] ?? '');
        $preview = BankStatementPdfReader::loadPreview($token, (int) (Auth::user()['id'] ?? 0));
        $bankAccount = $preview ? $this->bankAccounts->find((int) $preview['bank_account_id']) : null;
        if (!$preview || !$bankAccount) {
            Session::flash('error', 'That import has expired. Please upload the statement again.');
            $this->redirect('/accounting/bank-reconciliation/import');
            return;
        }

        if (!$preview['trusted'] && empty($_POST['checked'])) {
            Session::flash('error', 'Please tick the box to confirm you have checked these lines against your statement.');
            $this->redirect('/accounting/bank-reconciliation/import/review?token=' . urlencode($token));
            return;
        }

        $saved = $this->saveRows((int) $preview['bank_account_id'], $bankAccount, $preview['rows'], $preview['reader'] === 'ai' ? 'PDF (AI reading)' : 'PDF');
        BankStatementPdfReader::deletePreview($token);

        $message = "Imported {$saved['imported']} line(s) from the PDF, auto-matched {$saved['matched']}.";
        if ($saved['skipped'] > 0) {
            $message .= " Skipped {$saved['skipped']} duplicate(s) already imported.";
        }
        Session::flash('success', $message);
        $this->redirect('/accounting/bank-reconciliation?bank_account_id=' . (int) $preview['bank_account_id']);
    }

    public function importCancel(): void
    {
        Auth::authorize('accounting.bank_reconciliation');
        if (Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            $token = (string) ($_POST['token'] ?? '');
            if (BankStatementPdfReader::loadPreview($token, (int) (Auth::user()['id'] ?? 0))) {
                BankStatementPdfReader::deletePreview($token);
            }
        }
        $this->redirect('/accounting/bank-reconciliation/import');
    }

    public function match(): void
    {
        Auth::authorize('accounting.bank_reconciliation');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/bank-reconciliation');
            return;
        }

        $bankAccountId = (int) ($_POST['bank_account_id'] ?? 0);
        $statementId = (int) ($_POST['statement_id'] ?? 0);
        $journalLineId = (int) ($_POST['journal_line_id'] ?? 0);

        $statementLine = $this->statementLines->find($statementId);
        if (!$statementLine || $statementLine['reconciled']) {
            Session::flash('error', 'That statement line is not available to match.');
            $this->redirect('/accounting/bank-reconciliation?bank_account_id=' . $bankAccountId . $this->periodQuery());
            return;
        }

        $amount = (float) $statementLine['money_in'] > 0 ? (float) $statementLine['money_in'] : (float) $statementLine['money_out'];

        $this->reconciliation->match($statementId, $journalLineId, $amount, 'Manual', Auth::user()['id'] ?? null, 'Manually matched');

        Audit::log('Match', 'Accounting', 'Manually matched bank statement line #' . $statementId . ' to journal line #' . $journalLineId);
        Session::flash('success', 'Matched.');
        $this->redirect('/accounting/bank-reconciliation?bank_account_id=' . $bankAccountId . $this->periodQuery());
    }

    public function unmatch(): void
    {
        Auth::authorize('accounting.bank_reconciliation');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/bank-reconciliation');
            return;
        }

        $bankAccountId = (int) ($_POST['bank_account_id'] ?? 0);
        $statementId = (int) ($_POST['statement_id'] ?? 0);

        $this->reconciliation->unmatch($statementId);

        Audit::log('Unmatch', 'Accounting', 'Unmatched bank statement line #' . $statementId);
        Session::flash('success', 'Match undone.');
        $this->redirect('/accounting/bank-reconciliation?bank_account_id=' . $bankAccountId . $this->periodQuery());
    }

    public function autoMatch(): void
    {
        Auth::authorize('accounting.bank_reconciliation');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/bank-reconciliation');
            return;
        }

        $bankAccountId = (int) ($_POST['bank_account_id'] ?? 0);
        $bankAccount = $this->bankAccounts->find($bankAccountId);
        if (!$bankAccount) {
            Session::flash('error', 'Bank account not found.');
            $this->redirect('/accounting/bank-reconciliation');
            return;
        }

        $matched = $this->reconciliation->autoMatch($bankAccountId, (int) $bankAccount['account_id'], Auth::user()['id'] ?? null);

        Audit::log('Match', 'Accounting', "Auto-matched $matched line(s) for {$bankAccount['bank_name']} - {$bankAccount['account_name']}");
        Session::flash('success', "Auto-matched $matched line(s).");
        $this->redirect('/accounting/bank-reconciliation?bank_account_id=' . $bankAccountId . $this->periodQuery());
    }

    /**
     * A bank statement line with no corresponding journal at all (bank
     * charges, interest earned, a direct debit the app never saw) --
     * posts a brand-new journal and matches it in the same step.
     */
    public function createAdjustment(): void
    {
        Auth::authorize('accounting.bank_reconciliation');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/bank-reconciliation');
            return;
        }

        $bankAccountId = (int) ($_POST['bank_account_id'] ?? 0);
        $statementId = (int) ($_POST['statement_id'] ?? 0);
        $contraAccountId = (int) ($_POST['contra_account_id'] ?? 0);

        $bankAccount = $this->bankAccounts->find($bankAccountId);
        $statementLine = $this->statementLines->find($statementId);

        if (!$bankAccount || !$statementLine || $statementLine['reconciled']) {
            Session::flash('error', 'That statement line is not available to adjust.');
            $this->redirect('/accounting/bank-reconciliation?bank_account_id=' . $bankAccountId . $this->periodQuery());
            return;
        }

        if (!$contraAccountId) {
            Session::flash('error', 'Select the account this transaction should post against.');
            $this->redirect('/accounting/bank-reconciliation?bank_account_id=' . $bankAccountId . $this->periodQuery());
            return;
        }

        $moneyIn = (float) $statementLine['money_in'];
        $moneyOut = (float) $statementLine['money_out'];
        $amount = $moneyIn > 0 ? $moneyIn : $moneyOut;
        $bankGlAccountId = (int) $bankAccount['account_id'];

        $lines = $moneyIn > 0
            ? [
                ['account_id' => $bankGlAccountId, 'debit' => $amount, 'credit' => 0],
                ['account_id' => $contraAccountId, 'debit' => 0, 'credit' => $amount],
            ]
            : [
                ['account_id' => $contraAccountId, 'debit' => $amount, 'credit' => 0],
                ['account_id' => $bankGlAccountId, 'debit' => 0, 'credit' => $amount],
            ];

        $userId = Auth::user()['id'] ?? null;

        try {
            $journalId = $this->journal->post(
                'BANK_RECONCILIATION',
                'accounting_bank_statement',
                $statementId,
                generate_reference('BRJ'),
                'Bank statement adjustment: ' . ($statementLine['description'] ?: $statementLine['reference_no']),
                $lines,
                $userId,
                $statementLine['transaction_date'],
                'Manual'
            );
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/accounting/bank-reconciliation?bank_account_id=' . $bankAccountId . $this->periodQuery());
            return;
        }

        $journalLineId = $this->journal->lineIdForAccount($journalId, $bankGlAccountId);
        if ($journalLineId) {
            $this->reconciliation->match($statementId, $journalLineId, $amount, 'Manual', $userId, 'Created from bank statement adjustment');
        }

        Audit::log('Create', 'Accounting', 'Posted bank reconciliation adjustment journal #' . $journalId . ' for statement line #' . $statementId);
        Session::flash('success', 'Adjustment posted and matched.');
        $this->redirect('/accounting/bank-reconciliation?bank_account_id=' . $bankAccountId . $this->periodQuery());
    }
}
